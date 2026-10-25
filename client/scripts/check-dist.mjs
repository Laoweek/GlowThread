// Static checks and sizes for the built bundles in resources/dist/ (and the init loader).
//   pnpm check            (also runs at the end of pnpm build)
// Fails on anything that would break in ResourceLoader or below the browser floor
// (scripts/targets.mjs): JavaScript must parse as ES2017 inside ResourceLoader's
// packageFiles wrapper, with no eval, and the CSS must not use syntax that Chrome 87,
// Safari 14 or Firefox 78 drop. For right-to-left languages ResourceLoader serves the CSS
// flipped by CSSJanus: that may change declarations only (checkRtl).
import { readFileSync, readdirSync } from 'node:fs'
import { gzipSync } from 'node:zlib'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import * as acorn from 'acorn'
import cssjanus from 'cssjanus'

const repo = fileURLToPath(new URL('../..', import.meta.url))
const dist = path.join(repo, 'resources', 'dist')
const init = path.join(repo, 'resources', 'init', 'index.js')
// Budgets, JavaScript plus CSS, gzip: the comment section (about 64 kB, with the theming
// tokens), its modules loaded when needed (the open ⋯ menu, about 22 kB, and the composer,
// report form and moderation tools, about 20 kB), and the moderation hub (about 88 kB,
// loaded only on Special:GlowThread). A logged-in reader loads the comment section's three.
const BUDGET = { app: 115_000, 'app-menu': 25_000, 'app-extras': 25_000, 'app, logged in': 125_000, admin: 120_000, init: 2_000 }
// Each file is a ResourceLoader module: <output>.js is ext.glowThread.<output>, and a chunk
// <output>-<name>.js (scripts/vite-plugin-mw-chunks.mjs) is ext.glowThread.<output>.<name>.
const MODULES = JSON.parse(readFileSync(path.join(repo, 'extension.json'), 'utf8')).ResourceModules
// Code that belongs in a module loaded later, by a mark it leaves in the bundle: Reka UI's
// dismissable layers (menus, popovers, dialogs, with Floating UI) are in app-menu.js and
// app-extras.js, not in what every reader downloads first.
const LATER = { app: { 'Reka UI overlays': /data-dismissable-layer/ } }

const errors = []
const notes = []
const gz = (buf) => gzipSync(buf, { level: 9 }).length
const kB = (n) => (n / 1000).toFixed(1) + ' kB'

