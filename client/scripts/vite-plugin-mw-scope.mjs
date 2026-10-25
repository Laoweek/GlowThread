// Makes a Tailwind build safe to drop into a MediaWiki page, written for older browsers:
// no :is() or :where(), which Chrome 87 lacks.
//
// Source CSS writes `.gt-scope` for "app root or portal container" and sorts its rules
// into cascade layers. This step runs on the CSS file Vite wrote and:
// - layer `tokens` (src/design/tokens.css: --glowthread-* tokens resolved into --gt-v-* on
//   .gt-scope and the container parts): left as is, except that the container parts this
//   build's JavaScript never writes are dropped (usedParts)
// - layer `theme` (Tailwind's --gt-* variables): moved from :root to .gt-scope
// - layer `properties` (Tailwind's fallback for browsers without @property): its ::backdrop
//   copy goes (dropBackdrops)
// - layer `revert` (scoped `all: revert`): .gt-scope becomes REVERT, weight (2,1,0)
// - every other rule: .gt-scope becomes SCOPE, weight (2,2,0); html, body, :root and *
//   become the scope itself; any other selector is prefixed with it
// - all cascade layers are removed (skin CSS is unlayered, and unlayered beats layered)
// - @keyframes get a gt- prefix (spin, pulse, enter are common names)
// - padding-inline, margin-block and the like become -start and -end longhands (Safari 14)
// - Lightning CSS minifies and lowers the result for the browser floor (targets.mjs)
// - a declaration repeated in one rule keeps only its last copy (dedupe)
//
// Specificity ladder: skin and site rules with up to one ID lose to REVERT, the reverted
// elements are rebuilt by preflight, base rules and utilities under SCOPE, and inline
// styles from Reka UI and Floating UI still win because nothing is !important.
import postcss from 'postcss'
import { transform } from 'lightningcss'
import { readFileSync, writeFileSync } from 'node:fs'
import path from 'node:path'
import { LIGHTNING_TARGETS } from './targets.mjs'
import { CONTAINER_PARTS } from './tokens.mjs'

// :not(#_) tests only the scope root's own ID, and no scope root has the ID `_` (they are
// #glowthread-portal and a div without an ID): each one matches and only adds an ID's weight.
export const REVERT = '.gt-scope:not(#_):not(#_)'
export const SCOPE = '.gt-scope.gt-scope:not(#_):not(#_)'

// A container part's class in a selector, as a whole class: ext-glowthread-menu, not
// ext-glowthread-menu-trigger.
const PART = new RegExp(`\\.ext-glowthread-(${CONTAINER_PARTS.join('|')})(?![\\w-])`, 'g')

// The container parts a selector requires: those it names outside :not().
const required = (sel) => [...sel.replace(/:not\([^)]*\)/g, '').matchAll(PART)].map((m) => m[1])

