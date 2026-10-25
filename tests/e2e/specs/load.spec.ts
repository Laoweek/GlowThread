import { test, expect, PAGES, postComment, toast, unique } from './fixtures'
import type { Page } from '@playwright/test'
import { Session } from '../lib/wiki.mjs'

// The article comes first: nothing of the app loads until the reader scrolls toward the
// section (or it is on screen), and loading it moves nothing on the page.

/**
 * Waits until the page's downloads are done and two more frames have been drawn: by then the
 * loader's IntersectionObserver callbacks have run and any load they start is on the wire.
 */
async function settled(page: Page) {
  await page.waitForLoadState('networkidle')
  await page.evaluate(() => new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done))))
  await page.waitForLoadState('networkidle')
}

test.describe('a long article', () => {
  test.beforeEach(async ({ context }) => {
    // Make the article long, like a long recipe, before the loader runs.
    await context.addInitScript(() => {
      document.addEventListener('DOMContentLoaded', () => {
        const text = document.getElementById('mw-content-text')
        if (!text) return
        const spacer = document.createElement('div')
        spacer.id = 'gt-test-spacer'
        spacer.style.height = '4000px'
        text.appendChild(spacer)
      })
    })
  })

  test('loads the app only when the section nears the viewport', async ({ wiki, page }) => {
    const appRequests: string[] = []
    page.on('request', (r) => {
      if (/load\.php.*ext\.glowThread\.app/.test(decodeURIComponent(r.url()))) appRequests.push(r.url())
    })
    await wiki.open(PAGES.showcase, { scroll: false, wait: false })
    await settled(page)
    expect(appRequests).toEqual([])
    // The loader already reserved the space with a static skeleton.
    await expect(page.locator('#glowthread.ext-glowthread[aria-busy="true"]')).toHaveCount(1)
    await page.evaluate(() => window.scrollTo(0, document.getElementById('glowthread')!.getBoundingClientRect().top + window.scrollY - window.innerHeight * 1.5))
    await wiki.ready()
    expect(appRequests.length).toBe(1)
  })

  test('on a phone, loads nothing until the reader scrolls toward the section', async ({ wiki, page }) => {
    test.skip(!wiki.phone, 'phones: MobileFrontend collapses sections, which brought the section close')
    const requests: string[] = []
    page.on('request', (r) => {
      const u = decodeURIComponent(r.url())
      if (/ext\.glowThread\.app|glowthread\/v1\/page/.test(u)) requests.push(u)
    })
    await wiki.open(PAGES.showcase, { scroll: false, wait: false })
    await settled(page)
    // A little scrolling far above the section loads nothing either.
    const scrolled = page.evaluate(() => new Promise((done) => window.addEventListener('scroll', done, { once: true })))
    await page.mouse.wheel(0, 200)
    await scrolled
    await settled(page)
    expect(requests).toEqual([])
    await page.evaluate(() => document.querySelector('.ext-glowthread')!.scrollIntoView())
    await wiki.ready()
    expect(requests.length).toBe(2)
  })

  test('counts a scroll made before the loader ran', async ({ wiki, page }) => {
    // The loader waits for the DOM, seconds on a slow phone: hold it back meanwhile.
    let release = () => {}
    const held = new Promise<void>((done) => { release = done })
    await page.route((url) => url.pathname.endsWith('/load.php'), async (route) => {
      if (/ext\.glowThread\.init|glowThread\.[a-z,]*init/.test(decodeURIComponent(route.request().url()))) await held
      await route.continue()
    })
    const appRequests: string[] = []
    page.on('request', (r) => {
      if (/load\.php.*ext\.glowThread\.app/.test(decodeURIComponent(r.url()))) appRequests.push(r.url())
    })
    await page.goto('/index.php?title=' + encodeURIComponent(PAGES.showcase) + '&useskin=' + wiki.skin, { waitUntil: 'domcontentloaded' })
    // Scroll until the reserved space is within a screen below the viewport, not in it.
    await page.evaluate(() => {
      const body = document.getElementById('bodyContent')!
      const reserved = parseFloat(getComputedStyle(body, '::after').height) || 0
      const top = body.getBoundingClientRect().bottom + window.scrollY - reserved
      window.scrollTo(0, top - window.innerHeight * 1.5)
    })
    // The scroll event has fired before the loader runs.
    await page.evaluate(() => new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done))))
    release()
    await wiki.ready()
    expect(appRequests.length).toBe(1)
  })

  test('loads at once when the address points at a comment', async ({ wiki, page }) => {
    const first = await (await wiki.api('Admin')).rest('/page/' + (await wiki.pageId(PAGES.showcase)) + '/comments?sort=hot')
    const id = first.comments[1].id
    await wiki.open(PAGES.showcase, { hash: '#gt-' + id, scroll: false })
    await expect(page.locator('#gt-' + id)).toBeInViewport()
    await expect(page.locator('#gt-' + id + ' > div').first()).toHaveClass(/gt-highlight/)
  })

  test('moves nothing above the section while it loads', async ({ wiki, page }) => {
    await wiki.open(PAGES.showcase, { scroll: false, wait: false })
    await page.waitForLoadState('networkidle')
    const before = await page.evaluate(() => {
      const el = document.getElementById('glowthread')!
      const shifts: Array<{ value: number, where: string }> = []
      ;(window as any).__gtShifts = shifts
      new PerformanceObserver((list) => {
        for (const e of list.getEntries() as any[]) {
          for (const src of e.sources || []) {
            const node = src.node as Node | null
            const where = !node ? 'unknown' : el.contains(node) ? 'section' : el.compareDocumentPosition(node) & Node.DOCUMENT_POSITION_PRECEDING ? 'above' : 'below'
            shifts.push({ value: e.value, where })
          }
        }
      }).observe({ type: 'layout-shift', buffered: false })
      // Bring the top of the section into view, with the end of the article above it.
      window.scrollTo(0, el.getBoundingClientRect().top + window.scrollY - window.innerHeight / 2)
      return el.getBoundingClientRect().top + window.scrollY
    })
    await wiki.ready()
    await settled(page)
    const after = await page.evaluate(() => ({
      top: document.getElementById('glowthread')!.getBoundingClientRect().top + window.scrollY,
      shifts: (window as any).__gtShifts as Array<{ value: number, where: string }>,
    }))
    expect(after.top).toBeCloseTo(before, 0)
    // Only things below the section (the footer) may move as the list grows.
    expect(after.shifts.filter((x) => x.where === 'above' || x.where === 'section'), JSON.stringify(after.shifts)).toEqual([])
  })
})

