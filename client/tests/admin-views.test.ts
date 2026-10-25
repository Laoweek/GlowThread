import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick, ref } from 'vue'
import type { Component } from 'vue'
import QueueView from '@/admin/components/QueueView.vue'
import UserRecord from '@/admin/components/UserRecord.vue'
import SettingField from '@/admin/components/SettingField.vue'
import QueueItem from '@/admin/components/QueueItem.vue'
import { createQueue } from '@/admin/queue'
import { queueRoute } from '@/admin/router'
import { HUB } from '@/admin/context'
import type { Hub } from '@/admin/context'
import type { QueueResponse, UserHistoryResponse } from '@/shared/types'
import { item, page } from './admin-fixtures'

// Rendered views of the moderation hub, with a hub around the real queue store.
const REST = '/rest.php/glowthread/v1'

const mounted: Array<{ unmount(): void }> = []
afterEach(() => {
  mounted.splice(0).forEach((app) => app.unmount())
  document.body.innerHTML = ''
})

function hubWith(pages: Record<string, QueueResponse>, post = vi.fn()): Hub {
  // The first page, or the page after a cursor.
  const get = vi.fn(async (url: string) => {
    const cursor = /cursor=([^&]+)/.exec(url)
    return JSON.parse(JSON.stringify(pages[cursor ? cursor[1] : 'first']))
  })
  const queue = createQueue({ restBase: REST, get: get as any, post: post as any, resolvePage: async () => null, exactUndo: () => false })
  return {
    config: { subPage: '', restBase: REST, canErase: false, canConfigure: false },
    route: ref(queueRoute({ tab: 'held' })),
    queue,
    history: {} as Hub['history'],
    settings: {} as Hub['settings'],
    viewer: ref(null),
    blocked: ref(false),
    phone: ref(false),
    wide: ref(true),
    navigate() {},
    href: () => '#',
    lastQueue: ref(queueRoute()),
    act: async () => undefined,
    showRecord() {},
    recordMode: ref('user'),
    confirmErase() {},
    openShortcuts() {},
    focusItem() {},
  }
}

async function render(hub: Hub, component: Component, props: Record<string, unknown> = {}): Promise<HTMLElement> {
  const el = document.createElement('div')
  document.body.appendChild(el)
  const app = createApp({ render: () => h(component, props) })
  app.provide(HUB, hub)
  app.mount(el)
  mounted.push(app)
  await nextTick()
  return el
}

const loadMore = (el: HTMLElement) => el.querySelector('.ext-glowthread-load-more') as HTMLButtonElement | null

describe('the queue', () => {
  it('offers Load more, not "Queue clear", when a page comes back empty with more to load', async () => {
    // The server skipped comments on pages the moderator cannot read.
    const a = item({ status: 'held' })
    const hub = hubWith({ first: page([], 'c1', undefined, 'held'), c1: page([a], null, undefined, 'held') })
    await hub.queue.show('held', { page: '', user: '', q: '' })
    const el = await render(hub, QueueView)
    expect(el.querySelector('.ext-glowthread-empty')).toBeNull()
    loadMore(el)!.click()
    await vi.waitFor(() => expect(el.querySelector('[data-comment="' + a.comment.id + '"]')).not.toBeNull())
    expect(loadMore(el)).toBeNull()
  })

  it('draws again only the items that change, not every loaded item', async () => {
    const items = [1, 2, 3, 4, 5, 6].map(() => item({ status: 'held' }))
    // The server never answers: the approved item stays out of the list.
    const hub = hubWith({ first: page(items, null, undefined, 'held') }, vi.fn(() => new Promise(() => undefined)))
    await hub.queue.show('held', { page: '', user: '', q: '' })
    await render(hub, QueueView)
    // Every item asks for its checkbox label once per render.
    const message = vi.spyOn((globalThis as any).mw, 'message')
    const drawn = () => message.mock.calls.filter(([key]) => key === 'glowthread-ui-admin-select').length
    try {
      await hub.queue.move(1)
      await nextTick()
      // The item focus left and the item it reached.
      expect(drawn()).toBe(2)
      message.mockClear()
      // An item after the focused one leaves the tab: no other item is drawn again.
      hub.queue.act('approve', [items[4].comment.id])
      await nextTick()
      expect(hub.queue.state.items).toHaveLength(5)
      expect(drawn()).toBe(0)
    } finally {
      message.mockRestore()
    }
  })

  it('says the queue is clear when nothing is left to load', async () => {
    const hub = hubWith({ first: page([], null, undefined, 'held') })
    await hub.queue.show('held', { page: '', user: '', q: '' })
    const el = await render(hub, QueueView)
    expect(el.querySelector('.ext-glowthread-empty')).not.toBeNull()
    expect(loadMore(el)).toBeNull()
  })
})

