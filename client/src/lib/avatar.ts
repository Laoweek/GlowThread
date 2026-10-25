/**
 * Hue (0 to 359) for a user name: FNV-1a over the name's code points, so the same name
 * always gets the same colour, in every browser and in the admin app.
 */
export function nameHue(name: string): number {
  let h = 0x811c9dc5
  for (const ch of name) {
    h ^= ch.codePointAt(0) as number
    h = Math.imul(h, 0x01000193) >>> 0
  }
  return h % 360
}

/** The character shown in the avatar: the first one of the name, Latin letters upper-cased. */
export function monogram(name: string): string {
  const first = Array.from(name.trim())[0] || '?'
  return first.toUpperCase()
}
