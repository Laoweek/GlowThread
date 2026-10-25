import { test, expect, PAGES, postComment } from './fixtures'
import type { Page } from '@playwright/test'
import { openHub, hub, feedItems } from './admin-helpers'
import { Session } from '../lib/wiki.mjs'

// Internationalisation: the interface in another user language (uselang), right-to-left
// layouts, comments in other scripts and directions, per-language CJK fonts, and the old
// #flowthread address. The only spec with non-English text, each on purpose.

/** Boxes of the section's parts, relative to the section's own box. */
function rowGeometry(page: Page) {
  return page.evaluate(() => {
    const root = document.querySelector('#glowthread .gt-root')!.getBoundingClientRect()
    const rel = (r: DOMRect) => ({ left: r.left - root.left, right: root.right - r.right, width: r.width, height: r.height })
    const article = document.querySelector('#glowthread ol > li > article')!
    const row = article.firstElementChild!
    const avatar = row.querySelector('.gt-avatar')!.getBoundingClientRect()
    const name = article.querySelector('h3')!.getBoundingClientRect()
    const menu = article.querySelector('button[aria-haspopup="menu"]')!.getBoundingClientRect()
    return {
      dir: getComputedStyle(document.querySelector('#glowthread .gt-root')!).direction,
      htmlDir: document.documentElement.dir,
      rootWidth: root.width,
      avatar: rel(avatar),
      name: rel(name),
      menu: rel(menu),
      overflow: { page: document.documentElement.scrollWidth - window.innerWidth, section: (document.querySelector('#glowthread .gt-root') as HTMLElement).scrollWidth - (document.querySelector('#glowthread .gt-root') as HTMLElement).clientWidth },
    }
  })
}

/** Single-line comment bodies by comment id, relative to the section's box. */
function bodyBoxes(page: Page) {
  return page.evaluate(() => {
    const root = document.querySelector('#glowthread .gt-root')!.getBoundingClientRect()
    const out: Record<string, { left: number, right: number }> = {}
    document.querySelectorAll('#glowthread article[data-comment]').forEach((a) => {
      const p = a.querySelector(':scope > div p[dir="auto"]')
      if (!p) return
      const r = p.getBoundingClientRect()
      const line = parseFloat(getComputedStyle(p).lineHeight)
      if (r.height > line * 1.5) return // more than one line: the wrap differs with the width
      out[a.getAttribute('data-comment')!] = { left: r.left - root.left, right: root.right - r.right }
    })
    return out
  })
}

