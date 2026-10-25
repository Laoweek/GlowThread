import { describe, expect, it } from 'vitest'
import { restoreLabel, restoreLabelFor, restoreOutcome } from '@/shared/restore'
import type { ModInfo } from '@/shared/types'

const mod = (over: Partial<ModInfo>): { mod: ModInfo } => ({
  mod: { holdReasons: [], openReports: 0, reportReasons: [], statusBy: null, statusAt: null, pinnedAt: null, legacyId: null, ...over },
})

describe('what Restore does', () => {
  it('follows the status the server restores to', () => {
    expect(restoreOutcome(mod({ deletedFrom: 'held', holdReasons: ['spamblacklist'] }))).toBe('held')
    expect(restoreOutcome(mod({ deletedFrom: 'visible', holdReasons: ['newaccount'] }))).toBe('plain')
    expect(restoreOutcome(mod({ deletedFrom: 'visible' }))).toBe('plain')
  })

  it('warns that it publishes when the status before is unknown and the comment was held', () => {
    expect(restoreOutcome(mod({ deletedFrom: null, holdReasons: ['filter'] }))).toBe('publish')
    expect(restoreOutcome(mod({ holdReasons: ['filter'] }))).toBe('publish')
    expect(restoreOutcome(mod({ deletedFrom: null }))).toBe('plain')
    expect(restoreOutcome({ mod: undefined } as never)).toBe('plain')
  })

  it('labels one comment or a selection', () => {
    expect(restoreLabel('held')).toBe('restore-held')
    expect(restoreLabel('publish')).toBe('restore-publish')
    expect(restoreLabel('plain')).toBe('restore')
    const held = mod({ deletedFrom: 'held', holdReasons: ['filter'] })
    const plain = mod({ deletedFrom: 'visible' })
    const unknown = mod({ holdReasons: ['filter'] })
    expect(restoreLabelFor([held, held])).toBe('restore-held')
    expect(restoreLabelFor([held, plain])).toBe('restore')
    expect(restoreLabelFor([plain, unknown])).toBe('restore-publish')
    expect(restoreLabelFor([])).toBe('restore')
  })
})
