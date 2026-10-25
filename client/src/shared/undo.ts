// How a moderation action is undone, shared by the comment section and the moderation hub.
import type { CommentStatus } from './types'

export type ModAction = 'approve' | 'delete' | 'restore' | 'erase' | 'pin' | 'unpin' | 'dismiss'

export interface UndoStep {
  do: ModAction | 'undo'
  ids: number[]
  logids?: number[]
}

/** The reverse of each action where one exists. approve, dismiss and erase have none. */
const REVERSE: Partial<Record<ModAction, ModAction>> = { delete: 'restore', restore: 'delete', pin: 'unpin', unpin: 'pin' }

/**
 * How to undo `action` on the comments in `done`. The server undoes every logged action
 * exactly (`do=undo` with the log IDs it returned, docs/api.md), which is used whenever each
 * change has a log ID. Otherwise the reverse action is sent, except where it would do more
 * than undo: restoring makes a comment visible, so a deletion is undone by restoring only
 * when the status before it was visible. That status must be the server's (`old` in the
 * result), never a copy kept by the page: reports can hold a comment at any time.
 */
export function undoStep(
  action: ModAction,
  done: Array<{ id: number, logId?: number | null }>,
  before: Record<number, { status: CommentStatus | null | undefined }>,
  exact: boolean,
): UndoStep | null {
  if (!done.length || action === 'erase') return null
  if (exact && done.every((d) => typeof d.logId === 'number')) {
    return { do: 'undo', ids: done.map((d) => d.id), logids: done.map((d) => d.logId as number) }
  }
  const reverse = REVERSE[action]
  if (!reverse) return null
  const ids = done.map((d) => d.id).filter((id) => action !== 'delete' || (before[id] && before[id].status === 'visible'))
  return ids.length ? { do: reverse, ids } : null
}