function checkJs(file, label) {
  const js = readFileSync(file, 'utf8')
  const wrapped = `(function (require, module, exports) {\n${js}\n})`
  try {
    acorn.parse(wrapped, { ecmaVersion: 2017, sourceType: 'script' })
  } catch (e) {
    errors.push(`${label}: not ES2017 (${e.message})`)
  }
  const found = {}
  const bump = (k) => (found[k] = (found[k] || 0) + 1)
  ;(function walk(n) {
    if (!n || typeof n.type !== 'string') return
    switch (n.type) {
      case 'ChainExpression': bump('optional chaining'); break
      case 'LogicalExpression': if (n.operator === '??') bump('nullish coalescing'); break
      case 'AssignmentExpression': if (['??=', '||=', '&&='].includes(n.operator)) bump('logical assignment'); break
      case 'ObjectExpression': if (n.properties.some((p) => p.type === 'SpreadElement')) bump('object spread'); break
      case 'ObjectPattern': if (n.properties.some((p) => p.type === 'RestElement')) bump('object rest'); break
      case 'CatchClause': if (!n.param) bump('optional catch binding'); break
      case 'PropertyDefinition': bump('class fields'); break
      case 'PrivateIdentifier': bump('private names'); break
      case 'ForOfStatement': if (n.await) bump('for await'); break
      case 'ImportExpression': bump('import()'); break
      case 'Literal':
        if (n.bigint) bump('BigInt')
        if (n.regex && /[sdv]/.test(n.regex.flags)) bump('regex flags ' + n.regex.flags)
        if (n.regex && /\(\?<[=!]/.test(n.regex.pattern)) bump('regex lookbehind')
        if (n.regex && /\(\?<[A-Za-z_$]/.test(n.regex.pattern)) bump('regex named group')
        if (n.regex && /\\[pP]\{/.test(n.regex.pattern)) bump('regex \\p{}')
        break
    }
    for (const k in n) {
      const v = n[k]
      if (Array.isArray(v)) v.forEach(walk)
      else if (v && typeof v.type === 'string') walk(v)
    }
  })(acorn.parse(wrapped, { ecmaVersion: 'latest', sourceType: 'script' }))
  for (const [k, n] of Object.entries(found)) errors.push(`${label}: ${n}x ${k}`)
  if (/(?<![\w$.])eval\s*\(|new\s+Function\s*\(/.test(js)) errors.push(`${label}: eval or new Function (CSP)`)
  // Public tokens belong to the site (see checkTokens): no inline style or setProperty sets one,
  // and the apps read the resolved --gt-v-* values, so no string in the bundle names one.
  if (/["'`]--glowthread-/.test(js)) errors.push(`${label}: names a public --glowthread-* token in JavaScript`)
  // Runtime APIs newer than the floor. Each one listed here must be polyfilled
  // (src/lib/polyfills.ts) or guarded; the polyfilled ones are only reported.
  const polyfilled = ['Array#at', 'Object.hasOwn']
  const apis = {
    'Array#at': /\.at\(-?[\w$.]*\)/g,
    'Object.hasOwn': /Object\.hasOwn\(/g,
    structuredClone: /\bstructuredClone\(/g,
    'Array#findLast': /\.findLast(Index)?\(/g,
    'Array#toSorted etc.': /\.to(Sorted|Reversed|Spliced)\(/g,
    'crypto.randomUUID': /randomUUID\(/g,
    WeakRef: /\bnew WeakRef\(/g,
    'String#replaceAll': /\.replaceAll\(/g,
    'Element#replaceChildren': /\.replaceChildren\(/g,
  }
  for (const [name, re] of Object.entries(apis)) {
    // Vue's reactive array wrappers forward these methods and only call them when the app does.
    const vueWrappers = name === 'Array#toSorted etc.' ? (js.match(/return [\w$]+\(this\)\.to(Sorted|Reversed|Spliced)\(/g) || []).length : 0
    const n = (js.match(re) || []).length - vueWrappers
    if (!n) continue
    if (polyfilled.includes(name)) notes.push(`${label}: ${n}x ${name} (polyfilled)`)
    else notes.push(`${label}: ${n}x ${name} (check: newer than Chrome 87 / Safari 14 / Firefox 78?)`)
  }
  return js
}

function checkCss(file, label) {
  const css = readFileSync(file, 'utf8')
  const banned = {
    ':is()': /:is\(/g,
    ':where()': /:where\(/g,
    ':has()': /:has\(/g,
    'oklch/oklab/lab/lch colors': /(?<![\w-])(oklch|oklab|lab|lch)\(/g,
    'color-mix()': /color-mix\(/g,
    '@layer': /@layer\b/g,
    '@container': /@container\b/g,
    '@scope': /@scope\b/g,
    '@starting-style': /@starting-style\b/g,
    'media range syntax': /@media[^{]*\((?:width|height)\s*[<>]/g,
    'CSS nesting': /&/g,
    'individual transform properties': /[{;](?:translate|scale|rotate):/g,
    'logical shorthands (Safari 14.1)': /[{;](?:padding|margin|inset)-(?:inline|block):/g,
  }
  for (const [name, re] of Object.entries(banned)) {
    const n = (css.match(re) || []).length
    if (n) errors.push(`${label}: ${n}x ${name}`)
  }
  const soft = { 'flex gap (Safari 14.1)': /[{;]gap:/g, ':focus-visible (Safari 15.4)': /:focus-visible/g, 'aspect-ratio (Chrome 88)': /aspect-ratio:/g }
  for (const [name, re] of Object.entries(soft)) {
    const n = (css.match(re) || []).length
    if (n) notes.push(`${label}: ${n}x ${name}, degrades gracefully`)
  }
  checkRtl(css, label)
  checkTokens(css, label)
  return css
}

// Public tokens (--glowthread-*) belong to the site: the apps only read them, each with
// GlowThread's default as the fallback (src/design/tokens.css, generated by scripts/tokens.mjs).
// A declaration here would compete with the site's own, and a read without a fallback would
// leave the property empty where the site sets nothing.
function checkTokens(css, label) {
  const declared = [...new Set((css.match(/--glowthread-[\w-]+(?=\s*:)/g) || []))]
  if (declared.length) errors.push(`${label}: declares ${declared.length} public tokens, for example ${declared[0]}`)
  const bare = [...new Set((css.match(/var\(\s*--glowthread-[\w-]+\s*(?:,\s*)?\)/g) || []))]
  if (bare.length) errors.push(`${label}: ${bare.length} public tokens read without a fallback, for example ${bare[0]}`)
}

// CSSJanus (cssjanus here, its PHP port in ResourceLoader) flips left and right in
// declarations. It must leave every selector and at-rule as it is, and every animation name:
// it would rename an animation-name containing "left" or "right" but not its @keyframes.
function checkRtl(css, label) {
  const flipped = cssjanus.transform(css)
  const preludes = (s) => [...s.matchAll(/(?:^|[{}])([^{}]+)\{/g)].map((m) => m[1])
  const animations = (s) => (s.match(/animation(?:-name)?:[^;}]*/g) || []).join('\n')
  const a = preludes(css)
  const b = preludes(flipped)
  const changed = a.filter((x, i) => x !== b[i])
  if (a.length !== b.length || changed.length) errors.push(`${label}: CSSJanus changes ${changed.length || 'the number of'} selectors, for example ${changed[0]}`)
  if (animations(css) !== animations(flipped)) errors.push(`${label}: CSSJanus renames an animation (no "left" or "right" in keyframe names)`)
  const decls = (s) => s.split(/[{};]/)
  const da = decls(css)
  const db = decls(flipped)
  notes.push(`${label}: ${da.filter((x, i) => x !== db[i]).length} declarations flip for right-to-left languages`)
}

const rows = []
const initJs = checkJs(init, 'init/index.js')
// ResourceLoader minifies the loader when it serves it: measure it without comments and
// indentation, close to what readers download (the source keeps its explanations).
const initServed = initJs.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '').replace(/^\s+/gm, '')
rows.push(['init', gz(Buffer.from(initServed)), initServed.length, 0, 0])
const files = readdirSync(dist)
// A build writes <output>.licenses.txt: the other .js files are its chunks.
const outputs = files.filter((f) => f.endsWith('.licenses.txt')).map((f) => f.replace(/\.licenses\.txt$/, ''))
for (const f of files.filter((f) => f.endsWith('.js')).sort()) {
  const name = f.replace(/\.js$/, '')
  const js = checkJs(path.join(dist, f), f)
  let css = ''
  try {
    css = checkCss(path.join(dist, name + '.css'), name + '.css')
  } catch {
    // no stylesheet
  }
  rows.push([name, gz(Buffer.from(js)), js.length, gz(Buffer.from(css)), css.length])
  const out = outputs.filter((o) => name === o || name.startsWith(o + '-')).sort((a, b) => b.length - a.length)[0] || name
  const module = 'ext.glowThread.' + out + (name === out ? '' : '.' + name.slice(out.length + 1))
  const def = MODULES[module]
  if (!def || !(def.packageFiles || []).includes('resources/dist/' + f)) errors.push(`${f}: not in the packageFiles of ${module} (extension.json)`)
  for (const [what, re] of Object.entries(LATER[name] || {})) if (re.test(js)) errors.push(`${f}: contains ${what}, which belong in a module loaded later`)
  // A module the file requires must be a dependency, a module it loads must exist.
  for (const [, verb, dep] of js.matchAll(/(require|mw\.loader\.using)\("(ext\.glowThread\.[\w.]+)"\)/g)) {
    if (verb === 'require' && !(def && (def.dependencies || []).includes(dep))) errors.push(`${f}: requires ${dep}, which is not a dependency of ${module}`)
    if (verb !== 'require' && !MODULES[dep]) errors.push(`${f}: loads ${dep}, which is not in extension.json`)
  }
  // The bundles ship third-party code: their licenses go with them (a chunk's in its bundle's).
  let notices = ''
  try {
    notices = readFileSync(path.join(dist, out + '.licenses.txt'), 'utf8')
  } catch {
    errors.push(`${out}.licenses.txt: missing`)
  }
  // Vue's code is in @vue/runtime-core and its sibling packages: `vue` itself only re-exports
  // them, so it adds no code to a bundle and has no entry.
  for (const pkg of ['@vue/runtime-core', 'reka-ui', css ? 'tailwindcss' : null]) {
    if (notices && pkg && !new RegExp('^' + pkg + ' \\d', 'm').test(notices)) errors.push(`${out}.licenses.txt: no entry for ${pkg}`)
  }
}

const parts = rows.filter((r) => /^app(-|$)/.test(r[0]))
if (parts.length > 1) rows.push(['app, logged in', ...[1, 2, 3, 4].map((i) => parts.reduce((n, r) => n + r[i], 0))])

console.log('\n[glowthread] bundle sizes (gzip level 9; raw in parentheses)')
for (const [name, jsGz, jsRaw, cssGz, cssRaw] of rows) {
  const total = jsGz + cssGz
  const budget = BUDGET[name]
  console.log(
    `  ${name.padEnd(14)} js ${kB(jsGz)} (${kB(jsRaw)})` +
      (cssRaw ? `  css ${kB(cssGz)} (${kB(cssRaw)})  total ${kB(total)}` : '') +
      (budget ? `  budget ${kB(budget)}${total > budget ? '  OVER' : ''}` : ''),
  )
  if (budget && total > budget) notes.push(`${name}: ${kB(total)} is over the ${kB(budget)} budget`)
}
for (const n of notes) console.log('  note: ' + n)
if (errors.length) {
  for (const e of errors) console.error('  ERROR: ' + e)
  process.exitCode = 1
} else {
  console.log('  syntax and CSS checks passed')
}
