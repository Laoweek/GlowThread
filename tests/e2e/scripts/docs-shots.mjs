// Screenshots for the README and the docs, in English (docs/images/) and Simplified Chinese
// (docs/zh-CN/images/). Run from tests/e2e/ on a freshly seeded dev wiki:
//   (cd ../../dev && ./setup.sh --reset)
//   pnpm docs-shots
// English shots use the showcase page "Sourdough starter", Chinese shots the Chinese demo page
// "番茄炒蛋" with the interface in zh-hans (dev/README.md, "Seed content"). Both get the same
// shots under the same file names. Changes nothing on the wiki: the composer text is never sent.
// PNG at twice the CSS size. Desktop is 1200 px wide (Vector 2022 with its sticky header, the
// contents and the tools column), 1100 px for the moderation hub (tools folded into a menu, so
// the record fits beside the queue). Phones are 390 px (Minerva).
import { chromium } from '@playwright/test'
import { mkdirSync, readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { login, pageId, Session, WIKI } from '../lib/wiki.mjs'

const ROOT = new URL('../../../', import.meta.url)
const LANGS = [
  {
    code: 'en',
    out: 'docs/images/',
    locale: 'en-US',
    page: 'Sourdough starter',
    typed: 'Thanks for all the tips, @',
    themes: ['Default', 'Brand colour, square avatars, tinted pin', 'Serif text, compact spacing'],
  },
  {
    code: 'zh-hans',
    out: 'docs/zh-CN/images/',
    locale: 'zh-CN',
    page: '番茄炒蛋',
    typed: '谢谢大家的建议，@',
    themes: ['默认外观', '品牌色、方形头像、置顶底色', '衬线正文、紧凑间距'],
  },
]
const UA_PHONE = 'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36'
const desktop = { viewport: { width: 1200, height: 820 }, deviceScaleFactor: 2 }
const hubDesktop = { viewport: { width: 1100, height: 820 }, deviceScaleFactor: 2 }
const phone = { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, userAgent: UA_PHONE }

// Example themes from client/src/design/example-themes.css, by number.
const EXAMPLES = readFileSync(new URL('client/src/design/example-themes.css', ROOT), 'utf8')
function example(n) {
  const start = EXAMPLES.indexOf('/* ' + n + '. ')
  const next = EXAMPLES.slice(start + 4).search(/\/\* \d+[ab]?\. /)
  if (start < 0) throw new Error('No example ' + n + ' in example-themes.css')
  return next < 0 ? EXAMPLES.slice(start) : EXAMPLES.slice(start, start + 4 + next)
}
const THEMES = ['', example(1) + example(4) + example(7), example(2) + example(3)]

const browser = await chromium.launch()
const sessions = {}
const session = async (user) => (sessions[user] = sessions[user] || (await login(user)))

/** A page of the wiki in the language's interface, waited for until GlowThread has drawn. */
async function open(lang, { device = desktop, user = null, night = false, title = lang.page, query = {}, css = '' } = {}) {
  const ctx = await browser.newContext({ ...device, colorScheme: night ? 'dark' : 'light', reducedMotion: 'reduce', locale: lang.locale, timezoneId: 'UTC' })
  if (user) await ctx.addCookies((await session(user)).playwrightCookies(WIKI))
  if (night) {
    await ctx.addInitScript(() => {
      document.addEventListener('DOMContentLoaded', () => {
        document.documentElement.classList.remove('skin-theme-clientpref-day', 'skin-theme-clientpref-os')
        document.documentElement.classList.add('skin-theme-clientpref-night')
      })
    })
  }
  const page = await ctx.newPage()
  const errors = []
  page.on('pageerror', (e) => errors.push(e.message))
  const q = new URLSearchParams({ title, uselang: lang.code, ...query })
  if (night) q.set(device === phone ? 'minervanightmode' : 'vectornightmode', '1')
  await page.goto(WIKI + '/index.php?' + q)
  if (css) await page.addStyleTag({ content: css })
  return { ctx, page, errors }
}

async function section(page) {
  await page.waitForSelector('#glowthread', { state: 'attached' })
  await page.evaluate(() => document.getElementById('glowthread').scrollIntoView())
  await page.waitForSelector('#glowthread .ext-glowthread-heading')
  await page.waitForFunction(() => !document.querySelector('#glowthread .gt-pulse'))
  await page.evaluate(() => document.fonts.ready)
}

/** Scroll so that the element's top sits `offset` CSS pixels below the top of the window. */
async function scrollTo(page, locator, offset) {
  await locator.evaluate((el, o) => window.scrollTo(0, el.getBoundingClientRect().top + window.scrollY - o), offset)
  await page.waitForTimeout(400)
}

/**
 * Scroll so that about `context` CSS pixels of the article show above the comment section, with
 * the first visible block of the article starting right under Vector 2022's sticky header (about
 * 50 px), so no line is cut in half at the top.
 */
async function scrollBelowArticle(page, context) {
  await page.evaluate((context) => {
    const want = document.getElementById('glowthread').getBoundingClientRect().top + window.scrollY - context
    const tops = [...document.querySelectorAll('.mw-parser-output > *, .mw-parser-output li')]
      .map((el) => el.getBoundingClientRect().top + window.scrollY)
    const top = tops.reduce((a, b) => (Math.abs(b - want) < Math.abs(a - want) ? b : a))
    window.scrollTo(0, top - 58)
  }, context)
  await page.waitForTimeout(400)
}

/** The box of the elements, grown by `pad`, as a clip in page coordinates of the window. */
async function box(page, locators, { pad = 16, maxHeight = Infinity } = {}) {
  const boxes = []
  for (const l of locators) boxes.push(await l.boundingBox())
  const x = Math.max(0, Math.min(...boxes.map((b) => b.x)) - pad)
  const y = Math.max(0, Math.min(...boxes.map((b) => b.y)) - pad)
  const right = Math.max(...boxes.map((b) => b.x + b.width)) + pad
  const bottom = Math.max(...boxes.map((b) => b.y + b.height)) + pad
  return { x, y, width: right - x, height: Math.min(bottom - y, maxHeight) }
}

/**
 * A bottom edge for a clip: the lower edge of the comment (without its replies) that ends first
 * at or below `atLeast`, or last above `atMost`, so no line of text is cut in half.
 */
async function commentEdge(page, { atLeast = -Infinity, atMost = Infinity }) {
  // The lowest box inside each row (its buttons reach below the row), plus a little air.
  const edges = await page.locator('#glowthread .ext-glowthread-comment-row').evaluateAll((rows) => rows
    .filter((r) => r.getBoundingClientRect().height > 0)
    .map((r) => Math.max(...[r, ...r.querySelectorAll('*')].map((e) => e.getBoundingClientRect().bottom)) + 8))
  const fit = edges.filter((e) => e >= atLeast && e <= atMost)
  return atMost === Infinity ? Math.min(...fit) : Math.max(...fit)
}

async function save(lang, name, page, errors, options = {}) {
  // No hover effects from the last click.
  await page.mouse.move(0, 0)
  await page.waitForTimeout(200)
  const path = fileURLToPath(new URL(lang.out + name, ROOT))
  const buffer = await page.screenshot({ path, ...options })
  console.log(lang.out + name, errors.length ? 'ERRORS ' + errors.join('; ') : 'ok')
  return buffer
}

/** The top-level comment with the most replies on the language's page. */
async function longestThread(lang) {
  const list = await new Session().rest('/page/' + (await pageId(lang.page)) + '/comments?sort=hot')
  return list.comments.reduce((a, b) => (b.totalReplies > a.totalReplies ? b : a)).id
}

/** A ui message of the language, without its PLURAL. */
const messages = {}
function msg(lang, key, n = '') {
  messages[lang.code] = messages[lang.code] || JSON.parse(readFileSync(new URL('i18n/ui/' + lang.code + '.json', ROOT), 'utf8'))
  const text = messages[lang.code]['glowthread-ui-' + key]
  return text.replace(/\{\{PLURAL:\$1\|([^|}]*)(?:\|([^}]*))?\}\}/, (m, one, many) => (n === 1 ? one : many || one)).replace('$1', String(n))
}

