// @vitest-environment node
import { describe, expect, it } from 'vitest'
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import path from 'node:path'
import postcss from 'postcss'
import mwScope, { REVERT, SCOPE, scopeCss, usedParts } from '../scripts/vite-plugin-mw-scope.mjs'

// The build step that scopes each app's stylesheet (scripts/vite-plugin-mw-scope.mjs), on
// small samples in the shape src/design/tokens.css and Tailwind give it.
const tokens = `@layer tokens {
  .gt-scope, .gt-scope .ext-glowthread-comment, .gt-scope .ext-glowthread-record { --gt-v-color-fg: var(--glowthread-color-fg, #171717); }
  @media (max-width: 639px) { .gt-scope, .gt-scope .ext-glowthread-comment, .gt-scope .ext-glowthread-record { --gt-v-font-size-body: 0.9375rem; } }
  html.skin-theme-clientpref-night .gt-scope, html.skin-theme-clientpref-night .gt-scope .ext-glowthread-comment, .ext-glowthread-night .gt-scope .ext-glowthread-record { --gt-v-color-fg: #ededed; }
  .ext-glowthread-day.ext-glowthread-day .gt-scope .ext-glowthread-comment { --gt-v-color-fg: #171717; }
  .gt-scope .gt-avatar[style] { --gt-v-avatar-bg: #808080; }
  @layer revert { .gt-scope .ext-glowthread-comment, .gt-scope .ext-glowthread-record { color: var(--gt-v-color-fg); } }
}`
const fallback = (condition: string) => `@layer properties { @supports ${condition} { *, ::before, ::after, ::backdrop { --tw-shadow: 0 0 #0000; } } }`
const TAILWIND_CONDITION = '(((-webkit-hyphens: none)) and (not (margin-trim: inline))) or ((-moz-orient: inline) and (not (color:rgb(from red r g b))))'

const selectors = (css: string) => {
  const out: string[] = []
  postcss.parse(css).walkRules((r) => { out.push(...r.selectors) })
  return out
}
const declarations = (css: string) => {
  const out: string[] = []
  postcss.parse(css).walkDecls((d) => { out.push(`${d.prop}:${d.value}${d.important ? '!important' : ''}`) })
  return out
}

describe('the scope selectors', () => {
  it('add two IDs of weight with an ID no scope root carries', () => {
    expect(REVERT).toBe('.gt-scope:not(#_):not(#_)')
    expect(SCOPE).toBe('.gt-scope.gt-scope:not(#_):not(#_)')
  })
})

