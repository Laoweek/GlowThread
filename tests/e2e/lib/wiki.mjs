// A small Action API client for test setup: logs in as a dev account and calls API
// modules with that session. Passwords come from dev/.env.local (never printed).
import { randomBytes } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

/** Apache directly: page HTML is never served stale from the cache. */
export const WIKI = process.env.GT_WIKI || 'http://localhost:8151'
/** Varnish, the wiki's canonical address ($wgServer): the only place comment lists are cached. */
export const CDN = process.env.GT_CDN || 'http://localhost:8150'
const ENV = fileURLToPath(new URL('../../../dev/.env.local', import.meta.url))

/** Dev wiki pages with a role (dev/README.md, "Seed content"). Page ids are looked up at run time. */
export const PAGES = {
  showcase: 'Sourdough starter', // hand-written thread: a pinned notice, a long comment, an 11-reply conversation
  load: 'Sandbox/Load test', // about 400 generated comments: several pages, a popular strip in newest order
  play: 'Sandbox', // starts empty, tests post here
  partial: 'Banana bread', // PartialBlocked is blocked from this page only
  locked: 'Buttermilk pancakes',
  closed: 'Cold brew coffee',
  empty: 'Pickled red onions', // no comments, and no test posts here
  i18n: 'Sandbox/i18n', // comments in other scripts and directions
  userPage: 'User:Alice', // a short page with three comments, and no test posts here
  kitchen: 'Kitchen talk', // a busy discussion page: hundreds of comments
}

export function password(user) {
  const key = 'GLOWTHREAD_DEV_PASSWORD_' + user.toUpperCase()
  const line = readFileSync(ENV, 'utf8').split('\n').find((l) => l.startsWith(key + '='))
  if (line) return line.slice(key.length + 1).trim().replace(/^["']|["']$/g, '')
  throw new Error('No password for ' + user + ' in dev/.env.local (run dev/setup.sh)')
}

/**
 * A client address per account for X-Forwarded-For. GlowThread's write limits include shared
 * ip-all and subnet-all keys, and every request from the host reaches the wiki from one Docker
 * address (dev/README.md, "Accounts"): without this, all test accounts would share one limit.
 * Stable per name, in 10.77.0.0/16.
 */
export function clientIp(name = 'anonymous') {
  let h = 2166136261
  for (const ch of name) h = Math.imul(h ^ ch.codePointAt(0), 16777619) >>> 0
  return '10.77.' + ((h >>> 8) % 254 + 1) + '.' + (h % 254 + 1)
}

export class Session {
  constructor(name = 'anonymous') {
    this.cookies = new Map()
    this.ip = clientIp(name)
  }
  cookieHeader() {
    return [...this.cookies].map(([k, v]) => k + '=' + v).join('; ')
  }
  store(res) {
    for (const c of res.headers.getSetCookie()) {
      const [pair] = c.split(';')
      const i = pair.indexOf('=')
      const name = pair.slice(0, i)
      const value = pair.slice(i + 1)
      if (/expires=Thu, 01 Jan 1970/i.test(c) || value === 'deleted') this.cookies.delete(name)
      else this.cookies.set(name, value)
    }
  }
  async api(params, { post = false } = {}) {
    const body = new URLSearchParams({ format: 'json', formatversion: '2', ...params })
    const url = WIKI + '/api.php' + (post ? '' : '?' + body)
    const res = await fetch(url, {
      method: post ? 'POST' : 'GET',
      headers: { Cookie: this.cookieHeader(), 'X-Forwarded-For': this.ip, ...(post ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {}) },
      body: post ? body : undefined,
    })
    this.store(res)
    return res.json()
  }
  async token(type = 'csrf') {
    const r = await this.api({ action: 'query', meta: 'tokens', type })
    return r.query.tokens[type + 'token']
  }
  async write(params) {
    return this.api({ ...params, token: await this.token() }, { post: true })
  }
  async rest(path) {
    const res = await fetch(WIKI + '/rest.php/glowthread/v1' + path, { headers: { Cookie: this.cookieHeader(), 'X-Forwarded-For': this.ip } })
    return res.json()
  }
  /** Cookies for a Playwright context. */
  playwrightCookies(url = WIKI) {
    return [...this.cookies].map(([name, value]) => ({ name, value, url }))
  }
}

export async function login(user) {
  const s = new Session(user)
  const logintoken = await s.token('login')
  const r = await s.api({ action: 'clientlogin', username: user, password: password(user), logintoken, loginreturnurl: WIKI + '/' }, { post: true })
  if (!r.clientlogin || r.clientlogin.status !== 'PASS') throw new Error('Login failed for ' + user + ': ' + JSON.stringify(r.clientlogin || r.error))
  return s
}

/**
 * A new account for one test, logged in: `prefix` and a random part, so it can be typed as one
 * @mention query. For what the dev accounts cannot show more than once, such as a user nobody
 * has notified yet or an account inside the new-account gate. The password stays in memory.
 * Returns { name, id, session }.
 */
export async function createAccount(prefix) {
  const name = prefix + Date.now().toString(36) + Math.random().toString(36).slice(2, 6)
  const s = new Session(name)
  const pass = randomBytes(18).toString('base64url')
  const r = await s.api({ action: 'createaccount', username: name, password: pass, retype: pass, createreturnurl: WIKI + '/', createtoken: await s.token('createaccount') }, { post: true })
  if (!r.createaccount || r.createaccount.status !== 'PASS') throw new Error('Account creation failed for ' + name + ': ' + JSON.stringify(r.createaccount || r.error))
  const logintoken = await s.token('login')
  const l = await s.api({ action: 'clientlogin', username: name, password: pass, logintoken, loginreturnurl: WIKI + '/' }, { post: true })
  if (!l.clientlogin || l.clientlogin.status !== 'PASS') throw new Error('Login failed for ' + name + ': ' + JSON.stringify(l.clientlogin || l.error))
  const u = await s.api({ action: 'query', meta: 'userinfo' })
  return { name, id: u.query.userinfo.id, session: s }
}

export async function pageId(title) {
  const r = await new Session().api({ action: 'query', titles: title })
  const p = r.query.pages[0]
  if (p.missing) throw new Error('No page "' + title + '" on the dev wiki (run dev/setup.sh)')
  return p.pageid
}
