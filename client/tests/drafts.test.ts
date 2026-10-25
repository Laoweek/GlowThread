import { afterEach, describe, expect, it, vi } from 'vitest'
import { DRAFT_TTL, draftKey, sweepDrafts } from '@/comments/drafts'

const now = Date.parse('2026-10-10T12:00:00Z')
const draft = (text: string, at = now - 60000) => JSON.stringify({ text, at })

describe('drafts', () => {
  afterEach(() => {
    vi.restoreAllMocks()
    window.localStorage.clear()
  })

  it('keeps one draft per wiki, account and page or comment', () => {
    expect(draftKey('wikidb', 'Alice', 'page:19')).toBe('glowthread-draft:wikidb:Alice:page:19')
    expect(draftKey('otherwiki', 'Alice', 'page:19')).not.toBe(draftKey('wikidb', 'Alice', 'page:19'))
    expect(draftKey('wikidb', null, 'reply:5')).toBe('glowthread-draft:wikidb::reply:5')
    expect(draftKey('wikidb', 'Al:ce', 'page:1')).toBe('glowthread-draft:wikidb:Al%3Ace:page:1')
  })

  it('removes the drafts of everyone else who used this browser on this wiki', () => {
    const s = window.localStorage
    s.setItem(draftKey('wikidb', 'Alice', 'page:19'), draft('mine'))
    s.setItem(draftKey('wikidb', 'Alice', 'reply:7'), draft('mine too'))
    s.setItem(draftKey('wikidb', 'Bob', 'page:19'), draft('private text Bob never sent'))
    s.setItem(draftKey('wikidb', 'Al', 'page:19'), draft('a name that starts like Alice'))
    s.setItem(draftKey('wikidb', null, 'page:19'), draft('logged out'))
    // Another wiki on the same origin: its own app decides.
    s.setItem(draftKey('otherwiki', 'Bob', 'page:3'), draft('elsewhere'))
    // The format before drafts were kept per wiki.
    s.setItem('glowthread-draft:Bob:page:19', draft('old format'))
    s.setItem(draftKey('wikidb', 'Alice', 'page:20'), draft('a week old', now - DRAFT_TTL))
    s.setItem('unrelated', 'x')

    sweepDrafts('wikidb', 'Alice', now)
    const left = Object.keys(s).sort()
    expect(left).toEqual([
      'glowthread-draft:otherwiki:Bob:page:3',
      'glowthread-draft:wikidb:Alice:page:19',
      'glowthread-draft:wikidb:Alice:reply:7',
      'unrelated',
    ])
  })

  it('removes every account\'s drafts when the reader logged out', () => {
    window.localStorage.setItem(draftKey('wikidb', 'Alice', 'page:19'), draft('Alice was here'))
    sweepDrafts('wikidb', null, now)
    expect(window.localStorage.length).toBe(0)
  })

  it('does nothing when storage is blocked', () => {
    vi.spyOn(window, 'localStorage', 'get').mockImplementation(() => {
      throw new Error('SecurityError')
    })
    expect(() => sweepDrafts('wikidb', 'Alice', now)).not.toThrow()
  })
})
