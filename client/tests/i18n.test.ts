import { describe, expect, it } from 'vitest'
import { sentences, tParts } from '@/lib/i18n'
import { LOG_ACTIONS } from '@/admin/format'
import { setLang } from './setup'
import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs'
import path from 'node:path'

// Every message the source asks for exists in every language, and the files agree.
const root = path.resolve('.')
const i18n = path.join(root, '..', 'i18n', 'ui')
const load = (lang: string) => JSON.parse(readFileSync(path.join(i18n, lang + '.json'), 'utf8')) as Record<string, string>
const en = load('en')
const keys = Object.keys(en).filter((k) => k !== '@metadata')

function files(dir: string): string[] {
  return readdirSync(dir).flatMap((f) => {
    const p = path.join(dir, f)
    return statSync(p).isDirectory() ? files(p) : /\.(ts|vue)$/.test(f) ? [p] : []
  })
}
const source = files(path.join(root, 'src')).map((f) => readFileSync(f, 'utf8')).join('\n')

// Keys built at run time: prefix -> the suffixes the code can produce.
const SETTINGS = ['new-account-hours', 'new-account-approved', 'auto-hold-reporters', 'report-reasons', 'max-length', 'max-mentions', 'duplicate-window', 'public-log']
const FAMILIES: Record<string, string[]> = {
  // moderation hub (src/admin/)
  'admin-done-': ['approve', 'delete', 'restore', 'erase', 'pin', 'unpin', 'dismiss'],
  'admin-empty-': ['reported', 'held', 'deleted', 'all', 'filtered', 'page-missing', 'user', 'page'].flatMap((k) => [k, k + '-title']),
  'admin-key-': ['next', 'prev', 'toggle', 'pass', 'delete', 'restore', 'search', 'help', 'escape'],
  'admin-log-': LOG_ACTIONS,
  'admin-rate-': ['glowthread-post', 'glowthread-react', 'glowthread-report', 'glowthread-moderate', 'glowthread-erase', 'glowthread-configure'],
  'admin-rate-group-': ['user', 'newbie', 'ip', 'subnet', 'ip-all', 'subnet-all', 'anon', 'user-global'],
  'admin-record-mode-': ['user', 'page'],
  'admin-setting-': SETTINGS.flatMap((k) => [k, k + '-help']),
  'admin-settings-': ['gate', 'reports', 'limits', 'features'],
  'admin-skip-': ['pass', 'approve', 'delete', 'restore', 'dismiss', 'erase', 'pin', 'unpin'],
  'admin-tab-': ['reported', 'held', 'deleted', 'all'],
  'admin-sort-': ['reports', 'new', 'old'],
  'admin-unit-': ['hours', 'comments', 'people', 'chars', 'seconds'],
  'admin-value-': ['hours', 'comments', 'people', 'chars', 'seconds'],
  // comment section
  'chip-': ['held', 'reported'],
  'emoji-tab-': ['emoji', 'kaomoji'],
  'hold-': ['filter', 'filter-error', 'spamblacklist', 'newaccount', 'reports', 'parentheld'],
  'mod-done-': ['approve', 'delete', 'restore', 'pin', 'unpin', 'dismiss'],
  'page-state-': ['open', 'locked', 'disabled', 'open-help', 'locked-help', 'disabled-help'],
  'page-state-apply-': ['open', 'locked', 'disabled'],
  'page-state-confirm-': ['open', 'locked', 'disabled'],
  'page-state-done-': ['open', 'locked', 'disabled'],
  'queue-empty-': ['held', 'reported'],
  'reason-': ['spam', 'abuse', 'offtopic', 'spoiler', 'misinfo', 'other', 'legacy'],
}

describe('UI messages', () => {
  it('has every key the source uses', () => {
    const used = new Set<string>()
    for (const m of source.matchAll(/\bt(?:Parts)?\('([a-z0-9-]*[a-z0-9])'/g)) used.add(m[1])
    for (const m of source.matchAll(/'(error-[a-z-]+)'/g)) used.add(m[1])
    for (const m of source.matchAll(/\bt\('([a-z-]+-)' \+/g)) {
      expect(FAMILIES[m[1]], 'unknown family ' + m[1]).toBeDefined()
      FAMILIES[m[1]].forEach((s) => used.add(m[1] + s))
    }
    const missing = [...used].filter((k) => !en['glowthread-ui-' + k])
    expect(missing).toEqual([])
  })

  it('uses the glowthread-ui- prefix', () => {
    expect(keys.filter((k) => !k.startsWith('glowthread-ui-'))).toEqual([])
  })

  it.each(['zh-hans', 'zh-hant', 'qqq'])('%s has the same keys as en', (lang) => {
    if (!existsSync(path.join(i18n, lang + '.json'))) return
    const other = load(lang)
    expect(Object.keys(other).filter((k) => k !== '@metadata').sort()).toEqual([...keys].sort())
  })

  it('keeps $n parameters in the translations', () => {
    const params = (s: string) => (s.match(/\$\d/g) || []).sort().filter((x, i, a) => a.indexOf(x) === i).join()
    for (const lang of ['zh-hans', 'zh-hant']) {
      if (!existsSync(path.join(i18n, lang + '.json'))) continue
      const other = load(lang)
      for (const k of keys) expect(params(other[k] || ''), lang + ' ' + k).toBe(params(en[k]))
    }
  })
})

describe('message parts', () => {
  it('splits a message around its parameters, in the translation\'s order', () => {
    expect(tParts('mod-held-because')).toEqual(['Held for review: ', 1])
    expect(tParts('admin-on-page', 2)).toEqual([1, ' on ', 2])
    // Chinese: a full-width colon and no spaces.
    setLang('zh-hans')
    try {
      expect(tParts('popular-line', 2)).toEqual([1, '：', 2])
    } finally {
      setLang('en')
    }
  })

  it('needs no message that relies on spaces at its ends or on text after it', () => {
    // translatewiki.net trims spaces at the ends of messages: &#32; keeps one. A label that ends
    // in a colon is joined to what follows by the template, where word order cannot change: it
    // takes a parameter instead. A colon before a block (a list) is fine.
    const beforeBlock = ['glowthread-ui-admin-settings-review']
    for (const lang of ['en', 'zh-hans', 'zh-hant']) {
      const m = load(lang)
      for (const k of keys) {
        const v = m[k] || ''
        expect(/^\s|\s$/.test(v), lang + ' ' + k).toBe(false)
        if (beforeBlock.indexOf(k) < 0) expect(/[:：]$/.test(v), lang + ' ' + k).toBe(false)
      }
    }
  })
})

describe('sentences', () => {
  it('joins sentences with the language\'s word separator and skips empty ones', () => {
    expect(sentences('Approved 2 comments.', '', 'This cannot be undone here.')).toBe('Approved 2 comments. This cannot be undone here.')
    setLang('zh-hans')
    try {
      expect(sentences('已通过。', '无法撤销。')).toBe('已通过。无法撤销。')
    } finally {
      setLang('en')
    }
  })
})
