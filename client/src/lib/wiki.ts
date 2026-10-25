// Links into the wiki. Titles use canonical namespace names, which work in every language.

export function userUrl(name: string): string {
  return mw.util.getUrl('User:' + name)
}

export function contribsUrl(name: string): string {
  return mw.util.getUrl('Special:Contributions/' + name)
}

export function loginUrl(): string {
  const params: Record<string, string> = { returnto: mw.config.get('wgPageName') }
  return mw.util.getUrl('Special:UserLogin', params)
}

/** Permalink of a comment: survives page moves and works when the comment is not on page one. */
export function commentPermalink(id: number): string {
  return new URL(mw.util.getUrl('Special:GlowThreadLink/' + id), location.href).href
}

export function userRecordUrl(name: string): string {
  return mw.util.getUrl('Special:GlowThread/user/' + name)
}

export function blockListUrl(name: string): string {
  return mw.util.getUrl('Special:BlockList', { wpTarget: name })
}

export function pageUrl(title: string): string {
  return mw.util.getUrl(title)
}

/** Special:Block for a user name or IP: block, or change the current block. */
export function blockUrl(name: string): string {
  return mw.util.getUrl('Special:Block/' + name)
}

/** The block log of a user name or IP. */
export function blockLogUrl(name: string): string {
  return mw.util.getUrl('Special:Log/block', { page: 'User:' + name })
}

/** Special:Log with filters, for example { type: 'glowthread', page: title } or { logid: 12 }. */
export function logUrl(params: Record<string, string | number>): string {
  return mw.util.getUrl('Special:Log', params)
}

export function diffUrl(revId: number): string {
  return mw.util.getUrl('Special:Diff/' + revId)
}
