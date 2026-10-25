import { describe, expect, it, vi } from 'vitest'
import { isReactive } from 'vue'
import { createQueue } from '@/admin/queue'
import { ApiError } from '@/lib/api'
import type { QueueItem } from '@/shared/types'
import { item, moderated, page } from './admin-fixtures'

const REST = '/rest.php/glowthread/v1'
const noFilters = { page: '', user: '', q: '' }

function setup(first: QueueItem[], { next = null as string | null, post = (_m: string, p: any) => moderated(String(p.ids).split('|').map(Number), 'deleted') as any, exact = false } = {}) {
  // What the server answers to the counts-only request (refreshCounts).
  const server = {
    counts: { reported: 5, held: 3, deleted: 2 },
    // GET /comment/{id}: the comments as the server has them (as listed, unless a test changes it).
    comments: Object.fromEntries(first.map((x) => [x.comment.id, JSON.parse(JSON.stringify(x.comment))])) as Record<number, any>,
  }
  const get = vi.fn(async (url: string) => {
    if (url.indexOf('limit=1&') >= 0 || /limit=1$/.test(url)) return page([], null, { ...server.counts })
    const one = /\/comment\/(\d+)$/.exec(url)
    if (one) return { comment: server.comments[Number(one[1])] }
    return JSON.parse(JSON.stringify(page(first, next)))
  })
  const postFn = vi.fn(async (module: string, params: any) => post(module, params))
  const resolvePage = vi.fn(async (title: string) => (title === 'Sourdough starter' ? 42 : null))
  const queue = createQueue({ restBase: REST, get: get as any, post: postFn as any, resolvePage, exactUndo: () => exact })
  return { queue, get, post: postFn, resolvePage, server }
}

const ids = (q: ReturnType<typeof setup>['queue']) => q.state.items.map((x) => x.comment.id)

describe('loading', () => {
  it('requests the tab with filters and focuses the first item', async () => {
    const a = item({}, ['X'])
    const { queue, get } = setup([a])
    await queue.show('held', { page: 'Sourdough starter', user: 'Some User', q: 'crème brûlée' })
    expect(get).toHaveBeenCalledWith(REST + '/moderation/queue?tab=held&limit=50&pageid=42&user=Some%20User&q=cr%C3%A8me%20br%C3%BBl%C3%A9e')
    expect(queue.state.focusId).toBe(a.comment.id)
    expect(queue.state.counts).toEqual({ reported: 5, held: 3, deleted: 2 })
  })

  it('says when the page filter names no page, without asking the queue', async () => {
    const { queue, get } = setup([item()])
    await queue.show('reported', { page: 'Nope', user: '', q: '' })
    expect(queue.state.pageMissing).toBe(true)
    expect(queue.state.items).toEqual([])
    expect(get.mock.calls.every(([u]) => u.indexOf('limit=50') < 0)).toBe(true)
  })

  it('drops the answer of a load that a newer one replaced', async () => {
    const slow = item()
    const fast = item()
    const { queue, get } = setup([])
    let release: (v: any) => void = () => undefined
    get.mockImplementationOnce(() => new Promise((r) => { release = r }))
    get.mockResolvedValueOnce(page([fast]) as any)
    const first = queue.show('reported', noFilters)
    await queue.show('held', noFilters)
    release(page([slow]))
    await first
    expect(ids(queue)).toEqual([fast.comment.id])
    expect(queue.state.tab).toBe('held')
  })

  it('keeps the error for a retry', async () => {
    const { queue, get } = setup([item()])
    get.mockRejectedValueOnce(new ApiError('network'))
    await queue.show('reported', noFilters)
    expect(queue.state.status).toBe('error')
    await queue.load()
    expect(queue.state.status).toBe('ready')
  })
})

describe('focus', () => {
  it('moves with j and k and loads the next page past the end', async () => {
    const [a, b, c] = [item(), item(), item()]
    const { queue, get } = setup([a, b], { next: 'CUR+/' })
    await queue.show('reported', noFilters)
    await queue.move(1)
    expect(queue.state.focusId).toBe(b.comment.id)
    get.mockResolvedValueOnce(page([b, c]) as any)
    await queue.move(1)
    expect(get).toHaveBeenLastCalledWith(REST + '/moderation/queue?tab=reported&limit=50&cursor=CUR%2B%2F')
    expect(ids(queue)).toEqual([a.comment.id, b.comment.id, c.comment.id])
    expect(queue.state.focusId).toBe(c.comment.id)
    expect(await queue.move(1)).toBe(false)
    await queue.move(-5)
    expect(queue.state.focusId).toBe(a.comment.id)
  })
})

