// Writes the licenses of the third-party code in a bundle next to it: <output>.licenses.txt
// in resources/dist/, one entry per npm package whose code is in the bundle (name, version,
// license and the package's own license text), sorted by name, without dates, so a build
// reproduces it byte for byte. The stylesheet is built from Tailwind CSS, listed too. One
// file covers the bundle's chunks (<output>-<name>.js) as well.
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import path from 'node:path'

const LICENSE_FILE = /^(licen[cs]e|copying|notice)(\.(md|txt))?$/i

/** The package folder of a module path in node_modules, or null for the project's own code. */
function packageRoot(id) {
  const file = id.split('?')[0].replace(/\\/g, '/')
  const at = file.lastIndexOf('/node_modules/')
  if (at < 0) return null
  const rest = file.slice(at + '/node_modules/'.length).split('/')
  const name = rest[0].startsWith('@') ? rest[0] + '/' + rest[1] : rest[0]
  return file.slice(0, at) + '/node_modules/' + name
}

function describe(dir) {
  const pkg = JSON.parse(readFileSync(path.join(dir, 'package.json'), 'utf8'))
  const files = readdirSync(dir).filter((f) => LICENSE_FILE.test(f)).sort()
  const texts = files.map((f) => readFileSync(path.join(dir, f), 'utf8').replace(/\r\n/g, '\n').trim())
  return { name: pkg.name, version: pkg.version, license: pkg.license || 'see text', texts }
}

export default function licenses({ out, stylesheetFrom = ['tailwindcss'] }) {
  let root = process.cwd()
  return {
    name: 'glowthread-licenses',
    configResolved(config) {
      root = config.root
    },
    generateBundle(_options, bundle) {
      const dirs = new Set()
      const scripts = []
      for (const file of Object.values(bundle)) {
        if (file.type !== 'chunk') continue
        scripts[file.isEntry ? 'unshift' : 'push'](file.fileName)
        for (const [id, info] of Object.entries(file.modules)) {
          const dir = packageRoot(id)
          // Only code that made it into the bundle (tree-shaken modules render nothing).
          if (dir && info.renderedLength > 0) dirs.add(dir)
        }
      }
      for (const name of stylesheetFrom) {
        const dir = path.join(root, 'node_modules', name)
        if (existsSync(dir)) dirs.add(dir)
      }
      const packages = new Map()
      for (const dir of dirs) {
        const p = describe(dir)
        packages.set(p.name + '@' + p.version, p)
      }
      const list = [...packages.values()].sort((a, b) => (a.name < b.name ? -1 : a.name > b.name ? 1 : 0))
      const parts = [
        `Third-party software in ${scripts.join(', ')} and ${out}.css (GlowThread), with each package's license.`,
        ...list.map((p) => [
          '-'.repeat(72),
          `${p.name} ${p.version} (${p.license})`,
          '',
          p.texts.length ? p.texts.join('\n\n') : `The package ships no license file; its package.json names ${p.license}.`,
        ].join('\n')),
      ]
      this.emitFile({ type: 'asset', fileName: `${out}.licenses.txt`, source: parts.join('\n\n') + '\n' })
    },
  }
}
