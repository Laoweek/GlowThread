// Old Chromium check, logged out: the section mounts, looks right and its controls work.
// Starts on the showcase page (its 11-reply conversation unfolds in place), then opens the
// load-test page for the popular strip, which shows only when the first page of Newest does
// not hold every comment, and reveals its comment in the "Conversation" box above the list.
//   node run.mjs 'http://localhost:8151/index.php?title=Sourdough_starter&useskin=vector' --scenario scenarios/reader.mjs --out shots/c90-reader.png
import { PAGES } from '../../lib/wiki.mjs'

const wait = (page, fn, timeout = 20000) => page.waitForFunction(fn, { timeout })

export default async function (page, { sleep, screenshot, viewport, options }) {
  const r = {}
  await page.waitForSelector('#glowthread')
  await page.evaluate(() => document.getElementById('glowthread').scrollIntoView())
  await wait(page, () => document.querySelector('#glowthread article') && !document.querySelector('#glowthread .gt-pulse'))
  r.comments = await page.evaluate(() => document.querySelectorAll('#glowthread article').length)
  r.styles = await page.evaluate(() => {
    const cs = (s) => getComputedStyle(document.querySelector(s))
    return {
      headingWeight: cs('#gt-heading').fontWeight,
      bodyColor: cs('#glowthread article p[dir="auto"]').color,
      loginRadius: cs('#glowthread a[href*="UserLogin"]').borderTopLeftRadius,
      loginBg: cs('#glowthread a[href*="UserLogin"]').backgroundColor,
      tabUnderline: getComputedStyle(document.querySelector('#glowthread [role="tab"][aria-selected="true"]'), '::before').backgroundColor,
      avatarBg: cs('#glowthread .gt-avatar[style]').backgroundColor,
      rowGap: cs('#glowthread article > div').columnGap,
    }
  })
  await screenshot(options.out.replace(/\.png$/, '-section.png'))
  // The More actions menu opens in the portal and closes with Escape. Its items are a module of
  // their own (ext.glowThread.app.menu), loaded at the first opening: wait for the menu.
  const more = await page.$('#glowthread article button[aria-label="More actions"]')
  await more.click()
  await page.waitForSelector('#glowthread-portal [role="menu"]', { timeout: 20000 })
  r.menuItems = await page.evaluate(() => Array.from(document.querySelectorAll('#glowthread-portal [role="menuitem"]')).map((e) => e.textContent.trim()))
  await viewport(options.out.replace(/\.png$/, '-menu.png'))
  await page.keyboard.press('Escape')
  await sleep(300)
  r.menuClosed = await page.evaluate(() => !document.querySelector('#glowthread-portal [role="menu"]'))
  // Like as a reader: a toast asks to log in.
  await page.click('#glowthread article button[aria-pressed]')
  await sleep(400)
  r.toast = await page.evaluate(() => (document.querySelector('#glowthread-portal [role="status"]') || {}).textContent || '')
  // The longest conversation unfolds in place: "Show N more replies" (desktop) or "Show N replies" (phones).
  r.repliesLink = await page.evaluate(() => {
    const b = Array.from(document.querySelectorAll('#glowthread ol > li > article button')).find((x) => /^Show \d+ (more )?repl/.test(x.textContent.trim()))
    if (!b) return null
    b.setAttribute('data-c90', 'replies')
    return b.textContent.trim()
  })
  if (r.repliesLink) {
    const thread = await page.evaluate(() => document.querySelector('[data-c90="replies"]').closest('ol > li > article').getAttribute('data-comment'))
    // A click on the element itself: on phones the like toast may still float over it.
    await page.$eval('[data-c90="replies"]', (b) => b.click())
    await page.waitForFunction((id) => !document.querySelector('#gt-r-list-' + id + '[aria-busy="true"]') && document.querySelector('#glowthread [data-comment="' + id + '"] button[aria-expanded="true"]'), { timeout: 20000 }, thread)
    r.repliesShown = await page.evaluate((id) => document.querySelectorAll('#glowthread ol > li > article[data-comment="' + id + '"] article').length, thread)
    r.repliesFocus = await page.evaluate(() => document.activeElement.getAttribute('data-comment'))
    await viewport(options.out.replace(/\.png$/, '-replies.png'))
  }
  // Newest tab with the popular strip on the load-test page, then the comment it reveals.
  const url = new URL(page.url())
  url.pathname = '/index.php'
  url.searchParams.set('title', PAGES.load)
  await page.goto(url.href, { waitUntil: 'networkidle2', timeout: 60000 })
  await page.waitForSelector('#glowthread')
  await page.evaluate(() => document.getElementById('glowthread').scrollIntoView())
  await wait(page, () => document.querySelector('#glowthread article') && !document.querySelector('#glowthread .gt-pulse'))
  const tabs = await page.$$('#glowthread [role="tab"]')
  await tabs[1].click()
  await wait(page, () => document.querySelector('#glowthread [role="tab"][aria-selected="true"]').textContent.trim() === 'Newest' && !document.querySelector('#glowthread .gt-pulse'))
  r.popular = await page.evaluate(() => !!document.querySelector('#gt-popular-heading'))
  // The last of the strip, the oldest pick on this page: usually not on the first page of
  // Newest, so it opens the Conversation box above the list (r.linked.box).
  await page.$eval('#glowthread section[aria-labelledby="gt-popular-heading"] li:last-child button', (b) => b.click())
  await wait(page, () => document.querySelector('#glowthread [data-linked] article') || document.querySelector('#glowthread ol .gt-highlight'))
  await sleep(500)
  r.linked = await page.evaluate(() => {
    const box = document.querySelector('#glowthread [data-linked]')
    const hl = document.querySelector('#glowthread .gt-highlight')
    const rect = (el) => { const d = el.getBoundingClientRect(); return { left: Math.round(d.left), width: Math.round(d.width), top: Math.round(d.top), height: Math.round(d.height) } }
    return { box: box ? rect(box) : null, heading: box ? box.querySelector('h3').textContent.trim() : null, highlight: hl ? rect(hl) : null, focus: document.activeElement.getAttribute('data-comment') }
  })
  await viewport(options.out.replace(/\.png$/, '-linked.png'))
  const close = await page.$('#glowthread [data-linked] button[aria-label="Close"]')
  if (close) {
    await close.click()
    await sleep(400)
  }
  r.linkedClosed = await page.evaluate(() => !document.querySelector('#glowthread [data-linked]'))
  r.overflowX = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
  return r
}