describe('acting', () => {
  it('removes the comments at once, moves focus to the next one and the counts', async () => {
    const [a, b, c] = [item({}, ['X']), item({}, ['Y']), item({}, ['Z'])]
    let answer: (v: any) => void = () => undefined
    const { queue, post } = setup([a, b, c], { post: () => new Promise((r) => { answer = r }) })
    await queue.show('reported', noFilters)
    queue.focus(b.comment.id)
    const done = queue.act('delete', [b.comment.id])
    // Before the server answers:
    expect(ids(queue)).toEqual([a.comment.id, c.comment.id])
    expect(queue.state.focusId).toBe(c.comment.id)
    expect(queue.state.counts).toEqual({ reported: 4, held: 3, deleted: 3 })
    // The deletion goes out after its status was read fresh (for a safe undo).
    await vi.waitFor(() => expect(post).toHaveBeenCalled())
    answer(moderated([b.comment.id], 'deleted'))
    const r = await done
    expect(post).toHaveBeenCalledWith('glowthreadmoderate', { ids: String(b.comment.id), do: 'delete', reason: undefined })
    expect(r.done).toEqual([{ id: b.comment.id, action: 'delete', logId: undefined, status: 'deleted' }])
    expect(r.undo).toEqual([{ do: 'restore', ids: [b.comment.id] }])
  })

  it('puts the comments back where they were when the request fails', async () => {
    const [a, b, c] = [item({}, ['X']), item({}, ['Y']), item({}, ['Z'])]
    const { queue } = setup([a, b, c], { post: () => { throw new ApiError('blocked') } })
    await queue.show('reported', noFilters)
    queue.focus(b.comment.id)
    queue.toggleSelected(a.comment.id)
    queue.toggleSelected(b.comment.id)
    const r = await queue.act('delete', [a.comment.id, b.comment.id])
    expect((r.error as ApiError).code).toBe('blocked')
    expect(r.done).toEqual([])
    expect(ids(queue)).toEqual([a.comment.id, b.comment.id, c.comment.id])
    expect(queue.state.items[0].comment.status).toBe('visible')
    expect(queue.state.counts).toEqual({ reported: 5, held: 3, deleted: 2 })
  })

  it('marks comments another moderator changed first, and takes the rest', async () => {
    const [a, b] = [item({ status: 'held' }), item({ status: 'held' })]
    const { queue } = setup([a, b], {
      post: (_m, p) => moderated(String(p.ids).split('|').map(Number), 'visible', { [b.comment.id]: { result: 'error', code: 'badstatus', status: null } }),
    })
    await queue.show('held', noFilters)
    const r = await queue.act('pass', [a.comment.id, b.comment.id])
    expect(r.done.map((d) => d.id)).toEqual([a.comment.id])
    expect(r.stale).toEqual([b.comment.id])
    expect(ids(queue)).toEqual([b.comment.id])
    expect(queue.state.stale).toEqual([b.comment.id])
    // Approving has no undo without the server's exact undo.
    expect(r.undo).toBeNull()
  })

  it('Approve (pass) approves held comments and closes the reports of visible ones in one go', async () => {
    const held = item({ status: 'held' }, ['A'])
    const visible = item({}, ['B'])
    const { queue, post } = setup([held, visible], { post: (_m, p) => moderated(String(p.ids).split('|').map(Number), p.do === 'approve' ? 'visible' : 'visible') })
    await queue.show('reported', noFilters)
    const r = await queue.act('pass', [held.comment.id, visible.comment.id])
    expect(post.mock.calls.map(([, p]) => p.do).sort()).toEqual(['approve', 'dismiss'])
    expect(r.done.length).toBe(2)
    expect(ids(queue)).toEqual([])
  })

  it('puts a restored comment that was deleted while held back in the Held tab, from deletedFrom and the result', async () => {
    const known = item({ status: 'deleted', mod: { holdReasons: ['newaccount'], openReports: 0, reportReasons: [], statusBy: null, statusAt: null, pinnedAt: null, legacyId: null, deletedFrom: 'held' } })
    const unknown = item({ status: 'deleted', mod: { holdReasons: ['filter'], openReports: 0, reportReasons: [], statusBy: null, statusAt: null, pinnedAt: null, legacyId: null, deletedFrom: null } })
    // The server restores both to held (the second one's status before was on record after all).
    const { queue } = setup([known, unknown], { post: (_m, p) => moderated(String(p.ids).split('|').map(Number), 'held') })
    await queue.show('all', noFilters)
    const counts = { ...queue.state.counts! }
    const pending = queue.act('restore', [known.comment.id])
    // Optimistic: deletedFrom says held, so it is held at once (and counted there), never visible.
    expect(queue.state.items.find((x) => x.comment.id === known.comment.id)!.comment.status).toBe('held')
    expect(queue.state.counts).toEqual({ ...counts, held: counts.held + 1, deleted: counts.deleted - 1 })
    const r = await pending
    expect(r.done).toEqual([{ id: known.comment.id, action: 'restore', logId: undefined, status: 'held' }])
    // Without deletedFrom the guess is visible; the server's answer puts it in the Held tab.
    await queue.act('restore', [unknown.comment.id])
    expect(queue.state.items.map((x) => x.comment.status)).toEqual(['held', 'held'])
  })

  it('leaves comments an action does not apply to', async () => {
    const a = item()
    const { queue, post } = setup([a])
    await queue.show('all', noFilters)
    const r = await queue.act('restore', [a.comment.id])
    expect(r.skipped).toBe(true)
    expect(post).not.toHaveBeenCalled()
  })

  it('changes comments in place in the All tab, and sends at most 50 IDs per request', async () => {
    const many = Array.from({ length: 120 }, () => item())
    const { queue, post } = setup(many)
    await queue.show('all', noFilters)
    queue.setAllSelected(true)
    const r = await queue.act('delete', queue.state.selected.slice())
    expect(post.mock.calls.map(([, p]) => String(p.ids).split('|').length)).toEqual([50, 50, 20])
    expect(r.done.length).toBe(120)
    expect(queue.state.items.length).toBe(120)
    expect(queue.state.items.every((x) => x.comment.status === 'deleted')).toBe(true)
    expect(queue.state.busy).toEqual([])
  })

  it('fetches more items when only a few are left to work through', async () => {
    const items = Array.from({ length: 9 }, () => item({}, ['X']))
    const { queue, get } = setup(items, { next: 'NEXT' })
    await queue.show('reported', noFilters)
    get.mockClear()
    await queue.act('delete', [items[0].comment.id])
    expect(get.mock.calls.some(([u]) => u.indexOf('cursor=NEXT') >= 0)).toBe(true)
  })
})

