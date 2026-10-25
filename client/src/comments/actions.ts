// User actions with their feedback (toasts, undo), shared by rows, menus and the bulk bar.
import type { Store, ModAction } from './store'
import type { Comment, CommentStatus, ModerateResult } from '@/shared/types'
import { undoStep } from '@/shared/undo'
import type { UndoStep } from '@/shared/undo'
import { toast } from '@/ui/toast'
import { errorMessage } from '@/lib/errors'
import { sentences, t } from '@/lib/i18n'
import { copyText } from '@/lib/clipboard'
import { commentPermalink, loginUrl } from '@/lib/wiki'

const anon = () => mw.user.isAnon()
// The server takes 50 IDs (and log IDs) per request.
const CHUNK = 50

export function showError(e: unknown) {
  toast(errorMessage(e, { anon: anon() }), { tone: 'error' })
}

export async function like(store: Store, c: Comment) {
  if (anon()) {
    toast(t('like-login'), { action: { label: t('login'), onClick: () => { location.href = loginUrl() } } })
    return
  }
  // The new-account gate: say when liking becomes possible. Taking a like back still works.
  const v = store.viewer.value
  if (!c.viewer.canLike && !c.viewer.liked && v && v.reactBlockedReason === 'newaccount') {
    toast(t('like-new-account'))
    return
  }
  try {
    await store.toggleLike(c)
  } catch (e) {
    showError(e)
  }
}

export async function copyLink(c: Comment) {
  const ok = await copyText(commentPermalink(c.id))
  toast(ok ? t('link-copied') : t('link-copy-failed'), { tone: ok ? 'neutral' : 'error' })
}

/**
 * Runs a moderation action on one or more comments, with Undo for a few seconds. Undo is
 * built from the server's answer: the exact undo of the logged change (`do=undo` with its
 * log IDs), or, where an answer has no log ID, the reverse action, and a deletion only when
 * the server says the comment was visible before (`old`). The page's own copy of a status
 * is never trusted: reports can hold a comment after the page was loaded.
 */
export async function moderate(store: Store, ids: number[], action: ModAction): Promise<boolean> {
  const done: ModerateResult['results'] = []
  let failed = 0
  for (let i = 0; i < ids.length; i += CHUNK) {
    const chunk = ids.slice(i, i + CHUNK)
    try {
      const r = await store.moderate(chunk, action)
      for (const x of r.results) {
        if (x.result === 'success') done.push(x)
        else if (x.result === 'error') failed++
      }
    } catch (e) {
      // Nothing done yet: the error says why. Otherwise keep what was done, with its undo.
      if (!done.length) {
        showError(e)
        return false
      }
      failed += ids.length - i
      break
    }
  }
  if (failed && !done.length) {
    toast(t('mod-failed', failed), { tone: 'error' })
    return false
  }
  const before: Record<number, { status: CommentStatus | null | undefined }> = {}
  for (const x of done) before[x.id] = { status: x.old }
  const step = undoStep(action, done.map((x) => ({ id: x.id, logId: x.logId })), before, true)
  const message = done.length ? doneMessage(action, done) : t('mod-nochange')
  toast(sentences(message, failed ? t('mod-failed', failed) : ''), {
    action: step ? { label: t('undo'), onClick: () => { undo(store, step) } } : undefined,
  })
  return true
}

/** The toast after an action. A restore says the comment is public again (the server's result). */
function doneMessage(action: ModAction, done: ModerateResult['results']): string {
  if (action === 'restore' && done.some((x) => x.status === 'held')) return t('mod-done-restore-held', done.length)
  return t('mod-done-' + action, done.length)
}

async function undo(store: Store, step: UndoStep) {
  let ok = 0
  let changed = 0
  for (let i = 0; i < step.ids.length; i += CHUNK) {
    try {
      // Undo steps of the reader's own actions never erase.
      const r = await store.moderate(step.ids.slice(i, i + CHUNK), step.do as ModAction | 'undo', step.logids && step.logids.slice(i, i + CHUNK))
      ok += r.results.filter((x) => x.result === 'success').length
      changed += r.results.filter((x) => x.result === 'error').length
    } catch (e) {
      if (!ok) {
        showError(e)
        return
      }
      changed += step.ids.length - i
      break
    }
  }
  if (!ok && changed) toast(t('error-undo-failed'), { tone: 'error' })
  else toast(sentences(t('undone', ok), changed ? t('mod-failed', changed) : ''))
}
