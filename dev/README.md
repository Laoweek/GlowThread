English | [简体中文](README.zh-CN.md)

# GlowThread dev wiki

A local MediaWiki 1.43 for developing and testing GlowThread. It is a small English community cookbook ("GlowThread Dev") with made-up pages, accounts and comment threads, served through Varnish like a wiki with a cache in front. Nothing in it comes from a real wiki. One page, `Kitchen talk`, holds real public comments from the CC0 Civil Comments archive (news-site comments, selected and reviewed by hand) for real-world volume and thread shape.

```
browser ── 127.0.0.1:8150 ──> varnish (9.0, varnish/default.vcl)
                                  │
browser ── 127.0.0.1:8151 ──> mediawiki (official mediawiki:1.43 image, Apache + PHP 8.3) ──> db (MariaDB 10.11)
                              jobrunner (same image, runs queued jobs)                    ──┘
```

## Requirements

- Docker with Compose v2: Docker Desktop, [Colima](https://github.com/abiosoft/colima) (`colima start`) or Docker Engine on Linux.
- git and bash.
- About 3 GB of disk for the images (mediawiki 1.5 GB, varnish 0.5 GB, mariadb 0.4 GB, composer 0.3 GB) and a few hundred MB for the database.

PHP, Composer and Node are not needed on your machine. Everything runs in containers.

## Quick start

```sh
cd dev
./setup.sh            # first run: image downloads, then about 30 seconds
open http://localhost:8150/wiki/Sourdough_starter
```

`setup.sh` is safe to run again at any time, and a run with nothing to do takes a few seconds. Each run:

1. fetches the pinned third-party code (`fetch.sh`) and generates missing passwords in `.env.local`,
2. starts or recreates the containers (Compose project `glowthread`),
3. installs MediaWiki on the first run, then runs `update.php` so schema changes apply,
4. saves the seed pages, creates the accounts, sets their registration dates and places the blocks,
5. seeds comments on every page that has none yet,
6. runs queued jobs and empties the Varnish cache.

| Command | What it does |
|---|---|
| `./setup.sh --reset` | Deletes the wiki (database, installer output, logs) and sets it up again from the seed. Keeps `.env.local` and the fetched code. |
| `docker compose stop` | Stops the containers. `./setup.sh` or `docker compose start` starts them again. |
| `docker compose down --volumes` | Deletes the containers and the wiki. |
| `varnish/test.sh` | Tests the cache policy (no running wiki needed). |
| `./import-test.sh` | The FlowThread import test (see [Legacy mode](#legacy-mode-and-the-import-test)). |

## Services and ports

| URL or service | What |
|---|---|
| http://localhost:8150/ | The wiki through Varnish. This is `$wgServer`: links and purges use it. |
| http://localhost:8151/ | Apache directly, bypassing Varnish. Links on its pages still point to 8150. |
| `db` | MariaDB, not published. `docker compose exec db mariadb -uwikiuser -p glowthread` (password `GLOWTHREAD_DEV_DB_PASSWORD` in `.env.local`). Tables start with `gt_`. |
| `jobrunner` | Runs jobs as soon as they are queued (`$wgJobRunRate = 0`, so page views run none). |

All ports listen on 127.0.0.1 only. Use `localhost`, not `127.0.0.1`: Varnish caches comment lists only for the Host that MediaWiki purges (`localhost:8150`).

If your shell sets `http_proxy`, make sure local requests skip it (`NO_PROXY=localhost`, or `curl --noproxy localhost`).

Settings through environment variables:

| Variable | Default | Effect |
|---|---|---|
| `GLOWTHREAD_DEV_PORT` | `8150` | Varnish port, and the port in `$wgServer` |
| `GLOWTHREAD_DEV_DIRECT_PORT` | `8151` | Apache port |
| `GLOWTHREAD_SRC` | `..` | The GlowThread checkout to mount (see [Another checkout](#another-checkout)) |
| `GLOWTHREAD_DEV_LEGACY` | `0` | `1` loads FlowThread instead of GlowThread |
| `GLOWTHREAD_DEV_LEGACY_COMPAT` | `0` | `1` turns on `$wgGlowThreadLegacyCompat`. Cannot be combined with `GLOWTHREAD_DEV_LEGACY=1` |
| `GLOWTHREAD_DEV_DB` | `mariadb` | `mysql` runs MySQL 8.0 instead (see [MySQL](#mysql)) |
| `GLOWTHREAD_DEV_MW_IMAGE` | `mediawiki:1.43` | Another MediaWiki image, for example a newer 1.43 patch release |
| `GLOWTHREAD_DEV_LOAD_TEST_COMMENTS` | `400` | Comments generated on `Sandbox/Load test` (only when it has none, so use it with `--reset`) |

Pass them to every `setup.sh` run, for example `GLOWTHREAD_DEV_PORT=8250 GLOWTHREAD_DEV_DIRECT_PORT=8251 ./setup.sh`. Compose itself does not read `.env.local`, so start the stack with `./setup.sh`. After a first run, a plain `docker compose up -d` also works (with the default settings), because the database keeps the passwords it was created with.

## Accounts

Passwords are generated into `dev/.env.local` (mode 600, gitignored), one variable per account, for example `GLOWTHREAD_DEV_PASSWORD_ALICE`. `setup.sh` never prints them.

| Account | Groups | Registered | Role |
|---|---|---|---|
| Admin | sysop, bureaucrat, checkuser | 5 years ago | Installs the wiki, saves the seed pages, places the blocks |
| Moderator | sysop | 4 years ago | Pins notices, deletes, locks and closes pages in the seed |
| Alice, Bob, Carol, Dave, Erin | | 2 to 3 years ago | Regular members |
| Trent | trusted | 6 years ago | Long-time member. The dev group `trusted` has `glowthread-trusted`: no new-account gate, never held by reports alone |
| Mallory | | 21 months ago | Spammer and troll persona |
| Newbie | | 6 hours before the last `setup.sh` run | Inside GlowThread's new-account gate (24 hours), so its comments are held for review |
| Blocked | | 2 years ago | Blocked sitewide |
| PartialBlocked | | 2 years ago | Blocked from the page "Banana bread" only |
| BlockedMod | sysop | 3 years ago | A moderator who is blocked sitewide (blocked moderators cannot moderate) |
| 老陈, 麦穗, 林小满, 阿芳, 橘子汽水, 豆豆不吃豆, 养生小贴士, 闲置转让 | | 12 days to 5.5 years ago | Demo accounts: members who write only on the Chinese demo page `番茄炒蛋`. They have no password (and no variable in `.env.local`), so nobody can log in as them |

The blocks have no expiry and autoblock off: every dev request comes from the same few Docker addresses, so an autoblock would block the other accounts too. Each run sets any account password that differs from `.env.local`. Newbie's registration date moves with each run, so run `./setup.sh` again if Newbie should be gated and the last run was more than 18 hours ago. After such a rerun, Newbie seems to have registered after its own seeded comment, which is harmless.

Deleting `.env.local` while the database volume exists stops `setup.sh` before it changes anything, because the database keeps its first passwords. Run `./setup.sh --reset` then.

Log in from a script with the Action API (`action=query&meta=tokens&type=login`, then `action=clientlogin`). The session cookie is `glowthread_gt__session`.

MediaWiki trusts `X-Forwarded-For` from the Docker networks, so a test can pose as another client by sending `X-Forwarded-For: 203.0.113.7` (on either port). GlowThread's write limits include per-IP keys (`ip-all`, `subnet-all`) that all accounts would otherwise share here, so scripts that write a lot should send their own address per account.

## Seed content

The wiki is seeded from `seed/`:

| Folder or file | Content |
|---|---|
| `seed/content/` | The cookbook: `pages.json` and `pages/` (Main Page, nine recipes, `Kitchen talk`, the Chinese recipe `番茄炒蛋`, `Sandbox`, `Sandbox/i18n`), comment threads in `comments.json`, `zh-hans.json` (Chinese comments for `番茄炒蛋`), `civil-comments.json` (CC0, see `seed/dataset/README.md`) and any other comments file of that shape, `fragments.json` (sentence pieces for the load-test page), `i18n.json` (comments in other scripts and directions for `Sandbox/i18n`) and `flowthread.json` (legacy mode). Its `README.md` describes the format. |
| `seed/site/` | Pages the dev setup adds: `MediaWiki:Spam-blacklist` (blocks links to `spam.example`), `User:Alice` and `User:Trent` with comments on `User:Alice`, and `Sandbox/Load test`. |

Pages with a role:

| Page | State | What to test there |
|---|---|---|
| Sourdough starter | open | The showcase: 47 hand-written comments with a pinned notice, popular comments, a held comment by Newbie, a comment deleted by Moderator and open reports |
| Banana bread | open | Mallory's spam link, held by the spam blacklist. PartialBlocked is blocked here. |
| Buttermilk pancakes | locked | Comments stay, nobody can add new ones |
| Cold brew coffee | closed | Comments hidden (GlowThread state `disabled`) |
| Pickled red onions | open | No comments: the empty state |
| Kitchen talk | open | 414 real public comments in 324 threads from the CC0 Civil Comments archive: real lengths, reply shapes and like counts (`seed/dataset/README.md`) |
| 番茄炒蛋 | open | The Chinese demo page: a recipe in Chinese with 20 Chinese comments by the eight demo accounts and Moderator. A pinned notice, likes, @mentions, emoji, a link and open reports on two comments. The Chinese screenshots of the documentation are taken here (`tests/e2e/scripts/docs-shots.mjs`), the English ones on Sourdough starter |
| Sandbox | open | Starts empty, for test scripts |
| Sandbox/Load test | open | Load and pagination tests: 400 comments (`GLOWTHREAD_DEV_LOAD_TEST_COMMENTS`) over 17 months, with replies and likes, generated from `fragments.json` by a seeded random generator, so every run produces the same thread |
| Sandbox/i18n | open | Chinese, Japanese, Korean, Arabic, Hebrew, mixed directions, emoji sequences, combining marks, a long unbroken string |
| User:Alice | open | Comments on a user page notify its owner |

How seeding works (`maintenance/SeedComments.php`): every comment, reply, like, report, pin, deletion and page state goes through GlowThread's own services as the acting user, at the time the content gives (`"ago": "45d"`, relative to the run, with a little deterministic jitter). The clock is moved with `ConvertibleTimestamp::setFakeTime()`, and deferred updates run inside that time, so floors, counters, hot scores, log entries, Echo notifications and CheckUser rows all look as if people had written them over the past months. Each author writes from its own address: anonymous authors from theirs, accounts from a fixed one in 192.0.2.0/24. CheckUser keeps only the last 90 days, so older comments have no CheckUser rows. Only during seeding, rate limits are off and anonymous authors may post and report (old anonymous comments, as on a wiki that used to allow them).

Seeding skips every page that already has comments, so your own test comments survive a rerun. A comment the seed marks held must be held by GlowThread's own rules (the new-account gate or the spam blacklist). Likes and reports on a held comment are skipped, because readers cannot see it. To start over from the seed, run `./setup.sh --reset` (about 30 seconds).

## Wiki settings

`config/LocalSettings.php` loads the installer output from the data volume (database credentials, secret key), then `config/LocalSettings.dev.php`:

- English, UTC, short URLs (`/wiki/$1`), no email, no uploads.
- Database: the installer's defaults (binary table options), with the table prefix `gt_` so that prefix handling stays tested.
- Caches: no Redis. The object, session and parser caches live in the database, which the web server, the job runner and maintenance scripts share, so a seed or import run invalidates what the web server sees. APCu is the per-server cache.
- Skins: Vector 2022 (default), legacy Vector (`?useskin=vector`), Citizen (`?useskin=citizen`). Phones get Minerva through MobileFrontend.
- Extensions: Echo, Thanks and SpamBlacklist (bundled with the image), CheckUser and MobileFrontend (fetched), GlowThread (mounted), FlowThread (fetched, legacy mode only).
- GlowThread: its defaults, plus comments on user pages (`$wgGlowThreadNamespaces` main and User). `$wgGlowThreadLegacyCompat` stays off (its default) except in the import test.
- CDN: `$wgUseCdn`, PURGE to Varnish, `X-Forwarded-For` trusted from the Docker networks.

Third-party code (`fetch.sh`, pinned to exact commits, gitignored): CheckUser and MobileFrontend from their REL1_43 branches on Wikimedia's Gerrit, Citizen 3.23.0, and FlowThread 1.3.3 from https://github.com/nbdd0121/MW-FlowThread (its newest release, the last one that runs on MediaWiki 1.43: later master commits use a class name that 1.43 does not have). CheckUser's one runtime dependency (firebase/php-jwt) is installed by a throwaway `composer:2.10.3` container. To update a pin, change the commit in `fetch.sh` and delete that folder.

Why these choices:

- **Official image, no build.** `mediawiki:1.43` already has Apache, short URLs, APCu, intl and the bundled extensions. The dev settings (`config/php.ini`, `config/apache-access.conf`) are mounted, so there is nothing to build or rebuild.
- **MariaDB 10.11.** The LTS release MediaWiki's installer is most often used with, with the installer's default binary tables. MySQL 8.0 stays one variable away.
- **No Redis.** Nothing needs it. A database cache is shared by every process, an APCu-only cache would not be seen by maintenance scripts.
- **Varnish 9.0** with a VCL based on MediaWiki's manual, so caching bugs (a response that sets a cookie, a list that is never purged) show up here before they reach a wiki with a cache.

## Legacy mode and the import test

`GLOWTHREAD_DEV_LEGACY=1 ./setup.sh` loads FlowThread instead of GlowThread (both claim `action=flowthread`, so never both) and posts `seed/content/flowthread.json` through FlowThread's own API on pages that have no FlowThread comments yet. FlowThread dates comments by their ID, so they carry the time of that run. `./setup.sh` switches back. The two extensions keep their own tables, so switching never loses data.

`./import-test.sh` runs the whole migration on the dev data:

1. legacy mode, seeding FlowThread,
2. back to GlowThread with `$wgGlowThreadLegacyCompat` on (as a wiki coming from FlowThread would run),
3. `maintenance/run.php GlowThread:importFlowThread` with `--dry-run`, then the real run, then `--verify`, which exits 1 on any mismatch,
4. checks that every FlowThread comment has a GlowThread copy and that the read-only `action=flowthread` route answers,

and then returns to the normal mode, also when a step fails (`--keep` stays where it ended). It is safe to run again: the importer keys on FlowThread's IDs and adds only what is missing.

## Varnish and caching

`varnish/default.vcl` follows MediaWiki's manual (anonymous page views cached, logged-in users and APIs passed, PURGE from MediaWiki) and adds GlowThread's carve-out for its read route. Its comments explain each rule, so it can serve as an example for a production VCL. In short:

- Anonymous GET and HEAD of `/rest.php/glowthread/v1/page/{id}/comments` on the canonical Host are cached under one URL per page and sort order (`?sort=hot` or `?sort=new`). Junk parameters are dropped, a non-empty `cursor` (a later page) is passed (an empty one counts as the first page), and so are logged-in readers and every other GlowThread route.
- Only responses with an explicit `s-maxage` are stored. GlowThread sends `Cache-Control: public, s-maxage=300` to anonymous readers of the first page and purges both URLs when the list changes. Varnish hands every list response on to clients as `private`, because purges reach no cache in front of it.
- PURGE bans the exact Host and URL, desktop and mobile copies together, through headers stored on each object so that Varnish's ban lurker can clear the ban list in the background. A dev-only `BAN /rest.php/glowthread/v1/page/{id}` clears every cached URL of a page.
- PURGE and BAN are accepted only from Docker's default networks (172.16.0.0/12 and 192.168.0.0/16). A Docker engine set up with other address pools needs its range in the `purge` ACL.

Headers for debugging: `X-Cache` (`HIT` or `MISS`), and the dev-only `X-Cache-Detail`, which tells `PASS` (not looked up) from `MISS`.

Anonymous page HTML is kept for 5 hours (`$wgCdnMaxAge`). After changing server code, look at pages logged in, use port 8151, or empty the cache:

```sh
docker compose exec varnish varnishadm ban req.url '~' .
```

`varnish/test.sh` checks the whole policy with `varnishtest` against a fake backend, in a throwaway Varnish container, including PURGE from outside the ACL (through a PROXY-protocol listener) and a Varnish without the canonical Host. Run it after every VCL change. CI runs it too.

What GlowThread must keep doing for the carve-out to work: answer anonymous list reads with `public, s-maxage=N` and no `Set-Cookie`, purge byte-identical canonical URLs on the Host of `$wgServer`, never set a cookie whose name contains `session` or `Token` for anonymous readers, and make a cacheable response depend on the URL only (not on the client address, user agent, language or cookies).

## Debugging

```sh
docker compose logs -f mediawiki                                         # Apache access and errors
docker compose exec mediawiki sh -c 'tail -n 50 /var/www/data/logs/*.log'   # exceptions, PHP warnings, GlowThread's log
docker compose exec -u www-data mediawiki php maintenance/run.php eval      # PHP prompt with MediaWiki loaded
docker compose exec -u www-data mediawiki php maintenance/run.php sql      # SQL prompt
```

- Exceptions show their details on the page (`$wgShowExceptionDetails`), and deprecation warnings are logged.
- PHP changes apply on the next request. The parser cache is invalidated whenever a PHP or JSON file of the extension changes.
- ResourceLoader debug mode: add `?debug=1` to one page, or `touch config/rl-debug.on` for every request (remove the file to turn it off).
- A broken `extension.json` (one that does not parse) is skipped, so the wiki keeps working while you edit it.

## Another checkout

The project root is mounted read-only at `extensions/GlowThread`. To test another checkout, such as a git worktree:

```sh
GLOWTHREAD_SRC=/path/to/checkout ./setup.sh
```

The path must be one Docker can mount (with Colima or Docker Desktop on macOS, a folder under your home folder). That folder is inside Apache's web root, so `config/apache-access.conf` serves only its static assets (CSS, JS, images and fonts outside `client/`, `dev/`, `docs/`, `tests/` and the other non-runtime folders), runs none of its PHP files directly, and serves no hidden file or folder of any extension or skin.

PHP in the containers cannot read the checkout's `dev/` folder: an empty read-only folder is mounted over it, so `dev/.env.local` stays out of reach. To hide other local folders the same way, such as `private/`, list them in `dev/compose.override.yaml` (gitignored), which `docker compose` in `dev/`, and so `setup.sh`, loads with `compose.yaml`. Every folder it lists must exist in the mounted checkout, or the containers do not start.

## MySQL

`GLOWTHREAD_DEV_DB=mysql ./setup.sh` runs MySQL 8.0 instead of MariaDB, with its own volumes (`glowthread_db_mysql`, `glowthread_data_mysql`), so it is a second wiki seeded the same way. `./setup.sh` goes back to the MariaDB wiki, which is kept. `GLOWTHREAD_DEV_DB=mysql ./setup.sh --reset` deletes only the MySQL wiki.

## Files

| File | Purpose |
|---|---|
| `setup.sh`, `fetch.sh`, `import-test.sh` | Set up the stack, fetch pinned third-party code, run the import test |
| `compose.yaml` | The services |
| `compose.override.yaml` | Optional, local only: folders of this checkout to hide from the containers |
| `config/LocalSettings.php`, `config/LocalSettings.dev.php` | Settings entry point and dev settings |
| `config/php.ini`, `config/apache-access.conf` | PHP and Apache settings mounted into the image |
| `maintenance/Seed*.php` | Seed scripts: pages, accounts, GlowThread comments, FlowThread comments |
| `seed/content/`, `seed/site/` | Seed content |
| `seed/dataset/` | How `civil-comments.json` is built from the CC0 Civil Comments archive (`build.py`, license and attribution in its `README.md`) |
| `varnish/default.vcl`, `varnish/policy.vtc`, `varnish/test.sh` | Cache policy and its test |
