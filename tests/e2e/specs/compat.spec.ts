import { test, expect, PAGES } from './fixtures'
import { Session } from '../lib/wiki.mjs'

// FlowThread compatibility ($wgGlowThreadLegacyCompat). Off by default: a wiki that never ran
// FlowThread answers none of its API. A wiki that moved from FlowThread turns it on, and
// FlowThread's API then answers read-only from GlowThread's data. The dev wiki runs with it
// on after `GLOWTHREAD_DEV_LEGACY_COMPAT=1 ./setup.sh` in dev/ (dev/README.md).
// Special:FlowThreadLink and #flowthread links work either way (reader.spec.ts, i18n.spec.ts).

async function compatOn(): Promise<boolean> {
  const r = await new Session().api({ action: 'paraminfo', modules: 'flowthread' })
  return !!(r.paraminfo && r.paraminfo.modules && r.paraminfo.modules.length && !r.paraminfo.modules[0].missing)
}

const errorCode = (r: any) => (r.errors ? r.errors[0].code : r.error && r.error.code)

test.describe('FlowThread compatibility', () => {
  test.beforeEach(({ wiki }) => {
    test.skip(wiki.phone, 'HTTP only, one project is enough')
  })

  test('is off by default: FlowThread\'s API and log type do not exist', async ({ wiki }) => {
    test.skip(await compatOn(), 'the dev wiki runs with $wgGlowThreadLegacyCompat on (GLOWTHREAD_DEV_LEGACY_COMPAT=1)')
    const anon = new Session()
    const r = await anon.api({ action: 'flowthread', type: 'list', pageid: String(await wiki.pageId(PAGES.showcase)) })
    expect(errorCode(r)).toBe('badvalue')
    const log = await anon.api({ action: 'query', list: 'logevents', letype: 'comments' })
    expect(errorCode(log)).toBe('badvalue')
  })

  test('answers FlowThread\'s list read-only from GlowThread\'s data', async ({ wiki }) => {
    test.skip(!(await compatOn()), 'needs $wgGlowThreadLegacyCompat: run `GLOWTHREAD_DEV_LEGACY_COMPAT=1 ./setup.sh` in dev/')
    const pid = await wiki.pageId(PAGES.showcase)
    const anon = new Session()
    const r = await anon.api({ action: 'flowthread', type: 'list', pageid: String(pid), limit: '100' })
    const posts = r.flowthread.posts as Array<{ id: string, username: string, text: string, parentid: string }>
    const top = posts.filter((p) => p.parentid === '')
    // The same visible top-level comments as GlowThread's own list.
    const own = await anon.rest('/page/' + pid + '/comments?sort=new')
    const ids = [...own.pinned, ...own.comments].map((c: { id: number }) => String(c.id))
    expect(top.map((p) => p.id).sort()).toEqual(ids.sort())
    expect(r.flowthread.count).toBe(top.length)
    // Text is escaped HTML with nothing but links and line breaks.
    for (const p of posts) expect(p.text).not.toMatch(/<(?!a |\/a>|br>)/)
  })

  test('refuses FlowThread\'s writes', async ({ wiki }) => {
    test.skip(!(await compatOn()), 'needs $wgGlowThreadLegacyCompat: run `GLOWTHREAD_DEV_LEGACY_COMPAT=1 ./setup.sh` in dev/')
    const pid = String(await wiki.pageId(PAGES.play))
    const alice = await wiki.api('Alice')
    expect(errorCode(await alice.api({ action: 'flowthread', type: 'post', pageid: pid, content: 'Hello' }))).toBe('glowthread-legacy-readonly')
    expect(errorCode(await alice.api({ action: 'flowthread', type: 'post', pageid: pid, content: 'Hello' }, { post: true }))).toBe('glowthread-legacy-readonly')
  })
})
