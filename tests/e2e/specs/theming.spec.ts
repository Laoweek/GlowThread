import { test, expect, PAGES } from './fixtures'
import type { Page } from '@playwright/test'
import type { Wiki } from './fixtures'
import AxeBuilder from '@axe-core/playwright'
import { openHub } from './admin-helpers'
import { EXAMPLES, TOKEN, TOKENS, fallback, rgb } from './design'

// Theming (docs/theming.md): site CSS sets the public --glowthread-* properties, GlowThread only
// reads them, each with its default, into internal --gt-v-* on its scope roots (the app roots
// and #glowthread-portal, class ext-glowthread-theme) and on its container parts. Night mode
// reads --glowthread-night-*. The site CSS goes where MediaWiki puts a wiki's site styles (after
// GlowThread's) or where a skin's style module goes (before them): both must work. Expected
// values come from the token registry and the example themes in client/src/design/.

const SECTION = '#glowthread .gt-root'
const COMMENT = '#glowthread .ext-glowthread-comment'
const PORTAL = '#glowthread-portal'
const MENU = '#glowthread-portal .ext-glowthread-menu'
const TOAST = '#glowthread-portal .ext-glowthread-toast'
const REPORT = '#glowthread-portal .ext-glowthread-report'
const HUB = '#ext-glowthread-admin .gt-root'
const QUEUE_ITEM = '#ext-glowthread-admin .ext-glowthread-queue-item'

type Pos = 'site' | 'early'

/**
 * Serves the page with `css` in its HTML: 'site' where OutputPage puts site.styles (the
 * ResourceLoaderDynamicStyles marker, after which nothing else is inserted, so GlowThread's
 * stylesheets come first), 'early' at the top of <head> like a skin's style module.
 */
async function siteCss(page: Page, css: string, pos: Pos = 'site') {
  await page.unroute((url) => url.pathname.endsWith('/index.php'))
  await page.route((url) => url.pathname.endsWith('/index.php'), async (route) => {
    if (route.request().resourceType() !== 'document') return route.continue()
    const response = await route.fetch()
    const tag = '<style id="e2e-site-css">' + css + '</style>'
    const html = await response.text()
    const body = pos === 'early'
      ? html.replace(/<meta charset="UTF-8">/i, (m) => m + tag)
      : html.replace('<meta name="generator"', '<meta name="ResourceLoaderDynamicStyles" content="">' + tag + '\n<meta name="generator"')
    expect(body, 'the site CSS went into the page').toContain(tag)
    await route.fulfill({ response, body })
  })
}

/** Whether GlowThread's stylesheet comes before the site CSS in the document. */
const gtFirst = (page: Page) => page.evaluate(() => {
  const site = document.getElementById('e2e-site-css')!
  const gt = Array.from(document.querySelectorAll('style')).find((s) => (s.textContent || '').indexOf('.gt-scope') >= 0)!
  return !!(gt.compareDocumentPosition(site) & Node.DOCUMENT_POSITION_FOLLOWING)
})

/** The internal values of `names` on the first element matching `selector`. */
function internal(page: Page, selector: string, names: string[]) {
  return page.evaluate(([sel, ns]) => {
    const el = document.querySelector(sel as string)
    if (!el) return null
    const cs = getComputedStyle(el)
    return Object.fromEntries((ns as string[]).map((n) => [n, cs.getPropertyValue('--gt-v-' + n).trim()]))
  }, [selector, names] as const)
}

/** What --gt-v-<name> holds when the site gives `value` (phones add their step to some sizes). */
function expected(name: string, value: string, phone: boolean) {
  const t = TOKEN[name]
  return phone && t.phone ? 'calc(' + value + ' ' + t.phone + ')' : value
}

/**
 * Asserts internal values on a surface. The build minifies GlowThread's defaults (#fff for
 * #ffffff, .25rem for 0.25rem), so both sides are compared as the browser computes them: a
 * colour as rgb(), a length in px, a time in ms, anything else without white space.
 */
