import { describe, expect, it, vi } from 'vitest'
import { createSettings, invalidValue, settingKey } from '@/admin/settings'
import { createHistory } from '@/admin/history'
import { errorMessage } from '@/admin/errors'
import { ApiError } from '@/lib/api'
import { accountAge, duration, expiry } from '@/admin/format'
import type { SettingsResponse } from '@/shared/types'

const REST = '/rest.php/glowthread/v1'

function settingsResponse(): SettingsResponse {
  return {
    settings: {
      newAccountHours: { value: 24, default: 24, editable: true, overridden: false, type: 'int', min: 0, max: 8760 },
      reportReasons: { value: ['spam', 'other'], default: ['spam', 'other'], editable: true, overridden: false, type: 'list', options: ['spam', 'abuse', 'other'], minItems: 1 },
      publicLog: { value: true, default: true, editable: false, overridden: false, type: 'bool' },
    },
    canConfigure: true,
    filter: { page: 'MediaWiki:GlowThread-filter', rules: 2, invalid: [] },
  }
}

function settingsSetup(post: (m: string, p: any) => any) {
  const get = vi.fn(async () => settingsResponse())
  const postFn = vi.fn(async (m: string, p: any) => post(m, p))
  const filterPage = vi.fn(async () => ({ exists: true, text: 'cheap followers\n/(broken/', timestamp: '2026-10-09T00:00:00Z', readAt: '2026-10-09T01:00:00Z', canEdit: true }))
  const s = createSettings({ restBase: REST, get: get as any, post: postFn as any, filterPage, latestLogId: async () => 321 })
  return { s, get, post: postFn, filterPage }
}

describe('settings', () => {
  it('lists the changes, refuses values the server would refuse, and saves them with the reason', async () => {
    const { s, post } = settingsSetup((m, p) => ({
      result: 'success',
      changed: Object.keys(JSON.parse(p.settings)).map((name) => ({ name, old: 24, new: 48 })),
      settings: settingsResponse().settings,
    }))
    await s.load()
    expect(s.changes.value).toEqual([])
    s.state.draft.newAccountHours = 9000
    expect(s.problems.value).toEqual({ newAccountHours: 'range' })
    s.state.draft.newAccountHours = 48
    s.state.draft.reportReasons = ['other', 'spam'] // same set, other order: no change
    expect(s.changes.value).toEqual([{ name: 'newAccountHours', old: 24, new: 48 }])
    await s.save('Too much spam')
    expect(post).toHaveBeenCalledWith('glowthreadsettings', { settings: '{"newAccountHours":48}', reason: 'Too much spam' })
    expect(s.state.saved).toEqual({ changed: [{ name: 'newAccountHours', old: 24, new: 48 }], logId: 321 })
    expect(s.changes.value).toEqual([])
  })

  it('refuses to save values the server would refuse, and a second save while one runs', async () => {
    let finish: (v: any) => void = () => undefined
    const { s, post } = settingsSetup(() => new Promise((r) => { finish = r }))
    await s.load()
    s.state.draft.newAccountHours = NaN
    await expect(s.save('x')).rejects.toThrow()
    s.state.draft.newAccountHours = 48
    const first = s.save('x')
    await expect(s.save('x')).rejects.toThrow()
    finish({ result: 'success', changed: [], settings: settingsResponse().settings })
    await first
    expect(post).toHaveBeenCalledTimes(1)
  })

  it('skips repeated filter checks and keeps the newest answer', async () => {
    const answers: Array<(v: any) => void> = []
    const { s, post } = settingsSetup(() => new Promise((r) => answers.push(r)))
    await s.load()
    s.state.test.text = 'a'
    const older = s.check()
    s.state.test.text = 'b'
    const newer = s.check()
    await s.check() // unchanged since the last one: not sent
    expect(post).toHaveBeenCalledTimes(2)
    answers[1]({ held: false, matches: [], errors: [], invalid: [], rules: 2 })
    await newer
    answers[0]({ held: true, matches: [{ line: 1, rule: 'cheap followers', type: 'literal' }], errors: [], invalid: [{ line: 2, rule: 'x', error: 'e' }], rules: 1 })
    await older
    expect(s.state.test.result!.held).toBe(false)
    expect(s.state.filter.invalid).toEqual([])
  })

  it('checks draft rules and the test text on the server', async () => {
    const { s, post } = settingsSetup(() => ({ held: true, matches: [{ line: 1, rule: 'cheap followers', type: 'literal' }], errors: [], invalid: [{ line: 2, rule: '/(broken/', error: 'missing )' }], rules: 1 }))
    await s.load()
    s.state.test.text = 'Buy cheap followers now'
    await s.check()
    expect(post).toHaveBeenCalledWith('glowthreadfiltertest', { text: 'Buy cheap followers now', rules: 'cheap followers\n/(broken/' })
    expect(s.state.filter.invalid[0].line).toBe(2)
    expect(s.state.test.result!.matches[0].line).toBe(1)
  })

  it('saves the filter page with the edit API and keeps the draft on a conflict', async () => {
    const { s, post } = settingsSetup(() => {
      throw new ApiError('editconflict')
    })
    await s.load()
    s.state.filter.draft = 'cheap followers\nfree coins'
    await expect(s.saveFilter('Add a rule')).rejects.toThrow()
    expect(post).toHaveBeenCalledWith('edit', expect.objectContaining({
      title: 'MediaWiki:GlowThread-filter', text: 'cheap followers\nfree coins', summary: 'Add a rule',
      basetimestamp: '2026-10-09T00:00:00Z', starttimestamp: '2026-10-09T01:00:00Z', nocreate: 1,
    }))
    expect(s.state.filter.draft).toBe('cheap followers\nfree coins')
    expect(s.filterDirty.value).toBe(true)
  })

  it('names settings for messages and validates lists', () => {
    expect(settingKey('newAccountHours')).toBe('new-account-hours')
    const info = settingsResponse().settings.reportReasons
    expect(invalidValue(info, [])).toBe('list')
    expect(invalidValue(settingsResponse().settings.newAccountHours, NaN)).toBe('number')
  })

  it('takes 0 (off) or at least minOn for a setting that one account alone must not turn on', () => {
    const info = { value: 3, default: 3, editable: true, overridden: false, type: 'int' as const, min: 0, minOn: 2, max: 100 }
    expect([0, 1, 2, 100, 101, -1].map((v) => invalidValue(info, v))).toEqual([null, 'range', null, null, 'range', 'range'])
  })
})

