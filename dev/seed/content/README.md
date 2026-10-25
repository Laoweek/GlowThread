English | [简体中文](README.zh-CN.md)

# Seed content for the dev wiki

This folder holds the pages and comments that seed GlowThread's dev wiki: a small, made-up community cookbook. Everything except `civil-comments.json` is original writing created for this repository. The recipes, members and conversations are fictional and are not copied from any website, book or real wiki. `civil-comments.json` holds real public comments from a CC0 archive (see the last section). The content is released under the same license as the rest of the repository.

Links point only to `example.com`, `example.org` and `example.net`, plus `spam.example` for the spam test case. Anonymous authors use addresses from the documentation ranges (`198.51.100.0/24`, `203.0.113.0/24` and `2001:db8::/32`).

## Files

| File | What it holds |
|---|---|
| `pages.json` | The page list: title, wikitext file and comment state (`open`, `locked` or `closed`). |
| `pages/*.wiki` | Page text in plain core wikitext: the Main Page, nine recipes, `Kitchen talk`, the Chinese recipe `番茄炒蛋`, `Sandbox` and `Sandbox/i18n`. |
| `comments.json` | About 200 comments and replies on the recipe pages, keyed by page title. They include questions, corrections, tips, likes, reports, a pinned notice, a held comment, a deleted comment and spam. |
| `civil-comments.json` | 414 real public comments for `Kitchen talk` from the CC0 Civil Comments archive, selected and reviewed by hand (see the last section). |
| `fragments.json` | Short sentence pieces (`openers`, `bodies`, `closers`, `replies`) that the seeding code combines into a busy page with hundreds of comments. |
| `i18n.json` | Test comments for `Sandbox/i18n` only: Chinese, Japanese, Korean, Arabic, Hebrew, mixed-direction text, emoji sequences, Vietnamese accents, Devanagari combining marks, a long unbroken string and a comment at the length limit. Apart from the Chinese demo page `番茄炒蛋` and `zh-hans.json`, this is the only non-English text in the seed. |
| `zh-hans.json` | Made-up Chinese comments for `番茄炒蛋`, the Chinese demo page: replies, likes, @mentions, emoji, a link, a pinned notice and reports. The screenshots of the Chinese documentation use this page. |
| `flowthread.json` | Made-up comments for FlowThread legacy mode, used by the import tests. They use a little wikitext (bold, italics, links and line breaks). |

## Pages with a special role

* `Sourdough starter` is the showcase page with the most varied comments.
* `Buttermilk pancakes` is locked. Its comments stay visible but nobody can add new ones.
* `Cold brew coffee` is closed, so its comments are hidden.
* `Pickled red onions` has no comments, to show the empty state.
* `番茄炒蛋` (tomato and egg stir-fry) is a recipe in Chinese with a Chinese discussion, for the screenshots of the Chinese documentation.
* `Sandbox` is for test scripts and starts with no comments.

Comment authors are the dev wiki's fixed test accounts (Admin, Moderator, Alice, Bob, Carol, Dave, Erin, Newbie, Mallory and Trent), its eight Chinese demo accounts that write only on `番茄炒蛋` (老陈, 麦穗, 林小满, 阿芳, 橘子汽水, 豆豆不吃豆, 养生小贴士 and 闲置转让), or anonymous addresses. Ages such as `45d` or `3h` are relative to seeding time, and every text is at most 1000 Unicode code points.

## Civil Comments subset

`civil-comments.json` is the one file here that is not original writing. It holds 414 real public comments for the `Kitchen talk` page, a general food discussion with real-world volume and thread shape. They come from the Civil Comments archive and were reviewed by hand. `dev/seed/dataset/README.md` describes the selection, the license and how to rebuild the file.

Contains comments from the Civil Comments archive (Civil Research Data, figshare, doi:10.6084/m9.figshare.7376747, CC0 1.0) with toxicity labels from Jigsaw/Google (CC0 1.0).
