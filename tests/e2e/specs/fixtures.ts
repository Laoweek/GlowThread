import { test as base, expect } from '@playwright/test'
import type { Page, Locator } from '@playwright/test'
import { PAGES, clientIp, login, pageId } from '../lib/wiki.mjs'

export { expect, PAGES }

export interface Wiki {
  page: Page
  section: Locator
  skin: string
  phone: boolean
  errors: string[]
  as(user: string): Promise<void>
  open(title: string, options?: { hash?: string, scroll?: boolean, wait?: boolean, query?: Record<string, string> }): Promise<void>
  ready(): Promise<void>
  api(user: string): Promise<any>
  pageId(title: string): Promise<number>
}

// Console noise that is not ours: favicons, and Echo polling for badges.
const IGNORE = /favicon|echo|Failed to load resource: the server responded with a status of 404/i

export const test = base.extend<{ wiki: Wiki, gtSkin: string, gtNight: boolean }>({
  gtSkin: ['vector-2022', { option: true }],
  gtNight: [false, { option: true }],
  wiki: async ({ page, context, gtSkin, gtNight }, use, testInfo) => {
    const errors: string[] = []
    page.on('console', (m) => {
      if (m.type() === 'error' && !IGNORE.test(m.text() + ' ' + (m.location().url || ''))) errors.push(m.text())
    })
    page.on('pageerror', (e) => errors.push('pageerror: ' + e.message))
    // Logged-out views get an address of their own too (see as()).
    await context.setExtraHTTPHeaders({ 'X-Forwarded-For': clientIp('anonymous ' + testInfo.titlePath.join(' ')) })
    if (gtNight) {
      // What the skins store when a reader picks night mode (Citizen: localStorage,
      // Vector 2022: the client preferences cookie), plus the class itself as a fallback.
      await context.addInitScript(() => {
        try {
          localStorage.setItem('mwclientpreferences', 'skin-theme-clientpref-night')
        } catch (e) {
          // ignore
        }
        document.addEventListener('DOMContentLoaded', () => {
          document.documentElement.classList.remove('skin-theme-clientpref-day', 'skin-theme-clientpref-os')
          document.documentElement.classList.add('skin-theme-clientpref-night')
        })
      })
    }
    const phone = !!testInfo.project.use.isMobile
    const sessions: Record<string, any> = {}
    const wiki: Wiki = {
      page,
      section: page.locator('#glowthread'),
      skin: gtSkin,
      phone,
      errors,
      async as(user) {
        const s = await login(user)
        // Each account its own client address (lib/wiki.mjs, clientIp).
        await context.setExtraHTTPHeaders({ 'X-Forwarded-For': clientIp(user) })
        await context.addCookies(s.playwrightCookies(testInfo.project.use.baseURL || 'http://localhost:8151'))
      },
      async open(title, { hash = '', scroll = true, wait = true, query = {} } = {}) {
        const qs = new URLSearchParams({ title, useskin: gtSkin, ...query })
        await page.goto('/index.php?' + qs.toString() + hash)
        // The loader creates the container once ResourceLoader runs it.
        await page.waitForSelector('#glowthread', { state: 'attached', timeout: 20_000 })
        if (scroll) await page.evaluate(() => document.getElementById('glowthread')!.scrollIntoView())
        if (wait) await wiki.ready()
      },
      async ready() {
        await page.waitForSelector('#glowthread .gt-root #gt-heading', { timeout: 20_000 })
        await page.waitForFunction(() => !document.querySelector('#glowthread .gt-pulse'), null, { timeout: 20_000 })
      },
      async api(user) {
        if (!sessions[user]) sessions[user] = await login(user)
        return sessions[user]
      },
      pageId,
    }
    await use(wiki)
    expect(errors, 'console errors').toEqual([])
  },
})

/**
 * Posts a comment through the API and returns its id. Regular accounts may post 6 times a
 * minute, so this waits out a rate limit (sysops such as Admin are not limited).
 */
export async function postComment(wiki: Wiki, user: string, title: string, text: string, replyto?: number): Promise<number> {
  const s = await wiki.api(user)
  for (let attempt = 0; ; attempt++) {
    const r = await s.write({ action: 'glowthreadpost', pageid: await pageId(title), text, ...(replyto ? { replyto } : {}) })
    if (r.glowthreadpost) return r.glowthreadpost.id
    const code = r.error ? r.error.code : r.errors && r.errors[0].code
    if (code !== 'ratelimited' || attempt > 8) throw new Error(JSON.stringify(r))
    await new Promise((done) => setTimeout(done, 8000))
  }
}

export const unique = (prefix: string) => prefix + ' ' + Date.now().toString(36) + Math.random().toString(36).slice(2, 5)

/** The toast region (menus, sheets and toasts render in #glowthread-portal). */
export const toast = (page: Page) => page.locator('#glowthread-portal [role="status"]')
