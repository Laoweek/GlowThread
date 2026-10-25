import { test, expect } from './fixtures'
import AxeBuilder from '@axe-core/playwright'
import { TOKEN, fallback, rgb } from './design'
import { feedItems, hub, moderate, openHub, seedComment, token } from './admin-helpers'

// The moderation hub in every skin project: Vector 2022 (light and night), legacy Vector,
// Minerva on a phone, Citizen on a phone (light and night), and WebKit on legacy Vector.

async function axe(page: import('@playwright/test').Page) {
  const r = await new AxeBuilder({ page })
    .include('#ext-glowthread-admin')
    .include('#glowthread-portal')
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
    .analyze()
  return r.violations.map((v) => v.id + ' (' + v.nodes.length + '): ' + v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' | '))
}

test('meets WCAG 2.2 AA in automated checks (axe): queue, selection, record, settings', async ({ wiki, page }) => {
  const tk = token('axe')
  const reported = await seedComment(wiki, 'Accessibility check ' + tk, { reporters: ['Moderator'] })
  // A held one too (blacklisted link), deleted afterwards: authors may have only a few held.
  const held = await seedComment(wiki, 'Accessibility check ' + tk + ' https://spam.example/promo', { author: 'Erin' })
  try {
    await scanHub(wiki, page, tk)
  } finally {
    await moderate(wiki, [held], 'delete')
    await moderate(wiki, [reported], 'dismiss')
  }
})

async function scanHub(wiki: import('./fixtures').Wiki, page: import('@playwright/test').Page, tk: string) {
  await wiki.as('Moderator')
  await openHub(wiki, 'queue', { tab: 'all', q: tk })
  await expect(feedItems(page)).toHaveCount(2)
  await feedItems(page).first().getByRole('checkbox').click()
  await expect(page.getByRole('toolbar', { name: 'Bulk actions' })).toBeVisible()
  if (wiki.phone) {
    // The author's name opens the record as a sheet.
    await feedItems(page).first().locator('header button').first().click()
    await expect(page.getByRole('dialog')).toBeVisible()
  } else {
    await expect(hub(page).locator('aside h3')).toBeVisible()
  }
  expect(await axe(page)).toEqual([])
  await page.keyboard.press('Escape')
  await openHub(wiki, 'settings')
  await page.getByLabel('Test a comment').fill('Cheap casino tokens')
  expect(await axe(page)).toEqual([])
}

test('uses its own type and colours in this skin and theme', async ({ wiki, page }, testInfo) => {
  await wiki.as('Moderator')
  await openHub(wiki, 'queue', { tab: 'all' })
  const night = !!testInfo.project.use.gtNight
  const facts = await page.evaluate(() => {
    const root = document.querySelector('#ext-glowthread-admin .gt-root')!
    const text = document.querySelector('#ext-glowthread-admin article p[dir="auto"]')!
    return { family: getComputedStyle(root).fontFamily.split(',')[0].trim(), color: getComputedStyle(text).color }
  })
  // The defaults of the token registry (client/src/design/tokens.json).
  expect(facts.family).toBe(TOKEN['font-family'].light.split(',')[0].trim())
  expect(facts.color).toBe(await rgb(page, fallback('color-fg', night)))
  // A wiki may give the hub a background through the documented token (its night variant in
  // night mode).
  const hubBg = () => page.evaluate(() => getComputedStyle(document.querySelector('#ext-glowthread-admin .gt-admin')!).backgroundColor)
  const prop = night ? '--glowthread-night-color-bg' : '--glowthread-color-bg'
  await page.evaluate((p) => document.documentElement.style.setProperty(p, 'rgb(1, 2, 3)'), prop)
  await expect.poll(hubBg).toBe('rgb(1, 2, 3)')
  await page.evaluate((p) => document.documentElement.style.removeProperty(p), prop)
})

