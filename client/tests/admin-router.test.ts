import { describe, expect, it } from 'vitest'
import { formatRoute, isFiltered, parseRoute, queueRoute, routeTitle, routeUrl, sameRoute, specialBase } from '@/admin/router'

const getUrl = (title: string) => '/wiki/' + title.replace(/ /g, '_')

describe('parsing addresses', () => {
  it('starts on the reported tab', () => {
    expect(parseRoute('', '')).toEqual(queueRoute())
    expect(parseRoute('queue', '?title=x')).toEqual(queueRoute())
  })

  it('reads the tab and filters, ignoring unknown tabs', () => {
    expect(parseRoute('queue', '?tab=held&page=Crème_brûlée&user=Some_User&q=%20spam%20')).toEqual(
      queueRoute({ tab: 'held', page: 'Crème brûlée', user: 'Some User', q: 'spam' }),
    )
    expect(parseRoute('queue', '?tab=nonsense').view === 'queue' && (parseRoute('queue', '?tab=nonsense') as any).tab).toBe('reported')
  })

  it('reads user/ and page/ subpages, with slashes in page titles', () => {
    expect(parseRoute('user/Some_User', '')).toEqual(queueRoute({ tab: 'all', user: 'Some User', subject: 'user' }))
    expect(parseRoute('page/Help:A/B_c', '')).toEqual(queueRoute({ tab: 'all', page: 'Help:A/B c', subject: 'page' }))
    expect(parseRoute('Settings', '?tab=held')).toEqual({ view: 'settings' })
    // An empty name falls back to the queue.
    expect(parseRoute('user/', '')).toEqual(queueRoute())
  })
})

describe('writing addresses', () => {
  it('gives everything by one author or on one page the short form', () => {
    expect(formatRoute(queueRoute({ tab: 'all', user: 'Alice', subject: 'user' }))).toEqual({ sub: 'user/Alice', query: {} })
    expect(formatRoute(queueRoute({ tab: 'all', page: 'Crème brûlée', subject: 'page' }))).toEqual({ sub: 'page/Crème brûlée', query: {} })
    // Another tab or another filter keeps the long form.
    expect(formatRoute(queueRoute({ tab: 'held', user: 'Alice', subject: 'user' }))).toEqual({ sub: 'queue', query: { tab: 'held', user: 'Alice' } })
    expect(formatRoute(queueRoute({ tab: 'all', user: 'Alice', q: 'x', subject: 'user' }))).toEqual({ sub: 'queue', query: { tab: 'all', user: 'Alice', q: 'x' } })
    expect(formatRoute(queueRoute())).toEqual({ sub: 'queue', query: {} })
    expect(formatRoute({ view: 'settings' })).toEqual({ sub: 'settings', query: {} })
  })

  it('round-trips through the address', () => {
    for (const r of [
      queueRoute({ tab: 'deleted', page: 'A b', user: '203.0.113.7', q: 'crème brûlée' }),
      queueRoute({ tab: 'all', user: 'Alice', subject: 'user' }),
      queueRoute({ tab: 'all', page: 'Crème brûlée', subject: 'page' }),
    ]) {
      const { sub, query } = formatRoute(r)
      expect(parseRoute(sub, '?' + new URLSearchParams(query))).toEqual(r)
    }
  })

  it('builds URLs under the localized special page', () => {
    // MediaWiki localizes special page names: Special:GlowThread is 特殊:评论管理 on a zh-hans wiki.
    expect(specialBase('特殊:评论管理/user/Alice')).toBe('特殊:评论管理')
    expect(specialBase('Special:GlowThread')).toBe('Special:GlowThread')
    expect(routeUrl('特殊:评论管理', queueRoute({ tab: 'held', q: 'a b' }), getUrl)).toBe('/wiki/特殊:评论管理/queue?tab=held&q=a+b')
    // An article path with a query string gets & instead of ?.
    expect(routeUrl('Special:GlowThread', queueRoute({ tab: 'held' }), (t) => '/index.php?title=' + t)).toBe('/index.php?title=Special:GlowThread/queue&tab=held')
    expect(routeUrl('Special:GlowThread', { view: 'settings' }, getUrl)).toBe('/wiki/Special:GlowThread/settings')
    // useskin and uselang stay across views.
    expect(routeUrl('Special:GlowThread', queueRoute({ tab: 'held' }), getUrl, { useskin: 'citizen' })).toBe('/wiki/Special:GlowThread/queue?tab=held&useskin=citizen')
  })

  it('compares routes by their address', () => {
    expect(sameRoute(queueRoute(), queueRoute({ subject: 'user' }))).toBe(true)
    expect(sameRoute(queueRoute(), queueRoute({ tab: 'held' }))).toBe(false)
    expect(isFiltered(queueRoute())).toBe(false)
    expect(isFiltered(queueRoute({ q: 'x' }))).toBe(true)
  })
})

describe('the document title', () => {
  it('names the view, with the hidden characters of a lookalike name written out', () => {
    expect(routeTitle({ view: 'settings' })).toBe('Settings')
    expect(routeTitle(queueRoute({ tab: 'held' }))).toBe('Held')
    expect(routeTitle(queueRoute({ tab: 'all', page: 'Crème brûlée', subject: 'page' }))).toBe('Comments on Crème brûlée')
    // "Moderator" with a soft hyphen (MediaWiki without AntiSpoof accepts it).
    expect(routeTitle(queueRoute({ tab: 'all', user: 'Moder' + String.fromCodePoint(0xAD) + 'ator', subject: 'user' }))).toBe('Comments by Moder[U+00AD]ator')
  })
})
