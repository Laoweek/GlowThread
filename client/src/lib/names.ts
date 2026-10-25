// User names that look like another name. MediaWiki accepts names with characters that show
// nothing (a soft hyphen, a word joiner, bidi isolates, Hangul fillers…), so "Moderator" with a
// soft hyphen looks exactly like "Moderator". AntiSpoof refuses such names at registration.

/**
 * Characters that show nothing in a name: controls, format characters (soft hyphen, bidi
 * controls, word joiner, tags…), the combining grapheme joiner, Hangul and Khmer fillers,
 * Mongolian variation selectors, the object replacement character, the blank braille pattern
 * and the reserved code points that show nothing. Built at run time: the bundles target ES2017,
 * which has no \p{…}.
 */
const INVISIBLE = new RegExp('[\\p{Cc}\\p{Cf}\\u034F\\u115F\\u1160\\u17B4\\u17B5\\u180B-\\u180F\\u2065\\u2800\\u3164\\uFFA0\\uFFF0-\\uFFFC\\u{E0000}-\\u{E00FF}\\u{E01F0}-\\u{E0FFF}]', 'gu')
// Joiners stay as they are: some scripts and emoji need them.
const JOINERS = '\u200C\u200D'

/**
 * The name for moderators: each character that shows nothing written out as [U+00AD], so a
 * lookalike of another name can be told apart. Names cannot contain [ or ], so the mark
 * cannot be typed into a name. Display only: links and requests keep the real name.
 */
export function revealInvisible(name: string): string {
  return name.replace(INVISIBLE, (c) => (JOINERS.indexOf(c) >= 0 ? c : '[U+' + (c.codePointAt(0) as number).toString(16).toUpperCase().padStart(4, '0') + ']'))
}

/** A name as the viewer sees it: written out for moderators, unchanged for everyone else. */
export function displayName(name: string, moderator: boolean): string {
  return moderator ? revealInvisible(name) : name
}
