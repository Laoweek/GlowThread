import { describe, expect, it } from 'vitest'
import { linkLabel, plainText, safeHref, toParts } from '@/lib/segments'
import type { Segment } from '@/lib/segments'

const userUrl = (n: string) => '/wiki/User:' + n

describe('toParts', () => {
  it('keeps text, links and mentions in order', () => {
    const segs: Segment[] = [
      { type: 'text', text: 'see ' },
      { type: 'link', text: 'https://example.com/a', href: 'https://example.com/a' },
      { type: 'text', text: ' and ' },
      { type: 'mention', text: '@Bob', user: 'Bob', exists: true },
      { type: 'mention', text: '@Nobody', user: 'Nobody', exists: false },
    ]
    expect(toParts(segs, userUrl)).toEqual([
      { kind: 'text', text: 'see ' },
      { kind: 'link', text: 'example.com/a', href: 'https://example.com/a' },
      { kind: 'text', text: ' and ' },
      { kind: 'mention', text: '@Bob', href: '/wiki/User:Bob' },
      { kind: 'mention', text: '@Nobody', href: null },
    ])
  })
  it('turns a non-http link into plain text', () => {
    const parts = toParts([{ type: 'link', text: 'javascript:alert(1)', href: 'javascript:alert(1)' }], userUrl)
    expect(parts).toEqual([{ kind: 'text', text: 'javascript:alert(1)' }])
    expect(safeHref('data:text/html,x')).toBeNull()
    expect(safeHref('HTTPS://EXAMPLE.COM')).toBe('HTTPS://EXAMPLE.COM')
  })
  it('skips unknown segment types and copes with no segments', () => {
    expect(toParts([{ type: 'image', text: 'x' } as unknown as Segment], userUrl)).toEqual([])
    expect(toParts(undefined as unknown as Segment[], userUrl)).toEqual([])
  })
})

describe('linkLabel', () => {
  it('drops the scheme and shortens long paths in the middle, keeping the host', () => {
    expect(linkLabel('http://example.net/x')).toBe('example.net/x')
    expect(linkLabel('https://example.com/')).toBe('example.com')
    const label = linkLabel('https://example.com/' + 'a'.repeat(100) + 'END')
    expect(Array.from(label).length).toBe(60)
    expect(label.startsWith('example.com/aaa')).toBe(true)
    expect(label).toContain('…')
    expect(label.endsWith('END')).toBe(true)
  })

  it('always shows the real host, never a user@ part posing as one', () => {
    expect(linkLabel('https://wiki.example.org.official-login.verify.account.example.org.aaaaaaaaaaaaa@evil.example/steal')).toBe('evil.example/steal')
    expect(linkLabel('https://good.example@evil.example/')).toBe('evil.example')
    const longHost = 'https://' + 'sub.'.repeat(20) + 'evil.example/x'
    expect(linkLabel(longHost).startsWith('sub.'.repeat(20) + 'evil.example')).toBe(true)
    // Internationalized hosts show as the browser resolves them (punycode), not look-alikes.
    expect(linkLabel('https://ехаmple.com/')).toMatch(/^xn--[a-z0-9-]+\.com$/)
  })

  it('shows CJK paths readably', () => {
    // Percent-encoded CJK page titles decode to readable text in the label.
    expect(linkLabel('https://wiki.example.org/wiki/%E9%BA%BB%E5%A9%86%E8%B1%86%E8%85%90')).toBe('wiki.example.org/wiki/麻婆豆腐')
  })

  it('keeps line breaks, bidi controls and invisible characters encoded, so the label cannot fake an address', () => {
    // A line break would show the second address as a separate, trusted link.
    expect(linkLabel('https://evil.example/a%0A%0Ahttps://www.example.com/official')).toBe('evil.example/a%0A%0Ahttps://www.example.com/official')
    // A right-to-left override would show evil.example/exe.txt.
    expect(linkLabel('https://evil.example/%E2%80%AEtxt.exe')).toBe('evil.example/%E2%80%AEtxt.exe')
    expect(linkLabel('https://evil.example/%E2%80%8B%E2%80%8Bzw')).toBe('evil.example/%E2%80%8B%E2%80%8Bzw')
    expect(linkLabel('https://evil.example/%00nul')).toBe('evil.example/%00nul')
    // A plain space (the label would wrap there, and the rest would start a line of its own),
    // tab, CR, NEL, no-break and ideographic spaces, line separator, soft hyphen, word joiner,
    // BOM, language tag, object replacement, Hangul filler, Braille blank.
    for (const esc of ['%20', '%09', '%0D', '%C2%85', '%C2%A0', '%E3%80%80', '%E2%80%A8', '%C2%AD', '%E2%81%A0',
      '%EF%BB%BF', '%F3%A0%80%81', '%EF%BF%BC', '%E3%85%A4', '%E2%A0%80']) {
      expect(linkLabel('https://evil.example/a' + esc + 'b')).toBe('evil.example/a' + esc + 'b')
    }
    // Everything else still decodes, in the same label: letters, the query and fragment.
    expect(linkLabel('https://wiki.example.org/wiki/Cr%C3%A8me%20br%C3%BBl%C3%A9e%E2%80%AE?q=%E9%BA%BB#%E2%80%8Bx'))
      .toBe('wiki.example.org/wiki/Crème%20brûlée%E2%80%AE?q=麻#%E2%80%8Bx')
  })

  it('keeps the joiners and variation selectors that scripts and emoji need readable', () => {
    // Persian "Wikipedia" has a zero-width non-joiner, a red heart a variation selector.
    expect(linkLabel('https://fa.wikipedia.org/wiki/%D9%88%DB%8C%DA%A9%DB%8C%E2%80%8C%D9%BE%D8%AF%DB%8C%D8%A7'))
      .toBe('fa.wikipedia.org/wiki/\u0648\u06CC\u06A9\u06CC\u200C\u067E\u062F\u06CC\u0627')
    expect(linkLabel('https://example.com/%E2%9D%A4%EF%B8%8F')).toBe('example.com/\u2764\uFE0F')
  })

  it('also hides invisible characters of an address the browser cannot parse', () => {
    expect(linkLabel('https://a^b.example/x\u3164\u2800y')).toBe('https://a^b.example/x%E3%85%A4%E2%A0%80y')
  })
})

describe('plainText', () => {
  it('joins the text of all segments', () => {
    expect(plainText([{ type: 'text', text: 'a' }, { type: 'mention', text: '@B', user: 'B', exists: true }])).toBe('a@B')
  })
})
