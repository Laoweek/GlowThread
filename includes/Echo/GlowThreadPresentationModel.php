<?php

namespace MediaWiki\Extension\GlowThread\Echo;

use MediaWiki\Extension\GlowThread\Model\PageState;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Special\SpecialGlowThreadLink;
use MediaWiki\Extension\GlowThread\Store\PageStateStore;
use MediaWiki\Extension\GlowThread\Store\PostStore;
use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\MediaWikiServices;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

/**
 * Shared rendering of comment notifications.
 *
 * The comment is looked up when the notification is shown, not when it was sent. While readers
 * can see the comment, the body is a short plain-text excerpt and the primary link is its
 * permalink (Special:GlowThreadLink/<id>, which leads to #gt-<id> on the comment's page, also
 * after a page move). Once it is deleted, held, erased or otherwise hidden, the body is a
 * neutral text and the link goes to the page. The author of an erased comment is not named.
 */
abstract class GlowThreadPresentationModel extends EchoEventPresentationModel {

	/** Visible length of the excerpt, in characters */
	private const EXCERPT_LENGTH = 150;

	/** @var PostRecord|null|false False until loaded */
	private $post = false;
	private ?bool $visible = null;
	/** @var Title|null|false False until loaded */
	private $postTitle = false;

	/**
	 * The comment this notification is about, if it still exists.
	 */
	abstract protected function loadPost(): ?PostRecord;

	protected function getPost(): ?PostRecord {
		if ( $this->post === false ) {
			$this->post = $this->loadPost();
		}
		return $this->post;
	}

	protected static function getPostStore(): PostStore {
		return MediaWikiServices::getInstance()->getService( 'GlowThread.PostStore' );
	}

	/** @inheritDoc */
	public function canRender() {
		return (bool)$this->event->getTitle();
	}

	/**
	 * A comment by ID. Rows are shared by the notifications rendered in one request, which
	 * often are about the same comments and threads.
	 *
	 * @param int $id
	 * @param int[] $alsoLoad Comments to load in the same query, for later calls: the comment
	 *   replied to, which is usually the one the reply is shown under
	 * @return PostRecord|null
	 */
	protected static function getPostById( int $id, array $alsoLoad = [] ): ?PostRecord {
		if ( $id <= 0 ) {
			return null;
		}
		return self::getPostStore()->getByIdsForDisplay( [ $id, ...$alsoLoad ] )[$id] ?? null;
	}

	/**
	 * Whether the viewing user may see the comment now: it and the comments it is shown under
	 * are visible, comments are not turned off on its page, and the user can read that page.
	 * The page state is cached (PageStateStore) and the comments are shared between
	 * notifications, so a notification costs at most two queries here.
	 */
	protected function isPostVisible(): bool {
		if ( $this->visible === null ) {
			$post = $this->getPost();
			$title = $this->getPostTitle();
			/** @var PageStateStore $pageStates */
			$pageStates = MediaWikiServices::getInstance()->getService( 'GlowThread.PageStateStore' );
			$this->visible = $post !== null && $title !== null
				&& $this->areVisible( $post )
				&& $pageStates->getState( $post->getPageId() ) !== PageState::DISABLED
				// The full check, with getUserPermissionsErrorsExpensive handlers, as for sending
				&& $this->getUser()->definitelyCan( 'read', $title );
		}
		return $this->visible;
	}

	/**
	 * The comment and every comment it is shown under are visible.
	 */
	private function areVisible( PostRecord $post ): bool {
		if ( !$post->isVisible() ) {
			return false;
		}
		$ancestorIds = array_values( array_unique( array_filter( [ $post->getParentId(), $post->getThreadId() ] ) ) );
		$ancestors = self::getPostStore()->getByIdsForDisplay( $ancestorIds );
		foreach ( $ancestorIds as $id ) {
			if ( !isset( $ancestors[$id] ) || !$ancestors[$id]->isVisible() ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The page the comment is on now. It follows its page when the page moves. Usually that is
	 * the event's page, which Echo loads for all notifications at once.
	 */
	private function getPostTitle(): ?Title {
		if ( $this->postTitle === false ) {
			$post = $this->getPost();
			if ( !$post ) {
				$this->postTitle = null;
			} elseif ( $this->event->getPageId() === $post->getPageId() && $this->event->getTitle() ) {
				$this->postTitle = $this->event->getTitle();
			} else {
				// The comment moved to another page ID (an undeletion merged its page)
				$this->postTitle = MediaWikiServices::getInstance()->getTitleFactory()->newFromID( $post->getPageId() );
			}
		}
		return $this->postTitle;
	}

	protected function isPostErased(): bool {
		$post = $this->getPost();
		return $post !== null && $post->isErased();
	}

	/**
	 * A message with the agent as $1 (formatted name) and $2 (for GENDER), like
	 * getMessageWithAgent(), but without the name when the comment was erased or the event has
	 * no agent.
	 */
	protected function getMessageWithCommentAgent( string $key ): Message {
		if ( !$this->event->getAgent() || $this->isPostErased() ) {
			$msg = $this->msg( $key );
			// An invalid user name makes GENDER use its default form
			$msg->params(
				'<span class="history-deleted">' . $this->msg( 'rev-deleted-user' )->escaped() . '</span>',
				'[]'
			);
			return $msg;
		}
		return $this->getMessageWithAgent( $key );
	}

	/**
	 * The page title for headers, as a plain-text parameter.
	 */
	protected function getTitleText(): string {
		return $this->getTruncatedTitleText( $this->event->getTitle(), true );
	}

	/**
	 * Body: an excerpt of the comment while it is visible, otherwise a neutral text.
	 */
	protected function getExcerptMessage(): Message {
		$post = $this->getPost();
		if ( !$post || !$this->isPostVisible() ) {
			return $this->msg( 'notification-body-glowthread-unavailable' );
		}
		// Mentions as readers see them: @Name, or a placeholder that names nobody
		$mentionResolver = MediaWikiServices::getInstance()->getService( 'GlowThread.MentionResolver' );
		$plain = $mentionResolver->toPlainTexts( [ $post->getText() ] )[0];
		$text = trim( preg_replace( '/\s+/u', ' ', $plain ) ?? '' );
		return $this->msg( 'notification-body-glowthread-excerpt' )->plaintextParams(
			$this->language->embedBidi( $this->language->truncateForVisual( $text, self::EXCERPT_LENGTH ) )
		);
	}

	/**
	 * The comment's permalink while it is visible, otherwise the page.
	 *
	 * @return array{url:string,label:string}
	 */
	protected function getCommentLink(): array {
		$post = $this->getPost();
		if ( $post && $this->isPostVisible() ) {
			$permalink = SpecialPage::getTitleFor( SpecialGlowThreadLink::NAME, (string)$post->getId() );
			return [
				'url' => $permalink->getFullURL(),
				'label' => $this->msg( 'notification-link-text-view-glowthread-comment' )->text(),
			];
		}
		return [
			'url' => $this->event->getTitle()->getFullURL(),
			'label' => $this->msg( 'notification-link-text-view-glowthread-page' )->text(),
		];
	}

	/**
	 * Link to the agent's user page, unless the comment was erased.
	 *
	 * @return array[]
	 */
	protected function getAgentLinks(): array {
		if ( $this->isPostErased() || !$this->event->getAgent() ) {
			return [];
		}
		$link = $this->getAgentLink();
		return $link ? [ $link ] : [];
	}
}
