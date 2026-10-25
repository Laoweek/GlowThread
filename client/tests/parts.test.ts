// @vitest-environment node
import { describe, expect, it } from 'vitest'
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import path from 'node:path'
import { ATTRS, PARTS, PART_PREFIX } from '@/design/parts'
import { CONTAINER_PARTS, DOCS, PART_MARKERS, docsBlock, generatePartsDocs } from '../scripts/tokens.mjs'

// The part hooks (src/design/parts.ts) are a public contract with site CSS: every part renders,
// every hook the source writes is in the registry, and the docs tables follow it. The hooks on a
// rendered comment: tests/parts-render.test.ts.
const src = path.resolve('src')
function files(dir: string): string[] {
  return readdirSync(dir).flatMap((f) => {
    const p = path.join(dir, f)
    return statSync(p).isDirectory() ? files(p) : /\.(ts|vue)$/.test(f) ? [p] : []
  })
}
const sources = files(src)
  .filter((f) => !f.endsWith(path.join('design', 'parts.ts')))
  .map((f) => ({ path: f, file: path.relative(src, f), text: readFileSync(f, 'utf8') }))
const all = sources.map((s) => s.text).join('\n')
// Docs that show site CSS: the hooks they name must exist.
const examples = [
  { file: 'design/example-themes.css', text: readFileSync(path.join(src, 'design', 'example-themes.css'), 'utf8') },
  { file: '../README.md', text: readFileSync(path.resolve('README.md'), 'utf8') },
]
const names = PARTS.map((p) => p.name)
// Other ext-glowthread- names: mountApp's theme class, the switch classes, the loader's classes,
// the no-JavaScript notices and the hub's container ID.
const PAGE = /^(theme|night|day|admin|admin-nojs|nojs|reserve|size(-[a-z0-9]+)?)$/
const has = (text: string, cls: string) => new RegExp(cls.replace(/-/g, '\\-') + '(?![\\w-])').test(text)

// The files the apps load: everything an app's main.ts imports, followed through relative and
// @/ imports (static or import(), which loads a second module), without comments.
function loaded(): string {
  const seen = new Set<string>()
  const stack = readdirSync(src).map((d) => path.join(src, d, 'main.ts')).filter((f) => existsSync(f))
  while (stack.length) {
    const f = stack.pop() as string
    if (seen.has(f)) continue
    seen.add(f)
    for (const m of readFileSync(f, 'utf8').matchAll(/(?:from|import)(?:\s+|\s*\(\s*)['"]([^'"]+)['"]/g)) {
      if (!m[1].startsWith('.') && !m[1].startsWith('@/')) continue
      const base = m[1].startsWith('@/') ? path.join(src, m[1].slice(2)) : path.resolve(path.dirname(f), m[1])
      const hit = [base, base + '.ts', base + '.vue', path.join(base, 'index.ts')].find((p) => existsSync(p) && statSync(p).isFile())
      if (hit) stack.push(hit)
    }
  }
  return sources
    .filter((s) => seen.has(s.path))
    .map((s) => s.text.replace(/<!--[\s\S]*?-->/g, '').replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:\w'"`])\/\/.*$/gm, '$1'))
    .join('\n')
}
// Parts whose hook sits in a component the apps do not load yet. Empty: the registry and the
// docs list only hooks that render.
const UNRENDERED: string[] = []

describe('part registry', () => {
  it('has unique names in lower case, none named after a side (CSSJanus) or a page class', () => {
    expect(new Set(names).size).toBe(names.length)
    for (const n of names) {
      expect(n, n).toMatch(/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/)
      expect(/left|right/.test(n), n).toBe(false)
      expect(PAGE.test(n), n).toBe(false)
    }
  })

  it('describes every part in both languages', () => {
    for (const p of PARTS) {
      expect(['comments', 'overlay', 'hub', 'both'], p.name).toContain(p.app)
      expect(p.desc && p.zh, p.name).toBeTruthy()
    }
    for (const a of ATTRS) expect(a.desc && a.zh, a.name).toBeTruthy()
  })

  it('has the containers that tokens.css resolves the tokens on (CONTAINER_PARTS)', () => {
    expect(PARTS.filter((p) => p.container).map((p) => p.name).sort()).toEqual([...CONTAINER_PARTS].sort())
  })

  it('writes every part in a template', () => {
    expect(names.filter((n) => !has(all, PART_PREFIX + n))).toEqual([])
  })

  it('renders every part in a file the apps load (except the ones listed as not loaded yet)', () => {
    const code = loaded()
    expect(names.filter((n) => !has(code, PART_PREFIX + n))).toEqual(UNRENDERED)
  })

  it('lists every hook class the source, the example themes and the README use', () => {
    const unknown = new Set<string>()
    for (const s of [...sources, ...examples]) {
      for (const m of s.text.matchAll(/ext-glowthread-([a-z0-9]+(?:-[a-z0-9]+)*)/g)) {
        if (!names.includes(m[1]) && !PAGE.test(m[1])) unknown.add(`${s.file}: ${m[0]}`)
      }
    }
    expect([...unknown]).toEqual([])
  })

  it('lists every state attribute the source writes, and writes each one', () => {
    const known = ATTRS.map((a) => a.name)
    const written = new Set([...all.matchAll(/data-glowthread-[a-z]+(?:-[a-z]+)*/g)].map((m) => m[0]))
    written.delete('data-glowthread-app') // the app roots and the overlay container (mountApp)
    expect([...written].filter((a) => !known.includes(a))).toEqual([])
    expect(known.filter((a) => !written.has(a))).toEqual([])
    const shown = new Set(examples.flatMap((s) => [...s.text.matchAll(/data-glowthread-[a-z]+(?:-[a-z]+)*/g)].map((m) => m[0])))
    shown.delete('data-glowthread-app')
    expect([...shown].filter((a) => !known.includes(a))).toEqual([])
    // Each attribute belongs to a part, and each part names known attributes.
    const onParts = new Set(PARTS.flatMap((p) => p.attrs || []))
    expect(known.filter((a) => !onParts.has(a))).toEqual([])
    expect([...onParts].filter((a) => !known.includes(a))).toEqual([])
  })
})

for (const { file, lang } of DOCS) {
  const name = path.relative(path.resolve('..'), file)
  const block = docsBlock(readFileSync(file, 'utf8'), PART_MARKERS)
  describe(`part tables in ${name}`, () => {
    it('match the registry (run `pnpm tokens docs`)', () => {
      expect(block, `${name} needs the ${PART_MARKERS[0]} and ${PART_MARKERS[1]} markers`).not.toBeNull()
      expect(block).toBe(generatePartsDocs(lang))
    })
  })
}
