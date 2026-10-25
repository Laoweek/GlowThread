// Downloads the pinned Chromium 90 build (chromium.mjs) into .cache/.
// The archive is untrusted: it is saved into its own empty directory, checked against the
// pinned SHA-256, and unpacked with ditto. Nothing from it runs except the browser binary.
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { createWriteStream, existsSync } from 'node:fs';
import { mkdir, readFile, rename, rm } from 'node:fs/promises';
import path from 'node:path';
import { Readable } from 'node:stream';
import { pipeline } from 'node:stream/promises';
import { BUILD, CACHE_DIR, browserDir, executablePath } from './chromium.mjs';

if (process.platform !== 'darwin' || process.arch !== 'arm64') {
  console.error(
    `The pinned build is for macOS on Apple Silicon. This machine is ${process.platform}-${process.arch}.`
  );
  process.exit(1);
}

if (!existsSync(executablePath)) {
  const downloadDir = path.join(CACHE_DIR, `download-${BUILD.revision}`);
  const zipPath = path.join(downloadDir, 'chromium-mac-arm64.zip');
  await rm(downloadDir, { recursive: true, force: true });
  await mkdir(downloadDir, { recursive: true });

  console.log(`Downloading Chromium ${BUILD.version} (r${BUILD.revision}), about 120 MB`);
  const response = await fetch(BUILD.url);
  if (!response.ok) {
    throw new Error(`Download failed: HTTP ${response.status} for ${BUILD.url}`);
  }
  await pipeline(Readable.fromWeb(response.body), createWriteStream(zipPath));

  const sha256 = createHash('sha256').update(await readFile(zipPath)).digest('hex');
  if (sha256 !== BUILD.sha256) {
    throw new Error(`SHA-256 mismatch, not unpacking ${zipPath}: got ${sha256}, want ${BUILD.sha256}`);
  }

  // Unpack next to the final location, then rename, so a failed unpack never looks installed.
  const partialDir = `${browserDir}.partial`;
  await rm(partialDir, { recursive: true, force: true });
  await rm(browserDir, { recursive: true, force: true });
  execFileSync('/usr/bin/ditto', ['-x', '-k', zipPath, partialDir]);
  await rename(partialDir, browserDir);
  await rm(downloadDir, { recursive: true, force: true });
}

const version = execFileSync(executablePath, ['--version'], { encoding: 'utf8' }).trim();
console.log(`${version} ready at ${executablePath}`);
