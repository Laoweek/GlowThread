import { test, expect, PAGES, toast } from './fixtures'
import { Session } from '../lib/wiki.mjs'

test.describe('logged-out reader', () => {
  test('sees count, tabs, comments and a login prompt instead of the composer', async ({ wiki, page }) => {
    await wiki.open(PAGES.showcase)
    const s = wiki.section
    await expect(s.locator('#gt-heading')).toContainText('Comments')
    await expect(s.getByRole('tab', { name: 'Top' })).toHaveAttribute('aria-selected', 'true')
    await expect(s.getByRole('textbox')).toHaveCount(0)
    const login = s.getByRole('link', { name: 'Log in' })
    await expect(login).toHaveAttribute('href', /Special:UserLogin.*returnto=/)
    await expect(s.locator('article').first()).toBeVisible()
    // Pinned first, with its badge.
    await expect(s.locator('li article').first()).toContainText('Pinned')
    // Liking asks to log in, and changes nothing.
    const likeButton = s.locator('article button[aria-pressed]').first()
    const before = await likeButton.textContent()
    await likeButton.click()
    await expect(toast(page)).toContainText('Log in to like comments')
    await expect(likeButton).toHaveText(before as string)
    // The ⋯ menu is always there and offers the permalink.
    await s.locator('article').first().getByRole('button', { name: 'More actions' }).click()
    await expect(page.getByRole('menuitem', { name: 'Copy link' })).toBeVisible()
    await expect(page.getByRole('menuitem', { name: 'Report' })).toHaveCount(0)
    await page.keyboard.press('Escape')
  })

  test('shows dates older than a week in the user language, and the exact time in UTC', async ({ wiki }) => {
    await wiki.open(PAGES.showcase)
    // The seed dates the showcase over months: "Sep 1" this year, "Sep 1, 2025" before.
    const times = await wiki.section.locator('article time').evaluateAll((els) => els.map((el) => ({
      iso: el.getAttribute('datetime')!,
      label: (el.firstChild!.textContent || '').trim(),
      exact: el.querySelector('.gt-sr-only')!.textContent!.trim(),
    })))
    const old = times.filter((t) => Date.now() - Date.parse(t.iso) > 8 * 86400_000)
    expect(old.length).toBeGreaterThan(3)
    const year = new Date().getUTCFullYear()
    for (const t of old) {
      const d = new Date(t.iso)
      const day = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', timeZone: 'UTC' })
      expect(t.label).toBe(d.getUTCFullYear() === year ? day : day + ', ' + d.getUTCFullYear())
      expect(t.exact).toBe('(' + t.iso.slice(0, 10) + ' ' + t.iso.slice(11, 16) + ')')
    }
    expect(old.some((t) => /, \d{4}$/.test(t.label)) && old.some((t) => !/, \d{4}$/.test(t.label))).toBe(true)
  })

  test('loads more with the cursor until the end', async ({ wiki, page }) => {
    await wiki.open(PAGES.load)
    const items = wiki.section.locator('ol > li')
    const first = await items.count()
    const more = wiki.section.getByRole('button', { name: 'Load more' })
    await more.click()
    await expect.poll(() => items.count()).toBeGreaterThan(first)
    // Every further page, until the button goes away at the end of the list.
    for (let i = 0; i < 30 && (await more.count()); i++) {
      const n = await items.count()
      await more.click()
      await expect.poll(() => items.count()).toBeGreaterThan(n)
    }
    await expect(more).toHaveCount(0)
    // Every top-level comment the API lists to a logged-out reader, page by page, is in the list now.
    const s = new Session()
    const pid = await wiki.pageId(PAGES.load)
    let total = 0
    for (let cursor = ''; ;) {
      const r = await s.rest('/page/' + pid + '/comments?sort=hot' + (cursor ? '&cursor=' + encodeURIComponent(cursor) : ''))
      total += r.comments.length
      if (!r.next) break
      cursor = r.next
    }
    expect(total).toBeGreaterThan(100)
    await expect(wiki.section.locator('ol > li > article')).toHaveCount(total)
    const requests = await page.evaluate(() => performance.getEntriesByType('resource').map((r) => r.name).filter((n) => n.indexOf('/glowthread/v1/page/') >= 0))
    // The first page is the canonical URL (exactly one parameter), the next ones have a cursor.
    expect(requests[0]).toMatch(/\/comments\?sort=hot$/)
    expect(requests[requests.length - 1]).toMatch(/\/comments\?sort=hot&cursor=/)
  })

  test('folds long comments after six lines', async ({ wiki }) => {
    // The longest comment of the showcase's first page, found through the API.
    const list = await (await wiki.api('Admin')).rest('/page/' + (await wiki.pageId(PAGES.showcase)) + '/comments?sort=hot')
    const plain = (c: any) => c.segments.map((s: any) => s.text || '').join('')
    const longest = [...list.comments].sort((a: any, b: any) => plain(b).length - plain(a).length)[0]
    expect(plain(longest).length).toBeGreaterThan(600)
    await wiki.open(PAGES.showcase)
    const long = wiki.section.locator('article[data-comment="' + longest.id + '"]')
    const expand = long.getByRole('button', { name: 'Show more' }).first()
    await expect(expand).toHaveAttribute('aria-expanded', 'false')
    const folded = (await long.locator('p[dir="auto"]').first().boundingBox())!.height
    await expand.click()
    await expect(long.getByRole('button', { name: 'Show less' }).first()).toHaveAttribute('aria-expanded', 'true')
    const lastWords = plain(longest).trim().split(/\s+/).slice(-4).join(' ')
    await expect(long.locator('p[dir="auto"]').first()).toContainText(lastWords)
    expect((await long.locator('p[dir="auto"]').first().boundingBox())!.height).toBeGreaterThan(folded)
  })

  test('Special:GlowThreadLink opens the comment on its page', async ({ wiki, page }) => {
    const list = await (await wiki.api('Admin')).rest('/page/' + (await wiki.pageId(PAGES.showcase)) + '/comments?sort=new')
    const id = list.comments.find((c: { status: string }) => c.status === 'visible').id
    await page.goto('/index.php?title=Special:GlowThreadLink/' + id + '&useskin=' + wiki.skin)
    await expect(page).toHaveURL(new RegExp('Sourdough_starter.*#gt-' + id + '$'))
    await wiki.ready()
    await expect(page.locator('#gt-' + id)).toBeInViewport()
    await expect(page.locator('#gt-' + id + ' > div').first()).toHaveClass(/gt-highlight/)
    // The menu copies that permalink.
    await page.locator('#gt-' + id).getByRole('button', { name: 'More actions' }).first().click()
    await expect(page.getByRole('menuitem', { name: 'Copy link' })).toBeVisible()
    await page.keyboard.press('Escape')
  })

  test('Special:FlowThreadLink, from links made before a move from FlowThread, still works', async ({ wiki, page }) => {
    const list = await (await wiki.api('Admin')).rest('/page/' + (await wiki.pageId(PAGES.showcase)) + '/comments?sort=new')
    const id = list.comments.find((c: { status: string }) => c.status === 'visible').id
    await page.goto('/index.php?title=Special:FlowThreadLink/' + id + '&useskin=' + wiki.skin)
    await expect(page).toHaveURL(new RegExp('Sourdough_starter.*#gt-' + id + '$'))
    await wiki.ready()
    await expect(page.locator('#gt-' + id)).toBeInViewport()
  })
})

