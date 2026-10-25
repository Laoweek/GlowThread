// Writes the session cookies of a dev wiki account for run.mjs --cookies.
//   node login-cookies.mjs Alice        -> .cache/Alice.cookies.json (gitignored, delete after use)
// Passwords come from dev/.env.local through ../lib/wiki.mjs.
import { mkdirSync, writeFileSync } from 'node:fs'
import { login, WIKI } from '../lib/wiki.mjs'

const user = process.argv[2] || 'Alice'
const s = await login(user)
mkdirSync('.cache', { recursive: true })
const file = `.cache/${user}.cookies.json`
writeFileSync(file, JSON.stringify([...s.cookies].map(([name, value]) => ({ name, value, url: WIKI }))), { mode: 0o600 })
console.log(file)
