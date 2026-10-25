// @vitest-environment node
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import path from 'node:path'
import rekaUseId, { USE_ID_FILE, rewriteUseId } from '../scripts/vite-plugin-reka-useid.mjs'

// scripts/vite-plugin-reka-useid.mjs rewrites Reka UI's `import * as vue` in useId.js, which
// keeps all of Vue in every bundle. A Reka UI upgrade that changes the file must fail the build.
const file = path.join(path.dirname(createRequire(import.meta.url).resolve('reka-ui')), 'shared', 'useId.js')
const installed = readFileSync(file, 'utf8')

describe('the Reka UI useId rewrite', () => {
  it('turns the namespace import into a named one and keeps the branch that runs today', () => {
    const out = rewriteUseId(installed)
    expect(out).toContain('import { useId as vueUseId } from "vue";')
    expect(out).toContain('else if (true) id = vueUseId();')
    expect(out).not.toMatch(/import \* as/)
    expect(out).not.toMatch(/\bvue\./)
  })

  it('fails when the file is not the one it was written for', () => {
    expect(() => rewriteUseId(installed.replace('import * as vue from "vue";', 'import * as Vue from "vue";'))).toThrow(/changed/)
    expect(() => rewriteUseId(installed.replace('"useId" in vue', '"useId" in vue && vue.version'))).toThrow(/changed/)
    expect(() => rewriteUseId(installed + '\nexport const v = vue.version;\n')).toThrow(/namespace/)
  })

  it('applies to the installed file only, and fails a build that never saw it', () => {
    expect(USE_ID_FILE.test(file)).toBe(true)
    const plugin = rekaUseId()
    plugin.buildStart()
    expect(plugin.transform('import * as vue from "vue";', '/app/src/lib/x.ts')).toBeNull()
    expect(() => plugin.buildEnd()).toThrow(/not in the build/)
    plugin.buildStart()
    expect(plugin.transform(installed, file)).toEqual({ code: rewriteUseId(installed), map: null })
    expect(() => plugin.buildEnd()).not.toThrow()
    // A failed build reports its own error.
    plugin.buildStart()
    expect(() => plugin.buildEnd(new Error('other'))).not.toThrow()
  })
})
