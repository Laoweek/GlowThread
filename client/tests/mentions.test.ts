import { describe, expect, it } from 'vitest'
import { mentionQuery, rankCandidates, userPrefix } from '@/comments/mentions'

describe('mentionQuery', () => {
  it('finds the mention being typed', () => {
    expect(mentionQuery('@')).toEqual({ query: '', start: 0 })
    expect(mentionQuery('hi @Al')).toEqual({ query: 'Al', start: 3 })
    // Apostrophes and commas may belong to a name: the server decides.
    expect(mentionQuery("hi @O'B")).toEqual({ query: "O'B", start: 3 })
    expect(mentionQuery('@Bob,')).toEqual({ query: 'Bob,', start: 0 })
    // CJK text has no spaces: a mention starts right after it.
    expect(mentionQuery('谢谢@张三')).toEqual({ query: '张三', start: 2 })
  })
  it('ignores e-mail addresses and finished names', () => {
    expect(mentionQuery('a@example')).toBeNull()
    expect(mentionQuery('@Alice ')).toBeNull()
    // Fullwidth punctuation (CJK input) ends a name like a space.
    expect(mentionQuery('@Alice，')).toBeNull()
    // So do double and curly quotes.
    expect(mentionQuery('@Alice”')).toBeNull()
    expect(mentionQuery('@Alice"')).toBeNull()
    expect(mentionQuery('no mention')).toBeNull()
  })
})

describe('rankCandidates', () => {
  it('puts participants first, removes duplicates and the viewer, caps at 8', () => {
    const r = rankCandidates('b', ['Bob', 'Alice', 'Bea'], ['Bob', 'Boris', 'Bea', 'B1', 'B2', 'B3', 'B4', 'B5', 'B6'], 'Bea')
    expect(r).toEqual(['Bob', 'Boris', 'B1', 'B2', 'B3', 'B4', 'B5', 'B6'])
  })
  it('matches underscores and spaces alike', () => {
    expect(rankCandidates('alice_l', ['Alice Liddell'], [], null)).toEqual(['Alice Liddell'])
  })
  it('offers names with any characters: a picked mention names the user by ID', () => {
    expect(rankCandidates('o', ["O'Brien", 'O/B', 'O"B', 'O’B', 'O，B'], [], null)).toEqual(["O'Brien", 'O/B', 'O"B', 'O’B', 'O，B'])
  })
})

describe('userPrefix', () => {
  it('capitalises the first letter like MediaWiki user names', () => {
    expect(userPrefix('alice_l')).toBe('Alice l')
    // CJK has no case: the name stays as typed.
    expect(userPrefix('张')).toBe('张')
  })
})