// The layout shift check runs in every skin: skins.spec.ts.
test.describe('a short article', () => {
  test('loads the section on screen after the page, without a scroll', async ({ wiki, page }) => {
    const loaded = page.waitForEvent('load')
    await wiki.open(PAGES.play, { scroll: false, wait: false })
    await loaded
    await wiki.ready()
    await expect(page.locator('.ext-glowthread #gt-heading')).toBeVisible()
  })
})

/**
 * The modules of every load.php request the page makes, one list per request, expanded from
 * ResourceLoader's packed form ("ext.glowThread.app.extras,menu" is two modules).
 */
function moduleRequests(page: Page): string[][] {
  const requests: string[][] = []
  page.on('request', (r) => {
    const url = new URL(r.url())
    const packed = url.pathname.endsWith('/load.php') && url.searchParams.get('only') !== 'styles' ? url.searchParams.get('modules') : null
    if (!packed) return
    requests.push(packed.split('|').flatMap((group) => {
      const dot = group.lastIndexOf('.')
      return group.indexOf(',') < 0 || dot < 0 ? group.split(',') : group.slice(dot + 1).split(',').map((s) => group.slice(0, dot + 1) + s)
    }))
  })
  return requests
}

const APP = 'ext.glowThread.app'
const MENU = 'ext.glowThread.app.menu'
const EXTRAS = 'ext.glowThread.app.extras'
const withModule = (requests: string[][], name: string) => requests.filter((m) => m.indexOf(name) >= 0)

/** Holds back the requests for the menu's module a little, so that a first opening waits for it. */
async function slowMenuModule(page: Page) {
  await page.route(/\/load\.php\?/, async (route) => {
    if (/ext\.glowThread\.app\.(menu|extras,menu|menu,extras)/.test(decodeURIComponent(route.request().url()))) await new Promise((done) => setTimeout(done, 600))
    await route.continue()
  })
}

