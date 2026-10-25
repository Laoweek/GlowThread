import { describe, expect, it } from 'vitest'
import { actionFor, countDelta, frequentReporters, groupReports, inTab, transition, undoStep } from '@/admin/moderation'
import { comment, item } from './admin-fixtures'

describe('which action applies', () => {
  it('Approve (pass) approves a held comment and keeps a reported visible one', () => {
    expect(actionFor('pass', comment({ status: 'held' }))).toBe('approve')
    expect(actionFor('pass', item({}, ['Mallory']).comment)).toBe('dismiss')
    expect(actionFor('pass', comment())).toBeNull()
    expect(actionFor('pass', comment({ status: 'deleted' }))).toBeNull()
  })

  it('applies each action only where the server accepts it', () => {
    expect(actionFor('delete', comment({ status: 'held' }))).toBe('delete')
    expect(actionFor('delete', comment({ status: 'deleted' }))).toBeNull()
    expect(actionFor('restore', comment({ status: 'deleted' }))).toBe('restore')
    expect(actionFor('restore', comment({ status: 'erased' }))).toBeNull()
    expect(actionFor('erase', comment({ status: 'deleted' }))).toBe('erase')
    expect(actionFor('erase', comment())).toBeNull()
    expect(actionFor('pin', comment())).toBe('pin')
    expect(actionFor('pin', comment({ depth: 1 }))).toBeNull()
    expect(actionFor('pin', comment({ pinned: true }))).toBeNull()
    expect(actionFor('unpin', comment({ pinned: true }))).toBe('unpin')
    expect(actionFor('dismiss', comment())).toBeNull()
  })
})

describe('what an action does', () => {
  it('predicts the server state and the tab', () => {
    const reported = item({}, ['A', 'B']).comment
    const deleted = transition(reported, 'delete')
    expect(deleted.status).toBe('deleted')
    expect(deleted.mod!.openReports).toBe(0)
    expect(inTab('reported', reported)).toBe(true)
    expect(inTab('reported', deleted)).toBe(false)
    expect(inTab('deleted', deleted)).toBe(true)
    expect(inTab('all', deleted)).toBe(true)
    const held = comment({ status: 'held', mod: { holdReasons: ['filter'], openReports: 0, reportReasons: [], statusBy: null, statusAt: null, pinnedAt: null, legacyId: null } })
    // The server keeps hold reasons on record after approval.
    expect(transition(held, 'approve').mod!.holdReasons).toEqual(['filter'])
    expect(transition(held, 'approve').status).toBe('visible')
    expect(inTab('held', transition(held, 'dismiss'))).toBe(true)
    expect(transition(comment({ status: 'deleted' }), 'erase').segments).toEqual([])
    // The server keeps a pin on delete (only erase clears it).
    expect(transition(comment({ pinned: true }), 'delete').pinned).toBe(true)
    expect(transition(comment({ status: 'deleted', pinned: true }), 'erase').pinned).toBe(false)
    // The input is not changed.
    expect(reported.status).toBe('visible')
  })

  it('moves the tab counts', () => {
    const heldReported = item({ status: 'held' }, ['A']).comment
    expect(countDelta(heldReported, transition(heldReported, 'delete'))).toEqual({ reported: -1, held: -1, deleted: 1 })
    expect(countDelta(heldReported, transition(heldReported, 'dismiss'))).toEqual({ reported: -1, held: 0, deleted: 0 })
    const deleted = comment({ status: 'deleted' })
    expect(countDelta(deleted, transition(deleted, 'restore'))).toEqual({ reported: 0, held: 0, deleted: -1 })
  })
})

describe('undo', () => {
  const visible = comment()
  const held = comment({ status: 'held' })
  const before = { [visible.id]: visible, [held.id]: held }

  it('restores deleted comments that were visible, never held ones', () => {
    expect(undoStep('delete', [{ id: visible.id }, { id: held.id }], before, false)).toEqual({ do: 'restore', ids: [visible.id] })
    expect(undoStep('delete', [{ id: held.id }], before, false)).toBeNull()
  })

  it('reverses restore, pin and unpin, and nothing else without server support', () => {
    expect(undoStep('restore', [{ id: 1 }], {}, false)).toEqual({ do: 'delete', ids: [1] })
    expect(undoStep('pin', [{ id: 1 }], {}, false)).toEqual({ do: 'unpin', ids: [1] })
    expect(undoStep('unpin', [{ id: 1 }], {}, false)).toEqual({ do: 'pin', ids: [1] })
    expect(undoStep('approve', [{ id: 1 }], {}, false)).toBeNull()
    expect(undoStep('dismiss', [{ id: 1 }], {}, false)).toBeNull()
  })

  it('undoes any logged action exactly when the server can, never erase', () => {
    expect(undoStep('approve', [{ id: 1, logId: 11 }, { id: 2, logId: 12 }], {}, true)).toEqual({ do: 'undo', ids: [1, 2], logids: [11, 12] })
    expect(undoStep('delete', [{ id: held.id, logId: 5 }], before, true)).toEqual({ do: 'undo', ids: [held.id], logids: [5] })
    // Without log IDs in the answer the reverse action is used.
    expect(undoStep('approve', [{ id: 1 }], {}, true)).toBeNull()
    expect(undoStep('erase', [{ id: 1, logId: 3 }], {}, true)).toBeNull()
  })
})

describe('reports', () => {
  it('groups reports by reason with counts and reporters', () => {
    const it1 = item({}, ['Mallory', 'Carol', 'Mallory'], 'abuse')
    it1.reports[2].reason = 'spam'
    it1.reports[1].note = 'Personal attack'
    it1.comment.mod!.reportReasons = [{ reason: 'abuse', count: 2 }, { reason: 'spam', count: 1 }, { reason: 'other', count: 4 }]
    const groups = groupReports(it1)
    expect(groups.map((g) => [g.reason, g.count, g.reporters])).toEqual([
      ['other', 4, []], // beyond the 10 listed reports: counted, reporters unknown
      ['abuse', 2, ['Mallory', 'Carol']],
      ['spam', 1, ['Mallory']],
    ])
    expect(groups[1].notes).toEqual(['Personal attack'])
  })

  it('flags one reporter behind most of the loaded reports', () => {
    const items = [
      ...Array.from({ length: 6 }, () => item({}, ['Mallory'])),
      item({}, ['Carol', 'Mallory']),
      item({}, ['Carol']),
      item({ status: 'held' }),
    ]
    expect(frequentReporters(items)).toEqual({ Mallory: 7 })
    // A reporter of many items in a big backlog is not flagged below a third of it.
    const big = [...items, ...Array.from({ length: 20 }, () => item({}, ['Dave']))]
    expect(frequentReporters(big)).toEqual({ Dave: 20 })
  })
})