test.describe('user language', () => {
  test('?uselang=zh-hans shows the interface in Simplified Chinese', async ({ wiki }) => {
    // Messages from i18n/ui/zh-hans.json: Comments, Top, Newest, Log in, More actions.
    await wiki.open(PAGES.showcase, { query: { uselang: 'zh-hans' } })
    const s = wiki.section
    await expect(s.locator('#gt-heading')).toContainText('评论')
    await expect(s.getByRole('tab', { name: '热门' })).toHaveAttribute('aria-selected', 'true')
    await expect(s.getByRole('tab', { name: '最新' })).toBeVisible()
    await expect(s.getByRole('link', { name: '登录' })).toBeVisible()
    await expect(s.locator('article').first().getByRole('button', { name: '更多操作' })).toBeVisible()
    // "Show N more replies" (desktop) or "Show N replies" (phones, where replies fold).
    await expect(s.getByRole('button', { name: wiki.phone ? /^展开 \d+ 条回复$/ : /^再显示 \d+ 条回复$/ }).first()).toBeVisible()
  })

  test('?uselang=zh-hans names the conversation box in Simplified Chinese', async ({ wiki }) => {
    // The box a permalink opens above the list: "Conversation" and "Close".
    const s = new Session()
    const pid = await wiki.pageId(PAGES.kitchen)
    let r = await s.rest('/page/' + pid + '/comments?sort=hot')
    r = await s.rest('/page/' + pid + '/comments?sort=hot&cursor=' + encodeURIComponent(r.next))
    await wiki.open(PAGES.kitchen, { hash: '#gt-' + r.comments[0].id, scroll: false, query: { uselang: 'zh-hans' } })
    const box = wiki.section.locator('[data-linked]')
    await expect(box.getByRole('heading', { name: '对话' })).toBeVisible()
    await expect(box.getByRole('button', { name: '关闭' })).toBeVisible()
  })

  test('?uselang=zh-hans shows the moderator\'s notes in Simplified Chinese', async ({ wiki }) => {
    // "Page: Open" and "Held for review: new account" (Newbie's seeded comment).
    await wiki.as('Moderator')
    await wiki.open(PAGES.showcase, { query: { uselang: 'zh-hans' } })
    await expect(wiki.section.getByRole('button', { name: /^页面：/ })).toContainText('页面：开放')
    await wiki.section.getByRole('button', { name: /^待审核/ }).click()
    await expect(wiki.section.locator('article').first()).toContainText('待审核原因：新账号')
  })

  test('?uselang=zh-hans shows the moderation hub in Simplified Chinese', async ({ wiki, page }) => {
    // Tabs "Reported" and "Held", the title "Comment moderation", and "from A、B" under reports.
    await wiki.as('Moderator')
    await openHub(wiki, 'queue', { uselang: 'zh-hans' })
    await expect(page.getByRole('tab', { name: /^被举报/ })).toHaveAttribute('aria-selected', 'true')
    await expect(page.getByRole('tab', { name: /^待审核/ })).toBeVisible()
    await expect(page).toHaveTitle(/^被举报 · 评论管理/)
    await expect(feedItems(page).filter({ hasText: /来自 \S+、\S+/ }).first()).toBeVisible()
  })

  for (const lang of ['he', 'ar']) {
    test(`?uselang=${lang} mirrors the comment section`, async ({ wiki, page }) => {
      // Short English comments of its own, so every body is one line on a phone too.
      const ids: number[] = []
      for (const text of ['Short one', 'A second short comment', 'Third', 'Fourth, a bit longer']) ids.push(await postComment(wiki, 'Admin', PAGES.play, text + ' ' + Date.now().toString(36)))
      const open = async (query: Record<string, string>) => {
        await wiki.open(PAGES.play, { query })
        await wiki.section.getByRole('tab').nth(1).click()
        await expect(wiki.section.locator('article[data-comment="' + ids[3] + '"]')).toBeVisible()
      }
      await open({})
      const ltr = await rowGeometry(page)
      const ltrBodies = await bodyBoxes(page)
      await open({ uselang: lang })
      const rtl = await rowGeometry(page)
      expect(ltr.dir).toBe('ltr')
      expect(rtl.htmlDir).toBe('rtl')
      expect(rtl.dir).toBe('rtl')
      // The avatar starts the row on the right, the menu ends it on the left.
      expect(Math.abs(rtl.avatar.right - ltr.avatar.left)).toBeLessThanOrEqual(1)
      expect(Math.abs(rtl.menu.left - ltr.menu.right)).toBeLessThanOrEqual(1)
      expect(Math.abs(rtl.name.right - ltr.name.left)).toBeLessThanOrEqual(1)
      expect(rtl.overflow.page).toBeLessThanOrEqual(0)
      expect(rtl.overflow.section).toBeLessThanOrEqual(0)
      // The English comments keep their own direction (dir="auto") and sit next to the
      // avatar: each one-line body is the mirror image of its place in the English layout.
      expect(Math.abs(rtl.rootWidth - ltr.rootWidth)).toBeLessThanOrEqual(1)
      const rtlBodies = await bodyBoxes(page)
      for (const id of ids) {
        expect(ltrBodies[id], 'comment ' + id + ' fits one line').toBeTruthy()
        expect(Math.abs(rtlBodies[id].right - ltrBodies[id].left), 'comment ' + id).toBeLessThanOrEqual(1)
        await expect(wiki.section.locator('article[data-comment="' + id + '"] p[dir="auto"]').first()).toHaveCSS('direction', 'ltr')
      }
    })

    test(`?uselang=${lang} mirrors the moderation hub`, async ({ wiki, page }) => {
      await wiki.as('Moderator')
      await openHub(wiki, 'queue', { tab: 'all', uselang: lang })
      const facts = await page.evaluate(() => {
        const root = document.querySelector('#ext-glowthread-admin .gt-root')!
        const box = (el: Element) => el.getBoundingClientRect()
        const checkbox = '[role="checkbox"], input[type="checkbox"]'
        // The first item with a selection box and text (an erased comment has no text).
        const item = Array.from(document.querySelectorAll('#ext-glowthread-admin [role="feed"] > article')).find((a) => a.querySelector(checkbox) && a.querySelector('p[dir="auto"]'))!
        const check = box(item.querySelector(checkbox)!)
        const text = box(item.querySelector('p[dir="auto"]')!)
        const itemBox = box(item)
        return {
          dir: getComputedStyle(root).direction,
          // The selection box starts the item, on the right.
          checkRight: itemBox.right - check.right,
          checkLeft: check.left - itemBox.left,
          checkBeforeText: check.left > text.right - 1,
          overflow: document.documentElement.scrollWidth - window.innerWidth,
          rootOverflow: (root as HTMLElement).scrollWidth - (root as HTMLElement).clientWidth,
        }
      })
      expect(facts.dir).toBe('rtl')
      expect(facts.checkRight).toBeLessThan(facts.checkLeft)
      expect(facts.checkBeforeText).toBe(true)
      expect(facts.overflow).toBeLessThanOrEqual(0)
      expect(facts.rootOverflow).toBeLessThanOrEqual(0)
      await expect(hub(page).getByRole('tab').first()).toBeVisible()
    })
  }

  test('?uselang=he mirrors an unfolded conversation', async ({ wiki, page }) => {
    // The showcase's 11-reply conversation: the rule and indent of the replies move to the right.
    const list = await new Session().rest('/page/' + (await wiki.pageId(PAGES.showcase)) + '/comments?sort=hot')
    const id = list.comments.find((c: { totalReplies: number }) => c.totalReplies === 11).id
    const measure = async (query: Record<string, string>) => {
      await wiki.open(PAGES.showcase, { query })
      const thread = wiki.section.locator('ol > li > article[data-comment="' + id + '"]')
      await thread.getByRole('button', { name: /^Show \d+ (more )?replies$/ }).click()
      await expect(thread.locator('article[data-comment]')).toHaveCount(11)
      return page.evaluate((tid) => {
        const root = document.querySelector('#glowthread .gt-root')!.getBoundingClientRect()
        const box = document.getElementById('gt-r-list-' + tid)!
        const b = box.getBoundingClientRect()
        const cs = getComputedStyle(box)
        const avatar = box.querySelector('article[data-comment] .gt-avatar')!.getBoundingClientRect()
        return {
          dir: getComputedStyle(box).direction,
          left: b.left - root.left,
          right: root.right - b.right,
          ruleLeft: parseFloat(cs.borderLeftWidth),
          ruleRight: parseFloat(cs.borderRightWidth),
          avatarLeft: avatar.left - root.left,
          avatarRight: root.right - avatar.right,
          overflow: document.documentElement.scrollWidth - window.innerWidth,
        }
      }, id)
    }
    const ltr = await measure({})
    const rtl = await measure({ uselang: 'he' })
    expect(ltr.dir).toBe('ltr')
    expect(rtl.dir).toBe('rtl')
    expect(ltr.ruleLeft).toBeGreaterThan(0)
    expect(ltr.ruleRight).toBe(0)
    expect(rtl.ruleRight).toBe(ltr.ruleLeft)
    expect(rtl.ruleLeft).toBe(0)
    expect(Math.abs(rtl.right - ltr.left)).toBeLessThanOrEqual(1)
    expect(Math.abs(rtl.avatarRight - ltr.avatarLeft)).toBeLessThanOrEqual(1)
    expect(rtl.overflow).toBeLessThanOrEqual(0)
  })

  test('CJK fallback fonts follow the user language', async ({ wiki, page }) => {
    // The computed internal value (sites set the public --glowthread-font-cjk, GlowThread only
    // reads it), not the rendered face, so this holds on machines without these fonts.
    const cjk = () => page.evaluate(() => getComputedStyle(document.querySelector('#glowthread .gt-root')!).getPropertyValue('--gt-v-font-cjk').trim())
    const expected: Record<string, RegExp> = {
      'en': /^"PingFang SC"/,
      'ja': /^"Hiragino Sans"/, // Japanese glyph shapes for Han characters
      'ko': /^"Apple SD Gothic Neo"/,
      'zh-hant': /^"PingFang TC"/,
      'zh-hk': /^"PingFang HK"/,
    }
    for (const [lang, face] of Object.entries(expected)) {
      await wiki.open(PAGES.i18n, { query: { uselang: lang } })
      expect(await cjk(), lang).toMatch(face)
      // The stack the section uses includes that language's faces.
      const family = await page.evaluate(() => getComputedStyle(document.querySelector('#glowthread .gt-root')!).fontFamily)
      expect(family, lang).toContain(face.source.slice(1).replace(/\\/g, ''))
    }
  })
})

