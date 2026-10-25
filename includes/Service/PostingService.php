<?php

namespace MediaWiki\Extension\GlowThread\Service;

use MediaWiki\Api\ApiMessage;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\GlowThread\Cdn\CdnPurger;
use MediaWiki\Extension\GlowThread\HookRunner;
use MediaWiki\Extension\GlowThread\Model\HoldReason;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Model\PostStatus;
use MediaWiki\Extension\GlowThread\Policy\PageEligibility;
use MediaWiki\Extension\GlowThread\Policy\WriteGuard;
use MediaWiki\Extension\GlowThread\Store\PageStateStore;
use MediaWiki\Extension\GlowThread\Store\PostStore;
use MediaWiki\Extension\GlowThread\Text\TextProcessor;
use MediaWiki\Permissions\Authority;
use MediaWiki\User\ActorNormalization;
use MediaWiki\User\ActorStore;
use StatusValue;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Creates comments and replies.
 *
 * A reply under the author's own held comment is held too (HoldReason::PARENT_HELD), so it is
 * reviewed like its parent and notifies only once approved. An author with
 * $wgGlowThreadMaxHeldPerUser comments waiting for review cannot post another one that would be
 * held. GlowThreadPostSaved runs after the response is sent, in the author's request.
 */
class PostingService {

	/** Deepest display level. Replies to depth-2 comments are shown at depth 2. */
	public const MAX_DEPTH = 2;

	/** Matching filter rules kept with a hold (gtp_hold_detail), and characters kept of each */
	private const MAX_HOLD_MATCHES = 20;
	private const MAX_HOLD_RULE_CHARS = 255;

	public function __construct(
		private readonly WriteGuard $guard,
		private readonly TextProcessor $textProcessor,
		private readonly PostStore $postStore,
		private readonly PageStateStore $pageStateStore,
		private readonly PageEligibility $pageEligibility,
		private readonly ActorNormalization $actorNormalization,
		private readonly ActorStore $actorStore,
		private readonly CdnPurger $cdnPurger,
		private readonly HookRunner $hookRunner
	) {
	}

	/**
	 * Post a new comment or reply.
	 *
	 * @param Authority $authority
	 * @param int $pageId
	 * @param string $rawText As typed
	 * @param int|null $replyToId Comment being replied to
	 * @return StatusValue Value is the new PostRecord (status visible or held)
	 */
	public function post( Authority $authority, int $pageId, string $rawText, ?int $replyToId ): StatusValue {
		// Before anything else: reject oversized input without normalising it
		$size = $this->guard->checkRawSize( $rawText );
		if ( !$size->isOK() ) {
			return $size;
		}
		$page = $this->pageEligibility->getPage( $pageId );
		if ( !$page ) {
			return StatusValue::newFatal( ApiMessage::create( 'glowthread-error-page-missing', 'pagenotfound' ) );
		}
		$status = $this->guard->checkWrite( $authority, 'glowthread-post', $page, false );
		if ( !$status->isOK() ) {
			return $status;
		}
		$text = $this->textProcessor->normalize( $rawText );
		$replyTo = null;
		if ( $replyToId !== null ) {
			$replyTo = $this->postStore->getById( $replyToId, true );
			if ( !$replyTo ) {
				return StatusValue::newFatal( ApiMessage::create( 'glowthread-error-parent', 'badparent' ) );
			}
		}
		$content = $this->guard->checkContent( $authority, $page, $text, $replyTo );
		if ( !$content->isOK() ) {
			return $content;
		}
		$mentioned = $content->getValue();

		$dbw = $this->postStore->getPrimary();
		$author = $authority->getUser();
		$actorId = $this->actorNormalization->acquireActorId( $author, $dbw );
		$holdReasons = $this->guard->getHoldReasons( $authority, $text, $actorId, $filterMatches );
		if ( $replyTo && $this->isUnderHeldComment( $replyTo ) ) {
			$holdReasons[] = HoldReason::PARENT_HELD;
		}
		$newStatus = $holdReasons ? PostStatus::HELD : PostStatus::VISIBLE;
		$now = ConvertibleTimestamp::now( TS_MW );

		$dbw->startAtomic( __METHOD__, IDatabase::ATOMIC_CANCELABLE );
		if ( $holdReasons ) {
			$heldLimit = $this->guard->checkHeldLimit( $dbw, $actorId );
			if ( !$heldLimit->isOK() ) {
				$dbw->cancelAtomic( __METHOD__ );
				return $heldLimit;
			}
		}
		$placement = $this->place( $dbw, $replyTo, $actorId );
		if ( $placement === null ) {
			$dbw->cancelAtomic( __METHOD__ );
			return StatusValue::newFatal( ApiMessage::create( 'glowthread-error-parent', 'badparent' ) );
		}
		[ $parentId, $threadId, $depth ] = $placement;
		$seq = $this->pageStateStore->nextSeq( $dbw, $pageId );
		$id = $this->postStore->insert( $dbw, [
			'gtp_page' => $pageId,
			'gtp_actor' => $actorId,
			'gtp_parent' => $parentId,
			'gtp_reply_to' => $replyTo ? $replyTo->getId() : null,
			'gtp_thread' => $threadId,
			'gtp_depth' => $depth,
			'gtp_seq' => $seq,
			'gtp_text' => $text,
			'gtp_created' => $dbw->timestamp( $now ),
			'gtp_status' => $newStatus,
			'gtp_hold_reason' => $holdReasons ? implode( ',', $holdReasons ) : null,
			'gtp_hold_detail' => $holdReasons ? self::holdDetail( $filterMatches ) : null,
		] );
		if ( $newStatus === PostStatus::VISIBLE && $parentId !== null ) {
			$this->postStore->adjustCounter( $dbw, $parentId, 'gtp_reply_count', 1 );
		}
		$dbw->endAtomic( __METHOD__ );

		$post = $this->postStore->getById( $id, true );
		if ( $newStatus === PostStatus::VISIBLE ) {
			$this->cdnPurger->purgePage( $pageId );
		}
		$replyToAuthor = $replyTo ? $this->actorStore->getActorById( $replyTo->getActorId(), $dbw ) : null;
		$hookRunner = $this->hookRunner;
		// After the response is sent, and only if the write commits. The request is still the
		// author's, so CheckUser records their IP and user agent.
		DeferredUpdates::addCallableUpdate(
			static function () use ( $hookRunner, $post, $page, $author, $mentioned, $replyTo, $replyToAuthor ) {
				$hookRunner->onGlowThreadPostSaved( $post, $page, $author, $mentioned, $replyTo, $replyToAuthor );
			},
			DeferredUpdates::POSTSEND,
			$dbw
		);
		return StatusValue::newGood( $post );
	}

