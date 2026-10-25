import { describe, expect, it, vi } from 'vitest'
import { computed } from 'vue'
import { createStore } from '@/comments/store'
import { ApiError } from '@/lib/api'
import type { Comment, ListResponse, PageConfig } from '@/shared/types'

const config: PageConfig = {
  pageId: 42,
  enabled: true,
  state: 'open',
  defaultSort: 'hot',
  listUrls: { hot: '/rest.php/glowthread/v1/page/42/comments?sort=hot', new: '/rest.php/glowthread/v1/page/42/comments?sort=new' },
  restBase: '/rest.php/glowthread/v1',
  features: { legacyCompat: true },
}

let nextId = 100
function comment(over: Partial<Comment> = {}): Comment {
  const id = over.id || nextId++
  return {
    id, seq: id, pageId: 42, parentId: null, threadId: null, depth: 0, status: 'visible',
    author: { name: 'Alice', id: 3, anon: false, temp: false, hidden: false },
    created: '2026-10-08T00:00:00Z', edited: null, segments: [{ type: 'text', text: 'hi' }],
    likes: 0, pinned: false, replyTo: null,
    viewer: { own: false, liked: false, reported: false, canReply: true, canLike: true, canReport: true, canDelete: false },
    replies: [], replyCount: 0, totalReplies: 0,
    ...over,
  }
}

function list(comments: Comment[], over: Partial<ListResponse> = {}): ListResponse {
  return {
    page: { id: 42, title: 'Sourdough starter', state: 'open' }, sort: 'hot',
    viewer: { loggedIn: true, canPost: true, postBlockedReason: null, canReact: true, canReport: true, canModerate: false, canErase: false, canConfigure: false },
    config: { maxLength: 1000, maxMentions: 5, reportReasons: ['spam', 'other'], pageSize: 20, replyPreview: 3 },
    count: comments.length, pinned: [], popular: [], comments, next: null,
    ...over,
  }
}

function setup(first: ListResponse, post: (module: string, params: any) => any = () => ({})) {
  const get = vi.fn(async (url: string) => {
    if (url.indexOf('/moderation/') >= 0) return { counts: { held: 1, reported: 2 } }
    return JSON.parse(JSON.stringify(first))
  })
  const postFn = vi.fn(async (module: string, params: any) => post(module, params))
  const store = createStore({ config, get: get as any, post: postFn as any })
  return { store, get, post: postFn }
}

describe('reading', () => {
  it('requests the first page at the canonical URL, byte for byte', async () => {
    const { store, get } = setup(list([comment()]))
    await store.load()
    expect(get).toHaveBeenCalledWith('/rest.php/glowthread/v1/page/42/comments?sort=hot')
    await store.setSort('new')
    expect(get).toHaveBeenLastCalledWith('/rest.php/glowthread/v1/page/42/comments?sort=new')
  })

  it('pages with the cursor and skips duplicates', async () => {
    const a = comment()
    const b = comment()
    const { store, get } = setup(list([a], { next: 'eyJ+/=' }))
    await store.load()
    get.mockResolvedValueOnce(list([a, b], { next: null }) as any)
    await store.loadMore()
    expect(get).toHaveBeenLastCalledWith('/rest.php/glowthread/v1/page/42/comments?sort=hot&cursor=eyJ%2B%2F%3D')
    expect(store.current.value.data!.comments.map((c) => c.id)).toEqual([a.id, b.id])
    expect(store.current.value.data!.next).toBeNull()
  })

  it('keeps the error for a retry', async () => {
    const { store, get } = setup(list([]))
    get.mockRejectedValueOnce(new ApiError('network'))
    await expect(store.load()).rejects.toThrow()
    expect(store.current.value.status).toBe('error')
    await store.load()
    expect(store.current.value.status).toBe('ready')
  })
})