describe('acting safely', () => {
  it('skips comments whose request is still on its way, and keeps them marked busy', async () => {
    const [a, b] = [item(), item()]
    let answer: (v: any) => void = () => undefined
    const { queue, post } = setup([a, b], { post: (_m, p) => (p.do === 'delete' ? new Promise((r) => { answer = r }) : moderated(String(p.ids).split('|').map(Number), 'visible')) })
    await queue.show('all', noFilters)
    const first = queue.act('delete', [a.comment.id])
    await vi.waitFor(() => expect(post).toHaveBeenCalled())
    const second = await queue.act('pin', [a.comment.id])
    expect(second.pending).toBe(true)
    expect(second.skipped).toBe(false)
    // Another action that touches it and another comment: only the other one is sent.
    await queue.act('pin', [a.comment.id, b.comment.id])
    expect(post.mock.calls.map(([, p]) => [p.do, p.ids])).toEqual([['delete', String(a.comment.id)], ['pin', String(b.comment.id)]])
    expect(queue.state.busy).toEqual([a.comment.id])
    answer(moderated([a.comment.id], 'deleted'))
    await first
    expect(queue.state.busy).toEqual([])
  })

  it('puts a refused comment where its current status lists it, and tells limits from changes', async () => {
    const [a, b] = [item({ status: 'deleted' }), item({ status: 'deleted' })]
    const { queue } = setup([a, b], {
      post: () => ({
        result: 'partial', do: 'restore', results: [
          // Another moderator erased it meanwhile: it stays in the Deleted tab, marked.
          { id: a.comment.id, result: 'error', status: 'erased', code: 'badstatus' },
          { id: b.comment.id, result: 'error', status: 'deleted', code: 'ratelimited' },
        ],
      }),
    })
    await queue.show('deleted', noFilters)
    const r = await queue.act('restore', [a.comment.id, b.comment.id])
    expect(r.stale).toEqual([a.comment.id])
    expect(r.refused).toEqual([b.comment.id])
    expect(queue.state.items.map((x) => [x.comment.id, x.comment.status])).toEqual([[a.comment.id, 'erased'], [b.comment.id, 'deleted']])
    expect(queue.state.stale).toEqual([a.comment.id])
  })

  it('rolls back the chunk that failed and keeps the one that worked', async () => {
    const many = Array.from({ length: 60 }, () => item({}, ['X']))
    let call = 0
    const { queue } = setup(many, {
      post: (_m, p) => {
        call++
        if (call === 2) throw new ApiError('network')
        return moderated(String(p.ids).split('|').map(Number), 'deleted')
      },
    })
    await queue.show('reported', noFilters)
    const r = await queue.act('delete', many.map((x) => x.comment.id))
    expect(r.done.length).toBe(50)
    expect(r.refused.length).toBe(10)
    expect((r.error as ApiError).code).toBe('network')
    expect(queue.state.items.length).toBe(10)
  })

  it('lets j at the end wait for a refill already on its way', async () => {
    const items = Array.from({ length: 9 }, () => item({}, ['X']))
    const extra = item({}, ['Y'])
    const { queue, get } = setup(items, { next: 'NEXT' })
    await queue.show('reported', noFilters)
    let release: (v: any) => void = () => undefined
    get.mockImplementationOnce(() => new Promise((r) => { release = r }))
    queue.focus(items[8].comment.id)
    const refill = queue.loadMore()
    const moved = queue.move(1)
    release(page([extra]))
    await refill
    expect(await moved).toBe(true)
    expect(queue.state.focusId).toBe(extra.comment.id)
    expect(get.mock.calls.filter(([u]) => u.indexOf('cursor=NEXT') >= 0).length).toBe(1)
  })

  it('keeps the newest counts when answers overtake each other', async () => {
    const { queue, get } = setup([item()])
    await queue.show('reported', noFilters)
    let slow: (v: any) => void = () => undefined
    get.mockImplementationOnce(() => new Promise((r) => { slow = r }))
    get.mockResolvedValueOnce(page([], null, { reported: 9, held: 9, deleted: 9 }) as any)
    const older = queue.refreshCounts()
    await queue.refreshCounts()
    slow(page([], null, { reported: 1, held: 1, deleted: 1 }))
    await older
    expect(queue.state.counts).toEqual({ reported: 9, held: 9, deleted: 9 })
  })
})

