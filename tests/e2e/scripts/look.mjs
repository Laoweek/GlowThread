// Quick look during development. Run from tests/e2e/:
//   node scripts/look.mjs <title> <out> [--phone] [--user U] [--dark] [--skin s] [--hash #frag] [--viewport] [--do "click:selector" ...]
// Without --skin, the wiki's default for the device: Vector 2022 on desktop, Minerva on a phone.
import { chromium, devices } from '@playwright/test'
import { login, WIKI } from '../lib/wiki.mjs'
const args = process.argv.slice(2)
const flag = (n) => args.includes('--' + n)
const opts = (n) => args.flatMap((a, i) => (a === '--' + n ? [args[i + 1]] : []))
const [title, out] = args
const phone = flag('phone')
const dark = flag('dark')
const browser = await chromium.launch()
const ctx = await browser.newContext(phone ? { ...devices['Pixel 7'], colorScheme: dark ? 'dark' : 'light' } : { viewport: { width: 1440, height: 900 }, colorScheme: dark ? 'dark' : 'light' })
const user = opts('user')[0]
if (user) await ctx.addCookies((await login(user)).playwrightCookies())
if (dark) await ctx.addInitScript(() => { try { localStorage.setItem('mwclientpreferences', 'skin-theme-clientpref-night') } catch (e) {} })
const page = await ctx.newPage()
const errors = []
page.on('console', (m) => { if (m.type() === 'error' || m.type() === 'warning') errors.push(m.type() + ' ' + m.text().slice(0, 300)) })
page.on('pageerror', (e) => errors.push('pageerror ' + e.message))
const skin = opts('skin')[0]
const night = (skin || (phone ? 'minerva' : 'vector-2022')) === 'minerva' ? '&minervanightmode=1' : '&vectornightmode=1'
const q = (skin ? '&useskin=' + skin : '') + (dark ? night : '')
await page.goto(WIKI + '/index.php?title=' + encodeURIComponent(title) + q + (opts('hash')[0] || ''))
await page.evaluate(() => document.getElementById('glowthread') && document.getElementById('glowthread').scrollIntoView())
await page.waitForSelector('#glowthread .gt-root article', { timeout: 15000 }).catch(() => errors.push('no articles'))
await page.waitForTimeout(800)
for (const step of opts('do')) {
  const [kind, ...rest] = step.split(':')
  const arg = rest.join(':')
  if (kind === 'click') await page.click(arg)
  else if (kind === 'type') await page.keyboard.type(arg)
  else if (kind === 'press') await page.keyboard.press(arg)
  else if (kind === 'wait') await page.waitForTimeout(Number(arg))
  else if (kind === 'fill') { const [sel, txt] = arg.split('=>'); await page.fill(sel, txt) }
  await page.waitForTimeout(350)
}
if (flag('viewport')) await page.screenshot({ path: out })
else await (await page.$('#glowthread')).screenshot({ path: out })
console.log(JSON.stringify(errors))
await browser.close()
