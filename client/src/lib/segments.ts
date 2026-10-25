/**
 * Comment text arrives as segments, never HTML (docs/api.md). This turns them into
 * display parts that templates render with text bindings only. Nothing here returns
 * markup, and no template may use v-html for comment content.
 */
export type Segment =
  | { type: 'text', text: string }
  | { type: 'link', text: string, href: string }
  | { type: 'mention', text: string, user?: string, id?: number, exists: boolean }

export type Part =
  | { kind: 'text', text: string }
  | { kind: 'link', text: string, href: string }
  | { kind: 'mention', text: string, href: string | null }

const MAX_LINK_LABEL = 60

/**
 * Characters that stay percent-encoded in a label: controls, all whitespace (even a plain
 * space, where the label would wrap, so the text after it would start a line of its own), and
 * invisible or direction-changing characters: format and default-ignorable characters (a
 * superset of what the server strips from comment text), U+2800 and U+FFFC. Decoded, they could
 * break the label over lines, reverse part of it or hide characters, so the label would show
 * another address than the link goes to. Zero-width joiner and non-joiner and variation
 * selectors stay readable, as in comment text: Persian and Indic titles and emoji need them.
 */
const HIDDEN_IN_LABEL =
  /(?![\u200C\u200D\uFE00-\uFE0F\u{E0100}-\u{E01EF}])[\p{Cc}\p{Cf}\p{Z}\p{Default_Ignorable_Code_Point}\u2800\uFFFC]/gu

const encodeHidden = (text: string): string => text.replace(HIDDEN_IN_LABEL, (c) => encodeURIComponent(c))

/**
 * The text of a link, built from the address it goes to: the host in full (never cut, and
 * without any `user@` part, which could pose as another host), then the path, shortened in
 * the middle when long. The link itself keeps the full address.
 */
export function linkLabel(url: string): string {
  let u: URL
  try {
    u = new URL(url)
  } catch {
    return encodeHidden(url)
  }
  let rest = u.pathname + u.search + u.hash
  if (rest === '/') rest = ''
  try {
    // Readable paths for non-ASCII titles (/wiki/Cr%C3%A8me… → /wiki/Crème…). Display only.
    rest = encodeHidden(decodeURI(rest))
  } catch {
    // keep it encoded
  }
  const chars = Array.from(rest)
  const budget = Math.max(MAX_LINK_LABEL - Array.from(u.host).length, 16)
  if (chars.length > budget) {
    const head = Math.ceil((budget - 1) * 0.6)
    rest = chars.slice(0, head).join('') + '…' + chars.slice(chars.length - (budget - 1 - head)).join('')
  }
  return u.host + rest
}

/** Defence in depth: the server only sends http(s) links, anything else stays text. */
export function safeHref(href: string): string | null {
  return /^https?:\/\/[^\s]+$/i.test(href) ? href : null
}

export function toParts(segments: Segment[], userUrl: (name: string) => string): Part[] {
  const parts: Part[] = []
  for (const s of segments || []) {
    if (s.type === 'link') {
      const href = safeHref(s.href)
      parts.push(href ? { kind: 'link', text: linkLabel(href), href } : { kind: 'text', text: s.text })
    } else if (s.type === 'mention') {
      parts.push({ kind: 'mention', text: s.text, href: s.exists && s.user ? userUrl(s.user) : null })
    } else if (s.type === 'text') {
      parts.push({ kind: 'text', text: s.text })
    }
  }
  return parts
}

/** Plain text of a comment, for previews and screen reader labels. */
export function plainText(segments: Segment[]): string {
  return (segments || []).map((s) => s.text).join('')
}
