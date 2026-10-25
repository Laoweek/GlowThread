import { test, expect, PAGES, postComment, unique } from './fixtures'
import { Session } from '../lib/wiki.mjs'
import type { Page } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { TOKEN, fallback, rgb } from './design'

// Runs in every skin project: Vector 2022 (light and night), legacy Vector, Minerva on a
// phone, Citizen on a phone (light and night), and WebKit on legacy Vector.

const PROPS = [
  'display', 'position', 'color', 'background-color', 'font-family', 'font-size', 'font-weight', 'font-style',
  'line-height', 'letter-spacing', 'text-transform', 'text-decoration-line', 'text-align', 'white-space',
  'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
  'border-top-width', 'border-top-style', 'border-top-color', 'border-left-width', 'border-left-style', 'border-radius',
  'box-shadow', 'list-style-type', 'vertical-align', 'cursor', 'opacity', 'box-sizing',
  'font-variant-numeric', 'font-feature-settings', 'font-variation-settings', 'word-break', 'overflow-wrap', 'color-scheme',
]

/**
 * Where the loader puts the section (docs/theming.md, "Skin notes"): last inside #bodyContent,
 * or right after it on legacy Vector. Site CSS that places #glowthread relies on this.
 */
const SHAPE: Record<string, string> = {
  'vector': 'after',
  'vector-2022': 'inside',
  'minerva': 'inside',
  'citizen': 'inside',
}

/** The skin's page footer, which the section must stay above. */
const FOOTER: Record<string, string> = {
  'vector': '#footer',
  'vector-2022': '#footer',
  'minerva': '.minerva-footer',
  'citizen': '.citizen-page-footer',
}

/** Computed styles of every element under `selector`, keyed by position. */
function snapshot(page: Page, selector: string) {
  return page.evaluate(([sel, props]) => {
    const out: Record<string, Record<string, string>> = {}
    document.querySelectorAll(sel).forEach((root, r) => {
      ;[root, ...Array.from(root.querySelectorAll('*'))].forEach((el, i) => {
        const cs = getComputedStyle(el)
        out[r + ':' + i + ':' + el.tagName] = Object.fromEntries((props as string[]).map((p) => [p, cs.getPropertyValue(p)]))
      })
    })
    return out
  }, [selector, PROPS] as const)
}

function diff(a: Record<string, Record<string, string>>, b: Record<string, Record<string, string>>) {
  const rows: string[] = []
  for (const k of Object.keys(a)) {
    if (!b[k]) {
      rows.push(k + ' missing')
      continue
    }
    for (const p of PROPS) if (a[k][p] !== b[k][p]) rows.push(`${k} ${p}: ${a[k][p]} -> ${b[k][p]}`)
  }
  return rows
}

/** Waits until the page's downloads are done and two more frames have been drawn. */
async function settled(page: Page) {
  await page.waitForLoadState('networkidle')
  await page.evaluate(() => new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done))))
}

/** Waits for the animations and transitions under `selector` to finish (menus, sheets, colours). */
async function animationsDone(page: Page, selector: string) {
  // Polled on animation frames: a finished transition can start another (an icon inherits
  // its button's colour, and the app's reduced-motion rule keeps 1 ms transitions).
  await page.waitForFunction((sel) => Array.from(document.querySelectorAll(sel)).every((el) => el.getAnimations({ subtree: true }).every((a) => a.playState === 'finished')), selector)
}

/** Where the section sits: after the article text, above the skin's page footer, aligned with the text. */
function placement(page: Page, footer: string) {
  return page.evaluate((footerSel) => {
    const el = document.getElementById('glowthread')!
    const body = document.getElementById('bodyContent')!
    const text = document.getElementById('mw-content-text')!.getBoundingClientRect()
    const r = el.getBoundingClientRect()
    const foot = document.querySelector(footerSel)
    return {
      // Right after #bodyContent, or last inside it.
      shape: el.previousElementSibling === body ? 'after' : el.parentElement === body && body.lastElementChild === el ? 'inside' : 'elsewhere',
      classes: el.className,
      left: Math.round(r.left - text.left),
      belowText: r.top >= text.bottom - 1,
      footer: foot ? Math.round(foot.getBoundingClientRect().top - r.bottom) : null,
      overflow: document.documentElement.scrollWidth - window.innerWidth,
    }
  }, footer)
}

