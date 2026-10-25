English | [简体中文](SECURITY.zh-CN.md)

# Security policy

## Report a vulnerability

Please do not report security problems in public issues, pull requests or discussions.

Report them privately through this repository's **Security** tab: choose **Report a vulnerability**. Only the maintainers see the report.

Please include:

- what an attacker can do, and which rights or setup they need
- the steps to reproduce it, on a clean wiki if you can
- the MediaWiki version, the GlowThread version or commit, and the skin
- any setting that matters, from `LocalSettings.php` or Special:GlowThread/settings

## What counts

In scope:

- the extension code in `includes/`, `maintenance/`, `sql/`, `resources/` and `client/`
- the setups the documentation recommends, such as the caching rules in [docs/caching.md](docs/caching.md)

Out of scope:

- the development stack in `dev/`, which is for local development only
- problems in MediaWiki itself or in other extensions: report those to their maintainers, for MediaWiki through [Reporting security bugs](https://www.mediawiki.org/wiki/Reporting_security_bugs)

## Supported versions

GlowThread has no releases yet. Security fixes land on the `main` branch, so update to the latest `main` to get them. Once releases exist, each fix also goes into the next release.

## Running it safely

[docs/configuration.md](docs/configuration.md) explains the rights, limits and settings that matter for an open wiki, and [docs/caching.md](docs/caching.md) explains how to put a CDN in front of it.
