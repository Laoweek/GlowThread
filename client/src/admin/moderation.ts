// What a moderation action does to a comment, which actions apply to which comments, which
// tab a comment belongs to, and how an action is undone. Pure functions shared by the
// queue store and its tests; the server stays the authority (docs/api.md).
import type { Comment, QueueItem } from '@/shared/types'
import type { ModAction } from '@/shared/undo'
import type { Tab } from './router'

export type { ModAction, UndoStep } from '@/shared/undo'
export { undoStep } from '@/shared/undo'

/** What the moderator asks for. `pass` (Approve, key a) approves a held comment and keeps a reported visible one. */
export type Kind = 'pass' | ModAction

export interface Counts {
  reported: number
  held: number
  deleted: number
}

export const openReports = (c: Comment): number => (c.mod ? c.mod.openReports : 0)

/** The server action for one comment, or null when the request does not apply to it. */
export function actionFor(kind: Kind, c: Comment): ModAction | null {
  const s = c.status
  switch (kind) {
    case 'pass':
      if (s === 'held') return 'approve'
      return s === 'visible' && openReports(c) > 0 ? 'dismiss' : null
    case 'approve':
      return s === 'held' ? 'approve' : null
    case 'delete':
      return s === 'visible' || s === 'held' ? 'delete' : null
    case 'restore':
      return s === 'deleted' ? 'restore' : null
    case 'erase':
      return s === 'deleted' ? 'erase' : null
    case 'dismiss':
      return (s === 'visible' || s === 'held') && openReports(c) > 0 ? 'dismiss' : null
    case 'pin':
      return s === 'visible' && c.depth === 0 && !c.pinned ? 'pin' : null
    case 'unpin':
      return c.pinned ? 'unpin' : null
  }
  return null
}

function withReportsClosed(c: Comment): Comment {
  return { ...c, mod: c.mod ? { ...c.mod, openReports: 0, reportReasons: [] } : c.mod }
}

/** The comment as the server will return it after `action` (docs/api.md, glowthreadmoderate). */
export function transition(c: Comment, action: ModAction): Comment {
  switch (action) {
    case 'approve':
      // The server keeps the hold reasons on record; they only show while a comment is held.
      return withReportsClosed({ ...c, status: 'visible' })
    case 'delete':
      // A pin stays on a deleted comment (only erase removes it), so a restore brings it back pinned.
      // The server records the status before (mod.deletedFrom), which a restore goes back to.
      return withReportsClosed({
        ...c,
        status: 'deleted',
        mod: c.mod ? { ...c.mod, deletedFrom: c.status === 'held' ? 'held' : 'visible' } : c.mod,
      })
    case 'restore':
      // Back to the status before the deletion when the server says it (mod.deletedFrom):
      // a comment deleted while held returns to Held. Otherwise visible; the result corrects it.
      return {
        ...c,
        status: c.mod && c.mod.deletedFrom === 'held' ? 'held' : 'visible',
        mod: c.mod ? { ...c.mod, deletedFrom: null } : c.mod,
      }
    case 'erase':
      return withReportsClosed({ ...c, status: 'erased', segments: [], pinned: false })
    case 'dismiss':
      return withReportsClosed(c)
    case 'pin':
      return { ...c, pinned: true }
    case 'unpin':
      return { ...c, pinned: false }
  }
}

/** Whether a comment is listed in a tab (QueueHandler: ModerationQueryService::applyTab). */
export function inTab(tab: Tab, c: Comment): boolean {
  switch (tab) {
    case 'reported':
      return (c.status === 'visible' || c.status === 'held') && openReports(c) > 0
    case 'held':
      return c.status === 'held'
    case 'deleted':
      return c.status === 'deleted' || c.status === 'erased'
    default:
      return true
  }
}

/** How the tab counts change when a comment goes from `before` to `after`. */
export function countDelta(before: Comment, after: Comment): Counts {
  const d = (tab: Tab) => (inTab(tab, after) ? 1 : 0) - (inTab(tab, before) ? 1 : 0)
  return { reported: d('reported'), held: d('held'), deleted: d('deleted') }
}

// Report reasons grouped with their reporters: shared with the comment section's moderation notes.
export { groupReports } from '@/shared/reports'

/**
 * Reporters behind many of the loaded reports. One account filing most of a backlog is
 * the pattern the moderator must see: it can be a campaign against one author, or one
 * reader who reports everything.
 * Returns reporter name -> number of loaded items they reported, for those at or above
 * `min` items and a third of the reported items loaded.
 */
export function frequentReporters(items: QueueItem[], min = 5): Record<string, number> {
  const per: Record<string, number> = {}
  let reportedItems = 0
  for (const item of items) {
    if (!item.reports.length) continue
    reportedItems++
    const seen: Record<string, true> = {}
    for (const r of item.reports) {
      const name = r.reporter && !r.reporter.hidden ? r.reporter.name : null
      if (!name || seen[name]) continue
      seen[name] = true
      per[name] = (per[name] || 0) + 1
    }
  }
  const out: Record<string, number> = {}
  for (const name of Object.keys(per)) {
    if (per[name] >= min && per[name] * 3 >= reportedItems) out[name] = per[name]
  }
  return out
}
