// Drafts of unsent comments, kept in this browser only: a convenience that may be missing
// (storage blocked, private windows). One per wiki, account and page or comment answered, for a
// week, so a shared device does not show someone else's unsent text for long.

const PREFIX = 'glowthread-draft:'
export const DRAFT_TTL = 7 * 86400000

/**
 * The storage key: `glowthread-draft:<wiki>:<account>:page:<ID>` (or `reply:<ID>`). Wikis on one
 * origin (a path-based farm) keep apart. Logged-out readers have an empty account.
 */
export function draftKey(wiki: string, user: string | null, target: string): string {
  return PREFIX + encodeURIComponent(wiki) + ':' + encodeURIComponent(user || '') + ':' + target
}

/**
 * Removes the drafts this browser keeps for this wiki that are not the reader's: those of other
 * accounts or of logged-out use, once someone else uses the device or the reader logs out. Also
 * expired drafts, and drafts in the old format without a wiki. Other wikis' drafts stay.
 */
export function sweepDrafts(wiki: string, user: string | null, now = Date.now()) {
  try {
    const storage = window.localStorage
    const own = draftKey(wiki, user, '')
    const here = PREFIX + encodeURIComponent(wiki) + ':'
    const drop: string[] = []
    for (let i = 0; i < storage.length; i++) {
      const k = storage.key(i)
      if (!k || k.indexOf(PREFIX) !== 0) continue
      // <wiki>:<account>:<page or reply>:<ID>; the old format had no wiki.
      if (k.slice(PREFIX.length).split(':').length !== 4) drop.push(k)
      else if (k.indexOf(here) === 0 && (k.indexOf(own) !== 0 || expired(storage.getItem(k), now))) drop.push(k)
    }
    drop.forEach((k) => storage.removeItem(k))
  } catch {
    // storage blocked: there are no drafts either
  }
}

function expired(value: string | null, now: number): boolean {
  try {
    const d = JSON.parse(value || 'null')
    return !(d && typeof d.at === 'number' && now - d.at < DRAFT_TTL)
  } catch {
    return true
  }
}