async function expectValues(page: Page, selector: string, want: Record<string, string>, label = selector) {
  await expect(page.locator(selector).first(), label).toBeAttached()
  const bad = await page.evaluate(([sel, w]) => {
    const el = document.querySelector(sel as string)!
    const cs = getComputedStyle(el)
    const probe = document.createElement('span')
    probe.style.position = 'absolute'
    el.appendChild(probe)
    const canon = (v: string) => {
      v = v.trim()
      probe.style.cssText = 'position:absolute'
      probe.style.color = v
      if (probe.style.color) return 'colour ' + getComputedStyle(probe).color
      probe.style.paddingTop = v
      if (probe.style.paddingTop) return 'length ' + getComputedStyle(probe).paddingTop
      const time = /^(-?[\d.]+)(ms|s)$/.exec(v)
      if (time) return 'time ' + Number(time[1]) * (time[2] === 's' ? 1000 : 1)
      if (/^-?[\d.]+$/.test(v)) return 'number ' + Number(v)
      return v.replace(/\s+/g, '').toLowerCase()
    }
    const out: string[] = []
    for (const [n, v] of Object.entries(w as Record<string, string>)) {
      const got = cs.getPropertyValue('--gt-v-' + n)
      if (canon(got) !== canon(v)) out.push(n + ': got ' + got.trim() + ', want ' + v)
    }
    probe.remove()
    return out
  }, [selector, want] as const)
  expect(bad, label).toEqual([])
}

/** Opens the More actions menu of a comment that is not the reader's own. */
async function openMenu(wiki: Wiki, page: Page) {
  await wiki.section.locator('ol > li > article:not([data-glowthread-own])').first().getByRole('button', { name: 'More actions' }).first().click()
  await expect(page.locator(MENU)).toBeVisible()
}

const night = () => !!test.info().project.use.gtNight
/** The prefix a site uses for the current mode: light values, or night values in night mode. */
const prefix = () => (night() ? '--glowthread-night-' : '--glowthread-')

test.describe('site CSS selectors', () => {
  for (const pos of ['site', 'early'] as Pos[]) {
    test(`reach the section, menus, toasts, the report form and the hub from the ${pos === 'site' ? 'site styles' : 'skin module'} position`, async ({ wiki, page }) => {
      // Each selector sets a token of its own, so one page shows which selector reaches what.
      const p = prefix()
      const css = [
        ':root { ' + p + 'color-fg: rgb(1, 1, 1) }',
        'html { ' + p + 'color-fg-muted: rgb(2, 2, 2) }',
        'body { ' + p + 'color-fg-subtle: rgb(3, 3, 3) }',
        '.ext-glowthread-theme { ' + p + 'color-surface: rgb(4, 4, 4) }',
        '#glowthread { ' + p + 'color-border: rgb(5, 5, 5) }',
        // A rule for night mode only.
        'html.skin-theme-clientpref-night { --glowthread-radius-control: 7px }',
      ].join('\n')
      const everywhere = { 'color-fg': 'rgb(1, 1, 1)', 'color-fg-muted': 'rgb(2, 2, 2)', 'color-fg-subtle': 'rgb(3, 3, 3)', 'color-surface': 'rgb(4, 4, 4)', 'radius-control': night() ? '7px' : fallback('radius-control', false) }
      const inSection = { ...everywhere, 'color-border': 'rgb(5, 5, 5)' }
      const outside = { ...everywhere, 'color-border': fallback('color-border', night()) }
      await siteCss(page, css, pos)
      await wiki.as('Alice')
      await wiki.open(PAGES.showcase)
      expect(await gtFirst(page)).toBe(pos === 'site')
      await expectValues(page, SECTION, inSection, 'section')
      await expectValues(page, COMMENT, inSection, 'comment')
      // The text itself takes the colour.
      await expect(wiki.section.locator('article .ext-glowthread-body').first()).toHaveCSS('color', 'rgb(1, 1, 1)')
      await openMenu(wiki, page)
      await expectValues(page, MENU, outside, 'menu')
      await expect(page.locator(MENU)).toHaveCSS('background-color', 'rgb(4, 4, 4)')
      await page.getByRole('menuitem', { name: 'Copy link' }).click()
      await expect(page.locator(TOAST).first()).toBeVisible()
      await expectValues(page, TOAST, outside, 'toast')
      await openMenu(wiki, page)
      await page.getByRole('menuitem', { name: 'Report' }).click()
      await expect(page.locator(REPORT)).toBeVisible()
      await expectValues(page, REPORT, outside, 'report form')
      await page.keyboard.press('Escape')
      // The hub: everything but #glowthread, which is not on its page.
      await wiki.as('Moderator')
      await openHub(wiki, 'queue', { tab: 'all' })
      expect(await gtFirst(page)).toBe(pos === 'site')
      await expectValues(page, HUB, outside, 'hub')
      await expectValues(page, QUEUE_ITEM, outside, 'queue item')
      await expectValues(page, PORTAL + '[data-glowthread-app="hub"]', outside, 'hub overlays')
    })
  }
})

