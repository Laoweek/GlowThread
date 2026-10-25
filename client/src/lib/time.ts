import { t } from './i18n'

const pad = (n: number) => (n < 10 ? '0' : '') + n

function startOfDay(ms: number): number {
  const d = new Date(ms)
  d.setHours(0, 0, 0, 0)
  return d.getTime()
}

const dateFormats: Record<string, Intl.DateTimeFormat> = {}

/** A short date in the user language: "Sep 1" or "Sep 1, 2025" in English. */
function shortDate(ms: number, withYear: boolean): string {
  const code = mw.config.get('wgUserLanguage')
  // MediaWiki's codes are not all BCP 47 tags (sr-el, simple, zh-classical). The Gregorian
  // calendar, like the exact time (fa and th would default to others), and Latin digits here,
  // then MediaWiki's own digits below, as for every other number in the app.
  const lang = code ? mw.language.bcp47(code) + '-u-ca-gregory-nu-latn' : undefined
  const key = (lang || '') + (withYear ? ' y' : '')
  if (!dateFormats[key]) {
    const opts: Intl.DateTimeFormatOptions = withYear
      ? { year: 'numeric', month: 'short', day: 'numeric' }
      : { month: 'short', day: 'numeric' }
    try {
      dateFormats[key] = new Intl.DateTimeFormat(lang, opts)
    } catch {
      // A MediaWiki language code that is not a valid BCP 47 tag: the browser's language.
      dateFormats[key] = new Intl.DateTimeFormat(undefined, opts)
    }
  }
  return dateFormats[key].format(new Date(ms)).replace(/[0-9]/g, (d) => mw.language.convertNumber(Number(d)))
}

/**
 * Builds both short date formats before they are needed. The first Intl.DateTimeFormat loads
 * the locale's data, which would otherwise lengthen the task that draws the list.
 */
export function prepareDateFormats(): void {
  shortDate(0, false)
  shortDate(0, true)
}

/**
 * Short relative time for a comment, in the reader's time zone: just now, N minutes ago,
 * N hours ago, yesterday, N days ago (under a week), then a short date in the user
 * language, with the year only when it is not this year.
 */
export function relativeTime(iso: string, now: number = Date.now()): string {
  const then = Date.parse(iso)
  if (isNaN(then)) return ''
  const diff = Math.max(0, now - then)
  const minutes = Math.floor(diff / 60000)
  if (minutes < 1) return t('time-now')
  if (minutes < 60) return t('time-minutes', minutes)
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return t('time-hours', hours)
  const days = Math.round((startOfDay(now) - startOfDay(then)) / 86400000)
  if (days <= 1) return t('time-yesterday')
  if (days < 7) return t('time-days', days)
  return shortDate(then, new Date(then).getFullYear() !== new Date(now).getFullYear())
}

/** Exact local time for tooltips: 2026-10-08 14:23. */
export function exactTime(iso: string): string {
  const d = new Date(iso)
  if (isNaN(d.getTime())) return ''
  return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes())
}