describe('posting', () => {
  it('puts a new comment first in both orders and counts it', async () => {
    const old = comment()
    const fresh = comment({ viewer: { ...comment().viewer, own: true } })
    const { store, post } = setup(list([old]), () => ({ result: 'success', id: fresh.id, seq: fresh.seq, status: 'visible', comment: fresh }))
    await store.load('hot')
    await store.load('new')
    await store.post('hello')
    expect(post).toHaveBeenCalledWith('glowthreadpost', { pageid: 42, text: 'hello', replyto: undefined })
    for (const s of ['hot', 'new'] as const) {
      expect(store.state.lists[s].data!.comments[0].id).toBe(fresh.id)
      expect(store.state.lists[s].data!.count).toBe(2)
    }
    expect(store.state.fresh).toContain(fresh.id)
  })

  it('files a reply under its parent and counts it in the thread', async () => {
    const top = comment()
    const reply = comment({ parentId: top.id, threadId: top.id, depth: 1 })
    const { store } = setup(list([top]), () => ({ result: 'success', id: reply.id, seq: reply.seq, status: 'visible', comment: reply }))
    await store.load()
    await store.post('re', store.current.value.data!.comments[0])
    const t = store.current.value.data!.comments[0]
    expect(t.replies!.map((r) => r.id)).toEqual([reply.id])
    expect(t.replyCount).toBe(1)
    expect(t.totalReplies).toBe(1)
  })

  it('does not count a held comment for others', async () => {
    const held = comment({ status: 'held' })
    const { store } = setup(list([comment()]), () => ({ result: 'success', id: held.id, seq: held.seq, status: 'held', comment: held }))
    await store.load()
    await store.post('new account')
    expect(store.current.value.data!.count).toBe(1)
    expect(store.current.value.data!.comments[0].status).toBe('held')
  })

  it('remembers how long to wait after a rate limit', async () => {
    const { store } = setup(list([]), () => {
      throw new ApiError('ratelimited', '', { retryAfter: 30 })
    })
    await store.load()
    const before = Date.now()
    await expect(store.post('x')).rejects.toThrow()
    expect(store.state.rateLimitedUntil).toBeGreaterThanOrEqual(before + 30000)
  })
})

describe('likes', () => {
  it('changes the count at once and keeps the server count', async () => {
    const c = comment({ likes: 3 })
    let resolve: (v: unknown) => void = () => undefined
    const { store, post } = setup(list([c]), () => new Promise((r) => { resolve = r }))
    await store.load()
    const live = store.current.value.data!.comments[0]
    const done = store.toggleLike(live)
    expect(live.viewer.liked).toBe(true)
    expect(live.likes).toBe(4)
    expect(post).toHaveBeenCalledWith('glowthreadreact', { id: c.id, value: 1 })
    resolve({ result: 'success', id: c.id, liked: true, likes: 5, changed: true })
    await done
    expect(live.likes).toBe(5)
  })

  it('sends the last wish after quick double taps (idempotent values, not toggles)', async () => {
    const c = comment({ likes: 0 })
    const calls: number[] = []
    const { store } = setup(list([c]), async (_m, p) => {
      calls.push(p.value)
      await new Promise((r) => setTimeout(r, 5))
      return { result: 'success', id: c.id, liked: p.value === 1, likes: p.value, changed: true }
    })
    await store.load()
    const live = store.current.value.data!.comments[0]
    const first = store.toggleLike(live)
    store.toggleLike(live)
    expect(live.viewer.liked).toBe(false)
    await first
    expect(calls).toEqual([1, 0])
    expect(live.viewer.liked).toBe(false)
    expect(live.likes).toBe(0)
  })

  it('puts the like back when the request fails', async () => {
    const c = comment({ likes: 2 })
    const { store } = setup(list([c]), () => {
      throw new ApiError('ratelimited')
    })
    await store.load()
    const live = store.current.value.data!.comments[0]
    await expect(store.toggleLike(live)).rejects.toThrow()
    expect(live.viewer.liked).toBe(false)
    expect(live.likes).toBe(2)
  })
})

describe('removing and moderating', () => {
  it('removes an own deleted comment for readers and recounts', async () => {
    const top = comment()
    const reply = comment({ parentId: top.id, threadId: top.id, depth: 1, viewer: { ...comment().viewer, own: true, canDelete: true } })
    top.replies = [reply]
    top.replyCount = 1
    top.totalReplies = 1
    const { store } = setup(list([top], { count: 2 }), () => ({ result: 'success', id: reply.id, status: 'deleted' }))
    await store.load()
    await store.deleteOwn(store.find(reply.id)!)
    const t = store.current.value.data!.comments[0]
    expect(t.replies).toEqual([])
    expect(t.totalReplies).toBe(0)
    expect(store.current.value.data!.count).toBe(1)
  })

  it('applies per-item moderation results and counts hidden comments', async () => {
    const a = comment()
    const b = comment()
    const mod = { loggedIn: true, canPost: true, postBlockedReason: null, canReact: true, canReport: true, canModerate: true, canErase: true, canConfigure: true }
    const { store, post } = setup(list([a, b], { viewer: mod }), () => ({
      result: 'partial', do: 'delete',
      results: [{ id: a.id, result: 'success', status: 'deleted', code: null }, { id: b.id, result: 'error', status: null, code: 'commentnotfound' }],
    }))
    await store.load()
    await store.moderate([a.id, b.id], 'delete')
    expect(post).toHaveBeenCalledWith('glowthreadmoderate', { ids: a.id + '|' + b.id, do: 'delete' })
    expect(store.find(a.id)!.status).toBe('deleted')
    expect(store.find(b.id)!.status).toBe('visible')
    expect(store.current.value.data!.count).toBe(1)
  })
})

