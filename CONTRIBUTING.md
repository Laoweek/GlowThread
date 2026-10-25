English | [简体中文](CONTRIBUTING.zh-CN.md)

# Contributing to GlowThread

Thank you for helping. This page explains how to set up a development wiki, where the code lives, which checks to run, and the rules for messages and documentation.

## Set up

You need:

- Docker, for the development wiki and the PHP checks.
- Node.js 24 and pnpm 11 for the browser code. With [fnm](https://github.com/Schniz/fnm): `fnm install 24 && fnm use 24`. pnpm's version is pinned in the `packageManager` field of `client/package.json`. `corepack enable` installs it.

Then follow [dev/README.md](dev/README.md). It starts a local MediaWiki with GlowThread, Echo, CheckUser and Varnish, and fills it with made-up pages, accounts and comments, plus one page of reviewed real comments from the CC0 Civil Comments archive.

## Project layout

| Path | What it holds |
|---|---|
| `extension.json` | Registration: rights, rate limits, config, hooks, REST routes, API modules, ResourceLoader modules |
| `includes/` | PHP: services, stores, REST handlers, API modules, hooks, the importer (`Import/`) |
| `maintenance/` | `importFlowThread.php`, the FlowThread importer |
| `sql/` | `tables.json` (the abstract schema) and the generated SQL per database type |
| `i18n/` | Messages: `i18n/*.json` (server), `i18n/api/` (API help), `i18n/ui/` (the browser apps) |
| `client/` | Source of the browser apps (Vue, TypeScript, Tailwind CSS), with its own [README](client/README.md) |
| `resources/init/` | The small loader that reserves space and loads the app |
| `resources/dist/` | The built bundles. Committed, see [Client](#client) |
| `tests/phpunit/` | PHP unit and integration tests |
| `tests/e2e/` | Browser tests (Playwright) against the development wiki |
| `dev/` | The development wiki (Docker), its seed content and the example Varnish configuration |
| `scripts/php-test.sh` | Runs every PHP check in throwaway MediaWiki containers |
| `docs/` | Documentation, English and [zh-CN](docs/zh-CN/) |

[docs/architecture.md](docs/architecture.md) explains how the parts fit together.

## PHP checks

```sh
scripts/php-test.sh            # MediaWiki 1.43 and 1.46
scripts/php-test.sh 1.45       # another version, by image tag
```

The script starts a throwaway `mediawiki:<version>` container per version and runs `php -l`, the `extension.json` validation, PHPUnit (unit tests, and integration tests on a SQLite wiki) and phpcs with the MediaWiki coding standard. It prints a summary and removes the containers. The first run takes a few minutes per version, mostly to install MediaWiki's development dependencies.

Echo and CheckUser are loaded by default. `GLOWTHREAD_TEST_EXTENSIONS` chooses others, for example `GLOWTHREAD_TEST_EXTENSIONS="Echo CheckUser SpamBlacklist"`, or `""` to test without any. The header of the script lists every option.

When you change the schema, edit `sql/tables.json` and regenerate the SQL files with MediaWiki's `maintenance/generateSchemaSql.php`. A change to an existing table also needs a patch file and an entry in `includes/Hooks/SchemaHooks.php`, so that `update.php` applies it.

## Client

The comment section and the moderation hub are Vue apps in `client/`. Run in `client/`:

```sh
pnpm install
pnpm typecheck
pnpm test
pnpm build
```

- `pnpm test` runs the unit tests (Vitest), including a check that every message key the source uses exists, that `en`, `qqq`, `zh-hans` and `zh-hant` have the same keys, and that no message starts or ends with a space or ends with a colon.
- `pnpm build` rebuilds `resources/dist/` and then runs `pnpm check`: it fails on JavaScript above ES2017, on CSS that the oldest supported browsers drop, and on CSS that would break when ResourceLoader flips it for right-to-left languages (a selector or an animation name that CSSJanus would change). It also writes the third-party license list next to each bundle.

**Commit the rebuilt bundles.** Wikis install GlowThread without Node.js, so `resources/dist/` must match the source. CI rebuilds it from scratch and fails when the committed files differ. Run `pnpm build` after every change in `client/` and commit `resources/dist/` with it.

The browser floor (Chrome 87, Safari 14, Firefox 78), the rules for right-to-left languages, the design tokens and how to add a component or a message are in [client/README.md](client/README.md).

## Browser tests

`tests/e2e/` holds Playwright tests that run against the development wiki: reading, writing, moderation, page states, skins, light and dark mode, desktop and phone sizes.

1. Start the development wiki from a fresh seed ([dev/README.md](dev/README.md)): `cd dev && ./setup.sh --reset`. The tests expect the seeded moderation queue, and Newbie stays inside the new-account gate for only 18 hours after a setup.
2. In `tests/e2e/`:

   ```sh
   pnpm install
   pnpm exec playwright install chromium webkit
   pnpm test
   ```

   A full run takes about 7 minutes with one worker. `cdn.spec.ts` goes through Varnish on port 8150 (`GT_CDN`). The tests for the FlowThread compatibility API skip unless the wiki runs with it on (`GLOWTHREAD_DEV_LEGACY_COMPAT=1 ./setup.sh` in `dev/`).

The header of `tests/e2e/playwright.config.ts` lists the projects and the environment variables, such as `GT_WIKI` for the wiki's address. `tests/e2e/old-chromium/` checks the comment section in a real Chromium 90, close to the oldest browser engine GlowThread supports. It runs on macOS with Apple silicon only.

## Messages and languages

- English is the source language. Add every new message to `en.json` and document it in `qqq.json` in the same folder: where it appears, what each parameter (`$1`) is, and anything a translator must know.
- The interface ships in Simplified Chinese (`zh-hans`) and Traditional Chinese (`zh-hant`). For `i18n/ui/`, add the key to both files too, because `pnpm test` requires the same keys in every file. If you cannot translate it, say so in your pull request.
- Use MediaWiki's message syntax: `$1` for parameters, `{{PLURAL:$1|…}}` and `{{GENDER:…}}` where needed. Never build sentences from pieces.
- Pass the variable part of a message as a parameter, so translators control the word order: `Held for review: $1`, not a label `Held for review:` followed by text from the template. A message never starts or ends with a space and never ends with a colon (`pnpm test` checks this). A space a message needs at its end is written `&#32;`, because translatewiki.net trims spaces at the ends of messages.
- UI messages start with `glowthread-ui-`. ResourceLoader sends the keys of `i18n/ui/en.json` with the apps (the comment section leaves out the `glowthread-ui-admin-` keys, which only the moderation hub uses), so a new key needs no change in `extension.json`.

## Code style

- PHP follows the MediaWiki coding conventions, checked by phpcs in `scripts/php-test.sh`. Tabs for indentation.
- TypeScript and Vue follow the style of the files around them. `pnpm typecheck` must pass.
- Write code comments, test names and commit messages in English. Cite interface labels by their English text or their message key.
- Non-English text belongs only in message files, special page aliases, tests that check other scripts on purpose (CJK, right-to-left, emoji), and the Chinese demo page of the development wiki (`番茄炒蛋`, with its comments and accounts), which exists for the screenshots of the Chinese documentation.
- Tests and the development wiki use made-up data only, apart from the reviewed CC0 subset in `dev/seed/content/civil-comments.json` (see `dev/seed/dataset/README.md`). Use `example.com`, `example.org` and `example.net` for links, and documentation IP ranges (`192.0.2.0/24`, `198.51.100.0/24`, `203.0.113.0/24`, `2001:db8::/32`) for addresses.
- Keep changes focused. A bug fix comes with a test that fails without it.

## Documentation

- Every document exists in English and in Simplified Chinese. Root files and folder READMEs: `X.md` and `X.zh-CN.md` (for example `dev/README.md` and `dev/README.zh-CN.md`). Under `docs/`: `docs/x.md` and `docs/zh-CN/x.md`. Each starts with a line that links its twin.
- Change both versions in the same pull request. If you cannot write the Chinese version, say so, and keep the English one accurate.
- Write for wiki administrators who may read English as a second language: short sentences, familiar words, tables for settings, the setting's exact name and default.
- When behaviour changes, update the pages that describe it: [configuration](docs/configuration.md), [moderation](docs/moderation.md), [API](docs/api.md), and the README.
- The README's screenshots come from `tests/e2e/scripts/docs-shots.mjs`. When a change alters what they show, reset the development wiki (`cd dev && ./setup.sh --reset`), run `pnpm docs-shots` in `tests/e2e/`, and commit both `docs/images/` and `docs/zh-CN/images/`.
- Design files: [PRODUCT.md](PRODUCT.md) says who GlowThread serves and its principles, and [DESIGN.md](DESIGN.md) is the source of truth for the design system: colours, type, spacing, components and the rules behind them. Read both before you change the interface. `.impeccable/design.json` holds the same design system in machine-readable form for design tools, and the `<!-- impeccable:... -->` comment in PRODUCT.md marks its format for them. When you change a token or a component's look, update DESIGN.md, then design.json to match.

## Pull requests

Describe what changed and why, and how you tested it: which checks you ran, and for interface changes, which skins and screen sizes you looked at. CI runs the PHP checks on MediaWiki 1.43 and 1.46, the client build and its tests, and the Varnish policy test on every pull request. The browser tests run nightly and on demand.

## License

By contributing, you agree that your contributions are released under the [MIT License](LICENSE), like the rest of GlowThread.
