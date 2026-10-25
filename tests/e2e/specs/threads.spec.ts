import { test, expect, PAGES } from './fixtures'
import type { Page, Locator } from '@playwright/test'
import type { Wiki } from './fixtures'
import { Session } from '../lib/wiki.mjs'

// Conversations shown in place: replies unfold and page in under their comment ("Show N more
// replies", 50 at a time from /comment/{id}/replies) and fold again ("Hide replies").
// Permalinks and the popular strip reveal a comment in the list when its thread is there,
// otherwise in a closable "Conversation" box above the list.

const SHOW = /^Show \d+ (more )?repl(y|ies)$/

/** A thread's own "Show N (more) replies" link, not those of its replies (they have their own). */
const ownToggle = (page: Page, id: number) => page.locator('#gt-r-list-' + id + ' > div > button:not([aria-expanded="true"])')
const thread = (wiki: Wiki, id: number) => wiki.section.locator('ol > li > article[data-comment="' + id + '"]')
const replies = (t: Locator) => t.locator('article[data-comment]')
const focused = (page: Page) => page.evaluate(() => (document.activeElement && document.activeElement.getAttribute('data-comment')) || (document.activeElement && document.activeElement.id) || '')

/** The showcase conversation with 11 replies, as a logged-out reader gets it. */
async function elevenReplies(wiki: Wiki) {
  const list = await new Session().rest('/page/' + (await wiki.pageId(PAGES.showcase)) + '/comments?sort=hot')
  const t = list.comments.find((c: { totalReplies: number }) => c.totalReplies === 11)
  expect(t, 'the showcase has its 11-reply conversation').toBeTruthy()
  // Depth-1 replies come with their own replies: walk both levels, in the order they show.
  const all = await new Session().rest('/comment/' + t.id + '/replies?limit=50')
  const ids = (cs: any[]): number[] => cs.flatMap((c) => [c.id, ...ids(c.replies || [])])
  const previews = new Set(ids(t.replies))
  return { id: t.id as number, previews, hidden: ids(all.replies).filter((id) => !previews.has(id)) }
}

/** A page of comments past the first, from the end of a busy page's list. */
async function notInList(wiki: Wiki, title: string) {
  const s = new Session()
  const pid = await wiki.pageId(title)
  let r = await s.rest('/page/' + pid + '/comments?sort=hot')
  for (let i = 0; i < 2 && r.next; i++) r = await s.rest('/page/' + pid + '/comments?sort=hot&cursor=' + encodeURIComponent(r.next))
  return r.comments[r.comments.length - 1].id as number
}