test('sits after the article text and above the skin\'s page footer', async ({ wiki, page }) => {
  await wiki.open(PAGES.showcase)
  const p = await placement(page, FOOTER[wiki.skin])
  expect(p.shape).toBe(SHAPE[wiki.skin])
  expect(p.classes).toContain('ext-glowthread')
  expect(p.left).toBe(0)
  expect(p.belowText).toBe(true)
  expect(p.footer, 'no page footer ' + FOOTER[wiki.skin]).not.toBeNull()
  expect(p.footer!, 'the section ends above the page footer').toBeGreaterThanOrEqual(0)
  expect(p.overflow).toBeLessThanOrEqual(0)
})

test('stays where site CSS places it with its own grid area', async ({ wiki, page, context }) => {
  test.skip(wiki.skin !== 'citizen', 'Citizen lays out its page body as a grid')
  // A wiki's MediaWiki:Citizen.css that gives the section a row of its own between the
  // article and the page footer. It is in place before the loader runs, like site CSS.
  await context.addInitScript(() => {
    document.addEventListener('DOMContentLoaded', () => {
      const style = document.createElement('style')
      style.textContent = ".citizen-body-container { grid-template-areas: 'content' 'comments' 'footer' } #glowthread { grid-area: comments }"
      document.head.appendChild(style)
    })
  })
  await wiki.open(PAGES.showcase)
  const p = await placement(page, FOOTER.citizen)
  const grid = await page.evaluate(() => {
    const el = document.getElementById('glowthread')!
    return { parent: el.parentElement!.className, area: getComputedStyle(el).gridArea }
  })
  // Still a grid item in the row the site gave it, not moved into #bodyContent.
  expect(grid.parent).toContain('citizen-body-container')
  expect(grid.area).toContain('comments')
  expect(p.shape).toBe('after')
  expect(p.left).toBe(0)
  expect(p.belowText).toBe(true)
  expect(p.footer!).toBeGreaterThanOrEqual(0)
  expect(p.overflow).toBeLessThanOrEqual(0)
  await expect(wiki.section.locator('article').first()).toBeVisible()
})

test('takes the space reserved for it without moving the page', async ({ wiki, page, context, browserName }) => {
  test.skip(browserName === 'webkit', 'Safari has no Layout Instability API')
  await context.addInitScript(() => {
    const w = window as any
    w.__gtShifts = []
    new PerformanceObserver((list) => {
      for (const e of list.getEntries() as any[]) if (!e.hadRecentInput) w.__gtShifts.push(e.value)
    }).observe({ type: 'layout-shift', buffered: true })
  })
  const cls = () => page.evaluate(() => ((window as any).__gtShifts as number[]).reduce((a, b) => a + b, 0))
  // Short articles: the section is on screen without scrolling. GlowThread's share is the
  // difference to the same page without it (no reservation, no loader): skins shift on their
  // own, e.g. Vector 2022 fills its pinned appearance menu after load, which moves whatever
  // follows a short article. Pages whose comment count does not change during the run: the
  // server estimates the reserved height from a count it caches for an hour.
  for (const title of [PAGES.empty, PAGES.userPage, PAGES.locked]) {
    await wiki.open(title, { scroll: false })
    await settled(page)
    // The loader took over the reservation.
    expect(await page.evaluate(() => document.body.classList.contains('ext-glowthread-reserve'))).toBe(false)
    const withSection = await cls()
    await page.route((url) => url.pathname.endsWith('/index.php'), async (route) => {
      if (route.request().resourceType() !== 'document') return route.continue()
      const response = await route.fetch()
      const body = (await response.text()).replace(/\bext-glowthread-reserve\b/, '').replace(/"ext\.glowThread\.init",?/, '')
      await route.fulfill({ response, body })
    })
    await page.goto('/index.php?title=' + encodeURIComponent(title) + '&useskin=' + wiki.skin)
    await settled(page)
    expect(await page.evaluate(() => !!document.getElementById('glowthread') || document.body.classList.contains('ext-glowthread-reserve'))).toBe(false)
    const without = await cls()
    await page.unrouteAll()
    expect(withSection - without, title + ': ' + withSection.toFixed(4) + ' with, ' + without.toFixed(4) + ' without').toBeLessThan(0.02)
  }
})

