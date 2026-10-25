// @vitest-environment node
import { describe, expect, it } from 'vitest'
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import path from 'node:path'
import cssjanus from 'cssjanus'
import { CSS_FILE, DOCS, docsBlock, generateCss, generateDocs, loadRegistry } from '../scripts/tokens.mjs'

// The token registry (src/design/tokens.json) is the one source: the generated stylesheet,
// the docs tables and every token the source reads must agree with it.
interface Token { name: string, group: string, light?: string, night?: string, phone?: string, phoneOf?: string, desc: string, zh: string }
const reg = loadRegistry() as { groups: { id: string }[], tokens: Token[] }
const byName = new Map(reg.tokens.map((t) => [t.name, t]))
const src = path.resolve('src')
const dist = path.resolve('..', 'resources', 'dist')

function files(dir: string): string[] {
  return readdirSync(dir).flatMap((f) => {
    const p = path.join(dir, f)
    return statSync(p).isDirectory() ? files(p) : /\.(css|ts|vue)$/.test(f) ? [p] : []
  })
}
const sources = files(src).map((f) => ({ file: path.relative(src, f), text: readFileSync(f, 'utf8') }))
const all = sources.map((s) => s.text).join('\n')

describe('token registry', () => {
  it('generates src/design/tokens.css (run `pnpm tokens css` after editing tokens.json)', () => {
    expect(readFileSync(CSS_FILE, 'utf8')).toBe(generateCss(reg))
  })

  it('lists every public token the source reads', () => {
    const unknown = new Set<string>()
    for (const s of sources) {
      for (const m of s.text.matchAll(/--glowthread-(night-)?([a-z0-9]+(?:-[a-z0-9]+)*)(?![\w*<-])/g)) {
        const t = byName.get(m[2])
        // A night name only exists for a token with a night value.
        if (!t || (m[1] && !('night' in t))) unknown.add(`${s.file}: ${m[0]}`)
      }
    }
    expect([...unknown]).toEqual([])
  })

  it('has every resolved token (--gt-v-*) the source reads', () => {
    const unknown = new Set<string>()
    for (const s of sources) {
      for (const m of s.text.matchAll(/--gt-v-([a-z0-9]+(?:-[a-z0-9]+)*)(?![\w*<-])/g)) if (!byName.has(m[1])) unknown.add(`${s.file}: ${m[0]}`)
    }
    expect([...unknown]).toEqual([])
  })

  // Read as var(--gt-v-x) in CSS or as gt:utility-(--gt-v-x) in a template.
  it('uses every token somewhere', () => {
    const unused = reg.tokens.filter((t) =>
      t.phoneOf
        ? !all.includes(`var(--glowthread-${t.name},`)
        : !new RegExp(`\\(--gt-v-${t.name}[),]`).test(all),
    )
    expect(unused.map((t) => t.name)).toEqual([])
  })

  it('gives every token a group, a default and both descriptions', () => {
    const groups = new Set(reg.groups.map((g) => g.id))
    for (const t of reg.tokens) {
      expect(groups.has(t.group), t.name).toBe(true)
      expect(t.phoneOf ? byName.has(t.phoneOf) : typeof t.light === 'string', t.name).toBe(true)
      expect(t.desc && t.zh, t.name).toBeTruthy()
    }
  })

  it('has no "left" or "right" in a token name (CSSJanus would flip it)', () => {
    expect(reg.tokens.filter((t) => /left|right/.test(t.name)).map((t) => t.name)).toEqual([])
  })
})

for (const { file, lang } of DOCS) {
  const name = path.relative(path.resolve('..'), file)
  const block = existsSync(file) ? docsBlock(readFileSync(file, 'utf8')) : null
  describe(`docs tables in ${name}`, () => {
    it.skipIf(block === null)(`match the registry (run \`pnpm tokens docs\`; skipped when ${name} has no glowthread-tokens markers)`, () => {
      expect(block).toBe(generateDocs(reg, lang))
    })
  })
}