test.describe('comments in other scripts', () => {
  test('each comment on Sandbox/i18n takes its own direction and keeps every character', async ({ wiki, page }) => {
    const list = await new Session().rest('/page/' + (await wiki.pageId(PAGES.i18n)) + '/comments?sort=hot')
    const all = list.comments.flatMap((c: any) => [c, ...c.replies])
    expect(all.length).toBeGreaterThan(10)
    await wiki.open(PAGES.i18n)
    const collect = (scope: string) => page.evaluate((sel) => Object.fromEntries(Array.from(document.querySelectorAll(sel + ' article[data-comment]')).map((a) => {
      const p = a.querySelector(':scope > div p[dir="auto"]') as HTMLElement
      return [a.getAttribute('data-comment'), { rtl: p.matches(':dir(rtl)'), direction: getComputedStyle(p).direction, text: p.textContent || '' }]
    })), scope)
    const rendered: Record<string, { rtl: boolean, direction: string, text: string }> = await collect('#glowthread')
    // Phones fold replies behind "Show N replies": unfold each conversation in place.
    for (const c of list.comments) {
      if (c.replies.every((r: { id: number }) => rendered[r.id])) continue
      const thread = wiki.section.locator('ol > li > article[data-comment="' + c.id + '"]')
      await thread.getByRole('button', { name: /^Show \d+ repl(y|ies)$/ }).click()
      await expect(thread.locator('article[data-comment="' + c.replies[0].id + '"]')).toBeVisible()
    }
    Object.assign(rendered, await collect('#glowthread'))
    const squash = (t: string) => t.replace(/\s+/g, '')
    for (const c of all) {
      const r = rendered[c.id]
      expect(r, 'comment ' + c.id + ' is shown').toBeTruthy()
      const plain = c.segments.map((s: any) => s.text || '').join('')
      // dir="auto": the first strong character decides (Hebrew or Arabic: right to left).
      const first = (plain.match(/\p{L}/u) || [''])[0]
      const rtl = /[\p{Script=Hebrew}\p{Script=Arabic}]/u.test(first)
      expect(r.rtl, 'comment ' + c.id + ' direction').toBe(rtl)
      expect(r.direction).toBe(rtl ? 'rtl' : 'ltr')
      // No replacement characters, and every text run arrives whole (links show without
      // their scheme, so only the plain text segments are compared).
      expect(r.text).not.toContain('�')
      for (const s of c.segments.filter((s: any) => s.type === 'text')) expect(squash(r.text), 'comment ' + c.id).toContain(squash(s.text))
    }
    // The long unbroken string wraps inside the section.
    const overflow = await page.evaluate(() => {
      const s = document.querySelector('#glowthread .gt-root') as HTMLElement
      return { page: document.documentElement.scrollWidth - window.innerWidth, section: s.scrollWidth - s.clientWidth }
    })
    expect(overflow.page).toBeLessThanOrEqual(0)
    expect(overflow.section).toBeLessThanOrEqual(0)
  })
})

