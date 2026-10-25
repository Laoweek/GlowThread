English | [简体中文](zh-CN/api.md)

# GlowThread API reference

This is the contract that the comment section, the moderation hub, the FlowThread importer and the Echo and CheckUser handlers are built on. Other tools can use it too. Reads go through the REST API (`rest.php/glowthread/v1/...`). Writes go through Action API modules (`api.php`, POST with a CSRF token). The PHP code is the source of truth. The shapes below are copied from it, and the integration tests in `tests/phpunit/integration/` exercise every one of them.

How the parts fit together is in [architecture.md](architecture.md). Settings and rights are in [configuration.md](configuration.md), CDN setup in [caching.md](caching.md).

Contents: [conventions](#conventions), [objects](#objects), [REST routes](#rest-routes), [write modules](#write-modules), [error codes](#error-codes), [hooks](#hooks-for-other-extensions), [log entries](#log-entries), [data model notes for importers](#data-model-notes-for-importers), [UI messages](#ui-messages), [permalinks](#permalinks), [notifications](#notifications-echo), [CheckUser records](#checkuser-records), [compatibility API](#compatibility-api-actionflowthread).

## Conventions

- **Comment IDs** are integers (`glowthread_post.gtp_id`). **Floor numbers** (`seq`) are per page, start at 1 and are never reused. Show them as `#42`.
- **Timestamps** are ISO 8601 in UTC, for example `2026-10-08T14:23:05Z`.
- **Text is never HTML.** Every comment body comes as a list of [segments](#segment). Render each with a text binding (`{{ }}` in Vue, `textContent` in the DOM). Never use `v-html` or `innerHTML` for anything from these routes.
- **Lengths** count Unicode code points, like `Array.from( text ).length` in JavaScript, not `text.length`. A CJK character counts 1, 😀 counts 1, a flag counts 2.
- **Action API calls** must use `formatversion=2` (booleans are JSON booleans, lists are arrays). Send them with `new mw.Api().postWithToken( 'csrf', params )`.
- **Default sort** is `hot`.

### The canonical list URL

For anonymous readers, the first page of a list is the one response a CDN may cache. It must be requested at one of these two exact URLs:

```
{wgRestPath}/glowthread/v1/page/{pageId}/comments?sort=hot
{wgRestPath}/glowthread/v1/page/{pageId}/comments?sort=new
```

`wgRestPath` is the wiki's `$wgRestPath`, for example `/w/rest.php`. GlowThread purges these two URLs on every write that changes which comments readers see. MediaWiki purges exact URLs and never sends bans, so:

- Request the first page with exactly one query parameter, `sort`, and nothing else (no `limit`, no cache-busting parameter, no empty `cursor=`). Take the URL from `mw.config.get( 'wgGlowThread' ).listUrls.hot` or `.listUrls.new` as is.
- Any other form (another parameter, a different order, no `sort`) works but is answered `private`. A CDN can rewrite such variants to the canonical form before the cache lookup. [caching.md](caching.md#what-the-cdn-must-do) shows how.
- The response is public only when `$wgUseCdn` is on and `$wgGlowThreadCdnMaxAge` is above 0, like MediaWiki's own cacheable responses.
- It is also public only when the request's `Host` is exactly the host that purges carry: the host (and the port, when the URL has one) of `$wgInternalServer`, or of `$wgServer` when no internal server is set. Under another host name (an IP address, a second domain) it is `private`, because purges would never reach that copy.
- Logged-in readers and anonymous readers with a session cookie always get `private` responses, whatever the URL.
- Likes do not purge. Like counts (and the popular block) in the cached anonymous list may lag by up to `$wgGlowThreadCdnMaxAge` seconds (300 by default). Every change to which comments are shown (a new visible comment, a deletion, a restore, an approval, a hold, a pin or unpin, an undo, a page state change, a hideuser block) purges.

### Page config (`wgGlowThread`)

Read it with `mw.config.get( 'wgGlowThread' )`. It is added to eligible article views together with the module `ext.glowThread.init`. It is part of the cached page HTML, so it holds nothing about the reader.

```json
{
  "pageId": 42,
  "enabled": true,
  "state": "open",
  "defaultSort": "hot",
  "listUrls": {
    "hot": "/w/rest.php/glowthread/v1/page/42/comments?sort=hot",
    "new": "/w/rest.php/glowthread/v1/page/42/comments?sort=new"
  },
  "restBase": "/w/rest.php/glowthread/v1",
  "features": { "legacyCompat": false }
}
```

`enabled` is false when the page's comments are turned off (state `disabled`). Eligible means: the page exists, is in a namespace listed in `$wgGlowThreadNamespaces`, is not a talk page and is not the main page, and the view is the current revision with `action=view`.

Special:GlowThread adds `wgGlowThreadAdmin`:

```json
{ "subPage": "queue", "restBase": "/w/rest.php/glowthread/v1", "canErase": true, "canConfigure": true }
```

`subPage` is whatever follows `Special:GlowThread/` (`queue`, `settings`, `user/Name`, `page/Title`, or empty). The moderation hub routes it. The page shows the message `glowthread-special-nojs` inside `<div id="ext-glowthread-admin">` until the hub replaces it.

## Objects

### Comment

```json
{
  "id": 7,
  "seq": 12,
  "pageId": 42,
  "parentId": null,
  "threadId": null,
  "depth": 0,
  "status": "visible",
  "author": { "name": "Alice", "id": 3, "anon": false, "temp": false, "hidden": false },
  "created": "2026-10-08T14:23:05Z",
  "edited": null,
  "segments": [ { "type": "text", "text": "Fed mine twice a day and it doubled 🎉" } ],
  "likes": 3,
  "pinned": false,
  "replyTo": null,
  "viewer": {
    "own": false, "liked": true, "reported": false,
    "canReply": true, "canLike": true, "canReport": true, "canDelete": false
  },
  "replyCount": 2,
  "totalReplies": 5,
  "replies": [ "...comments, see below..." ]
}
```

| Field | Meaning |
|---|---|
| `parentId` | The comment this one is **shown under** (depth − 1). `null` for top-level comments |
| `threadId` | The top-level comment of the conversation, `null` for top-level comments |
| `depth` | `0` top-level, `1` reply, `2` reply to a reply. Replies to depth-2 comments are also shown at depth 2, under the same depth-1 comment |
| `replyTo` | For replies: the comment the author actually answered. Show "Reply to @name" when `replyTo.id !== parentId`. See below |
| `status` | `visible`, `held` (waiting for review, seen only by its author and moderators), `deleted` (moderators only), `erased` (moderators only, text and author removed) |
| `author.name` | `null` when the author is hidden: a hideuser block (unless the reader has `hideuser`), or an erased comment (unless the reader has `glowthread-erase`). Then `hidden` is true and `id` is 0 |
| `author.anon` | Legacy anonymous comment (imported from FlowThread): `name` is an IP address |
| `author.temp` | Temporary account |
| `author.registration` | Moderators only: when the account was created (ISO 8601). `null` for IP authors, for accounts older than MediaWiki's registration dates, and for hidden authors |
| `author.blocked` | Moderators only: `sitewide`, `partial` or `null`. Active blocks of the account or, for IP authors, of that exact address (not ranges, not autoblocks). `null` for hidden authors. `replyTo.author` and `mod.statusBy` do not carry these two fields |
| `segments` | Empty for erased comments |
| `likes` | Like count |
| `pinned` | Pinned to the top of the page (only top-level comments) |
| `viewer` | What the reader may do with this comment. Hints only: the write modules check again |
| `replyCount` | Number of comments shown directly under this one that the reader may see (only on comments returned with their replies) |
| `totalReplies` | Top-level comments in lists and threads: all replies the reader may see, both levels |
| `replies` | Comments shown under this one: in lists the first `replyPreview` (default 3) oldest first, in threads all of them. Lists load only these, not the other replies |

`replyTo`:

```json
{ "id": 9, "seq": 14, "visible": true, "author": { "name": "Bob", "id": 4, "anon": false, "temp": false, "hidden": false } }
```

When the reader cannot see the target (deleted, held by someone else), `visible` is false and `seq` and `author` are `null`.

Moderators (`glowthread-moderate`) also get `mod`:

```json
"mod": {
  "holdReasons": [ "filter" ],
  "holdMatches": [ { "line": 3, "rule": "free coins", "type": "literal" } ],
  "openReports": 2,
  "reportReasons": [ { "reason": "spam", "count": 2 } ],
  "statusBy": { "name": "Moderator", "id": 1, "anon": false, "temp": false, "hidden": false },
  "statusAt": "2026-10-08T15:00:00Z",
  "deletedFrom": null,
  "pinnedAt": null,
  "legacyId": null
}
```

| Field | Meaning |
|---|---|
| `holdReasons` | `filter` (word filter), `filter-error` (a filter regex failed at run time), `spamblacklist`, `newaccount`, `reports` (enough distinct reporters), `parentheld` (a reply the author wrote under their own held comment, see [`glowthreadpost`](#actionglowthreadpost)) |
| `holdMatches` | The word-filter rules that matched when the comment was held, as they were then. `line` is the line number in MediaWiki:GlowThread-filter at that time, `rule` the line as written (at most 255 characters), `type` `literal` or `regex`. At most 20. Empty for comments held for other reasons |
| `statusBy`, `statusAt` | Who changed the status last, and when. `statusBy` is `null` for automatic holds |
| `deletedFrom` | For a deleted comment, the status it had before the deletion, `visible` or `held`, which `restore` goes back to. `null` for other statuses, and for deletions whose log entry does not record it (FlowThread's deletions and imported deleted comments). `restore` makes those visible |
| `legacyId` | The FlowThread ID (22 hex digits) of imported comments |

Who sees what:

| Reader | Statuses listed | Replies under removed comments |
|---|---|---|
| Anonymous | `visible` | hidden |
| Logged in | `visible`, and their own `held` | hidden |
| Moderator | all four | shown |

A reply is shown to a non-moderator only if it, its display parent and its top-level comment are all visible to that reader. This is a product decision (FlowThread did the same): when a comment is deleted, held or erased, the replies shown under it disappear with it for readers, and no tombstone is sent. Every route agrees: the list leaves them out of `replies`, `replyCount`, `totalReplies`, `count` and `popular`, `/comment/{id}`, `/floor/{seq}`, `/thread` and `/replies` answer 404 `comment-not-found` for them, and the compatibility list leaves them out. Only the display parent and the top-level comment count: a reply whose `replyTo` target (a comment it answers that is shown beside it, not above it) was removed stays visible, with `replyTo.visible` false. Moderators see everything, removed comments included, with their status.

### Segment

```json
{ "type": "text", "text": "Hello\nsecond line " }
{ "type": "link", "text": "https://example.com/a", "href": "https://example.com/a" }
{ "type": "mention", "text": "@Alice Smith", "user": "Alice Smith", "id": 3, "exists": true }
{ "type": "mention", "text": "@…", "exists": false }
```

- `text` keeps newlines (`\n`). Render with `white-space: pre-wrap` (or `pre-line`). Give the comment body `dir="auto"`.
- `link.href` is always an `http:` or `https:` URL. Render as `<a href rel="nofollow ugc noopener" target="_blank">` with `text` as its text. Shorten long URLs visually only.
- `mention` comes from a `<@ID>` token (see the input rules). For a registered user who is not hidden, `text` is `@` and the user's current name, `user` is that name, `id` the user ID and `exists` true: link to `User:<user>`. Every other token (a missing user, a user hidden by a hideuser block, a token after a comment's first 50) is the placeholder `@…` with `exists` false and no `user` or `id`. Show its `text` as plain text: it never names anyone.

Input rules (what the server does with typed text):

- Invisible characters are removed: C0 and C1 controls except newline, DEL, bidi embeddings, overrides, isolates and marks, zero-width space, word joiner and the invisible math operators (U+2061 to U+2064), BOM, soft hyphen, the Mongolian vowel separator, the interlinear annotation marks and the object replacement character (U+FFF9 to U+FFFC), and the language tag (U+E0001). Tag characters (U+E0020 to U+E007F) are also removed, except in emoji flags such as the flag of England. Zero-width joiner and non-joiner stay (emoji sequences need them). Tabs become spaces. Text is NFC-normalised, invalid UTF-8 becomes U+FFFD, runs of more than 8 combining marks are cut, more than one blank line in a row is collapsed, and the text is trimmed. Text in which nothing would show (only spaces, combining marks, joiners, variation selectors, Hangul fillers, the Braille blank or other default-ignorable characters) becomes empty.
- URLs: `http://` or `https://` (any case), ending at whitespace, straight or curly quotes, `<>`, CJK punctuation (U+3000 to U+303F), full-width forms (U+FF00 to U+FFEF), or a letter of a script whose text often follows a URL without a space: Han, kana and Hangul, and Thai, Lao, Khmer and Myanmar, which put no spaces between words. Trailing `.,;:!?*'` and unbalanced closing brackets are not part of the link. URLs longer than 2048 bytes stay text. A URL with raw letters of these scripts in its path is linked only up to the first of them, so text written by software (an importer) must percent-encode non-ASCII characters in URLs.
- Mentions are tokens `<@ID>`: `<`, `@`, a user ID in ASCII digits without leading zeros (1 to 10 digits), and `>` (`TextProcessor::MENTION_PATTERN`). Nothing else is a mention: typed `@Name` stays plain text and notifies nobody.
  1. A client inserts the token when the writer picks a name in the autocomplete, and shows it as `@Name`. A token typed or sent by hand mentions that user too: the server cannot tell the two apart.
  2. No space is needed on either side (`谢谢<@42>！`), and a URL ends at `<`, so `https://a.example/x<@5>` is a link and a mention. Malformed tokens such as `<@0>`, `<@01>` and `<@ 1>` are plain text.
  3. The length cap counts the stored token (`<@123>` is 6 characters), not the name shown. A comment that mentions more than `maxMentions` different existing users is refused (`toomanymentions`). When comments are read, the first 50 tokens of each are looked up and show the user's current name, the rest show `@…`.

### Page and viewer blocks of the list response

```json
"page": { "id": 42, "title": "Sourdough starter", "state": "open" },
"viewer": {
  "loggedIn": true,
  "canPost": true,
  "postBlockedReason": null,
  "canReact": true,
  "reactBlockedReason": null,
  "canReport": true,
  "canModerate": false,
  "canErase": false,
  "canConfigure": false
}
```

`postBlockedReason` when `canPost` is false: `login`, `rights`, `blocked` (sitewide, or a partial block covering this page, or any block when the reader also lacks the right), `readonly` (the wiki is in read-only mode, logged-in readers only), `locked`, `disabled` or `ineligible`. Show the matching UI message. For anonymous readers the block is computed from rights only, so the cached response is the same for everyone. `canModerate` and `canErase` are false for blocked users. `reactBlockedReason` is `newaccount` when `canReact` is false because the reader's account is still in the new-account gate (its comments would be held, and its likes would rank comments). Show why the like button is off. Otherwise it is `null`.

For logged-in readers `canPost`, `canReact` and `canReport` (and the per-comment `viewer` flags built from them) follow rights, blocks (partial blocks only on the pages they cover) and read-only mode, but not rate limits. A reader who just used up a limit still sees `canPost: true`, and the write module answers [`ratelimited` with `retryAfter`](#error-codes). Reading the list never counts toward a rate limit.

## REST routes

All routes are `GET`, return JSON and need the `read` right. Every response except the canonical anonymous first page carries `Cache-Control: private, must-revalidate, max-age=0, s-maxage=0`. MediaWiki's own REST errors (a parameter that fails validation, a path that matches no route, `rest-read-denied` on a private wiki) carry no `Cache-Control`.

| Route | Who |
|---|---|
| [`/glowthread/v1/page/{pageId}/comments`](#get-glowthreadv1pagepageidcomments) | Readers of the page |
| [`/glowthread/v1/comment/{id}`](#get-glowthreadv1commentid) | Readers who may see the comment |
| [`/glowthread/v1/page/{pageId}/floor/{seq}`](#get-glowthreadv1pagepageidfloorseq) | Readers who may see the comment |
| [`/glowthread/v1/comment/{id}/thread`](#get-glowthreadv1commentidthread) | Readers who may see the comment |
| [`/glowthread/v1/comment/{id}/replies`](#get-glowthreadv1commentidreplies) | Readers who may see the comment |
| [`/glowthread/v1/moderation/queue`](#get-glowthreadv1moderationqueue) | `glowthread-moderate` |
| [`/glowthread/v1/moderation/user/{name}`](#get-glowthreadv1moderationusername) | `glowthread-moderate` |
| [`/glowthread/v1/moderation/page/{pageId}`](#get-glowthreadv1moderationpagepageid) | `glowthread-moderate` |
| [`/glowthread/v1/settings`](#get-glowthreadv1settings) | `glowthread-moderate` or `glowthread-configure` |

### `GET /glowthread/v1/page/{pageId}/comments`

The comment list of a page.

| Parameter | Values |
|---|---|
| `sort` | `hot` (default) or `new` |
| `cursor` | From `next` of the previous page. Omit for the first page |
| `limit` | 1 to 50 top-level comments, default `$wgGlowThreadPageSize` (20). Leave it out on the first page, see [the canonical URL](#the-canonical-list-url) |

```json
{
  "page": { "id": 42, "title": "Sourdough starter", "state": "open" },
  "sort": "hot",
  "viewer": { "...": "see above" },
  "config": {
    "maxLength": 1000, "maxMentions": 5,
    "reportReasons": [ "spam", "abuse", "offtopic", "spoiler", "misinfo", "other" ],
    "pageSize": 20, "replyPreview": 3
  },
  "count": 57,
  "pinned": [ "...top-level comments with replies..." ],
  "popular": [ "...comments without replies..." ],
  "comments": [ "...top-level comments with replies..." ],
  "next": "eyJrIjoiaG90IiwibiI6MTc5MTAwMDAwMCwibyI6MjB9"
}
```

- `count`: comments readers can see on the page, replies included.
- `pinned` (first page only): pinned top-level comments, newest pin first, at most 10. They are not repeated in `comments`, so pins beyond the newest 10 are not listed at all.
- `popular` (first page only): up to `$wgGlowThreadPopularCount` comments (top-level or replies) whose like count is at least `$wgGlowThreadPopularMinLikes` (the raw count, not the decayed score), ranked by decayed likes. With more candidates than places, an older comment can rank below newer ones with the same likes. Only the 500 candidates with the most likes are ranked: among equal counts the newest, then the highest ID. Empty when all of them are already on the first page (as on the hot first page). Popular replies carry `replyTo` for context. The comment section shows a popular comment in place: in the list when it is there, otherwise from `/comment/{id}/thread`.
- `hot`: `(likes + 1) × 0.5^(age in days / $wgGlowThreadHotHalfLife)`, best first. The cursor keeps the time of the first page, so pages agree. Offsets stop at 1000. The order of two comments does not change with the time, so the wiki may compute it once and keep it until the page changes (see Cache below).
- `new`: newest first, keyset paging.
- `next`: `null` on the last page.
- On a `disabled` page, non-moderators get the page, viewer and config blocks with empty lists and `count` 0.

Errors: 404 `page-not-found` (no such page, or the reader cannot read it), 400 `bad-cursor` (any cursor that was not made by the server, an empty one included, and one with a timestamp that is not a real date: never a 500).

Cache: `Cache-Control: public, s-maxage=<$wgGlowThreadCdnMaxAge>, max-age=0` for the canonical anonymous first page under the purged host with `$wgUseCdn` on, otherwise private. No `Set-Cookie`, no `Vary: Cookie`. The wiki also keeps anonymous first pages, and a summary of each page that the other lists use, in its object cache until the page changes ([caching.md](caching.md#the-wikis-object-cache)). The answers are the same as without it.

### `GET /glowthread/v1/comment/{id}`

```json
{ "comment": { "...comment...", "replyCount": 0 }, "page": { "id": 42, "title": "Sourdough starter", "state": "open" } }
```

`comment` has no `replies`. `replyCount` is the stored count of visible direct children. 404 `comment-not-found` when the comment does not exist, the reader cannot see it or anything it is shown under, its page is gone, comments are disabled there (non-moderators), or the reader cannot read the page.

### `GET /glowthread/v1/page/{pageId}/floor/{seq}`

The same as `/comment/{id}`, by floor number. Use it to resolve "#42" links typed by readers.

### `GET /glowthread/v1/comment/{id}/thread`

The conversation of `id`: its top-level comment with every reply the reader may see (at most 500). The comment section uses it for permalinks and popular comments: to complete a thread in the list, or to fill the "Conversation" box above the list.

```json
{
  "page": { "id": 42, "title": "Sourdough starter", "state": "open" },
  "focus": 15,
  "thread": { "...top-level comment, replies complete at both levels..." },
  "truncated": false
}
```

Scroll to and highlight `focus`. 404 `comment-not-found` as for `/comment/{id}`.

### `GET /glowthread/v1/comment/{id}/replies`

More replies under one comment, oldest first. `cursor` from `next`, `limit` 1 to 100 (default 20). The comment section asks for 50 at a time for "Show N more replies".

```json
{ "parentId": 7, "replies": [ "...comments. Depth-1 replies carry a preview of their own replies and replyCount..." ], "next": null }
```

Errors: 404 `comment-not-found`, 400 `bad-cursor`.

### `GET /glowthread/v1/moderation/queue`

Needs `glowthread-moderate` (403 `permission-denied`).

| Parameter | Values |
|---|---|
| `tab` | `reported` (default: visible or held comments with open reports), `held`, `deleted` (deleted and erased), `all` |
| `sort` | `reports` (most open reports first, then newest, the default of `reported`), `new` (newest first, the default of the other tabs), `old` (oldest first). Any tab takes any order |
| `pageid` | Only this page |
| `user` | Only this author (user name or IP) |
| `reporter` | Only comments with an **open** report filed by this user (user name or IP). An unknown user, or a hidden one for readers without `hideuser`, gives an empty list. Note that `do=dismiss` closes all open reports of a comment, other reporters' included |
| `q` | Text contains this, ignoring case. On MySQL with MediaWiki's default binary connection it compares lower-cased UTF-8 text, so Latin and other cased letters match either case and CJK is unaffected. With other settings the database collation decides (for example accent-insensitive). Postgres follows its `lc_ctype`, SQLite ignores the case of ASCII letters only. A `q` that is exactly a user's name also finds the comments that mention that user (stored as `<@ID>`), unless the user is hidden by a hideuser block and the reader lacks `hideuser` |
| `cursor`, `limit` | Paging, `limit` 1 to 50, default 20. A cursor belongs to its tab and order: a cursor of another `sort` answers 400 `bad-cursor` |

```json
{
  "tab": "reported",
  "sort": "reports",
  "items": [
    {
      "comment": { "...comment with mod..." },
      "page": { "id": 42, "title": "Sourdough starter", "exists": true },
      "context": { "...the comment it replies to, or null..." },
      "reports": [
        { "id": 3, "reason": "spam", "note": "ad link", "created": "2026-10-08T15:00:00Z",
          "reporter": { "name": "Carol", "id": 5, "anon": false, "temp": false, "hidden": false } }
      ]
    }
  ],
  "next": null,
  "counts": { "reported": 1, "held": 4, "deleted": 10 }
}
```

`sort` is the order used, also when the request did not name one. Filters combine (all must match). `counts` ignore the filters. `reported` and `held` (items and counts) leave out comments of deleted pages. `reports` lists up to 10 open reports per item, newest first. The route reads at most 10 times as many open reports as there are items, newest first across all items, so a comment with many recent reports can leave fewer for the others. Imported reports have reason `legacy` and `created` `null`. Their `reporter` is hidden, and the `reporter` filter leaves them out. `items` leave out comments on pages the reader cannot read, and on pages deleted with suppression unless the reader has `viewsuppressed` or `suppressrevision`. After 500 skipped rows a page of results can be short or empty, with a `next` cursor. `counts` include every comment.

### `GET /glowthread/v1/moderation/user/{name}`

Needs `glowthread-moderate`. 404 `user-not-found` for unknown names and for hidden users when the reader lacks `hideuser`. `cursor` and `limit` (1 to 50, default 20) page the comments. 400 `bad-cursor` for a cursor the server did not make.

```json
{
  "user": {
    "name": "Alice", "id": 3, "actorId": 7, "registered": true,
    "registration": "2024-01-01T00:00:00Z", "editCount": 12, "groups": [ "sysop" ],
    "blocks": [ { "sitewide": true, "expiry": "infinity", "reason": "spam", "by": "Moderator" } ]
  },
  "counts": { "visible": 30, "held": 1, "deleted": 2, "erased": 0 },
  "reports": { "filed": 9, "open": 1, "upheld": 6, "dismissed": 2 },
  "comments": [ "...queue items, newest first..." ],
  "next": null,
  "moderation": [
    { "id": 120, "action": "delete", "timestamp": "2026-10-08T15:00:00Z", "performer": "Moderator",
      "commentId": 7, "seq": 12, "reason": "spam" }
  ]
}
```

`reports` counts the reports this user filed and how they ended (`upheld`: the comment was deleted or erased). `moderation` is the last 20 entries of the comment log (`glowthread`) about this user's comments. `blocks` lists active blocks of the account, or for IP authors the blocks of that exact address (range blocks that cover it are not listed). Autoblocks are left out: their target is an IP address and their reason names the blocked account, so they would tie the two together. Log fields hidden by revision deletion or suppression are `null` unless the reader may see them, as in Special:Log. `comments` and `moderation` leave out the same pages as the queue's `items`. `counts` and `reports` do not.

Erased comments are tied to their author only for holders of `glowthread-erase`. For other moderators `counts.erased` is `null`, erased comments are left out of `comments`, and log entries about them are left out of `moderation`. The queue's `user` filter leaves them out the same way, and finds nothing for a user hidden by a hideuser block unless the reader has `hideuser`.

### `GET /glowthread/v1/moderation/page/{pageId}`

Needs `glowthread-moderate`. 404 `page-not-found` for a page the reader may not see by the same rule as the queue's `items`.

```json
{
  "page": { "id": 42, "title": "Sourdough starter", "exists": true, "state": "locked",
    "stateChangedBy": { "name": "Moderator", "...": "..." }, "stateChangedAt": "2026-10-08T15:00:00Z", "floors": 57 },
  "counts": { "visible": 50, "held": 2, "deleted": 5, "erased": 0, "reported": 3 },
  "moderation": [ "...last 20 log entries for this page, as above..." ]
}
```

`moderation` lists the page's last 20 entries of the comment log (`glowthread`). Where GlowThread registers FlowThread's `comments` log type, FlowThread's entries for the page are listed too, see [FlowThread's log entries](#flowthreads-log-entries).

### `GET /glowthread/v1/settings`

Needs `glowthread-moderate` or `glowthread-configure`.

```json
{
  "settings": {
    "maxLength": { "value": 1000, "default": 1000, "editable": true, "overridden": false, "type": "int", "min": 1, "max": 10000 },
    "reportReasons": { "value": [ "spam", "other" ], "default": [ "spam", "abuse", "offtopic", "spoiler", "misinfo", "other" ],
      "editable": true, "overridden": true, "type": "list", "options": [ "..." ], "minItems": 1,
      "changedAt": "2026-10-08T15:00:00Z", "changedByActor": 1 },
    "publicLog": { "value": true, "default": true, "editable": true, "overridden": false, "type": "bool" }
  },
  "canConfigure": true,
  "filter": {
    "page": "MediaWiki:GlowThread-filter",
    "rules": 12,
    "invalid": [ { "line": 4, "rule": "/(broken/", "error": "missing closing parenthesis at offset 7" } ]
  },
  "rateLimits": [
    { "key": "glowthread-post", "limits": { "user": [ 6, 60 ], "newbie": [ 3, 60 ], "ip": [ 3, 60 ], "ip-all": [ 12, 60 ], "subnet-all": [ 24, 60 ] }, "canBypass": true },
    { "key": "glowthread-erase", "limits": { "user": [ 30, 3600 ], "ip-all": [ 60, 3600 ], "subnet-all": [ 120, 3600 ] }, "canBypass": false }
  ]
}
```

The settings are listed in [configuration.md](configuration.md). `editable: false` means the administrator locked the setting in LocalSettings.php. `minOn` (only on `autoHoldReporters`): the setting is off at `min` and otherwise, when set on the wiki, at least `minOn`. So `autoHoldReporters` takes `0` or `2` to `100`: one account alone must not be able to hold a comment. LocalSettings.php may still set the default to `1`.

`rateLimits` lists the effective `$wgRateLimits` of GlowThread's keys, in this order and only those configured: `glowthread-post`, `glowthread-react`, `glowthread-report`, `glowthread-moderate`, `glowthread-erase`, `glowthread-configure`. `limits` maps each limit type of core (`anon`, `user`, `newbie`, `ip`, `subnet`, `ip-all`, `subnet-all`, `user-global` and group names) to `[ count, seconds ]`. `canBypass: false` means `noratelimit` (sysops, bots) does not exempt anyone from it. Read-only: the limits are set in LocalSettings.php. The word filter is edited on its wiki page, and `invalid` lists the lines that are ignored.

### REST errors

```json
{ "errorKey": "comment-not-found", "messageTranslations": { "en": "The comment does not exist or is not visible to you." },
  "httpCode": 404, "httpReason": "Not Found" }
```

Use `errorKey` in code. Invalid parameters (a `sort` that is neither `hot` nor `new`, a non-numeric ID, a `limit` out of range) are refused by MediaWiki's REST parameter validation with HTTP 400 before GlowThread runs.

## Write modules

All modules: POST only, `token` (CSRF) required in the POST body, write mode (refused in read-only mode), and every reader write checks read access, rights and blocks on the target page. Use `formatversion=2`. Results are under the module name, for example `{ "glowthreadpost": { ... } }`.

| Module | Does | Right |
|---|---|---|
| [`glowthreadpost`](#actionglowthreadpost) | New comment or reply | `glowthread-post` |
| [`glowthreadreact`](#actionglowthreadreact) | Like or take the like back | `glowthread-react` |
| [`glowthreadreport`](#actionglowthreadreport) | Report a comment | `glowthread-report` |
| [`glowthreaddelete`](#actionglowthreaddelete) | The author deletes their own comment | `glowthread-post` |
| [`glowthreadmoderate`](#actionglowthreadmoderate) | Approve, delete, restore, erase, pin, unpin, dismiss reports, undo | `glowthread-moderate` (`erase` also `glowthread-erase`) |
| [`glowthreadpage`](#actionglowthreadpage) | Open, lock or turn off a page's comments | `glowthread-moderate` |
| [`glowthreadfiltertest`](#actionglowthreadfiltertest) | Test word-filter rules without saving | `glowthread-configure` |
| [`glowthreadsettings`](#actionglowthreadsettings) | Change on-wiki settings | `glowthread-configure` |

### `action=glowthreadpost`

| Parameter | |
|---|---|
| `pageid` | Page ID (required) |
| `text` | Comment text (required). At most 20000 characters and 44096 bytes before normalisation. The real cap is `maxLength`, applied after it. Input longer than 4 bytes per character of `maxLength` plus 4096 bytes is refused `toolong` before it is normalised |
| `replyto` | ID of the comment answered (optional) |

```json
{ "glowthreadpost": {
  "result": "success", "id": 58, "seq": 13, "status": "held", "holdReasons": [ "newaccount" ],
  "comment": { "...comment, as the author sees it..." }
} }
```

`status` `held` is not an error: show the comment to its author with a "waiting for review" note. Right: `glowthread-post`. Rate limit: `glowthread-post`. `holdReasons` names only what is about the author: `newaccount` (the new-account gate) and `parentheld`. For authors without `glowthread-moderate` it leaves out the content checks (`filter`, `filter-error`, `spamblacklist`), so a comment held by the word filter comes with an empty list. The author still sees that it is held, but not which check held it, so the response cannot be used to tune text against a particular check. Moderators get the full list, here and in `mod.holdReasons`.

A reply to the author's own held comment (or under it) is held too, with hold reason `parentheld`, so it is reviewed with its parent and notifies nobody until a moderator approves it. A reply approved while its parent is still held notifies nobody yet (readers cannot see it). Its notifications go out when the parent is approved.

An author who already has `$wgGlowThreadMaxHeldPerUser` comments (default 3) waiting for review gets `toomanyheld` (data `max`) for a comment that would be held. Comments that would be visible are not affected. Show "wait until your comments have been reviewed".

Errors: `pagenotfound`, `pageineligible`, `commentsdisabled`, `commentslocked`, `emptycomment`, `toolong` (data `max`), `toomanymentions` (data `max`), `duplicate`, `badparent`, `toomanyheld` (data `max`), plus the [common errors](#error-codes).

### `action=glowthreadreact`

| Parameter | |
|---|---|
| `id` | Comment ID |
| `value` | `1` like, `0` take the like back. Idempotent, not a toggle |
| `type` | `like` (default, the only type) |

```json
{ "glowthreadreact": { "result": "success", "id": 7, "liked": true, "likes": 4, "changed": true } }
```

Works on locked pages. Errors: `commentnotfound` (missing, not visible to readers, or page gone. It is checked before rights, blocks and the page state, so the answer does not tell a hidden comment from a missing one), `ownlike`, `newaccount` (the account is still in the new-account gate: it cannot add a like, but it can take one back), `pageineligible`, `commentsdisabled`. Right and rate limit: `glowthread-react`. A like does not purge the cached list (see [the canonical list URL](#the-canonical-list-url)).

### `action=glowthreadreport`

| Parameter | |
|---|---|
| `id` | Comment ID |
| `reason` | `spam`, `abuse`, `offtopic`, `spoiler`, `misinfo`, `other`. Only the reasons in `config.reportReasons` are accepted |
| `note` | Optional, at most 500 characters after normalisation (`notetoolong`). Input over 1000 characters is refused by parameter validation before that |

```json
{ "glowthreadreport": { "result": "success", "id": 7, "already": false, "held": false } }
```

One report per user and comment: repeating returns `already: true` and changes nothing. `held: true` means this report reached the auto-hold threshold. Only eligible reporters count toward it: registered and not a temporary account, not blocked sitewide or from the page, and either `glowthread-trusted` or past the new-account gate themselves with an account at least 7 days old and a visible comment. Pinned comments and comments by users with `glowthread-moderate` or `glowthread-trusted` are never held by reports. Their reports only reach the moderation queue. Errors: `commentnotfound` (as for likes, before the page checks), `ownreport`, `badreason`, `notetoolong`, `pageineligible`, `commentsdisabled`. Right and rate limit: `glowthread-report` (default 20 a day per user).

### `action=glowthreaddelete`

The author deletes their own comment. `id`, optional `reason`.

```json
{ "glowthreaddelete": { "result": "success", "id": 7, "status": "deleted" } }
```

Needs an account, `glowthread-post` and no block covering the page (counts against the `glowthread-post` rate limit). Logged as `glowthread/delete` with the author as performer (hidden again if the comment is later erased, see [log entries](#log-entries)). Errors: `commentnotfound` (missing, or someone else's comment that the requester cannot see: held, deleted or erased, so the error does not confirm that it exists), `notauthor` (someone else's comment the requester can see. Anonymous requests always get this or `commentnotfound`), `badstatus`, `pageineligible`, `commentsdisabled`.

### `action=glowthreadmoderate`

| Parameter | |
|---|---|
| `ids` | Up to 50 comment IDs, separated by `\|` |
| `do` | `approve` (held → visible), `delete` (visible or held → deleted), `restore` (deleted → the status before the deletion: `visible`, or `held` with its hold reasons for a comment deleted while held, see `mod.deletedFrom`), `erase` (deleted → erased, irreversible, needs `glowthread-erase`), `pin` (visible top-level comments), `unpin`, `dismiss` (close open reports), `undo` (see below) |
| `logids` | `undo` only: the log entry to undo for each comment, in the order of `ids` (same count, up to 50) |
| `reason` | Optional, at most 500 characters, shown in Special:Log |

(The parameter is `do` because `action` is taken by the API itself.)

```json
{ "glowthreadmoderate": {
  "result": "partial",
  "do": "delete",
  "results": [
    { "id": 7, "result": "success", "status": "deleted", "old": "held", "logId": 1204, "code": null },
    { "id": 8, "result": "nochange", "status": "deleted", "old": "deleted", "logId": null, "code": null },
    { "id": 9, "result": "error", "status": null, "old": null, "logId": null, "code": "commentnotfound" }
  ]
} }
```

`result` is `success`, `partial` or `failure`. Per item:

- `status` is the status after the action (for `restore`: `visible` or `held`).
- `old` is the status the server found before it. It can differ from the client's copy, because reports can hold a comment after the list was loaded.
- `logId` is the `glowthread/*` log entry written for that comment, `null` for `nochange` and errors.
- `code` is set for errors: `commentnotfound`, `badstatus` (the action does not apply to the current status), `cannotpin` (replies cannot be pinned), `ratelimited` (`erase` only: the erase limit was reached, the comment stays deleted), and for `undo` `badlogid` and `cannotundo`.

Needs `glowthread-moderate` and **no block of any kind**, partial blocks included. Rate limit: `glowthread-moderate`. Side effects: `approve` dismisses open reports, `delete` and `erase` uphold them, `erase` also hides the comment's reply and mention notifications and the author's own earlier log entries about it, and blanks the notes of its reports.

**Undo** (`do=undo`) reverses exactly the log entries named in `logids`, one per comment in `ids` (the `logId` of earlier results). A comment may appear more than once, to undo several of its entries in that order. What each entry undoes:

| Entry | Undo |
|---|---|
| `approve` | Back to held (with its hold reasons). The reports that approval dismissed are open again |
| `delete` (moderator or author) | Back to the status before (`visible` or `held`, hold reasons kept). The reports it upheld are open again |
| `recover` (restore) | Deleted again |
| `pin` | Unpinned |
| `unpin` | Pinned again with the pin time it had (the same place on the page) |
| `markchecked` (dismiss) | The reports it dismissed are open again |

Undoing a status change (`approve`, `delete`, `recover`) also puts back who set the status before and when (`mod.statusBy`, `mod.statusAt`). So an automatic hold shows `statusBy: null` again, and several status changes of one comment can be undone newest first. Reports are reopened only if they are still resolved by that action (a report resolved again later stays resolved), and the open report count follows.

Per-item answers:

- `nochange`: the entry was already undone.
- `badstatus`: the comment changed since that entry, so undoing it would not restore what the entry changed. For status changes: another status change since (to the second). For `pin`: the pin time differs. For `unpin`: the comment is no longer visible (only visible comments are pinned), or it was pinned or unpinned again since (not undone). For `markchecked`: the comment was deleted, erased or changed status since, or none of the reports it dismissed is still dismissed by it.
- `badlogid`: the entry does not exist, is neither a `glowthread` entry nor one of FlowThread's `comments` entries, is about another comment, or its action is hidden from this moderator by log deletion.
- `cannotundo`: the entry has no undo. That is `erase`, `undo` itself, page state, settings and import entries, FlowThread's entries, and entries without the parameters an exact undo needs (see [log entries](#log-entries)).

Each undo writes a `glowthread/undo` log entry (its `logId` is in the result) and runs `GlowThreadPostModerated` with action `undo`. Echo sends no notice for an undo, so an author told about a deletion is not told when it is undone, and notices already sent stay. Approving a comment again after its approval was undone does not send its reply, mention and user page notices again (a comment notifies once), only the author's approval notice. Error `badlogids` when `logids` is missing or its count differs from `ids`. Same rights, block rules and rate limit as the other actions (`glowthread-moderate`).

`erase` counts each erased comment against the `glowthread-erase` limit (default 30 an hour per moderator), which `noratelimit` does not bypass. The comments beyond it get the per-item code `ratelimited` and stay deleted. When the limit is already used up, the whole request fails with `ratelimited` and `retryAfter`. Reaching the limit is logged (channel `GlowThread`).

### `action=glowthreadpage`

`pageid`, `state` (`open`, `locked`, `disabled`), optional `reason`.

```json
{ "glowthreadpage": { "result": "success", "pageid": 42, "state": "locked", "old": "open", "changed": true } }
```

Needs `glowthread-moderate` and no block. Purges the list and the article. Errors: `pagenotfound`, `pageineligible`.

### `action=glowthreadfiltertest`

`text`, optional `rules` (draft rules to test instead of the wiki page). Saves nothing.

```json
{ "glowthreadfiltertest": {
  "held": true,
  "matches": [ { "line": 3, "rule": "coins", "type": "literal" } ],
  "errors": [],
  "invalid": [ { "line": 5, "rule": "/(broken/", "error": "missing closing parenthesis at offset 7" } ],
  "rules": 4
} }
```

`errors` are rules that failed at run time on this text (the comment would be held). Needs `glowthread-configure` and no block. `text` is at most 10000 characters (40000 bytes), `rules` at most 65536 bytes. Each call counts against the `glowthread-configure` limit (default 60 a minute), which sysops cannot bypass: `ratelimited` with `retryAfter`.

### `action=glowthreadsettings`

`settings`: a JSON object of setting names and values. `null` resets a setting to its configured default. Optional `reason`.

```json
{ "glowthreadsettings": {
  "result": "success",
  "changed": [ { "name": "newAccountHours", "old": 24, "new": 48 } ],
  "logId": 812,
  "settings": { "...as GET /settings..." }
} }
```

`logId` is the `glowthread/settings` log entry of this change, for a link to `Special:Log?logid=812`. It is `null` when every value was already set as sent (`changed` is then empty).

Needs `glowthread-configure` and no block. Counts against the `glowthread-configure` limit like the filter test. All values are validated before anything is saved. Errors: `badsettingsjson`, `unknownsetting`, `settinglocked`, `badsettingvalue`, `nochange` (empty object).

## Error codes

Action API errors come as `{ "errors": [ { "code": "...", "text": "..." } ] }` (with `errorformat=plaintext`) or `{ "error": { "code": "...", "info": "..." } }`. Codes are stable. Messages are localised through `uselang`.

| Code | Meaning | Message key |
|---|---|---|
| `badtoken`, `missingparam` (token), `mustbeposted`, `mustpostparams` | No valid CSRF token in the POST body | core |
| `permissiondenied` | The user lacks the right (anonymous users included) | core |
| `blocked`, `autoblocked` | Block covering the page, or for moderation any block. Block details in `data.blockinfo` | core |
| `ratelimited` | `$wgRateLimits` key exceeded. `data.retryAfter`: whole seconds to wait before trying again, the longest window of the limits that apply. It is an upper bound: with the default limits, the window of the limit, for example 60 for posts and likes, 86400 for reports, 3600 for erasing. Read it as `errors[0].data.retryAfter` with `errorformat=plaintext` | core `apierror-ratelimited` |
| `readonly` | Wiki in read-only mode | core |
| `badvalue`, `toomanyvalues`, `badinteger` ... | Parameter validation | core |
| `pagenotfound` | No such page | `glowthread-error-page-missing` |
| `pageineligible` | Comments are not available there | `glowthread-error-page-ineligible` |
| `commentsdisabled` | Page state `disabled` | `glowthread-error-disabled` |
| `commentslocked` | Page state `locked` (posting and replying only) | `glowthread-error-locked` |
| `emptycomment` | Nothing visible left after normalisation | `glowthread-error-empty` |
| `toolong` | Over `maxLength`, data `max` | `glowthread-error-too-long` |
| `toomanymentions` | Over `maxMentions` distinct existing users, data `max` | `glowthread-error-too-many-mentions` |
| `toomanyheld` | The author already has `max` comments waiting for review and this one would be held too, data `max` | `glowthread-error-too-many-held` |
| `duplicate` | Same text by the same user on the same page within `duplicateWindow`, ignoring case, spacing, full-width forms and invisible characters | `glowthread-error-duplicate` |
| `badparent` | Reply target missing, on another page, or not visible (held targets only for their author) | `glowthread-error-parent` |
| `commentnotfound` | Comment missing or not visible to the requester | `glowthread-error-comment-missing` |
| `ownlike`, `ownreport` | Own comment | `glowthread-error-own-like`, `glowthread-error-own-report` |
| `newaccount` | Like from an account still in the new-account gate (taking a like back works) | `glowthread-error-new-account-like` |
| `badreason` | Report reason turned off | `glowthread-error-report-reason` |
| `notetoolong` | Report note over 500 characters | `glowthread-error-note-too-long` |
| `notauthor` | Self-delete of someone else's comment | `glowthread-error-not-author` |
| `badstatus` | Action does not apply to the status | `glowthread-error-bad-status` |
| `cannotpin` | Pinning a reply (per-item code only) | |
| `badlogids` | `do=undo` without `logids`, or not one log ID per comment ID | `glowthread-error-undo-logids` |
| `badlogid`, `cannotundo` | `do=undo`: the log entry is not about that comment, or has no undo (per-item codes only) | |
| `badsettingsjson`, `unknownsetting`, `settinglocked`, `badsettingvalue`, `nochange` | Settings | `glowthread-error-settings-json`, `-setting-unknown`, `-setting-locked`, `-setting-range`, `-setting-range-off` or `-setting-invalid`, `-settings-empty` |

## Hooks for other extensions

GlowThread runs these hooks (interfaces in `includes/Hook/`, runner `MediaWiki\Extension\GlowThread\HookRunner`). All run **after the response is sent**: a POSTSEND deferred update queued by the write, cancelled if the write transaction rolls back. They still run in the request of the user who acted, so `RequestContext::getMain()->getRequest()` has their IP and user agent (what CheckUser records), but the user does not wait for them. None can abort. Register handlers in your `extension.json` `Hooks` as usual.

`PostRecord` is `MediaWiki\Extension\GlowThread\Model\PostRecord`. Its read-only getters: `getId()`, `getPageId()`, `getActorId()`, `getParentId()`, `getReplyToId()`, `getThreadId()`, `getRootId()`, `getDepth()`, `getSeq()`, `getText()`, `getCreated()` (TS_MW), `getStatus()`, `isVisible()`, `isHeld()`, `getHoldReasons()`, `getLikeCount()`, `getReplyCount()`, `getOpenReportCount()`, `isPinned()`, `getLegacyId()`, `getHoldMatches()` (the filter rules that held it). IDs are ints, so they are safe in Echo's JSON `extra` on MediaWiki 1.46. Do not put objects in `extra`.

```php
// A new comment or reply was saved, visible or held.
public function onGlowThreadPostSaved(
	PostRecord $post, ProperPageIdentity $page, UserIdentity $author,
	array $mentionedUsers,          // UserIdentity[]: registered, not hidden, author left out, at most maxMentions
	?PostRecord $replyTo,           // the comment answered, null for top-level comments
	?UserIdentity $replyToAuthor
): void;

// An approval let readers see a held comment (see below). Send the notifications PostSaved held back.
public function onGlowThreadPostApproved(
	PostRecord $post, ProperPageIdentity $page, UserIdentity $author,
	array $mentionedUsers, ?PostRecord $replyTo, ?UserIdentity $replyToAuthor,
	UserIdentity $moderator
): void;

// Any moderation action on one comment, and author self-deletion.
public function onGlowThreadPostModerated(
	PostRecord $post,               // after the action
	?ProperPageIdentity $page,      // null if the page no longer exists
	string $action,                 // approve, delete, restore, erase, pin, unpin, dismiss, selfdelete, undo.
	                                // For undo, $post is the comment after the undo and $logId the
	                                // glowthread/undo entry, whose 7::undone names the entry undone.
	                                // After restore $post is visible, or held when it was deleted while held.
	UserIdentity $performer, string $reason,
	UserIdentity $author, int $logId
): void;

// A reader filed a report.
public function onGlowThreadReportFiled(
	PostRecord $post, ProperPageIdentity $page, UserIdentity $reporter,
	string $reason, bool $autoHeld   // true when this report held the comment
): void;
```

Rules for notification handlers:

- `GlowThreadPostSaved` with `$post->isHeld()`: send nothing. Wait for `GlowThreadPostApproved`, which carries the same data. At approval the mentions are recomputed from the text and are not capped, so apply `maxMentions` yourself. `GlowThreadPostApproved` does not run when the page no longer exists. It runs only once readers can see the comment: for the approved comment, unless a comment it is shown under is held, deleted or erased, and, when that comment is approved, for the replies under it that were held and approved before it. It does not check whether comments are turned off on the page. It can run again for a comment readers saw before (an approval undone and repeated, a comment held by reports approved), so send each notification once.
- Do not notify `$author` about their own comment, and skip `$replyToAuthor` when it equals `$author`.
- Moderation outcomes for the author: `GlowThreadPostModerated` with `delete`, `restore`, `erase`. A `restore` can leave the comment held (it was deleted while held), so check `$post->isVisible()` before treating it as published. `approve` also runs `GlowThreadPostModerated` after `GlowThreadPostApproved`. `selfdelete` is the author's own action.
- Erased comments: never show their text, it is blank. Privacy: after `erase`, the `$author` of `GlowThreadPostModerated` is the erased comment's author, whom GlowThread hides from everyone without `glowthread-erase`. The `$reporter` of `GlowThreadReportFiled` is shown only to moderators. Publish neither, and remove what you copied of an erased comment's text and author.
- Echo needs a `Title` on MediaWiki 1.43: `TitleFactory::newFromPageIdentity( $page )`.

For CheckUser: a comment post is not a log action. GlowThread's own handler (`includes/CheckUser/CheckUserHooks.php`) records each new comment and each report as a CheckUser private event, see [CheckUser records](#checkuser-records). Moderation actions are published to RecentChanges, so CheckUser records them on its own. While the `publicLog` setting is off they get no RecentChanges row, and GlowThread passes them to the `CheckUserInsert` service directly.

## Log entries

GlowThread writes two log types:

- `glowthread`, shown in Special:Log/glowthread ("Comment log"). It is readable only with `glowthread-viewlog` (`$wgLogRestrictions`), which `LogVisibilityHooks` gives to everyone while the `publicLog` setting is on, and otherwise only to users with `glowthread-moderate`, `glowthread-erase` or `glowthread-configure`. Core leaves restricted types out of RecentChanges, so `ModerationLogger` inserts the RecentChanges row itself, and only while `publicLog` is on. Entries logged while it is off get no row, so they send no watchlist emails or notifications and never appear in RecentChanges or watchlists. `LogVisibilityHooks` also hides the type's existing rows from Special:RecentChanges, Special:RecentChangesLinked, Special:Watchlist, `list=recentchanges` and `list=watchlist` for users without the right. It is hidden from "Main public logs" by default (`$wgFilterLogTypes`).
- `glowthread-private`, shown in Special:Log/glowthread-private ("Comment erasure log"). It is readable only with `glowthread-erase` (`$wgLogRestrictions`) and is not in RecentChanges.

Both are formatted by `includes/Log/CommentsLogFormatter.php`, with the message keys `glowthread-logentry-<action>` and `glowthread-logentry-private-<action>`.

| Type/action | Written by | Target | Parameters |
|---|---|---|---|
| `glowthread/delete` | moderator delete, author self-delete | page | `4::comment`, `5:user-link:author`, `6::seq` |
| `glowthread/recover` | restore | page | same |
| `glowthread/approve` | approve | page | same |
| `glowthread/markchecked` | dismiss reports | page | same |
| `glowthread/autohold` | automatic hold by reports (performer: the system user "GlowThread") | page | same |
| `glowthread/pin`, `glowthread/unpin` | pin, unpin | page | same |
| `glowthread/erase` | erase | page | `4::comment`, `6::seq` (no author) |
| `glowthread-private/erase` | erase | page | `4::comment`, `5:user-link:author`, `6::seq` |
| `glowthread/enable`, `glowthread/lock`, `glowthread/disable` | page state open, locked, disabled | page | none |
| `glowthread/undo` | `glowthreadmoderate` `do=undo` | page | `4::comment`, `5:user-link:author`, `6::seq`, `7::undone` (the log ID undone) |
| `glowthread/settings` | settings change | Special:GlowThread/settings | `4::changes`: list of `[ name, old, new ]` |
| `glowthread/import` | the FlowThread importer | Special:GlowThread | `4::count` (all comments imported so far, not only by this run), `5::options` (the import choices, JSON). Written to `logging` only, never published to RecentChanges |
| `glowthread/post` | CheckUser handler only, never in Special:Log | page | `4::comment`, `6::seq` |
| `glowthread/report` | CheckUser handler only, never in Special:Log | page | `4::comment`, `6::seq` |

- When the page no longer exists, the target is `Special:GlowThread/page/<page ID>`.
- Comment entries carry `log_search` relations `glowthread_post` (comment ID) and `glowthread_author` (author actor ID). The public `glowthread/erase` entry has no author relation. `glowthread/undo` entries also carry `glowthread_undo` (the log ID undone).
- `5:user-link:author` keeps the name at the time. Special:Log and `list=logevents` show the current name through `glowthread_author`.
- Some entries also keep what an exact undo needs. `delete` has `7::old` (the status before, `visible` or `held`). `recover` has `7::new` (the status the restore set). `unpin` has `7::old` (the pin time, TS_MW). `approve`, `delete` and `recover` have `9::prev` (`<actor ID>|<TS_MW>` of who set the status before and when, either part empty). `approve`, `delete`, `erase` and `markchecked` have `8::reports` (comma-separated IDs of the reports they resolved) when they resolved any. Neither the log messages nor `list=logevents` and `list=recentchanges` show these parameters. Machine-readable RC feeds (`$wgRCFeeds` with a JSON formatter) do: they send `log_params` raw, these included (`9::prev` holds an actor ID), as for every log type. Point such a feed only at trusted consumers.
- Legacy anonymous authors (IP addresses) are linked to their contributions, which core's `user-link` type cannot do for IPs.
- The formatter hides the author, in Special:Log and in `list=logevents`, from readers without `glowthread-erase` when the comment was later erased, and from readers without `hideuser` when the author is hidden by a hideuser block. Core's `user-link` parameter type does not check hidden users, so this is done here.
- Erasing also hides the author where they are the **performer**. The comment's public entries that its author performed (their own `glowthread/delete`, or a moderator acting on their own comment, including the `glowthread/erase` entry when moderators erase their own comment) get `log_deleted` (and `rc_deleted`) `DELETED_USER | DELETED_COMMENT`, as core's log revision deletion does. Readers without `deletedhistory` then see neither who acted nor the reason, in Special:Log, `list=logevents` (`userhidden`, `commenthidden`) and RecentChanges.

### FlowThread's log entries

FlowThread wrote its entries to the log type `comments`. GlowThread never writes that type. With `$wgGlowThreadLegacyCompat` on, GlowThread registers it with the same formatter, so the old entries keep rendering in Special:Log/comments ("FlowThread comment log") and in the moderation history of a page (`moderation` of [`/moderation/page/{pageId}`](#get-glowthreadv1moderationpagepageid)). Its entries then have the same readers as the `glowthread` type (`$wgLogRestrictions`: `glowthread-viewlog`). With the setting off, its entries are hidden (`$wgLogRestrictions`: `glowthread-legacy-log`), and RecentChanges rows show no `logparams`. It is not registered when another extension already registered that name: FlowThread itself, or Extension:Comments, which uses `comments` for its own log (`includes/Log/LegacyLogType.php`).

- FlowThread's entries are `delete`, `recover` and `markchecked` with `4::username`, `erase` with `4::postid` and `5::children`, `import` with `4::count`, `disable` and `enable`. They keep FlowThread's message keys `logentry-comments-*`. A page's moderation history on a migrated wiki therefore also lists FlowThread's entries for that page, `enable`, `disable` and `import` among them, with `commentId` and `seq` `null`. The user history does not list them, because they carry no `glowthread_author` relation.
- Their author name follows the same rules as GlowThread's: hidden from readers without `hideuser` when the user is hidden by a hideuser block, and in `erase` entries (whose `4::postid` holds the author's name, a FlowThread bug) always hidden from readers without `glowthread-erase`, as GlowThread's own erase entries name nobody. In the API the parameter (`username` or `postid`) is left out then.
- They cannot be undone (`cannotundo`), because they name no GlowThread comment.

## Data model notes for importers

Schema: `sql/tables.json`. Status values and constants are in `includes/Model/`. The FlowThread importer and its mapping are described in [migration-data.md](migration-data.md).

- `glowthread_post.gtp_status`: `visible`, `held`, `deleted`, `erased`. FlowThread status 0 → `visible`, 1 (deleted) → `deleted`, 2 (spam) → `held` with `gtp_hold_reason = 'filter'` (or `deleted`, an administrator's choice).
- Placement: `gtp_thread` is the top-level ancestor (null for top level). `gtp_reply_to` is the comment answered (null for top level). `gtp_depth` is `min( true depth, 2 )` and `gtp_parent` is the display parent: the true parent for depth 1 and 2, and for deeper comments their depth-1 ancestor. So `gtp_parent` always has depth `gtp_depth - 1`.
- `gtp_seq`: number the comments of each page from 1 in creation order, then call `PageStateStore::raiseSeqFloor( $dbw, $pageId, $maxSeq )` (service `GlowThread.PageStateStore`) so new comments continue above. `(gtp_page, gtp_seq)` is unique.
- `gtp_legacy_id`: the FlowThread ID as 22 lowercase hex digits (unique). Special:GlowThreadLink accepts it in any case.
- `gtp_text`: plain text, normalised with `TextProcessor::normalize()` (service `GlowThread.TextProcessor`). Mentions are `<@ID>` tokens (`TextProcessor::mentionToken()`). The FlowThread importer writes a token for each registered user who is not hidden, plain `@Name` for a name that is no user, and `@…` for a hidden user. Percent-encode non-ASCII characters in URLs (`https://wiki.example.org/wiki/%E6%97%A5...`), because a raw CJK letter ends a URL. `TextProcessor::normalize()`, `tokenize()`, `extractUrls()` and `extractMentions()` keep their signatures. The last two return lists of strings.
- Counters must match the rows: `gtp_like_count` = likes, `gtp_open_report_count` = reports with `gtrp_resolved_at` null, `gtp_reply_count` = **visible** comments whose `gtp_parent` is this comment.
- `gtp_created`, `gtp_status_timestamp`, `gtp_pinned` are `mwtimestamp`: write them with `$dbw->timestamp()`.
- Anonymous legacy authors: IP actors (`ActorNormalization::acquireActorId( new UserIdentityValue( 0, $ip ), $dbw )`).
- `glowthread_reaction`: `gtr_type = 'like'`, `gtr_timestamp` null for imported likes. Primary key `(gtr_post, gtr_actor, gtr_type)`.
- `glowthread_report`: `gtrp_reason = 'legacy'`, `gtrp_created` null, one row per `(post, actor)`.
- `glowthread_page`: `gtpg_state` `open`, `locked` or `disabled` (FlowThread control status 2, comments turned off by an administrator → `locked` by default, or `disabled`. Status 1, opted out → `locked` by default, or `disabled` or `open`. Both are the administrator's choice, see [import choices](migration-data.md#import-choices)), `gtpg_seq` the floor counter.
- No moderation hooks or log entries run during import unless the importer writes them.

## UI messages

UI strings live in `i18n/ui/*.json`. Every key in `i18n/ui/en.json` is shipped to `ext.glowThread.app` and `ext.glowThread.admin` automatically (`includes/ResourceLoader/UiMessagesModule.php` reads the file, and the comment section leaves out the `glowthread-ui-admin-*` keys), so adding a message needs no change to `extension.json`. Add the key to `en.json` and `qqq.json` (and to the other languages you can translate), then read it with `mw.message( key )`. Backend and API messages stay in `i18n/` and `i18n/api/`.

## Permalinks

| Link | Points to |
|---|---|
| `Special:GlowThreadLink/<id>` | A GlowThread comment ID |
| `Special:GlowThreadLink/<22 hex digits>` | The FlowThread ID of an imported comment |
| `Special:GlowThreadLink/<page title>/<floor>` | A floor number on a page, for example `Special:GlowThreadLink/Sourdough_starter/42` |

Each redirects to the page with the fragment `#gt-<id>`. The comment section gives each comment the element ID `gt-<id>` and scrolls to it. When the comment is not in the list, it fetches `/comment/<id>/thread` and shows the comment in place: unfolded in the list when its thread is there, otherwise in a "Conversation" box above the list. Comments the reader cannot see get the same "not found" page as missing ones (HTTP 404). Copy-link buttons, log entries and notifications use `Special:GlowThreadLink`.

`#glowthread` opens the comment section. Links made on a wiki that used FlowThread keep working: `Special:FlowThreadLink/...` leads to the same place as `Special:GlowThreadLink/...`, `Special:FlowThreadManage` redirects to `Special:GlowThread/queue`, and an address ending in `#flowthread` opens the comment section.

## Notifications (Echo)

Sent only when Echo is loaded (`includes/Echo/`). Types are registered with the `BeforeCreateEchoEvent` hook, which Echo runs on MediaWiki 1.43 and 1.46 alike. All four are in the category `glowthread` ("Comments" in Special:Preferences): web on and email off by default, both user-toggleable. Echo's section `message` is shown as Notices, `alert` as Alerts.

| Type | Recipient | Sent when | Section, icon |
|---|---|---|---|
| `glowthread-reply` | Author of the comment answered (`gtp_reply_to`) | A visible reply is saved, or a held reply is approved | `message`, `chat` |
| `glowthread-mention` | Mentioned users, at most `maxMentions`, in the order mentioned | A visible comment is saved, or a held one is approved | `message`, `mention` |
| `glowthread-userpage` | Owner of the user page the comment is on (`User:Name`, not its subpages), like FlowThread's `flowthread_userpage` | A visible comment is saved, or a held one is approved | `message`, `edit-user-talk` |
| `glowthread-moderation` | Comment author | A moderator deletes (with the reason), restores (only when the restore makes it visible), or approves a held comment | `alert`, `revert` (delete) or `reviewed` |

Nobody gets one:

- For their own comment or action (replying to yourself, mentioning yourself, a moderator acting on their own comment, self-deletion), or for an IP author.
- For a held comment (`GlowThreadPostSaved` with status `held`). Approval sends what saving held back, with the mention cap applied at that time.
- For a comment readers cannot see: one shown under a deleted or erased comment, or on a page whose comments are turned off. Nothing is sent for it later either. A reply under the author's own held comment is held itself (`parentheld`) and notifies once readers can see it: when it is approved, or, if its parent is still held then, when the parent is approved.
- No reply, mention or user page notice goes out again when a moderator approves a comment that reports had held (it was visible before) or an imported FlowThread comment (FlowThread notified at the time). The author still gets the approval notice.
- When a held comment that was deleted is restored: `restore` makes it held again, so its notices wait for its approval, as for any held comment. The author gets no restore notice either, and the approval sends the approval notice. Where the status before the deletion is unknown (`mod.deletedFrom` is `null`: FlowThread's deletions, imported comments), `restore` publishes the comment and only the restore notice goes out, no reply or mention notices.
- For an `undo`: undoing a moderation action sends nothing. Approving again after an undone approval does not send the reply, mention and user page notices again: GlowThread sends them once per comment (it looks for the comment's events in Echo's table). The author gets the approval notice again.
- When the replier is blocked from the page (sitewide, or a partial block covering the page or its namespace): no reply, mention or user page notice.
- When the recipient cannot read the page.
- Beyond 3 an hour from one author to one user: reply, mention and user page notices from the same author to the same recipient are capped at 3 an hour (`NotificationHooks::MAX_PER_RECIPIENT`, counted in MediaWiki's MicroStash like core's rate limits). The rest are dropped, not delayed.
- For erase (the author already got the delete notice), pin, unpin or dismissed reports.

A user gets at most one notification per comment, the first that applies of reply, mention and user page: a user both answered and mentioned gets only the reply, and a user page owner answered or mentioned on their user page gets only that. Echo's own filters still apply (mutes, notification preferences). Several unread replies on one page are bundled into one notification ("3 new replies to your comments on …"), and so are several unread mentions on one page ("3 new mentions of you in comments on …").

Event `extra` (ints, strings and lists of ints only, because Echo on MediaWiki 1.46 stores it as JSON):

| Key | In | Value |
|---|---|---|
| `comment-id` | all four | GlowThread comment ID |
| `reply-to-id` | reply | ID of the comment answered |
| `action` | moderation | `delete`, `restore` or `approve` |
| `reason` | moderation | Moderator's reason, at most 500 characters |
| `recipients` | all four | User IDs, read by Echo's `locateFromEventExtra` |

The moderation notice has no agent: it does not name the moderator (Special:Log/glowthread does).

Rendering checks the comment again each time the notification is shown:

- **Body**: while readers can see the comment (it and the comments it is shown under are `visible`, comments are not turned off on its page, and the recipient can read the page), a plain-text excerpt of up to 150 characters, with mentions as `@Name` (`@…` for a missing or hidden user). Otherwise the neutral text `notification-body-glowthread-unavailable` ("This comment is no longer available."). The delete notice shows the reason instead, or nothing.
- **Primary link**: `Special:GlowThreadLink/<id>` (which redirects to the page with the fragment `#gt-<id>`) while the comment is visible, otherwise the page.
- **Erased comments**: the reply, mention and user page notifications the comment caused (and FlowThread's notifications about an imported comment) are hidden with Echo's own moderation (`event_deleted`), the way Echo hides notifications about deleted pages. They are no longer listed, and Echo's API no longer returns the author as their `agent`. The unread counts are recomputed. Undeleting the page keeps them hidden: when an undeletion creates the page, Echo shows all notifications of its restored page ID again, and GlowThread hides those about erased comments again right after, also for a comment that an earlier undeletion moved to another page ID. Notifications about deleted or held comments show again with the page and render the neutral text above. A notification that still renders (an erased comment's moderation notice) does not name the author either.
- **Comments their author deleted**: when the author deletes their own comment (also a moderator deleting their own), its reply, mention and user page notifications are hidden the same way, so a mention does not outlive the comment. They stay hidden after an undeletion of the page while the comment is still deleted. A moderator who restores the comment does not bring them back. A moderator's deletion of someone else's comment leaves them, and they render the neutral text.

### Legacy FlowThread notifications

On wikis that used FlowThread, `$wgGlowThreadLegacyCompat` keeps FlowThread's stored notifications rendering. The six types are registered again with GlowThread's presentation model (`LegacyPresentationModel`), in their old category `flowthread`, which keeps users' old opt-outs and is hidden from the preferences. No new events of these types are created. With the setting off (the default), these types are not registered.

| Type | Section, icon | Header |
|---|---|---|
| `flowthread_reply` | `message`, `chat` | "… replied to your comment on …" |
| `flowthread_userpage` | `message`, `edit-user-talk` | "… commented on your user page" |
| `flowthread_mention` | `message`, `mention` | "… mentioned you in a comment on …" |
| `flowthread_delete` | `alert`, `revert` | "Your comment on … was deleted" |
| `flowthread_recover` | `alert`, `reviewed` | "Your comment on … was restored" |
| `flowthread_spam` | `alert`, `revert` | "Your comment on … was marked as spam automatically" |

FlowThread stored the comment ID in `extra['postid']` as 11 raw bytes (PHP `serialize`, so Echo on 1.46 still reads those rows). A 22-digit hex value is accepted too, in any case. The model finds the imported comment by `gtp_legacy_id` and then renders like the new types (excerpt and `Special:GlowThreadLink/<id>` link while visible). When no imported comment has that ID, it shows the neutral text and links to the page. A notification whose page was deleted does not render (Echo then hides it, as before).

## CheckUser records

Sent only when CheckUser is loaded (`includes/CheckUser/CheckUserHooks.php`). Every new comment, visible or held, becomes a CheckUser private event in `cu_private_event`: a `glowthread/post` log entry with `4::comment` (ID) and `6::seq`, performed by the author, targeting the page. It is built in `GlowThreadPostSaved` (the author's request), turned into a RecentChange object with `rc_logid` 0, and passed to the `CheckUserInsert` service, which records IP, X-Forwarded-For and user agent from the request. Nothing is written to `logging` or `recentchanges`, so comment posts never appear in Special:Log or Special:RecentChanges. Every report is recorded the same way, as a `glowthread/report` entry performed by the reporter (`4::comment`, `6::seq`, no reason or note), built in `GlowThreadReportFiled` (the reporter's request). Likes are not recorded.

Special:CheckUser "Get actions" for a user lists them as "Alice posted comment #4 on Page" (message `glowthread-logentry-post`, linking to `Special:GlowThreadLink/<id>`) and reports as "Bob reported comment #4 on Page" (`glowthread-logentry-report`), with IP, XFF and user agent. Moderation entries (`glowthread/*`) are published to RecentChanges, so CheckUser records them in `cu_log_event` itself. While the `publicLog` setting is off, `ModerationLogger` passes their RecentChange object to `CheckUserInsert` instead, with the same result in `cu_log_event`. Automatic holds (`glowthread/autohold`) are recorded as done by the software, with IP 127.0.0.1 and no XFF or user agent, not with the IP of the reporter whose report caused them. Their RecentChanges row, when they have one, carries 127.0.0.1 as `rc_ip` too. The restricted `glowthread-private` log is never published and stays out of RecentChanges and CheckUser.

## Compatibility API (`action=flowthread`)

A read-only stand-in for FlowThread's API, for wikis that move from FlowThread to GlowThread (`includes/Compat/`). Old clients that still call FlowThread's API (cached pages and scripts, gadgets, apps that embed the wiki) keep showing comments while they are updated. The module is added only when `$wgGlowThreadLegacyCompat` is true (default `false`) and FlowThread itself is not loaded. Old clients do not send tokens, and nothing here writes. See [migrating from FlowThread](migrating-from-flowthread.md).

| Parameter | |
|---|---|
| `type` | `list` or `listall`. FlowThread's write types `post`, `like`, `dislike`, `report`, `delete`, `recover`, `markchecked`, `erase` answer with the error `glowthread-legacy-readonly` ("FlowThread has been replaced by GlowThread. This interface is read-only …"), on GET and POST |
| `pageid` | `list`: required. `listall`: optional filter |
| `offset` | Comments to skip, 0 to 10000 (default 0) |
| `limit` | 1 to 100, or `max` (= 100). Default 10. `list` counts top-level comments |
| `filter`, `title`, `user`, `keyword`, `dir` | `listall` only. `filter`: `all`, `deleted` (deleted and erased), `spam` (held) or `reported`, default visible only. `dir`: `older` (default, newest first) or `newer`. A `keyword` that is exactly a user's name also finds the comments that mention that user, as `q` does in the queue |

`type=list` needs read access to the page (a page the reader cannot read is answered `nosuchpageid`, like a missing one). Responses are always private, also with `smaxage`: these URLs cannot be purged.

```json
{ "flowthread": {
  "posts": [
    { "id": "0605b2a3c4d5e6f7a8b9c0", "userid": 3, "username": "Alice",
      "text": "Hello <a href=\"https://example.com/\" class=\"external\" rel=\"nofollow ugc noopener\" target=\"_blank\">https://example.com/</a><br>\nline 2",
      "timestamp": 1696000000, "parentid": "", "like": 2, "myatt": 1 },
    { "id": "58", "userid": 0, "username": "203.0.113.7", "text": "…", "timestamp": 1791000000,
      "parentid": "0605b2a3c4d5e6f7a8b9c0", "like": 0, "myatt": 0 }
  ],
  "popular": [ "…same shape…" ],
  "count": 33
} }
```

- `posts`: the page's visible top-level comments, newest first, `offset` and `limit` applied to them, followed by the visible replies shown under them (depth 1 before depth 2, so each parent comes before its children, as FlowThread's client needs): at most 300 replies and 256 KiB of reply text per request, oldest first within each depth. Replies under a comment readers cannot see are left out. Held comments are not listed, not even to their author.
- `id`: the FlowThread ID (22 hex digits) for imported comments, the GlowThread ID as a decimal string for new ones. Special:GlowThreadLink accepts both. `parentid` uses the same form, `''` for top-level comments.
- `userid`: 0 for legacy anonymous (IP) authors and hidden authors. Hidden authors (hideuser blocks, erased comments) get the text of `rev-deleted-user` as `username`.
- `text`: HTML built from the [segments](#segment), because FlowThread's client inserts it with `.html()`. Text is escaped and newlines become `<br>`, links are `<a>` to `http:`/`https:` URLs only, and mentions of existing users link to their user page. Nothing else is markup.
- `timestamp`: Unix seconds. `like`: like count. `myatt`: 1 if the reader liked it, 2 if they reported it, else 0.
- `popular`: up to `$wgGlowThreadPopularCount` visible comments with at least `$wgGlowThreadPopularMinLikes` likes whose ancestors are visible, most likes first.
- `count`: visible top-level comments on the page. On a page whose comments are turned off, or where comments do not appear (not in `$wgGlowThreadNamespaces`, a talk page or the main page), everything is empty.

`type=listall` needs `glowthread-moderate` (`permissiondenied` otherwise) and returns `{ "more": bool, "posts": [...] }`, a flat list across pages. Each post also has `pageid`, `title` (null for deleted pages), `report` (open reports) and `status` (FlowThread's codes: 0 visible, 1 deleted or erased, 2 held). As in the moderation queue, the `user` filter leaves out erased comments for readers without `glowthread-erase`, and finds nothing for a user hidden by a hideuser block unless the reader has `hideuser`. It leaves out the same pages as the moderation queue, and `offset` counts only the comments it returns.