describe('refreshes and races', () => {
  it('keeps the pages a reader loaded when a moderator action refreshes the list', async () => {
    const page1 = [comment(), comment()]
    const page2 = [comment(), comment()]
    const { store, get } = setup(list(page1, { next: 'c2' }))
    await store.load()
    get.mockResolvedValueOnce(list(page2, { next: 'c3' }) as any)
    await store.loadMore()
    get.mockResolvedValueOnce(list(page1, { next: 'c2' }) as any)
    await store.load('hot', { quiet: true })
    expect(store.current.value.data!.comments.map((c) => c.id)).toEqual([...page1, ...page2].map((c) => c.id))
    expect(store.current.value.data!.next).toBe('c3')
  })

  it('drops a page that arrives after the list was replaced', async () => {
    const { store, get } = setup(list([comment()], { next: 'c2' }))
    await store.load()
    let release: (v: unknown) => void = () => undefined
    get.mockImplementationOnce(() => new Promise((r) => { release = r }))
    const more = store.loadMore()
    get.mockResolvedValueOnce(list([comment()], { next: 'fresh' }) as any)
    await store.load('hot', { quiet: true })
    release(list([comment()], { next: 'stale' }))
    await more
    expect(store.current.value.data!.next).toBe('fresh')
  })

  it('takes the current status from "nochange" results', async () => {
    const a = comment({ status: 'held' })
    const mod = { loggedIn: true, canPost: true, postBlockedReason: null, canReact: true, canReport: true, canModerate: true, canErase: true, canConfigure: true }
    const { store } = setup(list([a], { viewer: mod }), () => ({
      result: 'success', do: 'approve', results: [{ id: a.id, result: 'nochange', status: 'deleted', code: null }],
    }))
    await store.load()
    await store.moderate([a.id], 'approve')
    expect(store.find(a.id)!.status).toBe('deleted')
  })

  it('counts the replies that disappear with an own top-level comment', async () => {
    const top = comment({ viewer: { ...comment().viewer, own: true, canDelete: true }, totalReplies: 4, replyCount: 1 })
    top.replies = [comment({ parentId: top.id, threadId: top.id, depth: 1 })]
    const { store } = setup(list([top, comment()], { count: 6 }), () => ({ result: 'success', id: top.id, status: 'deleted' }))
    await store.load()
    await store.deleteOwn(store.find(top.id)!)
    expect(store.current.value.data!.count).toBe(1)
  })
})

