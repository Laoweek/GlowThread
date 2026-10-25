English | [简体中文](zh-CN/moderation.md)

# Moderation guide

This guide is for moderators: users with the right `glowthread-moderate` (sysops by default). It explains where to work, what each action does, and how to clear the queue quickly. Interface labels are quoted as they appear in the English interface.

Administrators: rights, limits and settings are in [Configuration](configuration.md).

## Where to work

| Place | Use it for |
|---|---|
| The comment section of a page | Acting on comments while you read them, and switching a page's comments between open, locked and closed |
| **Special:GlowThread** ("Comment moderation") | The queue of reported, held and deleted comments for the whole wiki, bulk actions, keyboard triage, the record of an author or a page, settings and the word filter |
| **Special:Log/glowthread** ("Comment log") | The history of every moderation action |

Every action is logged in Special:Log/glowthread with your name. Most actions can be undone right after you take them.

## Comment states

| State | Label | Who sees it |
|---|---|---|
| Visible | (no label) | Everyone |
| Held | "Awaiting review" | The author ("Only you can see this · awaiting review") and moderators |
| Deleted | "Hidden" | Moderators only. Can be restored |
| Erased | "Erased" | Moderators see that a comment was there. The text is gone for good. The author is shown only to users with `glowthread-erase` |

When a comment is deleted, held or erased, the replies shown under it disappear for readers too. They come back when the comment is restored or approved.

### Why a comment is held

A comment is held, never rejected, when one of these applies. Moderators see the reason after "Held for review:".

| Reason | Label |
|---|---|
| A line of the word filter matched | "word filter" |
| A rule of the word filter failed on this text | "word filter error" |
| A link is on the SpamBlacklist | "blacklisted link" |
| The account is new (the new-account gate) | "new account" |
| Several established users reported it | "several reports" |
| It replies to the author's own comment that is still held | "replies to a comment that is waiting for review" |

An author can have at most 3 comments waiting for review by default. Further comments that would be held are refused until a moderator has reviewed them.

## In the comment section

As a moderator you see more in each comment section:

- Held and deleted comments, with their label, the reason they were held ("Held for review:"), and the reasons they were reported, with "reported by" and the reporters' names.
- A moderation bar above the comments.

### The moderation bar