	/**
	 * Whether the comment replied to, or a comment it is shown under, is held. Only the author's
	 * own held comments can be replied to (WriteGuard::checkContent).
	 */
	private function isUnderHeldComment( PostRecord $replyTo ): bool {
		if ( $replyTo->isHeld() ) {
			return true;
		}
		$ancestorIds = array_filter( [ $replyTo->getParentId(), $replyTo->getThreadId() ] );
		foreach ( $this->postStore->getByIds( $ancestorIds, true ) as $ancestor ) {
			if ( $ancestor->isHeld() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Where a reply goes: display parent, thread and depth. Locks the comments involved and
	 * checks again that they are still usable.
	 *
	 * @return array{0:?int,1:?int,2:int}|null Null if the reply target is no longer usable
	 */
	private function place( IDatabase $dbw, ?PostRecord $replyTo, int $actorId ): ?array {
		if ( $replyTo === null ) {
			return [ null, null, 0 ];
		}
		$target = $this->postStore->getById( $replyTo->getId(), true, true );
		if ( !$target || !$this->isUsable( $target, $actorId ) ) {
			return null;
		}
		if ( $target->getDepth() < self::MAX_DEPTH ) {
			return [ $target->getId(), $target->getRootId(), $target->getDepth() + 1 ];
		}
		$parent = $this->postStore->getById( (int)$target->getParentId(), true, true );
		if ( !$parent || !$this->isUsable( $parent, $actorId ) ) {
			return null;
		}
		return [ $parent->getId(), $target->getRootId(), self::MAX_DEPTH ];
	}

	private function isUsable( PostRecord $post, int $actorId ): bool {
		return $post->isVisible() || ( $post->isHeld() && $post->getActorId() === $actorId );
	}

	/**
	 * gtp_hold_detail for a held comment: the filter rules that matched, as moderators see them.
	 *
	 * @param array[] $filterMatches From WriteGuard::getHoldReasons()
	 */
	private static function holdDetail( array $filterMatches ): ?string {
		if ( !$filterMatches ) {
			return null;
		}
		$kept = [];
		foreach ( array_slice( $filterMatches, 0, self::MAX_HOLD_MATCHES ) as $match ) {
			$kept[] = [
				'line' => $match['line'],
				'rule' => mb_substr( $match['rule'], 0, self::MAX_HOLD_RULE_CHARS ),
				'type' => $match['type'],
			];
		}
		return json_encode( [ 'filter' => $kept ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
	}
}
