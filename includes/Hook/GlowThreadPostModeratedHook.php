<?php

namespace MediaWiki\Extension\GlowThread\Hook;

use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\User\UserIdentity;

/**
 * @stable to implement
 */
interface GlowThreadPostModeratedHook {
	/**
	 * A moderation action changed a comment. Runs after commit, once per comment.
	 *
	 * Privacy: after erase, $author is the erased comment's author, whom GlowThread hides from
	 * everyone without glowthread-erase. Do not publish it, and remove what you copied of the
	 * comment (its text, its author).
	 *
	 * @param PostRecord $post The comment after the action
	 * @param ProperPageIdentity|null $page Null if the page no longer exists
	 * @param string $action approve, delete, restore, erase, pin, unpin, dismiss, selfdelete
	 *   (the author deleted their own comment), or undo (an earlier entry was undone exactly,
	 *   $logId is the glowthread/undo entry). After restore the comment is visible, or held when
	 *   it was deleted while held: check $post's status.
	 * @param UserIdentity $performer
	 * @param string $reason May be empty
	 * @param UserIdentity $author Author of the comment
	 * @param int $logId ID of the glowthread log entry, 0 if none was written
	 * @return void This hook must not abort, it must return no value
	 */
	public function onGlowThreadPostModerated(
		PostRecord $post,
		?ProperPageIdentity $page,
		string $action,
		UserIdentity $performer,
		string $reason,
		UserIdentity $author,
		int $logId
	): void;
}
