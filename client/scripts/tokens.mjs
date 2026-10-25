// Design tokens: generates src/design/tokens.css and the token tables of the theming docs
// from src/design/tokens.json, the one source, and the part tables from src/design/parts.ts.
//   pnpm tokens css    writes src/design/tokens.css
//   pnpm tokens docs   rewrites the tables between the glowthread-tokens markers and between
//                      the glowthread-parts markers in docs/theming.md and docs/zh-CN/theming.md
//                      (nothing outside them)
//
// GlowThread never declares a public --glowthread-* property. It only reads them, always with
// its own default as the fallback, so a site's declaration wins whatever its selector, order or
// layer. Each token is resolved into an internal --gt-v-<name> on the scope roots (the app
// roots and the overlay container) and again on the container parts below, so a site can also
// set a token on one part (a pinned comment, the hub's queue items). The apps only read --gt-v-*.
// Night mode reads --glowthread-night-<name> instead, with GlowThread's night default.
import { readFileSync, writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { ATTRS, PARTS, PART_PREFIX } from '../src/design/parts.ts'

const client = fileURLToPath(new URL('..', import.meta.url))
const repo = path.join(client, '..')
export const REGISTRY = path.join(client, 'src', 'design', 'tokens.json')
export const CSS_FILE = path.join(client, 'src', 'design', 'tokens.css')
export const DOCS = [
  { file: path.join(repo, 'docs', 'theming.md'), lang: 'en' },
  { file: path.join(repo, 'docs', 'zh-CN', 'theming.md'), lang: 'zh' },
]
export const MARKERS = ['<!-- glowthread-tokens:start -->', '<!-- glowthread-tokens:end -->']
export const PART_MARKERS = ['<!-- glowthread-parts:start -->', '<!-- glowthread-parts:end -->']

// Parts that take tokens of their own (class ext-glowthread-<part>). A short list on purpose:
// every element listed here resolves every token again, which costs style recalculation.
export const CONTAINER_PARTS = [
  'comment', 'composer', 'linked', 'popular', 'gate', 'mod-notes',
  'menu', 'popover', 'sheet', 'toast', 'mentions', 'emoji', 'report',
  'hub-nav', 'queue-item', 'record', 'settings', 'filter-editor', 'bulk-bar',
]

// Night mode: MediaWiki's theme classes on <html>, or the switch class on any ancestor.
// ext-glowthread-day forces light: the doubled class outweighs every night selector.
const NIGHT = ['html.skin-theme-clientpref-night', '.ext-glowthread-night']
const OS_NIGHT = 'html.skin-theme-clientpref-os'
const DAY = '.ext-glowthread-day.ext-glowthread-day'
// Phones. Font sizes step below 640 px as before. Tokens that replace a Tailwind sm: value or a
// min-width rule keep that media query (phoneMedia), so the switch happens at the same width.
const PHONE = '(max-width: 639px)'

export function loadRegistry(file = REGISTRY) {
  return JSON.parse(readFileSync(file, 'utf8'))
}

const pub = (name) => `--glowthread-${name}`
const nightPub = (name) => `--glowthread-night-${name}`
const internal = (name) => `--gt-v-${name}`

// The elements a token resolves on: the scope roots and container parts, or its own `at`.
function targets(t, suffix = '') {
  if (t.at) return [`.gt-scope ${t.at}${suffix}`]
  return ['.gt-scope' + suffix, ...CONTAINER_PARTS.map((p) => `.gt-scope .ext-glowthread-${p}${suffix}`)]
}

function rule(selectors, decls, indent = '') {
  return `${indent}${selectors.join(`,\n${indent}`)} {\n${decls.map((d) => `${indent}  ${d}\n`).join('')}${indent}}\n`
}

const decl = (t, value) => `${internal(t.name)}: ${value};`
// A token with defaults by language reads its default from --gt-lang-<name>, set once on the
// scope roots per language of <html> (parts inherit it), which keeps the :lang() rules few.
const langVar = (t) => `--gt-lang-${t.name}`
const light = (t) => decl(t, `var(${pub(t.name)}, ${t.lang ? `var(${langVar(t)})` : t.light})`)
const night = (t) => decl(t, `var(${nightPub(t.name)}, ${t.night})`)
function phone(t, reg) {
  const base = `calc(var(${pub(t.name)}, ${t.light}) ${t.phone})`
  const own = reg.tokens.find((x) => x.phoneOf === t.name)
  return decl(t, own ? `var(${pub(own.name)}, ${base})` : base)
}

// Rules for one set of tokens that share their target elements.
function rules(list, reg, at) {
  const sel = (prefix, suffix) => targets(list[0], suffix).map((s) => (prefix ? `${prefix} ${s}` : s))
  const modal = list.filter((t) => 'night' in t)
  const phones = list.filter((t) => t.phone)
  const media = [...new Set(phones.map((t) => t.phoneMedia || PHONE))]
  let out = rule(sel(''), list.map(light))
  for (const t of list.filter((x) => x.lang)) {
    out += rule(['.gt-scope'], [`${langVar(t)}: ${t.light};`])
    for (const l of t.lang) out += rule(l.match.map((m) => `:root:lang(${m}) .gt-scope`), [`${langVar(t)}: ${l.value};`])
  }
  for (const m of media) {
    const these = phones.filter((t) => (t.phoneMedia || PHONE) === m)
    out += `@media ${m} {\n${rule(sel(''), these.map((t) => phone(t, reg)), '  ')}}\n`
  }
  if (modal.length) {
    out += `@media (prefers-color-scheme: dark) {\n${rule(sel(OS_NIGHT), modal.map(night), '  ')}}\n`
    out += rule(NIGHT.flatMap((n) => sel(n)), modal.map(night))
    out += rule(sel(DAY), modal.map(light))
  }
  return out
}

export function generateCss(reg = loadRegistry()) {
  const live = reg.tokens.filter((t) => !t.phoneOf)
  const atGroups = [...new Set(live.filter((t) => t.at).map((t) => t.at))]
  let out = `/*
 * GENERATED by scripts/tokens.mjs from tokens.json: do not edit. Change tokens.json, then run
 * \`pnpm tokens css\` (and \`pnpm tokens docs\` for the docs tables).
 *
 * Public tokens (--glowthread-*) are only read here, each with GlowThread's default as the
 * fallback, and resolved into --gt-v-* on the scope roots (app roots, overlay container) and on
 * the container parts (.ext-glowthread-<part>). The apps read only --gt-v-*. Night mode reads
 * --glowthread-night-* under html.skin-theme-clientpref-night, under
 * html.skin-theme-clientpref-os with a dark system, and under the switch class
 * ext-glowthread-night on any ancestor. ext-glowthread-day on an ancestor forces light.
 * Layer "tokens": the build leaves these selectors unscoped (except the last rule, in layer
 * "revert") and keeps only the container parts each app renders.
 */
`
  out += rules(live.filter((t) => !t.at), reg, null)
  for (const at of atGroups) out += rules(live.filter((t) => t.at === at), reg, at)
  // Text inside a part that inherits its colour or face (an author name) would keep the root's
  // value: the parts set both again, so a token set on a part reaches that text too. Layer
  // "revert" (scoped by the build to the weight of the reset plus this class): above the reset,
  // below every element and utility rule, so a part keeps any colour or face its own rules give it.
  out += `/* Container parts set the text colour and face again, so a token set on a part reaches text that inherits them. */\n`
  out += `@layer revert {\n${rule(CONTAINER_PARTS.map((p) => `.gt-scope .ext-glowthread-${p}`), ['color: var(--gt-v-color-fg);', 'font-family: var(--gt-v-font-family);'], '  ')}}\n`
  return out
}

// Docs tables. A default that refers to another token shows its name.
function shownValue(t, lang, which) {
  const v = which === 'night' ? t.night : t.light
  if (which === 'light' && t.shown) return lang === 'zh' ? t.zhShown : t.shown
  const same = /^var\(--gt-v-([a-z0-9-]+)\)$/.exec(v)
  if (same) return lang === 'zh' ? `同 \`${same[1]}\`` : `same as \`${same[1]}\``
  return '`' + v.replace(/var\(--gt-v-([a-z0-9-]+)\)/g, '$1') + '`'
}

function phoneValue(t) {
  const m = /^([+-]) ([\d.]+)([a-z%]+)$/.exec(t.phone)
  const b = /^([\d.]+)([a-z%]+)$/.exec(t.light)
  if (!m || !b || m[3] !== b[2]) return null
  const n = Number(b[1]) + (m[1] === '-' ? -1 : 1) * Number(m[2])
  return `${Number(n.toFixed(6))}${b[2]}`
}

function defaultCell(t, reg, lang) {
  const zh = lang === 'zh'
  if (t.phoneOf) {
    const base = reg.tokens.find((x) => x.name === t.phoneOf)
    return zh ? `\`${base.name}\` ${base.phone}（${phoneValue(base)}）` : `\`${base.name}\` ${base.phone} (${phoneValue(base)})`
  }
  let cell = shownValue(t, lang, 'light')
  if ('night' in t && t.night !== t.light) cell += zh ? `，夜间 ${shownValue(t, lang, 'night')}` : `, night ${shownValue(t, lang, 'night')}`
  if (t.phone) {
    const v = phoneValue(t)
    cell += zh ? `，手机 \`${v}\`` : `, phones \`${v}\``
  }
  return cell
}

export function generateDocs(reg = loadRegistry(), lang = 'en') {
  const zh = lang === 'zh'
  const desc = (t) => (zh ? t.zh : t.desc)
  const cell = (s) => s.replace(/\|/g, '\\|')
  let out = ''
  for (const g of reg.groups) {
    const list = reg.tokens.filter((t) => t.group === g.id)
    if (g.id === 'colour') {
      out += `### ${zh ? g.zh : g.title}\n\n`
      out += zh ? '| 属性 | 浅色 | 夜间 | 用途 |\n|---|---|---|---|\n' : '| Property | Light | Night | Used for |\n|---|---|---|---|\n'
      for (const t of list) out += `| \`${pub(t.name)}\` | ${cell(shownValue(t, lang, 'light'))} | ${cell(shownValue(t, lang, 'night'))} | ${cell(desc(t))} |\n`
      if (g.note) out += `\n${zh ? g.zhNote : g.note}\n\n`
      out += zh ? '### 其他属性\n\n' : '### Other properties\n\n'
      continue
    }
    out += `#### ${zh ? g.zh : g.title}\n\n`
    out += zh ? '| 属性 | 默认值 | 用途 |\n|---|---|---|\n' : '| Property | Default | Used for |\n|---|---|---|\n'
    for (const t of list) out += `| \`${pub(t.name)}\` | ${cell(defaultCell(t, reg, lang))} | ${cell(desc(t))} |\n`
    out += '\n'
  }
  return out.trimEnd() + '\n'
}

// Part tables: the parts by where they render, then the state attributes.
const PART_GROUPS = [
  { app: 'comments', title: 'Comment section, inside `#glowthread`', zh: '评论区，位于 `#glowthread` 内' },
  { app: 'overlay', title: 'Overlays, inside `#glowthread-portal`', zh: '浮层，位于 `#glowthread-portal` 内' },
  { app: 'hub', title: 'Moderation hub, inside `#ext-glowthread-admin`', zh: '管理中心，位于 `#ext-glowthread-admin` 内' },
  { app: 'both', title: 'Both apps and their overlays', zh: '两个应用及其浮层' },
]

export function generatePartsDocs(lang = 'en') {
  const zh = lang === 'zh'
  const cell = (s) => s.replace(/\|/g, '\\|')
  const code = (s) => '`' + s + '`'
  let out = zh
    ? '每个部件是一种元素上的类。“自定义属性”一栏标为“可以”的部件会重新解析所有自定义属性：在它上面设置的 `--glowthread-*` 属性作用于它的内部。普通的 CSS 属性需要带三个 ID 的选择器才能胜过 GlowThread 自己的规则（它们带两个 ID）：评论区用 `#glowthread#glowthread#glowthread .ext-glowthread-name { font-weight: 600 }`，浮层把 `#glowthread-portal` 写三次，管理中心把 `#ext-glowthread-admin` 写三次。这样的规则在浅色和夜间模式下都生效，所以颜色请优先使用自定义属性。\n\n'
    : 'Each part is a class on one kind of element. The parts marked "yes" under Custom properties resolve every custom property again: a `--glowthread-*` property set on one of them applies inside it. Plain CSS properties need a selector with three IDs to outweigh GlowThread\'s own rules, which carry two: `#glowthread#glowthread#glowthread .ext-glowthread-name { font-weight: 600 }` in the comment section, `#glowthread-portal` three times for the overlays, `#ext-glowthread-admin` three times for the hub. Such a rule applies in light and night mode alike, so prefer the custom properties for colours.\n\n'
  for (const g of PART_GROUPS) {
    out += `#### ${zh ? g.zh : g.title}\n\n`
    out += zh ? '| 类 | 自定义属性 | 是什么 |\n|---|---|---|\n' : '| Class | Custom properties | What it is |\n|---|---|---|\n'
    for (const p of PARTS.filter((x) => x.app === g.app)) {
      out += `| ${code(PART_PREFIX + p.name)} | ${p.container ? (zh ? '可以' : 'yes') : ''} | ${cell(zh ? p.zh : p.desc)} |\n`
    }
    out += '\n'
  }
  out += zh ? '#### 状态属性\n\n| 属性 | 所在部件 | 取值 | 含义 |\n|---|---|---|---|\n' : '#### State attributes\n\n| Attribute | On | Values | Meaning |\n|---|---|---|---|\n'
  for (const a of ATTRS) {
    const on = PARTS.filter((p) => p.attrs && p.attrs.includes(a.name)).map((p) => code(PART_PREFIX + p.name)).join(zh ? '、' : ', ')
    const values = a.values ? a.values.map(code).join(zh ? '、' : ', ') : zh ? '有或没有' : 'present or absent'
    out += `| ${code(a.name)} | ${on} | ${values} | ${cell(zh ? a.zh : a.desc)} |\n`
  }
  return out
}

// The text between the markers, or null when the file has no markers.
export function docsBlock(text, markers = MARKERS) {
  const a = text.indexOf(markers[0])
  const b = text.indexOf(markers[1])
  if (a < 0 || b < a) return null
  return text.slice(a + markers[0].length + 1, b)
}

function writeDocs(reg) {
  for (const { file, lang } of DOCS) {
    let text = readFileSync(file, 'utf8')
    const blocks = [
      { markers: MARKERS, body: generateDocs(reg, lang), what: 'token tables' },
      { markers: PART_MARKERS, body: generatePartsDocs(lang), what: 'part tables' },
    ]
    for (const { markers, body, what } of blocks) {
      if (docsBlock(text, markers) === null) {
        console.log(`${path.relative(repo, file)}: no ${markers[0]} ... ${markers[1]} markers, ${what} left unchanged`)
        continue
      }
      const a = text.indexOf(markers[0]) + markers[0].length + 1
      const b = text.indexOf(markers[1])
      text = text.slice(0, a) + body + text.slice(b)
      console.log(`${path.relative(repo, file)}: ${what} written`)
    }
    writeFileSync(file, text)
  }
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const cmd = process.argv[2]
  const reg = loadRegistry()
  if (cmd === 'css') {
    writeFileSync(CSS_FILE, generateCss(reg))
    console.log(`${path.relative(repo, CSS_FILE)} written (${reg.tokens.length} tokens)`)
  } else if (cmd === 'docs') {
    writeDocs(reg)
  } else {
    console.error('usage: node scripts/tokens.mjs css|docs')
    process.exitCode = 1
  }
}
