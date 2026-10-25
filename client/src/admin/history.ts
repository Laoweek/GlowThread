// The record shown beside the queue: an author's history or a page's comment state.
// It follows the focused item, so answers are cached briefly and stale answers dropped.
import { reactive } from 'vue'
import type { PageHistoryResponse, PageState, PageStateResult, UserHistoryResponse } from '@/shared/types'

export type Subject = { type: 'user', name: string } | { type: 'page', id: number, title: string }

export interface HistoryDeps {
  restBase: string
  get<T>(url: string): Promise<T>
  post<T>(module: string, params: Record<string, string | number | undefined>): Promise<T>
  now?: () => number
}

type Status = 'idle' | 'loading' | 'ready' | 'error'
type Data = UserHistoryResponse | PageHistoryResponse

/** How long a record stays fresh while the moderator moves between items. */
const TTL = 60_000

export const subjectKey = (s: Subject) => (s.type === 'user' ? 'user:' + s.name : 'page:' + s.id)

export function createHistory(deps: HistoryDeps) {
  const now = deps.now || (() => Date.now())
  const cache = new Map<string, { at: number, data: Data }>()
  const state = reactive({
    subject: null as Subject | null,
    status: 'idle' as Status,
    error: null as unknown,
    user: null as UserHistoryResponse | null,
    page: null as PageHistoryResponse | null,
  })
  let wanted = ''

  function url(s: Subject): string {
    return s.type === 'user'
      ? deps.restBase + '/moderation/user/' + encodeURIComponent(s.name) + '?limit=8'
      : deps.restBase + '/moderation/page/' + s.id
  }

  function apply(s: Subject, data: Data) {
    if (s.type === 'user') {
      state.user = data as UserHistoryResponse
      state.page = null
    } else {
      state.page = data as PageHistoryResponse
      state.user = null
    }
    state.status = 'ready'
    state.error = null
  }

  async function show(s: Subject, { force = false } = {}) {
    const key = subjectKey(s)
    wanted = key
    state.subject = s
    const hit = cache.get(key)
    if (hit && !force && now() - hit.at < TTL) {
      apply(s, hit.data)
      return
    }
    // Keep the previous record on screen while a refresh of the same subject runs.
    const same = (s.type === 'user' && state.user && state.user.user.name === s.name) || (s.type === 'page' && state.page && state.page.page.id === s.id)
    if (!same) {
      state.status = 'loading'
      state.user = null
      state.page = null
    }
    try {
      const data = await deps.get<Data>(url(s))
      cache.set(key, { at: now(), data })
      if (wanted === key) apply(s, data)
    } catch (e) {
      if (wanted !== key) return
      state.status = 'error'
      state.error = e
    }
  }

  /** After a moderation action the records it touched are out of date. */
  function invalidate() {
    cache.clear()
    if (state.subject && state.status === 'ready') show(state.subject, { force: true })
  }

  function clear() {
    wanted = ''
    state.subject = null
    state.status = 'idle'
    state.user = null
    state.page = null
  }

  async function setPageState(next: PageState, reason: string): Promise<PageStateResult> {
    const page = state.page
    if (!page) throw new Error('no page')
    const r = await deps.post<PageStateResult>('glowthreadpage', { pageid: page.page.id, state: next, reason: reason || undefined })
    cache.delete('page:' + page.page.id)
    if (state.page && state.page.page.id === page.page.id) {
      state.page.page.state = r.state
      show({ type: 'page', id: page.page.id, title: page.page.title || '' }, { force: true })
    }
    return r
  }

  return { state, show, invalidate, clear, setPageState }
}

export type History = ReturnType<typeof createHistory>