test('skin CSS does not reach the app (leak-in)', async ({ wiki, page }) => {
  await wiki.open(PAGES.showcase)
  // Open a menu too, so the portal's content is covered. With the keyboard: a mouse pointer
  // would hover whatever sits under it once the skin's layout is gone, and change its colour.
  await wiki.section.locator('article').first().getByRole('button', { name: 'More actions' }).focus()
  await page.keyboard.press('Enter')
  await expect(page.getByRole('menu')).toBeVisible()
  // The trigger's colour transition too.
  await animationsDone(page, '#glowthread, #glowthread-portal')
  const sel = '#glowthread .gt-root, #glowthread-portal'
  // Keep the app's width, so auto margins and wrapping do not change with the skin's layout.
  await page.evaluate(() => {
    const root = document.querySelector('#glowthread .gt-root') as HTMLElement
    root.style.width = root.getBoundingClientRect().width + 'px'
  })
  const withSkin = await snapshot(page, sel)
  // Turn off every stylesheet except GlowThread's own, then measure again.
  await page.evaluate(() => {
    for (const s of Array.from(document.styleSheets)) {
      const own = s.ownerNode && (s.ownerNode.textContent || '').indexOf('.gt-scope') >= 0
      if (!own) s.disabled = true
    }
    document.querySelectorAll<HTMLElement>('[style]').forEach((el) => {
      if (!el.closest('#glowthread, #glowthread-portal')) el.removeAttribute('style')
    })
  })
  const bare = await snapshot(page, sel)
  expect(diff(bare, withSkin)).toEqual([])
})

test('the app does not reach the article (leak-out)', async ({ wiki, page, context }) => {
  const sel = '#mw-content-text, #firstHeading, h1'
  // First without the app: the module request fails, the loader backs out.
  await context.route('**/load.php?*', (route) =>
    decodeURIComponent(route.request().url()).indexOf('ext.glowThread.app') >= 0 ? route.abort() : route.continue(),
  )
  await wiki.open(PAGES.showcase, { wait: false })
  await page.waitForFunction(() => !document.querySelector('#glowthread[aria-busy]'))
  // Let the skin's own scripts settle (MobileFrontend collapses sections late).
  await settled(page)
  const without = await snapshot(page, sel)
  await context.unroute('**/load.php?*')
  wiki.errors.length = 0 // the blocked module logs a load error on purpose
  await wiki.open(PAGES.showcase)
  await settled(page)
  const withApp = await snapshot(page, sel)
  expect(diff(without, withApp)).toEqual([])
})

test('uses its own type and colours in this skin and theme', async ({ wiki, page }, testInfo) => {
  await wiki.open(PAGES.showcase)
  const night = !!testInfo.project.use.gtNight
  const facts = await page.evaluate(() => {
    const cs = (el: Element | null) => getComputedStyle(el as Element)
    const root = document.querySelector('#glowthread .gt-root')!
    const heading = document.getElementById('gt-heading')!
    const body = document.querySelector('#glowthread article p[dir="auto"]')!
    const login = document.querySelector('#glowthread a[href*="UserLogin"]')!
    return {
      family: cs(root).fontFamily.split(',')[0].trim(),
      headingWeight: cs(heading).fontWeight,
      color: cs(body).color,
      buttonRadius: cs(login).borderTopLeftRadius,
    }
  })
  // The defaults of the token registry (client/src/design/tokens.json).
  expect(facts.family).toBe(TOKEN['font-family'].light.split(',')[0].trim())
  expect(facts.headingWeight).toBe(TOKEN['font-weight-strong'].light)
  expect(facts.buttonRadius).toBe(TOKEN['radius-control'].light)
  expect(facts.color).toBe(await rgb(page, fallback('color-fg', night)))
  // A wiki may give the section a background through the documented token (its night
  // variant in night mode).
  const sectionBg = () => page.evaluate(() => getComputedStyle(document.querySelector('#glowthread .glowthread')!).backgroundColor)
  expect(await sectionBg()).toBe(await rgb(page, fallback('color-bg', night)))
  const prop = night ? '--glowthread-night-color-bg' : '--glowthread-color-bg'
  await page.evaluate((p) => document.documentElement.style.setProperty(p, 'rgb(1, 2, 3)'), prop)
  await expect.poll(sectionBg).toBe('rgb(1, 2, 3)')
  await page.evaluate((p) => document.documentElement.style.removeProperty(p), prop)
})

