import { test, expect } from './fixtures'
import {
  HUB, SANDBOX, activeId, expectFocusOn, feedItems, hub, item, moderate, openHub, seedComment, statusOf, toast, token,
} from './admin-helpers'
import type { Wiki } from './fixtures'

// Special:GlowThread on Vector 2022 (desktop) and Minerva (phone). Keyboard-only flows run
// on the desktop, touch layouts (record sheet, bulk bar) are checked on the phone.

const FILTER_PAGE = 'MediaWiki:GlowThread-filter'

/** Replaces the word filter rules for the test, and returns a function that puts the old ones back. */
async function useFilterRules(wiki: Wiki, text: string) {
  const admin = await wiki.api('Admin')
  const read = await admin.api({ action: 'query', titles: FILTER_PAGE, prop: 'revisions', rvprop: 'content', rvslots: 'main' })
  const p = read.query.pages[0]
  const original = p.missing ? '' : p.revisions[0].slots.main.content
  const r = await admin.write({ action: 'edit', title: FILTER_PAGE, text, summary: 'e2e test rules' })
  if (!r.edit) throw new Error('edit: ' + JSON.stringify(r))
  return async () => {
    await admin.write({ action: 'edit', title: FILTER_PAGE, text: original, summary: 'e2e test: restore the rules' })
  }
}

