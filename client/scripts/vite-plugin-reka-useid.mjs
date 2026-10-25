// Lets the bundles drop the parts of Vue they do not use. Reka UI's useId
// (reka-ui/dist/shared/useId.js) imports the whole vue namespace to test `"useId" in vue`,
// which keeps every export of Vue in each bundle (about 15 kB gzip). Vue 3.5 has useId, so
// that test is always true and the same branch runs: this rewrites it to a named import.
//
// The rewrite must apply exactly. When a Reka UI upgrade changes the file, the build fails
// instead of silently keeping all of Vue again or calling the wrong thing: check the new
// file, then update the patterns below (or drop this plugin if Reka imports useId by name).
export const USE_ID_FILE = /[\\/]reka-ui[\\/]dist[\\/]shared[\\/]useId\.js$/

const REWRITES = [
  ['import * as vue from "vue";', 'import { useId as vueUseId } from "vue";'],
  ['else if ("useId" in vue) id = vue.useId?.();', 'else if (true) id = vueUseId();'],
]

/** The rewritten useId.js. Throws when the file is not the one this was written for. */
export function rewriteUseId(code) {
  let out = code
  for (const [from, to] of REWRITES) {
    if (out.split(from).length !== 2) throw new Error(`[reka-useid] reka-ui/dist/shared/useId.js changed: expected "${from}" once`)
    out = out.replace(from, to)
  }
  if (/\bvue\./.test(out)) throw new Error('[reka-useid] reka-ui/dist/shared/useId.js still reads the vue namespace')
  return out
}

export default function rekaUseId() {
  let seen = false
  return {
    name: 'reka-useid',
    enforce: 'pre',
    buildStart() {
      seen = false
    },
    transform(code, id) {
      if (!USE_ID_FILE.test(id.split('?')[0])) return null
      seen = true
      return { code: rewriteUseId(code), map: null }
    },
    buildEnd(error) {
      // Every app uses Reka UI components that call useId: a build without the file means
      // Reka moved it, and the namespace import may be back under another name.
      if (!error && !seen) throw new Error('[reka-useid] reka-ui/dist/shared/useId.js is not in the build: check where Reka UI moved useId')
    },
  }
}
