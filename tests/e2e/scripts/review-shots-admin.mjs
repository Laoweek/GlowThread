// Screenshots of the moderation hub (Special:GlowThread) for the design review, written to
// .impeccable/review/. Run from tests/e2e/:
//   node scripts/review-shots-admin.mjs
// Desktop is 1440 px wide on the wiki's default skin (Vector 2022), the phone 390 px on the
// default phone skin (Minerva). Uses the dev wiki's seeded moderation queue as Moderator, and
// changes nothing: approvals are answered by a stand-in and the settings are never saved.
import { chromium } from '@playwright/test'
import { mkdirSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { login, WIKI } from '../lib/wiki.mjs'

const OUT = fileURLToPath(new URL('../../../.impeccable/review/', import.meta.url))
mkdirSync(OUT, { recursive: true })
const HUB = 'Special:GlowThread'
const UA_PHONE = 'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36'
const desktop = { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 }
const phone = { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, userAgent: UA_PHONE }

const browser = await chromium.launch()
const session = await login('Moderator')

async function shot(name, { device = desktop, night = false, sub = 'queue', query = {}, act = null, full = false, mockWrites = false, emptyQueue = false } = {}) {
  const ctx = await browser.newContext({ ...device, colorScheme: night ? 'dark' : 'light', reducedMotion: 'reduce', locale: 'en-US', timezoneId: 'UTC' })
  await ctx.addCookies(session.playwrightCookies(WIKI))
  if (night) {
    await ctx.addInitScript(() => {
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
  const page = await ctx.newPage()
  if (mockWrites) {
    // Moderation answered by a stand-in (held comments approved): the seeded queue stays as
    // it is, and the counts the hub asks for afterwards agree with the stand-in.
    let approved = 0
    await page.route('**/api.php', async (route) => {
      const body = route.request().postData() || ''
      if (route.request().method() !== 'POST' || !body.includes('glowthreadmoderate')) return route.continue()
      const params = new URLSearchParams(body)
      const ids = (params.get('ids') || '').split('|').map(Number)
      approved += ids.length
      return route.fulfill({ json: { glowthreadmoderate: { result: 'success', do: params.get('do'), results: ids.map((id, i) => ({ id, result: 'success', status: 'visible', old: 'held', code: null, logId: 900000 + i })) } } })
    })
    await page.route('**/rest.php/glowthread/v1/moderation/queue?*', async (route) => {
      const json = await (await route.fetch()).json()
      if (json.counts) json.counts.held = Math.max(0, json.counts.held - approved)
      return route.fulfill({ json })
    })
  }
  if (emptyQueue) {
    // A cleared queue: no items in any tab.
    await page.route('**/rest.php/glowthread/v1/moderation/queue?*', (route) => route.fulfill({ json: { tab: 'reported', sort: 'reports', items: [], next: null, counts: { reported: 0, held: 0, deleted: 256 } } }))
  }
  const errors = []
  page.on('pageerror', (e) => errors.push(e.message))
  // No useskin: the wiki's default for the device. Night mode as each skin's own switch sets it.
  const qs = new URLSearchParams({ title: HUB + '/' + sub, ...query })
  if (night) qs.set(device === phone ? 'minervanightmode' : 'vectornightmode', '1')
  await page.goto(WIKI + '/index.php?' + qs.toString())
  await page.waitForSelector('#ext-glowthread-admin .gt-root')
  await page.waitForFunction(() => !document.querySelector('#ext-glowthread-admin .gt-pulse'))
  await page.waitForTimeout(800)
  if (act) await act(page)
  await page.waitForTimeout(600)
  await page.screenshot({ path: OUT + name, fullPage: full })
  console.log(name, errors.length ? 'ERRORS ' + errors.join('; ') : 'ok')
  await ctx.close()
}

const items = (p) => p.locator('#ext-glowthread-admin [role="feed"] > article')

// The queue as a moderator meets it, keyboard focus on the first item (j).
await shot('admin-desktop.png', { act: async (p) => p.keyboard.press('j') })
// Night: held comments with two selected and the bulk bar.
await shot('admin-desktop-dark.png', {
  night: true,
  query: { tab: 'held' },
  act: async (p) => {
    for (const key of ['j', 'x', 'j', 'x']) await p.keyboard.press(key)
  },
})
// Phone.
await shot('admin-mobile.png', { device: phone })
// The record of Mallory, the spammer persona, opened from her held comment.
await shot('admin-history.png', {
  query: { tab: 'held' },
  act: async (p) => {
    const mallory = p.getByRole('button', { name: 'Mallory', exact: true })
    await items(p).filter({ has: mallory }).first().locator(mallory).first().click()
    await p.waitForTimeout(800)
  },
})
// Settings with the word-filter test. The dev wiki has no filter rules, so the sample would
// not be held; plain rules match whole words, so a rule such as "cheap coins" would catch it.
await shot('admin-settings.png', {
  sub: 'settings',
  full: true,
  act: async (p) => {
    await p.locator('#gt-filter-test').fill('Selling cheap coins, message me for a discount')
    await p.waitForTimeout(1200)
    await p.evaluate(() => window.scrollTo(0, 0))
  },
})
// What the hub shows around an action: the undo toast (the write is a stand-in).
await shot('admin-undo-toast.png', {
  query: { tab: 'held' },
  mockWrites: true,
  act: async (p) => {
    await p.keyboard.press('j')
    await p.keyboard.press('a')
    await p.getByRole('button', { name: 'Undo' }).waitFor()
  },
})
await shot('admin-empty.png', { emptyQueue: true })
await shot('admin-shortcuts.png', { act: async (p) => p.keyboard.press('?') })
// Unsaved settings: the save bar lists the change and asks for a reason (never saved).
await shot('admin-settings-save.png', {
  sub: 'settings',
  act: async (p) => {
    const field = p.locator('#gt-setting-maxLength')
    await field.fill(String(Number(await field.inputValue()) === 1000 ? 1200 : 1000))
    const bar = p.getByRole('region', { name: /unsaved change/ })
    await bar.getByRole('button', { name: 'Review and save' }).click()
    await bar.getByRole('textbox').fill('Longer comments for detailed recipe notes')
  },
})
// Phone: the record as a sheet, and the bulk bar.
await shot('admin-mobile-history.png', {
  device: phone,
  act: async (p) => {
    await items(p).nth(1).locator('header button').first().click()
    await p.getByRole('dialog').waitFor()
    await p.waitForTimeout(800)
  },
})
await shot('admin-mobile-bulk.png', {
  device: phone,
  query: { tab: 'held' },
  act: async (p) => {
    await items(p).nth(0).getByRole('checkbox').click()
    await items(p).nth(1).getByRole('checkbox').click()
    await p.getByRole('toolbar', { name: 'Bulk actions' }).waitFor()
  },
})
await browser.close()
