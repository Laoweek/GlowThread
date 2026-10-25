/**
 * Talking to the backend (docs/api.md).
 * - Reads: plain fetch() of the REST routes. The first page of the comment list must be
 *   requested at the exact URL from wgGlowThread.listUrls (Varnish caches and purges
 *   that byte-identical URL), so callers pass URLs through untouched.
 * - Writes: mw.Api().postWithToken( 'csrf', ... ), POST with a CSRF token, formatversion 2.
 * Every failure becomes an ApiError with a stable code; lib/errors.ts turns it into a message.
 */

export class ApiError extends Error {
  code: string
  data: Record<string, any> | null
  status: number
  constructor(code: string, message = '', data: Record<string, any> | null = null, status = 0) {
    super(message || code)
    this.code = code
    this.data = data
    this.status = status
  }
}

/** A request made before the app ran: its URL and the response it will give. */
export interface EarlyResponse {
  url: string
  response: Promise<Response>
}

let early: EarlyResponse | null = null

/**
 * Hands restGet the request the loader (resources/init/index.js) started for the first page of
 * the list, with the same URL, headers and credentials. The first restGet takes it if it asks
 * for that exact URL; every other call (Try again, another sort) makes a request of its own.
 */
export function useEarlyResponse(r: EarlyResponse) {
  early = r
}

export async function restGet<T>(url: string, signal?: AbortSignal): Promise<T> {
  let res: Response
  const pending = early && early.url === url && !signal ? early.response : null
  early = null
  try {
    res = await (pending || fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal }))
  } catch (e) {
    if (e && (e as Error).name === 'AbortError') throw new ApiError('aborted')
    throw new ApiError('network')
  }
  let body: any = null
  try {
    body = await res.json()
  } catch {
    // not JSON (an HTML error page from a proxy)
  }
  if (!res.ok) {
    const key = body && typeof body.errorKey === 'string' ? body.errorKey : res.status >= 500 ? 'server' : 'http'
    const text = body && body.messageTranslations ? String(Object.values(body.messageTranslations)[0] || '') : ''
    throw new ApiError(key, text, body, res.status)
  }
  if (!body) throw new ApiError('server', '', null, res.status)
  return body as T
}

/** Calls an Action API write module and returns its result object (`result[module]`). */
export function actionPost<T>(module: string, params: Record<string, string | number | undefined>): Promise<T> {
  const clean: Record<string, unknown> = { action: module, formatversion: 2, errorformat: 'plaintext' }
  for (const k of Object.keys(params)) {
    if (params[k] !== undefined) clean[k] = params[k]
  }
  return new Promise<T>((resolve, reject) => {
    new mw.Api().postWithToken('csrf', clean).then(
      (result: any) => resolve(result && result[module] as T),
      (code: string, result: any) => reject(toApiError(code, result)),
    )
  })
}

/** Read-only Action API query (user search for @mentions, block details). */
export function actionGet<T>(params: Record<string, string | number>): Promise<T> {
  return new Promise<T>((resolve, reject) => {
    new mw.Api().get(Object.assign({ formatversion: 2, errorformat: 'plaintext' }, params)).then(
      (result: any) => resolve(result as T),
      (code: string, result: any) => reject(toApiError(code, result)),
    )
  })
}

function toApiError(code: string, result: any): ApiError {
  if (code === 'http') {
    const xhr = result && result.xhr
    const status = xhr && typeof xhr.status === 'number' ? xhr.status : 0
    if (result && result.textStatus === 'abort') return new ApiError('aborted')
    return new ApiError(status === 0 ? 'network' : status >= 500 ? 'server' : 'http', '', null, status)
  }
  const err = result && result.errors && result.errors[0]
  return new ApiError(code || (err && err.code) || 'unknown', (err && err.text) || '', (err && err.data) || null)
}
