import { describe, expect, it } from 'vitest'
import { deserialize, serialize, shiftUnits, tokenIds, validUnits } from '@/comments/units'
import { mentionToken, storedText } from '@/lib/text'
import type { Unit } from '@/comments/units'

// Picked mentions in the composer (MENTIONS-SPEC.md, "Token form (decided)").
const bob = (start: number): Unit => ({ start, end: start + 4, id: 4, name: 'Bob' })

describe('the stored form', () => {
  it('writes each mention as <@ID>, everything else as typed', () => {
    expect(mentionToken(123)).toBe('<@123>')
    const ann: Unit = { start: 9, end: 17, id: 7, name: 'Ann Lee' }
    // Units in any order; typed "@Bob" is not a mention.
    expect(serialize('@Bob and @Ann Lee, hi @Bob', [ann, bob(0)])).toBe('<@4> and <@7>, hi @Bob')
    expect(serialize('no mentions', [])).toBe('no mentions')
  })

  it('is rebuilt from a comment\'s segments, placeholders as shown', () => {
    expect(storedText([
      { type: 'text', text: 'hi ' },
      { type: 'mention', text: '@Bob', user: 'Bob', id: 4, exists: true },
      { type: 'text', text: ' and ' },
      { type: 'mention', text: '@…', exists: false } as never,
      { type: 'link', text: 'https://a.example/', href: 'https://a.example/' },
    ])).toBe('hi <@4> and @…https://a.example/')
  })

  it('reads only well-formed tokens', () => {
    expect(tokenIds('<@1> 谢谢<@42>！ hi<@7>, https://a.example/x<@5> <@42>')).toEqual([1, 42, 7, 5])
    for (const plain of ['@Alice', '<@0>', '<@01>', '<@ 1>', '<@1 >', '<@-1>', '<@abc>', '<@12345678901>']) {
      expect(tokenIds(plain), plain).toEqual([])
    }
  })

  it('turns tokens back into units with the given names, and keeps unknown ones as they are', () => {
    const r = deserialize('hi <@4>, <@9> and <@7>!', { 4: 'Bob', 7: 'Ann Lee' })
    expect(r.text).toBe('hi @Bob, <@9> and @Ann Lee!')
    expect(r.units).toEqual([{ start: 3, end: 7, id: 4, name: 'Bob' }, { start: 18, end: 26, id: 7, name: 'Ann Lee' }])
    expect(serialize(r.text, r.units)).toBe('hi <@4>, <@9> and <@7>!')
  })
})

describe('units while the text changes', () => {
  const units = [bob(3)] // "hi @Bob there"
  const before = 'hi @Bob there'

  it('move with text typed or pasted before them', () => {
    expect(shiftUnits(before, 'oh hi @Bob there', 3, units)).toEqual([bob(6)])
    // At the very start of the mention: still before it.
    expect(shiftUnits(before, 'hi x@Bob there', 4, units)).toEqual([bob(4)])
  })

  it('stay with text typed after them, even right at their end', () => {
    expect(shiftUnits(before, 'hi @Bob! there', 8, units)).toEqual([bob(3)])
    expect(shiftUnits(before, 'hi @Bob there, yes', 18, units)).toEqual([bob(3)])
  })

  it('become plain text when the change reaches inside', () => {
    expect(shiftUnits(before, 'hi @Bxob there', 6, units)).toEqual([])
    // Backspace inside the name, and a deletion that takes the whole mention.
    expect(shiftUnits(before, 'hi @Bb there', 5, units)).toEqual([])
    expect(shiftUnits(before, 'hi  there', 3, units)).toEqual([])
    // Pasting over a selection that covers part of it.
    expect(shiftUnits(before, 'hi @Bo, Carol there', 13, units)).toEqual([])
  })

  it('use the caret where the same letter could have been typed in two places', () => {
    const anna: Unit[] = [{ start: 0, end: 5, id: 2, name: 'Anna' }]
    // "a" typed inside "@Anna" (caret 5) or after it (caret 6): the same text.
    expect(shiftUnits('@Anna', '@Annaa', 5, anna)).toEqual([])
    expect(shiftUnits('@Anna', '@Annaa', 6, anna)).toEqual(anna)
  })

  it('keep only units that still read @Name', () => {
    expect(validUnits('hi @Bob', [bob(3), { start: 0, end: 4, id: 5, name: 'Eve' }])).toEqual([bob(3)])
  })
})
