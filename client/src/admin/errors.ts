// Error messages of the hub: its own codes (settings, the word-filter page, user records),
// then the shared mapping in lib/errors.ts.
import { ApiError } from '@/lib/api'
import { errorMessage as sharedMessage } from '@/lib/errors'
import { t } from '@/lib/i18n'

const KEYS: Record<string, string> = {
  // glowthreadsettings
  badsettingsjson: 'error-settings-invalid',
  unknownsetting: 'error-settings-invalid',
  badsettingvalue: 'error-settings-invalid',
  settinglocked: 'error-setting-locked',
  nochange: 'error-settings-nochange',
  // action=edit of MediaWiki:GlowThread-filter
  editconflict: 'error-edit-conflict',
  editfailed: 'error-edit-failed',
  protectedpage: 'error-edit-protected',
  cascadeprotected: 'error-edit-protected',
  'protectednamespace-interface': 'error-edit-protected',
  // do=undo (per item and for the request)
  badlogid: 'error-undo-failed',
  badlogids: 'error-undo-failed',
  cannotundo: 'error-undo-failed',
  // REST: GET /moderation/user/{name}
  'user-not-found': 'error-user-missing',
}

export function errorMessage(err: unknown): string {
  if (err instanceof ApiError && KEYS[err.code]) return t(KEYS[err.code])
  return sharedMessage(err)
}
