import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import { exactTime, relativeTime } from '@/lib/time'
import { setLang } from './setup'

// Local times, so the calendar-day rules do not depend on the machine's zone.
const at = (y: number, mo: number, d: number, h = 12, mi = 0) => new Date(y, mo - 1, d, h, mi).getTime()
const iso = (ms: number) => new Date(ms).toISOString()

describe('relativeTime', () => {
  const now = at(2026, 10, 8, 15, 30)
  it('uses minutes and hours for the last day', () => {
    expect(relativeTime(iso(now - 20 * 1000), now)).toBe('just now')
    expect(relativeTime(iso(now - 5 * 60000), now)).toBe('5 minutes ago')
    expect(relativeTime(iso(now - 59 * 60000), now)).toBe('59 minutes ago')
    expect(relativeTime(iso(now - 2 * 3600000), now)).toBe('2 hours ago')
    expect(relativeTime(iso(now - 23 * 3600000), now)).toBe('23 hours ago')
  })
  it('says yesterday by calendar day, then days, then dates', () => {
    expect(relativeTime(iso(at(2026, 10, 7, 9)), now)).toBe('yesterday')
    expect(relativeTime(iso(at(2026, 10, 5, 9)), now)).toBe('3 days ago')
    expect(relativeTime(iso(at(2026, 10, 2, 9)), now)).toBe('6 days ago')
    expect(relativeTime(iso(at(2026, 10, 1, 9)), now)).toBe('Oct 1')
    expect(relativeTime(iso(at(2026, 9, 1, 9)), now)).toBe('Sep 1')
    expect(relativeTime(iso(at(2025, 12, 31, 9)), now)).toBe('Dec 31, 2025')
  })
  it('treats clock skew as just now and ignores bad input', () => {
    expect(relativeTime(iso(now + 60000), now)).toBe('just now')
    expect(relativeTime('not a date', now)).toBe('')
  })
  it('uses plural forms in English', () => {
    expect(relativeTime(iso(now - 60000), now)).toBe('1 minute ago')
    expect(relativeTime(iso(now - 3 * 3600000), now)).toBe('3 hours ago')
  })
  it('writes dates with the digits MediaWiki uses for the language', () => {
    const language = (globalThis as any).mw.language
    const convert = language.convertNumber
    language.convertNumber = (n: number) => '٠١٢٣٤٥٦٧٨٩'.charAt(n) || String(n)
    try {
      expect(relativeTime(iso(at(2025, 12, 31, 9)), now)).toBe('Dec ٣١, ٢٠٢٥')
    } finally {
      language.convertNumber = convert
    }
  })
  // Dates only: these language codes have no message file in the tests.
  it('turns MediaWiki language codes into BCP 47 tags for dates', () => {
    setLang('sr-el')
    try {
      // sr-el is Serbian in Latin script: sr alone would give Cyrillic month names.
      expect(relativeTime(iso(at(2026, 9, 1, 9)), now)).toMatch(/^[^\u0400-\u04ff]+$/)
    } finally {
      setLang('en')
    }
  })
  it('uses the Gregorian calendar in every language, like the exact time', () => {
    // fa would default to the Persian calendar (year 1404).
    setLang('fa')
    try {
      expect(relativeTime(iso(at(2025, 12, 31, 9)), now)).toContain('2025')
    } finally {
      setLang('en')
    }
  })
  it('falls back to the browser language for a code that is not a valid tag', () => {
    setLang('zh-min-nan')
    try {
      const expected = new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric' }).format(new Date(at(2026, 9, 1, 9)))
      expect(relativeTime(iso(at(2026, 9, 1, 9)), now)).toBe(expected)
    } finally {
      setLang('en')
    }
  })
})

// zh-hans messages: no spaces, no plural forms, and Chinese short dates.
describe('relativeTime in Chinese', () => {
  const now = at(2026, 10, 8, 15, 30)
  beforeAll(() => setLang('zh-hans'))
  afterAll(() => setLang('en'))
  it('uses minutes and hours for the last day', () => {
    expect(relativeTime(iso(now - 20 * 1000), now)).toBe('刚刚')
    expect(relativeTime(iso(now - 5 * 60000), now)).toBe('5分钟前')
    expect(relativeTime(iso(now - 59 * 60000), now)).toBe('59分钟前')
    expect(relativeTime(iso(now - 2 * 3600000), now)).toBe('2小时前')
    expect(relativeTime(iso(now - 23 * 3600000), now)).toBe('23小时前')
  })
  it('says yesterday by calendar day, then days, then dates', () => {
    expect(relativeTime(iso(at(2026, 10, 7, 9)), now)).toBe('昨天')
    expect(relativeTime(iso(at(2026, 10, 5, 9)), now)).toBe('3天前')
    expect(relativeTime(iso(at(2026, 10, 2, 9)), now)).toBe('6天前')
    expect(relativeTime(iso(at(2026, 10, 1, 9)), now)).toBe('10月1日')
    expect(relativeTime(iso(at(2026, 9, 1, 9)), now)).toBe('9月1日')
    expect(relativeTime(iso(at(2025, 12, 31, 9)), now)).toBe('2025年12月31日')
  })
  it('treats clock skew as just now', () => {
    expect(relativeTime(iso(now + 60000), now)).toBe('刚刚')
  })
})

describe('exactTime', () => {
  it('formats local date and time', () => {
    expect(exactTime(iso(at(2026, 1, 2, 3, 4)))).toBe('2026-01-02 03:04')
  })
})