test.describe('moderation hub', () => {
  test('refuses a reader without the moderation right', async ({ wiki, page }) => {
    await wiki.as('Alice')
    await page.goto('/index.php?title=' + encodeURIComponent(HUB + '/queue') + '&useskin=' + wiki.skin)
    await expect(page.locator('#firstHeading')).toContainText('Permission error')
    await expect(page.locator('#ext-glowthread-admin')).toHaveCount(0)
    const alice = await wiki.api('Alice')
    expect((await alice.rest('/moderation/queue')).errorKey).toBe('permission-denied')
  })

  test('routes deep links, back and forward, and the document title', async ({ wiki, page }) => {
    test.skip(wiki.phone, 'desktop')
    await wiki.as('Moderator')
    await openHub(wiki, 'queue', { tab: 'held', page: SANDBOX })
    await expect(page.getByRole('tab', { name: /^Held/ })).toHaveAttribute('aria-selected', 'true')
    await expect(page.locator('#gt-filter-page')).toHaveValue(SANDBOX)
    await expect(page).toHaveTitle(/^Held · Comment moderation/)
    await page.getByRole('tab', { name: /^Deleted/ }).click()
    await expect(page).toHaveURL(/tab=deleted/)
    await expect(page).toHaveURL(/page=Sandbox(&|$)/)
    await expect(page).toHaveTitle(/^Deleted · Comment moderation/)
    await page.goBack()
    await expect(page.getByRole('tab', { name: /^Held/ })).toHaveAttribute('aria-selected', 'true')
    await page.goForward()
    await expect(page.getByRole('tab', { name: /^Deleted/ })).toHaveAttribute('aria-selected', 'true')
    // The comment log is GlowThread's own log type.
    await expect(hub(page).getByRole('link', { name: 'Comment log' })).toHaveAttribute('href', /Special(:|%3A)Log(\/glowthread|&type=glowthread)$/)
    await hub(page).getByRole('navigation').getByRole('link', { name: 'Settings' }).click()
    await expect(page).toHaveURL(/Special(:|%3A)GlowThread\/settings/)
    await expect(page).toHaveTitle(/^Settings · Comment moderation/)
    await page.goBack()
    await expect(page.getByRole('tab', { name: /^Deleted/ })).toHaveAttribute('aria-selected', 'true')
    // Everything by one author, with their record.
    await openHub(wiki, 'user/Bob')
    await expect(page).toHaveTitle(/^Comments by Bob · Comment moderation/)
    await expect(page.getByRole('tab', { name: /^All/ })).toHaveAttribute('aria-selected', 'true')
    await expect(page.locator('#gt-filter-user')).toHaveValue('Bob')
    await expect(hub(page).locator('aside h3')).toHaveText('Bob')
  })

  test('opens on the seeded queue: reported comments first, with their reasons', async ({ wiki, page }) => {
    await wiki.as('Moderator')
    await openHub(wiki, 'queue')
    await expect(page.getByRole('tab', { name: /^Reported/ })).toHaveAttribute('aria-selected', 'true')
    await expect(feedItems(page).first()).toBeVisible()
    await expect(feedItems(page).first()).toContainText(/×\d+/)
    await page.getByRole('tab', { name: /^Held/ }).click()
    await expect(feedItems(page).first()).toContainText(/Held for review: /)
  })

  test('triages with the keyboard alone, with undo', async ({ wiki, page }) => {
    test.skip(wiki.phone, 'keyboard')
    const tk = token('kb')
    const ids = []
    for (let i = 1; i <= 3; i++) ids.push(await seedComment(wiki, 'Keyboard triage ' + i + ' ' + tk, { reporters: ['Moderator'] }))
    const [c1, c2, c3] = ids
    await wiki.as('Moderator')
    await openHub(wiki, 'queue', { q: tk })
    await expect(feedItems(page)).toHaveCount(3)
    // Same report count: newest first. The first j puts focus on the first item.
    await page.keyboard.press('j')
    await expectFocusOn(page, c3)
    await page.keyboard.press('j')
    await expectFocusOn(page, c2)
    await page.keyboard.press('k')
    await expectFocusOn(page, c3)
    // d deletes it, and focus moves on to the next item.
    await page.keyboard.press('d')
    await expect(toast(page)).toContainText('Deleted 1 comment')
    await expect(item(page, c3)).toHaveCount(0)
    await expectFocusOn(page, c2)
    expect(await statusOf(wiki, c3)).toBe('deleted')
    // F8 reaches Undo in the toast; the server's exact undo brings it back with its reports.
    await page.keyboard.press('F8')
    await expect.poll(() => page.evaluate(() => document.activeElement && document.activeElement.textContent)).toContain('Undo')
    await page.keyboard.press('Enter')
    await expect(toast(page)).toContainText('Undid the action on 1 comment')
    expect(await statusOf(wiki, c3)).toBe('visible')
    await expect(item(page, c3)).toBeVisible()
    // a keeps a reported comment and closes its reports.
    await page.keyboard.press('j')
    await expectFocusOn(page, c2)
    await page.keyboard.press('a')
    await expect(toast(page)).toContainText('Approved 1 comment')
    await expectFocusOn(page, c1)
    await expect(feedItems(page)).toHaveCount(2)
    // x selects, Esc clears.
    await page.keyboard.press('x')
    const bar = page.getByRole('toolbar', { name: 'Bulk actions' })
    await expect(bar).toContainText('1 selected')
    await page.keyboard.press('Escape')
    await expect(bar).toHaveCount(0)
    // / goes to the search field, where the keys are text; Esc comes back to the list.
    await page.keyboard.press('/')
    expect(await activeId(page)).toBe('gt-filter-q')
    await page.keyboard.type('d')
    await expect(feedItems(page)).toHaveCount(2)
    await page.keyboard.press('Escape')
    await expectFocusOn(page, c1)
    await expect(page.locator('#gt-filter-q')).toHaveValue(tk)
    // ? lists the shortcuts.
    await page.keyboard.press('?')
    await expect(page.getByRole('dialog', { name: 'Keyboard shortcuts' })).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(page.getByRole('dialog')).toHaveCount(0)
    expect(await statusOf(wiki, c1)).toBe('visible')
    await moderate(wiki, [c1, c3], 'dismiss') // leave the shared queue as it was
  })

  test('acts on a selection from the bulk bar and undoes it', async ({ wiki, page }) => {
    const tk = token('bulk')
    const a = await seedComment(wiki, 'Bulk A ' + tk)
    const b = await seedComment(wiki, 'Bulk B ' + tk)
    await wiki.as('Moderator')
    await openHub(wiki, 'queue', { tab: 'all', q: tk })
    await expect(feedItems(page)).toHaveCount(2)
    for (const id of [a, b]) await item(page, id).getByRole('checkbox').click()
    const bar = page.getByRole('toolbar', { name: 'Bulk actions' })
    await expect(bar).toContainText('2 selected')
    if (wiki.phone) {
      // The bar fits the phone screen.
      const box = await bar.boundingBox()
      expect(box!.x).toBeGreaterThanOrEqual(0)
      expect(box!.x + box!.width).toBeLessThanOrEqual(page.viewportSize()!.width)
    }
    await bar.getByRole('button', { name: 'Delete' }).click()
    for (const id of [a, b]) await expect(item(page, id).locator('header')).toContainText('Deleted')
    await expect(bar).toHaveCount(0)
    await toast(page).getByRole('button', { name: 'Undo' }).click()
    for (const id of [a, b]) await expect(item(page, id).locator('header')).not.toContainText('Deleted')
    expect(await statusOf(wiki, a)).toBe('visible')
    expect(await statusOf(wiki, b)).toBe('visible')
  })

  test('approves a held comment and restores a deleted one', async ({ wiki, page }) => {
    const tk = token('held')
    // The dev wiki's spam blacklist holds the link. Held authors are spread over the regular
    // accounts: each may post 6 times a minute and have only 3 held comments at a time.
    const held = await seedComment(wiki, 'Held ' + tk + ' https://spam.example/promo', { author: 'Dave' })
    const gone = await seedComment(wiki, 'Deleted ' + tk)
    await moderate(wiki, [gone], 'delete')
    await wiki.as('Moderator')
    try {
      await openHub(wiki, 'queue', { tab: 'held', q: tk })
      await expect(item(page, held)).toContainText('Held for review: blacklisted link')
      await item(page, held).getByRole('button', { name: /^Approve/ }).click()
      await expect(toast(page)).toContainText('Approved 1 comment')
    } finally {
      // Never leave a held comment behind when the approval did not happen.
      if ((await statusOf(wiki, held)) === 'held') await moderate(wiki, [held], 'delete')
    }
    await expect(item(page, held)).toHaveCount(0)
    await expect(page.locator('#gt-queue-status')).toContainText('Nothing matches these filters')
    expect(await statusOf(wiki, held)).toBe('visible')
    await page.getByRole('tab', { name: /^Deleted/ }).click()
    await item(page, gone).getByRole('button', { name: /^Restore/ }).click()
    await expect(toast(page)).toContainText('Restored 1 comment, now public')
    expect(await statusOf(wiki, gone)).toBe('visible')
  })

  test('erases only for erase holders, after saying it is permanent', async ({ wiki, page, context }) => {
    const tk = token('erase')
    const id = await seedComment(wiki, 'To erase ' + tk)
    await moderate(wiki, [id], 'delete')
    await wiki.as('Moderator')
    await openHub(wiki, 'queue', { tab: 'deleted', q: tk })
    await item(page, id).getByRole('button', { name: 'Erase…' }).click()
    const dialog = page.getByRole('alertdialog')
    await expect(dialog).toContainText('Erase this comment for good?')
    await expect(dialog).toContainText('It cannot be undone')
    // It opens on Cancel.
    await expect(dialog.getByRole('button', { name: 'Cancel' })).toBeFocused()
    await page.keyboard.press('Enter')
    await expect(dialog).toHaveCount(0)
    expect(await statusOf(wiki, id)).toBe('deleted')
    await item(page, id).getByRole('button', { name: 'Erase…' }).click()
    await dialog.getByRole('textbox').fill('Test erase')
    await dialog.getByRole('button', { name: 'Erase permanently' }).click()
    await expect(toast(page)).toContainText('Erased 1 comment')
    await expect(item(page, id)).toContainText('This comment was erased.')
    expect(await statusOf(wiki, id)).toBe('erased')
    // Erasing is logged in the private log, never in the public one.
    const admin = await wiki.api('Admin')
    const priv = await admin.api({ action: 'query', list: 'logevents', letype: 'glowthread-private', lelimit: '5' })
    expect(priv.query.logevents.some((e: { action: string }) => e.action === 'erase'), JSON.stringify(priv.query.logevents)).toBe(true)
    // A moderator without glowthread-erase gets no erase button.
    const other = await seedComment(wiki, 'Cannot erase ' + tk)
    await moderate(wiki, [other], 'delete')
    await context.route(/title=Special%3AGlowThread/, async (route) => {
      const res = await route.fetch()
      await route.fulfill({ response: res, body: (await res.text()).replace('"canErase":true', '"canErase":false') })
    })
    await openHub(wiki, 'queue', { tab: 'deleted', q: tk })
    await expect(item(page, other).getByRole('button', { name: /^Restore/ })).toBeVisible()
    await expect(item(page, other).getByRole('button', { name: 'Erase…' })).toHaveCount(0)
  })

  test('shows the author and page records, and switches the page state', async ({ wiki, page }) => {
    const tk = token('rec')
    const id = await seedComment(wiki, 'Record ' + tk, { author: 'Bob', reporters: ['Admin'] })
    try {
      await wiki.as('Moderator')
      await openHub(wiki, 'queue', { q: tk })
      if (wiki.phone) {
        await item(page, id).getByRole('button', { name: 'Bob', exact: true }).click()
        const sheet = page.getByRole('dialog', { name: 'Record of Bob' })
        await expect(sheet).toBeVisible()
        await expect(sheet).toContainText('Reports filed')
        await expect(sheet.getByRole('link', { name: /Block log/ })).toHaveAttribute('href', /Special(:|%3A)Log\/block/)
        return
      }
      const aside = hub(page).locator('aside')
      // The record follows the focused item: its author first.
      await expect(aside.locator('h3')).toHaveText('Bob')
      await expect(aside).toContainText(/Joined [23] years ago/)
      await expect(aside.getByRole('link', { name: /^Block/ }).first()).toHaveAttribute('href', /Special(:|%3A)Block\/Bob/)
      await aside.getByRole('button', { name: 'Page' }).click()
      await expect(aside.locator('h3')).toHaveText(SANDBOX)
      await expect(aside).toContainText(/\d+ comments posted so far/)
      const states = aside.getByRole('group', { name: 'Comments on this page' })
      await states.getByRole('button', { name: 'Locked' }).click()
      const confirm = aside.getByRole('group', { name: /^Lock comments on this page\?/ })
      await confirm.getByRole('textbox').fill('Test lock')
      await confirm.getByRole('button', { name: 'Lock' }).click()
      await expect(toast(page)).toContainText('Comments are locked on this page')
      const mod = await wiki.api('Moderator')
      const pid = await wiki.pageId(SANDBOX)
      try {
        expect((await mod.rest('/moderation/page/' + pid)).page.state).toBe('locked')
        await states.getByRole('button', { name: 'Open' }).click()
        await aside.getByRole('group', { name: /^Open comments on this page again/ }).getByRole('button', { name: 'Open' }).click()
        await expect(toast(page)).toContainText('Comments are open on this page')
      } finally {
        await mod.write({ action: 'glowthreadpage', pageid: pid, state: 'open' })
      }
    } finally {
      await moderate(wiki, [id], 'dismiss') // leave the shared queue as it was
    }
  })

  test('marks blocked authors, sitewide and partial', async ({ wiki, page }) => {
    test.skip(wiki.phone, 'one layout is enough')
    // The record of a partially blocked account says so.
    await wiki.as('Moderator')
    await openHub(wiki, 'user/PartialBlocked')
    await expect(hub(page).locator('aside')).toContainText('Partially blocked')
    await openHub(wiki, 'user/Blocked')
    await expect(hub(page).locator('aside')).toContainText('Blocked sitewide')
  })

  test('keeps the record of a reporter clicked in another item (focus follow does not replace it)', async ({ wiki, page }) => {
    test.skip(wiki.phone, 'the record follows focus on wide screens only')
    const tk = token('follow')
    const first = await seedComment(wiki, 'Record follows one ' + tk, { reporters: ['Moderator'] })
    const second = await seedComment(wiki, 'Record follows two ' + tk, { author: 'Bob', reporters: ['Moderator'] })
    try {
      await wiki.as('Moderator')
      await openHub(wiki, 'queue', { q: tk })
      const aside = hub(page).locator('aside')
      await item(page, first).focus()
      await expect(aside.locator('h3')).toHaveText('Admin')
      // The press moves focus into the other item before the click opens the reporter.
      await item(page, second).getByRole('button', { name: 'Moderator', exact: true }).click()
      await expect(aside.locator('h3')).toHaveText('Moderator')
      // Nothing to wait for but time: past the hub's 150 ms focus-follow delay (admin/App.vue),
      // the record must not go back to the item's author.
      await page.waitForTimeout(500)
      await expect(aside.locator('h3')).toHaveText('Moderator')
    } finally {
      await moderate(wiki, [first, second], 'dismiss')
    }
  })

  test('saves settings with a reason and links to the log entry', async ({ wiki, page }) => {
    test.skip(wiki.phone, 'one layout is enough for the save flow')
    const mod = await wiki.api('Moderator')
    const was = (await mod.rest('/settings')).settings.maxMentions
    await wiki.as('Moderator')
    await openHub(wiki, 'settings')
    const field = page.getByLabel('Mentions per comment')
    const before = await field.inputValue()
    const next = before === '6' ? '7' : '6'
    await field.fill(next)
    const bar = page.getByRole('region', { name: /unsaved change/ })
    await bar.getByRole('button', { name: 'Review and save' }).click()
    await expect(bar).toContainText('Mentions per comment')
    await expect(bar).toContainText(before + ' people')
    await expect(bar).toContainText(next + ' people')
    await bar.getByRole('button', { name: 'Save changes' }).click()
    await expect(bar).toContainText('Please give a reason.')
    await bar.getByRole('textbox').fill('End-to-end test')
    try {
      await bar.getByRole('button', { name: 'Save changes' }).click()
      await expect(hub(page).getByRole('note').filter({ hasText: 'Saved 1 change.' })).toBeVisible()
      const link = hub(page).getByRole('link', { name: 'View in the comment log' })
      await expect(link).toHaveAttribute('href', /logid=\d+/)
      expect((await mod.rest('/settings')).settings.maxMentions.value).toBe(Number(next))
      // The entry is in GlowThread's public log.
      const logid = Number(/logid=(\d+)/.exec((await link.getAttribute('href'))!)![1])
      const log = await mod.api({ action: 'query', list: 'logevents', letype: 'glowthread', lelimit: '5' })
      expect(log.query.logevents.map((e: { logid: number }) => e.logid)).toContain(logid)
    } finally {
      // Back to the stored value, or to no override at all.
      await mod.write({ action: 'glowthreadsettings', settings: JSON.stringify({ maxMentions: was.overridden ? was.value : null }), reason: 'End-to-end test: restore' })
    }
  })

  test('tests the word filter live and saves it with a summary', async ({ wiki, page }) => {
    test.skip(wiki.phone, 'one layout is enough for the editor')
    const restore = await useFilterRules(wiki, '# Test rules\ncasino\n')
    try {
      await wiki.as('Moderator')
      await openHub(wiki, 'settings')
      const test = page.getByLabel('Test a comment')
      // Plain rules match whole words, ignoring case, also with spaced-out letters.
      await test.fill('Visit our Casino tonight')
      await expect(page.getByText('Would be held for review: matches line 2 (casino).')).toBeVisible()
      await test.fill('c a s i n o night')
      await expect(page.getByText('Would be held for review: matches line 2 (casino).')).toBeVisible()
      await test.fill('Two casinos in town')
      await expect(page.getByText('Would not be held.')).toBeVisible()
      const editor = page.getByLabel('Word filter rules, one per line')
      await editor.fill('# Test rules\ncasino\n/(broken/\n')
      await expect(page.locator('#gt-filter-invalid')).toContainText('Line 3')
      await test.fill('An ordinary comment')
      await expect(page.getByText('Would not be held.')).toBeVisible()
      await editor.fill('# Test rules\ncasino\njackpot\n')
      await page.getByRole('button', { name: 'Save rules' }).click()
      await expect(page.getByText('Please give a reason.')).toBeVisible()
      await page.getByLabel('Edit summary (required)').fill('End-to-end test: one more rule')
      await page.getByRole('button', { name: 'Save rules' }).click()
      await expect(page.getByRole('link', { name: 'View the change' })).toHaveAttribute('href', /Special(:|%3A)Diff\/\d+/)
      await expect(page.getByText(/^2 active rules/)).toBeVisible()
    } finally {
      await restore()
    }
  })

  test('explains why a blocked moderator cannot act', async ({ wiki, page }) => {
    test.skip(wiki.phone, 'desktop')
    const tk = token('blk')
    const id = await seedComment(wiki, 'Blocked moderator ' + tk, { reporters: ['Moderator'] })
    try {
      await wiki.as('BlockedMod')
      await openHub(wiki, 'queue', { q: tk })
      await expect(hub(page).getByRole('alert').filter({ hasText: 'You are blocked (reason:' })).toContainText('so the wiki refuses your moderation actions')
      await expect(feedItems(page).first().getByRole('button', { name: /^Approve/ })).toBeDisabled()
      await page.keyboard.press('j')
      await page.keyboard.press('d')
      await expect(page.locator('#glowthread-portal [role="alert"]')).toContainText('You are blocked, so you cannot moderate comments.')
      await expect(feedItems(page)).toHaveCount(1)
      expect(await statusOf(wiki, id)).toBe('visible')
    } finally {
      await moderate(wiki, [id], 'dismiss')
    }
  })

  test('shows an error with retry, and an empty queue that says why', async ({ wiki, page, context }) => {
    await wiki.as('Moderator')
    let fail = true
    await context.route('**/rest.php/glowthread/v1/moderation/queue?*', (route) =>
      fail && route.request().url().indexOf('limit=50') >= 0
        ? route.fulfill({ status: 503, contentType: 'text/html', body: 'down' })
        : route.continue(),
    )
    await openHub(wiki, 'queue', { q: token('none') })
    const alert = hub(page).getByRole('alert')
    await expect(alert).toContainText('The server had a problem. Try again in a moment.')
    fail = false
    wiki.errors.length = 0 // the failed request is logged on purpose
    await alert.getByRole('button', { name: 'Try again' }).click()
    await expect(page.locator('#gt-queue-status')).toContainText('Nothing matches these filters')
    await page.locator('#gt-queue-status').getByRole('button', { name: 'Clear filters' }).click()
    await expect(page.locator('#gt-filter-q')).toHaveValue('')
  })

  test('keys act on the item the moderator is in, not the remembered one', async ({ wiki, page }) => {
    test.skip(wiki.phone, 'keyboard')
    const tk = token('c2')
    const older = await seedComment(wiki, 'Focus 1 ' + tk, { reporters: ['Moderator'] })
    const newer = await seedComment(wiki, 'Focus 2 ' + tk, { reporters: ['Moderator'] })
    try {
      await wiki.as('Moderator')
      await openHub(wiki, 'queue', { q: tk })
      await page.keyboard.press('j')
      await expectFocusOn(page, newer)
      // Tab on into the next item's checkbox: that item is now the one being worked on.
      await item(page, older).getByRole('checkbox').focus()
      await page.keyboard.press('d')
      await expect(toast(page)).toContainText('Deleted 1 comment')
      expect(await statusOf(wiki, older)).toBe('deleted')
      expect(await statusOf(wiki, newer)).toBe('visible')
      // A key pressed while focus is outside the hub (here the page heading) acts on nothing.
      await page.locator('#firstHeading').evaluate((h) => { h.setAttribute('tabindex', '-1'); (h as HTMLElement).focus() })
      const sent: string[] = []
      page.on('request', (r) => { if (r.method() === 'POST' && /glowthreadmoderate/.test(r.postData() || '')) sent.push(r.postData() || '') })
      await page.keyboard.press('d')
      await page.waitForLoadState('networkidle')
      expect(sent).toEqual([])
      expect(await statusOf(wiki, newer)).toBe('visible')
    } finally {
      await moderate(wiki, [newer], 'dismiss')
    }
  })

  test('a deleted comment that was held says so, and Restore puts it back to held', async ({ wiki, page }) => {
    const tk = token('c7')
    const id = await seedComment(wiki, 'Was held ' + tk + ' https://spam.example/promo', { author: 'Erin' })
    await moderate(wiki, [id], 'delete')
    try {
      await wiki.as('Moderator')
      await openHub(wiki, 'queue', { tab: 'deleted', q: tk })
      await expect(item(page, id)).toContainText('Was held for review: blacklisted link')
      await expect(item(page, id).getByRole('button', { name: /Restore for review/ })).toBeVisible()
      // The bulk bar says the same; the server restores to the status before the deletion.
      await item(page, id).getByRole('checkbox').click()
      await page.getByRole('toolbar', { name: 'Bulk actions' }).getByRole('button', { name: /Restore for review/ }).click()
      await expect(toast(page)).toContainText('still waiting for review')
      expect(await statusOf(wiki, id)).toBe('held')
      // It is listed under Held now, as held.
      await page.getByRole('tab', { name: /^Held/ }).click()
      await expect(item(page, id)).toBeVisible()
      await expect(item(page, id).getByRole('button', { name: /^Approve/ })).toBeVisible()
    } finally {
      await moderate(wiki, [id], 'delete') // authors may have only a few held comments
    }
  })

  test('approve has an exact undo: back to held', async ({ wiki, page }) => {
    test.skip(wiki.phone, 'one layout is enough')
    const tk = token('undo')
    const id = await seedComment(wiki, 'Approve and undo ' + tk + ' https://spam.example/promo', { author: 'Carol' })
    try {
      await wiki.as('Moderator')
      await openHub(wiki, 'queue', { tab: 'held', q: tk })
      await item(page, id).getByRole('button', { name: /^Approve/ }).click()
      await expect(toast(page)).toContainText('Approved 1 comment')
      expect(await statusOf(wiki, id)).toBe('visible')
      await toast(page).getByRole('button', { name: 'Undo' }).click()
      await expect(toast(page)).toContainText('Undid the action on 1 comment')
      expect(await statusOf(wiki, id)).toBe('held')
      await expect(item(page, id)).toBeVisible()
    } finally {
      await moderate(wiki, [id], 'delete')
    }
  })

  test('orders the queue, filters by reporter, and shows the matched filter lines', async ({ wiki, page }) => {
    test.skip(wiki.phone, 'one layout is enough')
    const tk = token('ord')
    const restore = await useFilterRules(wiki, '# Test rules\ncasino\n')
    const a = await seedComment(wiki, 'Order one ' + tk, { reporters: ['Moderator'] })
    const b = await seedComment(wiki, 'Order two ' + tk, { reporters: ['Moderator', 'Bob'] })
    const held = await seedComment(wiki, 'New casino in town ' + tk, { author: 'Dave' })
    try {
      await wiki.as('Moderator')
      await openHub(wiki, 'queue', { tab: 'all', q: tk })
      await expect(feedItems(page).first()).toHaveAttribute('id', 'gt-q-' + held)
      await page.getByLabel('Order').selectOption('old')
      await expect(page).toHaveURL(/sort=old/)
      await expect(feedItems(page).first()).toHaveAttribute('id', 'gt-q-' + a)
      // The word-filter line that held it, recorded with the hold.
      await expect(item(page, held)).toContainText('Held for review: word filter')
      await expect(item(page, held)).toContainText(/Matched line \d+ \(casino\)/)
      // Each reason once, with its reporters.
      await expect(item(page, b)).toContainText(/Spam or advertising\s*×2\s*from (Moderator, Bob|Bob, Moderator)/)
      // Only one reporter's reports.
      await openHub(wiki, 'queue', { tab: 'reported', reporter: 'Moderator', q: tk })
      await expect(page.getByText('Reported by Moderator')).toBeVisible()
      await expect(feedItems(page)).toHaveCount(2)
      await page.getByRole('button', { name: 'Show all reporters' }).click()
      await expect(page).not.toHaveURL(/reporter=/)
    } finally {
      await moderate(wiki, [a, b], 'dismiss')
      await moderate(wiki, [held], 'delete')
      await restore()
    }
  })

  test('the "Which rule?" check sends the comment as stored, mentions as tokens', async ({ wiki, page }) => {
    test.skip(wiki.phone, 'one layout is enough')
    const tk = token('rule')
    const restore = await useFilterRules(wiki, '# Test rules\ncasino\n')
    let held = 0
    try {
      const bob = (await (await wiki.api('Admin')).api({ action: 'query', list: 'users', ususers: 'Bob' })).query.users[0].userid
      const text = 'Casino night with <@' + bob + '> ' + tk
      held = await seedComment(wiki, text, { author: 'Dave' })
      // Comments held before the hub recorded the matched lines have none: then the hub offers
      // to find the rule. The test takes them out of the queue's answer.
      await page.route(/\/moderation\/queue/, async (route) => {
        const response = await route.fetch()
        const json = await response.json()
        for (const i of json.items || []) if (i.comment.id === held && i.comment.mod) i.comment.mod.holdMatches = []
        await route.fulfill({ response, json })
      })
      await wiki.as('Moderator')
      await openHub(wiki, 'queue', { tab: 'all', q: tk })
      await expect(item(page, held)).toContainText('Casino night with @Bob')
      const request = page.waitForRequest((r) => r.method() === 'POST' && /action=glowthreadfiltertest/.test(r.postData() || ''))
      await item(page, held).getByRole('button', { name: 'Which rule?' }).click()
      // What the filter saw when it held the comment: the mention as its token, not the name.
      expect(new URLSearchParams((await request).postData() || '').get('text')).toBe(text)
      await expect(item(page, held)).toContainText(/Matched line \d+ \(casino\)/)
    } finally {
      if (held) await moderate(wiki, [held], 'delete')
      await restore()
    }
  })

  test('x, a, d and r do nothing while focus is in the record panel', async ({ wiki, page }) => {
    test.skip(wiki.phone, 'the record is a panel beside the queue on wide screens only (a dialog on phones)')
    const tk = token('rk')
    const id = await seedComment(wiki, 'Record keys ' + tk, { author: 'Bob', reporters: ['Admin'] })
    try {
      await wiki.as('Moderator')
      await openHub(wiki, 'queue', { q: tk })
      await page.keyboard.press('j')
      await expectFocusOn(page, id)
      const aside = hub(page).locator('aside')
      await expect(aside.locator('h3')).toHaveText('Bob')
      const sent: string[] = []
      page.on('request', (r) => { if (r.method() === 'POST' && /glowthreadmoderate/.test(r.postData() || '')) sent.push(r.postData() || '') })
      const select = item(page, id).getByRole('checkbox')
      // Focus on a control of the panel: the keys could be meant for the comments listed there.
      await aside.getByRole('button', { name: 'Page', exact: true }).focus()
      for (const key of ['x', 'a', 'd', 'r']) await page.keyboard.press(key)
      // A click on the panel's text leaves focus on the page: the keys still do nothing.
      await aside.locator('h3').click()
      expect(await page.evaluate(() => document.activeElement === document.body)).toBe(true)
      for (const key of ['x', 'a', 'd', 'r']) await page.keyboard.press(key)
      await expect(select).not.toBeChecked()
      // Back in the queue, they act again.
      await item(page, id).focus()
      await page.keyboard.press('x')
      await expect(select).toBeChecked()
      // By now a moderation request sent by the keys above would have gone out: none did, and
      // the comment is still visible with its report open (a dismisses reports).
      expect(sent).toEqual([])
      const now = (await (await wiki.api('Admin')).rest('/comment/' + id)).comment
      expect([now.status, now.mod.openReports]).toEqual(['visible', 1])
    } finally {
      await moderate(wiki, [id], 'dismiss')
    }
  })

  test('the reports threshold is 0 (off) or 2 to 100, and 1 is refused', async ({ wiki, page }) => {
    test.skip(wiki.phone, 'one layout is enough')
    await wiki.as('Moderator')
    await openHub(wiki, 'settings')
    const field = page.getByLabel('Hold after reports from')
    await expect(page.locator('#' + (await field.getAttribute('id')) + '-range')).toContainText('0 (off) or 2 to 100')
    const before = await field.inputValue()
    // One account alone must not be able to hold a comment.
    await field.fill('1')
    await expect(field).toHaveAttribute('aria-invalid', 'true')
    await expect(page.getByRole('alert').filter({ hasText: 'Enter 0 to turn it off, or a whole number from 2 to 100.' })).toBeVisible()
    const bar = page.getByRole('region', { name: /unsaved change/ })
    await expect(bar.getByRole('button', { name: 'Review and save' })).toBeDisabled()
    for (const ok of ['0', '2', '100']) {
      await field.fill(ok)
      await expect(field).not.toHaveAttribute('aria-invalid', 'true')
    }
    await field.fill(before)
    // The API refuses it too.
    const mod = await wiki.api('Moderator')
    const was = (await mod.rest('/settings')).settings.autoHoldReporters.value
    const r = await mod.write({ action: 'glowthreadsettings', settings: JSON.stringify({ autoHoldReporters: 1 }), reason: 'End-to-end test: refused' })
    expect((r.error || r.errors[0]).code).toBe('badsettingvalue')
    expect((await mod.rest('/settings')).settings.autoHoldReporters.value).toBe(was)
  })

  test('shows the rate limits with their numbers', async ({ wiki, page }) => {
    test.skip(wiki.phone, 'one layout is enough')
    await wiki.as('Moderator')
    await openHub(wiki, 'settings')
    const table = page.locator('table').filter({ hasText: 'Posting, replying' })
    await expect(table).toContainText(/\d+ per (\d+ )?minutes?/)
    await expect(table).toContainText('Administrators too')
  })
})
