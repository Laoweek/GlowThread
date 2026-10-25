import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { restGet, useEarlyResponse, ApiError } from '@/lib/api'
import { config, json, list, mockFetch, mountSection, pageMw, restoreMw, settle } from './comments-fixtures'

// The loader (resources/init/index.js) requests the first page of the list while the app
// loads and hands the request to mount(): the app takes it for its first request, at the exact
// URL only and once, and a failure ends in the error state with Try again as before.
const url = config.listUrls.hot
const OPTIONS = { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: undefined }

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('restGet with an early response', () => {
  it('uses it once, for its URL, instead of a request of its own', async () => {
    const fetch = mockFetch(() => json({ from: 'fetch' }))
    useEarlyResponse({ url, response: Promise.resolve(json({ from: 'loader' })) })
    expect(await restGet(url)).toEqual({ from: 'loader' })
    expect(fetch).not.toHaveBeenCalled()
    expect(await restGet(url)).toEqual({ from: 'fetch' })
    expect(fetch).toHaveBeenCalledWith(url, OPTIONS)
  })

  it('never answers another URL, and the first request drops it', async () => {
    const fetch = mockFetch(() => json({ from: 'fetch' }))
    useEarlyResponse({ url, response: Promise.resolve(json({ from: 'loader' })) })
    expect(await restGet(config.listUrls.new)).toEqual({ from: 'fetch' })
    expect(await restGet(url)).toEqual({ from: 'fetch' })
    expect(fetch.mock.calls.map((c) => c[0])).toEqual([config.listUrls.new, url])
  })

  it('turns its failures into the same errors as a request of its own', async () => {
    mockFetch(() => json({}))
    const fails = async (response: Promise<Response>) => {
      useEarlyResponse({ url, response })
      return restGet(url).then(() => null, (e: ApiError) => [e.code, e.status])
    }
    expect(await fails(Promise.reject(new TypeError('Failed to fetch')))).toEqual(['network', 0])
    expect(await fails(Promise.resolve(new Response('<html>Bad gateway</html>', { status: 502 })))).toEqual(['server', 502])
    expect(await fails(Promise.resolve(json({ errorKey: 'glowthread-rest-nopage' }, 404)))).toEqual(['glowthread-rest-nopage', 404])
    expect(await fails(Promise.resolve(new Response('{"comments": [', { status: 200 })))).toEqual(['server', 200])
  })
})

describe('the comment section mounted with the loader\'s request', () => {
  beforeEach(() => pageMw())
  afterEach(() => {
    restoreMw()
    document.body.innerHTML = ''
  })

  it('draws the list from it without asking again', async () => {
    const fetch = mockFetch(() => json({}))
    const { el, app } = mountSection({ url, response: Promise.resolve(json(list(3))) })
    await settle()
    expect(el.querySelectorAll('[data-comment]').length).toBe(4)
    expect(fetch).not.toHaveBeenCalled()
    app.unmount()
  })

  it('shows the error with Try again when it failed, and Try again asks the server', async () => {
    const fetch = mockFetch(() => json(list(3)))
    const { el, app } = mountSection({ url, response: Promise.reject(new TypeError('Failed to fetch')) })
    await settle()
    const alert = el.querySelector('[role="alert"]') as HTMLElement
    expect(alert.textContent).toContain('Could not reach the wiki')
    expect(el.querySelector('[data-comment]')).toBeNull()
    expect(fetch).not.toHaveBeenCalled()
    const retry = [...alert.querySelectorAll('button')].find((b) => b.textContent!.includes('Try again')) as HTMLButtonElement
    retry.click()
    await settle()
    expect(fetch).toHaveBeenCalledTimes(1)
    expect(fetch).toHaveBeenCalledWith(url, OPTIONS)
    expect(el.querySelector('[role="alert"]')).toBeNull()
    expect(el.querySelectorAll('[data-comment]').length).toBe(4)
    app.unmount()
  })

  it('shows the server error for an answer that is not JSON', async () => {
    mockFetch(() => json(list(3)))
    const { el, app } = mountSection({ url, response: Promise.resolve(new Response('<html>Bad gateway</html>', { status: 502 })) })
    await settle()
    expect((el.querySelector('[role="alert"]') as HTMLElement).textContent).toContain('The server had a problem')
    app.unmount()
  })

  it('makes a request of its own when mounted without one (an older loader)', async () => {
    const fetch = mockFetch(() => json(list(3)))
    const { el, app } = mountSection()
    await settle()
    expect(fetch).toHaveBeenCalledWith(url, OPTIONS)
    expect(el.querySelectorAll('[data-comment]').length).toBe(4)
    app.unmount()
  })
})
