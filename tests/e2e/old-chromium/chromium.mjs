// The pinned old Chromium build. setup.mjs downloads it, run.mjs launches it.
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));

// Chromium 90.0.4430.0 (r857950, the Chrome 90 branch point), native Apple Silicon build.
// Google's Mac_Arm snapshot bucket only goes back to r882387 (Chrome 92), so this comes
// from Playwright's CDN, which still serves the build that playwright-core 1.10.0 pinned.
export const BUILD = {
  revision: '857950',
  version: '90.0.4430.0',
  url: 'https://cdn.playwright.dev/builds/chromium/857950/chromium-mac-arm64.zip',
  sha256: '32bca9f6e2d1aa871a323e07d0136bb5086d465bf44672d5dad261425823fddf',
};

export const CACHE_DIR = path.join(here, '.cache');
export const browserDir = path.join(CACHE_DIR, `chromium-${BUILD.revision}`);
export const executablePath = path.join(
  browserDir,
  'chrome-mac',
  'Chromium.app',
  'Contents',
  'MacOS',
  'Chromium'
);
