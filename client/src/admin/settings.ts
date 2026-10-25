// Settings and the word filter. Settings are edited as a draft: the moderator sees the
// difference, gives a reason and saves once (glowthreadsettings, logged). The filter is a
// wiki page (MediaWiki:GlowThread-filter) saved through the edit API with a summary, so it
// keeps its history and protection. Draft rules are checked and tested by the server
// (glowthreadfiltertest) without saving anything.
import { computed, reactive } from 'vue'
import type { FilterTestResult, InvalidRule, SettingInfo, SettingsResponse, SettingsResult } from '@/shared/types'
import { ApiError } from '@/lib/api'
import type { FilterPage } from './wikiApi'

export interface SettingsDeps {
  restBase: string
  get<T>(url: string): Promise<T>
  post<T>(module: string, params: Record<string, string | number | undefined>): Promise<T>
  filterPage(title: string): Promise<FilterPage>
  latestLogId(): Promise<number | null>
}

type Status = 'idle' | 'loading' | 'ready' | 'error'
export type SettingValue = number | boolean | string[]

/** The order settings appear in, by section. */
export const SECTIONS: Array<{ id: string, settings: string[] }> = [
  { id: 'gate', settings: ['newAccountHours', 'newAccountApproved'] },
  { id: 'reports', settings: ['autoHoldReporters', 'reportReasons'] },
  { id: 'limits', settings: ['maxLength', 'maxMentions', 'duplicateWindow'] },
  { id: 'features', settings: ['publicLog'] },
]

/** Message key part of a setting: newAccountHours -> new-account-hours. */
export function settingKey(name: string): string {
  return name.replace(/[A-Z]/g, (m) => '-' + m.toLowerCase())
}

/** Unit of each number setting (message admin-unit-* and admin-value-*). */
export const UNITS: Record<string, string> = {
  newAccountHours: 'hours', newAccountApproved: 'comments', autoHoldReporters: 'people',
  maxLength: 'chars', maxMentions: 'people', duplicateWindow: 'seconds',
}

export function sameValue(a: unknown, b: unknown): boolean {
  if (Array.isArray(a) && Array.isArray(b)) return a.length === b.length && a.slice().sort().join('|') === b.slice().sort().join('|')
  return a === b
}

/** A draft value the server would refuse, or null. */
export function invalidValue(info: SettingInfo, value: unknown): 'range' | 'number' | 'list' | null {
  if (info.type === 'int') {
    if (typeof value !== 'number' || !isFinite(value) || Math.floor(value) !== value) return 'number'
    if ((info.min !== undefined && value < info.min) || (info.max !== undefined && value > info.max)) return 'range'
    // Off at `min`, otherwise at least `minOn`.
    if (info.minOn !== undefined && value !== info.min && value < info.minOn) return 'range'
  }
  if (info.type === 'list' && (!Array.isArray(value) || value.length < (info.minItems || 0))) return 'list'
  return null
}