describe('the record beside the queue', () => {
  it('caches records while the moderator moves, and drops answers for subjects left behind', async () => {
    let t = 0
    const answers: Record<string, (v: any) => void> = {}
    const get = vi.fn((url: string) => new Promise((r) => { answers[url] = r }))
    const h = createHistory({ restBase: REST, get: get as any, post: vi.fn() as any, now: () => t })
    const alice = h.show({ type: 'user', name: 'Alice' })
    const bob = h.show({ type: 'user', name: 'Bob B' })
    answers[REST + '/moderation/user/Bob%20B?limit=8']({ user: { name: 'Bob B' } })
    await bob
    answers[REST + '/moderation/user/Alice?limit=8']({ user: { name: 'Alice' } })
    await alice
    expect(h.state.user!.user.name).toBe('Bob B')
    // Back to Alice within a minute: from the cache.
    await h.show({ type: 'user', name: 'Alice' })
    expect(h.state.user!.user.name).toBe('Alice')
    expect(get).toHaveBeenCalledTimes(2)
    t = 61_000
    h.show({ type: 'user', name: 'Alice' })
    expect(get).toHaveBeenCalledTimes(3)
  })
})

describe('error messages of the hub', () => {
  it('maps its own codes and falls back to the shared ones', () => {
    expect(errorMessage(new ApiError('editconflict'))).toContain('Someone else changed the rules')
    expect(errorMessage(new ApiError('badsettingvalue'))).toContain('A value is not allowed')
    expect(errorMessage(new ApiError('user-not-found'))).toContain('There is no such user')
    expect(errorMessage(new ApiError('blocked', '', { blockinfo: { blockreason: 'Flooding' } }))).toBe('You are blocked from doing that. Reason: Flooding')
    expect(errorMessage(new ApiError('permission-denied'))).toBe('You do not have permission to do that.')
    expect(errorMessage(new Error('x'))).toContain('Something went wrong')
  })
})

describe('formatting', () => {
  const now = Date.parse('2026-10-09T12:00:00Z')
  it('says how old an account is and marks young ones', () => {
    expect(accountAge('2026-10-09T11:30:00Z', now)).toEqual({ text: 'Joined less than an hour ago', young: true })
    expect(accountAge('2026-10-09T01:00:00Z', now)).toEqual({ text: 'Joined 11 hours ago', young: true })
    expect(accountAge('2026-10-01T12:00:00Z', now)).toEqual({ text: 'Joined 8 days ago', young: false })
    expect(accountAge('2026-01-01T00:00:00Z', now)).toEqual({ text: 'Joined 9 months ago', young: false })
    expect(accountAge('2024-01-01T00:00:00Z', now)).toEqual({ text: 'Joined 2 years ago', young: false })
    expect(accountAge(null, now)).toBeNull()
  })

  it('writes durations and expiries', () => {
    expect(duration(600)).toBe('10 minutes')
    expect(duration(86400)).toBe('1 day')
    expect(duration(90)).toBe('90 seconds')
    expect(duration(0)).toBe('off')
    expect(expiry('infinity')).toBe('never')
  })
})