test.describe('replies in place', () => {
  test('unfolds all 11 replies of a conversation and folds them again, with the keyboard', async ({ wiki, page }) => {
    const t = await elevenReplies(wiki)
    await wiki.open(PAGES.showcase)
    const article = thread(wiki, t.id)
    const toggle = ownToggle(page, t.id)
    if (wiki.phone) {
      // Phones fold every conversation behind its reply count.
      await expect(replies(article)).toHaveCount(0)
      await expect(toggle).toHaveText('Show 11 replies')
      await expect(toggle).toHaveAttribute('aria-expanded', 'false')
      await expect(toggle).toHaveAttribute('aria-controls', 'gt-r-list-' + t.id)
    } else {
      await expect(replies(article)).toHaveCount(t.previews.size)
      await expect(toggle).toHaveText('Show ' + t.hidden.length + ' more replies')
    }
    await toggle.focus()
    await page.keyboard.press('Enter')
    await expect(replies(article)).toHaveCount(11)
    // Focus continues from the first reply that appeared.
    await expect.poll(() => focused(page)).toBe(String(t.hidden[0]))
    const hide = article.getByRole('button', { name: 'Hide replies' })
    await expect(hide).toHaveAttribute('aria-expanded', 'true')
    await expect(article.getByRole('button', { name: SHOW })).toHaveCount(0)
    await hide.focus()
    await page.keyboard.press('Enter')
    await expect(replies(article)).toHaveCount(0)
    // The link takes the place of the replies, and keeps the focus.
    await expect(toggle).toHaveText('Show 11 replies')
    await expect(toggle).toHaveAttribute('aria-expanded', 'false')
    await expect(toggle).toBeFocused()
    // Unfolding again needs no request: everything is loaded.
    await toggle.click()
    await expect(replies(article)).toHaveCount(11)
  })

  test('says that replies are loading, and offers Try again when they fail', async ({ wiki, page }) => {
    const t = await elevenReplies(wiki)
    let release = () => {}
    let fail = true
    const held = new Promise<void>((done) => { release = done })
    await page.route('**/rest.php/glowthread/v1/comment/' + t.id + '/replies?*', async (route) => {
      if (fail) return route.abort()
      await held
      await route.continue()
    })
    await wiki.open(PAGES.showcase)
    const article = thread(wiki, t.id)
    await ownToggle(page, t.id).click()
    const alert = article.getByRole('alert')
    await expect(alert).toContainText('Could not reach the wiki.')
    wiki.errors.length = 0 // the aborted request logs a network error on purpose
    fail = false
    await alert.getByRole('button', { name: 'Try again' }).click()
    await expect(page.locator('#gt-r-list-' + t.id)).toHaveAttribute('aria-busy', 'true')
    await expect(ownToggle(page, t.id)).toHaveText('Loading…')
    release()
    await expect(replies(article)).toHaveCount(11)
    await expect(page.locator('#gt-r-list-' + t.id)).not.toHaveAttribute('aria-busy', 'true')
    await expect(article.getByRole('alert')).toHaveCount(0)
  })

  test('pages a long conversation 50 replies at a time, and a reply\'s own replies under its own link', async ({ wiki, page }) => {
    test.skip(wiki.phone, 'one layout is enough for paging')
    test.setTimeout(90_000)
    // A conversation of its own on the sandbox: 55 replies, the first with 5 of its own.
    const admin = await wiki.api('Admin')
    const pageid = await wiki.pageId(PAGES.play)
    const tag = Date.now().toString(36)
    const post = async (text: string, replyto?: number) => {
      const r = await admin.write({ action: 'glowthreadpost', pageid, text: text + ' ' + tag, ...(replyto ? { replyto } : {}) })
      if (!r.glowthreadpost) throw new Error(JSON.stringify(r))
      return r.glowthreadpost.id as number
    }
    const root = await post('A long conversation')
    const direct: number[] = []
    for (let i = 1; i <= 55; i++) direct.push(await post('Reply ' + i, root))
    const nested: number[] = []
    for (let i = 1; i <= 5; i++) nested.push(await post('Answer ' + i, direct[0]))
    const calls: string[] = []
    page.on('request', (r) => { if (/\/comment\/\d+\/replies/.test(r.url())) calls.push(decodeURIComponent(r.url())) })
    await wiki.open(PAGES.play)
    await wiki.section.getByRole('tab', { name: 'Newest' }).click()
    const article = thread(wiki, root)
    const toggle = ownToggle(page, root)
    const own = ownToggle(page, direct[0])
    // Three replies, the first with three of its five: each list has its own link.
    await expect(replies(article)).toHaveCount(6)
    await expect(toggle).toHaveText('Show 52 more replies')
    await expect(own).toHaveText('Show 2 more replies')
    // The first page holds replies 1 to 50 (the three shown are not repeated).
    await toggle.click()
    await expect(replies(article)).toHaveCount(53)
    await expect.poll(() => focused(page)).toBe(String(direct[3]))
    await expect(toggle).toHaveText('Show 5 more replies')
    await toggle.click()
    await expect(replies(article)).toHaveCount(58)
    await expect(article.locator('article[data-comment="' + direct[54] + '"]')).toBeVisible()
    await own.click()
    await expect(replies(article)).toHaveCount(60)
    await expect(article.getByRole('button', { name: SHOW })).toHaveCount(0)
    expect(calls).toEqual([
      expect.stringMatching(new RegExp('/comment/' + root + '/replies\\?limit=50$')),
      expect.stringMatching(new RegExp('/comment/' + root + '/replies\\?limit=50&cursor=.+')),
      expect.stringMatching(new RegExp('/comment/' + direct[0] + '/replies\\?limit=50$')),
    ])
    // A permalink to the last reply, which no first page lists: its conversation unfolds in place.
    await wiki.open(PAGES.play, { hash: '#gt-' + direct[54], scroll: false })
    const target = wiki.section.locator('ol #gt-' + direct[54])
    await expect(target).toBeInViewport()
    await expect(target.locator('> div').first()).toHaveClass(/gt-highlight/)
    await expect.poll(() => focused(page)).toBe(String(direct[54]))
    await expect(wiki.section.locator('[data-linked]')).toHaveCount(0)
  })
})

