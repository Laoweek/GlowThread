<?php

namespace MediaWiki\Extension\GlowThread\Echo;

use MediaWiki\Extension\GlowThread\Model\PostRecord;

/**
 * glowthread-reply, glowthread-mention and glowthread-userpage notifications.
 *
 * Header parameters: $1 agent, $2 agent for GENDER, $3 page, $4 viewing user for GENDER.
 * Bundled replies and mentions: $1 count, $2 page, $3 viewing user.
 */
class CommentPresentationModel extends GlowThreadPresentationModel {

	/** @inheritDoc */
	protected function loadPost(): ?PostRecord {
		return self::getPostById(
			(int)$this->event->getExtraParam( 'comment-id' ),
			[ (int)$this->event->getExtraParam( 'reply-to-id' ) ]
		);
	}

	/** @inheritDoc */
	public function getIconType() {
		if ( $this->type === EchoSetupHooks::TYPE_MENTION ) {
			return 'mention';
		}
		// FlowThread's user page notification had the same icon
		return $this->type === EchoSetupHooks::TYPE_USERPAGE ? 'edit-user-talk' : 'chat';
	}

	/** @inheritDoc */
	public function getHeaderMessage() {
		if ( $this->isBundled() ) {
			// Only replies and mentions are bundled. Messages: notification-bundle-header-glowthread-reply,
			// notification-bundle-header-glowthread-mention
			$msg = $this->msg( 'notification-bundle-header-' . $this->type );
			$msg->numParams( $this->getNotificationCountForOutput() );
			$msg->plaintextParams( $this->getTitleText() );
			$msg->params( $this->getViewingUserForGender() );
			return $msg;
		}
		// Messages: notification-header-glowthread-reply, notification-header-glowthread-mention,
		// notification-header-glowthread-userpage
		$msg = $this->getMessageWithCommentAgent( $this->getHeaderMessageKey() );
		$msg->plaintextParams( $this->getTitleText() );
		$msg->params( $this->getViewingUserForGender() );
		return $msg;
	}

	/** @inheritDoc */
	public function getCompactHeaderMessage() {
		// Messages: notification-compact-header-glowthread-reply,
		// notification-compact-header-glowthread-mention, notification-compact-header-glowthread-userpage
		$msg = $this->getMessageWithCommentAgent( $this->getCompactHeaderMessageKey() );
		$msg->params( $this->getViewingUserForGender() );
		return $msg;
	}

	/** @inheritDoc */
	public function getBodyMessage() {
		return $this->isBundled() ? false : $this->getExcerptMessage();
	}

	/** @inheritDoc */
	public function getPrimaryLink() {
		return $this->getCommentLink();
	}

	/** @inheritDoc */
	public function getSecondaryLinks() {
		return $this->isBundled() ? [] : $this->getAgentLinks();
	}
}
