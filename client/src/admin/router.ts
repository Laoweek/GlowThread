// Routes of Special:GlowThread. The server hands the subpage to the app
// (wgGlowThreadAdmin.subPage) and the app owns everything after it: views, filters,
// browser back and forward, and the document title. Pure functions, unit-tested.
//
//   (empty), queue?tab=held&page=T&user=U&q=text   the queue
//   user/Name                                      everything by one author, with their record
//   page/Title                                     everything on one page, with its record
//   settings                                       settings and the word filter
import { t } from '@/lib/i18n'
import { revealInvisible } from '@/lib/names'

export type Tab = 'reported' | 'held' | 'deleted' | 'all'
export const TABS: Tab[] = ['reported', 'held', 'deleted', 'all']
export type Sort = 'reports' | 'new' | 'old'
export const SORTS: Sort[] = ['reports', 'new', 'old']

/** The order a tab uses unless the moderator picks another (as the server does). */
export function defaultSort(tab: Tab): Sort {
  return tab === 'reported' ? 'reports' : 'new'
}

export interface QueueRoute {
  view: 'queue'
  tab: Tab
  /** Page title filter, as typed (spaces, not underscores). */
  page: string
  /** Author filter: user name or IP. */
  user: string
  /** Text filter. */
  q: string
  /** Only comments with an open report by this user (the flood marker leads here). */
  reporter: string
  /** Order; empty for the tab's default. */
  sort: Sort | ''
  /** The record the side panel starts with: set by the user/ and page/ routes. */
  subject: 'user' | 'page' | null
}

export interface SettingsRoute {
  view: 'settings'
}

export type Route = QueueRoute | SettingsRoute

export function queueRoute(over: Partial<QueueRoute> = {}): QueueRoute {
  return { view: 'queue', tab: 'reported', page: '', user: '', q: '', reporter: '', sort: '', subject: null, ...over }
}

const spaces = (s: string) => s.replace(/_/g, ' ').trim()

/** The route for a subpage (`user/Some_name`) and a query string (`?tab=held`). */
export function parseRoute(subPage: string, search: string): Route {
  const sub = (subPage || '').replace(/^\/+/, '')
  const params = new URLSearchParams(search || '')
  const i = sub.indexOf('/')
  const head = (i < 0 ? sub : sub.slice(0, i)).toLowerCase()
  const rest = i < 0 ? '' : spaces(sub.slice(i + 1))
  if (head === 'settings') return { view: 'settings' }
  if (head === 'user' && rest) return queueRoute({ tab: 'all', user: rest, subject: 'user' })
  if (head === 'page' && rest) return queueRoute({ tab: 'all', page: rest, subject: 'page' })
  const tab = TABS.indexOf(params.get('tab') as Tab) >= 0 ? params.get('tab') as Tab : 'reported'
  const sort = params.get('sort') as Sort
  return queueRoute({
    tab,
    page: spaces(params.get('page') || ''),
    user: spaces(params.get('user') || ''),
    q: (params.get('q') || '').trim(),
    reporter: spaces(params.get('reporter') || ''),
    sort: SORTS.indexOf(sort) >= 0 && sort !== defaultSort(tab) ? sort : '',
  })
}

/**
 * The canonical address of a route: the subpage and the query parameters. Everything by
 * one author or on one page gets the short user/ or page/ form.
 */
export function formatRoute(route: Route): { sub: string, query: Record<string, string> } {
  if (route.view === 'settings') return { sub: 'settings', query: {} }
  const r = route
  const plain = !r.q && !r.reporter && !r.sort && r.tab === 'all'
  if (plain && r.user && !r.page && r.subject === 'user') return { sub: 'user/' + r.user, query: {} }
  if (plain && r.page && !r.user && r.subject === 'page') return { sub: 'page/' + r.page, query: {} }
  const query: Record<string, string> = {}
  if (r.tab !== 'reported') query.tab = r.tab
  if (r.page) query.page = r.page
  if (r.user) query.user = r.user
  if (r.reporter) query.reporter = r.reporter
  if (r.q) query.q = r.q
  if (r.sort && r.sort !== defaultSort(r.tab)) query.sort = r.sort
  return { sub: 'queue', query }
}

/**
 * URL of a route under the special page. `base` is the special page's own title as the
 * wiki writes it (`特殊:评论管理` on a Chinese wiki), `getUrl` is mw.util.getUrl, `keep` are
 * parameters that stay from the current address (useskin, uselang).
 */
export function routeUrl(base: string, route: Route, getUrl: (title: string) => string, keep: Record<string, string> = {}): string {
  const { sub, query } = formatRoute(route)
  const url = getUrl(base + '/' + sub)
  const qs = new URLSearchParams(Object.assign({}, query, keep)).toString()
  if (!qs) return url
  return url + (url.indexOf('?') >= 0 ? '&' : '?') + qs
}

export function sameRoute(a: Route, b: Route): boolean {
  const fa = formatRoute(a)
  const fb = formatRoute(b)
  return fa.sub === fb.sub && JSON.stringify(fa.query) === JSON.stringify(fb.query)
}

/** The special page's title without the subpage: `特殊:评论管理/user/Alice` → `特殊:评论管理`. */
export function specialBase(pageName: string): string {
  const i = pageName.indexOf('/')
  return i < 0 ? pageName : pageName.slice(0, i)
}

/** Whether the route filters the queue at all (the empty state then says so). */
export function isFiltered(route: QueueRoute): boolean {
  return !!(route.page || route.user || route.q || route.reporter)
}

/** The order a queue route uses. */
export function sortOf(route: QueueRoute): Sort {
  return route.sort || defaultSort(route.tab)
}

/** The view's name in the document title. Names show their hidden characters (moderators only here). */
export function routeTitle(r: Route): string {
  if (r.view === 'settings') return t('admin-nav-settings')
  if (r.subject === 'user' && r.user && r.tab === 'all') return t('admin-title-user', revealInvisible(r.user))
  if (r.subject === 'page' && r.page && r.tab === 'all') return t('admin-title-page', r.page)
  return t('admin-tab-' + r.tab)
}