describe('container parts', () => {
  it('are the classes the JavaScript writes in full', () => {
    const parts = usedParts('h("div",{class:"ext-glowthread-comment gt:flex"}),h("li",{class:`ext-glowthread-menu-trigger ext-glowthread-comment-row`})')
    expect([...parts]).toEqual(['comment'])
    expect(usedParts('className=`gt-scope gt-root ext-glowthread-theme`;document.getElementById(`ext-glowthread-admin`)').size).toBe(0)
  })

  it('stop the build when a part\'s class may be put together from pieces', () => {
    for (const js of [
      'class:"ext-glowthread-"+e', 'class:`ext-glowthread-${e}`', 'class:\'ext-glowthread-queue-\'+e', 'class:`ext-glowthread-hub${e}`',
      'class:"ext-glowthread-queue"+(a?"-item":"")', 'class:"ext-glowthread-mod"+s', 'class:"ext-glowthread"+e',
    ]) {
      expect(() => usedParts(js), js).toThrow(/together from pieces/)
    }
  })

  it('let other classes be put together', () => {
    expect([...usedParts('class:`ext-glowthread-comment${p?" gt:ring":""}`')]).toEqual(['comment'])
    for (const js of ['class:`ext-glowthread-size-${n}`', 'h("ol",{class:"ext-glowthread-queue"})', 'class:"ext-glowthread-theme "+e', 'e.closest(`.ext-glowthread`)']) {
      expect(usedParts(js).size, js).toBe(0)
    }
  })

  it('keep only the token rules of the parts the build renders', () => {
    const out = selectors(scopeCss(tokens, { minify: false, parts: new Set(['comment']) }))
    expect(out).toEqual([
      '.gt-scope', '.gt-scope .ext-glowthread-comment',
      '.gt-scope', '.gt-scope .ext-glowthread-comment',
      'html.skin-theme-clientpref-night .gt-scope', 'html.skin-theme-clientpref-night .gt-scope .ext-glowthread-comment',
      '.ext-glowthread-day.ext-glowthread-day .gt-scope .ext-glowthread-comment',
      '.gt-scope .gt-avatar[style]',
      // The part rule in layer revert gets the reset's weight.
      `${REVERT} .ext-glowthread-comment`,
    ])
  })

  it('drop a selector that needs any part the build lacks, not one that excludes it', () => {
    const css = '@layer tokens { .gt-scope .gt-x:not(.ext-glowthread-record), .gt-scope .ext-glowthread-comment .ext-glowthread-record { --gt-v-x: 1; } }'
    expect(selectors(scopeCss(css, { minify: false, parts: new Set(['comment']) }))).toEqual(['.gt-scope .gt-x:not(.ext-glowthread-record)'])
  })

  it('are all kept when the build does not say which it renders', () => {
    const out = selectors(scopeCss(tokens, { minify: false }))
    expect(out).toContain('.ext-glowthread-night .gt-scope .ext-glowthread-record')
    expect(out).toContain(`${REVERT} .ext-glowthread-record`)
  })

  it('come from the entry and its chunks together, which share the stylesheet', () => {
    const dir = mkdtempSync(path.join(tmpdir(), 'gt-mw-scope-'))
    try {
      writeFileSync(path.join(dir, 'app.css'), tokens)
      const bundle = {
        'app.js': { type: 'chunk', code: 'h("article",{class:"ext-glowthread-comment"})' },
        'app-menu.js': { type: 'chunk', code: 'h("div",{class:`ext-glowthread-record gt:p-2`})' },
        // Only the JavaScript counts.
        'app.licenses.txt': { type: 'asset', source: 'ext-glowthread-' },
        'app.css': { type: 'asset' },
      }
      const plugin = mwScope()
      plugin.generateBundle({}, bundle)
      plugin.writeBundle({ dir }, bundle)
      const out = selectors(readFileSync(path.join(dir, 'app.css'), 'utf8'))
      expect(out).toContain('.gt-scope .ext-glowthread-comment')
      expect(out).toContain('.gt-scope .ext-glowthread-record')
      // Checked before Vite writes any file.
      bundle['app-menu.js'].code = 'h("div",{class:"ext-glowthread-"+e})'
      expect(() => mwScope().generateBundle({}, bundle)).toThrow(/together from pieces/)
    } finally {
      rmSync(dir, { recursive: true, force: true })
    }
  })
})

describe('Tailwind\'s @property fallback', () => {
  it('loses its ::backdrop copy, and keeps its condition', () => {
    const out = scopeCss(fallback(TAILWIND_CONDITION))
    expect(out).toContain('@supports (((-webkit-hyphens:none)) and (not (margin-trim:inline))) or ((-moz-orient:inline) and (not (color:rgb(from red r g b)))){')
    expect(selectors(out)).toEqual([SCOPE, `${SCOPE} *`, `${SCOPE}:before`, `${SCOPE} :before`, `${SCOPE}:after`, `${SCOPE} :after`])
  })

  it('keeps its ::backdrop copy when a rule styles a ::backdrop', () => {
    const out = selectors(scopeCss(fallback(TAILWIND_CONDITION) + '@layer utilities { .gt-dim::backdrop { background-color: var(--tw-shadow); } }'))
    expect(out).toContain(`${SCOPE}::backdrop`)
    expect(out).toContain(`${SCOPE} ::backdrop`)
  })
})

describe('repeated declarations', () => {
  it('keep their last copy, in its place', () => {
    const out = scopeCss('@layer base { .gt-scope a { color: red; text-decoration: inherit; color: red; text-decoration-thickness: 1px; color: red !important } }', { minify: false })
    expect(declarations(out)).toEqual(['text-decoration:inherit', 'color:red', 'text-decoration-thickness:1px', 'color:red!important'])
  })

  it('leave one -webkit- copy after the last Lightning CSS pass', () => {
    const out = scopeCss('@layer base { .gt-scope a { -webkit-text-decoration: inherit; -webkit-text-decoration: inherit; text-decoration: inherit } }')
    expect(declarations(out)).toEqual(['-webkit-text-decoration:inherit', 'text-decoration:inherit'])
  })
})