// The reader app is three modules (client/src/comments/lazy.ts): ext.glowThread.app for every
// reader, ext.glowThread.app.menu with the items of the ⋯ menus, and ext.glowThread.app.extras
// with the composer, the report form and the moderation tools.
test.describe('the app in parts', () => {
  test('a logged-out reader loads the menus only when reaching for one, and once', async ({ wiki, page }) => {
    const requests = moduleRequests(page)
    await wiki.open(PAGES.showcase)
    await settled(page)
    expect(withModule(requests, APP)).toHaveLength(1)
    expect(withModule(requests, MENU)).toEqual([])
    expect(withModule(requests, EXTRAS)).toEqual([])
    const more = wiki.section.getByRole('button', { name: 'More actions' })
    // A pointer over ⋯ on a desktop, a touch on a phone.
    if (wiki.phone) await more.first().tap()
    else await more.first().hover()
    await expect.poll(() => withModule(requests, MENU).length).toBe(1)
    if (wiki.phone) await page.keyboard.press('Escape')
    // Other menus, opened and closed: nothing more to load.
    for (const i of [1, 2]) {
      await more.nth(i).click()
      await expect(page.getByRole('menu')).toBeVisible()
      await page.keyboard.press('Escape')
      await expect(page.getByRole('menu')).toHaveCount(0)
    }
    await settled(page)
    expect(withModule(requests, MENU)).toHaveLength(1)
    expect(withModule(requests, EXTRAS)).toEqual([])
  })

  test('focus on ⋯ loads the menus too', async ({ wiki, page }) => {
    test.skip(wiki.phone, 'keyboard')
    const requests = moduleRequests(page)
    await wiki.open(PAGES.showcase)
    await settled(page)
    expect(withModule(requests, MENU)).toEqual([])
    await wiki.section.getByRole('button', { name: 'More actions' }).first().focus()
    await expect.poll(() => withModule(requests, MENU).length).toBe(1)
  })

  for (const how of ['click', 'tap', 'Enter'] as const) {
    test('the ⋯ menu opens at the first ' + how + ', its items still loading, as it does later', async ({ wiki, page }) => {
      test.skip(how === 'tap' ? !wiki.phone : wiki.phone, how === 'tap' ? 'touch screens' : 'desktop')
      await slowMenuModule(page)
      await wiki.open(PAGES.showcase)
      const more = wiki.section.getByRole('button', { name: 'More actions' }).first()
      const menu = page.getByRole('menu')
      /** Opens the menu, and returns where focus went: the menu or its first item. */
      const open = async () => {
        if (how === 'click') await more.click()
        else if (how === 'tap') await more.tap()
        else {
          await more.focus()
          await page.keyboard.press('Enter')
        }
        await expect(menu).toBeVisible()
        // ⋯ names the menu it opened, the first time already.
        await expect(more).toHaveAttribute('aria-expanded', 'true')
        expect(await more.getAttribute('aria-controls')).toBe(await menu.getAttribute('id'))
        await expect.poll(() => menu.evaluate((m) => m.contains(document.activeElement))).toBe(true)
        const focus = await page.evaluate(() => document.activeElement!.getAttribute('role') + ' ' + document.activeElement!.textContent!.trim())
        await page.keyboard.press('Escape')
        await expect(menu).toHaveCount(0)
        await expect(more).toBeFocused()
        return focus
      }
      const first = await open()
      // The keyboard lands on the first item. A pointer leaves focus on the menu itself, ready
      // for the arrow keys (Reka's menus do that every time).
      expect(first).toBe(how === 'Enter' ? 'menuitem Copy link' : 'menu Copy link')
      expect(await open()).toBe(first)
    })
  }

  test('a logged-in reader gets the composer and the menus in a request of their own, with the first comments', async ({ wiki, page, context }) => {
    const parent = await postComment(wiki, 'Admin', PAGES.play, unique('Answer me'))
    // Whether the composer was there when the first comment appeared (one render, nothing moves).
    await context.addInitScript(() => {
      new MutationObserver(() => {
        const w = window as any
        if (w.__gtComposerFirst === undefined && document.querySelector('#glowthread article')) w.__gtComposerFirst = !!document.querySelector('#glowthread textarea')
      }).observe(document, { childList: true, subtree: true })
    })
    const requests = moduleRequests(page)
    await wiki.as('Alice')
    await wiki.open(PAGES.play)
    await expect(wiki.section.locator('article').first()).toBeVisible()
    expect(await page.evaluate(() => (window as any).__gtComposerFirst)).toBe(true)
    // The app's own request is the one every reader shares: the other two come apart.
    const app = withModule(requests, APP)
    expect(app).toHaveLength(1)
    expect(app[0]).not.toContain(EXTRAS)
    expect(app[0]).not.toContain(MENU)
    expect(withModule(requests, EXTRAS)).toHaveLength(1)
    expect(withModule(requests, MENU)).toHaveLength(1)
    // A reply with an emoji and a mention.
    await wiki.section.getByRole('tab', { name: 'Newest' }).click()
    const article = wiki.section.locator('article[data-comment="' + parent + '"]')
    await article.getByRole('button', { name: 'Reply' }).first().click()
    const box = article.getByRole('textbox')
    await expect(box).toBeFocused()
    const text = unique('Parts reply')
    await page.keyboard.type(text + ' ')
    await article.getByRole('button', { name: 'Emoji' }).click()
    await page.getByRole('button', { name: '🎂' }).click()
    await expect(box).toBeFocused()
    const users = page.waitForResponse((r) => /[?&]list=allusers/.test(r.url()))
    await page.keyboard.type(' @Bo')
    await users
    await page.getByRole('option', { name: 'Bob', exact: true }).click()
    await article.getByRole('button', { name: 'Send' }).click()
    const reply = article.locator('article', { hasText: text })
    await expect(reply).toContainText(text + ' 🎂 @Bob')
    await expect(reply.locator('p a', { hasText: '@Bob' })).toHaveAttribute('href', /User:Bob$/)
  })

  test('when the composer\'s module cannot load, the list still shows and says so', async ({ wiki, page }) => {
    await page.route(/\/load\.php\?/, (route) => (decodeURIComponent(route.request().url()).indexOf('ext.glowThread.app.extras') >= 0 ? route.abort() : route.continue()))
    await wiki.as('Alice')
    await wiki.open(PAGES.showcase, { wait: false })
    // At once, not after the 5 s the list would wait for the module (App.vue, EXTRAS_WAIT).
    await expect(wiki.section.locator('article').first()).toBeVisible({ timeout: 4_000 })
    await expect(toast(page)).toContainText('Could not reach the wiki. Check your connection and try again.')
    await settled(page)
    // No placeholder left waiting for the module.
    await expect(wiki.section.locator('.gt-pulse')).toHaveCount(0)
    await expect(page.locator('#glowthread')).not.toHaveAttribute('aria-busy', 'true')
    await expect(wiki.section.getByRole('tab', { name: 'Newest' })).toBeVisible()
    wiki.errors.length = 0 // the failed module is logged on purpose
  })

  test('a logged-in reader\'s #gt- link centres and highlights the comment, as a logged-out reader\'s does', async ({ wiki, page }) => {
    // A comment every reader sees, with comments above and below it.
    const list = await new Session().rest('/page/' + (await wiki.pageId(PAGES.showcase)) + '/comments?sort=hot')
    const id = list.comments[3].id
    /** How far the comment sits from the middle of what the window shows below any sticky header. */
    const offset = () => page.evaluate((cid) => {
      let inset = 0
      for (let el = document.elementFromPoint(innerWidth / 2, 1); el && el !== document.body; el = el.parentElement) {
        if (/fixed|sticky/.test(getComputedStyle(el).position)) inset = el.getBoundingClientRect().bottom
      }
      const r = document.getElementById('gt-' + cid)!.getBoundingClientRect()
      return Math.round(r.top + r.height / 2 - (inset + innerHeight) / 2)
    }, id)
    const visit = async () => {
      await wiki.open(PAGES.showcase, { hash: '#gt-' + id, scroll: false })
      await expect(page.locator('#gt-' + id + ' > div').first()).toHaveClass(/gt-highlight/)
      await expect.poll(() => page.evaluate(() => document.activeElement!.id)).toBe('gt-' + id)
      // Where it lands, and where it stays once everything is drawn.
      const landed = await offset()
      await settled(page)
      expect(await offset()).toBe(landed)
      return landed
    }
    const reader = await visit()
    expect(Math.abs(reader)).toBeLessThan(page.viewportSize()!.height / 4)
    // Logged in, the list waits for the composer's module: the comment still lands in the same place.
    await page.goto('about:blank') // the same address again would only move to the anchor
    await wiki.as('Alice')
    expect(Math.abs((await visit()) - reader)).toBeLessThanOrEqual(4)
  })
})
