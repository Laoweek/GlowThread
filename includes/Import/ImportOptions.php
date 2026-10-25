<?php

namespace MediaWiki\Extension\GlowThread\Import;

use InvalidArgumentException;

/**
 * Choices for one import run. The defaults are the recommendations in docs/migration-data.md
 * ("Import choices").
 */
final class ImportOptions {

	/** FlowThread spam (status 2): held for review (reason "filter"), or deleted */
	public const SPAM = [ 'held', 'deleted' ];
	/**
	 * FlowThread control status 2 (comments turned off by an admin), which kept showing the
	 * existing comments: locked (comments readable, no new ones), or disabled (hidden)
	 */
	public const DISABLED_PAGES = [ 'locked', 'disabled' ];
	/** FlowThread control status 1 (opted out by the page owner): locked, disabled or open */
	public const OPTED_OUT = [ 'locked', 'disabled', 'open' ];
	/** Unreviewed reports on deleted comments: resolved as upheld by the importer, or left open */
	public const REPORTS_ON_DELETED = [ 'resolved', 'open' ];
	/** A comment whose text is empty after conversion: kept as it is, or deleted */
	public const EMPTY_TEXT = [ 'keep', 'delete' ];
	/**
	 * A normal comment that FlowThread did not show (an ancestor is deleted, or its parent is
	 * missing) but that GlowThread's display rule would show: deleted, or visible
	 */
	public const HIDDEN = [ 'deleted', 'visible' ];
	/**
	 * Text from a heimu spoiler span (class "heimu", the black-bar spoiler style of many
	 * Chinese-language wikis): kept visible, or the comment is held for review
	 */
	public const SPOILERS = [ 'keep', 'hold' ];
	/** Images: dropped, or replaced by their URL */
	public const IMAGES = [ 'drop', 'link' ];
	/** Likes and reports on one's own comment: kept, or dropped */
	public const SELF_ATTITUDES = [ 'keep', 'drop' ];

	public function __construct(
		/** Only this page, or null for every page */
		public readonly ?int $pageId = null,
		/**
		 * Rewrite imported rows that differ from what the source gives now (otherwise only report),
		 * except what was decided in GlowThread since (see FlowThreadImporter)
		 */
		public readonly bool $update = false,
		public readonly string $spam = 'held',
		public readonly string $optedOut = 'locked',
		public readonly string $reportsOnDeleted = 'resolved',
		public readonly string $emptyText = 'keep',
		public readonly string $hidden = 'deleted',
		public readonly string $spoilers = 'keep',
		public readonly string $images = 'drop',
		public readonly string $selfAttitudes = 'keep',
		/** Actor name prefix for authors whose user ID no longer exists ("imported>Name") */
		public readonly string $missingUserPrefix = 'imported',
		/** Comments per write batch (one transaction, then a wait for replication) */
		public readonly int $batchSize = 200,
		/** Write one glowthread/import log entry when comments were added */
		public readonly bool $log = true,
		public readonly string $disabledPages = 'locked'
	) {
		$choices = [
			'spam' => self::SPAM, 'disabledPages' => self::DISABLED_PAGES, 'optedOut' => self::OPTED_OUT,
			'reportsOnDeleted' => self::REPORTS_ON_DELETED,
			'emptyText' => self::EMPTY_TEXT, 'hidden' => self::HIDDEN, 'spoilers' => self::SPOILERS,
			'images' => self::IMAGES, 'selfAttitudes' => self::SELF_ATTITUDES,
		];
		foreach ( $choices as $name => $allowed ) {
			if ( !in_array( $this->$name, $allowed, true ) ) {
				throw new InvalidArgumentException(
					"Option $name must be one of " . implode( ', ', $allowed ) . ', got "' . $this->$name . '"'
				);
			}
		}
		if ( $this->batchSize < 1 ) {
			throw new InvalidArgumentException( 'The batch size must be at least 1' );
		}
		if ( $this->missingUserPrefix === '' || preg_match( '/[>:\/#|\[\]{}<]/', $this->missingUserPrefix ) ) {
			throw new InvalidArgumentException( 'The missing-user prefix must be a plain word' );
		}
	}

	/**
	 * @return array<string,mixed> The decisions, for the report
	 */
	public function toArray(): array {
		return get_object_vars( $this );
	}
}
