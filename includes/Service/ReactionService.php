<?php

namespace MediaWiki\Extension\GlowThread\Service;

use MediaWiki\Api\ApiMessage;
use MediaWiki\Extension\GlowThread\Policy\PageEligibility;
use MediaWiki\Extension\GlowThread\Policy\WriteGuard;
use MediaWiki\Extension\GlowThread\Query\ListCache;
use MediaWiki\Extension\GlowThread\Store\PostStore;
use MediaWiki\Extension\GlowThread\Store\ReactionStore;
use MediaWiki\Permissions\Authority;
use MediaWiki\User\ActorNormalization;
use StatusValue;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Likes. Setting a like is idempotent: value 1 sets it, value 0 clears it, and repeating a
 * call changes nothing. Accounts the new-account gate would hold cannot like (error newaccount),
 * but can remove a like.
 *
 * Likes do not purge the CDN: purging on every like would let one account turn likes into a
 * stream of CDN bans. Like counts in the anonymous list the CDN caches may lag by up to
 * $wgGlowThreadCdnMaxAge seconds. The wiki's own cache of the lists (ListCache) is purged at
 * once, so readers who are logged in, and every reader without a CDN, get fresh counts.
 */
class ReactionService {

	public function __construct(
		private readonly WriteGuard $guard,
		private readonly PostStore $postStore,
		private readonly ReactionStore $reactionStore,
		private readonly PageEligibility $pageEligibility,
		private readonly ActorNormalization $actorNormalization,
		private readonly ListCache $listCache
	) {
	}

	/**
	 * @param Authority $authority
	 * @param int $postId
	 * @param bool $like
	 * @return StatusValue Value: [ 'liked' => bool, 'likes' => int, 'changed' => bool ]
	 */
	public function setLike( Authority $authority, int $postId, bool $like ): StatusValue {
		$post = $this->postStore->getById( $postId, true );
		$page = $post ? $this->pageEligibility->getPage( $post->getPageId() ) : null;
		// A hidden comment is answered like a missing one, before the page checks, so that the
		// error does not tell whether it exists or what state its page is in.
		if ( !$post || !$page || !$this->postStore->isPubliclyVisible( $post, true ) ) {
			return StatusValue::newFatal( ApiMessage::create( 'glowthread-error-comment-missing', 'commentnotfound' ) );
		}
		$status = $this->guard->checkWrite( $authority, 'glowthread-react', $page, true );
		if ( !$status->isOK() ) {
			return $status;
		}
		$dbw = $this->postStore->getPrimary();
		$actorId = $this->actorNormalization->acquireActorId( $authority->getUser(), $dbw );
		if ( $actorId === $post->getActorId() ) {
			return StatusValue::newFatal( ApiMessage::create( 'glowthread-error-own-like', 'ownlike' ) );
		}
		// Likes rank comments (hot order, top comments), so accounts the new-account gate would
		// hold cannot add one. Removing a like stays possible.
		if ( $like && $this->guard->isGatedNewAccount( $authority, $actorId ) ) {
			return StatusValue::newFatal( ApiMessage::create( 'glowthread-error-new-account-like', 'newaccount' ) );
		}

		$dbw->startAtomic( __METHOD__ );
		if ( $like ) {
			$changed = $this->reactionStore->add(
				$dbw, $postId, $actorId, ReactionStore::TYPE_LIKE, ConvertibleTimestamp::now( TS_MW )
			);
		} else {
			$changed = $this->reactionStore->remove( $dbw, $postId, $actorId, ReactionStore::TYPE_LIKE );
		}
		if ( $changed ) {
			$this->postStore->adjustCounter( $dbw, $postId, 'gtp_like_count', $like ? 1 : -1 );
		}
		$dbw->endAtomic( __METHOD__ );
		if ( $changed ) {
			$this->listCache->touchPage( $post->getPageId() );
		}

		$fresh = $this->postStore->getById( $postId, true );
		return StatusValue::newGood( [
			'liked' => $like,
			'likes' => $fresh ? $fresh->getLikeCount() : $post->getLikeCount(),
			'changed' => $changed,
		] );
	}
}
