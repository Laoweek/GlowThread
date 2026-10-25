<?php

namespace MediaWiki\Extension\GlowThread\Model;

use stdClass;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Immutable view of one glowthread_post row. Timestamps are TS_MW strings.
 */
class PostRecord {

	/**
	 * @param int $id
	 * @param int $pageId
	 * @param int $actorId
	 * @param int|null $parentId Display parent (depth - 1)
	 * @param int|null $replyToId Comment the author replied to
	 * @param int|null $threadId Top-level ancestor
	 * @param int $depth 0, 1 or 2
	 * @param int $seq Per-page floor number
	 * @param string $text Plain-text source
	 * @param string $created
	 * @param string|null $edited
	 * @param string $status One of PostStatus::ALL
	 * @param string[] $holdReasons
	 * @param int|null $statusActorId
	 * @param string|null $statusTimestamp
	 * @param int $likeCount
	 * @param int $replyCount Visible direct (display) children
	 * @param int $openReportCount
	 * @param string|null $pinned Pin timestamp
	 * @param string|null $legacyId FlowThread ID in hex
	 * @param array[] $holdMatches Word filter rules that matched when the comment was held,
	 *  each [ 'line' => int, 'rule' => string, 'type' => 'literal'|'regex' ]
	 */
	public function __construct(
		private readonly int $id,
		private readonly int $pageId,
		private readonly int $actorId,
		private readonly ?int $parentId,
		private readonly ?int $replyToId,
		private readonly ?int $threadId,
		private readonly int $depth,
		private readonly int $seq,
		private readonly string $text,
		private readonly string $created,
		private readonly ?string $edited,
		private readonly string $status,
		private readonly array $holdReasons,
		private readonly ?int $statusActorId,
		private readonly ?string $statusTimestamp,
		private readonly int $likeCount,
		private readonly int $replyCount,
		private readonly int $openReportCount,
		private readonly ?string $pinned,
		private readonly ?string $legacyId,
		private readonly array $holdMatches = []
	) {
	}

	/**
	 * Build from a glowthread_post row selected with PostStore::FIELDS.
	 */
	public static function newFromRow( stdClass $row ): self {
		return new self(
			(int)$row->gtp_id,
			(int)$row->gtp_page,
			(int)$row->gtp_actor,
			self::nullableInt( $row->gtp_parent ),
			self::nullableInt( $row->gtp_reply_to ),
			self::nullableInt( $row->gtp_thread ),
			(int)$row->gtp_depth,
			(int)$row->gtp_seq,
			(string)$row->gtp_text,
			self::timestamp( $row->gtp_created ) ?? '19700101000000',
			self::timestamp( $row->gtp_edited ),
			(string)$row->gtp_status,
			$row->gtp_hold_reason === null || $row->gtp_hold_reason === ''
				? []
				: explode( ',', (string)$row->gtp_hold_reason ),
			self::nullableInt( $row->gtp_status_actor ),
			self::timestamp( $row->gtp_status_timestamp ),
			(int)$row->gtp_like_count,
			(int)$row->gtp_reply_count,
			(int)$row->gtp_open_report_count,
			self::timestamp( $row->gtp_pinned ),
			$row->gtp_legacy_id === null ? null : (string)$row->gtp_legacy_id,
			self::holdMatches( $row->gtp_hold_detail )
		);
	}

	/**
	 * @param mixed $detail gtp_hold_detail: JSON { "filter": [ { line, rule, type }, ... ] } or null
	 * @return array[]
	 */
	private static function holdMatches( $detail ): array {
		if ( $detail === null || $detail === '' ) {
			return [];
		}
		$decoded = json_decode( (string)$detail, true );
		$matches = [];
		foreach ( is_array( $decoded['filter'] ?? null ) ? $decoded['filter'] : [] as $match ) {
			if ( is_array( $match ) && isset( $match['line'] ) && isset( $match['rule'] ) && isset( $match['type'] ) ) {
				$matches[] = [
					'line' => (int)$match['line'],
					'rule' => (string)$match['rule'],
					'type' => (string)$match['type'],
				];
			}
		}
		return $matches;
	}