test.describe('addresses that point at the section', () => {
  test.beforeEach(async ({ context }) => {
    // A long article, so the section starts far below the first screen.
    await context.addInitScript(() => {
      document.addEventListener('DOMContentLoaded', () => {
        const text = document.getElementById('mw-content-text')
        if (!text) return
        const spacer = document.createElement('div')
        spacer.style.height = '4000px'
        text.appendChild(spacer)
      })
    })
  })

  for (const hash of ['#glowthread', '#flowthread']) {
    test(`${hash} in the address opens and scrolls to the comments`, async ({ wiki, page }) => {
      // #flowthread comes from links made before a wiki moved from FlowThread.
      await wiki.open(PAGES.showcase, { hash, scroll: false })
      await expect(wiki.section.locator('#gt-heading')).toBeInViewport()
      await expect(wiki.section.locator('article').first()).toBeVisible()
    })
  }

  test('a hash change to #glowthread before the app mounts scrolls to the section and loads it', async ({ wiki, page }) => {
    await wiki.open(PAGES.showcase, { scroll: false, wait: false })
    // The loader has run (the skeleton is there), the app has not.
    await expect(page.locator('#glowthread[aria-busy="true"]')).toHaveCount(1)
    await expect(wiki.section.locator('.gt-root')).toHaveCount(0)
    expect(await page.evaluate(() => window.scrollY)).toBe(0)
    await page.evaluate(() => { location.hash = '#glowthread' })
    await expect(wiki.section).toBeInViewport()
    await wiki.ready()
    await expect(wiki.section.locator('#gt-heading')).toBeVisible()
  })
})
