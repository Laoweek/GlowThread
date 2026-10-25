// The whole comment section in a test: a page config in mw.config, a logged-out reader, and the
// app mounted the way the loader does it (resources/init/index.js, src/comments/main.ts).
import { vi } from 'vitest'
import { nextTick } from 'vue'
import type { App } from 'vue'
import { mount } from '@/comments/main'
import type { EarlyResponse } from '@/lib/api'
import type { Comment, ListResponse, PageConfig } from '@/shared/types'

export const config: PageConfig = {
  pageId: 42, enabled: true, state: 'open', defaultSort: 'hot',
  listUrls: { hot: '/rest.php/glowthread/v1/page/42/comments?sort=hot', new: '/rest.php/glowthread/v1/page/42/comments?sort=new' },
  restBase: '/rest.php/glowthread/v1',
  features: { legacyCompat: false },
}

export function comment(id: number, over: Partial<Comment> = {}): Comment {
  return {
    id, seq: id, pageId: 42, parentId: null, threadId: null, depth: 0, status: 'visible',
    author: { name: 'Bob ' + id, id: 3, anon: false, temp: false, hidden: false },
    created: '2026-06-08T00:00:00Z', edited: null, segments: [{ type: 'text', text: 'comment ' + id }],
    likes: id % 3, pinned: false, replyTo: null,
    viewer: { own: false, liked: false, reported: false, canReply: true, canLike: true, canReport: true, canDelete: false },
    replies: [], replyCount: 0, totalReplies: 0,
    ...over,
  }
}

/** A first page of `n` threads; thread 2 has a reply. */
export function list(n: number): ListResponse {
  const threads = Array.from({ length: n }, (_, i) => comment(i + 1))
  if (n > 1) {
    threads[1].replies = [comment(101, { parentId: 2, threadId: 2, depth: 1 })]
    threads[1].replyCount = threads[1].totalReplies = 1
  }
  return {
    page: { id: 42, title: 'Sourdough starter', state: 'open' }, sort: 'hot',
    viewer: { loggedIn: false, canPost: false, postBlockedReason: 'login', canReact: false, reactBlockedReason: null, canReport: false, canModerate: false, canErase: false, canConfigure: false },
    config: { maxLength: 1000, maxMentions: 5, reportReasons: ['spam', 'other'], pageSize: 20, replyPreview: 3 },
    count: n + 1, pinned: [], popular: [], comments: threads, next: null,
  }
}

export const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })

const g = globalThis as any
let saved: { get: unknown, isAnon: unknown } | null = null

/** mw as on an article for a logged-out reader. Undo with `restoreMw`. */
export function pageMw() {
  saved = { get: g.mw.config.get, isAnon: g.mw.user.isAnon }
  const values: Record<string, unknown> = { wgGlowThread: config, wgUserName: null, wgWikiID: 'testwiki', wgPageName: 'Sourdough_starter', wgUserLanguage: 'en' }
  g.mw.config.get = (k: string) => (k in values ? values[k] : null)
  g.mw.user.isAnon = () => true
}

export function restoreMw() {
  if (!saved) return
  g.mw.config.get = saved.get
  g.mw.user.isAnon = saved.isAnon
  saved = null
}

/** Mounts the section like the loader: a container with the reserved height, and the early list request. */
export function mountSection(early?: EarlyResponse | null, reserve = 900): { el: HTMLElement, app: App } {
  const el = document.createElement('div')
  el.id = 'glowthread'
  el.className = 'ext-glowthread'
  el.setAttribute('data-gt-reserve', String(reserve))
  document.body.appendChild(el)
  const app = mount(el, early)
  return { el, app }
}

/** Lets fetch() answers, json() and Vue's updates through (no timers). */
export async function settle() {
  for (let i = 0; i < 10; i++) {
    await Promise.resolve()
    await nextTick()
  }
  await new Promise((r) => setTimeout(r, 0))
  await nextTick()
}

export function mockFetch(answer: (url: string) => Response | Promise<Response>) {
  const fetch = vi.fn(async (url: string) => answer(url))
  vi.stubGlobal('fetch', fetch)
  return fetch
}
