// A minimal `mw` for unit tests: messages come from i18n/ui/<lang>.json with $1
// parameters and {{PLURAL:$1|one|other}}, like mw.message().text().
import { readFileSync } from 'node:fs'
import path from 'node:path'

// Tests run from client/.
// &#32; keeps a space at the end of a message, as MediaWiki's MessageCache reads it.
const load = (lang: string): Record<string, string> =>
  JSON.parse(readFileSync(path.resolve('..', 'i18n', 'ui', lang + '.json'), 'utf8').replace(/&#32;/g, ' '))

export const messages: Record<string, Record<string, string>> = { en: load('en'), 'zh-hans': load('zh-hans') }
// Core messages the apps use (mediawiki.language ships them).
messages.en['word-separator'] = ' '
messages['zh-hans']['word-separator'] = ''
let lang = 'en'
export function setLang(l: string) {
  lang = l
}

function format(text: string, params: Array<string | number>): string {
  let out = text.replace(/\$(\d+)/g, (_, n) => String(params[Number(n) - 1]))
  out = out.replace(/\{\{PLURAL:([^|}]*)\|([^}]*)\}\}/g, (_, n, forms) => {
    const f = forms.split('|')
    return Number(n) === 1 ? f[0] : f[f.length - 1]
  })
  return out
}

const g = globalThis as any
g.mw = {
  // wgUserLanguage follows setLang, so dates do not depend on the machine locale.
  config: { get: (k: string) => (k === 'wgUserName' ? 'Alice' : k === 'wgPageName' ? 'Test_page' : k === 'wgUserLanguage' ? lang : null) },
  message: (key: string, ...params: Array<string | number>) => {
    const text = messages[lang][key]
    return {
      exists: () => text !== undefined,
      text: () => (text === undefined ? '⧼' + key + '⧽' : format(text, params)),
      plain: () => (text === undefined ? '⧼' + key + '⧽' : format(text, params)),
    }
  },
  user: { getName: () => 'Alice', isAnon: () => false },
  util: {
    getUrl: (title: string, params?: Record<string, string>) =>
      '/wiki/' + encodeURIComponent(title.replace(/ /g, '_')).replace(/%2F/g, '/').replace(/%3A/g, ':') +
      (params ? '?' + new URLSearchParams(params) : ''),
    wikiUrlencode: (s: string) => encodeURIComponent(s.replace(/ /g, '_')),
  },
  language: {
    convertNumber: (n: number) => String(n),
    // Like mw.language.bcp47 for the codes the tests use.
    bcp47: (code: string) => (code === 'sr-el' ? 'sr-Latn' : code),
  },
}