describe('undo', () => {
  it('offers no restore when the server has the comment held by now (reports hold on their own)', async () => {
    const a = item({}, ['X'])
    const b = item()
    const { queue, server } = setup([a, b])
    await queue.show('all', noFilters)
    // Held by reports after the list was loaded; the list still says visible.
    server.comments[a.comment.id].status = 'held'
    const r = await queue.act('delete', [a.comment.id])
    expect(r.done.length).toBe(1)
    expect(r.undo).toBeNull()
    // The same for a comment without reports when the list was loaded.
    server.comments[b.comment.id].status = 'held'
    expect((await queue.act('delete', [b.comment.id])).undo).toBeNull()
  })

  it('offers no restore for a large deletion it cannot check', async () => {
    const many = Array.from({ length: 11 }, () => item())
    const { queue, get } = setup(many)
    await queue.show('all', noFilters)
    const r = await queue.act('delete', many.map((x) => x.comment.id))
    expect(r.done.length).toBe(11)
    expect(r.undo).toBeNull()
    expect(get.mock.calls.some(([u]) => /\/comment\/\d+$/.test(u))).toBe(false)
  })

  it('takes the prior status from the server when it is given', async () => {
    const a = item({}, ['X'])
    const { queue } = setup([a], {
      post: (_m, p) => {
        const r = moderated(String(p.ids).split('|').map(Number), 'deleted')
        ;(r.results as any[]).forEach((x) => { x.old = 'held' })
        return r
      },
    })
    await queue.show('reported', noFilters)
    const r = await queue.act('delete', [a.comment.id])
    // Held before the deletion (says the server): no restore.
    expect(r.undo).toBeNull()
  })

  it('puts a deleted comment back in the All tab by restoring it', async () => {
    const a = item()
    const { queue, post } = setup([a], { post: (_m, p) => moderated(String(p.ids).split('|').map(Number), p.do === 'delete' ? 'deleted' : 'visible') })
    await queue.show('all', noFilters)
    const r = await queue.act('delete', [a.comment.id])
    expect(queue.state.items[0].comment.status).toBe('deleted')
    const u = await queue.undo(r)
    expect(post).toHaveBeenLastCalledWith('glowthreadmoderate', { ids: String(a.comment.id), do: 'restore', logids: undefined })
    expect(u.done.length).toBe(1)
    expect(queue.state.items[0].comment.status).toBe('visible')
  })

  it('brings a restored comment back to the Deleted tab by deleting it again', async () => {
    const [a, b] = [item({ status: 'deleted' }), item({ status: 'deleted' })]
    const { queue } = setup([a, b], { post: (_m, p) => moderated(String(p.ids).split('|').map(Number), p.do === 'restore' ? 'visible' : 'deleted') })
    await queue.show('deleted', noFilters)
    const r = await queue.act('restore', [a.comment.id])
    expect(ids(queue)).toEqual([b.comment.id])
    await queue.undo(r)
    expect(ids(queue)).toEqual([a.comment.id, b.comment.id])
    expect(queue.state.counts!.deleted).toBe(2)
  })

  it('with exact undo brings back a reported comment with its reports', async () => {
    const a = item({}, ['Mallory', 'Carol'])
    const { queue, post } = setup([a], {
      exact: true,
      post: (_m, p) => {
        const r = moderated(String(p.ids).split('|').map(Number), p.do === 'undo' ? 'visible' : 'deleted')
        if (p.do !== 'undo') (r.results as any[]).forEach((x) => { x.logId = 77 })
        return r
      },
    })
    await queue.show('reported', noFilters)
    const r = await queue.act('delete', [a.comment.id])
    expect(r.undo).toEqual([{ do: 'undo', ids: [a.comment.id], logids: [77] }])
    expect(ids(queue)).toEqual([])
    await queue.undo(r)
    expect(post).toHaveBeenLastCalledWith('glowthreadmoderate', { ids: String(a.comment.id), do: 'undo', logids: '77' })
    expect(ids(queue)).toEqual([a.comment.id])
    expect(queue.state.items[0].reports.length).toBe(2)
    expect(queue.state.items[0].comment.mod!.openReports).toBe(2)
  })

  it('undoes more than 50 items 50 at a time, each ID with its log ID', async () => {
    const all = Array.from({ length: 60 }, () => item({}, ['Mallory']))
    const { queue, post } = setup(all, {
      exact: true,
      post: (_m, p) => {
        const r = moderated(String(p.ids).split('|').map(Number), p.do === 'undo' ? 'visible' : 'deleted')
        if (p.do !== 'undo') (r.results as any[]).forEach((x) => { x.logId = 1000 + x.id })
        return r
      },
    })
    await queue.show('reported', noFilters)
    const r = await queue.act('delete', all.map((x) => x.comment.id))
    post.mockClear()
    const u = await queue.undo(r)
    const sent = post.mock.calls.map(([, p]) => [String(p.ids).split('|').length, String(p.logids).split('|').length])
    expect(sent).toEqual([[50, 50], [10, 10]])
    const last = post.mock.calls[1][1]
    expect(String(last.logids).split('|')[0]).toBe(String(1000 + Number(String(last.ids).split('|')[0])))
    expect(u.done.length).toBe(60)
  })
})

