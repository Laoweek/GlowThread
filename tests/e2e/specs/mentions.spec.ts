import { test, expect, PAGES, postComment, unique } from './fixtures'
import type { Wiki } from './fixtures'
import type { Page } from '@playwright/test'
import { Session, createAccount } from '../lib/wiki.mjs'

// @mentions are picked, never guessed (docs/api.md, "Input rules"): only a name picked from the
// composer's suggestions is a mention, stored as <@ID>. Typed, pasted or edited text is plain
// text. Tests write on the sandbox page as different members, so none of them reaches the
// posting rate limit (6 a minute), and check what was stored through the REST API.
// Tests that wait for notifications mention new accounts: one author notifies one user at most
// 3 times an hour (NotificationHooks::MAX_PER_RECIPIENT), so the dev accounts would run out.

const list = (page: Page) => page.getByRole('listbox', { name: 'People you can mention' })
const box = (wiki: Wiki) => wiki.section.getByRole('textbox')
/** The underlines drawn behind the composer's text, one per mention. */
const marks = (wiki: Wiki) => wiki.section.locator('.ext-glowthread-composer mark')

/** User IDs by name. */
async function userIds(wiki: Wiki, names: string[]): Promise<Record<string, number>> {
  const r = await (await wiki.api('Admin')).api({ action: 'query', list: 'users', ususers: names.join('|') })
  return Object.fromEntries(r.query.users.map((u: { name: string, userid: number }) => [u.name, u.userid]))
}

const token = (id: number) => '<@' + id + '>'

/** A comment's stored text, rebuilt from its segments: a mention segment with an ID is a token. */
async function storedText(wiki: Wiki, id: number): Promise<string> {
  const r = await (await wiki.api('Admin')).rest('/comment/' + id)
  return r.comment.segments.map((s: { type: string, text: string, id?: number }) => (s.type === 'mention' && s.id ? token(s.id) : s.text)).join('')
}

/** Echo mention notifications of the account of session `s` that open comment `id`. */
async function mentionNotices(s: Session, id: number): Promise<number> {
  const r = await s.api({ action: 'query', meta: 'notifications', notformat: 'model', notlimit: 50, notsections: 'alert|message', notprop: 'list' })
  return r.query.notifications.list.filter((n: any) => n.type === 'glowthread-mention' && new RegExp('GlowThreadLink/' + id + '(\\D|$)').test(n['*'].links.primary.url)).length
}

/** Types text that ends in an @query, and waits for the suggestions from the wiki's user list. */
async function typeQuery(page: Page, text: string) {
  const users = page.waitForResponse((r) => /[?&]list=allusers/.test(r.url()))
  await page.keyboard.type(text)
  await users
}

/** Picks `name` from the open suggestions with the arrow keys and Enter, and returns the presses. */
async function pickWithKeys(page: Page, name: string): Promise<number> {
  const option = list(page).getByRole('option', { name, exact: true })
  await expect(option).toBeVisible()
  let presses = 0
  for (; presses < 8 && (await option.getAttribute('aria-selected')) !== 'true'; presses++) await page.keyboard.press('ArrowDown')
  await expect(option).toHaveAttribute('aria-selected', 'true')
  await page.keyboard.press('Enter')
  await expect(list(page)).toHaveCount(0)
  return presses
}

/** Puts the caret at the end of the text (End does not move it on macOS). */
async function caretToEnd(wiki: Wiki) {
  await box(wiki).evaluate((el: HTMLTextAreaElement) => el.setSelectionRange(el.value.length, el.value.length))
}

/** Sends the composer's text and returns the new comment's ID. */
async function send(wiki: Wiki, text: string): Promise<number> {
  await wiki.section.getByRole('button', { name: 'Send' }).click()
  const article = wiki.section.locator('ol > li article', { hasText: text })
  await expect(article).toHaveCount(1)
  return Number(await article.getAttribute('data-comment'))
}