test.describe('the token registry', () => {
  // Every colour token, and one token of each other group, each with a value of its own.
  const colours = TOKENS.filter((t) => t.group === 'colour' && !t.at)
  const others = ['line-height-body', 'comment-padding-y', 'radius-control', 'avatar-radius', 'icon-size', 'duration-fast', 'text-max-width', 'z-toast']
  const SAMPLE: Record<string, string> = {
    'line-height-body': '1.9', 'comment-padding-y': '7px', 'radius-control': '3px', 'avatar-radius': '5px',
    'icon-size': '19px', 'duration-fast': '123ms', 'text-max-width': '33em', 'z-toast': '999',
  }
  const sentinel = (i: number, n: string) => (n === 'color-scheme' ? 'light dark' : 'rgb(' + (i + 10) + ', ' + ((i * 7) % 250) + ', ' + (200 - i) + ')')
  const light = Object.fromEntries([...colours.map((t, i) => [t.name, sentinel(i, t.name)]), ...others.map((n) => [n, SAMPLE[n]])])
  const nightValues = Object.fromEntries(colours.filter((t) => t.night !== undefined).map((t, i) => [t.name, t.name === 'color-scheme' ? 'dark light' : 'rgb(' + (i + 60) + ', 9, 9)']))

  test('the registry has the tokens these tests sample', () => {
    expect(colours.length).toBeGreaterThan(20)
    for (const n of others) expect(TOKEN[n], n).toBeTruthy()
  })

  test('light values apply in light mode and leave night mode alone', async ({ wiki, page }) => {
    await siteCss(page, ':root { ' + Object.entries(light).map(([n, v]) => '--glowthread-' + n + ': ' + v).join('; ') + ' }')
    await wiki.as('Alice')
    await wiki.open(PAGES.showcase)
    // In night mode, tokens with a night value keep GlowThread's night default.
    const want = Object.fromEntries(Object.entries(light).map(([n, v]) => [n, night() && TOKEN[n].night !== undefined ? fallback(n, true) : expected(n, v, wiki.phone)]))
    await expectValues(page, SECTION, want, 'section')
    await expectValues(page, PORTAL, want, 'overlays')
    await openMenu(wiki, page)
    await expectValues(page, MENU, want, 'menu')
    await wiki.as('Moderator')
    await openHub(wiki, 'queue', { tab: 'all' })
    await expectValues(page, HUB, want, 'hub')
    await expectValues(page, QUEUE_ITEM, want, 'queue item')
  })

  test('night values apply in night mode only', async ({ wiki, page }) => {
    await siteCss(page, ':root { ' + Object.entries(nightValues).map(([n, v]) => '--glowthread-night-' + n + ': ' + v).join('; ') + ' }')
    await wiki.open(PAGES.showcase)
    const want = Object.fromEntries(Object.entries(nightValues).map(([n, v]) => [n, night() ? v : fallback(n, false)]))
    await expectValues(page, SECTION, want, 'section')
    await expectValues(page, COMMENT, want, 'comment')
    await expectValues(page, PORTAL, want, 'overlays')
  })
})

test('the night and day switches, and a dark system with the skin following it', async ({ wiki, page }) => {
  await wiki.open(PAGES.showcase)
  const fg = (n: boolean) => ({ 'color-fg': fallback('color-fg', n), 'color-surface': fallback('color-surface', n) })
  const body = wiki.section.locator('article .ext-glowthread-body').first()
  const html = (add: string[], remove: string[] = []) => page.evaluate(([a, r]) => {
    document.documentElement.classList.remove(...r)
    document.documentElement.classList.add(...a)
  }, [add, remove])
  const skinNight = ['skin-theme-clientpref-night', 'skin-theme-clientpref-os']
  await expectValues(page, SECTION, fg(night()), 'as the project')
  // ext-glowthread-night on <html> or any other ancestor turns night on.
  await html(['ext-glowthread-night'], skinNight)
  await expectValues(page, SECTION, fg(true), 'html.ext-glowthread-night')
  await expectValues(page, PORTAL, fg(true), 'overlays with html.ext-glowthread-night')
  await expect(body).toHaveCSS('color', await rgb(page, fallback('color-fg', true)))
  await html([], ['ext-glowthread-night'])
  await page.evaluate(() => document.body.classList.add('ext-glowthread-night'))
  await expectValues(page, SECTION, fg(true), 'body.ext-glowthread-night')
  await page.evaluate(() => document.body.classList.remove('ext-glowthread-night'))
  await expectValues(page, SECTION, fg(false), 'no switch, no night class')
  // ext-glowthread-day keeps light values under the skin's night class.
  await html(['skin-theme-clientpref-night', 'ext-glowthread-day'])
  await expectValues(page, SECTION, fg(false), 'html.ext-glowthread-day under the night class')
  await expect(body).toHaveCSS('color', await rgb(page, fallback('color-fg', false)))
  await html([], ['ext-glowthread-day', 'skin-theme-clientpref-night'])
  // The skin following the system: night when the system is dark.
  await html(['skin-theme-clientpref-os'])
  await page.emulateMedia({ colorScheme: 'light' })
  await expectValues(page, SECTION, fg(false), 'os, light system')
  await page.emulateMedia({ colorScheme: 'dark' })
  await expectValues(page, SECTION, fg(true), 'os, dark system')
  await expectValues(page, PORTAL, fg(true), 'overlays, os, dark system')
})