describe('safety rules', () => {
  it('does not show another page\'s comment as part of this page', async () => {
    const { store, get } = setup(list([comment()]))
    await store.load()
    const foreign = comment({ pageId: 99 })
    get.mockResolvedValueOnce({ page: { id: 99, title: 'Crème brûlée', state: 'open' }, focus: foreign.id, thread: foreign, truncated: false } as any)
    expect(await store.showComment(foreign.id)).toBe('elsewhere')
    expect(store.state.linked.status).toBe('elsewhere')
    expect(store.state.linked.data!.page.title).toBe('Crème brûlée')
    const own = comment()
    get.mockResolvedValueOnce({ page: { id: 42, title: 'Sourdough starter', state: 'open' }, focus: own.id, thread: own, truncated: false } as any)
    expect(await store.showComment(own.id)).toBe('linked')
    expect(store.state.linked.status).toBe('ready')
  })

  it('drops the selection of Select (manage mode) when the view changes', async () => {
    const a = comment()
    const { store } = setup(list([a]))
    await store.load()
    store.setManage(true)
    store.toggleSelected(a.id)
    await store.setSort('new')
    expect(store.state.selected).toEqual([])
    store.toggleSelected(a.id)
    await store.setModFilter('held')
    expect(store.state.selected).toEqual([])
    store.toggleSelected(a.id)
    // The same view again keeps it.
    await store.setModFilter('held')
    expect(store.state.selected).toEqual([a.id])
  })

  it('drops the selection of replies a folded conversation hides, but not the reader\'s new ones', async () => {
    const top = comment({ totalReplies: 3, replyCount: 2 })
    const a = comment({ parentId: top.id, threadId: top.id, depth: 1, replyCount: 1 })
    const deep = comment({ parentId: a.id, threadId: top.id, depth: 2 })
    a.replies = [deep]
    const mine = comment({ parentId: top.id, threadId: top.id, depth: 1 })
    top.replies = [a, mine]
    const other = comment()
    const { store } = setup(list([top, other]))
    await store.load()
    store.state.fresh.push(mine.id)
    store.setManage(true)
    for (const id of [top.id, a.id, deep.id, mine.id, other.id]) store.toggleSelected(id)
    store.dropFolded(store.find(top.id)!)
    expect(store.state.selected).toEqual([top.id, mine.id, other.id])
  })

  it('drops the selection of a linked conversation when it closes', async () => {
    const listed = comment()
    const { store, get } = setup(list([listed]))
    await store.load()
    const top = comment({ totalReplies: 1, replyCount: 1 })
    const reply = comment({ parentId: top.id, threadId: top.id, depth: 1 })
    get.mockResolvedValueOnce({ page: { id: 42, title: 'Sourdough starter', state: 'open' }, focus: reply.id, thread: { ...top, replies: [reply] }, truncated: false } as any)
    expect(await store.showComment(reply.id)).toBe('linked')
    store.setManage(true)
    for (const id of [listed.id, top.id, reply.id]) store.toggleSelected(id)
    store.closeLinked()
    expect(store.state.selected).toEqual([listed.id])
    // Another linked conversation takes its place: the same.
    get.mockResolvedValueOnce({ page: { id: 42, title: 'Sourdough starter', state: 'open' }, focus: reply.id, thread: { ...top, replies: [reply] }, truncated: false } as any)
    await store.showComment(reply.id)
    store.toggleSelected(reply.id)
    const next = comment()
    get.mockResolvedValueOnce({ page: { id: 42, title: 'Sourdough starter', state: 'open' }, focus: next.id, thread: next, truncated: false } as any)
    expect(await store.showComment(next.id)).toBe('linked')
    expect(store.state.selected).toEqual([listed.id])
  })

  it('sends the server\'s exact undo with its log IDs, then reloads the lists', async () => {
    const a = comment()
    const { store, post, get } = setup(list([a]), (_m, p) => ({ result: 'success', do: p.do, results: [{ id: a.id, result: 'success', status: 'visible', old: 'deleted', code: null, logId: 7 }] }))
    await store.load()
    get.mockClear()
    await store.moderate([a.id], 'undo', [5])
    expect(post).toHaveBeenLastCalledWith('glowthreadmoderate', { ids: String(a.id), do: 'undo', logids: '5' })
    expect(get.mock.calls.some(([u]) => u.indexOf('/comments?sort=hot') >= 0)).toBe(true)
  })

  it('counts from the status the server had, not the page\'s copy', async () => {
    const a = comment()
    // Held by reports after the page loaded: hiding it does not change the public count.
    const { store } = setup(list([a]), (_m, p) => ({ result: 'success', do: p.do, results: [{ id: a.id, result: 'success', status: 'deleted', old: 'held', code: null, logId: 8 }] }))
    await store.load()
    const before = store.current.value.data!.count
    await store.moderate([a.id], 'delete')
    expect(store.current.value.data!.count).toBe(before)
  })
})

