// Picked @mentions in the composer. The textarea holds plain text. A unit is a range of it,
// `@Name`, that stands for a user picked from the suggestions. It is sent as a token with the
// user ID (docs/api.md, "Input rules"), so it survives renames. Typed text is never a mention.
// Pure, unit-tested (tests/units.test.ts).

import { mentionToken } from '@/lib/text'

export interface Unit { start: number, end: number, id: number, name: string }

// The stored form of a mention (mentionToken() writes it), as TextProcessor::MENTION_PATTERN reads it.
const TOKEN = /<@([1-9][0-9]{0,9})>/g

const byStart = (a: Unit, b: Unit) => a.start - b.start

/** The text to send or store: each unit becomes its token. */
export function serialize(text: string, units: Unit[]): string {
  let out = ''
  let at = 0
  for (const u of units.slice().sort(byStart)) {
    out += text.slice(at, u.start) + mentionToken(u.id)
    at = u.end
  }
  return out + text.slice(at)
}

/** The user IDs of the tokens in a stored text, each once. */
export function tokenIds(source: string): number[] {
  const ids: number[] = []
  for (const m of source.match(TOKEN) || []) {
    const id = Number(m.slice(2, -1))
    if (ids.indexOf(id) < 0) ids.push(id)
  }
  return ids
}

/** Text and units from a stored text. Tokens of users missing from `names` stay as they are. */
export function deserialize(source: string, names: Record<number, string>): { text: string, units: Unit[] } {
  let text = ''
  let at = 0
  const units: Unit[] = []
  source.replace(TOKEN, (m: string, id: string, offset: number) => {
    const name = names[Number(id)]
    if (name) {
      text += source.slice(at, offset)
      units.push({ start: text.length, end: text.length + 1 + name.length, id: Number(id), name })
      text += '@' + name
      at = offset + m.length
    }
    return m
  })
  return { text: text + source.slice(at), units }
}

/**
 * The units after the text changed from `before` to `after`, the caret at `caret` (the end of
 * what was typed, pasted or composed, or where a deletion was). Units before the change stay,
 * units after it move, and a unit the change reaches into is plain text from then on.
 */
export function shiftUnits(before: string, after: string, caret: number, units: Unit[]): Unit[] {
  // What follows the caret is unchanged, unless the change came from elsewhere.
  let tail = after.length - caret
  if (tail < 0 || tail > before.length || before.slice(before.length - tail) !== after.slice(caret)) {
    tail = 0
    while (tail < before.length && tail < after.length && before[before.length - 1 - tail] === after[after.length - 1 - tail]) tail++
  }
  let head = 0
  const most = Math.min(before.length, after.length) - tail
  while (head < most && before[head] === after[head]) head++
  const end = before.length - tail
  const delta = after.length - before.length
  return units
    .filter((u) => u.end <= head || u.start >= end)
    .map((u) => (u.start >= end && delta ? { ...u, start: u.start + delta, end: u.end + delta } : u))
}

/** The units that still read `@Name` in `text` (a safety net for restored states). */
export function validUnits(text: string, units: Unit[]): Unit[] {
  return units.filter((u) => text.slice(u.start, u.end) === '@' + u.name).sort(byStart)
}
