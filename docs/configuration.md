English | [简体中文](zh-CN/configuration.md)

# Configuration

This page lists everything a wiki administrator can configure in GlowThread: the variables in `LocalSettings.php`, rights and groups, rate limits, the settings that can be changed on the wiki, the word filter, logs, notifications, CheckUser records, the FlowThread compatibility API, what happens when pages are deleted or moved, and the special pages.

Related pages:

- [README](../README.md): install and first steps
- [Moderation guide](moderation.md): how moderators use these settings
- [Running behind a CDN](caching.md): Varnish and other caches
- [API reference](api.md): REST routes, API modules, hooks and log entries

## Before you start

Load the extension and create its tables:

```php
wfLoadExtension( 'GlowThread' );
```

```sh
php maintenance/run.php update
```

`update` creates five tables: `glowthread_post`, `glowthread_reaction`, `glowthread_report`, `glowthread_page` and `glowthread_setting`. The schema files are in `sql/<database type>/tables-generated.sql`. It also creates the system user "GlowThread" that logs [automatic holds](#automatic-holds-by-reports).

**Database.** Comment lists use window functions (`ROW_NUMBER() OVER`) to load only the replies they show. This needs MariaDB 10.2 or later, MySQL 8.0 or later, SQLite 3.25 or later, or PostgreSQL. The development wiki runs on MariaDB 10.11 or MySQL 8.0, and the PHP tests run on SQLite. PostgreSQL supports the SQL, but GlowThread is not tested on it.

All text columns are binary (`BLOB` or `VARBINARY`). Emoji and other 4-byte characters are stored intact even when `$wgDBTableOptions` uses the 3-byte `utf8` character set.

**Optional extensions.** GlowThread uses these when they are loaded, and works without them:

| Extension | What GlowThread does with it |
|---|---|
| [Echo](https://www.mediawiki.org/wiki/Extension:Echo) | [Notifications](#notifications-echo) for replies, mentions, comments on user pages and moderation |
| [CheckUser](https://www.mediawiki.org/wiki/Extension:CheckUser) | A [CheckUser record](#checkuser) for every new comment and report |
| [SpamBlacklist](https://www.mediawiki.org/wiki/Extension:SpamBlacklist) | Links in comments are checked. A comment with a blacklisted link is held for review |

**Recommended: [AntiSpoof](https://www.mediawiki.org/wiki/Extension:AntiSpoof).** Without it, anyone can register a name that looks exactly like another, such as "Moderator" with an invisible soft hyphen. GlowThread writes such characters out only for moderators, and leaves these names out of @mention suggestions.

**Do not load FlowThread at the same time.** Each extension would add its own comment section, and FlowThread erases its comments when a page is deleted. To move from FlowThread, follow [Migrating from FlowThread](migrating-from-flowthread.md).

## LocalSettings variables

All variables are optional. Put them after `wfLoadExtension( 'GlowThread' );`.

| Variable | Default | Meaning |
|---|---|---|
| `$wgGlowThreadNamespaces` | `[ 0 => true ]` | Namespaces where comments appear, as namespace ID => `true` or `false`. Your value is merged with the default, so `[ 0 => false, 2 => true ]` turns the main namespace off and user pages on. Talk namespaces, special pages, pages that do not exist and the main page never get comments |
| `$wgGlowThreadMaxLength` | `1000` | Longest comment, in Unicode code points. Most emoji count as one, emoji sequences such as flags or families as several. On-wiki setting `maxLength` |
| `$wgGlowThreadMaxMentions` | `5` | Most different existing users one comment may mention. On-wiki setting `maxMentions` |
| `$wgGlowThreadPageSize` | `20` | Top-level comments per page of the list, from 1 to 50 |
| `$wgGlowThreadReplyPreview` | `3` | Replies shown under each comment, from 0 to 20. Readers load the rest in place with "Show N more replies" |
| `$wgGlowThreadPopularCount` | `3` | Size of the block of top comments shown above the list, from 0 to 10. `0` turns the block off |
| `$wgGlowThreadPopularMinLikes` | `2` | Likes a comment needs before it can enter the top comments block |
| `$wgGlowThreadHotHalfLife` | `90` | Half-life in days of the like score used by "hot" order and the top comments block. A shorter half-life lets new comments win sooner. See [Hot order](#hot-order) |
| `$wgGlowThreadCdnMaxAge` | `300` | Seconds a CDN may keep the first page of comments for logged-out readers (`s-maxage`). Applies only while `$wgUseCdn` is on. `0` makes every list response private. See [Running behind a CDN](caching.md) |
| `$wgGlowThreadDuplicateWindow` | `600` | Seconds during which the same user cannot post the same text on the same page again. Case, spacing, full-width forms and invisible characters do not make a text different. On-wiki setting `duplicateWindow` |
| `$wgGlowThreadNewAccountHours` | `24` | New-account gate: account age in hours, see [below](#the-new-account-gate). `0` turns the gate off. On-wiki setting `newAccountHours` |
| `$wgGlowThreadNewAccountApproved` | `1` | New-account gate: visible comments that let a young account skip the gate. On-wiki setting `newAccountApproved` |
| `$wgGlowThreadAutoHoldReporters` | `3` | Hold a visible comment once this many different eligible users have open reports on it, see [below](#automatic-holds-by-reports). `0` turns it off. On the wiki it takes `0` or `2` to `100`, so one account alone cannot hold a comment. `LocalSettings.php` may still set `1`. On-wiki setting `autoHoldReporters` |
| `$wgGlowThreadMaxHeldPerUser` | `3` | Most comments one author may have waiting for review. A further comment that would be held is refused with the error `toomanyheld`. Comments that would be visible at once are not affected. `0` means no cap. LocalSettings only |
| `$wgGlowThreadReportReasons` | all six | Report reasons offered to readers: `spam`, `abuse`, `offtopic`, `spoiler`, `misinfo`, `other`. Setting the variable replaces the whole list. On-wiki setting `reportReasons` |
| `$wgGlowThreadPublicLog` | `true` | Show GlowThread's log entries (moderation actions and settings changes, and FlowThread's `comments` entries while `$wgGlowThreadLegacyCompat` is on) to everyone, in Special:Log, Special:RecentChanges, watchlists and the API. When `false`, only users with `glowthread-moderate`, `glowthread-erase` or `glowthread-configure` see them, and entries logged meanwhile stay out of RecentChanges and watchlists for good, see [Logs](#logs). CheckUser records them either way. On-wiki setting `publicLog` |
| `$wgGlowThreadTheme` | `'auto'` | Colour scheme of the comment section and the moderation hub. `'auto'` follows the skin's light or night mode and, where the skin follows it, the reader's system setting. `'night'` and `'day'` keep them dark or light by adding the class `ext-glowthread-night` or `ext-glowthread-day` to `<html>`, on article views with the comment section and on Special:GlowThread. Use `'night'` only when the page behind the comments is always dark, and `'day'` only when it stays light. Other values count as `'auto'`. LocalSettings only. See [Theming](theming.md#wgglowthreadtheme) |
| `$wgGlowThreadLegacyCompat` | `false` | FlowThread compatibility: answer FlowThread's API (`api.php?action=flowthread`) read-only, and show FlowThread's stored notifications and log entries. A wiki that migrated from FlowThread sets it to `true` and keeps it on. See [FlowThread compatibility](#flowthread-compatibility) |
| `$wgGlowThreadOnWikiSettings` | see [On-wiki settings](#on-wiki-settings) | Which settings users with `glowthread-configure` may change on the wiki |

Values outside the ranges above are clamped to the nearest allowed value.

Raise `maxLength` only as far as the wiki needs. Every read turns each comment's text into links again, so long comments cost more on every page view, in the worst case more than in proportion to their length.

### The new-account gate

A comment is **held for review**, never rejected, when its author's account is younger than `newAccountHours` **and** has fewer than `newAccountApproved` visible comments. Users with `glowthread-trusted` skip the gate. Very old accounts without a registration date count as old. Accounts the gate holds cannot like comments until they pass it (error `newaccount`). They can still remove a like.

With the defaults, a new account's comments wait for review during its first 24 hours, until a moderator has approved one of them. How strict to be depends on your community and on how much spam reaches the wiki. The gate looks at account age and approved comments, not at edit count, because many readers comment without ever editing. Set `newAccountHours` to `0` to turn the gate off.

### Automatic holds by reports

A visible comment is held for review once `autoHoldReporters` (default 3) different **eligible** users have open reports on it. A reporter is eligible when they:

- are registered, not with a temporary account, and are not the author,
- are not blocked from reporting there: sitewide, or by a partial block on that page or its namespace,
- have `glowthread-trusted`, or meet all of these: they would pass the new-account gate themselves (their account is at least `newAccountHours` old, or they have `newAccountApproved` visible comments), their account is at least 7 days old, and they have at least one visible comment. The 7 days and the one comment are fixed, not settings.

So accounts registered ahead of time and never used cannot hide a comment. Every report still reaches the moderation queue.

Pinned comments, comments by users with `glowthread-moderate` or `glowthread-trusted`, and comments a moderator has already kept (approved, restored, or with its reports dismissed) are never held by reports. Their reports go to the queue only. Undoing that approval, restore or dismissal ends this. Reports imported from FlowThread do not count, unless the same user reports the comment again. Each automatic hold is logged as `glowthread/autohold`, performed by the system user "GlowThread". The name is reserved, so nobody can register it, and `update` creates the user. An account that already had the name before GlowThread was installed is not taken over: holds are logged as "Maintenance script" instead. Like any account with a reserved name, that account can no longer log in until it is renamed.

With the gate turned off (`newAccountHours` set to `0`), reporters still need an account 7 days old and a visible comment.

### Hot order

Readers can sort comments by "Top" (hot order, `sort=hot`, the default) or "Newest". Hot order and the "Top comments" block rank by a like score that halves every `$wgGlowThreadHotHalfLife` days:

- hot order: (likes + 1) × 0.5^(age in days / half-life)
- top comments: likes × 0.5^(age in days / half-life), among comments with at least `$wgGlowThreadPopularMinLikes` likes

With more candidates than places, an older comment with many likes can be left out of the top comments block. Hot order ranks the 5,000 most recently posted top-level comments of a page and pages through the first 1,000 of them. On a page with more, the older comments appear only in "Newest" order.

## On-wiki settings

Users with `glowthread-configure` (sysops by default) change these on **Special:GlowThread/settings**. The [moderation guide](moderation.md#settings) shows the screen. The screen asks for a reason, and every change is logged in Special:Log/glowthread with the old and new values.

| Setting | Type | Allowed values | LocalSettings variable |
|---|---|---|---|
| `maxLength` | whole number | 1 to 10000 | `$wgGlowThreadMaxLength` |
| `maxMentions` | whole number | 0 to 50 | `$wgGlowThreadMaxMentions` |
| `duplicateWindow` | seconds | 0 to 86400 | `$wgGlowThreadDuplicateWindow` |
| `newAccountHours` | hours | 0 to 8760 | `$wgGlowThreadNewAccountHours` |
| `newAccountApproved` | whole number | 0 to 1000 | `$wgGlowThreadNewAccountApproved` |
| `autoHoldReporters` | whole number | 0 (off), or 2 to 100 | `$wgGlowThreadAutoHoldReporters` |
| `reportReasons` | list | at least one of the six reasons | `$wgGlowThreadReportReasons` |
| `publicLog` | on or off | | `$wgGlowThreadPublicLog` |

Setting one of these variables in `LocalSettings.php` locks the setting to your value. The settings screen then shows it greyed out and marked "Locked in LocalSettings", and a value saved on the wiki earlier no longer applies. Remove the line to make the setting editable on the wiki again. While a variable is not set, the setting starts from the default in the table [above](#localsettings-variables).

`$wgGlowThreadOnWikiSettings` locks a setting without choosing a value, so it stays at the default. The default is:

```php
$wgGlowThreadOnWikiSettings = [
	'maxLength' => true,
	'maxMentions' => true,
	'duplicateWindow' => true,
	'newAccountHours' => true,
	'newAccountApproved' => true,
	'autoHoldReporters' => true,
	'reportReasons' => true,
	'publicLog' => true,
];
```

Your value is merged with the default. For example, this locks the report reasons at their default and leaves the rest as they are:

```php
$wgGlowThreadOnWikiSettings = [ 'reportReasons' => false ];
```

Settings are cached in MediaWiki's WAN object cache, and for a few seconds in each PHP process, until they change. A change clears that cache. Lists cached by a CDN for logged-out readers show the change within `$wgGlowThreadCdnMaxAge` seconds.

## The word filter

The rules live on the wiki page **MediaWiki:GlowThread-filter**. Like any interface page, it needs the `editinterface` right to edit, and it has a history and can be protected. When the page does not exist, there are no rules. Opening it for editing shows a short description of the syntax as the starting text. The page is public like every interface page: anyone can read the rules, spammers too. On Special:GlowThread/settings, moderators can read the rules, users with `glowthread-configure` can test them, and users with `editinterface` can edit them.

- One rule per line. Empty lines and lines that start with `#` (comments) are ignored.
- A plain line and the comment are both normalised before they are compared: NFKC (full-width and other compatibility forms become plain ones), case folding, and removal of invisible characters such as zero-width spaces. Accents and other combining marks on Latin, Greek, Cyrillic, Arabic and Hebrew letters are removed, unless the line itself has accents: then the comment keeps its accents too. Marks that write vowels, tones or voicing in Thai, Indic scripts and kana stay.
- A plain line in a script that puts spaces between words (Latin, Cyrillic, Greek, Arabic and most others) matches **whole words**. The rule `ass` matches "Ass!" but not "class" or "pass".
  - A rule of several words matches them in order, with any spaces, punctuation or symbols between them, or none: `cheap coins` also matches "cheap-coins" and "cheapcoins".
  - The letters of a word may be spaced out: `spam` also matches "s p a m", and `ass` matches "a.s.s". A word is never split anywhere else, so "it's hit" does not match `shit`. A lower-case letter followed by spaced-out capitals does not start one, so "Got a S S rank" does not match `ass`.
  - Chinese, Japanese, Korean, Thai, Lao, Khmer and Myanmar letters count as word boundaries, because text in these scripts often runs straight into a Latin word without a space. `spam` matches in "这个spam链接".
- A plain line with Chinese, Japanese, Korean, Thai, Lao, Khmer or Myanmar letters, or without any letter or digit (an emoji, for example), matches **anywhere** in the comment. It also matches when spaces, symbols or ASCII punctuation are put between its characters, as long as the rule has at least two characters that are not spaces, punctuation or symbols. So this does not apply to emoji rules. It never matches across a line break, full-width sentence and clause punctuation (`。、，！？：；`), CJK brackets and quotes (`「」『』【】《》〈〉`, full-width `（）［］`) or curly quotes (`“”‘’`).
- A line `/pattern/flags` is a PCRE regular expression, run on the comment as stored. Flags can be `i`, `m`, `s` and `x`. `u` (Unicode) is always on. Add `i` to ignore case. Slashes inside the pattern need no escaping.
- Each rule is compiled and run on its own, so one broken line does not disable the others. Broken lines are ignored and listed on the settings screen.
- A match **holds** the comment for review. The comment is never rejected. If a regular expression fails while it runs (for example on the PCRE backtrack limit), the comment is held too.
- Limits: at most 2000 rules are read (`WordFilter::MAX_RULES`). Each regular expression runs with `pcre.backtrack_limit` lowered to 100000 (`WordFilter::REGEX_MATCH_LIMIT`). After 0.25 s of checking (`WordFilter::REGEX_TIME_BUDGET`), the regular expressions left are not run. A regular expression stopped either way holds the comment ("time-limit"). PCRE does not count every kind of work, so a long expression made of many greedy scans, such as `(\w+)\s+\1` repeated, can still take about 80 ms per copy on a 10000-character comment.
- The filter applies to every author, including sysops and users with `glowthread-trusted`.

Example:

```text
# Advertising: whole words, also "cheap-pills" or "c h e a p pills"
cheap pills
# A regular expression for variants
/\bfree\s+(?:coins|gems)\b/i
```

To check rules without saving them, use "Test a comment" on the settings screen. It calls the API module `action=glowthreadfiltertest`, which needs `glowthread-configure`. The test runs the same code as posting a comment, on the rules in the editor, saved or not.

## Rights

| Right | Default groups | Allows |
|---|---|---|
| `glowthread-post` | `user` | Post comments and replies, delete one's own comments |
| `glowthread-react` | `user` | Like comments |
| `glowthread-report` | `user` | Report comments |
| `glowthread-trusted` | `sysop`, `bot` | Skip the new-account gate. Comments by these users are never held by reports |
| `glowthread-moderate` | `sysop` | Use Special:GlowThread and the moderation tools in the comment section, change a page's comment state, see held and deleted comments and the records of authors and pages |
| `glowthread-erase` | `sysop` | Erase deleted comments for good (together with `glowthread-moderate`), and read Special:Log/glowthread-private |
| `glowthread-configure` | `sysop` | Change the on-wiki settings and test the word filter. The settings screen is part of Special:GlowThread, which needs `glowthread-moderate` |
| `glowthread-viewlog` | everyone while `publicLog` is on, otherwise users with `glowthread-moderate`, `glowthread-erase` or `glowthread-configure` | Read Special:Log/glowthread, and its entries in RecentChanges and watchlists. See [Logs](#logs) |

Page protection does not cover comments: users with `glowthread-post` can comment on a protected page. Only `$wgNamespaceProtection` applies. To stop new comments on a page, set its comments to "Locked".

Anonymous posting is not supported and not tested. Keep `glowthread-post`, `glowthread-react` and `glowthread-report` away from the `*` group.

Give rights to other groups as usual. For example, a group of comment moderators, and erasing for oversighters only:

```php
$wgGroupPermissions['commentmod']['glowthread-moderate'] = true;

$wgGroupPermissions['sysop']['glowthread-erase'] = false;
$wgGroupPermissions['suppress']['glowthread-erase'] = true;
```

Erasing also needs `glowthread-moderate`. Oversighters who are not sysops need it too.

To let trusted regulars skip the new-account gate, give `glowthread-trusted` to a group they are in, for example `autoconfirmed`, if your wiki's autoconfirm rules are strict enough.

### Blocks

- Reader actions (post, like, report, delete one's own comment) check blocks on the comment's page. A partial block applies only to the pages and namespaces it covers.
- Moderation and settings changes are refused under any block, partial blocks included.
- There is no "block from commenting only". Block the user from editing, or use a partial block on the pages or namespaces concerned.

### Bot passwords and OAuth

The grant `editpage` gives post, react and report. `delete` gives moderate. `oversight` gives erase, which cannot be undone, so a tool that erases comments needs both `delete` and `oversight`. `editsiteconfig` gives configure. `basic`, which every bot password and OAuth consumer has, gives `glowthread-viewlog`, so tools read the comment log whenever their user can.

## Rate limits

GlowThread adds these limits to `$wgRateLimits`. Each key is the name of a GlowThread right.

| Key | `user` | `newbie` | `ip` | `ip-all` | `subnet-all` |
|---|---|---|---|---|---|
| `glowthread-post` (post, reply, delete own comment) | 6 a minute | 3 a minute | 3 a minute | 12 a minute | 24 a minute |
| `glowthread-react` | 60 a minute | 30 a minute | 30 a minute | 120 a minute | 240 a minute |
| `glowthread-report` | 20 a day | 5 a day | 5 a day | 30 a day | 60 a day |
| `glowthread-moderate` | 120 a minute | | | 240 a minute | 480 a minute |
| `glowthread-erase` (each erased comment), no bypass | 30 an hour | | | 60 an hour | 120 an hour |
| `glowthread-configure` (settings changes, filter tests), no bypass | 60 a minute | | | 120 a minute | 240 a minute |

What the columns mean:

- `user`: per account.
- `newbie`: per account that is not autoconfirmed. It replaces `user` for those accounts.
- `ip`: per IP address, for logged-out users and newbies only.
- `ip-all`: everyone behind one IP address, accounts included.
- `subnet-all`: everyone behind one subnet, accounts included. A subnet is an IPv4 /24 or an IPv6 /64.

Rules to know before you change them:

- Users with `noratelimit` (sysops and bureaucrats by default) are not limited, except by the two keys marked "no bypass". Erasing cannot be undone, so one compromised sysop account must not be able to blank many comments at once. Those keys set `&can-bypass` to `false`.
- Your values are merged with these defaults per condition. `$wgRateLimits['glowthread-post']['user'] = [ 10, 60 ];` replaces only that one condition.
- MediaWiki applies `ip-all` and `subnet-all` to an account only when their rate is higher than the account's own rate (it ignores them "if user limit is more permissive"). The defaults are therefore above the `user` rate: 2 and 4 times for most keys, 1.5 and 3 times for reports. If you raise a `user` limit, raise `ip-all` and `subnet-all` with it, or they silently stop applying to accounts. `subnet-all` is only checked when `ip-all` is set too.
- `ip-all` and `subnet-all` stop a group of fresh accounts on one address from multiplying the per-account limit. They are set well above normal traffic, so that many readers behind one carrier-grade NAT address are not affected.
- A client with a whole IPv6 /48, which many hosts and providers hand out, has 65,536 /64 subnets, so `ip-all` and `subnet-all` do not bind it: only the per-account limits apply. This is how MediaWiki's rate limiter works. If it matters on your wiki, limit requests per /48 at your front proxy or with a `PingLimiter` hook.
- When a limit trips, the API answers `ratelimited` with `data.retryAfter`, the longest window of the conditions that apply.
- An erase request for more comments than the hour has left erases up to the limit and answers `ratelimited` for the rest, which stay deleted.

**MediaWiki must see each reader's real IP address.** Behind a proxy or CDN that MediaWiki does not trust, every request seems to come from the proxy. `ip-all` then becomes one limit for the whole wiki: 12 comments a minute from everyone together. To check, open `api.php?action=query&meta=userinfo&format=json` while logged out. `query.userinfo.name` is the address MediaWiki sees. It must be your own public address. How to fix it is in [Running behind a CDN](caching.md#client-ip-addresses).

**Counters live in `$wgMicroStashType`.** All application servers must share it (Redis or Memcached, for example). If the store loses its data (a restart without persistence, or eviction under memory pressure), every limit starts again from zero. The cap on held comments per author (`$wgGlowThreadMaxHeldPerUser`) is counted in the database and still applies. Keep the store below its memory limit.

## Logs

| Log | Contents | Who can read it |
|---|---|---|
| Special:Log/glowthread ("Comment log") | Every moderation action, page state change and settings change, authors deleting their own comments, and FlowThread imports (`glowthread/import`) | Users with `glowthread-viewlog` (`$wgLogRestrictions`): everyone while `publicLog` is on. Hidden from "Main public logs" by default (`$wgFilterLogTypes`) |
| Special:Log/glowthread-private ("Comment erasure log") | Who wrote each erased comment | Users with `glowthread-erase` (`$wgLogRestrictions`) |
| Special:Log/comments ("FlowThread comment log") | FlowThread's old entries, on a wiki that migrated from FlowThread | The same readers as Special:Log/glowthread, while `$wgGlowThreadLegacyCompat` is on and no other extension uses the log type `comments`. Hidden from "Main public logs" by default |

- **Who sees the log.** `$wgLogRestrictions` ties the `glowthread` type, and `comments` while GlowThread shows it, to the right `glowthread-viewlog`. GlowThread gives that right to everyone while the `publicLog` setting is on, and otherwise only to users with `glowthread-moderate`, `glowthread-erase` or `glowthread-configure`. To let another group read the log while the setting is off, give it `glowthread-viewlog`. Users without the right do not see these entries in Special:Log, Special:RecentChanges, Special:RecentChangesLinked, watchlists or the API.
- `glowthread` entries are published to Special:RecentChanges as logged actions while `publicLog` is on, so CheckUser records them like other log entries, and watchers of the page see them in their watchlist. The import entry (`glowthread/import`) is the exception: it is written to the log only. New comments and reports are not logged. With CheckUser loaded, each one gets a [CheckUser record](#checkuser) instead.
- **Entries logged while `publicLog` is off get no RecentChanges row.** They send no watchlist emails or Echo watchlist notifications and leave no watchlist markers. Moderators find them in Special:Log/glowthread and on Special:GlowThread, not in RecentChanges or watchlists, even after the setting is turned on again. With CheckUser loaded, GlowThread hands them to CheckUser directly.
- GlowThread writes only the `glowthread` and `glowthread-private` log types. `comments` is FlowThread's log type. GlowThread registers it only while `$wgGlowThreadLegacyCompat` is on, and only when no other extension already uses that name (FlowThread itself, or Extension:Comments, whose log has the same name). Then FlowThread's old entries keep showing in Special:Log/comments and in the page history on Special:GlowThread. With the setting off, `$wgLogRestrictions` limits `comments` to `glowthread-legacy-log`, which no group has. The entries stay out of Special:Log and the API until it is on again.
- After a comment is erased, the public entries performed by its author about that comment (deleting it themselves, or acting on it as a moderator) get their performer and reason hidden with `log_deleted`, as core's log revision deletion does. Readers without `deletedhistory` see neither. If you move `glowthread-erase` to the `suppress` group, sysops keep `deletedhistory` and can still see these performers.
- GlowThread's entries show the author's current name, and hide it when that account is hidden by `hideuser`.
- Old FlowThread entries name the comment's author as plain text. The log formatter hides a name hidden by a `hideuser` block, and hides the author named by a FlowThread `erase` entry from everyone without `glowthread-erase`. FlowThread's `delete`, `recover` and `markchecked` entries store only the author's name, not the comment, so they keep naming the author of a comment that is erased later. Hide such an entry with core's log revision deletion if it matters.
- Debug log: route the channel `GlowThread` to a file to record when a moderator reaches the erase limit, for example `$wgDebugLogGroups['GlowThread'] = '/var/log/mediawiki/glowthread.log';`. MediaWiki's own `ratelimit` channel records every limit that trips.

The entry formats are in [api.md](api.md#log-entries).

## Notifications (Echo)

With Echo loaded, GlowThread notifies people when someone:

- replies to their comment,
- mentions them in a comment (`@Name`),
- comments on their user page (`User:Name`, not its subpages), once comments are on in the User namespace (`$wgGlowThreadNamespaces[NS_USER] = true;`). When that comment also replies to or mentions Name, Name gets that notification instead,
- a moderator removes, restores or approves their comment.

There is nothing to configure. The notification types register themselves through Echo's hooks.

- Users choose in Special:Preferences, Notifications, category "Comments" (`glowthread`). Web notifications are on and email is off by default.
- When a comment is erased, the notifications it caused (reply, mention, user page) are hidden with Echo's moderation, so Echo no longer shows its author.
- Undoing a moderation action sends nothing.
- A comment notifies once. Approving it again after an undo sends no new reply or mention notice.
- One author sends one user at most 3 comment notifications an hour. The rest are dropped.
- Mentions on one page are bundled into one notification, like replies.
- When an author deletes their own comment, its notifications are hidden, as for an erased comment.
- **Recommended:** turn on Echo's per-user mute. Without it, a reader who is harassed through mentions or replies can only turn off all comment notifications, not mute one person.

  ```php
  $wgEchoPerUserBlacklist = true;
  ```

- On a wiki that migrated from FlowThread, FlowThread's stored notifications keep showing while `$wgGlowThreadLegacyCompat` is on (see [FlowThread compatibility](#flowthread-compatibility)). Their category is not offered in the preferences. [Migrating from FlowThread](migrating-from-flowthread.md#9-carry-over-notification-preferences) shows how to copy users' FlowThread notification choices to GlowThread.

Details of each notification are in [api.md](api.md#notifications-echo).

## CheckUser

With CheckUser loaded, every new comment, visible or held, is stored as a CheckUser private event with the author's IP address, `X-Forwarded-For` and user agent. Special:CheckUser lists it among the user's actions as a posted comment on a page. Every report is stored the same way, with the reporter's IP address, `X-Forwarded-For` and user agent, and listed as a reported comment. Likes are not recorded. These records follow CheckUser's own retention (`$wgCUDMaxAge`, 90 days by default). They are not written to Special:Log or Special:RecentChanges.

Moderation actions are ordinary `glowthread` log entries, so CheckUser records them like other log entries, through RecentChanges, or directly from GlowThread while `publicLog` is off. They contain what Special:Log/glowthread already shows. Authors of erased comments, and users hidden by a `hideuser` block, stay hidden there. The restricted `glowthread-private` log never reaches RecentChanges or CheckUser. Automatic holds (`glowthread/autohold`) are recorded as done by the software, with the IP address 127.0.0.1 and no `X-Forwarded-For` or user agent, and their RecentChanges row, when they have one, carries 127.0.0.1 too. The reporter whose report caused the hold has their own report record.

Comments imported from FlowThread have no CheckUser records.

## FlowThread compatibility

Only for wikis that migrated from FlowThread. Set `$wgGlowThreadLegacyCompat = true;` and keep it on. It does three things.

**FlowThread's stored notifications.** GlowThread registers FlowThread's Echo notification types, so users' old FlowThread notifications keep showing. Nothing creates new ones.

**FlowThread's log entries.** GlowThread registers FlowThread's `comments` log type, so its old entries keep showing in Special:Log/comments and in the page history on Special:GlowThread. This is skipped when another extension already uses that name: FlowThread itself, or Extension:Comments. See [Logs](#logs).

With the setting off, these notifications and log entries are hidden, not deleted: turning it on again brings them back.

**The compatibility API.** `api.php?action=flowthread` keeps answering FlowThread's read types from GlowThread's data:

- `type=list` for readers,
- `type=listall` for users with `glowthread-moderate`.

Every write type is refused with an error that names GlowThread. This keeps external tools, apps and scripts that read FlowThread's API working.

- Off by default, because a new wiki has no FlowThread data. [Migrating from FlowThread](migrating-from-flowthread.md) turns it on. With it off, `action=flowthread` is an unknown action.
- The module is never added while FlowThread itself is loaded.
- Limits per request: at most 100 top-level comments, offsets up to 10000, and at most 300 replies and 256 KiB of reply text per list page.
- Answers are always `private`, so no cache keeps them.

The response format is in [api.md](api.md#compatibility-api-actionflowthread).

## CDN

GlowThread works without a CDN. With Varnish or another CDN in front, logged-out readers can get the first page of comments from the cache, and MediaWiki purges it when it changes. The CDN needs a carve-out for `/rest.php/glowthread/v1/`, and MediaWiki must see real client IP addresses. `$wgServer` must be a fixed URL, not one computed from each request, unless `$wgInternalServer` is set ([Host name](caching.md#host-name)). Everything about it is in [Running behind a CDN](caching.md).

## Page lifecycle

- **Deleting a page** hides its comments, because their page is gone. Nothing is erased.
- **Undeleting** brings back the comments of the page IDs whose revisions that undeletion restores, and only those. Restoring a revision of a later page at the same title does not bring back the comments of an earlier page that was deleted there. When the old page ID was taken over by a re-created page, the comments move to the merged page, and their floor numbers are moved above the floors already used there. The merged page keeps the stricter comment state, and a `lock` or `disable` entry names the user who undeleted.
- **Suppressed deletions.** Comments of a page whose last deletion was a suppression (`suppress/delete`) come back only with an undeletion that also lifts the suppression, which needs `suppressrevision`. Otherwise they stay detached, like the suppressed revisions.
- **Moving a page** needs nothing. Comments follow the page ID. The page's cached lists are purged.
- **Hiding a user.** Placing, changing or lifting a block with `hideuser` through Special:Block or the API purges the cached comment lists of up to 500 pages where that user commented most recently. Lists of other pages, for example a page where someone mentioned the user, update within `$wgGlowThreadCdnMaxAge` seconds. CentralAuth's global suppression purges nothing.
- **Merging accounts.** With Extension:UserMerge, merging an account moves its comments, likes, reports, recorded moderation actions and log authorship to the account that stays, and points mentions of it to that account. Where both accounts liked or reported the same comment, one row stays (for reports, the open one, else the newer one) and the comment's counts are corrected.

## Special pages

| Page | Who | What it is |
|---|---|---|
| Special:GlowThread (alias Special:Comments) | `glowthread-moderate` | The moderation hub. Subpages: `queue` (the default), `settings`, `user/<name>` (everything by one author), `page/<title>` (everything on one page). See the [moderation guide](moderation.md) |
| Special:GlowThreadLink | everyone | Permalinks to comments, unlisted. `Special:GlowThreadLink/<id>` opens the comment with that ID on its page. `Special:GlowThreadLink/<page title>/<n>` opens comment number `n` (its floor number) on a page. `Special:GlowThreadLink/<22 hex digits>` opens a comment imported from FlowThread by its FlowThread ID. Comments the reader cannot see answer "not found" |
| Special:FlowThreadLink | everyone | FlowThread's permalink page name, an alias of Special:GlowThreadLink, so links from a former FlowThread install keep working. Unlisted |
| Special:FlowThreadManage | everyone (the hub itself needs `glowthread-moderate`) | FlowThread's moderation page name. Redirects to Special:GlowThread/queue. Unlisted. Not added while FlowThread itself is loaded |

A permalink lands on the page with the fragment `#gt-<id>`, and the comment section opens at that comment. The fragment `#glowthread` opens the comment section itself.
