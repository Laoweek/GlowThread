// End-to-end tests against the dev wiki (dev/README.md). Run from tests/e2e/:
//   pnpm install
//   (cd ../../dev && ./setup.sh --reset)   a fresh wiki from the seed, about 30 seconds
//   pnpm exec playwright test
// The seed provides every page, account and moderation-queue item the tests read. Tests that
// write make their own comments (on "Sandbox") and clean up what would get in the way.
// Screenshots for the design review: node scripts/review-shots.mjs (.impeccable/review/).
// Screenshots for the README: node scripts/docs-shots.mjs (docs/images/, docs/zh-CN/images/).
// The old-browser check (Chromium 90) lives in old-chromium/.
// GT_WIKI selects the wiki (default: Apache directly, http://localhost:8151, so anonymous
// page HTML is never served stale from Varnish). cdn.spec.ts goes through Varnish (GT_CDN,
// default http://localhost:8150). Functional tests run on Vector 2022 (desktop) and Minerva
// (phone), skins.spec.ts, admin-skins.spec.ts and theming.spec.ts on every skin and theme.
import { defineConfig, devices } from '@playwright/test'

const baseURL = process.env.GT_WIKI || 'http://localhost:8151'
const phone = devices['Pixel 7']
const desktop = { viewport: { width: 1440, height: 900 } }
const functional = /(reader|threads|writer|mentions|moderator|states|load|admin|security|i18n|compat|cdn)\.spec\.ts/
const skins = /(skins|admin-skins|theming)\.spec\.ts/

export default defineConfig({
  testDir: './specs',
  outputDir: './.results',
  timeout: 45_000,
  expect: { timeout: 8_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list']],
  use: { baseURL, reducedMotion: 'reduce', locale: 'en-US', timezoneId: 'UTC' },
  projects: [
    { name: 'vector-2022', testMatch: [functional, skins], use: { ...desktop, gtSkin: 'vector-2022' } },
    { name: 'minerva-phone', testMatch: [functional, skins], use: { ...phone, gtSkin: 'minerva' } },
    { name: 'vector', testMatch: skins, use: { ...desktop, gtSkin: 'vector' } },
    { name: 'vector-2022-night', testMatch: skins, use: { ...desktop, gtSkin: 'vector-2022', gtNight: true } },
    { name: 'citizen-phone', testMatch: skins, use: { ...phone, gtSkin: 'citizen' } },
    { name: 'citizen-phone-night', testMatch: skins, use: { ...phone, gtSkin: 'citizen', gtNight: true } },
    // Safari's engine, on legacy Vector, so the older skin also runs the reader and writer
    // flows. There is no Firefox project yet: Playwright's Firefox does not start in a
    // sandboxed macOS shell ("Could not find profile folder").
    { name: 'webkit', testMatch: /(reader|threads|writer|mentions|skins|admin-skins|theming)\.spec\.ts/, use: { ...devices['Desktop Safari'], ...desktop, gtSkin: 'vector' } },
  ],
})
