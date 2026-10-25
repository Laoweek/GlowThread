<?php

namespace MediaWiki\Extension\GlowThread\Hook;

use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\User\UserIdentity;

/**
 * @stable to implement
 */
interface GlowThreadPostSavedHook {
	/**
	 * A new comment or reply was saved. Runs after the write transaction committed, in the
	 * request of the author (so request IP and user agent are the author's).
	 *
	 * The comment can be visible or held. Handlers that notify people must do nothing for held
	 * comments and wait for GlowThreadPostApproved.
	 *
	 * @param PostRecord $post The new comment (check $post->getStatus())
	 * @param ProperPageIdentity $page Page the comment is on
	 * @param UserIdentity $author
	 * @param UserIdentity[] $mentionedUsers Registered, not hidden users mentioned in the text,
	 *   without the author, at most the mention cap
	 * @param PostRecord|null $replyTo The comment the author replied to, null for top-level comments
	 * @param UserIdentity|null $replyToAuthor Author of $replyTo
	 * @return void This hook must not abort, it must return no value
	 */
	public function onGlowThreadPostSaved(
		PostRecord $post,
		ProperPageIdentity $page,
		UserIdentity $author,
		array $mentionedUsers,
		?PostRecord $replyTo,
		?UserIdentity $replyToAuthor
	): void;
}
