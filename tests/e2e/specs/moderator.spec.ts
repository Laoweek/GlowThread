import { test, expect, PAGES, postComment, toast, unique } from './fixtures'

test.describe('moderator in place', () => {
  test('filters to held comments and approves one', async ({ wiki, page }) => {
    // A link on the dev wiki's spam blacklist holds the comment (not the new-account gate,
    // which states.spec.ts needs untouched).
    const text = unique('Held for the test')
    // Bob may have only a few held comments (toomanyheld): delete those an interrupted run left here.
    const mod = await wiki.api('Moderator')
    const left = await mod.rest('/moderation/queue?tab=held&user=Bob&pageid=' + (await wiki.pageId(PAGES.play)) + '&limit=50')
    const leftIds = left.items.map((i: { comment: { id: number } }) => i.comment.id)
    if (leftIds.length) await mod.write({ action: 'glowthreadmoderate', ids: leftIds.join('|'), do: 'delete' })
    await postComment(wiki, 'Bob', PAGES.play, text + ' https://spam.example/promo')
    await wiki.as('Moderator')
    await wiki.open(PAGES.play)
    const chip = wiki.section.getByRole('button', { name: /^Awaiting review/ })
    await chip.click()
    await expect(chip).toHaveAttribute('aria-pressed', 'true')
    const item = wiki.section.locator('article', { hasText: text })
    await expect(item).toContainText('Held for review: blacklisted link')
    await item.getByRole('button', { name: 'Approve' }).click()
    await expect(toast(page)).toContainText('Approved 1 comment')
    await expect(item.getByRole('button', { name: 'Approve' })).toHaveCount(0)
    await chip.click()
    await expect(chip).toHaveAttribute('aria-pressed', 'false')
  })

  test('shows each report reason once with its reporters, in the text column above the like row', async ({ wiki }) => {
    const text = unique('Reported for the test')
    const id = await postComment(wiki, 'Admin', PAGES.play, text)
    for (const user of ['Moderator', 'Bob']) await (await wiki.api(user)).write({ action: 'glowthreadreport', id, reason: 'spam' })
    try {
      await wiki.as('Moderator')
      await wiki.open(PAGES.play)
      await wiki.section.getByRole('button', { name: /^Reported/ }).click()
      const article = wiki.section.locator('article[data-comment="' + id + '"]')
      await expect(article.locator('header')).toContainText('2 reports')
      const reason = article.locator('p', { hasText: 'Spam or advertising' })
      await expect(reason).toHaveCount(1)
      await expect(reason).toContainText('×2')
      await expect(reason).toContainText(/reported by (Moderator, Bob|Bob, Moderator)/)
      // One block with the decisions, above the like row, starting where the text starts.
      const box = async (l: import('@playwright/test').Locator) => (await l.boundingBox())!
      const body = await box(article.locator('p[dir="auto"]').first())
      const line = await box(reason.locator('xpath=../..'))
      const dismiss = await box(article.getByRole('button', { name: 'Dismiss reports' }))
      // The like row (a button, or plain text in the moderator's filtered view).
      const like = await box(article.locator('> div').first().locator('footer'))
      expect(Math.round(line.x)).toBe(Math.round(body.x))
      expect(dismiss.y).toBeGreaterThan(line.y)
      expect(like.y).toBeGreaterThan(dismiss.y)
    } finally {
      await (await wiki.api('Admin')).write({ action: 'glowthreadmoderate', ids: String(id), do: 'dismiss' })
    }
  })

  test('hides a comment and undoes it from the toast', async ({ wiki, page }) => {
    const id = await postComment(wiki, 'Admin', PAGES.play, unique('A comment to hide'))
    await wiki.as('Moderator')
    await wiki.open(PAGES.play)
    await wiki.section.getByRole('tab', { name: 'Newest' }).click()
    const article = wiki.section.locator('article[data-comment="' + id + '"]')
    await article.getByRole('button', { name: 'More actions' }).first().click()
    await page.getByRole('menuitem', { name: 'Hide' }).click()
    await expect(article.locator('header').first()).toContainText('Hidden')
    await expect(toast(page)).toContainText('Hid 1 comment')
    await toast(page).getByRole('button', { name: 'Undo' }).click()
    await expect(article.locator('header').first()).not.toContainText('Hidden')
    await expect(article.getByRole('button', { name: 'Reply' })).toBeVisible()
  })

  test('pins and unpins a comment', async ({ wiki, page }) => {
    const id = await postComment(wiki, 'Admin', PAGES.play, unique('Please pin this'))
    await wiki.as('Moderator')
    await wiki.open(PAGES.play)
    await wiki.section.getByRole('tab', { name: 'Newest' }).click()
    await wiki.section.locator('article[data-comment="' + id + '"]').getByRole('button', { name: 'More actions' }).first().click()
    await page.getByRole('menuitem', { name: 'Pin', exact: true }).click()
    // Pinning reloads the list (the server orders the pinned block).
    await expect(toast(page)).toContainText('Pinned')
    const first = wiki.section.locator('ol > li').first().locator('article').first()
    await expect(first).toHaveAttribute('data-comment', String(id))
    await expect(first.locator('header').first()).toContainText('Pinned')
    await first.getByRole('button', { name: 'More actions' }).first().click()
    await page.getByRole('menuitem', { name: 'Unpin' }).click()
    await expect(toast(page)).toContainText('Unpinned')
    await expect(wiki.section.locator('article[data-comment="' + id + '"] header').first()).not.toContainText('Pinned')
  })

  test('selects comments in Select mode and acts on them at once', async ({ wiki, page }) => {
    const a = await postComment(wiki, 'Admin', PAGES.play, unique('Bulk one'))
    const b = await postComment(wiki, 'Admin', PAGES.play, unique('Bulk two'))
    await wiki.as('Moderator')
    await wiki.open(PAGES.play)
    await wiki.section.getByRole('tab', { name: 'Newest' }).click()
    await wiki.section.getByRole('button', { name: 'Select', exact: true }).click()
    for (const id of [a, b]) await wiki.section.locator('article[data-comment="' + id + '"]').getByRole('checkbox', { name: /^Select comment #\d+$/ }).first().click()
    const bar = wiki.section.getByRole('toolbar', { name: 'Bulk actions' })
    await expect(bar).toContainText('2 selected')
    await bar.getByRole('button', { name: 'Hide' }).click()
    for (const id of [a, b]) await expect(wiki.section.locator('article[data-comment="' + id + '"] header').first()).toContainText('Hidden')
    await toast(page).getByRole('button', { name: 'Undo' }).click()
    for (const id of [a, b]) await expect(wiki.section.locator('article[data-comment="' + id + '"] header').first()).not.toContainText('Hidden')
    await bar.getByRole('button', { name: 'Done' }).click()
    await expect(bar).toHaveCount(0)
  })

  test('locks the page after an inline confirmation', async ({ wiki, page }) => {
    const pid = await wiki.pageId(PAGES.play)
    await wiki.as('Moderator')
    await wiki.open(PAGES.play)
    try {
      await wiki.section.getByRole('button', { name: /^Page:/ }).click()
      await page.getByRole('menuitemradio', { name: /^Locked/ }).click()
      const confirm = wiki.section.getByRole('group', { name: /^Lock comments on this page\?/ })
      await confirm.getByRole('button', { name: 'Lock' }).click()
      await expect(toast(page)).toContainText('Comments are locked on this page')
      await expect(wiki.section.getByRole('button', { name: /^Page:/ })).toContainText('Locked')
      await expect(wiki.section.getByRole('note')).toContainText('Comments on this page are locked')
    } finally {
      await (await wiki.api('Moderator')).write({ action: 'glowthreadpage', pageid: pid, state: 'open' })
    }
  })

  test('sees the seeded queue of the showcase in place: held, reported and hidden', async ({ wiki }) => {
    await wiki.as('Moderator')
    await wiki.open(PAGES.showcase)
    const held = wiki.section.getByRole('button', { name: /^Awaiting review/ })
    const reported = wiki.section.getByRole('button', { name: /^Reported/ })
    await expect(held).toContainText(/[1-9]/)
    await expect(reported).toContainText(/[1-9]/)
    await held.click()
    await expect(wiki.section.locator('article').first()).toContainText('Held for review')
    await reported.click()
    await expect(wiki.section.locator('article').first()).toContainText(/\d+ reports?/)
    // A moderator also sees the comment the seed hid, with Restore.
    await reported.click()
    await wiki.section.getByRole('tab', { name: 'Newest' }).click()
    await expect(wiki.section.locator('article', { has: wiki.page.getByRole('button', { name: /^Restore/ }) }).first()).toContainText('Hidden')
  })
})