test('a token set on a part applies inside it: pinned comments on a tint, replies reset', async ({ wiki, page }) => {
  // A pinned comment with a reply of its own, on the sandbox.
  const admin = await wiki.api('Admin')
  const pageid = await wiki.pageId(PAGES.play)
  const post = async (text: string, replyto?: number) => (await admin.write({ action: 'glowthreadpost', pageid, text: text + ' ' + Date.now().toString(36), ...(replyto ? { replyto } : {}) })).glowthreadpost.id as number
  const root = await post('Pinned for the tint')
  const reply = await post('A reply under it', root)
  await admin.write({ action: 'glowthreadmoderate', ids: String(root), do: 'pin' })
  try {
    const tint = night() ? 'rgb(40, 44, 52)' : 'rgb(246, 248, 252)'
    await siteCss(page, '.ext-glowthread-comment[data-glowthread-pinned] { --glowthread-comment-bg: rgb(246, 248, 252); --glowthread-night-comment-bg: rgb(40, 44, 52) }')
    await wiki.open(PAGES.play)
    const row = (id: number) => page.locator('#glowthread [data-comment="' + id + '"] > .ext-glowthread-comment-row')
    // Phones fold replies behind their count.
    if (wiki.phone) await page.locator('#glowthread [data-comment="' + root + '"]').getByRole('button', { name: 'Show 1 reply' }).click()
    await expect(page.locator('#glowthread [data-comment="' + root + '"]')).toHaveAttribute('data-glowthread-pinned', '')
    await expect(row(root)).toHaveCSS('background-color', tint)
    // Replies are comments inside it: they inherit the tint...
    await expect(row(reply)).toHaveCSS('background-color', tint)
    // ...unless the site resets it on them.
    await page.addStyleTag({ content: '.ext-glowthread-comment[data-glowthread-pinned] .ext-glowthread-comment { --glowthread-comment-bg: transparent; --glowthread-night-comment-bg: transparent }' })
    await expect(row(reply)).toHaveCSS('background-color', 'rgba(0, 0, 0, 0)')
    await expect(page.locator('#glowthread ol > li > article:not([data-glowthread-pinned]) > .ext-glowthread-comment-row').first()).toHaveCSS('background-color', 'rgba(0, 0, 0, 0)')
  } finally {
    await admin.write({ action: 'glowthreadmoderate', ids: String(root), do: 'unpin' })
  }
})

test('three IDs win over GlowThread\'s own rules for a plain property, one ID does not', async ({ wiki, page }) => {
  await wiki.open(PAGES.showcase)
  const meta = await page.locator('#glowthread .ext-glowthread-meta').first().evaluate((el) => getComputedStyle(el).color)
  await siteCss(page, [
    '#glowthread#glowthread#glowthread .ext-glowthread-name { color: rgb(255, 0, 0) }',
    '#glowthread#glowthread#glowthread .ext-glowthread-reply { border-top-left-radius: 0 }',
    '#glowthread .ext-glowthread-meta { color: rgb(1, 2, 3) }',
    '#glowthread-portal#glowthread-portal#glowthread-portal .ext-glowthread-menu-item { color: rgb(0, 0, 255) }',
  ].join('\n'))
  await wiki.as('Alice')
  await wiki.open(PAGES.showcase)
  await expect(page.locator('#glowthread .ext-glowthread-name').first()).toHaveCSS('color', 'rgb(255, 0, 0)')
  await expect(page.locator('#glowthread .ext-glowthread-reply').first()).toHaveCSS('border-top-left-radius', '0px')
  await expect(page.locator('#glowthread .ext-glowthread-meta').first()).toHaveCSS('color', meta)
  await openMenu(wiki, page)
  await expect(page.locator(MENU + ' .ext-glowthread-menu-item').first()).toHaveCSS('color', 'rgb(0, 0, 255)')
})