for (const lang of LANGS) {
  mkdirSync(fileURLToPath(new URL(lang.out, ROOT)), { recursive: true })

  // The comment section under the article, as a logged-in reader meets it.
  {
    const { ctx, page, errors } = await open(lang, { user: 'Alice' })
    await section(page)
    await scrollBelowArticle(page, 150)
    await save(lang, 'comments.png', page, errors)
    await ctx.close()
  }

  // Night mode, with the longest conversation unfolded in place.
  {
    const { ctx, page, errors } = await open(lang, { user: 'Alice', night: true })
    await section(page)
    const thread = page.locator('#glowthread article[data-comment="' + (await longestThread(lang)) + '"]')
    await thread.locator('.ext-glowthread-replies-toggle').first().click()
    await thread.locator('.ext-glowthread-replies-toggle', { hasText: msg(lang, 'replies-hide') }).waitFor()
    // The first reply that appeared takes the focus (and scrolls into view): wait for it, then
    // drop it, so no focus ring shows.
    await page.waitForFunction((id) => {
      const el = document.activeElement
      return el && el.hasAttribute('data-comment') && el.getAttribute('data-comment') !== id && el.closest('[data-comment="' + id + '"]')
    }, await thread.getAttribute('data-comment'))
    await page.evaluate(() => document.activeElement.blur())
    await scrollTo(page, thread, 70)
    await save(lang, 'night.png', page, errors)
    await ctx.close()
  }

  // A phone, Minerva.
  {
    const { ctx, page, errors } = await open(lang, { device: phone, user: 'Alice' })
    await section(page)
    await scrollTo(page, page.locator('#glowthread'), 8)
    await save(lang, 'phone.png', page, errors)
    await ctx.close()
  }

  // Writing a comment: the @mention suggestions.
  {
    const { ctx, page, errors } = await open(lang, { user: 'Alice' })
    await section(page)
    await scrollTo(page, page.locator('#glowthread .ext-glowthread-header'), 90)
    const composer = page.locator('#glowthread .ext-glowthread-composer').first()
    await composer.locator('textarea').click()
    await page.keyboard.type(lang.typed, { delay: 20 })
    const mentions = page.locator('.ext-glowthread-mentions')
    await mentions.waitFor()
    await page.waitForTimeout(400)
    const clip = await box(page, [page.locator('#glowthread .ext-glowthread-header'), composer, mentions], { pad: 20 })
    clip.height = (await commentEdge(page, { atLeast: clip.y + clip.height - 12 })) - clip.y
    await save(lang, 'composer.png', page, errors, { clip })
    await ctx.close()
  }

  // A moderator in the comment section: the reported comments, with reasons and actions.
  {
    const { ctx, page, errors } = await open(lang, { user: 'Moderator' })
    await section(page)
    await page.locator('#glowthread .ext-glowthread-modtools button', { hasText: msg(lang, 'chip-reported') }).click()
    await page.waitForFunction(() => !document.querySelector('#glowthread .gt-pulse'))
    await page.locator('#glowthread .ext-glowthread-mod-notes').first().waitFor()
    await scrollTo(page, page.locator('#glowthread .ext-glowthread-header'), 70)
    const clip = await box(page, [page.locator('#glowthread .ext-glowthread-section')], { pad: 20 })
    clip.height = (await commentEdge(page, { atMost: clip.y + 740 })) - clip.y
    await save(lang, 'moderation.png', page, errors, { clip })
    await ctx.close()
  }

  // The moderation hub: reported comments on the page, the first one's author beside it.
  {
    const { ctx, page, errors } = await open(lang, { device: hubDesktop, user: 'Moderator', title: 'Special:GlowThread', query: { tab: 'reported', page: lang.page } })
    await page.waitForSelector('#ext-glowthread-admin .ext-glowthread-queue-item')
    await page.waitForFunction(() => !document.querySelector('#ext-glowthread-admin .gt-pulse'))
    await page.keyboard.press('j')
    await page.locator('#ext-glowthread-admin .ext-glowthread-record').waitFor()
    await page.waitForTimeout(800)
    await page.evaluate(() => window.scrollTo(0, 0))
    const clip = await box(page, [page.locator('#firstHeading'), page.locator('#ext-glowthread-admin')], { pad: 20, maxHeight: 720 })
    await save(lang, 'hub.png', page, errors, { clip })
    await ctx.close()
  }

  // Themes from site CSS: the same comments with three of the example themes, side by side.
  {
    const panels = []
    for (const css of THEMES) {
      const { ctx, page, errors } = await open(lang, { device: phone, user: 'Alice', css })
      await section(page)
      await scrollTo(page, page.locator('#glowthread'), 12)
      panels.push((await page.screenshot({ clip: { x: 0, y: 0, width: 390, height: 600 } })).toString('base64'))
      if (errors.length) console.log('theme panel ERRORS ' + errors.join('; '))
      await ctx.close()
    }
    const ctx = await browser.newContext({ viewport: { width: 1400, height: 800 }, deviceScaleFactor: 2 })
    const page = await ctx.newPage()
    // Each panel fades out at the bottom, so no comment ends cut in half.
    const cells = panels.map((p, i) => `<figure><div class="panel"><img src="data:image/png;base64,${p}"></div><figcaption>${lang.themes[i]}</figcaption></figure>`).join('')
    await page.setContent(`<!doctype html><meta charset="utf-8"><style>
      body { margin: 0 }
      #shot { display: inline-flex; gap: 24px; padding: 24px; background: #eceef1; font: 15px/1.4 system-ui, -apple-system, "PingFang SC", "Noto Sans CJK SC", sans-serif; color: #202122 }
      figure { margin: 0 }
      .panel { position: relative; overflow: hidden; border-radius: 12px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.18) }
      .panel::after { content: ""; position: absolute; left: 0; right: 0; bottom: 0; height: 96px; background: linear-gradient(rgba(255, 255, 255, 0), #fff) }
      img { display: block; width: 390px; height: 600px }
      figcaption { margin-top: 12px; text-align: center }
    </style><div id="shot">${cells}</div>`)
    await page.evaluate(() => Promise.all([...document.images].map((i) => i.decode())))
    await page.mouse.move(0, 0)
    const path = fileURLToPath(new URL(lang.out + 'themes.png', ROOT))
    await page.locator('#shot').screenshot({ path })
    console.log(lang.out + 'themes.png ok')
    await ctx.close()
  }
}
await browser.close()
