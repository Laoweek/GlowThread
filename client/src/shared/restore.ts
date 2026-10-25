// What Restore does to a deleted comment, for its label, so restoring never publishes text
// nobody approved without saying so. The server restores to the status before the deletion
// (`mod.deletedFrom`): a comment deleted while held goes back to held. Where that status is
// unknown (FlowThread's and older deletions), restore publishes, which must be said when the
// comment was once held and never approved.
import type { Comment } from './types'

/** held: back to Awaiting review. publish: becomes public without review. plain: an ordinary restore. */
export type RestoreOutcome = 'held' | 'publish' | 'plain'

export function restoreOutcome(c: Pick<Comment, 'mod'>): RestoreOutcome {
  const m = c.mod
  if (!m) return 'plain'
  if (m.deletedFrom === 'held') return 'held'
  if (m.deletedFrom === 'visible') return 'plain'
  return m.holdReasons.length ? 'publish' : 'plain'
}

/** The message key of the restore action for that outcome. */
export function restoreLabel(outcome: RestoreOutcome): string {
  return outcome === 'held' ? 'restore-held' : outcome === 'publish' ? 'restore-publish' : 'restore'
}

/** One label for several comments: a warning wins, then Restore for review when every one goes back there. */
export function restoreLabelFor(comments: Array<Pick<Comment, 'mod'>>): string {
  const outcomes = comments.map(restoreOutcome)
  if (outcomes.indexOf('publish') >= 0) return restoreLabel('publish')
  if (outcomes.length && outcomes.every((o) => o === 'held')) return restoreLabel('held')
  return restoreLabel('plain')
}
