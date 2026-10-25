// State and actions of the moderation queue. No DOM and no mw: the transport is injected,
// so the tests drive it with fakes (tests/admin-queue.test.ts).
//
// Actions are optimistic: the comments leave the tab at once (or change in place in All),
// the counts follow, and focus moves to the next item. When the server refuses, they come
// back where they were. The server's answer is the authority: an action that failed
// because another moderator got there first marks the item as changed.
import { computed, markRaw, reactive } from 'vue'
import type { Comment, ModerateResult, QueueItem, QueueResponse } from '@/shared/types'
import type { Tab } from './router'
import { actionFor, countDelta, inTab, openReports, transition, undoStep } from './moderation'
import type { Counts, Kind, ModAction, UndoStep } from './moderation'

export interface QueueDeps {
  restBase: string
  get<T>(url: string): Promise<T>
  post<T>(module: string, params: Record<string, string | number | undefined>): Promise<T>
  /** Page ID of a title, null when there is no such page. */
  resolvePage(title: string): Promise<number | null>
  /** Whether the server undoes logged actions exactly (`do=undo`). */
  exactUndo?: () => boolean
  pageSize?: number
}

export interface Filters {
  page: string
  user: string
  q: string
  /** Comments with an open report by this user. */
  reporter?: string
  /** reports, new or old; empty for the tab's default. */
  sort?: string
}

type Status = 'idle' | 'loading' | 'ready' | 'error'

/** Items per request: the API's maximum, so a backlog of hundreds takes few round trips. */
const PAGE_SIZE = 50
/** IDs per glowthreadmoderate call (the API's limit). */
const CHUNK = 50
/** Fetch the next page once this few items are left to work through. */
const REFILL_BELOW = 8
/** Deletions up to this many read the current status first, so they can be undone safely. */
const FRESH_CHECKS = 10

export interface ActResult {
  /** Comments the action changed, by server action. */
  done: Array<{ id: number, action: ModAction, logId?: number | null, status?: Comment['status'] | null }>
  /** Comments the action did not apply to any more (someone else got there first). */
  stale: number[]
  /** Comments refused for another reason: a rate limit (erase has its own), a failed request. */
  refused: number[]
  /** Comments already in the wanted state. */
  nochange: number[]
  /** The request failed as a whole (network, permission, block): nothing changed. */
  error: unknown
  /** How to undo it, when it can be undone. */
  undo: UndoStep[] | null
  /** Nothing in the request applied (for example Restore on a visible comment). */
  skipped: boolean
  /** Everything asked for still has a request on its way: nothing was sent, nothing to say. */
  pending: boolean
  /** The comments before the action, by ID. */
  before: Record<number, Comment>
  /** The whole queue items before the action, to put them back on undo. */
  items: Record<number, QueueItem>
}

const emptyResult = (): ActResult => ({ done: [], stale: [], refused: [], nochange: [], error: null, undo: null, skipped: false, pending: false, before: {}, items: {} })

const clone = <T>(x: T): T => JSON.parse(JSON.stringify(x))

/**
 * Items enter the list as plain objects: the list is reactive, its items are not. An item is
 * replaced whenever it changes, never changed in place, so the list sees every change, and
 * renders, filters and lookups over hundreds of items no longer go through a proxy (and keep
 * a dependency) for every field they read.
 */
const plain = (item: QueueItem): QueueItem => markRaw(item)

