import { test, expect, PAGES, postComment, toast, unique } from './fixtures'
import type { Page } from '@playwright/test'
import { createAccount } from '../lib/wiki.mjs'

/** Sums layout shifts from the start of the page (installed before it loads). */
async function recordShifts(page: Page) {
  await page.context().addInitScript(() => {
    const w = window as any
    w.__gtShifts = 0
    new PerformanceObserver((list) => {
      for (const e of list.getEntries() as any[]) if (!e.hadRecentInput) w.__gtShifts += e.value
    }).observe({ type: 'layout-shift', buffered: true })
  })
}

/** Waits until the page's downloads are done and two frames have been drawn. */
async function settled(page: Page) {
  await page.waitForLoadState('networkidle')
  await page.evaluate(() => new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done))))
}

test.describe('viewer and page states', () => {
  test('a new account sees its held comment, others do not', async ({ wiki, page, browser }) => {
    // The gate lets an account through once it has an approved comment: undo that first.
    const mod = await wiki.api('Moderator')
    const record = await mod.rest('/moderation/user/Newbie')
    const visible = record.comments.filter((i: any) => i.comment.status === 'visible').map((i: any) => i.comment.id)
    if (visible.length) await mod.write({ action: 'glowthreadmoderate', ids: visible.join('|'), do: 'delete' })
    // An account may have only a few held comments at a time (toomanyheld): delete the ones
    // earlier runs left on this page (never approve them, that would open the gate).
    const playId = await wiki.pageId(PAGES.play)
    const held = await mod.rest('/moderation/queue?tab=held&user=Newbie&pageid=' + playId + '&limit=50')
    const heldIds = held.items.map((i: any) => i.comment.id)
    if (heldIds.length) await mod.write({ action: 'glowthreadmoderate', ids: heldIds.join('|'), do: 'delete' })
    await wiki.as('Newbie')
    await wiki.open(PAGES.play)
    const text = unique('My first comment here')
    await wiki.section.getByRole('textbox').fill(text)
    await wiki.section.getByRole('button', { name: 'Send' }).click()
    const mine = wiki.section.locator('article', { hasText: text })
    await expect(mine).toContainText('Only you can see this · awaiting review')
    await expect(mine).toContainText('A moderator will review this comment.')
    await expect(toast(page)).toContainText('Others will see it once a moderator approves it')
    // A logged-out reader does not see it.
    const use = test.info().project.use
    const anonContext = await browser.newContext({ baseURL: use.baseURL, viewport: use.viewport, userAgent: use.userAgent, isMobile: use.isMobile, hasTouch: use.hasTouch, locale: use.locale, timezoneId: use.timezoneId })
    const anon = await anonContext.newPage()
    await anon.goto('/index.php?title=' + encodeURIComponent(PAGES.play) + '&useskin=' + wiki.skin)
    await anon.waitForSelector('#glowthread', { state: 'attached' })
    await anon.evaluate(() => document.getElementById('glowthread')!.scrollIntoView())
    await anon.waitForSelector('#glowthread .gt-root #gt-heading')
    await anon.waitForFunction(() => !document.querySelector('#glowthread .gt-pulse'))
    await expect(anon.locator('#glowthread article', { hasText: text })).toHaveCount(0)
    await anonContext.close()
  })

  test('an account inside the new-account gate is told when it can like, and the API refuses its like', async ({ wiki, page, context }) => {
    const id = await postComment(wiki, 'Admin', PAGES.play, unique('Like me later'))
    // Registered a moment ago, nothing approved yet: inside the gate.
    const fresh = await createAccount('Newcomer')
    await context.setExtraHTTPHeaders({ 'X-Forwarded-For': fresh.session.ip })
    await context.addCookies(fresh.session.playwrightCookies(test.info().project.use.baseURL))
    await wiki.open(PAGES.play)
    await wiki.section.getByRole('tab', { name: 'Newest' }).click()
    const like = wiki.section.locator('article[data-comment="' + id + '"] footer button[aria-pressed]').first()
    await expect(like).toHaveAttribute('aria-pressed', 'false')
    await like.click()
    await expect(toast(page)).toContainText('You can like comments once your account is a little older or one of your comments is approved.')
    await expect(like).toHaveAttribute('aria-pressed', 'false')
    await expect(like).toHaveText('0')
    const r = await fresh.session.write({ action: 'glowthreadreact', id, value: '1', type: 'like' })
    expect((r.error || r.errors[0]).code).toBe('newaccount')
    const list = await (await wiki.api('Admin')).rest('/page/' + (await wiki.pageId(PAGES.play)) + '/comments?sort=new')
    expect(list.comments.find((c: { id: number }) => c.id === id).likes).toBe(0)
  })

  test('a blocked user sees why instead of the composer', async ({ wiki }) => {
    await wiki.as('Blocked')
    await wiki.open(PAGES.play)
    const note = wiki.section.getByRole('note')
    await expect(note).toContainText('You are blocked and cannot post comments.')
    await expect(wiki.section.getByRole('link', { name: 'Block details' })).toHaveAttribute('href', /Special:BlockList/)
    await expect(wiki.section.getByRole('textbox')).toHaveCount(0)
  })

  test('a partial block applies to its page only', async ({ wiki }) => {
    await wiki.as('PartialBlocked')
    await wiki.open(PAGES.partial)
    await expect(wiki.section.getByRole('note')).toContainText('You are blocked from commenting on this page.')
    await expect(wiki.section.getByRole('textbox')).toHaveCount(0)
    await wiki.open(PAGES.play)
    await expect(wiki.section.getByRole('textbox')).toHaveCount(1)
  })

  test('a locked page can be read and liked but not answered', async ({ wiki }) => {
    await wiki.as('Alice')
    await wiki.open(PAGES.locked)
    await expect(wiki.section.getByRole('note')).toContainText('Comments on this page are locked. You can read and like them, but not post or reply.')
    await expect(wiki.section.getByRole('textbox')).toHaveCount(0)
    await expect(wiki.section.locator('article').first()).toBeVisible()
    await expect(wiki.section.locator('article footer').getByRole('button', { name: 'Reply' })).toHaveCount(0)
    // Alice's own comments cannot be liked: take someone else's.
    await expect(wiki.section.locator('article:not(:has(h3 a[href$="User:Alice"])) > div footer button[aria-pressed]').first()).toBeEnabled()
  })

  test('a closed page shows readers a short note only, in the space held for it', async ({ wiki, page }) => {
    await recordShifts(page)
    await wiki.open(PAGES.closed, { scroll: false })
    await expect(wiki.section.getByRole('note')).toHaveText('Comments are closed on this page.')
    await expect(wiki.section.locator('article')).toHaveCount(0)
    await expect(wiki.section.getByRole('tab')).toHaveCount(0)
    // The server reserved the note's height before the first paint: nothing moved.
    await settled(page)
    expect(await page.evaluate(() => (window as any).__gtShifts)).toBeLessThan(0.02)
  })

  test('a moderator sees a closed page with a note that readers do not see its comments', async ({ wiki }) => {
    await wiki.as('Moderator')
    await wiki.open(PAGES.closed)
    await expect(wiki.section.getByRole('note').filter({ hasText: 'You see them because you are a moderator.' })).toBeVisible()
    await expect(wiki.section.getByRole('button', { name: /^Page:/ })).toContainText('Closed')
  })

  test('the empty state invites the first comment', async ({ wiki }) => {
    await wiki.as('Alice')
    await wiki.open(PAGES.empty)
    await expect(wiki.section).toContainText('No comments yet')
    await expect(wiki.section).toContainText('Be the first to say something.')
    await expect(wiki.section.getByRole('textbox')).toHaveCount(1)
  })

  test('the empty state tells a logged-out reader nobody has commented yet', async ({ wiki }) => {
    await wiki.open(PAGES.empty)
    await expect(wiki.section).toContainText('No comments yet')
    await expect(wiki.section).toContainText('Nobody has commented here yet.')
    await expect(wiki.section.getByRole('link', { name: 'Log in' })).toBeVisible()
  })

  test('shows a skeleton in the reserved space while the list loads', async ({ wiki, page }) => {
    let release = () => {}
    const held = new Promise<void>((done) => { release = done })
    await page.route('**/rest.php/glowthread/v1/page/*/comments?sort=*', async (route) => {
      await held
      await route.continue()
    })
    await wiki.open(PAGES.showcase, { wait: false })
    await page.waitForSelector('#glowthread .gt-root #gt-heading')
    await expect(wiki.section.locator('.gt-pulse').first()).toBeVisible()
    await expect(wiki.section.locator('article')).toHaveCount(0)
    await expect(wiki.section.getByRole('alert')).toHaveCount(0)
    // The skeleton keeps the height the loader reserved, so the arrival of the list moves nothing above.
    const reserve = Number(await wiki.section.getAttribute('data-gt-reserve'))
    expect(reserve).toBeGreaterThan(100)
    expect((await wiki.section.boundingBox())!.height).toBeGreaterThanOrEqual(reserve - 1)
    release()
    await wiki.ready()
    await expect(wiki.section.locator('article').first()).toBeVisible()
  })

  test('a failed load offers a retry that works', async ({ wiki, page }) => {
    let fail = true
    await page.route('**/rest.php/glowthread/v1/page/**', (route) => (fail ? route.abort() : route.continue()))
    await wiki.open(PAGES.showcase, { wait: false })
    const alert = wiki.section.getByRole('alert')
    await expect(alert).toContainText('Could not reach the wiki. Check your connection and try again.')
    fail = false
    await alert.getByRole('button', { name: 'Try again' }).click()
    await expect(wiki.section.locator('article').first()).toBeVisible()
    wiki.errors.length = 0 // the aborted request logs a network error on purpose
  })

  test('a server error says so and offers a retry', async ({ wiki, page }) => {
    let fail = true
    await page.route('**/rest.php/glowthread/v1/page/*/comments?sort=*', (route) => (fail ? route.fulfill({ status: 503, contentType: 'text/html', body: 'down' }) : route.continue()))
    await wiki.open(PAGES.showcase, { wait: false })
    const alert = wiki.section.getByRole('alert')
    await expect(alert).toContainText('The server had a problem. Try again in a moment.')
    fail = false
    wiki.errors.length = 0 // the failed request is logged on purpose
    await alert.getByRole('button', { name: 'Try again' }).click()
    await expect(wiki.section.locator('article').first()).toBeVisible()
  })

  test('a rate limit says how long to wait', async ({ wiki, page }) => {
    await wiki.as('Alice')
    await page.route('**/api.php', async (route) => {
      if ((route.request().postData() || '').indexOf('action=glowthreadpost') >= 0) {
        await route.fulfill({ json: { errors: [{ code: 'ratelimited', text: 'slow down', data: { retryAfter: 30 } }] } })
      } else {
        await route.continue()
      }
    })
    await wiki.open(PAGES.play)
    await wiki.section.getByRole('textbox').fill(unique('Too fast'))
    await wiki.section.getByRole('button', { name: 'Send' }).click()
    await expect(wiki.section).toContainText(/You are doing that too often\. Try again in \d+ seconds?\./)
    await expect(wiki.section.getByRole('button', { name: 'Send' })).toBeDisabled()
  })
})
