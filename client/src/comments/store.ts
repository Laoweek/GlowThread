// State and actions of the comment section. No DOM and no mw here: the transport is
// injected (Deps), so tests drive the store with fakes (store.test.ts).
import { computed, reactive } from 'vue'
import type {
  Comment, DeleteResult, ListResponse, ModerateResult, PageConfig, PageHistoryResponse,
  PageState, PostResult, QueueItem, QueueResponse, ReactResult, ReportReason, ReportResult, Sort,
  RepliesResponse, ThreadResponse, PageStateResult,
} from '@/shared/types'
import { ApiError } from '@/lib/api'

export interface Deps {
  config: PageConfig
  get<T>(url: string): Promise<T>
  post<T>(module: string, params: Record<string, string | number | undefined>): Promise<T>
  now?: () => number
}

type Status = 'idle' | 'loading' | 'ready' | 'error'

/** Replies loaded per request when a conversation is expanded (the route allows 1 to 100). */
export const REPLIES_PAGE = 50

/** Paging state of the replies under one comment: `next` is the cursor, `done` when all are in. */
interface RepliesSlot {
  status: Status
  error: unknown
  next: string | null
  done: boolean
}

interface ListSlot {
  status: Status
  error: unknown
  data: ListResponse | null
  loadingMore: boolean
  moreError: unknown
}

export type ModAction = 'approve' | 'delete' | 'restore' | 'pin' | 'unpin' | 'dismiss'
export type ModFilter = 'held' | 'reported'

const slot = (): ListSlot => ({ status: 'idle', error: null, data: null, loadingMore: false, moreError: null })