const posted = (wiki: Wiki, id: number) => wiki.section.locator('article[data-comment="' + id + '"]')

test.describe('@mentions in the composer', () => {
  test('a name picked with the arrow keys and Enter, or with a click, is a mention', async ({ wiki, page }) => {
    // Two new accounts that share a start nobody else has.
    const start = 'Mt' + Math.random().toString(36).slice(2, 6)
    const accounts = [await createAccount(start), await createAccount(start)]
    await wiki.as('Alice')
    await wiki.open(PAGES.play)
    await box(wiki).click()
    const text = unique('Picked')
    await page.keyboard.type(text + ' ')
    // That start offers both: the arrow keys move from the first to the second.
    await typeQuery(page, '@' + start)
    await expect(list(page).getByRole('option')).toHaveCount(2)
    // The keys pick the one that is not highlighted yet, a click the other.
    const active = await list(page).getByRole('option', { name: accounts[0].name, exact: true }).getAttribute('aria-selected')
    const [one, two] = active === 'true' ? [accounts[1], accounts[0]] : accounts
    expect(await pickWithKeys(page, one.name)).toBeGreaterThan(0)
    await page.keyboard.type('and ')
    await typeQuery(page, '@' + two.name)
    await list(page).getByRole('option', { name: two.name, exact: true }).click()
    await expect(list(page)).toHaveCount(0)
    await expect(box(wiki)).toHaveValue(text + ' @' + one.name + ' and @' + two.name + ' ')
    await expect(box(wiki)).toBeFocused()
    // Both are underlined.
    await expect(marks(wiki)).toHaveText(['@' + one.name, '@' + two.name])
    await expect(marks(wiki).first()).toHaveCSS('box-shadow', /inset/)
    const id = await send(wiki, text)
    const article = posted(wiki, id)
    await expect(article.locator('p a', { hasText: '@' + one.name })).toHaveAttribute('href', new RegExp('User:' + one.name + '$'))
    await expect(article.locator('p a', { hasText: '@' + two.name })).toHaveAttribute('href', new RegExp('User:' + two.name + '$'))
    expect(await storedText(wiki, id)).toBe(text + ' ' + token(one.id) + ' and ' + token(two.id))
    // Each gets a notification for this comment (Echo runs from the job queue).
    await expect.poll(() => mentionNotices(one.session, id), { timeout: 30_000 }).toBe(1)
    await expect.poll(() => mentionNotices(two.session, id), { timeout: 30_000 }).toBe(1)
  })

  test('a name typed in full but not picked stays plain text: no link, no notification', async ({ wiki, page }) => {
    const typed = await createAccount('Mt')
    const picked = await createAccount('Mt')
    await wiki.as('Dave')
    await wiki.open(PAGES.play)
    await box(wiki).click()
    const text = unique('Typed')
    // An existing account, its name typed in full. No name starts with "name,", so the
    // suggestions close and nothing is picked.
    await page.keyboard.type(text + ' @' + typed.name + ',')
    await expect(list(page)).toHaveCount(0)
    await page.keyboard.type(' ')
    // Another, picked in the same comment: its notification shows that the comment's went out.
    await typeQuery(page, '@' + picked.name)
    await pickWithKeys(page, picked.name)
    await expect(marks(wiki)).toHaveText(['@' + picked.name])
    const id = await send(wiki, text)
    const article = posted(wiki, id)
    await expect(article).toContainText(text + ' @' + typed.name + ', @' + picked.name)
    await expect(article.locator('a', { hasText: '@' + typed.name })).toHaveCount(0)
    await expect(article.locator('p a', { hasText: '@' + picked.name })).toHaveCount(1)
    expect(await storedText(wiki, id)).toBe(text + ' @' + typed.name + ', ' + token(picked.id))
    await expect.poll(() => mentionNotices(picked.session, id), { timeout: 30_000 }).toBe(1)
    expect(await mentionNotices(typed.session, id)).toBe(0)
  })

  /** Types `text`, a space and a picked @Bob, then removes the mention with Backspace. */
  async function pickAndRemove(wiki: Wiki, page: Page, text: string) {
    await box(wiki).click()
    await page.keyboard.type(text + ' ')
    await typeQuery(page, '@Bo')
    await pickWithKeys(page, 'Bob')
    await expect(box(wiki)).toHaveValue(text + ' @Bob ')
    // The first Backspace takes the space after it, the second the whole mention.
    await page.keyboard.press('Backspace')
    await expect(box(wiki)).toHaveValue(text + ' @Bob')
    await expect(marks(wiki)).toHaveText(['@Bob'])
    await page.keyboard.press('Backspace')
    await expect(box(wiki)).toHaveValue(text + ' ')
    await expect(marks(wiki)).toHaveCount(0)
  }

  test('Backspace at the end of a mention removes it whole', async ({ wiki, page }) => {
    await wiki.as('Carol')
    await wiki.open(PAGES.play)
    const text = unique('Backspace')
    await pickAndRemove(wiki, page, text)
    await page.keyboard.type('and more')
    const id = await send(wiki, text)
    expect(await storedText(wiki, id)).toBe(text + ' and more')
  })

  test('Undo after Backspace brings the mention back as a mention', async ({ wiki, page, browserName }) => {
    // WebKit's Undo takes back the whole run of typing, the mention with it, as in any textarea
    // there (one Cmd+Z after typing and a Backspace empties a plain textarea too).
    test.skip(browserName === 'webkit', 'WebKit undoes the whole typing run, not only the deletion')
    const ids = await userIds(wiki, ['Bob'])
    await wiki.as('Carol')
    await wiki.open(PAGES.play)
    const text = unique('Undo')
    await pickAndRemove(wiki, page, text)
    await page.keyboard.press('ControlOrMeta+z')
    await expect(box(wiki)).toHaveValue(text + ' @Bob')
    await expect(marks(wiki)).toHaveText(['@Bob'])
    const id = await send(wiki, text)
    expect(await storedText(wiki, id)).toBe(text + ' ' + token(ids.Bob))
  })

  test('typing inside a mention turns it into plain text', async ({ wiki, page }) => {
    await wiki.as('Trent')
    await wiki.open(PAGES.play)
    await box(wiki).click()
    const text = unique('Inside')
    await page.keyboard.type(text + ' ')
    await typeQuery(page, '@Bo')
    await pickWithKeys(page, 'Bob')
    await expect(marks(wiki)).toHaveCount(1)
    // Into the name: "@Bo|b " and a letter.
    await page.keyboard.press('ArrowLeft')
    await page.keyboard.press('ArrowLeft')
    await page.keyboard.type('x')
    await expect(box(wiki)).toHaveValue(text + ' @Boxb ')
    await expect(marks(wiki)).toHaveCount(0)
    const id = await send(wiki, text)
    await expect(posted(wiki, id).locator('p a')).toHaveCount(0)
    expect(await storedText(wiki, id)).toBe(text + ' @Boxb')
  })

  test('pasted text and a mention cut in half are plain text', async ({ wiki, page }) => {
    const ids = await userIds(wiki, ['Carol'])
    await wiki.as('Erin')
    await wiki.open(PAGES.play)
    await box(wiki).click()
    const text = unique('Paste')
    await page.keyboard.type(text + ' ')
    await typeQuery(page, '@Bo')
    await pickWithKeys(page, 'Bob')
    // Cut "ob" out of "@Bob": the rest is plain text, and so is "ob" pasted back.
    await page.keyboard.press('ArrowLeft')
    await page.keyboard.press('Shift+ArrowLeft')
    await page.keyboard.press('Shift+ArrowLeft')
    await page.keyboard.press('ControlOrMeta+x')
    await expect(box(wiki)).toHaveValue(text + ' @B ')
    await expect(marks(wiki)).toHaveCount(0)
    await page.keyboard.press('ControlOrMeta+v')
    await expect(box(wiki)).toHaveValue(text + ' @Bob ')
    await expect(marks(wiki)).toHaveCount(0)
    // A picked mention, copied and pasted after itself: the copy is plain text.
    await caretToEnd(wiki)
    await typeQuery(page, '@Car')
    await pickWithKeys(page, 'Carol')
    await page.keyboard.press('ArrowLeft')
    for (let i = 0; i < '@Carol'.length; i++) await page.keyboard.press('Shift+ArrowLeft')
    await page.keyboard.press('ControlOrMeta+c')
    await caretToEnd(wiki)
    await page.keyboard.press('ControlOrMeta+v')
    await expect(box(wiki)).toHaveValue(text + ' @Bob @Carol @Carol')
    await expect(marks(wiki)).toHaveText(['@Carol'])
    const id = await send(wiki, text)
    await expect(posted(wiki, id).locator('p a')).toHaveCount(1)
    expect(await storedText(wiki, id)).toBe(text + ' @Bob ' + token(ids.Carol) + ' @Carol')
  })

  test('a Chinese input method composes around mentions and picks a name it composed', async ({ wiki, page, browserName }) => {
    test.skip(browserName !== 'chromium', 'Input.imeSetComposition is a Chrome DevTools Protocol command')
    const ids = await userIds(wiki, ['Bob', '老陈'])
    const cdp = await page.context().newCDPSession(page)
    // What an input method sends: the pinyin as it grows (a composition), then the characters.
    const compose = async (pinyin: string[], commit: string) => {
      for (const p of pinyin) await cdp.send('Input.imeSetComposition', { text: p, selectionStart: p.length, selectionEnd: p.length })
      await cdp.send('Input.insertText', { text: commit })
    }
    await wiki.as('Mallory')
    await wiki.open(PAGES.play)
    await box(wiki).click()
    const text = unique('IME')
    await page.keyboard.type(text + ' ')
    await typeQuery(page, '@Bo')
    await pickWithKeys(page, 'Bob')
    // Composing after a mention leaves it alone.
    await compose(['x', 'xie', 'xiexie'], '谢谢')
    await expect(box(wiki)).toHaveValue(text + ' @Bob 谢谢')
    await expect(marks(wiki)).toHaveText(['@Bob'])
    await page.keyboard.type('@')
    const users = page.waitForResponse((r) => /[?&]list=allusers/.test(r.url()) && /auprefix=%E8%80%81/.test(r.url()))
    await compose(['l', 'la', 'lao'], '老')
    await users
    await pickWithKeys(page, '老陈')
    await expect(box(wiki)).toHaveValue(text + ' @Bob 谢谢@老陈 ')
    await expect(marks(wiki)).toHaveText(['@Bob', '@老陈'])
    const id = await send(wiki, text)
    await expect(posted(wiki, id).locator('p a', { hasText: '@老陈' })).toHaveAttribute('href', /User:(%E8%80%81%E9%99%88|老陈)$/)
    expect(await storedText(wiki, id)).toBe(text + ' ' + token(ids.Bob) + ' 谢谢' + token(ids['老陈']))
  })

  test('a draft keeps its mention through a reload', async ({ wiki, page }) => {
    const ids = await userIds(wiki, ['Bob'])
    await wiki.as('Moderator')
    await wiki.open(PAGES.play)
    await box(wiki).click()
    const text = unique('Draft')
    await page.keyboard.type(text + ' for ')
    await typeQuery(page, '@Bo')
    await pickWithKeys(page, 'Bob')
    await page.keyboard.type('later')
    // Drafts are saved shortly after typing stops.
    await expect.poll(() => page.evaluate(() => Object.keys(localStorage).filter((k) => k.indexOf('glowthread-draft:') === 0).map((k) => localStorage.getItem(k)).join())).toContain(token(ids.Bob) + ' later')
    await wiki.open(PAGES.play)
    await expect(box(wiki)).toHaveValue(text + ' for @Bob later')
    await expect(marks(wiki)).toHaveText(['@Bob'])
    const id = await send(wiki, text)
    expect(await storedText(wiki, id)).toBe(text + ' for ' + token(ids.Bob) + ' later')
  })

  test('near the length limit the count includes mentions as the server counts them', async ({ wiki, page }) => {
    const admin = await wiki.api('Admin')
    const pageid = await wiki.pageId(PAGES.play)
    const max: number = (await admin.rest('/page/' + pageid + '/comments?limit=1')).config.maxLength
    const ids = await userIds(wiki, ['Moderator'])
    // "@Moderator" shows 10 characters, its token counts 4 or so: the limit is on the token.
    const head = unique('Long') + ' '
    const fill = (head + 'word '.repeat(max)).slice(0, max - 1 - token(ids.Moderator).length).replace(/ $/, 's')
    await wiki.as('Admin')
    await wiki.open(PAGES.play)
    await box(wiki).fill(fill)
    await caretToEnd(wiki)
    await typeQuery(page, ' @Mod')
    await pickWithKeys(page, 'Moderator')
    const count = wiki.section.locator('.ext-glowthread-composer-toolbar .gt-num')
    const sendButton = wiki.section.getByRole('button', { name: 'Send' })
    const n = (v: number) => v.toLocaleString('en-US')
    // The space after a picked name is trimmed, as on the server.
    await expect(count).toHaveText(n(max) + ' / ' + n(max))
    await expect(sendButton).toBeEnabled()
    // One more word is too long here, and the server says so too.
    await page.keyboard.type('y')
    await expect(count).toHaveText(n(max + 2) + ' / ' + n(max))
    await expect(sendButton).toBeDisabled()
    await expect(wiki.section.getByText('A comment can be at most ' + n(max) + ' characters long.')).toBeVisible()
    const r = await admin.write({ action: 'glowthreadpost', pageid, text: fill + ' ' + token(ids.Moderator) + ' y' })
    const refusal = r.error || r.errors[0]
    expect(refusal.code).toBe('toolong')
    expect(refusal.info || refusal.text).toContain('The limit is ' + n(max) + ' characters')
    await page.keyboard.press('Backspace')
    await expect(count).toHaveText(n(max) + ' / ' + n(max))
    const id = await send(wiki, head.trim())
    expect(Array.from(await storedText(wiki, id)).length).toBe(max)
  })

  test('the underline stays on its mention in a long text that scrolls', async ({ wiki, page }) => {
    await wiki.as('Moderator')
    await wiki.open(PAGES.play)
    const lines = Array.from({ length: 30 }, (_, i) => 'Line ' + (i + 1) + ' of a long comment that wraps on a narrow screen')
    await box(wiki).fill(lines.join('\n') + '\nThanks ')
    await caretToEnd(wiki)
    await typeQuery(page, '@Bo')
    await pickWithKeys(page, 'Bob')
    await page.keyboard.type('for reading')
    /** Where the mark is, and where the same text would be in the textarea (a copy laid out like it). */
    const where = () => page.evaluate(() => {
      const ta = document.querySelector('.ext-glowthread-composer textarea') as HTMLTextAreaElement
      const mirror = document.querySelector('.ext-glowthread-composer mark')!.parentElement as HTMLElement
      const mark = mirror.querySelector('mark')!.getBoundingClientRect()
      const box = ta.getBoundingClientRect()
      const style = getComputedStyle(ta)
      const copy = document.createElement('div')
      for (const p of ['font', 'letterSpacing', 'wordSpacing', 'lineHeight', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'tabSize', 'textTransform']) (copy.style as any)[p] = (style as any)[p]
      copy.style.cssText += ';position:absolute;visibility:hidden;white-space:pre-wrap;overflow-wrap:break-word;box-sizing:border-box;border:0'
      copy.style.width = ta.clientWidth + 'px'
      const at = ta.value.indexOf('@Bob')
      const probe = document.createElement('span')
      probe.textContent = '@Bob'
      copy.append(ta.value.slice(0, at), probe, ta.value.slice(at + 4))
      document.body.append(copy)
      const top = probe.offsetTop - ta.scrollTop
      const left = probe.offsetLeft
      copy.remove()
      return {
        scrolled: ta.scrollTop,
        mirrorScrolled: mirror.scrollTop,
        heights: [mirror.scrollHeight, ta.scrollHeight],
        widths: [mirror.clientWidth, ta.clientWidth],
        offset: [mark.top - box.top - ta.clientTop, mark.left - box.left - ta.clientLeft],
        expected: [top, left],
        visible: mark.top >= box.top && mark.bottom <= box.bottom,
      }
    })
    const near = (a: number[], b: number[]) => a.every((v, i) => Math.abs(v - b[i]) <= 2)
    let w = await where()
    // The textarea scrolled to the caret at the end, and the highlight with it.
    expect(w.scrolled).toBeGreaterThan(0)
    expect(w.mirrorScrolled).toBe(w.scrolled)
    expect(near(w.heights, [w.heights[1], w.heights[1]]), JSON.stringify(w)).toBe(true)
    expect(w.widths[0]).toBe(w.widths[1])
    expect(w.visible).toBe(true)
    expect(near(w.offset, w.expected), JSON.stringify(w)).toBe(true)
    // Scrolled back to the top, the mention is out of sight, and so is its underline.
    await box(wiki).evaluate((ta) => { ta.scrollTop = 0 })
    await expect.poll(async () => (await where()).mirrorScrolled).toBe(0)
    w = await where()
    expect(w.visible).toBe(false)
    expect(near(w.offset, w.expected), JSON.stringify(w)).toBe(true)
    await box(wiki).fill('')
  })
})