export function createSettings(deps: SettingsDeps) {
  const state = reactive({
    status: 'idle' as Status,
    error: null as unknown,
    data: null as SettingsResponse | null,
    draft: {} as Record<string, SettingValue>,
    saving: false,
    saved: null as { changed: SettingsResult['changed'], logId: number | null } | null,
    filter: {
      status: 'idle' as Status,
      error: null as unknown,
      page: null as FilterPage | null,
      draft: '',
      invalid: [] as InvalidRule[],
      rules: 0,
      checking: false,
      saving: false,
      savedRev: null as number | null,
    },
    test: { text: '', result: null as FilterTestResult | null, error: null as unknown, running: false },
  })

  const changes = computed(() => {
    const d = state.data
    if (!d) return []
    return Object.keys(state.draft)
      .filter((name) => d.settings[name] && !sameValue(state.draft[name], d.settings[name].value))
      .map((name) => ({ name, old: d.settings[name].value, new: state.draft[name] }))
  })
  const problems = computed(() => {
    const d = state.data
    const out: Record<string, string> = {}
    if (!d) return out
    for (const c of changes.value) {
      const p = invalidValue(d.settings[c.name], c.new)
      if (p) out[c.name] = p
    }
    return out
  })
  const filterDirty = computed(() => !!state.filter.page && state.filter.draft !== state.filter.page.text)

  function resetDraft() {
    const d = state.data
    state.draft = {}
    if (!d) return
    for (const name of Object.keys(d.settings)) {
      const v = d.settings[name].value
      state.draft[name] = Array.isArray(v) ? v.slice() : v
    }
  }

  async function load() {
    state.status = 'loading'
    state.error = null
    try {
      state.data = await deps.get<SettingsResponse>(deps.restBase + '/settings')
      resetDraft()
      state.filter.invalid = state.data.filter.invalid
      state.filter.rules = state.data.filter.rules
      state.status = 'ready'
    } catch (e) {
      state.status = 'error'
      state.error = e
      return
    }
    await loadFilter()
  }

  async function loadFilter() {
    if (!state.data) return
    state.filter.status = 'loading'
    state.filter.error = null
    try {
      const page = await deps.filterPage(state.data.filter.page)
      state.filter.page = page
      state.filter.draft = page.text
      state.filter.status = 'ready'
    } catch (e) {
      state.filter.status = 'error'
      state.filter.error = e
    }
  }

  /** Saves the changed settings with a reason. */
  async function save(reason: string): Promise<SettingsResult> {
    // Never send a value the server would refuse or read as "reset" (NaN becomes null in JSON).
    if (state.saving || Object.keys(problems.value).length) throw new ApiError('badsettingvalue')
    const body: Record<string, SettingValue> = {}
    for (const c of changes.value) body[c.name] = c.new as SettingValue
    state.saving = true
    try {
      const r = await deps.post<SettingsResult>('glowthreadsettings', { settings: JSON.stringify(body), reason })
      if (state.data) state.data.settings = r.settings
      resetDraft()
      // The server names its log entry; older servers did not, then the newest entry is used.
      let logId: number | null = typeof r.logId === 'number' ? r.logId : null
      if (logId === null && r.changed.length) {
        try {
          logId = await deps.latestLogId()
        } catch {
          // the log link then points to the settings log as a whole
        }
      }
      state.saved = { changed: r.changed, logId }
      return r
    } finally {
      state.saving = false
    }
  }

  /**
   * Checks the draft rules and runs the test text through them. The server parses the
   * draft exactly as it will parse the saved page, so invalid lines are flagged before
   * saving.
   */
  // Checks count against a rate limit: skip repeats, and keep only the newest answer.
  let checked = ''
  let checkSeq = 0

  async function check() {
    if (!state.data || !state.data.canConfigure) return
    const text = state.test.text
    const rules = state.filter.draft
    const key = text + '\u0000' + rules
    if (key === checked) return
    checked = key
    const seq = ++checkSeq
    state.filter.checking = true
    state.test.running = !!text.trim()
    try {
      const r = await deps.post<FilterTestResult>('glowthreadfiltertest', { text: text.trim() ? text : '-', rules })
      if (seq !== checkSeq) return
      state.filter.invalid = r.invalid
      state.filter.rules = r.rules
      state.test.result = text.trim() ? r : null
      state.test.error = null
    } catch (e) {
      if (seq !== checkSeq) return
      checked = '' // a failed check may be repeated
      state.test.error = e
    } finally {
      if (seq === checkSeq) {
        state.filter.checking = false
        state.test.running = false
      }
    }
  }

  /** Saves the word-filter page with a summary. An edit conflict reloads nothing: the draft stays. */
  async function saveFilter(summary: string): Promise<{ newrevid?: number, nochange?: boolean }> {
    const page = state.filter.page
    if (!page || !state.data) throw new Error('filter not loaded')
    state.filter.saving = true
    try {
      const r = await deps.post<any>('edit', {
        title: state.data.filter.page,
        text: state.filter.draft,
        summary,
        basetimestamp: page.timestamp || undefined,
        starttimestamp: page.readAt || undefined,
        createonly: page.exists ? undefined : 1,
        nocreate: page.exists ? 1 : undefined,
      })
      if (!r || r.result !== 'Success') throw new ApiError('editfailed')
      state.filter.savedRev = r.newrevid || null
      // Read the saved page back, and the server's own parse of it.
      const settings = await deps.get<SettingsResponse>(deps.restBase + '/settings')
      if (state.data) state.data.filter = settings.filter
      state.filter.invalid = settings.filter.invalid
      state.filter.rules = settings.filter.rules
      await loadFilter()
      return { newrevid: r.newrevid, nochange: !!r.nochange }
    } finally {
      state.filter.saving = false
    }
  }

  return { state, changes, problems, filterDirty, load, loadFilter, resetDraft, save, check, saveFilter }
}

export type Settings = ReturnType<typeof createSettings>