test.describe('permalinks and the popular strip', () => {
  test('a permalink to a reply not loaded yet unfolds its conversation in the list', async ({ wiki, page }) => {
    const t = await elevenReplies(wiki)
    const id = t.hidden[t.hidden.length - 1]
    await wiki.open(PAGES.showcase, { hash: '#gt-' + id, scroll: false })
    const target = thread(wiki, t.id).locator('#gt-' + id)
    await expect(target).toBeInViewport()
    await expect(target.locator('> div').first()).toHaveClass(/gt-highlight/)
    await expect.poll(() => focused(page)).toBe(String(id))
    await expect(wiki.section.locator('[data-linked]')).toHaveCount(0)
    await expect(thread(wiki, t.id).getByRole('button', { name: 'Hide replies' })).toBeVisible()
  })

  test('a permalink to a comment not in the list shows its conversation above the list, until closed', async ({ wiki, page }) => {
    const id = await notInList(wiki, PAGES.kitchen)
    await wiki.open(PAGES.kitchen, { hash: '#gt-' + id, scroll: false })
    const box = wiki.section.locator('[data-linked]')
    await expect(box.getByRole('heading', { name: 'Conversation' })).toBeVisible()
    const target = box.locator('article[data-comment="' + id + '"]')
    await expect(target).toBeInViewport()
    await expect(target.locator('> div').first()).toHaveClass(/gt-highlight/)
    await expect.poll(() => focused(page)).toBe(String(id))
    await expect(wiki.section.locator('ol article[data-comment="' + id + '"]')).toHaveCount(0)
    // Above the list.
    const boxTop = (await box.boundingBox())!.y
    expect((await wiki.section.locator('ol > li').first().boundingBox())!.y).toBeGreaterThan(boxTop)
    await box.getByRole('button', { name: 'Close' }).click()
    await expect(box).toHaveCount(0)
    await expect(wiki.section.locator('#gt-heading')).toBeFocused()
  })

  test('the popular strip reveals each comment in place: in the list, or above it', async ({ wiki, page }) => {
    // The load-test page in newest order: some of its most liked comments are on the first
    // page, others are older.
    const s = new Session()
    const pid = await wiki.pageId(PAGES.load)
    const newest = await s.rest('/page/' + pid + '/comments?sort=new')
    const shown = new Set<number>()
    const walk = (cs: any[]) => cs.forEach((c) => { shown.add(c.id); walk(c.replies || []) })
    walk(newest.comments)
    expect(newest.popular.length).toBeGreaterThan(0)
    await wiki.open(PAGES.load)
    await wiki.section.getByRole('tab', { name: 'Newest' }).click()
    const strip = wiki.section.locator('section[aria-labelledby="gt-popular-heading"]')
    await expect(strip.locator('#gt-popular-heading')).toHaveText('Top comments')
    const buttons = strip.getByRole('button')
    await expect(buttons).toHaveCount(newest.popular.length)
    for (const [i, pick] of (newest.popular as Array<{ id: number, author: { name: string } }>).entries()) {
      const button = buttons.nth(i)
      await expect(button).toHaveAccessibleName(new RegExp('^' + pick.author.name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ': .+\\. Go to this comment$'))
      await button.click()
      const box = wiki.section.locator('[data-linked]')
      const where = shown.has(pick.id) ? wiki.section.locator('ol') : box
      const target = where.locator('article[data-comment="' + pick.id + '"]')
      await expect(target).toBeInViewport()
      await expect(target.locator('> div').first()).toHaveClass(/gt-highlight/)
      await expect.poll(() => focused(page)).toBe(String(pick.id))
      if (shown.has(pick.id)) {
        await expect(box).toHaveCount(0)
      } else {
        await expect(box.getByRole('heading', { name: 'Conversation' })).toBeVisible()
        await box.getByRole('button', { name: 'Close' }).click()
        await expect(box).toHaveCount(0)
      }
    }
  })
})