describe('conversations in place', () => {
  const at = (n: number) => '2026-10-0' + n + 'T00:00:00Z'

  it('counts what each link stands for, so every hidden reply has exactly one', async () => {
    const top = comment({ totalReplies: 9, replyCount: 5 })
    const r1 = comment({ parentId: top.id, threadId: top.id, depth: 1, replyCount: 4 })
    r1.replies = [comment({ parentId: r1.id, threadId: top.id, depth: 2 })]
    top.replies = [r1]
    const { store } = setup(list([top]))
    await store.load()
    const t = store.find(top.id)!
    // 9 replies, 2 shown, 3 more under r1 (its own link): 4 behind the thread's link.
    expect(store.hiddenReplies(t.replies![0])).toBe(3)
    expect(store.hiddenReplies(t)).toBe(4)
  })

  it('loads the next replies in place, oldest first, without duplicates, until all are in', async () => {
    const top = comment({ totalReplies: 4, replyCount: 4 })
    const [a, b, c, d] = [1, 2, 3, 4].map((n) => comment({ parentId: top.id, threadId: top.id, depth: 1, created: at(n) }))
    top.replies = [a]
    const { store, get } = setup(list([top]))
    await store.load()
    get.mockResolvedValueOnce({ parentId: top.id, replies: [a, b], next: 'c1' } as any)
    expect(await store.loadReplies(store.find(top.id)!)).toEqual([b.id])
    expect(get).toHaveBeenLastCalledWith('/rest.php/glowthread/v1/comment/' + top.id + '/replies?limit=50')
    get.mockResolvedValueOnce({ parentId: top.id, replies: [d, c], next: null } as any)
    await store.loadReplies(store.find(top.id)!)
    expect(get).toHaveBeenLastCalledWith('/rest.php/glowthread/v1/comment/' + top.id + '/replies?limit=50&cursor=c1')
    expect(store.find(top.id)!.replies!.map((r) => r.id)).toEqual([a.id, b.id, c.id, d.id])
    expect(store.hiddenReplies(store.find(top.id)!)).toBe(0)
  })

  it('lets the templates follow the paging state', async () => {
    const top = comment({ totalReplies: 1, replyCount: 1 })
    const { store, get } = setup(list([top]))
    await store.load()
    const status = computed(() => store.state.replies[top.id] && store.state.replies[top.id].status)
    get.mockResolvedValueOnce({ parentId: top.id, replies: [], next: null } as any)
    const loading = store.loadReplies(store.find(top.id)!)
    expect(status.value).toBe('loading')
    await loading
    expect(status.value).toBe('ready')
  })

  it('skips pages the reader already has, so a conversation cut at 500 replies goes on', async () => {
    const top = comment({ totalReplies: 3, replyCount: 3 })
    const [a, b, c] = [1, 2, 3].map((n) => comment({ parentId: top.id, threadId: top.id, depth: 1, created: at(n) }))
    const { store, get } = setup(list([comment()]))
    await store.load()
    // The linked conversation as /thread sent it: two of three replies (truncated).
    get.mockResolvedValueOnce({ page: { id: 42, title: 'Sourdough starter', state: 'open' }, focus: b.id, thread: { ...top, replies: [a, b] }, truncated: true } as any)
    expect(await store.showComment(b.id)).toBe('linked')
    const linked = store.state.linked.data!.thread
    expect(store.hiddenReplies(linked)).toBe(1)
    get.mockResolvedValueOnce({ parentId: top.id, replies: [a, b], next: 'c1' } as any)
    get.mockResolvedValueOnce({ parentId: top.id, replies: [c], next: null } as any)
    expect(await store.loadReplies(linked)).toEqual([c.id])
    expect(linked.replies!.map((r) => r.id)).toEqual([a.id, b.id, c.id])
    expect(store.hiddenReplies(linked)).toBe(0)
  })

  it('keeps a failed page for Try again', async () => {
    const top = comment({ totalReplies: 2, replyCount: 2 })
    const { store, get } = setup(list([top]))
    await store.load()
    get.mockRejectedValueOnce(new ApiError('network'))
    await expect(store.loadReplies(store.find(top.id)!)).rejects.toThrow()
    expect(store.state.replies[top.id].status).toBe('error')
    expect(store.hiddenReplies(store.find(top.id)!)).toBe(2)
  })

  it('shows a linked comment in the list when its thread is there, completed from the server', async () => {
    const top = comment({ totalReplies: 3, replyCount: 3 })
    const [a, b, c] = [1, 2, 3].map((n) => comment({ parentId: top.id, threadId: top.id, depth: 1, created: at(n) }))
    top.replies = [a]
    const { store, get } = setup(list([top]))
    await store.load()
    // Already loaded: no request, the thread unfolds (phones fold it).
    expect(await store.showComment(a.id)).toBe('list')
    expect(store.state.folds[top.id]).toBe(false)
    const thread = JSON.parse(JSON.stringify({ ...top, replies: [a, b, c] }))
    get.mockResolvedValueOnce({ page: { id: 42, title: 'Sourdough starter', state: 'open' }, focus: c.id, thread, truncated: false } as any)
    expect(await store.showComment(c.id)).toBe('list')
    expect(store.find(top.id)!.replies!.map((r) => r.id)).toEqual([a.id, b.id, c.id])
    expect(store.state.linked.status).toBe('idle')
  })
})