export function createQueue(deps: QueueDeps) {
  const pageSize = deps.pageSize || PAGE_SIZE

  const state = reactive({
    tab: 'reported' as Tab,
    filters: { page: '', user: '', q: '', reporter: '', sort: '' } as Filters,
    pageId: null as number | null,
    /** The page filter names a page that does not exist. */
    pageMissing: false,
    status: 'idle' as Status,
    error: null as unknown,
    items: [] as QueueItem[],
    next: null as string | null,
    loadingMore: false,
    moreError: null as unknown,
    counts: null as Counts | null,
    /** Counts when the list was loaded, moved by our own actions: the difference is news. */
    baseline: null as Counts | null,
    focusId: null as number | null,
    selected: [] as number[],
    /** Comments with a request in flight (changed in place, so their buttons wait). */
    busy: [] as number[],
    /** Comments another moderator changed since they were loaded. */
    stale: [] as number[],
  })

  // Server order of every loaded comment, so a comment put back lands where it was.
  let rank = new Map<number, number>()
  let nextRank = 0
  // Each load gets a number; answers to older loads are dropped.
  let generation = 0
  // The page being fetched, so a j at the end waits for a refill already on its way.
  let more: Promise<void> | null = null

  const focusIndex = computed(() => {
    const i = state.items.findIndex((x) => x.comment.id === state.focusId)
    return i >= 0 ? i : 0
  })
  const focused = computed<QueueItem | null>(() => state.items[focusIndex.value] || null)
  /** Items that arrived in this tab since it was loaded (by other moderators or readers). */
  const fresh = computed(() => {
    const tab = state.tab
    if (tab === 'all' || !state.counts || !state.baseline) return 0
    return Math.max(0, state.counts[tab] - state.baseline[tab])
  })

  function url(cursor: string | null, limit = pageSize, tab: Tab = state.tab, withFilters = true): string {
    let u = deps.restBase + '/moderation/queue?tab=' + tab + '&limit=' + limit
    if (withFilters) {
      if (state.pageId) u += '&pageid=' + state.pageId
      if (state.filters.user) u += '&user=' + encodeURIComponent(state.filters.user)
      if (state.filters.reporter) u += '&reporter=' + encodeURIComponent(state.filters.reporter)
      if (state.filters.q) u += '&q=' + encodeURIComponent(state.filters.q)
      if (state.filters.sort) u += '&sort=' + state.filters.sort
    }
    if (cursor) u += '&cursor=' + encodeURIComponent(cursor)
    return u
  }

  function remember(items: QueueItem[]) {
    for (const item of items) {
      if (!rank.has(item.comment.id)) rank.set(item.comment.id, nextRank++)
    }
  }

  /** Shows a tab with filters; loads unless it is already showing exactly that. */
  async function show(tab: Tab, filters: Filters, { force = false } = {}) {
    const f = { page: filters.page, user: filters.user, q: filters.q, reporter: filters.reporter || '', sort: filters.sort || '' }
    const same = tab === state.tab && (Object.keys(f) as Array<keyof Filters>).every((k) => f[k] === (state.filters[k] || ''))
    if (same && !force && state.status !== 'idle' && state.status !== 'error') return
    state.tab = tab
    state.filters = f
    await load({ keepFocus: same })
  }

  async function load({ keepFocus = false } = {}) {
    const gen = ++generation
    more = null
    state.loadingMore = false
    state.status = 'loading'
    state.error = null
    state.moreError = null
    state.selected = []
    state.stale = []
    if (!keepFocus) {
      state.items = []
      state.focusId = null
    }
    try {
      state.pageMissing = false
      state.pageId = null
      if (state.filters.page) {
        const id = await deps.resolvePage(state.filters.page)
        if (gen !== generation) return
        if (!id) {
          state.pageMissing = true
          state.items = []
          state.next = null
          state.status = 'ready'
          refreshCounts()
          return
        }
        state.pageId = id
      }
      const d = await deps.get<QueueResponse>(url(null))
      if (gen !== generation) return
      rank = new Map()
      nextRank = 0
      remember(d.items)
      const keep = keepFocus && state.focusId !== null && d.items.some((x) => x.comment.id === state.focusId)
      state.items = d.items.map(plain)
      state.next = d.next
      state.counts = d.counts
      state.baseline = clone(d.counts)
      if (!keep) state.focusId = d.items.length ? d.items[0].comment.id : null
      state.status = 'ready'
    } catch (e) {
      if (gen !== generation) return
      state.status = 'error'
      state.error = e
    }
  }

  function loadMore(): Promise<void> {
    if (more) return more
    if (!state.next || state.status !== 'ready') return Promise.resolve()
    const p: Promise<void> = fetchMore().then(() => {
      if (more === p) more = null
    })
    more = p
    return p
  }

  async function fetchMore() {
    const gen = generation
    state.loadingMore = true
    state.moreError = null
    try {
      const d = await deps.get<QueueResponse>(url(state.next))
      if (gen !== generation) return
      const seen = new Set(state.items.map((x) => x.comment.id))
      const added = d.items.filter((x) => !seen.has(x.comment.id))
      remember(added)
      state.items.push(...added.map(plain))
      state.next = d.next
      state.counts = d.counts
    } catch (e) {
      if (gen === generation) state.moreError = e
    } finally {
      if (gen === generation) state.loadingMore = false
    }
  }

  // Counts answers can overtake each other (a poll during an action): keep the newest.
  let countsAsked = 0

  /** Counts only: the queue's cheapest request. */
  async function refreshCounts() {
    const asked = ++countsAsked
    try {
      const d = await deps.get<QueueResponse>(url(null, 1, 'held', false))
      if (asked !== countsAsked) return
      state.counts = d.counts
      if (!state.baseline) state.baseline = clone(d.counts)
    } catch {
      // keep the last counts
    }
  }

  // ---- focus and selection ---------------------------------------------------------

  /** Moves focus by `delta` items. Past the end it loads the next page and continues. */
  async function move(delta: number): Promise<boolean> {
    const n = state.items.length
    if (!n) return false
    const i = focusIndex.value + delta
    if (i >= n) {
      // Wait for a refill on its way, then fetch the next page if that was not enough.
      for (let tries = 0; tries < 2 && focusIndex.value + delta >= state.items.length; tries++) {
        if (!state.next && !more) return false
        await loadMore()
      }
      if (focusIndex.value + delta >= state.items.length) return false
      return move(delta)
    }
    const target = Math.max(0, i)
    state.focusId = state.items[target].comment.id
    if (state.items.length - target <= REFILL_BELOW) loadMore()
    return true
  }

  function focus(id: number) {
    if (state.items.some((x) => x.comment.id === id)) state.focusId = id
  }

  function toggleSelected(id: number) {
    const i = state.selected.indexOf(id)
    if (i >= 0) state.selected.splice(i, 1)
    else state.selected.push(id)
  }

  function setAllSelected(on: boolean) {
    state.selected = on ? state.items.map((x) => x.comment.id) : []
  }

  // ---- changing the list -----------------------------------------------------------

  function indexOf(id: number) {
    return state.items.findIndex((x) => x.comment.id === id)
  }

  function addCounts(delta: Counts) {
    for (const target of [state.counts, state.baseline]) {
      if (!target) continue
      target.reported = Math.max(0, target.reported + delta.reported)
      target.held = Math.max(0, target.held + delta.held)
      target.deleted = Math.max(0, target.deleted + delta.deleted)
    }
  }

  /** Puts `comment` in the list as the server would list it now: in place, out, or back in order. */
  function place(item: QueueItem, comment: Comment) {
    const id = comment.id
    const i = indexOf(id)
    const belongs = inTab(state.tab, comment)
    if (i >= 0 && belongs) {
      state.items[i] = plain({ ...state.items[i], comment })
      return
    }
    if (i >= 0 && !belongs) {
      const wasFocused = state.focusId === id
      state.items.splice(i, 1)
      const s = state.selected.indexOf(id)
      if (s >= 0) state.selected.splice(s, 1)
      if (wasFocused) {
        const next = state.items[Math.min(i, state.items.length - 1)]
        state.focusId = next ? next.comment.id : null
      }
      return
    }
    if (i < 0 && belongs) {
      const r = rank.has(id) ? (rank.get(id) as number) : nextRank++
      rank.set(id, r)
      let at = state.items.findIndex((x) => (rank.get(x.comment.id) as number) > r)
      if (at < 0) at = state.items.length
      state.items.splice(at, 0, plain({ ...item, comment }))
      if (state.focusId === null) state.focusId = id
    }
  }

  function setBusy(ids: number[], on: boolean) {
    state.busy = on ? state.busy.concat(ids) : state.busy.filter((x) => ids.indexOf(x) < 0)
  }

  /**
   * Runs `kind` on the given comments (the selection or the focused one). Each comment
   * gets the server action that applies to it (Approve: approve when held, close the reports
   * when visible); comments it does not apply to are left alone.
   */
  async function act(kind: Kind, ids: number[], { reason }: { reason?: string } = {}): Promise<ActResult> {
    const result = emptyResult()
    const groups: Partial<Record<ModAction, number[]>> = {}
    const items = result.items
    let waiting = 0
    for (const id of ids) {
      const i = indexOf(id)
      if (i < 0) continue
      // A request for it is still on its way: two quick keys must not race.
      if (state.busy.indexOf(id) >= 0) {
        waiting++
        continue
      }
      const item = state.items[i]
      const action = actionFor(kind, item.comment)
      if (!action) continue
      ;(groups[action] = groups[action] || []).push(id)
      items[id] = clone(item)
      result.before[id] = items[id].comment
    }
    const actions = Object.keys(groups) as ModAction[]
    if (!actions.length) {
      if (waiting) result.pending = true
      else result.skipped = true
      return result
    }
    const gen = generation

    // Optimistic: every comment moves to where the server will list it.
    for (const action of actions) {
      for (const id of groups[action] as number[]) {
        const after = transition(items[id].comment, action)
        addCounts(countDelta(items[id].comment, after))
        place(items[id], after)
      }
    }
    const acted = actions.reduce((all, a) => all.concat(groups[a] as number[]), [] as number[])
    const inFlight = acted.filter((id) => indexOf(id) >= 0)
    setBusy(inFlight, true)

    const rollback = (id: number) => {
      if (gen !== generation) return
      const now = state.items[indexOf(id)]
      const current = now ? now.comment : null
      const before = items[id].comment
      // Undo the optimistic count change for this comment.
      const action = actions.find((a) => (groups[a] as number[]).indexOf(id) >= 0) as ModAction
      const predicted = current || transition(before, action)
      addCounts(countDelta(predicted, before))
      place(items[id], before)
    }

    // Undo of a deletion restores, which publishes. Our copy of the status can be old (reports
    // hold a visible comment on their own), so read it fresh just before deleting: only a
    // comment the server still showed as visible gets a restore-undo. A few at most; a larger
    // bulk deletion has no undo unless the server says the status before (results[].old).
    const deleting = groups.delete || []
    const exactUndo = !!(deps.exactUndo && deps.exactUndo())
    if (deleting.length && !exactUndo) {
      const fresh = deleting.length <= FRESH_CHECKS
        ? await Promise.all(deleting.map((id) => deps.get<{ comment: Comment }>(deps.restBase + '/comment/' + id)
          .then((d) => (d && d.comment ? d.comment.status : null))
          .catch(() => null)))
        : deleting.map(() => null)
      deleting.forEach((id, n) => {
        result.before[id] = { ...result.before[id], status: fresh[n] || 'held' }
      })
    }

    for (const action of actions) {
      const all = groups[action] as number[]
      for (let i = 0; i < all.length; i += CHUNK) {
        const chunk = all.slice(i, i + CHUNK)
        let r: ModerateResult
        try {
          r = await deps.post<ModerateResult>('glowthreadmoderate', { ids: chunk.join('|'), do: action, reason: reason || undefined })
        } catch (e) {
          result.error = result.error || e
          chunk.forEach(rollback)
          result.refused.push(...chunk)
          continue
        }
        const answered = new Set<number>()
        for (const x of r.results) {
          answered.add(x.id)
          if (x.result === 'success') {
            result.done.push({ id: x.id, action, logId: x.logId, status: x.status })
            // The status before, when the server says (requested): it decides undo, not our copy.
            if (x.old && result.before[x.id]) result.before[x.id] = { ...result.before[x.id], status: x.old }
            // The status after, as the server reports it, where the optimistic guess differs
            // (a restore can return a comment to Held): counts and list follow the server.
            if (x.status && gen === generation) {
              const i = indexOf(x.id)
              const guessed = i >= 0 ? state.items[i].comment : transition(items[x.id].comment, action)
              if (guessed.status !== x.status) {
                const actual = { ...guessed, status: x.status }
                addCounts(countDelta(guessed, actual))
                place(i >= 0 ? state.items[i] : items[x.id], actual)
              }
            }
          } else if (x.result === 'nochange') {
            result.nochange.push(x.id)
            // Already in the wanted state; take the status the server reports.
            if (x.status && x.status !== items[x.id].comment.status && gen === generation) {
              const i = indexOf(x.id)
              if (i >= 0) place(state.items[i], { ...state.items[i].comment, status: x.status })
            }
          } else {
            rollback(x.id)
            // Put it where its current status (from the server) lists it.
            if (x.status && x.status !== items[x.id].comment.status && gen === generation) {
              place(items[x.id], { ...items[x.id].comment, status: x.status })
            }
            if (x.code === 'badstatus' || x.code === 'commentnotfound' || x.code === 'cannotpin') {
              result.stale.push(x.id)
              if (gen === generation && state.stale.indexOf(x.id) < 0) state.stale.push(x.id)
            } else {
              result.refused.push(x.id)
            }
          }
        }
        for (const id of chunk) {
          if (!answered.has(id)) {
            result.refused.push(id)
            rollback(id)
          }
        }
      }
    }
    setBusy(inFlight, false)

    // Undo is offered per action; Approve on a mixed selection may need two steps.
    const steps: UndoStep[] = []
    for (const action of actions) {
      const step = undoStep(action, result.done.filter((d) => d.action === action), result.before, exactUndo)
      if (step) steps.push(step)
    }
    const undoneAll = steps.reduce((n, s) => n + s.ids.length, 0)
    // Undo only when it covers the whole action, or the moderator would believe it did.
    result.undo = steps.length && undoneAll === result.done.length ? steps : null

    if (gen === generation && state.next && state.items.length <= REFILL_BELOW) loadMore()
    refreshCounts()
    return result
  }

  /** Sends the undo steps of an earlier action and puts the comments back. */
  async function undo(prev: ActResult): Promise<ActResult> {
    const out = emptyResult()
    const gen = generation
    // The server takes CHUNK IDs (and log IDs) per request, as for the action itself.
    const requests: UndoStep[] = []
    for (const step of prev.undo || []) {
      for (let i = 0; i < step.ids.length; i += CHUNK) {
        requests.push({ do: step.do, ids: step.ids.slice(i, i + CHUNK), logids: step.logids && step.logids.slice(i, i + CHUNK) })
      }
    }
    for (const step of requests) {
      try {
        const r = await deps.post<ModerateResult>('glowthreadmoderate', {
          ids: step.ids.join('|'),
          do: step.do,
          logids: step.logids ? step.logids.join('|') : undefined,
        })
        for (const x of r.results) {
          const kept = prev.items[x.id]
          const original = prev.done.find((d) => d.id === x.id)
          if (x.result !== 'success' || !kept || !original) {
            if (x.result !== 'nochange') out.stale.push(x.id)
            continue
          }
          // Exact undo brings back the earlier state, a reverse action its own result
          // (restoring a deleted comment does not reopen the reports the deletion closed).
          const was = transition(kept.comment, original.action)
          const now = step.do === 'undo' ? kept.comment : transition(was, step.do)
          out.done.push({ id: x.id, action: step.do === 'undo' ? original.action : step.do })
          addCounts(countDelta(was, now))
          if (gen === generation) place({ ...kept, reports: openReports(now) ? kept.reports : [] }, now)
        }
      } catch (e) {
        out.error = out.error || e
      }
    }
    refreshCounts()
    return out
  }

  /** The selection as a set, for lookups per item. */
  const selectedSet = computed(() => new Set(state.selected))

  return {
    state, focusIndex, focused, fresh, selectedSet,
    show, load, loadMore, refreshCounts, move, focus, toggleSelected, setAllSelected, act, undo,
  }
}

export type Queue = ReturnType<typeof createQueue>
