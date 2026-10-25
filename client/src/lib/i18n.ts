/**
 * UI messages. Every key lives in i18n/ui/*.json as glowthread-ui-<key>, and
 * ResourceLoader ships all of them with the app module (UiMessagesModule).
 * Parameters follow MediaWiki: $1, $2 and {{PLURAL:$1|...}}.
 */
export const MESSAGE_PREFIX = 'glowthread-ui-'

export function t(key: string, ...params: Array<string | number>): string {
  return mw.message(MESSAGE_PREFIX + key, ...params).text()
}

/** Formats a number for the user language (MediaWiki digit conversion). */
export function num(n: number): string {
  return mw.language.convertNumber(n)
}

/** One part of a message: its text, or the number of a parameter ($1 is 1). */
export type MessagePart = string | number

/**
 * A message as parts, for a template that styles or links its parameters: "Held for review: $1"
 * gives ['Held for review: ', 1]. The parts keep the translation's word order and spacing, so
 * no message has to end in a colon or a space.
 */
export function tParts(key: string, count = 1): MessagePart[] {
  const params: string[] = []
  for (let i = 1; i <= count; i++) params.push('' + i + '')
  const parts: MessagePart[] = []
  t(key, ...params).split(/(\d+)/).forEach((s, i) => {
    if (i % 2) parts.push(Number(s))
    else if (s) parts.push(s)
  })
  return parts
}

/** Whole sentences joined the way the user language does: with a space, or none in Chinese. */
export function sentences(...parts: string[]): string {
  return parts.filter((p) => p).join(mw.message('word-separator').text())
}