test('keeps its heading type against a site rule that sets heading fonts with !important', async ({ wiki, page }) => {
  await wiki.open(PAGES.showcase)
  // A common pattern in a wiki's MediaWiki:Common.css or skin CSS.
  await page.addStyleTag({ content: 'h1, h2, h3, h4, h5, h6 { font-family: Georgia, serif !important }' })
  const fonts = await page.evaluate(() => ({
    section: getComputedStyle(document.getElementById('gt-heading')!).fontFamily.split(',')[0].trim(),
    author: getComputedStyle(document.querySelector('#glowthread article h3')!).fontFamily.split(',')[0].trim(),
    article: getComputedStyle(document.getElementById('firstHeading')!).fontFamily.split(',')[0].trim(),
  }))
  // The rule still applies to the article, so the test would notice if it stopped working.
  const own = TOKEN['font-family'].light.split(',')[0].trim()
  expect(fonts).toEqual({ section: own, author: own, article: 'Georgia' })
})

/** Buttons, tabs and hit-area links under `selector` smaller than 44 px (counting their ::after hit area). */
function smallTargets(page: Page, selector: string) {
  return page.evaluate((sel) => {
    const bad: string[] = []
    document.querySelectorAll<HTMLElement>(sel).forEach((el) => {
      const r = el.getBoundingClientRect()
      if (!r.width) return
      const after = getComputedStyle(el, '::after')
      const w = Math.max(r.width, parseFloat(after.minWidth) || 0)
      const h = Math.max(r.height, parseFloat(after.minHeight) || 0)
      if (w < 44 || h < 44) bad.push((el.getAttribute('aria-label') || el.textContent || el.tagName).trim().slice(0, 20) + ` ${Math.round(w)}x${Math.round(h)}`)
    })
    return bad
  }, selector)
}

test('fits a phone screen and keeps touch targets at 44 px', async ({ wiki, page }) => {
  test.skip(!wiki.phone, 'phones only')
  await wiki.open(PAGES.showcase)
  const overflow = await page.evaluate(() => {
    const s = document.querySelector('#glowthread .gt-root') as HTMLElement
    return { page: document.documentElement.scrollWidth - window.innerWidth, section: s.scrollWidth - s.clientWidth }
  })
  expect(overflow.page).toBeLessThanOrEqual(0)
  expect(overflow.section).toBeLessThanOrEqual(0)
  expect(await smallTargets(page, '#glowthread button, #glowthread [role="tab"], #glowthread a.gt-hit')).toEqual([])
  // The report form, a sheet on phones: its buttons, and each reason row tappable over its full
  // 44 px height (a tap at the row's edges must land on that option, not on the row around it).
  await wiki.as('Moderator')
  await wiki.open(PAGES.showcase)
  const other = wiki.section.locator('ol > li > article').filter({ hasNot: page.locator('h3 a[href$="User:Moderator"]') }).first()
  await other.getByRole('button', { name: 'More actions' }).first().click()
  await page.getByRole('menuitem', { name: 'Report' }).click()
  const sheet = page.getByRole('dialog', { name: 'Report comment' })
  await expect(sheet.getByRole('radio').first()).toBeVisible()
  await animationsDone(page, '#glowthread-portal')
  expect(await smallTargets(page, '[role="dialog"] button:not([role="radio"])')).toEqual([])
  const rows = await sheet.evaluate((dialog) => Array.from(dialog.querySelectorAll('[role="radio"]')).map((radio) => {
    const row = radio.parentElement!.getBoundingClientRect()
    const own = (x: number, y: number) => {
      const hit = document.elementFromPoint(x, y)
      const target = hit && hit.closest('label, [role="radio"]')
      return !!target && (target === radio || target.getAttribute('for') === radio.id)
    }
    const x = row.left + row.width / 2
    return {
      name: (radio.parentElement!.textContent || '').trim(),
      height: Math.round(row.height),
      top: own(x, row.top + 2),
      bottom: own(x, row.bottom - 2),
    }
  }))
  expect(rows.length).toBeGreaterThan(1)
  expect(rows.filter((r) => r.height < 44 || !r.top || !r.bottom)).toEqual([])
})

