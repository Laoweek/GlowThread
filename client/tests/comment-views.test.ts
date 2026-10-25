import { afterEach, describe, expect, it, vi } from 'vitest'
import { createApp, h, nextTick, ref } from 'vue'
import type { Component, Ref } from 'vue'
import CommentItem from '@/comments/components/CommentItem.vue'
import LinkedThread from '@/comments/components/LinkedThread.vue'
import ModQueue from '@/comments/components/ModQueue.vue'
import { createStore } from '@/comments/store'
import type { Store } from '@/comments/store'
import { OVERLAYS, STORE } from '@/comments/context'
import { toasts } from '@/ui/toast'
import type { Comment, ListResponse, ListViewer, PageConfig } from '@/shared/types'

// Rendered comment views: what a reader sees and what the bulk bar would act on.
const config: PageConfig = {
  pageId: 42, enabled: true, state: 'open', defaultSort: 'hot',
  listUrls: { hot: '/rest.php/glowthread/v1/page/42/comments?sort=hot', new: '/rest.php/glowthread/v1/page/42/comments?sort=new' },
  restBase: '/rest.php/glowthread/v1',
  features: { legacyCompat: true },
}

function comment(over: Partial<Comment> = {}): Comment {
  const id = over.id || 1
  return {
    id, seq: id, pageId: 42, parentId: null, threadId: null, depth: 0, status: 'visible',
    author: { name: 'Bob', id: 3, anon: false, temp: false, hidden: false },
    created: '2026-10-08T00:00:00Z', edited: null, segments: [{ type: 'text', text: 'hi' }],
    likes: 2, pinned: false, replyTo: null,
    viewer: { own: false, liked: false, reported: false, canReply: true, canLike: true, canReport: true, canDelete: false },
    replies: [], replyCount: 0, totalReplies: 0,
    ...over,
  }
}

const mounted: Array<{ unmount(): void }> = []
afterEach(() => {
  mounted.splice(0).forEach((app) => app.unmount())
  document.body.innerHTML = ''
  toasts.splice(0)
})

async function render(
  comments: Comment[],
  { viewer = {}, view, phone = ref(false) }: { viewer?: Partial<ListViewer>, view?: (store: Store) => [Component, Record<string, unknown>], phone?: Ref<boolean> } = {},
) {
  const data: ListResponse = {
    page: { id: 42, title: 'Sourdough starter', state: 'open' }, sort: 'hot',
    viewer: { loggedIn: true, canPost: true, postBlockedReason: null, canReact: true, canReport: true, canModerate: false, canErase: false, canConfigure: false, ...viewer },
    config: { maxLength: 1000, maxMentions: 5, reportReasons: ['spam', 'other'], pageSize: 20, replyPreview: 3 },
    count: comments.length, pinned: [], popular: [], comments, next: null,
  }
  const get = vi.fn(async (url: string) => (url.indexOf('/moderation/') >= 0 ? { counts: { held: 0, reported: 0 } } : JSON.parse(JSON.stringify(data))))
  const post = vi.fn(async () => ({ result: 'success', id: 1, liked: false, likes: 1, changed: true }))
  const store = createStore({ config, get: get as any, post: post as any })
  await store.load()
  const [component, props] = view ? view(store) : [CommentItem, { comment: store.find(comments[0].id) }]
  const el = document.createElement('div')
  document.body.appendChild(el)
  const app = createApp({ render: () => h(component, { portal: document.body, ...props }) })
  app.provide(STORE, store)
  app.provide(OVERLAYS, { phone, openReport() {} })
  app.mount(el)
  mounted.push(app)
  await nextTick()
  return { el, store, get, post }
}

describe('Select mode', () => {
  const thread = () => comment({ replies: [comment({ id: 2, parentId: 1, threadId: 1, depth: 1 })], replyCount: 1, totalReplies: 1 })

  it('drops a selected reply when the reader folds its conversation', async () => {
    const { el, store } = await render([thread()])
    store.state.folds[1] = false
    store.setManage(true)
    store.toggleSelected(2)
    await nextTick()
    const hide = [...el.querySelectorAll('button')].find((b) => b.textContent!.indexOf('Hide replies') >= 0) as HTMLButtonElement
    hide.click()
    await nextTick()
    expect(el.querySelector('[data-comment="2"]')).toBeNull()
    expect(store.state.selected).toEqual([])
  })

  it('drops a selected reply when the screen narrows to a phone and the conversation folds', async () => {
    const phone = ref(false)
    const { el, store } = await render([thread()], { phone })
    store.setManage(true)
    store.toggleSelected(1)
    store.toggleSelected(2)
    phone.value = true
    await nextTick()
    expect(el.querySelector('[data-comment="2"]')).toBeNull()
    expect(store.state.selected).toEqual([1])
  })
})

