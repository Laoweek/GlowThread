<?php

namespace MediaWiki\Extension\GlowThread\Query;

use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Model\PostStatus;
use MediaWiki\Permissions\Authority;

/**
 * Who is reading, and what that changes. Built without writing anything, so that anonymous
 * list responses stay cacheable.
 */
class Viewer {

	/**
	 * @param Authority $authority
	 * @param int|null $actorId Null for anonymous readers and users who never acted
	 * @param bool $isModerator Has glowthread-moderate: sees held, deleted and erased comments
	 * @param bool $canErase Has glowthread-erase: sees who wrote erased comments
	 * @param bool $canSeeHiddenUsers Has hideuser: sees names hidden by hideuser blocks
	 */
	public function __construct(
		public readonly Authority $authority,
		public readonly ?int $actorId,
		public readonly bool $isModerator,
		public readonly bool $canErase,
		public readonly bool $canSeeHiddenUsers
	) {
	}

	public function isAnon(): bool {
		return !$this->authority->isRegistered();
	}

	public function isOwn( PostRecord $post ): bool {
		return $this->actorId !== null && $this->actorId === $post->getActorId();
	}

	/**
	 * Whether the status alone lets this viewer see the comment.
	 */
	public function canSeeStatus( PostRecord $post ): bool {
		switch ( $post->getStatus() ) {
			case PostStatus::VISIBLE:
				return true;
			case PostStatus::HELD:
				return $this->isModerator || $this->isOwn( $post );
			default:
				return $this->isModerator;
		}
	}
}