/** Controls under the hub smaller than 44 px (counting their ::after hit area). */
function smallTargets(page: import('@playwright/test').Page, selector: string) {
  return page.evaluate((sel) => {
    const bad: string[] = []
    document.querySelectorAll<HTMLElement>(sel).forEach((el) => {
      const r = el.getBoundingClientRect()
      if (!r.width) return
      const after = getComputedStyle(el, '::after')
      const w = Math.max(r.width, parseFloat(after.minWidth) || 0)
      const h = Math.max(r.height, parseFloat(after.minHeight) || 0)
      if (w < 44 || h < 44) bad.push((el.getAttribute('aria-label') || el.textContent || el.id || el.tagName).trim().slice(0, 20) + ` ${Math.round(w)}x${Math.round(h)}`)
    })
    return bad
  }, selector)
}

test('fits a phone screen with 44 px touch targets', async ({ wiki, page }) => {
  test.skip(!wiki.phone, 'phones only')
  await wiki.as('Moderator')
  await openHub(wiki, 'queue', { tab: 'all' })
  await feedItems(page).first().getByRole('checkbox').click()
  const overflow = () => page.evaluate(() => {
    const s = document.querySelector('#ext-glowthread-admin .gt-root') as HTMLElement
    return { page: document.documentElement.scrollWidth - window.innerWidth, app: s.scrollWidth - s.clientWidth }
  })
  const queue = await overflow()
  expect(queue.page).toBeLessThanOrEqual(0)
  expect(queue.app).toBeLessThanOrEqual(0)
  expect(await smallTargets(page, '#ext-glowthread-admin button, #ext-glowthread-admin [role="tab"], #ext-glowthread-admin [role="checkbox"], #ext-glowthread-admin a.gt-hit, #ext-glowthread-admin label.gt-hit')).toEqual([])
  // Settings: number fields, checkboxes and the rows around them (captions and links in
  // prose are text, not controls).
  await openHub(wiki, 'settings')
  const settings = await overflow()
  expect(settings.page).toBeLessThanOrEqual(0)
  expect(settings.app).toBeLessThanOrEqual(0)
  expect(await smallTargets(page, '#ext-glowthread-admin button, #ext-glowthread-admin [role="checkbox"], #ext-glowthread-admin input, #ext-glowthread-admin textarea, #ext-glowthread-admin a.gt-hit')).toEqual([])
  // Each checkbox row is tappable over its full height: a tap at its edges toggles that box.
  const rows = await page.evaluate(() => Array.from(document.querySelectorAll('#ext-glowthread-admin label:has([role="checkbox"])')).map((label) => {
    label.scrollIntoView({ block: 'center' }) // elementFromPoint sees only the viewport
    const r = label.getBoundingClientRect()
    const x = r.left + r.width / 2
    const own = (y: number) => {
      const hit = document.elementFromPoint(x, y)
      return !!hit && (hit === label || label.contains(hit))
    }
    return { name: (label.textContent || '').trim().slice(0, 30), height: Math.round(r.height), top: own(r.top + 2), bottom: own(r.bottom - 2) }
  }))
  expect(rows.length).toBeGreaterThan(1)
  expect(rows.filter((r) => r.height < 44 || !r.top || !r.bottom)).toEqual([])
})

// Two site CSS rules with !important, as wikis write them in MediaWiki:Common.css or a skin's
// stylesheet. The hub lives inside #bodyContent, so both would reach it without its counter-rules.
const SITE_CSS = '#bodyContent p { margin: 1em 0 !important } h1, h2, h3, h4, h5, h6 { font-family: Georgia, serif !important }'

test('holds against the wiki\'s !important site rules', async ({ wiki, page }) => {
  const tk = token('site')
  const id = await seedComment(wiki, 'Site CSS ' + tk, { reporters: ['Moderator'] })
  await wiki.as('Moderator')
  await openHub(wiki, 'queue', { q: tk })
  await page.addStyleTag({ content: SITE_CSS })
  const facts = await page.evaluate(() => {
    const p = document.querySelector('#ext-glowthread-admin article p[dir="auto"]')!
    const h = document.querySelector('#ext-glowthread-admin h2')!
    return { margin: getComputedStyle(p).marginTop, font: getComputedStyle(h).fontFamily.split(',')[0].trim() }
  })
  await moderate(wiki, [id], 'dismiss')
  expect(facts).toEqual({ margin: '0px', font: TOKEN['font-family'].light.split(',')[0].trim() })
})
