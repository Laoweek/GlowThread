// Old Chromium check, logged in: post with the emoji picker and an @mention, like and unlike.
//   node login-cookies.mjs Alice
//   node run.mjs 'http://localhost:8151/index.php?title=Sandbox&useskin=vector' --cookies .cache/Alice.cookies.json --scenario scenarios/writer.mjs --out shots/c90-writer.png
//   rm .cache/Alice.cookies.json
const wait = (page, fn, arg, timeout = 20000) => page.waitForFunction(fn, { timeout }, arg)
const CAKE = '\u{1F382}' // labelled with itself in the emoji picker

export default async function (page, { sleep, screenshot, options }) {
  const r = {}
  await page.waitForSelector('#glowthread')
  await page.evaluate(() => document.getElementById('glowthread').scrollIntoView())
  await wait(page, () => document.querySelector('#glowthread textarea') && !document.querySelector('#glowthread .gt-pulse'))
  await page.click('#glowthread textarea')
  const text = 'Chromium 90 test ' + Date.now().toString(36)
  await page.keyboard.type(text + ' @Bo')
  await wait(page, () => document.querySelector('[role="listbox"] [role="option"]'))
  r.mentionOptions = await page.evaluate(() => Array.from(document.querySelectorAll('[role="listbox"] [role="option"]')).map((o) => o.textContent.trim()))
  await page.keyboard.press('Enter')
  await page.click('#glowthread button[aria-label="Emoji"]')
  await wait(page, (e) => document.querySelector('#glowthread-portal button[aria-label="' + e + '"]'), CAKE)
  await screenshot(options.out.replace(/\.png$/, '-emoji.png'))
  await page.click('#glowthread-portal button[aria-label="' + CAKE + '"]')
  await sleep(300)
  r.draft = await page.$eval('#glowthread textarea', (t) => t.value)
  await page.keyboard.down('Control')
  await page.keyboard.press('Enter')
  await page.keyboard.up('Control')
  await wait(page, (t) => Array.from(document.querySelectorAll('#glowthread article')).some((a) => a.textContent.indexOf(t) >= 0), text)
  r.posted = true
  r.mentionLink = await page.evaluate((t) => {
    const a = Array.from(document.querySelectorAll('#glowthread article')).find((x) => x.textContent.indexOf(t) >= 0)
    const link = a.querySelector('p a[href*="User:Bob"]')
    return link ? link.textContent : null
  }, text)
  // Like the first comment that is not ours, then take it back.
  const like = await page.$('#glowthread article button[aria-pressed="false"]')
  if (like) {
    await like.click()
    await sleep(600)
    r.liked = await page.evaluate((b) => b.getAttribute('aria-pressed'), like)
    await like.click()
    await sleep(600)
    r.unliked = await page.evaluate((b) => b.getAttribute('aria-pressed'), like)
  }
  await sleep(500) // the new comment's entrance animation
  await screenshot(options.out.replace(/\.png$/, '-posted.png'))
  return r
}