test('a long unbroken word in a comment does not make the page wider than a phone screen', async ({ wiki, page }) => {
  test.skip(!wiki.phone, 'phones only')
  // On a page of its own, so that the test pages keep their layout if this breaks again.
  const title = 'GlowThread long word test'
  const edit = await (await wiki.api('Admin')).write({ action: 'edit', title, text: 'A page for a comment with a long word.', summary: 'e2e' })
  expect(edit.edit && edit.edit.result, JSON.stringify(edit)).toBe('Success')
  const word = 'x'.repeat(120)
  await postComment(wiki, 'Admin', title, unique('Long word') + ' ' + word)
  await wiki.open(title)
  await expect(wiki.section.locator('article', { hasText: word }).first()).toBeVisible()
  // A phone browser widens its layout to fit wide content, and then zooms out: compare with the
  // screen, not with the window, which grows with the content.
  const screen = page.viewportSize()!.width
  const widths = await page.evaluate(() => ({ window: window.innerWidth, page: document.documentElement.scrollWidth }))
  expect(widths).toEqual({ window: screen, page: screen })
})

test('meets WCAG 2.2 AA in automated checks (axe), reading and writing', async ({ wiki, page }) => {
  const scan = async () => {
    const r = await new AxeBuilder({ page })
      .include('#glowthread')
      .include('#glowthread-portal')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
      .analyze()
    return r.violations.map((v) => v.id + ' (' + v.nodes.length + '): ' + v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' | '))
  }
  await wiki.open(PAGES.showcase)
  expect(await scan()).toEqual([])
  await wiki.as('Alice')
  await wiki.open(PAGES.showcase)
  await wiki.section.getByRole('textbox').click()
  await wiki.section.locator('ol > li > article').first().getByRole('button', { name: 'More actions' }).first().click()
  await expect(page.getByRole('menu')).toBeVisible()
  await animationsDone(page, '#glowthread, #glowthread-portal')
  expect(await scan()).toEqual([])
  await page.keyboard.press('Escape')
})

test('meets WCAG 2.2 AA with a conversation unfolded, and with a linked conversation (axe)', async ({ wiki, page }) => {
  const scan = async () => {
    const r = await new AxeBuilder({ page }).include('#glowthread').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze()
    return r.violations.map((v) => v.id + ' (' + v.nodes.length + '): ' + v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' | '))
  }
  // The showcase's 11-reply conversation, every reply shown.
  const list = await new Session().rest('/page/' + (await wiki.pageId(PAGES.showcase)) + '/comments?sort=hot')
  const id = list.comments.find((c: { totalReplies: number }) => c.totalReplies === 11).id
  await wiki.open(PAGES.showcase)
  const thread = wiki.section.locator('ol > li > article[data-comment="' + id + '"]')
  await thread.getByRole('button', { name: /^Show \d+ (more )?replies$/ }).click()
  await expect(thread.locator('article[data-comment]')).toHaveCount(11)
  await animationsDone(page, '#glowthread')
  expect(await scan()).toEqual([])
  // A permalink to a comment far down a busy page: its conversation in the box above the list.
  const s = new Session()
  const pid = await wiki.pageId(PAGES.kitchen)
  let r = await s.rest('/page/' + pid + '/comments?sort=hot')
  r = await s.rest('/page/' + pid + '/comments?sort=hot&cursor=' + encodeURIComponent(r.next))
  await wiki.open(PAGES.kitchen, { hash: '#gt-' + r.comments[r.comments.length - 1].id, scroll: false })
  await expect(wiki.section.locator('[data-linked] article').first()).toBeVisible()
  await animationsDone(page, '#glowthread')
  expect(await scan()).toEqual([])
})
