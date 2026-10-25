// Loads a page in headless Chromium 90 (the Android WebView era) and reports what broke.
//
// Setup, once, from this folder:
//   pnpm install      puppeteer-core 8.0.0, whose DevTools protocol matches Chromium 90
//   pnpm run setup    downloads Chromium 90.0.4430.0 for macOS arm64 (~120 MB) into .cache/
//
// Run:
//   node run.mjs <url> [--width 1440 --height 900] [--mobile] [--dpr 2] [--out shot.png]
//                [--scenario examples/scroll-bottom.mjs] [--ua "..."] [--cookies cookies.json] [--dark]
//
//   --mobile     390x844 at 2x with touch and an Android WebView user agent (Chrome/90)
//   --ua         replaces the user agent (default: this Chromium's, without "Headless")
//   --cookies    JSON array of puppeteer cookie objects. Cookies without url or domain get <url>.
//   --dark       emulates prefers-color-scheme: dark
//   --scenario   module whose default export is `async function (page, helpers)`.
//                helpers = { sleep(ms), screenshot(path), viewport(path), options }. Its return
//                value is printed as scenarioResult. If it throws, the exit code is 1.
//                screenshot() takes the full page, viewport() what is on screen (on a desktop
//                viewport, at the host display's pixel ratio: 2x on a Retina Mac).
//
// Prints JSON: { browserVersion, userAgent, consoleErrors, pageErrors, scenarioResult, screenshot }.
// The full-page screenshot is taken after the scenario, even when it throws.
// In it, fixed elements (such as Citizen's mobile bottom bar) are drawn where they sit in the
// current viewport, which can be mid-image on long pages. Use helpers.viewport() in a
// scenario to see them as a reader would.
// Prefer helpers.viewport() to page.screenshot(): puppeteer 8 always captures with
// captureBeyondViewport, which makes Chromium 90 re-match media queries and restart any CSS
// animation whose name changes at a breakpoint, so the shot shows it at its first frames.
//
// Example:
//   node run.mjs http://localhost:8151/wiki/Sandbox --mobile --out shots/sandbox-mobile.png
import { existsSync } from 'node:fs';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { parseArgs } from 'node:util';
import puppeteer from 'puppeteer-core';
import { executablePath } from './chromium.mjs';

const { values: options, positionals } = parseArgs({
  allowPositionals: true,
  options: {
    width: { type: 'string' },
    height: { type: 'string' },
    dpr: { type: 'string' },
    mobile: { type: 'boolean', default: false },
    out: { type: 'string', default: 'shot.png' },
    scenario: { type: 'string' },
    ua: { type: 'string' },
    cookies: { type: 'string' },
    dark: { type: 'boolean', default: false },
  },
});

const url = positionals[0];
if (!url) {
  console.error('Usage: node run.mjs <url> [--width N --height N --mobile --dpr N --out shot.png --scenario file.mjs --ua "..." --cookies file.json --dark]');
  process.exit(2);
}
if (!existsSync(executablePath)) {
  console.error('Chromium 90 is not installed. Run `pnpm install && pnpm run setup` in this folder first.');
  process.exit(2);
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const browser = await puppeteer.launch({
  executablePath,
  headless: true,
  args: ['--disable-gpu'],
});

let exitCode = 0;
try {
  const browserVersion = await browser.version();
  const page = await browser.newPage();

  const consoleErrors = [];
  const pageErrors = [];
  page.on('console', (message) => {
    if (message.type() === 'error') {
      const { url: source, lineNumber } = message.location();
      consoleErrors.push({ text: message.text(), source, line: lineNumber });
    }
  });
  page.on('pageerror', (error) => pageErrors.push(error.message));

  const chromeVersion = browserVersion.split('/')[1];
  const userAgent =
    options.ua ??
    (options.mobile
      ? `Mozilla/5.0 (Linux; Android 11; Pixel 5 Build/RQ3A.211001.001; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/${chromeVersion} Mobile Safari/537.36`
      : (await browser.userAgent()).replace('HeadlessChrome/', 'Chrome/'));
  await page.setUserAgent(userAgent);
  await page.setViewport({
    width: Number(options.width ?? (options.mobile ? 390 : 1440)),
    height: Number(options.height ?? (options.mobile ? 844 : 900)),
    deviceScaleFactor: Number(options.dpr ?? (options.mobile ? 2 : 1)),
    isMobile: options.mobile,
    hasTouch: options.mobile,
  });
  if (options.dark) {
    await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'dark' }]);
  }
  if (options.cookies) {
    const cookies = JSON.parse(await readFile(options.cookies, 'utf8'));
    await page.setCookie(...cookies.map((cookie) => (cookie.url || cookie.domain ? cookie : { ...cookie, url })));
  }

  await page.goto(url, { waitUntil: 'networkidle2', timeout: 60_000 });

  let scenarioResult = null;
  if (options.scenario) {
    const { default: scenario } = await import(pathToFileURL(path.resolve(options.scenario)).href);
    const helpers = {
      sleep,
      screenshot: (file) => page.screenshot({ path: file, fullPage: true }),
      viewport: async (file) => {
        const cdp = await page.target().createCDPSession();
        const { data } = await cdp.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        await cdp.detach();
        await writeFile(file, Buffer.from(data, 'base64'));
      },
      options,
    };
    try {
      scenarioResult = (await scenario(page, helpers)) ?? null;
    } catch (error) {
      scenarioResult = { error: error.message, stack: error.stack };
      exitCode = 1;
    }
  }

  await mkdir(path.dirname(path.resolve(options.out)), { recursive: true });
  await page.screenshot({ path: options.out, fullPage: true });

  const report = {
    browserVersion,
    userAgent: await page.evaluate(() => navigator.userAgent),
    consoleErrors,
    pageErrors,
    scenarioResult,
    screenshot: path.resolve(options.out),
  };
  console.log(JSON.stringify(report, null, 2));
} finally {
  await browser.close();
}
process.exit(exitCode);
