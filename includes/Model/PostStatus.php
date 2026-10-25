<?php

namespace MediaWiki\Extension\GlowThread\Model;

/**
 * Comment status values stored in glowthread_post.gtp_status.
 */
final class PostStatus {
	/** Shown to everyone */
	public const VISIBLE = 'visible';
	/** Waiting for review: shown only to its author and moderators */
	public const HELD = 'held';
	/** Removed: shown only to moderators, restorable */
	public const DELETED = 'deleted';
	/** Tombstone: text blanked and author hidden, irreversible */
	public const ERASED = 'erased';

	public const ALL = [ self::VISIBLE, self::HELD, self::DELETED, self::ERASED ];
}
