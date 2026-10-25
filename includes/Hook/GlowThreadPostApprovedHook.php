<?php

namespace MediaWiki\Extension\GlowThread\Hook;

use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\User\UserIdentity;

/**
 * @stable to implement
 */
interface GlowThreadPostApprovedHook {
	/**
	 * An approval let readers see a comment that was held. Runs after commit. Send the reply and
	 * mention notifications that GlowThreadPostSaved held back. GlowThreadPostModerated also
	 * runs for the same action.
	 *
	 * Runs for each comment an approval lets readers see: the approved comment, unless a comment
	 * it is shown under is held, deleted or erased, and the replies under it that were held and
	 * approved before it (they waited for it). Restoring a comment does not run it for replies
	 * approved under it meanwhile. The page state is not checked: comments may be turned off on
	 * the page. It can run again for a comment readers saw before (an approval undone and
	 * repeated, a comment held by reports approved), so send each notification once.
	 *
	 * Privacy: the comment can be deleted or erased later. A handler that copies its text or
	 * author elsewhere should remove the copy when GlowThreadPostModerated reports that.
	 *
	 * @param PostRecord $post The comment readers can now see (status visible)
	 * @param ProperPageIdentity $page
	 * @param UserIdentity $author
	 * @param UserIdentity[] $mentionedUsers As in GlowThreadPostSaved, recomputed now
	 * @param PostRecord|null $replyTo
	 * @param UserIdentity|null $replyToAuthor
	 * @param UserIdentity $moderator Who approved it, or the comment it is shown under
	 * @return void This hook must not abort, it must return no value
	 */
	public function onGlowThreadPostApproved(
		PostRecord $post,
		ProperPageIdentity $page,
		UserIdentity $author,
		array $mentionedUsers,
		?PostRecord $replyTo,
		?UserIdentity $replyToAuthor,
		UserIdentity $moderator
	): void;
}
