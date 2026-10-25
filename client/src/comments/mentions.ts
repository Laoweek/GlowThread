// @mention suggestions. Typing @ and a name opens them, and only a name picked from them is a
// mention: a unit of the composer (units.ts), sent as a token with the user ID. Typed text stays
// plain text.
import { revealInvisible } from '@/lib/names'

// Characters that end the name being typed: whitespace, @ < > [ ] { } | # / :, a double quote,
// curly quotes, CJK punctuation (U+3000 to U+303F) and full-width forms (U+FF00 to U+FFEF).
// Apostrophes, commas and parentheses may belong to a name ("@O'Brien").
const NAME_CHAR = "[^\\s@<>\\[\\]{}|#/:\"\\u201C\\u201D\\u2018\\u2019\\u3000-\\u303F\\uFF00-\\uFFEF]"
const QUERY = new RegExp('(^|[^A-Za-z0-9_./@])@(' + NAME_CHAR + '{0,40})$')

/** The mention being typed just before the caret, if any. */
export function mentionQuery(before: string): { query: string, start: number } | null {
  const m = QUERY.exec(before)
  if (!m) return null
  return { query: m[2], start: before.length - m[2].length - 1 }
}

const fold = (s: string) => s.replace(/_/g, ' ').toLowerCase()

/**
 * Suggestions: people already in this conversation first, then other users, at most 8. Names
 * with characters that show nothing are left out: they look like another name, so the reader
 * could not tell whom they pick.
 */
export function rankCandidates(query: string, participants: string[], others: string[], self: string | null): string[] {
  const q = fold(query)
  const out: string[] = []
  const add = (n: string) => {
    if (n && n !== self && out.indexOf(n) < 0 && fold(n).indexOf(q) === 0 && revealInvisible(n) === n) out.push(n)
  }
  participants.forEach(add)
  others.forEach(add)
  return out.slice(0, 8)
}

/** MediaWiki user names start with a capital letter; prefix search is case-sensitive after it. */
export function userPrefix(query: string): string {
  const q = query.replace(/_/g, ' ')
  return q.charAt(0).toUpperCase() + q.slice(1)
}
