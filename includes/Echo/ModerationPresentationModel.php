<?php

namespace MediaWiki\Extension\GlowThread\Echo;

use MediaWiki\Extension\GlowThread\Model\PostRecord;

/**
 * glowthread-moderation: a moderator deleted, restored or approved the viewer's comment.
 *
 * The event has no agent, so the moderator is not named here (Special:Log/glowthread names them).
 * Header parameters: $1 page, $2 viewing user for GENDER.
 */
class ModerationPresentationModel extends GlowThreadPresentationModel {

	/** @inheritDoc */
	protected function loadPost(): ?PostRecord {
		return self::getPostById( (int)$this->event->getExtraParam( 'comment-id' ) );
	}

	private function getAction(): string {
		return (string)$this->event->getExtraParam( 'action', '' );
	}

	/** @inheritDoc */
	public function canRender() {
		return parent::canRender() && in_array( $this->getAction(), NotificationHooks::MODERATION_ACTIONS, true );
	}

	/** @inheritDoc */
	public function getIconType() {
		return $this->getAction() === 'delete' ? 'revert' : 'reviewed';
	}

	/** @inheritDoc */
	public function getHeaderMessage() {
		// Messages: notification-header-glowthread-moderation-delete,
		// notification-header-glowthread-moderation-restore,
		// notification-header-glowthread-moderation-approve
		$msg = $this->msg( 'notification-header-glowthread-moderation-' . $this->getAction() );
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
		if ( $this->getAction() !== 'delete' ) {
			return $this->getExcerptMessage();
		}
		$reason = trim( (string)$this->event->getExtraParam( 'reason', '' ) );
		return $reason === ''
			? false
			: $this->msg( 'notification-body-glowthread-moderation-reason' )->plaintextParams( $reason );
	}

	/** @inheritDoc */
	public function getPrimaryLink() {
		return $this->getCommentLink();
	}

	/** @inheritDoc */
	public function getSecondaryLinks() {
		return [];
	}
}
