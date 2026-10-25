// Helpers for the moderation hub specs (Special:GlowThread). Tests post and report their own
// comments on the sandbox page, each with a unique token, and filter the queue by that token,
// so they never depend on what else is in the dev wiki's queue (the seed puts held, reported
// and deleted comments there).
import type { Page } from '@playwright/test'
import { expect, postComment, PAGES, toast } from './fixtures'
import type { Wiki } from './fixtures'

export { toast }

export const SANDBOX = PAGES.play
/** The special page by its canonical English name. */
export const HUB = 'Special:GlowThread'

export const token = (prefix: string) => prefix + Date.now().toString(36) + Math.random().toString(36).slice(2, 5)

export async function openHub(wiki: Wiki, sub = 'queue', query: Record<string, string> = {}) {
  const qs = new URLSearchParams({ title: HUB + '/' + sub, ...query, useskin: wiki.skin })
  await wiki.page.goto('/index.php?' + qs.toString())
  await wiki.page.waitForSelector('#ext-glowthread-admin .gt-root', { timeout: 20_000 })
  await wiki.page.waitForFunction(() => !document.querySelector('#ext-glowthread-admin .gt-pulse'), null, { timeout: 20_000 })
}

export const hub = (page: Page) => page.locator('#ext-glowthread-admin')
export const feedItems = (page: Page) => page.locator('#ext-glowthread-admin [role="feed"] > article')
export const item = (page: Page, id: number) => page.locator('#gt-q-' + id)

/**
 * A comment posted for the test, optionally reported. Admin and Moderator have no rate limits,
 * so they write and report by default: regular accounts may post only 6 times a minute.
 * A regular account may have only 3 held comments (toomanyheld), so its held sandbox comments
 * that an interrupted run left behind are deleted first (tests run one at a time).
 */
export async function seedComment(wiki: Wiki, text: string, { author = 'Admin', reporters = [] as string[], reason = 'spam' } = {}) {
  if (author !== 'Admin' && author !== 'Moderator') {
    const admin = await wiki.api('Admin')
    const left = await admin.rest('/moderation/queue?tab=held&limit=50&user=' + encodeURIComponent(author) + '&pageid=' + (await wiki.pageId(SANDBOX)))
    const ids = left.items.map((i: { comment: { id: number } }) => i.comment.id)
    if (ids.length) await moderate(wiki, ids, 'delete')
  }
  const id = await postComment(wiki, author, SANDBOX, text)
  for (const user of reporters) {
    const s = await wiki.api(user)
    const r = await s.write({ action: 'glowthreadreport', id, reason })
    if (!r.glowthreadreport) throw new Error('report: ' + JSON.stringify(r))
  }
  return id
}

export async function moderate(wiki: Wiki, ids: number[], action: string) {
  const s = await wiki.api('Admin')
  const r = await s.write({ action: 'glowthreadmoderate', ids: ids.join('|'), do: action })
  if (!r.glowthreadmoderate) throw new Error('moderate: ' + JSON.stringify(r))
}

export async function statusOf(wiki: Wiki, id: number): Promise<string> {
  const s = await wiki.api('Admin')
  const r = await s.rest('/comment/' + id)
  return r.comment.status
}

/** The id of the focused element, to follow keyboard focus. */
export const activeId = (page: Page) => page.evaluate(() => (document.activeElement ? document.activeElement.id : ''))

export async function expectFocusOn(page: Page, id: number) {
  await expect.poll(() => activeId(page)).toBe('gt-q-' + id)
}
