import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import path from 'node:path'
import { LOG_ACTIONS } from '@/admin/format'

// Every log action the server can write, or show from FlowThread's old log, has a label in the
// hub's log lists. Tests run from client/.
const repo = path.resolve('..')
const json = (f: string) => JSON.parse(readFileSync(path.join(repo, f), 'utf8'))

function serverActions(): string[] {
  const actions = new Set<string>()
  // GlowThread's own log types: the action filters list every action.
  const filtered = json('extension.json').ActionFilteredLogs || {}
  for (const type of Object.keys(filtered)) {
    for (const list of Object.values(filtered[type]) as string[][]) list.forEach((a) => actions.add(a))
  }
  // FlowThread's comments log, shown on wikis with $wgGlowThreadLegacyCompat.
  const legacy = readFileSync(path.join(repo, 'includes', 'Log', 'LegacyLogType.php'), 'utf8')
  const filters = /ACTION_FILTERS = \[([\s\S]*?)\];/.exec(legacy)
  expect(filters, 'ACTION_FILTERS in LegacyLogType.php').not.toBeNull()
  for (const m of filters![1].matchAll(/=> \[([^\]]*)\]/g)) {
    for (const a of m[1].matchAll(/'([a-z-]+)'/g)) actions.add(a[1])
  }
  return [...actions].sort()
}

describe('log action labels', () => {
  it('has a label for every action the server logs', () => {
    const actions = serverActions()
    expect(actions.length).toBeGreaterThan(5)
    expect(actions.filter((a) => LOG_ACTIONS.indexOf(a) < 0)).toEqual([])
    for (const lang of ['en', 'qqq', 'zh-hans', 'zh-hant']) {
      const m = json(path.join('i18n', 'ui', lang + '.json'))
      expect(actions.filter((a) => !m['glowthread-ui-admin-log-' + a]), lang).toEqual([])
    }
  })
})
