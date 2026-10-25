import { test, expect, PAGES, postComment, unique } from './fixtures'
import { CDN } from '../lib/wiki.mjs'

// Cache behaviour through Varnish, the dev wiki's canonical address (dev/README.md, "Varnish
// and caching"): the anonymous first page of a comment list is cached under its canonical URL
// and purged when the list changes; logged-in readers and later pages always reach MediaWiki.

interface ListResponse { cache: string | null, detail: string | null, control: string | null, setCookie: string | null, ids: number[] }

async function list(pid: number, { query = 'sort=hot', cookie = '' } = {}): Promise<ListResponse> {
  const res = await fetch(CDN + '/rest.php/glowthread/v1/page/' + pid + '/comments?' + query, { headers: cookie ? { Cookie: cookie } : {} })
  const json = await res.json()
  return {
    cache: res.headers.get('x-cache'),
    detail: res.headers.get('x-cache-detail'),
    control: res.headers.get('cache-control'),
    setCookie: res.headers.get('set-cookie'),
    ids: (json.comments || []).map((c: { id: number }) => c.id),
  }
}

test.describe('comment lists through the cache', () => {
  test.beforeEach(({ wiki }) => {
    test.skip(wiki.phone, 'HTTP only, one project is enough')
  })

  test('serves the anonymous first page from the cache, without cookies', async ({ wiki }) => {
    const pid = await wiki.pageId(PAGES.showcase)
    await list(pid)
    const second = await list(pid)
    expect(second.cache).toBe('HIT')
    // Varnish keeps it, and hands it on private: purges reach no cache in front of it.
    expect(second.control).toBe('private, must-revalidate, max-age=0, s-maxage=0')
    expect(second.setCookie).toBeNull()
  })

  test('a new comment purges both orders at once', async ({ wiki }) => {
    const pid = await wiki.pageId(PAGES.play)
    for (const query of ['sort=hot', 'sort=new']) {
      await list(pid, { query })
      expect((await list(pid, { query })).cache).toBe('HIT')
    }
    const id = await postComment(wiki, 'Admin', PAGES.play, unique('Fresh from the oven'))
    for (const query of ['sort=new', 'sort=hot']) {
      await expect.poll(async () => (await list(pid, { query })).ids, { message: query }).toContain(id)
    }
  })

  test('a comment a moderator hides leaves the cached list', async ({ wiki }) => {
    const pid = await wiki.pageId(PAGES.play)
    const id = await postComment(wiki, 'Admin', PAGES.play, unique('Soon hidden'))
    await expect.poll(async () => (await list(pid, { query: 'sort=new' })).ids).toContain(id)
    expect((await list(pid, { query: 'sort=new' })).cache).toBe('HIT')
    const r = await (await wiki.api('Moderator')).write({ action: 'glowthreadmoderate', ids: String(id), do: 'delete' })
    expect(r.glowthreadmoderate, JSON.stringify(r)).toBeTruthy()
    await expect.poll(async () => (await list(pid, { query: 'sort=new' })).ids).not.toContain(id)
  })

  test('passes logged-in readers and later pages to the wiki', async ({ wiki }) => {
    const alice = await wiki.api('Alice')
    const pid = await wiki.pageId(PAGES.load)
    const loggedIn = await list(pid, { cookie: alice.cookieHeader() })
    expect(loggedIn.detail).toBe('PASS')
    expect(loggedIn.control).not.toMatch(/public/)
    const first = await fetch(CDN + '/rest.php/glowthread/v1/page/' + pid + '/comments?sort=hot').then((r) => r.json())
    const later = await list(pid, { query: 'sort=hot&cursor=' + encodeURIComponent(first.next) })
    expect(later.detail).toBe('PASS')
    expect(later.ids.length).toBeGreaterThan(0)
  })
})

test('a logged-out reader on the cached wiki sees a comment posted a moment ago, and gets no session cookie', async ({ wiki, page, context }) => {
  const url = CDN + '/index.php?title=' + encodeURIComponent(PAGES.play) + '&useskin=' + wiki.skin
  const open = async () => {
    await page.goto(url)
    await page.waitForSelector('#glowthread', { state: 'attached' })
    await page.evaluate(() => document.getElementById('glowthread')!.scrollIntoView())
    await wiki.ready()
  }
  await open()
  const text = unique('Posted while you were reading')
  await postComment(wiki, 'Admin', PAGES.play, text)
  await expect.poll(async () => {
    await open()
    return wiki.section.locator('article', { hasText: text }).count()
  }).toBe(1)
  const cookies = (await context.cookies(CDN)).map((c) => c.name)
  expect(cookies.filter((n) => /session|Token/.test(n))).toEqual([])
})