describe('an author\'s record', () => {
  const data = (next: string | null): UserHistoryResponse => ({
    user: { name: 'Alice', id: 3, actorId: 7, registered: true, registration: null, editCount: 1, groups: [], blocks: [] },
    counts: { visible: 4, held: 0, deleted: 0, erased: null },
    reports: { filed: 0, open: 0, upheld: 0, dismissed: 0 },
    comments: [],
    next,
    moderation: [],
  })

  it('does not say "No comments." when the server skipped some and has more', async () => {
    const hub = hubWith({})
    expect((await render(hub, UserRecord, { data: data('c1') })).textContent).not.toContain('No comments.')
    expect((await render(hub, UserRecord, { data: data(null) })).textContent).toContain('No comments.')
  })
})

describe('a setting that is off at 0 and otherwise needs 2 or more', () => {
  const info = { value: 3, default: 3, editable: true, overridden: false, type: 'int' as const, min: 0, minOn: 2, max: 100 }

  it('says so in its range, and in its error when 1 is entered', async () => {
    const hub = hubWith({})
    const ok = await render(hub, SettingField, { name: 'autoHoldReporters', info, value: 0, readonly: false })
    expect(ok.textContent).toContain('0 (off) or 2 to 100')
    expect(ok.querySelector('[role="alert"]')).toBeNull()
    const bad = await render(hub, SettingField, { name: 'autoHoldReporters', info, value: 1, readonly: false })
    expect(bad.querySelector('[role="alert"]')!.textContent!.trim()).toBe('Enter 0 to turn it off, or a whole number from 2 to 100.')
    expect(bad.querySelector('input')!.getAttribute('aria-invalid')).toBe('true')
  })
})

describe('which rule held a comment', () => {
  it('tests the comment as stored, with its mentions as tokens', async () => {
    const posted: Array<Record<string, unknown>> = []
    ;(globalThis as any).mw.Api = class {
      postWithToken(_t: string, p: Record<string, unknown>) {
        posted.push(p)
        return Promise.resolve({ glowthreadfiltertest: { held: true, matches: [], errors: [], invalid: [], rules: 1 } })
      }
    }
    const hub = hubWith({})
    hub.config.canConfigure = true
    const held = item({ status: 'held', segments: [{ type: 'text', text: 'buy now ' }, { type: 'mention', text: '@Bob', user: 'Bob', id: 4, exists: true } as never] })
    held.comment.mod!.holdReasons = ['filter']
    const el = await render(hub, QueueItem, { item: held, focused: false, busy: false, stale: false, frequent: {}, tab: 'held' })
    ;([...el.querySelectorAll('button')].find((b) => b.textContent!.trim() === 'Which rule?') as HTMLButtonElement).click()
    await vi.waitFor(() => expect(posted.length).toBe(1))
    expect(posted[0]).toMatchObject({ action: 'glowthreadfiltertest', text: 'buy now <@4>' })
    delete (globalThis as any).mw.Api
  })
})
