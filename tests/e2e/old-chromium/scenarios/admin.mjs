// Old Chromium check of the moderation hub (Special:GlowThread) as a moderator: the queue
// mounts with counts and items, the keyboard moves focus and selects, the bulk bar and the
// record panel show, the shortcut sheet opens, and the settings view renders.
//   node login-cookies.mjs Moderator
//   node run.mjs 'http://localhost:8151/index.php?title=Special:GlowThread/queue&tab=all&useskin=vector' \
//     --cookies .cache/Moderator.cookies.json --scenario scenarios/admin.mjs --out shots/c90-admin.png
//   rm .cache/Moderator.cookies.json
const wait = (page, fn, timeout = 20000) => page.waitForFunction(fn, { timeout })

export default async function (page, { sleep, viewport, options }) {
  const r = {}
  await wait(page, () => document.querySelector('#ext-glowthread-admin [role="feed"] > article') && !document.querySelector('#ext-glowthread-admin .gt-pulse'))
  r.items = await page.evaluate(() => document.querySelectorAll('#ext-glowthread-admin [role="feed"] > article').length)
  r.tabs = await page.evaluate(() => Array.from(document.querySelectorAll('#ext-glowthread-admin [role="tab"]')).map((t) => t.textContent.replace(/\s+/g, ' ').trim()))
  r.layout = await page.evaluate(() => {
    const root = document.querySelector('#ext-glowthread-admin .gt-admin')
    const cs = getComputedStyle(root)
    return { display: cs.display, columns: cs.gridTemplateColumns, aside: !!root.querySelector('aside') }
  })
  r.styles = await page.evaluate(() => {
    const cs = (s) => getComputedStyle(document.querySelector(s))
    return {
      font: cs('#ext-glowthread-admin .gt-root').fontFamily.split(',')[0],
      tabUnderline: getComputedStyle(document.querySelector('#ext-glowthread-admin [role="tab"][aria-selected="true"]'), '::before').backgroundColor,
      itemGap: cs('#ext-glowthread-admin [role="feed"] > article').columnGap,
      kbd: cs('#ext-glowthread-admin .gt-kbd').borderTopWidth,
    }
  })
  // Keyboard: the first j focuses the first item, the next moves on, x selects.
  await page.keyboard.press('j')
  await page.keyboard.press('j')
  await sleep(300)
  r.focused = await page.evaluate(() => document.activeElement.id)
  await page.keyboard.press('x')
  await sleep(300)
  r.bulkBar = await page.evaluate(() => {
    const bar = document.querySelector('[role="toolbar"][aria-label="Bulk actions"]')
    return bar ? bar.textContent.replace(/\s+/g, ' ').trim().slice(0, 60) : null
  })
  r.record = await page.evaluate(() => (document.querySelector('#ext-glowthread-admin aside h3') || {}).textContent || null)
  await viewport(options.out.replace(/\.png$/, '-queue.png'))
  await page.keyboard.press('Escape')
  await page.keyboard.press('?')
  await sleep(500)
  r.shortcuts = await page.evaluate(() => !!document.querySelector('[role="dialog"]'))
  await viewport(options.out.replace(/\.png$/, '-shortcuts.png'))
  await page.keyboard.press('Escape')
  await sleep(400)
  // Settings through the rail (client-side route).
  await page.click('#ext-glowthread-admin nav a[href*="settings"]')
  await wait(page, () => document.querySelector('#gt-setting-maxLength') && document.querySelector('#ext-glowthread-admin textarea'))
  r.settings = await page.evaluate(() => ({ url: location.pathname + location.search, title: document.title.split(' - ')[0], maxLength: document.querySelector('#gt-setting-maxLength').value }))
  await viewport(options.out.replace(/\.png$/, '-settings.png'))
  await page.goBack()
  await wait(page, () => document.querySelector('#ext-glowthread-admin [role="feed"]'))
  r.back = await page.evaluate(() => document.title.split(' - ')[0])
  r.overflowX = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
  return r
}