test.describe('special authors and removed comments', () => {
  test('renders hidden users, IP authors and tombstones from the API shapes', async ({ wiki, page }) => {
    // Shapes from docs/api.md on top of the real response: a hidden author, an anonymous
    // comment, an erased and a deleted comment as a moderator sees them.
    await page.route('**/rest.php/glowthread/v1/page/*/comments?sort=*', async (route) => {
      const json = await (await route.fetch()).json()
      const [a, b, c, d] = json.comments
      a.author = { name: null, id: 0, anon: false, temp: false, hidden: true }
      b.author = { name: '203.0.113.7', id: 0, anon: true, temp: false, hidden: false }
      c.status = 'erased'
      c.segments = []
      c.author = { name: null, id: 0, anon: false, temp: false, hidden: true }
      d.status = 'deleted'
      await route.fulfill({ json })
    })
    await wiki.open(PAGES.showcase)
    const items = wiki.section.locator('ol > li > article:not([data-pinned])')
    await expect(items.nth(0).locator('h3').first()).toHaveText('Hidden user')
    await expect(items.nth(0).locator('h3').first().locator('a')).toHaveCount(0)
    await expect(items.nth(1).locator('h3').first()).toHaveText('203.0.113.7')
    await expect(items.nth(1).locator('h3').first().locator('a')).toHaveCount(0)
    await expect(items.nth(2)).toContainText('This comment was erased.')
    await expect(items.nth(2)).toContainText('Erased')
    await expect(items.nth(3)).toContainText('Hidden')
    // No like or reply row on the hidden comment itself (its replies keep theirs).
    await expect(items.nth(3).locator('> div').first().locator('footer')).toHaveCount(0)
  })
})
