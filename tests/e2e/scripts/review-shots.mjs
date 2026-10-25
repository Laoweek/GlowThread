// Screenshots for the design review, written to .impeccable/review/. Run from tests/e2e/:
//   node scripts/review-shots.mjs
// Desktop is 1440 px wide on the wiki's default skin (Vector 2022), phones are 390 px on the
// default phone skin (Minerva through MobileFrontend). Legacy Vector and Citizen get one shot
// each. The section's top is scrolled to the top of the screen, as a reader meets it. Uses the
// dev wiki's seeded pages (dev/README.md, "Seed content"), mostly the showcase thread.
import { chromium } from '@playwright/test'
import { mkdirSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { login, pageId, PAGES, Session, WIKI } from '../lib/wiki.mjs'

const OUT = fileURLToPath(new URL('../../../.impeccable/review/', import.meta.url))
mkdirSync(OUT, { recursive: true })
const UA_PHONE = 'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36'
const desktop = { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 }
const phone = { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, userAgent: UA_PHONE }

const browser = await chromium.launch()
const sessions = {}
async function shot(name, { device = desktop, skin = null, user = null, night = false, title = PAGES.showcase, hash = '', before = null, act = null, full = false, offset = 16, list = 'ok' } = {}) {
  const ctx = await browser.newContext({ ...device, colorScheme: night ? 'dark' : 'light', reducedMotion: 'reduce', locale: 'en-US', timezoneId: 'UTC' })
  if (user) {
    sessions[user] = sessions[user] || (await login(user))
    await ctx.addCookies(sessions[user].playwrightCookies(WIKI))
  }
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
  if (list === 'hang') {
    // The loading state: the first page of comments never arrives.
    await page.route('**/rest.php/glowthread/v1/page/*/comments?sort=*', () => new Promise(() => {}))
  } else if (list === 'fail') {
    // The error state: the server answers the list with an error.
    await page.route('**/rest.php/glowthread/v1/page/*/comments?sort=*', (route) => route.fulfill({ status: 503, json: { error: 'unavailable' } }))
  }
  const errors = []
  page.on('pageerror', (e) => errors.push(e.message))
  // No useskin: the wiki's default for the device. Night mode as each skin's own switch sets it
  // (Vector 2022 takes vectornightmode for logged-in readers, Minerva takes minervanightmode).
  const q = new URLSearchParams({ title })
  if (skin) q.set('useskin', skin)
  if (night) q.set((skin || (device === phone ? 'minerva' : 'vector-2022')) === 'minerva' ? 'minervanightmode' : 'vectornightmode', '1')
  await page.goto(WIKI + '/index.php?' + q + hash)
  await page.waitForSelector('#glowthread', { state: 'attached' })
  await page.evaluate(() => document.getElementById('glowthread').scrollIntoView())
  await page.waitForSelector('#glowthread .gt-root #gt-heading')
  if (list === 'hang') await page.waitForSelector('#glowthread .gt-root .gt-pulse')
  else if (list === 'fail') await page.getByRole('button', { name: 'Try again' }).waitFor()
  else await page.waitForFunction(() => !document.querySelector('#glowthread .gt-pulse'))
  if (before) await before(page)
  await page.evaluate((o) => {
    const el = document.getElementById('glowthread')
    window.scrollTo(0, el.getBoundingClientRect().top + window.scrollY - o)
  }, offset)
  await page.waitForTimeout(300)
  if (act) await act(page)
  await page.waitForTimeout(500)
  if (full) await (await page.$('#glowthread')).screenshot({ path: OUT + name })
  else await page.screenshot({ path: OUT + name })
  console.log(name, errors.length ? 'ERRORS ' + errors.join('; ') : 'ok')
  await ctx.close()
}

// The second comment in Top order (the first is the pinned notice). Its author is Bob, so the
// menu and report shots use Carol, who may report it.
const menu = async (page) => {
  await page.locator('#glowthread ol > li > article').nth(1).getByRole('button', { name: 'More actions' }).first().click()
}
const report = async (page) => {
  await menu(page)
  await page.getByRole('menuitem', { name: 'Report' }).click()
}
const focusComposer = async (page) => {
  await page.locator('#glowthread textarea').first().click()
  await page.keyboard.type('Thanks for all the tips, @Bo')
}
// Newest order, once its first page is drawn (so the scroll below sees the full height).
const newest = async (page) => {
  await page.getByRole('tab', { name: 'Newest' }).click()
  await page.getByRole('tabpanel', { name: 'Newest' }).locator('article').first().waitFor()
  await page.waitForFunction(() => !document.querySelector('#glowthread .gt-pulse'))
}
// The longest conversation on the showcase page (11 replies), unfolded in place and scrolled
// into view. A permalink to a comment past the first page opens the Conversation box instead.
const showcase = await new Session().rest('/page/' + (await pageId(PAGES.showcase)) + '/comments?sort=hot')
const longest = showcase.comments.find((c) => c.totalReplies === 11).id
const thread = async (page) => {
  const t = page.locator('#glowthread ol > li > article[data-comment="' + longest + '"]')
  await t.getByRole('button', { name: /^Show \d+ (more )?replies$/ }).click()
  await t.getByRole('button', { name: 'Hide replies' }).waitFor()
  await t.evaluate((el) => el.scrollIntoView({ block: 'start' }))
}
const kitchen = await pageId(PAGES.kitchen)
let late = await new Session().rest('/page/' + kitchen + '/comments?sort=hot')
late = await new Session().rest('/page/' + kitchen + '/comments?sort=hot&cursor=' + encodeURIComponent(late.next))
const linkedHash = '#gt-' + late.comments[late.comments.length - 1].id
const linked = async (page) => {
  await page.locator('#glowthread [data-linked] article').first().waitFor()
  await page.locator('#glowthread [data-linked]').evaluate((el) => el.scrollIntoView({ block: 'start' }))
}

// Required set: desktop and phone, light and dark.
await shot('desktop.png', { user: 'Alice' })
await shot('desktop-dark.png', { night: true, user: 'Alice' })
await shot('mobile.png', { device: phone })
await shot('mobile-dark.png', { device: phone, night: true })
// Extra variants.
await shot('desktop-logged-out.png', {})
await shot('desktop-vector.png', { skin: 'vector' })
await shot('desktop-composer.png', { user: 'Alice', act: focusComposer })
await shot('desktop-menu.png', { user: 'Carol', act: menu })
// Newest order on the load-test page: its popular strip sits above the list.
await shot('desktop-newest.png', { user: 'Alice', title: PAGES.load, before: newest })
await shot('desktop-moderator.png', { user: 'Moderator', before: newest })
await shot('desktop-moderator-dark.png', { night: true, user: 'Moderator', before: newest })
await shot('desktop-thread.png', { user: 'Alice', act: thread })
await shot('desktop-linked.png', { title: PAGES.kitchen, hash: linkedHash, act: linked })
await shot('desktop-report.png', { user: 'Carol', act: report })
await shot('desktop-empty.png', { user: 'Alice', title: PAGES.empty })
await shot('desktop-full.png', { user: 'Alice', full: true })
await shot('mobile-logged-in.png', { device: phone, user: 'Alice' })
await shot('mobile-report.png', { device: phone, user: 'Carol', act: report })
await shot('mobile-thread-dark.png', { device: phone, night: true, act: thread })
await shot('mobile-linked.png', { device: phone, title: PAGES.kitchen, hash: linkedHash, act: linked })
await shot('mobile-citizen.png', { device: phone, skin: 'citizen' })
await shot('mobile-blocked.png', { device: phone, user: 'PartialBlocked', title: PAGES.partial })
// Inline moderation: the Reported chip on, so the reported comments show their reasons and
// Approve / Hide / Dismiss reports in place (the seed files reports on the showcase page).
const reportedOnly = async (p) => {
  await p.getByRole('group', { name: 'Moderation tools' }).getByRole('button', { name: /^Reported/ }).click()
  await p.waitForFunction(() => !document.querySelector('#glowthread .gt-pulse'))
}
await shot('desktop-moderation-inline.png', { user: 'Moderator', before: reportedOnly })
await shot('desktop-moderation-inline-dark.png', { night: true, user: 'Moderator', before: reportedOnly })
await shot('mobile-moderation-inline.png', { device: phone, user: 'Moderator', before: reportedOnly })
await shot('mobile-moderation-inline-dark.png', { device: phone, night: true, user: 'Moderator', before: reportedOnly })
// A comment deleted while it was held: Restore for review puts it back in the review queue,
// and says so. Newbie's comment links to a blacklisted site, so it is held whether or not
// Newbie is still inside the new-account gate. Made once on the showcase page, then found
// again by its text.
async function deletedWhileHeld() {
  const mod = await login('Moderator')
  const text = 'Hello from a new baker! I wrote up my first loaf here: https://spam.example/first-loaf'
  const found = await mod.rest('/moderation/queue?tab=deleted&limit=50&q=' + encodeURIComponent(text))
  if (found.items.length) return found.items[0].comment.id
  const r = await (await login('Newbie')).write({ action: 'glowthreadpost', pageid: await pageId(PAGES.showcase), text })
  if (!r.glowthreadpost || r.glowthreadpost.status !== 'held') throw new Error('expected a held comment: ' + JSON.stringify(r))
  const id = r.glowthreadpost.id
  await mod.write({ action: 'glowthreadmoderate', ids: String(id), do: 'delete', reason: 'Link to a spam site' })
  return id
}
const restoreHeldId = await deletedWhileHeld()
await shot('desktop-restore-held.png', {
  user: 'Moderator',
  before: newest,
  act: async (p) => p.locator('#glowthread article[data-comment="' + restoreHeldId + '"]').evaluate((el) => el.scrollIntoView({ block: 'center' })),
})
// Loading and error states.
await shot('desktop-loading.png', { user: 'Alice', list: 'hang' })
await shot('desktop-error.png', { user: 'Alice', list: 'fail' })
await browser.close()