test.describe('@mentions when reading', () => {
  test('a mention of a user hidden by a block shows a placeholder, and the page never has the name', async ({ wiki, page, browserName }) => {
    // Each run hides one more account: once per run is enough.
    test.skip(wiki.phone || browserName === 'webkit', 'one layout is enough')
    // A new account, mentioned, then blocked with its name hidden. Hiding needs the suppress
    // group: Admin (a bureaucrat) takes it for this step only.
    const { name, id: userId } = await createAccount('Hidden')
    const text = unique('Hidden mention')
    const id = await postComment(wiki, 'Admin', PAGES.play, text + ' ' + token(userId) + ' thanks')
    const admin = await wiki.api('Admin')
    const rights = async (change: Record<string, string>) => {
      const r = await admin.api({ action: 'userrights', user: 'Admin', reason: 'e2e: hide a test account', ...change, token: await admin.token('userrights') }, { post: true })
      if (!r.userrights) throw new Error('userrights: ' + JSON.stringify(r))
    }
    await rights({ add: 'suppress', expiry: '10 minutes' })
    try {
      const b = await admin.write({ action: 'block', user: name, expiry: 'infinite', hidename: '1', reason: 'e2e: a hidden user' })
      expect(b.block, JSON.stringify(b)).toBeTruthy()
    } finally {
      await rights({ remove: 'suppress' })
    }
    for (const reader of [null, 'Alice']) {
      if (reader) await wiki.as(reader)
      await wiki.open(PAGES.play)
      await wiki.section.getByRole('tab', { name: 'Newest' }).click()
      const article = posted(wiki, id)
      await expect(article).toContainText(text + ' @… thanks')
      await expect(article.locator('p a')).toHaveCount(0)
      expect(await page.content()).not.toContain(name)
      const s = reader ? await wiki.api(reader) : new Session()
      expect(JSON.stringify(await s.rest('/comment/' + id))).not.toContain(name)
    }
  })
})