// `ext-glowthread` and maybe the start of a name, right before a ${...} or before the end of a
// string joined with +, and `ext-glowthread-` or a name ending in - before the end of any
// string (no class looks like that, so it is put together somewhere).
const PIECE = /ext-glowthread(?:-([\w-]*))?(?=(\$\{|["'`]\s*\+|["'`]))/g

/**
 * The container parts whose class a build's JavaScript writes. The class must be written in
 * full: one that could be put together from pieces stops the build, because the part it names
 * would lose its token rules (scopeCss drops the parts a build does not write).
 */
export function usedParts(js) {
  for (const m of js.matchAll(PIECE)) {
    // `ext-glowthread` alone is the container's class, whole unless something is joined to it.
    const name = m[1] || ''
    const open = m[1] !== undefined && (!name || name.endsWith('-'))
    const joined = m[2] === '${' || m[2].includes('+')
    if ((open || joined) && CONTAINER_PARTS.some((p) => p !== name && p.startsWith(name))) {
      throw new Error(`[mw-scope] the JavaScript may put a container part's class together from pieces (${js.slice(m.index, m.index + 60)}): write each ext-glowthread-<part> class in full, so the build can tell which container parts an app renders`)
    }
  }
  return new Set(CONTAINER_PARTS.filter((p) => new RegExp(`ext-glowthread-${p}(?![\\w-])`).test(js)))
}

function scope(sel, layer) {
  sel = sel.trim()
  if (layer === 'tokens') return [sel]
  if (/^html\[dir=/.test(sel)) return []
  if (layer === 'theme') return /^(:root|:host)$/.test(sel) ? ['.gt-scope'] : [sel]
  const S = layer === 'revert' ? REVERT : SCOPE
  if (sel.includes('.gt-scope')) return [sel.split('.gt-scope').join(S)]
  if (/^(html|:host|:root|body)$/.test(sel)) return [S]
  if (sel === '*') return [S, `${S} *`]
  if (/^::?(before|after|backdrop|marker|placeholder|selection)$/.test(sel)) return [`${S}${sel}`, `${S} ${sel}`]
  return [`${S} ${sel}`]
}

// Tailwind gives every element the initial values of its --tw-* properties where @property is
// missing (@layer properties), ::backdrop included.
const inFallback = (node) => {
  for (let p = node.parent; p; p = p.parent) if (p.type === 'atrule' && p.name === 'layer' && p.params === 'properties') return true
  return false
}

function dropBackdrops(root) {
  // The fallback's values on ::backdrop matter only to a rule that styles a ::backdrop: the apps
  // have none (and no top-layer element, such as a modal dialog, a popover or fullscreen).
  let backdrops = false
  root.walkRules((r) => {
    if (r.selector.includes('::backdrop') && !inFallback(r)) backdrops = true
  })
  if (backdrops) return
  root.walkAtRules('layer', (layer) => {
    if (layer.params !== 'properties') return
    layer.walkRules((r) => {
      const kept = r.selectors.filter((x) => !x.includes('::backdrop'))
      if (!kept.length) r.remove()
      else r.selectors = kept
    })
  })
}

// Lightning CSS adds a -webkit- copy of some declarations on each pass over the CSS (this step
// makes one more), so a rule can hold the same declaration several times. Only the last copy
// counts, so the earlier ones go.
function dedupe(css) {
  const root = postcss.parse(css)
  root.walkRules((rule) => {
    const seen = new Set()
    for (const d of [...rule.nodes].reverse()) {
      if (d.type !== 'decl') continue
      const key = `${d.prop}:${d.value}${d.important ? '!important' : ''}`
      if (seen.has(key)) d.remove()
      else seen.add(key)
    }
  })
  return root.toString()
}

/**
 * Scopes Vite's CSS for MediaWiki. `parts` is the set of container parts the build renders
 * (usedParts): the token rules of the others are dropped. Without it, all are kept.
 */
export function scopeCss(css, { minify = true, parts } = {}) {
  const root = postcss.parse(css)
  dropBackdrops(root)
  const names = []
  root.walkAtRules(/keyframes$/, (r) => {
    if (r.params.startsWith('gt-')) return
    names.push(r.params)
    r.params = 'gt-' + r.params
  })
  const kf = names.length
    ? new RegExp(`(^|[\\s,(])(${names.map((k) => k.replace(/[-\\]/g, '\\$&')).join('|')})(?=$|[\\s,)])`, 'g')
    : null
  root.walkRules((rule) => {
    let layer = null
    let tokens = false
    for (let p = rule.parent; p && p.type !== 'root'; p = p.parent) {
      if (p.type === 'atrule' && /keyframes$/.test(p.name)) return
      if (p.type === 'atrule' && p.name === 'layer' && !layer) layer = p.params
      if (p.type === 'atrule' && p.name === 'layer' && p.params === 'tokens') tokens = true
    }
    let own = rule.selectors
    // Token rules (and the part rule in tokens' nested layer revert) for a container part the
    // build never renders match nothing.
    if (tokens && parts) own = own.filter((s) => required(s).every((p) => parts.has(p)))
    const selectors = [...new Set(own.flatMap((s) => scope(s, layer)))]
    if (!selectors.length) return rule.remove()
    rule.selectors = selectors
    if (kf) {
      rule.walkDecls(/^(animation|animation-name)$/, (d) => {
        d.value = d.value.replace(kf, '$1gt-$2')
      })
    }
  })
  // Logical shorthands (padding-inline, margin-block) need Safari 14.1. Lightning CSS splits
  // them into their -start and -end longhands for the browser floor, but not when the value
  // has a var() (the spacing utilities read --gt-v-space), so split those here.
  root.walkDecls(/^(padding|margin)-(inline|block)$/, (d) => {
    const [start, end = start] = postcss.list.space(d.value)
    d.cloneBefore({ prop: d.prop + '-start', value: start })
    d.cloneBefore({ prop: d.prop + '-end', value: end })
    d.remove()
  })
  // Until none is left: a layer nested in another (tokens.css has one) moves up a level each time.
  for (let found = true; found; ) {
    found = false
    root.walkAtRules('layer', (r) => {
      found = true
      return r.nodes ? r.replaceWith(r.nodes) : r.remove()
    })
  }
  const out = root.toString()
  if (!minify) return dedupe(out)
  const { code, warnings } = transform({
    filename: 'app.css',
    code: Buffer.from(out),
    minify: true,
    targets: LIGHTNING_TARGETS,
  })
  for (const w of warnings) console.warn('[mw-scope]', w.message)
  return dedupe(code.toString())
}

export default function mwScope() {
  let parts
  // Vite writes library-mode CSS late, so rewrite the file after it is written.
  return {
    name: 'mw-scope',
    enforce: 'post',
    // Before any file is written, so a class in pieces stops the build with nothing half done.
    generateBundle(_options, bundle) {
      // The stylesheet serves the entry and its import() chunks: the parts they render.
      parts = usedParts(Object.values(bundle).filter((f) => f.type === 'chunk').map((f) => f.code).join('\n'))
    },
    writeBundle({ dir }, bundle) {
      // Only this build's files: other entries share the output folder.
      for (const f of Object.keys(bundle).filter((f) => f.endsWith('.css'))) {
        writeFileSync(path.join(dir, f), scopeCss(readFileSync(path.join(dir, f), 'utf8'), { parts }))
      }
    },
  }
}
