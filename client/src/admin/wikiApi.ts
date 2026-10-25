// Core Action API reads the hub needs besides GlowThread's own routes: who the moderator
// is (rights, blocks), title and name suggestions, the word-filter
// page, and what glowthreadmoderate accepts. All are GET requests without side effects.
import { actionGet } from '@/lib/api'

export interface ViewerInfo {
  name: string
  rights: string[]
  /** Any block refuses moderation, partial ones included (docs/api.md). */
  block: { reason: string, expiry: string, partial: boolean, by: string } | null
}

export async function viewerInfo(): Promise<ViewerInfo> {
  const r = await actionGet<any>({ action: 'query', meta: 'userinfo', uiprop: 'blockinfo|rights' })
  const u = (r && r.query && r.query.userinfo) || {}
  return {
    name: u.name || '',
    rights: u.rights || [],
    block: u.blockid
      ? { reason: u.blockreason || '', expiry: u.blockexpiry || 'infinite', partial: !!u.blockpartial, by: u.blockedby || '' }
      : null,
  }
}

/** Page titles starting with `text` (the page filter's suggestions). */
export async function pageSuggestions(text: string): Promise<string[]> {
  const r = await actionGet<any>({ action: 'query', list: 'prefixsearch', pssearch: text, pslimit: 8 })
  return ((r && r.query && r.query.prefixsearch) || []).map((p: { title: string }) => p.title)
}

/** User names starting with `text` (the author filter's suggestions). */
export async function userSuggestions(text: string): Promise<string[]> {
  const q = text.replace(/_/g, ' ')
  const prefix = q.charAt(0).toUpperCase() + q.slice(1)
  const r = await actionGet<any>({ action: 'query', list: 'allusers', auprefix: prefix, aulimit: 8 })
  return ((r && r.query && r.query.allusers) || []).map((u: { name: string }) => u.name)
}

/** Page ID of a title, null when the page does not exist. */
export async function resolvePage(title: string): Promise<number | null> {
  const r = await actionGet<any>({ action: 'query', titles: title })
  const page = r && r.query && r.query.pages && r.query.pages[0]
  return page && !page.missing && !page.invalid && page.pageid ? page.pageid : null
}

export interface FilterPage {
  exists: boolean
  text: string
  /** Revision timestamp, sent back as basetimestamp to detect edit conflicts. */
  timestamp: string | null
  /** When the text was read, sent back as starttimestamp. */
  readAt: string
  canEdit: boolean
}

/** The word-filter page: its text, and whether this moderator may edit it (editinterface). */
export async function filterPage(title: string): Promise<FilterPage> {
  const r = await actionGet<any>({
    action: 'query', titles: title, prop: 'revisions|info', rvprop: 'content|timestamp', rvslots: 'main',
    intestactions: 'edit', curtimestamp: 1,
  })
  const page = (r && r.query && r.query.pages && r.query.pages[0]) || {}
  const rev = page.revisions && page.revisions[0]
  return {
    exists: !page.missing,
    text: rev && rev.slots && rev.slots.main ? rev.slots.main.content || '' : '',
    timestamp: rev ? rev.timestamp : null,
    readAt: r.curtimestamp || '',
    canEdit: !!(page.actions && page.actions.edit),
  }
}

/** The newest settings entry in Special:Log/glowthread, to link to it after saving. */
export async function latestSettingsLogId(): Promise<number | null> {
  const r = await actionGet<any>({ action: 'query', list: 'logevents', letype: 'glowthread', leaction: 'glowthread/settings', lelimit: 1, leprop: 'ids' })
  const e = r && r.query && r.query.logevents && r.query.logevents[0]
  return e && e.logid ? e.logid : null
}

/** Whether glowthreadmoderate offers exact undo (`do=undo`), requested from the backend. */
export async function hasExactUndo(): Promise<boolean> {
  const r = await actionGet<any>({ action: 'paraminfo', modules: 'glowthreadmoderate' })
  const mod = r && r.paraminfo && r.paraminfo.modules && r.paraminfo.modules[0]
  const param = mod && (mod.parameters || []).find((p: { name: string }) => p.name === 'do')
  return !!(param && Array.isArray(param.type) && param.type.indexOf('undo') >= 0)
}