export function createStore(deps: Deps) {
  const cfg = deps.config
  const now = deps.now || (() => Date.now())

  const state = reactive({
    sort: (cfg.defaultSort === 'new' ? 'new' : 'hot') as Sort,
    lists: { hot: slot(), new: slot() } as Record<Sort, ListSlot>,
    modFilter: null as ModFilter | null,
    queue: { status: 'idle' as Status, error: null as unknown, items: [] as QueueItem[], next: null as string | null, loadingMore: false },
    modCounts: null as { held: number, reported: number } | null,
    manage: false,
    selected: [] as number[],
    // A conversation asked for (a #gt- link, the popular strip) whose thread is not in the list:
    // shown above it. `elsewhere`: the comment is on another page.
    linked: { status: 'idle' as Status | 'elsewhere', error: null as unknown, data: null as ThreadResponse | null, id: 0 },
    // Top-level comments whose replies the reader folded (true) or opened (false). Unset: folded
    // on phones, the preview elsewhere.
    folds: {} as Record<number, boolean>,
    // Paging of the replies under a comment, by its ID.
    replies: {} as Record<number, RepliesSlot>,
    // "<surface>:<id>": the same comment can show in the list and in the linked conversation.
    replyingTo: null as string | null,
    confirmingDelete: null as string | null,
    fresh: [] as number[],
    rateLimitedUntil: 0,
  })

  const current = computed(() => state.lists[state.sort])
  /** The latest list response of either sort: page, viewer, config and count. */
  const data = computed(() => current.value.data || state.lists.hot.data || state.lists.new.data)
  const viewer = computed(() => (data.value ? data.value.viewer : null))
  const canModerate = computed(() => !!(viewer.value && viewer.value.canModerate))

  // ---- traversal -------------------------------------------------------------------

  function eachTree(fn: (c: Comment, parent: Comment | null) => void) {
    const visit = (c: Comment | null | undefined, parent: Comment | null) => {
      if (!c) return
      fn(c, parent)
      for (const r of c.replies || []) visit(r, c)
    }
    for (const sort of ['hot', 'new'] as Sort[]) {
      const d = state.lists[sort].data
      if (!d) continue
      d.pinned.forEach((c) => visit(c, null))
      d.popular.forEach((c) => visit(c, null))
      d.comments.forEach((c) => visit(c, null))
    }
    if (state.linked.data) visit(state.linked.data.thread, null)
    for (const item of state.queue.items) {
      visit(item.comment, null)
      if (item.context) visit(item.context, null)
    }
  }

  function each(id: number, fn: (c: Comment) => void) {
    eachTree((c) => {
      if (c.id === id) fn(c)
    })
  }

  function find(id: number): Comment | null {
    let found: Comment | null = null
    each(id, (c) => {
      found = found || c
    })
    return found
  }

  function countBelow(c: Comment): number {
    return (c.replies || []).reduce((n, r) => n + 1 + countBelow(r), 0)
  }

  function bumpCount(delta: number) {
    for (const sort of ['hot', 'new'] as Sort[]) {
      const d = state.lists[sort].data
      if (d) d.count = Math.max(0, d.count + delta)
    }
  }

  // ---- reading ---------------------------------------------------------------------

  function listUrl(sort: Sort, cursor?: string | null): string {
    // The first page must be the canonical URL, byte for byte (Varnish purges it).
    if (!cursor) return cfg.listUrls[sort]
    return cfg.restBase + '/page/' + cfg.pageId + '/comments?sort=' + sort + '&cursor=' + encodeURIComponent(cursor)
  }

  async function load(sort: Sort = state.sort, { quiet = false } = {}) {
    const s = state.lists[sort]
    if (!quiet) {
      s.status = 'loading'
      s.error = null
    }
    try {
      const d = await deps.get<ListResponse>(listUrl(sort))
      for (const c of d.comments) normalize(c)
      for (const c of d.pinned) normalize(c)
      const old = s.data
      if (quiet && old && old.comments.length > d.comments.length) {
        // A refresh after a moderator action: keep the pages the reader already loaded.
        const fresh = new Set(d.comments.concat(d.pinned).map((c) => c.id))
        d.comments = d.comments.concat(old.comments.filter((c) => !fresh.has(c.id)))
        d.next = old.next
      }
      s.data = d
      s.status = 'ready'
      s.error = null
      s.moreError = null
      if (d.viewer.canModerate && !state.modCounts) loadModCounts()
    } catch (e) {
      if (!quiet || !s.data) {
        s.status = 'error'
        s.error = e
      }
      throw e
    }
  }

  async function setSort(sort: Sort) {
    // Bulk actions act on what is shown: a selection made in another view is dropped.
    if (sort !== state.sort) state.selected = []
    state.sort = sort
    if (state.lists[sort].status === 'idle') await load(sort).catch(() => undefined)
  }

  async function loadMore() {
    const s = current.value
    if (!s.data || !s.data.next || s.loadingMore) return
    s.loadingMore = true
    s.moreError = null
    const target = s.data
    try {
      const d = await deps.get<ListResponse>(listUrl(state.sort, target.next))
      // The list was replaced meanwhile (a refresh): this page belongs to the old one.
      if (s.data !== target) return
      const seen = new Set(target.comments.map((c) => c.id))
      for (const c of d.comments) {
        if (!seen.has(c.id)) target.comments.push(normalize(c))
      }
      target.next = d.next
    } catch (e) {
      s.moreError = e
    } finally {
      s.loadingMore = false
    }
  }

  // ---- conversations -----------------------------------------------------------------

  /** The top-level comment in the current list whose conversation holds `id`, if any. */
  function topInList(id: number): Comment | null {
    const d = current.value.data
    if (!d) return null
    const holds = (c: Comment): boolean => c.id === id || (c.replies || []).some(holds)
    return d.pinned.concat(d.comments).find(holds) || null
  }

  /** Replies not shown under `c` that its own "Show N more replies" stands for. */
  function hiddenReplies(c: Comment): number {
    const slot = state.replies[c.id]
    if (slot && slot.done) return 0
    const shown = c.replies || []
    if (c.depth > 0) return Math.max(0, (c.replyCount || 0) - shown.length)
    // A shown reply with more replies of its own has its own link for them.
    const behindReplies = shown.reduce((n, r) => n + hiddenReplies(r), 0)
    return Math.max(0, (c.totalReplies || 0) - countBelow(c) - behindReplies)
  }

  /** Loads the next replies under `c` into every copy of it (the list, the linked conversation). */
  async function loadReplies(c: Comment): Promise<number[]> {
    if (!state.replies[c.id]) state.replies[c.id] = { status: 'idle', error: null, next: null, done: false }
    // Through the reactive record, so the templates follow the changes.
    const slot = state.replies[c.id]
    if (slot.status === 'loading') return []
    slot.status = 'loading'
    slot.error = null
    try {
      const before = new Set((c.replies || []).map((x) => x.id))
      let added: number[] = []
      // Pages the reader already has (the preview, or a linked conversation the server cut
      // at 500 replies) add nothing: go on to the first page with something new.
      for (let i = 0; i < 20 && !added.length && !slot.done; i++) {
        const q = '?limit=' + REPLIES_PAGE + (slot.next ? '&cursor=' + encodeURIComponent(slot.next) : '')
        const r = await deps.get<RepliesResponse>(cfg.restBase + '/comment/' + c.id + '/replies' + q)
        added = added.concat(r.replies.filter((x) => !before.has(x.id)).map((x) => x.id))
        each(c.id, (x) => mergeReplies(x, r.replies.map(normalize)))
        slot.next = r.next
        slot.done = !r.next
      }
      slot.status = 'ready'
      return added
    } catch (e) {
      slot.status = 'error'
      slot.error = e
      throw e
    }
  }

  /**
   * Shows the conversation of comment `id`: in the list when its thread is there (unfolded,
   * completed from the server when the comment is not loaded yet), otherwise above the list.
   */
  async function showComment(id: number): Promise<'list' | 'linked' | 'elsewhere' | 'error'> {
    const top = topInList(id)
    if (top) {
      state.folds[top.id] = false
      return 'list'
    }
    // A conversation shown before goes, with its part of the selection.
    closeLinked()
    state.linked = { status: 'loading', error: null, data: null, id }
    try {
      const d = await deps.get<ThreadResponse>(cfg.restBase + '/comment/' + id + '/thread')
      if (state.linked.id !== id) return 'error'
      normalize(d.thread)
      // Never show another page's comment as if it belonged here (a crafted #gt- link).
      if (d.page && d.page.id !== cfg.pageId) {
        state.linked.data = d
        state.linked.status = 'elsewhere'
        return 'elsewhere'
      }
      const listed = topInList(d.thread.id)
      if (listed) {
        each(d.thread.id, (x) => mergeReplies(x, d.thread.replies || []))
        state.folds[d.thread.id] = false
        closeLinked()
        return 'list'
      }
      state.linked.data = d
      state.linked.status = 'ready'
      return 'linked'
    } catch (e) {
      if (state.linked.id !== id) return 'error'
      state.linked.status = 'error'
      state.linked.error = e
      return 'error'
    }
  }

  function closeLinked() {
    const d = state.linked.data
    state.linked = { status: 'idle', error: null, data: null, id: 0 }
    // Its comments leave the selection, unless the list shows them too.
    if (d) deselect(subtree(d.thread).filter((id) => !topInList(id)))
  }

  async function loadModCounts() {
    try {
      const d = await deps.get<PageHistoryResponse>(cfg.restBase + '/moderation/page/' + cfg.pageId)
      state.modCounts = { held: d.counts.held, reported: d.counts.reported }
    } catch {
      state.modCounts = null
    }
  }

  function queueUrl(filter: ModFilter, cursor: string | null): string {
    return cfg.restBase + '/moderation/queue?tab=' + filter + '&pageid=' + cfg.pageId + (cursor ? '&cursor=' + encodeURIComponent(cursor) : '')
  }

  async function loadMoreQueue() {
    const filter = state.modFilter
    const q = state.queue
    if (!filter || !q.next || q.loadingMore) return
    q.loadingMore = true
    try {
      const d = await deps.get<QueueResponse>(queueUrl(filter, q.next))
      if (state.modFilter !== filter) return
      const seen = new Set(q.items.map((i) => i.comment.id))
      for (const item of d.items) {
        if (!seen.has(item.comment.id)) q.items.push({ ...item, comment: normalize(item.comment) })
      }
      q.next = d.next
    } catch (e) {
      q.error = e
    } finally {
      q.loadingMore = false
    }
  }

  async function setModFilter(filter: ModFilter | null) {
    if (filter !== state.modFilter) state.selected = []
    state.modFilter = filter
    state.queue.items = []
    state.queue.next = null
    if (!filter) return
    state.queue.status = 'loading'
    state.queue.error = null
    try {
      const d = await deps.get<QueueResponse>(queueUrl(filter, null))
      if (state.modFilter !== filter) return
      for (const item of d.items) normalize(item.comment)
      state.queue.items = d.items
      state.queue.next = d.next
      state.queue.status = 'ready'
    } catch (e) {
      if (state.modFilter !== filter) return
      state.queue.status = 'error'
      state.queue.error = e
    }
  }

  // ---- writing ---------------------------------------------------------------------

  async function post(text: string, replyTo: Comment | null = null): Promise<Comment> {
    try {
      const r = await deps.post<PostResult>('glowthreadpost', {
        pageid: cfg.pageId,
        text,
        replyto: replyTo ? replyTo.id : undefined,
      })
      const c = normalize(r.comment)
      state.fresh.push(c.id)
      insert(c)
      if (c.status === 'visible') bumpCount(1)
      return c
    } catch (e) {
      if (e instanceof ApiError && e.code === 'ratelimited') {
        const wait = Number(e.data && e.data.retryAfter) || 0
        state.rateLimitedUntil = wait ? now() + wait * 1000 : 0
      }
      throw e
    }
  }

  function insert(c: Comment) {
    if (!c.parentId) {
      for (const sort of ['hot', 'new'] as Sort[]) {
        const d = state.lists[sort].data
        if (d && !d.comments.some((x) => x.id === c.id)) d.comments.unshift(c)
      }
      return
    }
    const done = new Set<Comment>()
    eachTree((p) => {
      if (p.id !== c.parentId || done.has(p)) return
      done.add(p)
      p.replies = p.replies || []
      if (!p.replies.some((x) => x.id === c.id)) {
        p.replies.push(c)
        p.replyCount = (p.replyCount || 0) + 1
      }
    })
    const top = c.threadId
    if (top) {
      each(top, (t) => {
        t.totalReplies = (t.totalReplies || 0) + 1
      })
    }
  }

  function remove(id: number) {
    const target = find(id)
    if (!target) return
    for (const sort of ['hot', 'new'] as Sort[]) {
      const d = state.lists[sort].data
      if (!d) continue
      d.comments = d.comments.filter((c) => c.id !== id)
      d.pinned = d.pinned.filter((c) => c.id !== id)
      d.popular = d.popular.filter((c) => c.id !== id)
    }
    eachTree((c) => {
      if (c.replies && c.replies.some((r) => r.id === id)) {
        c.replies = c.replies.filter((r) => r.id !== id)
        c.replyCount = Math.max(0, (c.replyCount || 1) - 1)
      }
    })
    if (target.threadId) {
      each(target.threadId, (t) => {
        t.totalReplies = Math.max(0, (t.totalReplies || 1) - 1)
      })
    }
  }

  // Likes: optimistic, idempotent (value 1 or 0), last click wins. While a request
  // runs, further clicks only change the wanted value; when it returns, a request for
  // the newest wanted value follows if needed.
  const likeWanted = new Map<number, boolean>()
  const likeConfirmed = new Map<number, { liked: boolean, likes: number }>()
  const likeBusy = new Set<number>()

  function applyLike(id: number, liked: boolean, likes: number) {
    each(id, (c) => {
      c.viewer.liked = liked
      c.likes = likes
    })
  }

  function toggleLike(c: Comment): Promise<void> {
    const want = !c.viewer.liked
    if (!likeConfirmed.has(c.id)) likeConfirmed.set(c.id, { liked: c.viewer.liked, likes: c.likes })
    applyLike(c.id, want, Math.max(0, c.likes + (want ? 1 : -1)))
    likeWanted.set(c.id, want)
    return likeBusy.has(c.id) ? Promise.resolve() : sendLike(c.id)
  }

  async function sendLike(id: number): Promise<void> {
    const want = likeWanted.get(id) as boolean
    likeBusy.add(id)
    try {
      const r = await deps.post<ReactResult>('glowthreadreact', { id, value: want ? 1 : 0 })
      likeConfirmed.set(id, { liked: r.liked, likes: r.likes })
      if (likeWanted.get(id) === want) {
        applyLike(id, r.liked, r.likes)
        likeWanted.delete(id)
        likeConfirmed.delete(id)
      } else {
        likeBusy.delete(id)
        return await sendLike(id)
      }
    } catch (e) {
      const back = likeConfirmed.get(id)
      if (back) applyLike(id, back.liked, back.likes)
      likeWanted.delete(id)
      likeConfirmed.delete(id)
      throw e
    } finally {
      likeBusy.delete(id)
    }
  }

  async function report(c: Comment, reason: ReportReason, note: string): Promise<ReportResult> {
    const r = await deps.post<ReportResult>('glowthreadreport', { id: c.id, reason, note: note || undefined })
    each(c.id, (x) => {
      x.viewer.reported = true
    })
    return r
  }

  async function deleteOwn(c: Comment) {
    const r = await deps.post<DeleteResult>('glowthreaddelete', { id: c.id })
    const wasVisible = c.status === 'visible'
    let gone = 1
    if (canModerate.value) {
      each(c.id, (x) => {
        x.status = r.status
      })
    } else {
      // Readers stop seeing the replies under a removed comment too (docs/api.md).
      gone += c.depth === 0 ? c.totalReplies || 0 : countBelow(c)
      remove(c.id)
      if (state.linked.data && state.linked.data.thread.id === c.id) closeLinked()
    }
    if (wasVisible) bumpCount(-gone)
    state.confirmingDelete = null
  }

  /**
   * Runs a moderation action, or `undo` with the log IDs an earlier action returned (the
   * server puts back exactly what that action changed, docs/api.md).
   */
  async function moderate(ids: number[], action: ModAction | 'undo', logids?: number[]): Promise<ModerateResult> {
    const r = await deps.post<ModerateResult>('glowthreadmoderate', {
      ids: ids.join('|'),
      do: action,
      logids: logids ? logids.join('|') : undefined,
    })
    let delta = 0
    for (const item of r.results) {
      // "nochange": another moderator got there first; still take the current status.
      if (item.result === 'nochange' && item.status) {
        const st = item.status
        each(item.id, (c) => {
          c.status = st
        })
      }
      if (item.result !== 'success') continue
      // The status before, as the server saw it: the page's copy may be out of date.
      const before = find(item.id)
      const was = item.old || (before ? before.status : null)
      each(item.id, (c) => {
        if (item.status) c.status = item.status
        if (c.mod && (action === 'approve' || action === 'dismiss' || action === 'delete')) {
          c.mod.openReports = 0
          c.mod.reportReasons = []
        }
        if (action === 'pin') c.pinned = true
        if (action === 'unpin') c.pinned = false
      })
      if (was === 'visible' && item.status && item.status !== 'visible') delta--
      if (was && was !== 'visible' && item.status === 'visible') delta++
    }
    if (delta) bumpCount(delta)
    if (action === 'pin' || action === 'unpin' || action === 'undo') {
      // Pinned comments move between blocks, and an undo can reopen reports or put a pin
      // back: the server knows the result.
      await Promise.all((['hot', 'new'] as Sort[]).filter((s) => state.lists[s].data).map((s) => load(s, { quiet: true }).catch(() => undefined)))
    }
    loadModCounts()
    return r
  }

  async function setPageState(next: PageState) {
    const r = await deps.post<PageStateResult>('glowthreadpage', { pageid: cfg.pageId, state: next })
    for (const sort of ['hot', 'new'] as Sort[]) {
      const d = state.lists[sort].data
      if (d) d.page.state = r.state
    }
    // Posting rights and the list itself depend on the state.
    await Promise.all((['hot', 'new'] as Sort[]).filter((s) => state.lists[s].data).map((s) => load(s, { quiet: true }).catch(() => undefined)))
    return r
  }

  /** Bulk actions act on what is shown: comments that leave the view leave the selection. */
  function deselect(ids: number[]) {
    if (ids.length && state.selected.length) state.selected = state.selected.filter((id) => ids.indexOf(id) < 0)
  }

  /** A folded conversation hides its replies, except the reader's own new ones. */
  function dropFolded(c: Comment) {
    deselect((c.replies || []).filter((r) => state.fresh.indexOf(r.id) < 0).flatMap(subtree))
  }

  function toggleSelected(id: number) {
    const i = state.selected.indexOf(id)
    if (i >= 0) state.selected.splice(i, 1)
    else state.selected.push(id)
  }

  function setManage(on: boolean) {
    state.manage = on
    state.selected = []
  }

  return {
    config: cfg, state, current, data, viewer, canModerate,
    load, setSort, loadMore, hiddenReplies, loadReplies, showComment, closeLinked, loadModCounts, setModFilter, loadMoreQueue,
    post, toggleLike, report, deleteOwn, moderate, setPageState,
    toggleSelected, dropFolded, setManage, find, listUrl,
  }
}

