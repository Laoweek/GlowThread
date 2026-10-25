// Builds every app entry into resources/dist/.
//   pnpm build
// An entry is a folder src/<name>/ with a main.ts. Its bundle is written to
// resources/dist/<output>.js and <output>.css, where <output> is the folder name,
// except for the comment section, whose module (ext.glowThread.app) expects app.js.
// A new app (for example src/admin/) needs no change here.
import { readdirSync, existsSync, rmSync, mkdirSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { build } from 'vite'

const OUTPUT_NAMES = { comments: 'app' }

const root = fileURLToPath(new URL('..', import.meta.url))
const src = path.join(root, 'src')
const dist = path.join(root, '..', 'resources', 'dist')

const entries = readdirSync(src, { withFileTypes: true })
  .filter((d) => d.isDirectory() && existsSync(path.join(src, d.name, 'main.ts')))
  .map((d) => d.name)
  .sort()

rmSync(dist, { recursive: true, force: true })
mkdirSync(dist, { recursive: true })

for (const entry of entries) {
  const out = OUTPUT_NAMES[entry] || entry
  process.env.GT_ENTRY = entry
  process.env.GT_OUT = out
  console.log(`\n[glowthread] ${entry} -> resources/dist/${out}.{js,css}`)
  await build({ configFile: path.join(root, 'vite.config.ts'), root, logLevel: 'warn' })
}

await import('./check-dist.mjs')