test('the hook selectors are on the page and take site rules', async ({ wiki, page }) => {
  // One plain property per hook, set through the escape hatch, shows that a rule with it applies.
  const hooks: Array<[string, string]> = [
    ['#glowthread#glowthread#glowthread article.ext-glowthread-comment[data-glowthread-depth="0"][data-glowthread-status="visible"]', 'comment, depth 0'],
    ['#glowthread#glowthread#glowthread article.ext-glowthread-comment[data-glowthread-depth="1"]', 'reply, depth 1'],
    ['#glowthread#glowthread#glowthread [data-glowthread-pinned]', 'pinned'],
    ['#glowthread#glowthread#glowthread [data-glowthread-own]', 'own'],
    ['#glowthread#glowthread#glowthread .ext-glowthread-badge[data-glowthread-tone="accent"]', 'Pinned badge'],
    ['#glowthread#glowthread#glowthread .ext-glowthread-button[data-glowthread-variant]', 'button'],
    ['#glowthread-portal#glowthread-portal#glowthread-portal .ext-glowthread-menu .ext-glowthread-menu-item', 'menu item'],
    ['#ext-glowthread-admin#ext-glowthread-admin#ext-glowthread-admin .ext-glowthread-queue-item[data-glowthread-status]', 'queue item'],
  ]
  await siteCss(page, hooks.map(([sel]) => sel + ' { outline: 3px dotted rgb(9, 8, 7) }').join('\n'))
  await wiki.as('Alice')
  await wiki.open(PAGES.showcase)
  // Phones fold replies: unfold the longest conversation, which has replies at both depths.
  if (wiki.phone) {
    const thread = wiki.section.getByRole('button', { name: 'Show 11 replies' })
    const id = await page.locator('#glowthread ol > li > article').filter({ has: page.getByRole('button', { name: 'Show 11 replies' }) }).getAttribute('data-comment')
    await thread.click()
    await expect(page.locator('#glowthread [data-comment="' + id + '"]').getByRole('button', { name: 'Hide replies' })).toBeVisible()
  }
  for (const [sel, label] of hooks.slice(0, 6)) {
    await expect(page.locator(sel).first(), label).toHaveCSS('outline-style', 'dotted')
  }
  const depths = await page.locator('#glowthread article.ext-glowthread-comment').evaluateAll((els) => [...new Set(els.map((e) => e.getAttribute('data-glowthread-depth')))].sort())
  expect(depths).toEqual(['0', '1', '2'])
  await expect(page.locator('#glowthread [data-glowthread-pinned]')).toHaveCount(1)
  await openMenu(wiki, page)
  await expect(page.locator(hooks[6][0]).first(), 'menu item').toHaveCSS('outline-style', 'dotted')
  await page.keyboard.press('Escape')
  await wiki.as('Moderator')
  await openHub(wiki, 'queue', { tab: 'all' })
  await expect(page.locator(hooks[7][0]).first(), 'queue item').toHaveCSS('outline-style', 'dotted')
})

/** The example themes, block by block ("1.", "2.", "5a." ... in client/src/design/example-themes.css). */
function exampleBlocks() {
  const out: Array<{ id: string, title: string, css: string, rules: Array<{ selector: string, decls: Array<[string, string]> }> }> = []
  const parts = EXAMPLES.split(/(?=\/\* \d+[a-z]?\. )/).slice(1)
  for (const part of parts) {
    const m = /^\/\* (\d+[a-z]?)\. ([^.\n]+)/.exec(part)!
    const css = part.replace(/\/\*[\s\S]*?\*\//g, '')
    const rules = [...css.matchAll(/([^{}]+)\{([^}]*)\}/g)].map((r) => ({
      selector: r[1].trim(),
      decls: [...r[2].matchAll(/(--glowthread-[a-z0-9-]+)\s*:\s*([^;]+);/g)].map((d) => [d[1], d[2].trim()] as [string, string]),
    }))
    out.push({ id: m[1], title: m[2].trim(), css, rules })
  }
  return out
}