describe('a #gt- link to a comment on another page', () => {
  it('says only that it is on another page, with a link: never the title its creator chose', async () => {
    const foreign = comment({ id: 9, pageId: 99 })
    const { el } = await render([comment()], {
      view: (store) => {
        store.state.linked = {
          status: 'elsewhere', error: null, id: 9,
          data: { page: { id: 99, title: 'Official notice: send your password to Mallory', state: 'open' }, focus: 9, thread: foreign, truncated: false },
        }
        return [LinkedThread, { focus: 9 }]
      },
    })
    const note = el.querySelector('[role="note"]') as HTMLElement
    expect(note.textContent).toContain('This comment is on another page.')
    expect(note.textContent).not.toContain('Mallory')
    expect(note.querySelector('a')!.getAttribute('href')).toContain('Special:GlowThreadLink/9')
  })
})

describe('liking with a new account', () => {
  const gate: Partial<ListViewer> = { canReact: false, reactBlockedReason: 'newaccount' }
  const notYet = { own: false, liked: false, reported: false, canReply: true, canLike: false, canReport: true, canDelete: false }

  it('offers the like button and says when liking becomes possible, without asking the server', async () => {
    const { el, post } = await render([comment({ viewer: notYet })], { viewer: gate })
    const button = el.querySelector('button.ext-glowthread-like') as HTMLButtonElement
    expect(button).not.toBeNull()
    button.click()
    await nextTick()
    expect(toasts[toasts.length - 1].message).toBe('You can like comments once your account is a little older or one of your comments is approved.')
    expect(post).not.toHaveBeenCalled()
  })

  it('still lets the reader take back a like', async () => {
    const { el, post } = await render([comment({ viewer: { ...notYet, liked: true } })], { viewer: gate })
    ;(el.querySelector('button.ext-glowthread-like') as HTMLButtonElement).click()
    await nextTick()
    expect(post).toHaveBeenCalledWith('glowthreadreact', { id: 1, value: 0 })
  })

  it('shows only the count when the reader cannot like for another reason', async () => {
    const { el } = await render([comment({ viewer: notYet })], { viewer: { canReact: false } })
    expect(el.querySelector('button.ext-glowthread-like')).toBeNull()
    expect(el.querySelector('.ext-glowthread-like')!.textContent).toContain('2 likes')
  })
})

describe('the author\'s own held comment', () => {
  it('says it waits for review without naming a reason', async () => {
    // Authors get no hold reasons (no `mod` block), or only those about their account.
    const own = { own: true, liked: false, reported: false, canReply: true, canLike: false, canReport: false, canDelete: true }
    const { el } = await render([comment({ status: 'held', viewer: own })])
    expect(el.textContent).toContain('Only you can see this · awaiting review')
    expect(el.textContent).toContain('A moderator will review this comment. Others will see it once it is approved.')
    expect(el.textContent).not.toContain('Held for review')
  })
})

describe('the moderation filter (Awaiting review)', () => {
  it('offers Load more, not "none", when a page comes back empty with more to load', async () => {
    const queued = (items: unknown[], next: string | null) => ({ tab: 'held', items, next, counts: { reported: 0, held: 1, deleted: 0 } })
    const held = comment({ id: 7, status: 'held' })
    const { el, get, store } = await render([comment()], { viewer: { canModerate: true }, view: () => [ModQueue, {}] })
    get.mockImplementation(async (url: string) => (url.indexOf('cursor=c1') >= 0 ? queued([{ comment: held, page: { id: 42, title: 'Sourdough starter', exists: true }, context: null, reports: [] }], null) : queued([], 'c1')))
    await store.setModFilter('held')
    await nextTick()
    expect(el.querySelector('.ext-glowthread-empty')).toBeNull()
    ;(el.querySelector('.ext-glowthread-load-more') as HTMLButtonElement).click()
    await vi.waitFor(() => expect(el.querySelector('[data-comment="7"]')).not.toBeNull())
    expect(el.querySelector('.ext-glowthread-load-more')).toBeNull()
  })
})
