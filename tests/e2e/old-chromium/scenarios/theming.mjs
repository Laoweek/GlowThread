// Old Chromium check of theming, logged out: GlowThread's defaults render (each public
// --glowthread-* property is read with a fallback), site CSS reaches the section and the
// overlay container, the three-ID escape hatch wins, and the night switch class works.
// Throws (exit code 1) on the first value that differs. Expected defaults come from the
// token registry (client/src/design/tokens.json).
//   node run.mjs 'http://localhost:8151/index.php?title=Sourdough_starter&useskin=vector-2022' --scenario scenarios/theming.mjs --out shots/c90-theming.png
import { readFileSync } from 'node:fs'

const registry = JSON.parse(readFileSync(new URL('../../../../client/src/design/tokens.json', import.meta.url), 'utf8'))
const token = Object.fromEntries(registry.tokens.map((t) => [t.name, t]))
const SITE_CSS = `:root { --glowthread-color-fg: rgb(1, 2, 3); --glowthread-radius-control: 3px }
.ext-glowthread-theme { --glowthread-color-surface: rgb(7, 8, 9) }
#glowthread#glowthread#glowthread .ext-glowthread-name { color: rgb(255, 0, 0) }`

const wait = (page, fn, timeout = 20000) => page.waitForFunction(fn, { timeout })

export default async function (page, { sleep, viewport, options }) {
  const r = {}
  await page.waitForSelector('#glowthread')
  await page.evaluate(() => document.getElementById('glowthread').scrollIntoView())
  await wait(page, () => document.querySelector('#glowthread article') && !document.querySelector('#glowthread .gt-pulse'))
  // A colour as the browser computes it, for comparing with the registry's hex values.
  const colour = (v) => page.evaluate((c) => {
    const s = document.createElement('span')
    s.style.color = c
    document.body.appendChild(s)
    const out = getComputedStyle(s).color
    s.remove()
    return out
  }, v)
  const look = () => page.evaluate(() => {
    const cs = (sel, prop) => {
      const el = document.querySelector(sel)
      return el ? getComputedStyle(el).getPropertyValue(prop).trim() : null
    }
    return {
      text: cs('#glowthread article .ext-glowthread-body', 'color'),
      name: cs('#glowthread article .ext-glowthread-name', 'color'),
      radius: cs('#glowthread article .ext-glowthread-menu-trigger', 'border-top-left-radius'),
      menu: cs('#glowthread-portal .ext-glowthread-menu', 'background-color'),
    }
  })
  const check = (label, got, want) => {
    r[label] = got
    if (got !== want) throw new Error(label + ': got ' + got + ', want ' + want)
  }
  // Defaults, from the fallbacks.
  const before = await look()
  check('default text', before.text, await colour(token['color-fg'].light))
  check('default radius', before.radius, token['radius-control'].light)
  // Site CSS, at the start of <head> as a skin module would put it.
  await page.evaluate((css) => {
    const s = document.createElement('style')
    s.textContent = css
    document.head.insertBefore(s, document.head.firstChild)
  }, SITE_CSS)
  await sleep(200)
  const after = await look()
  check('site text', after.text, 'rgb(1, 2, 3)')
  check('site radius', after.radius, '3px')
  check('three IDs', after.name, 'rgb(255, 0, 0)')
  await page.click('#glowthread article .ext-glowthread-menu-trigger')
  await wait(page, () => document.querySelector('#glowthread-portal .ext-glowthread-menu'))
  await sleep(400)
  check('site menu surface', (await look()).menu, 'rgb(7, 8, 9)')
  await viewport(options.out.replace(/\.png$/, '-menu.png'))
  await page.keyboard.press('Escape')
  await sleep(300)
  // The night switch: night values (the site set light ones only, so GlowThread's night defaults).
  await page.evaluate(() => document.documentElement.classList.add('ext-glowthread-night'))
  await sleep(200)
  check('night text', (await look()).text, await colour(token['color-fg'].night))
  return r
}
