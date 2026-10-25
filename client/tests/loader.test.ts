import { afterEach, describe, expect, it, vi } from 'vitest'
import { readFileSync } from 'node:fs'
import path from 'node:path'
import { restGet } from '@/lib/api'
import { config, json, list } from './comments-fixtures'

// resources/init/index.js starts the first page of the list when it loads the app, with the
// request the app would make itself (restGet), and hands it to mount().
const source = readFileSync(path.resolve('..', 'resources', 'init', 'index.js'), 'utf8')
const g = globalThis as any
const saved = { mw: g.mw, $: g.$ }

function runLoader(cfg: unknown) {
  const mount = vi.fn()
  g.mw = {
    config: { get: (k: string) => (k === 'wgGlowThread' ? cfg : k === 'skin' ? 'vector' : null) },
    loader: {
      using: vi.fn(() => Promise.resolve(() => ({ mount }))),
      load: vi.fn(),
    },
  }
  g.$ = (fn: () => void) => fn()
  const content = document.createElement('div')
  content.id = 'bodyContent'
  document.body.appendChild(content)
  // A link to a comment loads the app at once.
  location.hash = '#gt-5'
  new Function(source)()
  return { mount, using: g.mw.loader.using }
}

afterEach(() => {
  g.mw = saved.mw
  g.$ = saved.$
  location.hash = ''
  document.body.innerHTML = ''
  vi.unstubAllGlobals()
})

describe('the loader', () => {
  it('requests the first page with the app\'s own request and hands it to the app', async () => {
    const fetch = vi.fn(async () => json(list(3)))
    vi.stubGlobal('fetch', fetch)
    const { mount, using } = runLoader(config)
    expect(using).toHaveBeenCalledWith('ext.glowThread.app')
    expect(fetch).toHaveBeenCalledTimes(1)
    const [url, options] = fetch.mock.calls[0] as unknown as [string, RequestInit]
    expect(url).toBe(config.listUrls.hot)
    await new Promise((r) => setTimeout(r, 0))
    expect(mount).toHaveBeenCalledTimes(1)
    const [el, early] = mount.mock.calls[0]
    expect(el.id).toBe('glowthread')
    expect(early.url).toBe(url)
    expect(early.response).toBe(fetch.mock.results[0].value)

    // The same request as restGet's, so Varnish serves the same object.
    await restGet(config.listUrls.new)
    const [, own] = fetch.mock.calls[1] as unknown as [string, RequestInit]
    expect(options).toEqual({ credentials: own.credentials, headers: own.headers })
    expect(own.signal).toBeUndefined()
  })

  it('leaves a failed request to the app, without an unhandled rejection', async () => {
    const failure = Promise.reject(new TypeError('Failed to fetch'))
    const unhandled = vi.fn()
    process.on('unhandledRejection', unhandled)
    // Not a vi.fn: it would watch the promise it returns, which counts as handling it.
    vi.stubGlobal('fetch', () => failure)
    const { mount } = runLoader(config)
    await new Promise((r) => setTimeout(r, 10))
    process.off('unhandledRejection', unhandled)
    expect(unhandled).not.toHaveBeenCalled()
    await expect(mount.mock.calls[0][1].response).rejects.toThrow('Failed to fetch')
  })

  it('requests nothing without list URLs', async () => {
    const fetch = vi.fn()
    vi.stubGlobal('fetch', fetch)
    const { mount } = runLoader({ pageId: 42 })
    await new Promise((r) => setTimeout(r, 0))
    expect(fetch).not.toHaveBeenCalled()
    expect(mount).toHaveBeenCalledWith(expect.any(HTMLElement), null)
  })
})