describe('news', () => {
  it('counts what arrived since loading, not what the moderator did', async () => {
    const [a, b] = [item({}, ['X']), item({}, ['Y'])]
    const { queue, server } = setup([a, b])
    await queue.show('reported', noFilters)
    server.counts = { reported: 4, held: 3, deleted: 3 }
    await queue.act('delete', [a.comment.id])
    await queue.refreshCounts()
    expect(queue.fresh.value).toBe(0)
    server.counts = { reported: 7, held: 3, deleted: 3 }
    await queue.refreshCounts()
    // 4 left after the deletion; 7 now: 3 new.
    expect(queue.fresh.value).toBe(3)
  })
})

describe('the list', () => {
  it('holds plain items, replaced whenever they change (hundreds are read on every action)', async () => {
    const [a, b, c] = [item({}, ['X']), item({}, ['Y']), item({}, ['Z'])]
    const { queue, get } = setup([a, b], {
      next: 'c1',
      exact: true,
      post: (_m, p) => {
        // A pin fails (the item changes in place and back); a deletion and its undo go through.
        if (p.do === 'pin') throw new ApiError('blocked')
        const r = moderated(String(p.ids).split('|').map(Number), p.do === 'undo' ? 'visible' : 'deleted')
        if (p.do !== 'undo') (r.results as any[]).forEach((x) => { x.logId = 77 })
        return r
      },
    })
    const plain = () => queue.state.items.every((x) => !isReactive(x) && !isReactive(x.comment))
    await queue.show('reported', noFilters)
    expect(plain()).toBe(true)
    get.mockResolvedValueOnce(page([c]) as any)
    await queue.loadMore()
    expect(plain()).toBe(true)
    const before = queue.state.items[1]
    await queue.act('pin', [b.comment.id])
    expect(queue.state.items[1]).not.toBe(before)
    expect(plain()).toBe(true)
    // Out of the list and back in.
    await queue.undo(await queue.act('delete', [a.comment.id]))
    expect(ids(queue)).toEqual([a.comment.id, b.comment.id, c.comment.id])
    expect(plain()).toBe(true)
  })
})
