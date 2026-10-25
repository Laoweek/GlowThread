import { test, expect, PAGES, postComment, toast, unique } from './fixtures'
import { Session, WIKI } from '../lib/wiki.mjs'

// Regression tests for security fixes in the client, comment section side. The hub's (keys act
// on the focused item, restoring a comment deleted while held) are in admin.spec.ts.

async function statusOf(wiki: import('./fixtures').Wiki, id: number): Promise<string> {
  return (await (await wiki.api('Admin')).rest('/comment/' + id)).comment.status
}

test.describe('security fixes (comment section)', () => {
  test('undo after Hide puts back what the server had, never publishing a comment held meanwhile', async ({ wiki, page }) => {
    test.skip(wiki.phone, 'one layout is enough')
    const text = unique('Undo test')
    const id = await postComment(wiki, 'Bob', PAGES.play, text)
    await wiki.as('Moderator')
    await wiki.open(PAGES.play)
    await wiki.section.getByRole('tab', { name: 'Newest' }).click()
    const article = wiki.section.locator('article[data-comment="' + id + '"]')
    await expect(article).toBeVisible()
    try {
      // Three eligible readers (past the new-account gate) report it after the moderator
      // loaded the page: the server holds it.
      for (const user of ['Admin', 'Alice', 'Moderator']) {
        const r = await (await wiki.api(user)).write({ action: 'glowthreadreport', id, reason: 'spam' })
        expect(r.glowthreadreport, JSON.stringify(r)).toBeTruthy()
      }
      expect(await statusOf(wiki, id)).toBe('held')
      // The page still shows it as visible; the moderator hides it and undoes.
      await article.getByRole('button', { name: 'More actions' }).first().click()
      await page.getByRole('menuitem', { name: 'Hide' }).click()
      await expect(toast(page)).toContainText('Hid 1 comment')
      const undoRequest = page.waitForRequest((r) => r.method() === 'POST' && /glowthreadmoderate/.test(r.postData() || '') && /do=undo/.test(r.postData() || ''))
      await toast(page).getByRole('button', { name: 'Undo' }).click()
      const sent = new URLSearchParams((await undoRequest).postData() || '')
      expect(sent.get('logids')).toMatch(/^\d+$/)
      await expect(toast(page)).toContainText('Undid the action on 1 comment')
      // Back to held with its reports, not public.
      expect(await statusOf(wiki, id)).toBe('held')
    } finally {
      // Bob may have only a few held comments at a time.
      await (await wiki.api('Admin')).write({ action: 'glowthreadmoderate', ids: String(id), do: 'delete' })
    }
  })

  test('a link never hides its real host behind a user@ part', async ({ wiki }) => {
    test.skip(wiki.phone, 'one layout is enough')
    const text = unique('Links') + ' https://wiki.example.org.official-login.example.org@evil.example/steal https://evil.example/a@b'
    const id = await postComment(wiki, 'Admin', PAGES.play, text)
    await wiki.open(PAGES.play)
    await wiki.section.getByRole('tab', { name: 'Newest' }).click()
    const article = wiki.section.locator('article[data-comment="' + id + '"]')
    // The server leaves a URL with a user part as plain text; an @ later in the path still
    // links, and its text starts with the host it goes to.
    await expect(article).toContainText('official-login.example.org@evil.example/steal')
    const links = article.locator('a[rel~="ugc"]')
    await expect(links).toHaveCount(1)
    await expect(links).toHaveText('evil.example/a@b')
    expect(new URL(await links.getAttribute('href') as string).hostname).toBe('evil.example')
  })

  test('a #gt- link to another page\'s comment says so instead of showing it here, without the page title', async ({ wiki, page }) => {
    const first = await (await wiki.api('Admin')).rest('/page/' + (await wiki.pageId(PAGES.showcase)) + '/comments?sort=new')
    // A comment anyone may see (the newest may be held by another test).
    const foreign = first.comments.find((c: { status: string }) => c.status === 'visible').id
    await wiki.open(PAGES.play, { hash: '#gt-' + foreign, scroll: false })
    // The conversation box above the list says the comment is elsewhere, and shows nothing of it.
    // Not even the page's title: whoever creates a page chooses it (client review C-13).
    const box = wiki.section.locator('[data-linked]')
    await expect(box.getByRole('heading', { name: 'Conversation' })).toBeVisible()
    await expect(box.getByRole('note')).toHaveText('This comment is on another page. Open it there')
    expect(await box.evaluate((e) => e.outerHTML)).not.toContain(PAGES.showcase)
    await expect(box.locator('article')).toHaveCount(0)
    await expect(page.locator('#glowthread article[data-comment="' + foreign + '"]')).toHaveCount(0)
    await expect(box.getByRole('link', { name: 'Open it there' })).toHaveAttribute('href', new RegExp('GlowThreadLink/' + foreign + '$'))
  })

  test('page text cannot capture the section, its overlays or a permalink', async ({ wiki, page }) => {
    test.skip(wiki.phone, 'one layout is enough')
    const title = 'GlowThread capture test'
    const admin = await wiki.api('Admin')
    await admin.write({ action: 'edit', title, text: 'Capture test.', summary: 'e2e' })
    const id = await postComment(wiki, 'Admin', title, unique('A linked comment'))
    // Elements with the app's IDs: in the heading (DISPLAYTITLE keeps an id and opacity), in a
    // 2 px box, a hidden overlay container, a heading and a decoy with the comment's ID.
    const r = await admin.write({
      action: 'edit', title, summary: 'e2e',
      text: '{{DISPLAYTITLE:<span id="glowthread" style="opacity:0"></span>' + title + '}}\n' +
        '<div id="gt-' + id + '">decoy</div>\n<div style="height:2px;overflow:hidden"><div id="glowthread"></div></div>\n' +
        '<div id="glowthread-portal" style="display:none"></div>\n<span id="gt-heading">decoy heading</span>\nCapture test.',
    })
    expect(r.edit && r.edit.result).toBe('Success')
    await wiki.open(title, { hash: '#gt-' + id, scroll: false })
    const where = await page.evaluate((cid) => {
      const real = document.querySelector('.ext-glowthread')!
      const active = document.activeElement as HTMLElement
      const portals = Array.from(document.querySelectorAll('[id="glowthread-portal"]'))
      return {
        realInPageText: !!real.closest('.mw-parser-output, h1'),
        decoysEmpty: Array.from(document.querySelectorAll('[id="glowthread"]')).filter((d) => d !== real).map((d) => d.childElementCount),
        focusInApp: !!active.closest('.gt-root') && active.id === 'gt-' + cid,
        overlaysOnBody: portals.filter((p) => p.classList.contains('gt-portal')).map((p) => p.parentNode === document.body),
        decoyPortalUnused: portals.filter((p) => p.closest('.mw-parser-output')).every((p) => !p.classList.contains('gt-portal')),
        sectionName: real.querySelector('.glowthread')!.getAttribute('aria-label'),
      }
    }, id)
    expect(where).toEqual({ realInPageText: false, decoysEmpty: [0, 0], focusInApp: true, overlaysOnBody: [true], decoyPortalUnused: true, sectionName: 'Comments' })
  })

  test('pages with comments cannot be framed for logged-in readers: views and diffs', async ({ wiki }) => {
    test.skip(wiki.phone, 'headers do not depend on the layout')
    // The comment section's buttons act in one click, so a hostile page must not frame them
    // over a decoy. Logged-out views stay frameable, as core leaves them (the CDN shares them).
    const frameOptions = async (s: Session, path: string) => {
      const res = await fetch(WIKI + path, { headers: { Cookie: s.cookieHeader(), 'X-Forwarded-For': s.ip }, redirect: 'manual' })
      expect(res.status, path).toBe(200)
      return res.headers.get('x-frame-options')
    }
    const member = await wiki.api('Alice')
    const reader = new Session()
    const title = encodeURIComponent(PAGES.showcase.replace(/ /g, '_'))
    const diffOf = async (page: string) => {
      const r = await member.api({ action: 'query', prop: 'revisions', titles: page, rvprop: 'ids' })
      return '/index.php?title=' + encodeURIComponent(page.replace(/ /g, '_')) + '&diff=' + r.query.pages[0].revisions[0].revid
    }
    const diff = await diffOf(PAGES.showcase)
    expect(await frameOptions(member, '/wiki/' + title)).toBe('DENY')
    expect(await frameOptions(member, diff)).toBe('DENY')
    // Also a diff without the article below it.
    expect(await frameOptions(member, diff + '&diffonly=1')).toBe('DENY')
    expect(await frameOptions(reader, '/wiki/' + title)).toBeNull()
    expect(await frameOptions(reader, diff)).toBeNull()
    // A page without the comment section keeps core's behaviour.
    expect(await frameOptions(member, '/wiki/Main_Page')).toBeNull()
    expect(await frameOptions(member, (await diffOf('Main Page')) + '&diffonly=1')).toBeNull()
  })

  test('switching views drops the Select mode selection', async ({ wiki }) => {
    test.skip(wiki.phone, 'one layout is enough')
    const id = await postComment(wiki, 'Admin', PAGES.play, unique('Selection test'))
    await wiki.as('Moderator')
    await wiki.open(PAGES.play)
    await wiki.section.getByRole('tab', { name: 'Newest' }).click()
    await wiki.section.getByRole('button', { name: 'Select', exact: true }).click()
    await wiki.section.locator('article[data-comment="' + id + '"]').getByRole('checkbox').first().click()
    const bar = wiki.section.getByRole('toolbar', { name: 'Bulk actions' })
    await expect(bar).toContainText('1 selected')
    await wiki.section.getByRole('tab', { name: 'Top' }).click()
    await expect(bar).toContainText('0 selected')
  })

  test('restoring a selection with a comment deleted while held says it goes back to review', async ({ wiki }) => {
    test.skip(wiki.phone, 'one layout is enough')
    // Held for its blacklisted link, then deleted without being approved.
    const id = await postComment(wiki, 'Alice', PAGES.play, unique('Was held') + ' https://spam.example/promo')
    const admin = await wiki.api('Admin')
    await admin.write({ action: 'glowthreadmoderate', ids: String(id), do: 'delete' })
    await wiki.as('Moderator')
    await wiki.open(PAGES.play)
    await wiki.section.getByRole('tab', { name: 'Newest' }).click()
    await wiki.section.getByRole('button', { name: 'Select', exact: true }).click()
    const bar = wiki.section.getByRole('toolbar', { name: 'Bulk actions' })
    await expect(bar.getByRole('button', { name: 'Restore', exact: true })).toBeVisible()
    await wiki.section.locator('article[data-comment="' + id + '"]').getByRole('checkbox').first().click()
    await expect(bar.getByRole('button', { name: 'Restore for review' })).toBeVisible()
  })
})
