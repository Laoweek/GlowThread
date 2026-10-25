/** Length as the server counts it: Unicode code points of the trimmed text. */
export function codePointLength(text: string): number {
  return Array.from(text.trim()).length
}

/** The stored form of a mention of user `id` (docs/api.md, "Input rules"): the only place that writes it. */
export function mentionToken(id: number): string {
  return '<@' + id + '>'
}

/** A comment's stored text rebuilt from its segments, mentions as tokens (for the word filter test). */
export function storedText(segments: Array<{ text: string, id?: number }>): string {
  return segments.map((s) => (s.id ? mentionToken(s.id) : s.text)).join('')
}