// WCAG contrast of the default colours, from the registry. Translucent colours are laid over
// the surface they sit on.
type RGBA = [number, number, number, number]
function parse(v: string): RGBA {
  if (v === 'transparent') return [0, 0, 0, 0]
  const hex = /^#([0-9a-f]{6})$/i.exec(v)
  if (hex) {
    const n = parseInt(hex[1], 16)
    return [(n >> 16) & 255, (n >> 8) & 255, n & 255, 1]
  }
  const rgba = /^rgba?\(([^)]*)\)$/.exec(v)
  if (rgba) {
    const p = rgba[1].split(',').map(Number)
    return [p[0], p[1], p[2], p.length > 3 ? p[3] : 1]
  }
  throw new Error('not a colour: ' + v)
}
function colour(name: string, mode: 'light' | 'night'): RGBA {
  const t = byName.get(name) as Token
  const v = (mode === 'night' ? t.night : t.light) as string
  const ref = /^var\(--gt-v-([a-z0-9-]+)\)$/.exec(v)
  return ref ? colour(ref[1], mode) : parse(v)
}
const over = (fg: RGBA, bg: RGBA): RGBA => [0, 1, 2].map((i) => fg[i] * fg[3] + bg[i] * (1 - fg[3])).concat(1) as RGBA
const lum = (c: RGBA) => {
  const [r, g, b] = c.slice(0, 3).map((x) => (x /= 255) <= 0.04045 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4)
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}
const contrast = (fg: RGBA, bg: RGBA) => {
  const [x, y] = [lum(over(fg, bg)), lum(bg)].sort((p, q) => q - p)
  return (x + 0.05) / (y + 0.05)
}

describe.each(['light', 'night'] as const)('default contrast (%s)', (mode) => {
  const surface = colour('color-surface', mode)
  const white: RGBA = [255, 255, 255, 1]
  const on = (name: string) => over(colour(name, mode), surface)
  const pairs: [string, RGBA, RGBA, number][] = [
    ['fg on surface', colour('color-fg', mode), surface, 4.5],
    ['fg-muted on surface', colour('color-fg-muted', mode), surface, 4.5],
    ['fg-muted on surface-subtle', colour('color-fg-muted', mode), on('color-surface-subtle'), 4.5],
    ['fg-subtle (placeholders) on surface', colour('color-fg-subtle', mode), surface, 4.5],
    ['accent-text on surface', colour('color-accent-text', mode), surface, 4.5],
    ['accent-text on accent-badge', colour('color-accent-text', mode), on('color-accent-badge'), 4.5],
    // Matched line numbers in the word filter and the hub's new-items chip.
    ['accent-text on accent-subtle', colour('color-accent-text', mode), on('color-accent-subtle'), 4.5],
    ['on-accent on accent-bg', colour('color-on-accent', mode), on('color-accent-bg'), 4.5],
    ['on-accent on accent-bg-hover', colour('color-on-accent', mode), on('color-accent-bg-hover'), 4.5],
    ['danger on danger-subtle', colour('color-danger', mode), on('color-danger-subtle'), 4.5],
    ['warning on warning-subtle', colour('color-warning', mode), on('color-warning-subtle'), 4.5],
    ['success on success-subtle', colour('color-success', mode), on('color-success-subtle'), 4.5],
    ['accent (marks, focus ring) on surface', colour('color-accent', mode), surface, 3],
    ['focus ring on surface', colour('color-focus-ring', mode), surface, 3],
    ['border-control on surface', colour('color-border-control', mode), surface, 3],
  ]
  if (mode === 'light') {
    // Light mode sits on the skin's white page.
    pairs.push(['fg on white', colour('color-fg', mode), white, 4.5], ['fg-muted on white', colour('color-fg-muted', mode), white, 4.5], ['fg-subtle on white', colour('color-fg-subtle', mode), white, 4.5])
  }
  it.each(pairs)('%s', (_, fg, bg, min) => {
    expect(contrast(fg, bg)).toBeGreaterThanOrEqual(min)
  })
})

describe('built CSS and right-to-left languages', () => {
  const built = existsSync(dist) ? readdirSync(dist).filter((f) => f.endsWith('.css')) : []
  // Tailwind emits only the utilities the templates use: a token nothing reads in the bundles
  // (not even another token's default) has no effect.
  it.skipIf(!built.length)('reads every token in the bundles (skipped before the first build)', () => {
    const css = built.map((f) => readFileSync(path.join(dist, f), 'utf8')).join('\n')
    const unread = reg.tokens.filter((t) =>
      t.phoneOf ? !css.includes(`var(--glowthread-${t.name},`) : !new RegExp(`var\\(--gt-v-${t.name}[),]`).test(css),
    )
    expect(unread.map((t) => t.name)).toEqual([])
  })
  it.skipIf(!built.length)('CSSJanus keeps every custom property name (skipped before the first build)', () => {
    const names = (css: string) => [...new Set(css.match(/--[\w-]+/g) || [])].sort()
    for (const f of built) {
      const css = readFileSync(path.join(dist, f), 'utf8')
      expect(names(cssjanus.transform(css)), f).toEqual(names(css))
    }
  })
})
