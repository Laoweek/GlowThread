import { describe, expect, it } from 'vitest'
import { createApp, h, nextTick, ref } from 'vue'
import CommentItem from '@/comments/components/CommentItem.vue'
import { createStore } from '@/comments/store'
import { OVERLAYS, STORE } from '@/comments/context'
import type { Comment, ListResponse, PageConfig } from '@/shared/types'

// The part hooks and state attributes on a rendered comment (registry: src/design/parts.ts,
// checked by tests/parts.test.ts).
const config: PageConfig = {
  pageId: 42,
  enabled: true,
  state: 'open',
  defaultSort: 'hot',
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

async function render(c: Comment): Promise<HTMLElement> {
  const data: ListResponse = {
    page: { id: 42, title: 'Sourdough starter', state: 'open' }, sort: 'hot',
    viewer: { loggedIn: true, canPost: true, postBlockedReason: null, canReact: true, canReport: true, canModerate: false, canErase: false, canConfigure: false },
    config: { maxLength: 1000, maxMentions: 5, reportReasons: ['spam', 'other'], pageSize: 20, replyPreview: 3 },
    count: 1, pinned: [], popular: [], comments: [c], next: null,
  }
  const store = createStore({ config, get: (async () => JSON.parse(JSON.stringify(data))) as any, post: (async () => ({})) as any })
  await store.load()
  const el = document.createElement('div')
  document.body.appendChild(el)
  const app = createApp({ render: () => h(CommentItem, { comment: c, portal: document.body }) })
  app.provide(STORE, store)
  app.provide(OVERLAYS, { phone: ref(false), openReport() {} })
  app.mount(el)
  await nextTick()
  return el
}

describe('hooks on a comment', () => {
  it('marks the comment, its parts and its state', async () => {
    const reply = comment({ id: 2, parentId: 1, depth: 1, author: { name: 'Alice', id: 4, anon: false, temp: false, hidden: false }, viewer: { own: true, liked: false, reported: false, canReply: true, canLike: false, canReport: false, canDelete: true } })
    const el = await render(comment({ pinned: true, replies: [reply], replyCount: 3, totalReplies: 3 }))
    const top = el.querySelector('article') as HTMLElement
    expect(top.classList.contains('ext-glowthread-comment')).toBe(true)
    expect(top.getAttribute('data-glowthread-depth')).toBe('0')
    expect(top.getAttribute('data-glowthread-status')).toBe('visible')
    expect(top.hasAttribute('data-glowthread-pinned')).toBe(true)
    expect(top.hasAttribute('data-pinned')).toBe(true)
    expect(top.hasAttribute('data-glowthread-own')).toBe(false)
    // The comment's own parts, outside its replies.
    const row = top.querySelector(':scope > .ext-glowthread-comment-row') as HTMLElement
    for (const p of ['avatar', 'name', 'meta', 'body', 'actions', 'like', 'menu-trigger']) {
      expect(row.querySelector('.ext-glowthread-' + p), p).not.toBeNull()
    }
    expect(row.querySelector('.ext-glowthread-badge')!.getAttribute('data-glowthread-tone')).toBe('accent')
    const replyButton = row.querySelector('.ext-glowthread-reply') as HTMLElement
    expect(replyButton.classList.contains('ext-glowthread-button')).toBe(true)
    expect(replyButton.getAttribute('data-glowthread-variant')).toBe('ghost')
    // Replies: inside the comment, one level down, the reader's own, not pinned.
    const replies = top.querySelector(':scope > .ext-glowthread-replies') as HTMLElement
    const inner = replies.querySelector('article') as HTMLElement
    expect(inner.classList.contains('ext-glowthread-comment')).toBe(true)
    expect(inner.getAttribute('data-glowthread-depth')).toBe('1')
    expect(inner.hasAttribute('data-glowthread-own')).toBe(true)
    expect(inner.hasAttribute('data-glowthread-pinned')).toBe(false)
    expect(replies.querySelector('.ext-glowthread-replies-toggle')).not.toBeNull()
  })

  it('marks a held comment and its badge', async () => {
    const el = await render(comment({ status: 'held', viewer: { own: true, liked: false, reported: false, canReply: false, canLike: false, canReport: false, canDelete: true } }))
    const top = el.querySelector('article') as HTMLElement
    expect(top.getAttribute('data-glowthread-status')).toBe('held')
    expect(top.hasAttribute('data-glowthread-own')).toBe(true)
    expect(top.hasAttribute('data-glowthread-pinned')).toBe(false)
    expect(top.querySelector('.ext-glowthread-badge')!.getAttribute('data-glowthread-tone')).toBe('warning')
  })
})
