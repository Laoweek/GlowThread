// @vitest-environment node
import { describe, expect, it } from 'vitest'
import { existsSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'

// The comment section ships in three modules (src/comments/lazy.ts): ext.glowThread.app for
// every reader, ext.glowThread.app.menu (menu.ts) once a reader opens a ⋯ menu, and
// ext.glowThread.app.extras (extras.ts) for readers who can post, report or moderate. A static
// import of one of their files from the first module would move it back there without any
// error: every reader would download it again.
const src = path.resolve('src')

/** The files a module's static imports reach from `entry`, without import(). */
function reached(entry: string): Set<string> {
  const seen = new Set<string>()
  const stack = [path.join(src, entry)]
  while (stack.length) {
    const f = stack.pop() as string
    if (seen.has(f)) continue
    seen.add(f)
    const code = readFileSync(f, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:\w'"`])\/\/.*$/gm, '$1')
    // import ... from, export ... from and import '...', but not import type or import().
    for (const m of code.matchAll(/\b(?:import|export)\s+(type\s)?[^'"]*?\bfrom\s*['"]([^'"]+)['"]|\bimport\s*['"]([^'"]+)['"]/g)) {
      const spec = m[2] || m[3]
      if (m[1] || (!spec.startsWith('.') && !spec.startsWith('@/'))) continue
      const base = spec.startsWith('@/') ? path.join(src, spec.slice(2)) : path.resolve(path.dirname(f), spec)
      const hit = [base, base + '.ts', base + '.vue', path.join(base, 'index.ts')].find((p) => existsSync(p) && statSync(p).isFile())
      if (hit) stack.push(hit)
    }
  }
  return seen
}

// What each module must keep out of the first one.
const LATER: Record<string, string[]> = {
  'comments/menu.ts': [
    'comments/components/CommentMenuContent.vue', 'shared/restore.ts', 'ui/dropdown-menu/DropdownMenuContent.vue',
    'ui/dropdown-menu/DropdownMenuItem.vue', 'ui/dropdown-menu/DropdownMenuLabel.vue', 'ui/dropdown-menu/DropdownMenuSeparator.vue',
  ],
  'comments/extras.ts': [
    'comments/components/Composer.vue', 'comments/components/EmojiPicker.vue', 'comments/components/MentionList.vue',
    'comments/mentions.ts', 'comments/units.ts', 'lib/press.ts', 'lib/text.ts', 'ui/textarea/Textarea.vue', 'ui/popover/PopoverContent.vue',
    'comments/components/ReportDialog.vue', 'comments/components/ReportForm.vue', 'ui/radio-group/RadioGroupItem.vue', 'ui/sheet/SheetContent.vue',
    'comments/components/ModTools.vue', 'comments/components/ModQueue.vue', 'comments/components/ModNotes.vue', 'comments/components/BulkBar.vue',
    'shared/reports.ts', 'ui/checkbox/Checkbox.vue',
  ],
}

describe('the comment section split', () => {
  const first = reached('comments/main.ts')

  it('loads the other modules only through lazy.ts', () => {
    expect(first.has(path.join(src, 'comments', 'lazy.ts'))).toBe(true)
    for (const entry of Object.keys(LATER)) expect(first.has(path.join(src, entry))).toBe(false)
  })

  for (const [entry, files] of Object.entries(LATER)) {
    it('keeps ' + entry + ' out of the first module', () => {
      const own = reached(entry)
      for (const f of files) {
        expect(own.has(path.join(src, f)), f + ' in ' + entry).toBe(true)
        expect(first.has(path.join(src, f)), f + ' in the first module').toBe(false)
      }
    })
  }
})
