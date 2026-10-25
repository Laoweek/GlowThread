// The design registry of the client (client/src/design/): the tokens with their defaults and
// the example themes, read at run time so the tests follow the source.
import type { Page } from '@playwright/test'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

const DESIGN = new URL('../../../client/src/design/', import.meta.url)

export interface Token { name: string, group: string, light: string, night?: string, phone?: string, phoneOf?: string, at?: string, lang?: unknown }
export const TOKENS: Token[] = JSON.parse(readFileSync(fileURLToPath(new URL('tokens.json', DESIGN)), 'utf8')).tokens
export const TOKEN: Record<string, Token> = Object.fromEntries(TOKENS.map((t) => [t.name, t]))
export const EXAMPLES = readFileSync(fileURLToPath(new URL('example-themes.css', DESIGN)), 'utf8')

/** GlowThread's default for a token in light or night mode, references to other tokens resolved. */
export function fallback(name: string, night: boolean): string {
  const t = TOKEN[name]
  const v = night && t.night !== undefined ? t.night : t.light
  return v.replace(/var\(--gt-v-([a-z0-9-]+)\)/g, (_, other) => fallback(other, night))
}

/** A colour as getComputedStyle reports it (rgb() or rgba()). */
export const rgb = (page: Page, value: string) => page.evaluate((v) => {
  const probe = document.createElement('span')
  probe.style.color = v
  document.body.appendChild(probe)
  const c = getComputedStyle(probe).color
  probe.remove()
  return c
}, value)