	/**
	 * @param mixed $value
	 */
	private static function nullableInt( $value ): ?int {
		return $value === null ? null : (int)$value;
	}

	/**
	 * Normalise a DB timestamp (BINARY(14) on MySQL, TIMESTAMPTZ on Postgres) to TS_MW.
	 *
	 * @param mixed $value
	 */
	private static function timestamp( $value ): ?string {
		if ( $value === null || $value === '' ) {
			return null;
		}
		// Already TS_MW in MySQL, MariaDB and SQLite: skip the conversion, which costs several
		// times more and runs for every timestamp of every comment a list shows
		if ( self::isPlainTimestamp( $value ) ) {
			return $value;
		}
		return ConvertibleTimestamp::convert( TS_MW, $value ) ?: null;
	}

	/**
	 * Whether a value is a TS_MW timestamp that ConvertibleTimestamp gives back unchanged: 14
	 * digits that form a real date and time, in a year from 1000 on. Others (such as a 13th month,
	 * which it would move into the next year) need the conversion.
	 *
	 * @param mixed $value
	 */
	public static function isPlainTimestamp( $value ): bool {
		return is_string( $value ) && strlen( $value ) === 14 && ctype_digit( $value ) && $value[0] !== '0'
			&& checkdate( (int)substr( $value, 4, 2 ), (int)substr( $value, 6, 2 ), (int)substr( $value, 0, 4 ) )
			&& (int)substr( $value, 8, 2 ) < 24 && (int)substr( $value, 10, 2 ) < 60
			&& (int)substr( $value, 12, 2 ) < 60;
	}

	public function getId(): int {
		return $this->id;
	}

	public function getPageId(): int {
		return $this->pageId;
	}

	public function getActorId(): int {
		return $this->actorId;
	}

	public function getParentId(): ?int {
		return $this->parentId;
	}

	public function getReplyToId(): ?int {
		return $this->replyToId;
	}

	public function getThreadId(): ?int {
		return $this->threadId;
	}

	/**
	 * The top-level comment of this comment's thread (itself for top-level comments).
	 */
	public function getRootId(): int {
		return $this->threadId ?? $this->id;
	}

	public function getDepth(): int {
		return $this->depth;
	}

	public function isTopLevel(): bool {
		return $this->parentId === null;
	}

	public function getSeq(): int {
		return $this->seq;
	}

	public function getText(): string {
		return $this->text;
	}

	public function getCreated(): string {
		return $this->created;
	}

	public function getEdited(): ?string {
		return $this->edited;
	}

	public function getStatus(): string {
		return $this->status;
	}

	public function isVisible(): bool {
		return $this->status === PostStatus::VISIBLE;
	}

	public function isHeld(): bool {
		return $this->status === PostStatus::HELD;
	}

	public function isDeleted(): bool {
		return $this->status === PostStatus::DELETED;
	}

	public function isErased(): bool {
		return $this->status === PostStatus::ERASED;
	}

	/**
	 * @return string[]
	 */
	public function getHoldReasons(): array {
		return $this->holdReasons;
	}

	public function getStatusActorId(): ?int {
		return $this->statusActorId;
	}

	public function getStatusTimestamp(): ?string {
		return $this->statusTimestamp;
	}

	public function getLikeCount(): int {
		return $this->likeCount;
	}

	public function getReplyCount(): int {
		return $this->replyCount;
	}

	public function getOpenReportCount(): int {
		return $this->openReportCount;
	}

	public function getPinned(): ?string {
		return $this->pinned;
	}

	public function isPinned(): bool {
		return $this->pinned !== null;
	}

	public function getLegacyId(): ?string {
		return $this->legacyId;
	}

	/**
	 * Word filter rules that matched when the comment was held (the rules of that time).
	 *
	 * @return array[] Each [ 'line' => int, 'rule' => string, 'type' => 'literal'|'regex' ]
	 */
	public function getHoldMatches(): array {
		return $this->holdMatches;
	}
}
