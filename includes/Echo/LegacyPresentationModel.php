<?php

namespace MediaWiki\Extension\GlowThread\Echo;

use InvalidArgumentException;
use MediaWiki\Extension\GlowThread\Import\LegacyId;
use MediaWiki\Extension\GlowThread\Model\PostRecord;

/**
 * Notifications FlowThread stored before the switch (flowthread_reply, flowthread_userpage,
 * flowthread_mention, flowthread_delete, flowthread_recover, flowthread_spam).
 *
 * FlowThread put the comment's 11-byte binary ID in extra['postid'] (PHP serialize). A 22-digit
 * hex form is accepted too. The comment is found among imported comments by its FlowThread ID.
 * When it was not imported, the notification links to the page and shows a neutral body.
 *
 * Header parameters, as FlowThread had them: $1 agent, $2 agent for GENDER, $3 page,
 * $4 viewing user for GENDER. The agent of the alert types is the comment author.
 */
class LegacyPresentationModel extends GlowThreadPresentationModel {

	private const ICONS = [
		'flowthread_reply' => 'chat',
		'flowthread_userpage' => 'edit-user-talk',
		'flowthread_mention' => 'mention',
		'flowthread_delete' => 'revert',
		'flowthread_recover' => 'reviewed',
		'flowthread_spam' => 'revert',
	];

	/**
	 * FlowThread's comment ID from the event's extra data, as 22 lowercase hex digits.
	 *
	 * @param mixed $postId 11 raw bytes or 22 hex digits
	 * @return string|null Null when it is neither
	 */
	public static function toLegacyHex( $postId ): ?string {
		if ( !is_string( $postId ) ) {
			return null;
		}
		try {
			return LegacyId::fromString( $postId )->getHex();
		} catch ( InvalidArgumentException $e ) {
			return null;
		}
	}

	/** @inheritDoc */
	protected function loadPost(): ?PostRecord {
		$hex = self::toLegacyHex( $this->event->getExtraParam( 'postid' ) );
		return $hex === null ? null : self::getPostStore()->getByLegacyId( $hex );
	}

	private function isAlert(): bool {
		return ( EchoSetupHooks::LEGACY_TYPES[$this->type]['section'] ?? '' ) === 'alert';
	}

	/** @inheritDoc */
	public function getIconType() {
		return self::ICONS[$this->type] ?? 'chat';
	}

	/** @inheritDoc */
	public function getHeaderMessage() {
		// Messages: notification-header-flowthread_reply, notification-header-flowthread_userpage,
		// notification-header-flowthread_mention, notification-header-flowthread_delete,
		// notification-header-flowthread_recover, notification-header-flowthread_spam
		$msg = $this->getMessageWithCommentAgent( $this->getHeaderMessageKey() );
		$msg->plaintextParams( $this->getTitleText() );
		$msg->params( $this->getViewingUserForGender() );
		return $msg;
	}

	/** @inheritDoc */
	public function getCompactHeaderMessage() {
		return $this->getHeaderMessage();
	}

	/** @inheritDoc */
	public function getSubjectMessage() {
		return $this->getHeaderMessage();
	}

	/** @inheritDoc */
	public function getBodyMessage() {
		return $this->getExcerptMessage();
	}

	/** @inheritDoc */
	public function getPrimaryLink() {
		return $this->getCommentLink();
	}

	/** @inheritDoc */
	public function getSecondaryLinks() {
		return $this->isAlert() ? [] : $this->getAgentLinks();
	}
}
