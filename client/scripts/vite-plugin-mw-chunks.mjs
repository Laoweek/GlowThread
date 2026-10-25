// Code splitting for ResourceLoader. An import() in an app makes Vite write more files next
// to the bundle, <output>-<name>.js, as CommonJS that reads the others with
// require( './<file>.js' ). ResourceLoader serves each file as its own module
// (extension.json): <output>.js is ext.glowThread.<output>, <output>-<name>.js is
// ext.glowThread.<output>.<name>. This step rewrites the requires:
// - an import() in the bundle becomes mw.loader.using() of the chunk's module,
// - a chunk takes what it imports from the bundle or another chunk with
//   require( 'ext.glowThread.…' ), which must be a dependency of its module (scripts/check-dist.mjs
//   checks that).
// ResourceLoader caches each module on its own, so during a deploy a reader can get the bundle of
// one build and a chunk of another, whose exports would not match. Every file carries the build's
// ID, and a chunk refuses to run with a module of another build: the app treats that as a module
// that failed to load.
import { createHash } from 'node:crypto'

export default function mwChunks({ out }) {
  const entryModule = 'ext.glowThread.' + out
  const moduleOf = (file) => {
    const name = file.replace(/\.js$/, '')
    if (name === out) return entryModule
    return name.startsWith(out + '-') ? entryModule + '.' + name.slice(out.length + 1) : null
  }
  return {
    name: 'glowthread-mw-chunks',
    generateBundle(_options, bundle) {
      const chunks = Object.values(bundle).filter((f) => f.type === 'chunk')
      if (chunks.length < 2) return
      const build = createHash('sha256').update(chunks.map((c) => c.code).join('\n')).digest('hex').slice(0, 12)
      const check = (m) => `(function(m){if(m.__gtBuild!==${JSON.stringify(build)})throw new Error(${JSON.stringify('GlowThread: ' + m + ' is from another build')});return m})(require(${JSON.stringify(m)}))`
      for (const chunk of chunks) {
        if (!moduleOf(chunk.fileName)) this.error(`${chunk.fileName}: not named ${out}.js or ${out}-<name>.js`)
        for (const file of chunk.dynamicImports) {
          if (!chunk.isEntry) this.error(`${chunk.fileName}: import() works only in ${out}.js`)
          const m = JSON.stringify(moduleOf(file))
          chunk.code = replaceRequire(this, chunk, file, '=>', `mw.loader.using(${m}).then(function(r){return r(${m})})`)
        }
        for (const file of chunk.imports) {
          // The bundle's module runs alone: it cannot wait for another.
          if (chunk.isEntry) this.error(`${out}.js: needs ${file} when it runs`)
          chunk.code = replaceRequire(this, chunk, file, '=', check(moduleOf(file)))
        }
        chunk.code += `\nexports.__gtBuild=${JSON.stringify(build)};\n`
      }
    },
  }
}

// Only the plain forms Rolldown writes are rewritten: `() => require(...)` for an import(),
// `x = require(...)` for an import. Anything else (an interop wrapper) stops the build.
function replaceRequire(ctx, chunk, file, before, by) {
  const re = new RegExp(`(\\S?\\S?)\\s*require\\((["'\`])\\./${file.replace(/[.-]/g, '\\$&')}\\2\\)`, 'g')
  let n = 0
  const code = chunk.code.replace(re, (all, prev) => {
    if (!prev.endsWith(before) || (before === '=' && prev.endsWith('=>'))) ctx.error(`${chunk.fileName}: unexpected ${all.trim()}`)
    n++
    return prev + by
  })
  if (!n) ctx.error(`${chunk.fileName}: no require of ./${file} to rewrite`)
  return code
}