| Control | What it does |
|---|---|
| "Awaiting review" with a count | Shows only the held comments of this page. Click again to show all |
| "Reported" with a count | Shows only the comments of this page with open reports |
| "Page:" menu ("Comments on this page") | Switches the page between "Open", "Locked" and "Closed", after a confirmation. See [Page states](#page-states) |
| "Select" | Turns on selection: a checkbox on each comment, and "Bulk actions" for the selected comments. "Done" turns it off |

### The comment menu

Open a comment's "More actions" menu. Under "Moderation" it offers what applies to that comment:

| Item | Applies to | What it does |
|---|---|---|
| "Pin" / "Unpin" | Visible top-level comments | Shows the comment first, above the other comments, marked "Pinned" |
| "Approve" | Held comments | Publishes the comment and closes its open reports. Later reports no longer hold it automatically. The author is notified |
| "Dismiss reports" | Comments with open reports | Keeps the comment as it is and closes its reports. Later reports no longer hold it automatically |
| "Hide" | Visible and held comments | Deletes the comment: hides it from readers. Its open reports are closed as upheld. The author is notified |
| "Restore" | Deleted comments | Returns the comment to the state it had before it was deleted. The author is notified when the comment becomes visible again |
| "View user record" | Every comment whose author is not hidden | Opens Special:GlowThread with everything by that author and their record |

The restore item changes its label to say what will happen:

- "Restore": the comment becomes visible again.
- "Restore for review": the comment was deleted while it was held, so it goes back to the held queue. Restoring never publishes text that nobody has approved.
- "Restore and publish": the comment was once held and the deletion did not record its earlier state (for example a deletion from before the switch to GlowThread). Restoring makes it visible without review.

Erasing is only offered on Special:GlowThread.

### Undo

After an action, a message at the bottom of the screen confirms it, with an "Undo" button. Undo reverses exactly what the action changed: the status, who set it and when, and the reports it closed. It fails with a message if the comment changed in the meantime. Approve, hide, restore, pin, unpin and dismiss can be undone. Erase cannot.

Undoing sends no notification. An author who was told about a deletion is not told when it is undone.

Press `F8` to move to the newest message, to reach its "Undo" button from the keyboard.

## The moderation hub: Special:GlowThread

The hub has a side menu: "Queue", "Settings", "Comment log", "Word filter page" and "Keyboard shortcuts".

### The queue

| Tab | Shows | Default order |
|---|---|---|
| "Reported" | Visible or held comments with open reports | "Most reported" |
| "Held" | Comments waiting for review, with the reason | "Newest" |
| "Deleted" | Deleted and erased comments | "Newest" |
| "All" | Everything | "Newest" |

The queue and the records show only comments on pages you can read. Comments of a page deleted with suppression are shown only to users who can see suppressed revisions. The tab counts include all comments.

Narrow the list:

- "Search comment text" finds text inside comments. It does not search authors, page titles or report notes.
- "Filters" opens the "Page" and "Author" fields. The author can be a user name or an IP address.
- "Order" switches between "Most reported", "Newest" and "Oldest".
- A reporter who filed many of the reports in the list gets a badge ("N reports in this list"). Click it to show only that person's reports ("Reported by …"). This helps when one person reports many comments in a short time.

When new items arrive while you work, a "N new · Refresh" button appears. The list does not jump on its own.

Each item shows the comment, its author with the account age ("Joined 3 days ago") and any block, the page, the comment it replies to, and its reports with reasons and notes. For a comment held by the word filter, the item shows the lines that matched ("Matched line 3 (…)"). When the hold did not record them, users with `glowthread-configure` can click "Which rule?" to test the current rules against the comment. "View in context" opens the comment on its page. Reports imported from FlowThread show their reason but not who filed them.

Item buttons:

| Button | Key | Applies to | What it does |
|---|---|---|---|
| "Approve" | `a` | Held comments | Publishes the comment and closes its reports |
| "Approve" | `a` | Visible comments with reports | Keeps the comment and closes its reports |
| "Delete" | `d` | Visible and held comments | Hides the comment. Its reports are closed as upheld |
| "Restore" | `r` | Deleted comments | Returns it to its state before the deletion (see the labels [above](#the-comment-menu)) |
| "Dismiss reports" | | Held comments with reports | Closes the reports and leaves the comment held |
| "Erase…" | | Deleted comments | Erases the comment for good, see [Erasing](#erasing) |
| "Pin" / "Unpin" | | Visible top-level comments | As in the comment section |

### Bulk actions

Tick the checkbox of several items, or use "Select all loaded". A bar appears with "Approve", "Delete", "Restore" and "Dismiss reports". When an action applies to only some of the selected comments, the button shows how many. "Clear selection" (or `Esc`) empties the selection.

One message confirms the whole batch, with one "Undo" for all of it. If another moderator changed one of the comments first, the message says so and leaves that comment alone.

### Keyboard triage

Press `?` in the queue to see the shortcuts.

| Key | Action |
|---|---|
| `j` | Next comment |
| `k` | Previous comment |
| `x` | Select or unselect the current comment |
| `a` | Approve: publish a held comment, or keep a reported one and close its reports |
| `d` | Delete |
| `r` | Restore a deleted comment |
| `/` | Search the comment text |
| `?` | Show the shortcuts |
| `Esc` | Clear the selection |
| `F8` | Jump to the newest message, to reach "Undo" |

- `a`, `d` and `r` act on the selected comments. With nothing selected, they act on the current comment. When keyboard focus is inside a comment that is not selected, they act on that comment alone.
- In the "Reported", "Held" and "Deleted" tabs, a comment leaves the list after an action and the next one becomes current, so you can work through the list with `j`, `a` and `d`.
- Letter keys do nothing while you type in a field, while a dialog or menu is open, or with Ctrl, Alt or Cmd held. A held key repeats only for `j` and `k`, never for actions.
- `x`, `a`, `d` and `r` do nothing while you work in the record panel.

A quick pass through reports: open "Reported", press `a` to keep a comment that is fine, `d` to delete one that breaks the rules, `j` to skip one you want to look at later.

### The record panel

Select a comment and the "Record" panel next to the queue shows the record of its author. Switch between "Author" and "Page" at the top of the panel. The addresses `Special:GlowThread/user/<name>` and `Special:GlowThread/page/<title>` open everything by one author or on one page, with the record.

The author record shows:

- the account: when it joined, its edit count, and any block, with links to "Block this user", "Change block" and the "Block log",
- "Comments": how many are visible, held, deleted and erased,
- "Reports filed": how many reports this person filed, how many were upheld, dismissed or are still open, and the share of decided reports that were upheld. "Review their N open reports" lists them,
- "Actions on their comments": recent moderation,
- "Recent comments" and "All their comments".

The page record shows how many comments were posted on the page so far, the page's comment state with the option to change it (with an optional reason), its comments by status, "Recent moderation", "All comments on this page" and "Comment log for this page". On a wiki that came from FlowThread and has `$wgGlowThreadLegacyCompat` on, "Recent moderation" can also list FlowThread's own entries for the page, for example comments turned on or off ("Opened comments", "Closed comments"), imports ("Imported comments") and deletions. They show no comment number.

Use the record before you act on a borderline comment: a new account with only deleted comments is a different case from a long-time member's first report.

## Erasing

Deleting hides a comment and can be undone. Erasing is final. Use it for content that must not stay in the database, for example personal data or illegal content.

- Only deleted comments can be erased, by users with `glowthread-erase` and `glowthread-moderate` (sysops by default).
- Click "Erase…" on a deleted comment in the queue. The dialog asks "Erase this comment for good?" and takes an optional reason. "Erase permanently" confirms.
- The text is blanked. The author is hidden in the comment from everyone without `glowthread-erase`, and in their earlier log entries about it from everyone without `deletedhistory`. The notifications the comment caused (replies, mentions) are hidden. The row stays, so replies and log entries still point somewhere.
- The author is recorded in Special:Log/glowthread-private ("Comment erasure log"), which only users with `glowthread-erase` can read.
- Each moderator can erase at most 30 comments an hour. Sysops cannot bypass this limit.
- There is no undo, and nothing is ever erased automatically.

## Page states

| State | Label | Readers | Moderators |
|---|---|---|---|
| Open | "Open" | Everyone can post and reply | Everything |
| Locked | "Locked" | Readers can read and like, but not post or reply | Can still moderate |
| Closed | "Closed" | Readers do not see the comment section. Existing comments are kept | See the comments, with a note that readers do not |

Change the state with the "Page:" menu in the comment section, or in the page record on Special:GlowThread. Each change asks for confirmation and is logged.

Lock a page when a discussion gets out of hand and you want to keep what was said. Close a page when comments do not belong there at all.

Page protection does not cover comments. Users with `glowthread-post` can still comment on a protected page. To stop new comments on a page, lock it.

## Settings

**Special:GlowThread/settings** shows the wiki-wide settings. Moderators can view them. Changing them needs `glowthread-configure` (sysops by default).

| Section | Settings |
|---|---|
| "New accounts" | "Account age needed" (hours) and "Visible comments that skip the gate". A summary sentence says what the gate does with the current values. 0 hours turns the gate off |
| "Reports" | "Hold after reports from" (people) and "Report reasons offered" |
| "Limits" | "Maximum length" (characters), "Mentions per comment" and "Repeat interval" (seconds before the same user may post the same text on the same page again) |
| "Features" | "Moderation log visible to everyone": whether everyone can read the comment log, see [The comment log](#the-comment-log) |
| "Rate limits" | Shown for reference only. They are set in `LocalSettings.php` |

Each field shows its allowed range and default. A setting that the administrators fixed in `LocalSettings.php` is greyed out, shows "Locked in LocalSettings" and cannot be changed here.

To save: change the fields, click "Review and save", check the list of changes, give a reason (required, shown in the log) and click "Save changes". "Discard" drops unsaved changes. Every change is logged with the old and new value.

How "Hold after reports from" counts reporters: only registered users who are not the author, are not blocked from reporting there (sitewide, or a partial block on the page or its namespace), would pass the new-account gate themselves, have an account at least 7 days old and have at least one visible comment. Users with `glowthread-trusted` count even with a new account. Temporary accounts never count, however old, but their reports still reach the queue. Reports imported from FlowThread do not count, unless the same user reports the comment again. Details are in [Configuration](configuration.md#automatic-holds-by-reports).

Reports never hold a comment that a moderator has already kept: approved, restored, or with its reports dismissed. Later reports still appear in the "Reported" tab. If you undo that action, reports can hold the comment again. Each automatic hold is logged in Special:Log/glowthread, with "GlowThread" as the performer. That name is reserved, so nobody can register it. If an account already had it before GlowThread was installed, it is not taken over and the holds name "Maintenance script" instead. Like any account with a reserved name, it can no longer log in: rename it to give its owner access again.

## The word filter

The "Word filter" part of the settings page edits the rules on **MediaWiki:GlowThread-filter**. Moderators see the rules here, but the page itself is public like every interface page: anyone can read the rules, spammers too. Testing them needs `glowthread-configure`, and editing them needs `editinterface`.

- One rule per line. A plain line ignores case, full-width forms, invisible characters, and accents unless the line has them. In languages that put spaces between words, it matches whole words: the rule `ass` matches "Ass!", "a s s" and "a.s.s" but not "class". A rule of several words also matches with other spaces or symbols between its words, or none. These whole-word rules also match right next to Chinese, Japanese or Korean text, which often has no space before a Latin word. In Chinese, Japanese, Korean, Thai, Lao, Khmer and Myanmar, a plain line matches anywhere, even with spaces, symbols or ASCII punctuation between its characters, but not across a line break, full-width punctuation such as 。 and ，, or brackets and quotes such as 「」 and “”. `/pattern/flags` is a regular expression (flags `i`, `m`, `s`, `x`). Lines starting with `#` are comments.
- A match holds the comment for review. It is never rejected.
- Lines that are not valid are marked with their line number and ignored.
- At most 2000 rules are used. The next line is marked "too-many-rules", and it and the lines after it are ignored. A regular expression that would run too long stops early. After a quarter of a second on one comment, the regular expressions and whole-word lines left are not run. Either way the comment is held, as when a rule fails ("time-limit").
- To save, give an edit summary (required) and click "Save rules". The change is an ordinary edit of the page, with history. "View the change" shows the diff.

**Test before you save.** Paste a comment into "Test a comment". The result says "Would be held for review: matches …" with the matching lines, or "Would not be held.". The test runs the same check as posting, on the rules in the editor, saved or not, so you can try a new rule against real examples first.

Keep rules specific, and test them against harmless comments too. A plain rule matches more than the exact words:

- A rule of several words also matches across punctuation, so `cheap coins` matches "Is it cheap? Coins are rare".
- A word also matches its letters spaced out, as in initials, so `ass` matches "A.S.S.".
- A plain rule in Chinese, Japanese and the other scripts without spaces matches inside longer words, because those scripts have no spaces to mark word boundaries.
- A rule without accents also matches the word with accents, so `ano` matches "año". Write the accents to match only that spelling: `lồn` does not match "lớn".
- A word split once, such as "sp am" or "s-pam", does not match `spam`. Use a regular expression such as `/\bs\W*p\W*a\W*m\b/i` to catch other splits.

Use a regular expression when you need more control.

The full syntax is in [Configuration](configuration.md#the-word-filter).

## The comment log

Special:Log/glowthread ("Comment log") lists every moderation action, page state change, settings change and author self-deletion, with the moderator, the comment number and author, and the reason. Filter it by type with "Type of comment action". The log is hidden from "Main public logs" by default, so open it by name or from the hub's "Comment log" link. While "Moderation log visible to everyone" is off, only moderators and administrators can read it, and the actions taken meanwhile never show in Special:RecentChanges or watchlists, even after the setting is turned on again.

New comments and reports are not logged. With the CheckUser extension, each one is a CheckUser record instead.

Who wrote an erased comment is in Special:Log/glowthread-private ("Comment erasure log"), see [Erasing](#erasing). On a wiki that came from FlowThread, FlowThread's old entries are in Special:Log/comments ("FlowThread comment log") while `$wgGlowThreadLegacyCompat` is on.

## Blocks

- A moderator under any block, partial blocks included, cannot moderate. The queue stays readable.
- A blocked reader cannot post, like or report. A partial block applies only on the pages or namespaces it covers.
- There is no "block from commenting only". To stop someone from commenting, block them from editing, or use a partial block on the pages or namespaces concerned. The author record links to Special:Block.