export type Store = ReturnType<typeof createStore>

/** The IDs of a comment and every reply below it. */
function subtree(c: Comment): number[] {
  return [c.id].concat(...(c.replies || []).map(subtree))
}

/** Gives every comment the optional fields the templates read. */
function normalize(c: Comment): Comment {
  if (!c.replies) c.replies = []
  if (c.replyCount === undefined) c.replyCount = c.replies.length
  if (c.totalReplies === undefined) c.totalReplies = c.replyCount
  for (const r of c.replies) normalize(r)
  return c
}

/**
 * Adds `incoming` replies to `c.replies`: new ones are added, known ones keep their object (and
 * the reader's state in it) with the server's counts and any replies of their own it did not
 * have. Oldest first, as the server sends them, so the reader's own new reply stays last.
 */
function mergeReplies(c: Comment, incoming: Comment[]) {
  const byId = new Map((c.replies || []).map((r) => [r.id, r] as [number, Comment]))
  for (const r of incoming) {
    const known = byId.get(r.id)
    if (!known) {
      byId.set(r.id, r)
      continue
    }
    if (r.replyCount !== undefined) known.replyCount = r.replyCount
    if (r.replies && r.replies.length) mergeReplies(known, r.replies)
  }
  c.replies = [...byId.values()].sort((a, b) => (a.created < b.created ? -1 : a.created > b.created ? 1 : a.id - b.id))
}
