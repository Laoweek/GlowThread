// Small formatting helpers of the hub: account age, durations, block expiry.
import { t, num } from '@/lib/i18n'
import { exactTime } from '@/lib/time'

const HOUR = 3600_000
const DAY = 24 * HOUR

/** Accounts younger than this are marked in the queue (a common sign of throwaway accounts). */
export const YOUNG_ACCOUNT_DAYS = 7

/** "Joined 3 days ago" from a registration time; `young` for accounts under a week. */
export function accountAge(iso: string | null, now: number = Date.now()): { text: string, young: boolean } | null {
  if (!iso) return null
  const then = Date.parse(iso)
  if (isNaN(then)) return null
  const ms = Math.max(0, now - then)
  const young = ms < YOUNG_ACCOUNT_DAYS * DAY
  if (ms < HOUR) return { text: t('admin-age-new'), young }
  if (ms < DAY) return { text: t('admin-age-hours', Math.floor(ms / HOUR)), young }
  const days = Math.floor(ms / DAY)
  if (days < 60) return { text: t('admin-age-days', days), young }
  if (days < 730) return { text: t('admin-age-months', Math.floor(days / 30)), young }
  return { text: t('admin-age-years', Math.floor(days / 365)), young }
}

/** A number of seconds as the largest whole unit: 600 → 10 minutes. */
export function duration(seconds: number): string {
  if (seconds <= 0) return t('admin-duration-off')
  if (seconds % 86400 === 0) return t('admin-duration-days', num(seconds / 86400))
  if (seconds % 3600 === 0) return t('admin-duration-hours', num(seconds / 3600))
  if (seconds % 60 === 0) return t('admin-duration-minutes', num(seconds / 60))
  return t('admin-duration-seconds', num(seconds))
}

/** Block expiry: never, or the exact local time. */
export function expiry(value: string): string {
  return /^infinit/.test(value) ? t('admin-block-forever') : exactTime(value)
}

/**
 * Log actions with a label (admin-log-<action>): GlowThread's own, and FlowThread's on wikis
 * that came from it (enable, disable, import). tests/log-actions.test.ts checks this list
 * against the actions the server registers.
 */
export const LOG_ACTIONS = ['delete', 'recover', 'approve', 'markchecked', 'pin', 'unpin', 'erase', 'enable', 'lock', 'disable', 'settings', 'import', 'undo', 'autohold']