/** A dark page around the section, as on a dark-only site: everything but GlowThread transparent. */
const DARK_PAGE = 'html, body { background: #101114 !important; color: #e8e8e8 !important } body *:not(.gt-scope):not(.gt-scope *) { background-color: transparent !important; background-image: none !important }'

async function axe(page: Page) {
  const r = await new AxeBuilder({ page }).include('#glowthread').include('#glowthread-portal').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze()
  return r.violations.map((v) => v.id + ' (' + v.nodes.length + '): ' + v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' | '))
}

test.describe('the example themes', () => {
  const blocks = exampleBlocks()

  test('are all here', () => {
    expect(blocks.map((b) => b.id)).toEqual(['1', '2', '3', '4', '5a', '5b', '6', '7'])
  })

  for (const block of blocks) {
    test(`${block.id}. ${block.title}`, async ({ wiki, page }) => {
      const dark = block.id === '5a' || block.id === '5b'
      await siteCss(page, block.css + (dark && !night() ? '\n' + DARK_PAGE : ''))
      if (block.id === '5a') {
        // No CSS: the switch class on <html>, as a skin or a gadget adds it.
        await page.context().addInitScript(() => document.addEventListener('DOMContentLoaded', () => document.documentElement.classList.add('ext-glowthread-night')))
      }
      const hub = block.rules.some((r) => r.selector.indexOf('"hub"') >= 0)
      if (hub) {
        // One app only: the comment section keeps the defaults.
        await wiki.as('Alice')
        await wiki.open(PAGES.showcase)
        await expectValues(page, SECTION, { space: fallback('space', false) }, 'section without the hub rule')
        await wiki.as('Moderator')
        await openHub(wiki, 'queue', { tab: 'all' })
      } else {
        await wiki.as('Alice')
        await wiki.open(PAGES.showcase)
      }
      if (block.id === '5a') await expectValues(page, SECTION, { 'color-fg': fallback('color-fg', true), 'color-surface': fallback('color-surface', true) })
      for (const rule of block.rules) {
        // Where the rule applies: the app roots for :root, the hub for the hub rule, the
        // pinned comment and its replies for the pinned rules.
        const target = rule.selector === ':root' ? (hub ? HUB : SECTION)
          : rule.selector.indexOf('"hub"') >= 0 ? HUB
          : rule.selector.indexOf('[data-glowthread-pinned] .ext-glowthread-comment') >= 0 ? null
          : '#glowthread .ext-glowthread-comment[data-glowthread-pinned]'
        if (!target) continue // the showcase's pinned comment has no replies (the tint test covers them)
        const want: Record<string, string> = {}
        for (const [prop, value] of rule.decls) {
          const isNight = prop.startsWith('--glowthread-night-')
          const name = prop.replace(/^--glowthread-(night-)?/, '')
          if (isNight !== night() && (isNight || TOKEN[name].night !== undefined)) continue // the other mode's value
          want[name] = expected(name, value, wiki.phone)
        }
        await expectValues(page, target, want, block.id + ' ' + rule.selector)
      }
      if (block.id === '1' || dark) {
        // Brand colours and dark themes keep their contrast, a menu open too.
        expect(await axe(page)).toEqual([])
        await openMenu(wiki, page)
        await expect(page.getByRole('menuitem').first()).toBeVisible()
        await page.waitForFunction(() => document.getAnimations().every((a) => a.playState === 'finished'))
        expect(await axe(page)).toEqual([])
      }
    })
  }
})

test('a theme applies in a right-to-left interface too', async ({ wiki, page }) => {
  const brand = exampleBlocks().find((b) => b.id === '1')!
  await siteCss(page, brand.css)
  await wiki.as('Alice')
  await wiki.open(PAGES.showcase, { query: { uselang: 'he' } })
  const p = night() ? '--glowthread-night-' : '--glowthread-'
  const want = Object.fromEntries(brand.rules[0].decls.filter(([prop]) => prop.startsWith(p) && (night() || !prop.startsWith('--glowthread-night-'))).map(([prop, v]) => [prop.slice(p.length), v]))
  expect(Object.keys(want).length).toBeGreaterThan(3)
  expect(await page.locator(SECTION).evaluate((el) => getComputedStyle(el).direction)).toBe('rtl')
  await expectValues(page, SECTION, want, 'section, he')
  await openMenu(wiki, page)
  await expectValues(page, MENU, want, 'menu, he')
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
  expect(overflow).toBeLessThanOrEqual(0)
  expect(await axe(page)).toEqual([])
})
