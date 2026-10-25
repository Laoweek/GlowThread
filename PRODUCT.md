English | [简体中文](PRODUCT.zh-CN.md)

# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Stack

MediaWiki extension for MediaWiki 1.43 LTS and later, on any database MediaWiki supports. Echo, CheckUser, SpamBlacklist and a CDN (such as Varnish) are optional: GlowThread uses each one when it is there. The UI is a Vue 3.5 app with shadcn-vue components (Reka UI) and scoped Tailwind CSS, built with Vite and bundled with its own Vue runtime, because MediaWiki 1.43 ships Vue 3.4 and current Reka UI needs 3.5. A small always-loaded script loads the app only when the comment section nears the viewport.

## Users

- **Readers**, most of them logged out, many on phones. They come for the article and often look at only the first screen of comments.
- **Commenters**, logged-in members of the wiki's community. Their comments are mostly short: a reaction, a question, a tip, a correction to the article, a thank-you. Some of them never edit an article.
- **Moderators**, usually the wiki's administrators. There are few of them, and they clear reports and held comments between other wiki work.
- **Administrators**, who install and configure the extension, set rights and rate limits, adjust site CSS, upgrade MediaWiki, and, on wikis that used FlowThread, run the migration.

## Product Purpose

GlowThread is a comment section under wiki articles, for any MediaWiki wiki. It is also a replacement for FlowThread, which is no longer maintained, crashes on MediaWiki 1.44 and loses emoji. It is a rewrite: no FlowThread code is reused, and an importer carries FlowThread's comments, likes, reports and page settings over.

Success means: readers see comments without slowing the article, members react and post in seconds on a phone, moderators clear the queue in minutes with every action logged, and administrators can upgrade MediaWiki without the comments breaking.

## Positioning

Comments that live inside the wiki rather than beside it: the same accounts, rights, blocks, logs, Echo notifications and CheckUser coverage as the rest of the site, with plain-text storage that keeps the original words, timestamps and authors. No hosted or third-party comment system offers that with nested replies, likes, reports and wiki identity.

## Operating Context

- Any content language. UI messages ship in English, Simplified Chinese and Traditional Chinese, and translate through MediaWiki's message files. Comment text in any script, right-to-left included, shows in its own direction.
- Skins: legacy Vector, Vector 2022, Citizen and Minerva (with MobileFrontend) are supported and tested. Vector 2022, Citizen and Minerva have night modes, and GlowThread follows them.
- Administrators place the section and change its colours, type and radii with site CSS: the `#glowthread` container and the public `--glowthread-*` custom properties.
- No outside requests: no CDNs, web fonts or avatar services. The section works on wikis behind strict networks or privacy rules.
- Small wikis on one modest server and busy wikis behind a CDN. The anonymous first page of a list is cacheable and purged when it changes, and the app loads only when a reader nears the comments.
- Posting needs an account. Anonymous posting is not built.

## Capabilities and Constraints

- Plain-text source, rendered on read: escape everything, autolink URLs with `rel="nofollow ugc"`, `@Name` mentions, emoji. No wikitext.
- Lazy-loaded list, hot and new sort, a popular block with time decay, "load more" with cursor paging, two visible reply levels with "show N more replies" in place, pinned comments, likes, reports with reasons, delete own comment, @mention autocomplete and an emoji picker.
- Writes need POST and a CSRF token. Rights and blocks go through MediaWiki, partial blocks apply only to their pages, and blocked users cannot moderate. Rate limits, a length cap, a mentions cap, a word filter that holds comments for review, SpamBlacklist on URLs, a new-account gate, and CheckUser events.
- Moderation queue with tabs for reported, held and deleted comments, filters, bulk actions, exact undo, and every action in Special:Log under the `glowthread` log type (erasures also in the restricted `glowthread-private` log). Per-page settings (comments off, locked). Deleting a page hides its comments and undeleting restores them.
- Admin surfaces: a moderation queue, inline moderation inside the comment section, a user and page history panel, and a settings screen for the word filter, new-account gate, report reasons and per-page switches. Rate limits are shown there and set in LocalSettings.php.
- Echo notifications for replies, mentions, comments on a user's own user page and moderation outcomes.
- For wikis that used FlowThread: an importer, old permalinks and `#flowthread` links that keep working, and a compatibility setting (off by default, kept on by a migrated wiki) that answers FlowThread's API read-only and keeps FlowThread's stored notifications and old log entries rendering. Anonymous comments imported from FlowThread keep showing their IP address as the author name.
- Not now: anonymous posting, editing comments, section-anchored comments, real-time updates, a wikitext or rich editor, unlimited nesting, AI moderation, image uploads.

## Brand Commitments

- One accent, a pink (`#dc6e7e`), in two shades. Site CSS can change it through the custom properties.
- GlowThread looks the same on every skin rather than imitating each skin, and it must work in legacy Vector, Vector 2022, Citizen and Minerva.
- Standing visual preference: the category standard for a comment section, played straight and executed precisely rather than a novel visual world. The craft bar is the comment layout readers know from large video and community sites, and Linear and Vercel (Geist): strict neutral palette, one accent, a tight type scale, exact alignment and spacing, a calm dark mode. shadcn-vue is the component foundation.
- It follows the skin's light and dark mode and exposes custom properties so site CSS can adjust it.
- The extension name is GlowThread. The comment section's container is `#glowthread`.

## Evidence on Hand

- No usage data ships with the project. The dev wiki runs on made-up content (a community cookbook) with made-up accounts and comments, plus one sandbox page with CJK, right-to-left and emoji text for i18n testing, and one page of reviewed real comments from the CC0 Civil Comments archive.
- Mockups and screenshots use made-up names and comments. Do not invent usage figures, testimonials or endorsements.

## Product Principles

1. The article comes first: comments load cheaply, late and without moving the page.
2. Reacting is cheaper than writing: likes and emoji take one tap.
3. A moderator's minute is the scarcest resource: every queue item can be judged in context and acted on in one step, and every action is logged.
4. Safe by default: escape everything, require tokens, respect blocks and rate limits, hold rather than reject.
5. Keep the history: import everything, never erase anything automatically.

## Accessibility & Inclusion

Every action is a real control that works by touch and keyboard, with nothing hidden behind hover. Target WCAG 2.2 AA in light and dark mode on every supported skin, with CJK text at readable sizes on phones.
