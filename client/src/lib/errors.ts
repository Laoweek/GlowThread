import { ApiError } from './api'
import { t } from './i18n'

/**
 * Backend error codes (docs/api.md, "Error codes" and "REST errors") to UI message keys.
 * Each message names the problem and what the reader can do about it.
 */
const KEYS: Record<string, string> = {
  // transport
  network: 'error-network',
  server: 'error-server',
  http: 'error-server',
  // core Action API
  badtoken: 'error-session',
  notoken: 'error-session',
  mustbeposted: 'error-session',
  mustpostparams: 'error-session',
  permissiondenied: 'error-permission',
  blocked: 'error-blocked',
  autoblocked: 'error-blocked',
  ratelimited: 'error-ratelimited',
  readonly: 'error-readonly',
  // GlowThread write modules
  pagenotfound: 'error-page-missing',
  pageineligible: 'error-page-ineligible',
  commentsdisabled: 'error-disabled',
  commentslocked: 'error-locked',
  emptycomment: 'error-empty',
  toolong: 'error-too-long',
  toomanymentions: 'error-too-many-mentions',
  duplicate: 'error-duplicate',
  toomanyheld: 'error-too-many-held',
  badparent: 'error-parent',
  commentnotfound: 'error-comment-missing',
  ownlike: 'error-own-like',
  // a like from an account the new-account gate holds
  newaccount: 'like-new-account',
  ownreport: 'error-own-report',
  badreason: 'error-report-reason',
  notetoolong: 'error-note-too-long',
  notauthor: 'error-not-author',
  badstatus: 'error-bad-status',
  cannotpin: 'error-cannot-pin',
  // REST errorKey values
  'page-not-found': 'error-page-missing',
  'comment-not-found': 'error-comment-missing',
  'bad-cursor': 'error-bad-cursor',
  'permission-denied': 'error-permission',
}

export interface ErrorInfo {
  code: string
  key: string
  params: Array<string | number>
}

export function describeError(err: unknown, { anon = false } = {}): ErrorInfo {
  const e = err instanceof ApiError ? err : new ApiError('unknown')
  const data = e.data || {}
  let key = KEYS[e.code] || 'error-unknown'
  const params: Array<string | number> = []
  if (key === 'error-permission' && anon) key = 'error-login'
  if (key === 'error-too-long' || key === 'error-too-many-mentions' || key === 'error-too-many-held') params.push(Number(data.max) || 0)
  if (key === 'error-ratelimited' && Number(data.retryAfter) > 0) {
    key = 'error-ratelimited-wait'
    params.push(Math.ceil(Number(data.retryAfter)))
  }
  if (key === 'error-blocked') {
    const reason = data.blockinfo && (data.blockinfo.blockreason as string)
    if (reason) {
      key = 'error-blocked-reason'
      params.push(reason)
    }
  }
  return { code: e.code, key, params }
}

/** The message to show for a failed request. */
export function errorMessage(err: unknown, options: { anon?: boolean } = {}): string {
  const info = describeError(err, options)
  return t(info.key, ...info.params)
}
