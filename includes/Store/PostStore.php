<?php

namespace MediaWiki\Extension\GlowThread\Store;

use MapCacheLRU;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Model\PostStatus;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILBFactory;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * Row access for glowthread_post. Counters change only through relative SQL.
 */
class PostStore {

	public const TABLE = 'glowthread_post';

	public const FIELDS = [
		'gtp_id', 'gtp_page', 'gtp_actor', 'gtp_parent', 'gtp_reply_to', 'gtp_thread', 'gtp_depth',
		'gtp_seq', 'gtp_text', 'gtp_created', 'gtp_edited', 'gtp_status', 'gtp_hold_reason',
		'gtp_status_actor', 'gtp_status_timestamp', 'gtp_like_count', 'gtp_reply_count',
		'gtp_open_report_count', 'gtp_pinned', 'gtp_legacy_id', 'gtp_hold_detail',
	];

	/** Counter columns that may be adjusted with adjustCounter() */
	private const COUNTERS = [ 'gtp_like_count', 'gtp_reply_count', 'gtp_open_report_count' ];

	/** Seconds getByIdsForDisplay() reuses a row (long-running scripts) */
	private const DISPLAY_TTL = 5;

	/** @var MapCacheLRU Comment ID => PostRecord, or false when missing */
	private MapCacheLRU $displayCache;

	public function __construct( private readonly IConnectionProvider $dbProvider ) {
		$this->displayCache = new MapCacheLRU( 1000 );
	}

	public function getReplica(): IReadableDatabase {
		return $this->dbProvider->getReplicaDatabase();
	}

	public function getPrimary(): IDatabase {
		return $this->dbProvider->getPrimaryDatabase();
	}

	/**
	 * The primary outside the current transaction, for reads that must see what other
	 * transactions committed after this one started (MySQL's repeatable read hides it). On
	 * SQLite this is the same connection: its transactions never write on stale reads.
	 */
	public function getPrimaryLatest(): IReadableDatabase {
		if ( $this->dbProvider instanceof ILBFactory ) {
			return $this->dbProvider->getMainLB()->getConnection(
				ILoadBalancer::DB_PRIMARY, [], false, ILoadBalancer::CONN_TRX_AUTOCOMMIT
			);
		}
		return $this->getPrimary();
	}

	/**
	 * Lock an actor's row until the transaction ends, so that writes by one actor that must
	 * see each other run one after another. No effect on SQLite, which locks the whole database.
	 */
	public function lockActor( IDatabase $dbw, int $actorId ): void {
		$dbw->newSelectQueryBuilder()
			->select( 'actor_id' )
			->from( 'actor' )
			->where( [ 'actor_id' => $actorId ] )
			->forUpdate()
			->caller( __METHOD__ )
			->fetchField();
	}

	/**
	 * A select builder on glowthread_post with all PostRecord fields.
	 */
	public function newSelectQueryBuilder( IReadableDatabase $db ): SelectQueryBuilder {
		return $db->newSelectQueryBuilder()
			->select( self::FIELDS )
			->from( self::TABLE );
	}

	/**
	 * @param int $id
	 * @param bool $primary Read from the primary, locking the row when $forUpdate is set
	 * @param bool $forUpdate
	 */
	public function getById( int $id, bool $primary = false, bool $forUpdate = false ): ?PostRecord {
		if ( $id <= 0 ) {
			return null;
		}
		$db = $primary ? $this->getPrimary() : $this->getReplica();
		$qb = $this->newSelectQueryBuilder( $db )
			->where( [ 'gtp_id' => $id ] )
			->caller( __METHOD__ );
		if ( $forUpdate ) {
			$qb->forUpdate();
		}
		$row = $qb->fetchRow();
		return $row ? PostRecord::newFromRow( $row ) : null;
	}

	/**
	 * @param int[] $ids
	 * @param bool $primary
	 * @return array<int,PostRecord> Keyed by ID, in no particular order
	 */
	public function getByIds( array $ids, bool $primary = false ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( !$ids ) {
			return [];
		}
		$db = $primary ? $this->getPrimary() : $this->getReplica();
		$res = $this->newSelectQueryBuilder( $db )
			->where( [ 'gtp_id' => $ids ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$posts = [];
		foreach ( $res as $row ) {
			$post = PostRecord::newFromRow( $row );
			$posts[$post->getId()] = $post;
		}
		return $posts;
	}

	/**
	 * The visible comments among $ids, read from the replica without loading the rows.
	 *
	 * @param int[] $ids
	 * @return int[] The subset of $ids whose comments are visible, in no particular order
	 */
	public function getVisibleIds( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( !$ids ) {
			return [];
		}
		return array_map( 'intval', $this->getReplica()->newSelectQueryBuilder()
			->select( 'gtp_id' )
			->from( self::TABLE )
			->where( [ 'gtp_id' => $ids, 'gtp_status' => PostStatus::VISIBLE ] )
			->caller( __METHOD__ )
			->fetchFieldValues() );
	}

	/**
	 * Replica rows remembered for a few seconds within this process, for code that renders many
	 * items about the same comments (Echo notifications: a reply and its thread, several
	 * notifications about one comment, log entries). Status, pin and page changes made through
	 * this store forget the rows they change. Never use it for writes.
	 *
	 * @param int[] $ids
	 * @return array<int,PostRecord> Keyed by ID, missing comments left out
	 */
	public function getByIdsForDisplay( array $ids ): array {
		$out = [];
		$missing = [];
		foreach ( array_unique( array_filter( array_map( 'intval', $ids ) ) ) as $id ) {
			$cached = $this->displayCache->get( $id, self::DISPLAY_TTL );
			if ( $cached === null ) {
				$missing[] = $id;
			} elseif ( $cached !== false ) {
				$out[$id] = $cached;
			}
		}
		if ( $missing ) {
			$loaded = $this->getByIds( $missing );
			foreach ( $missing as $id ) {
				$this->displayCache->set( $id, $loaded[$id] ?? false );
				if ( isset( $loaded[$id] ) ) {
					$out[$id] = $loaded[$id];
				}
			}
		}
		return $out;
	}

	/**
	 * Whether readers can see a comment: it and every comment it is shown under are visible.
	 */
	public function isPubliclyVisible( PostRecord $post, bool $primary = false ): bool {
		if ( !$post->isVisible() ) {
			return false;
		}
		$ancestorIds = array_unique( array_filter( [ $post->getParentId(), $post->getThreadId() ] ) );
		if ( !$ancestorIds ) {
			return true;
		}
		$ancestors = $this->getByIds( $ancestorIds, $primary );
		foreach ( $ancestorIds as $id ) {
			if ( !isset( $ancestors[$id] ) || !$ancestors[$id]->isVisible() ) {
				return false;
			}
		}
		return true;
	}

	public function getByPageAndSeq( int $pageId, int $seq ): ?PostRecord {
		$row = $this->newSelectQueryBuilder( $this->getReplica() )
			->where( [ 'gtp_page' => $pageId, 'gtp_seq' => $seq ] )
			->caller( __METHOD__ )
			->fetchRow();
		return $row ? PostRecord::newFromRow( $row ) : null;
	}

	/**
	 * @param string $legacyId FlowThread ID as hex (case-insensitive)
	 */
	public function getByLegacyId( string $legacyId ): ?PostRecord {
		$row = $this->newSelectQueryBuilder( $this->getReplica() )
			->where( [ 'gtp_legacy_id' => strtolower( $legacyId ) ] )
			->caller( __METHOD__ )
			->fetchRow();
		return $row ? PostRecord::newFromRow( $row ) : null;
	}

	/**
	 * Insert a comment row. Must be called inside the caller's atomic section.
	 *
	 * @param IDatabase $dbw
	 * @param array $row Column => value. Timestamps must already be in DB format.
	 * @return int New comment ID
	 */
	public function insert( IDatabase $dbw, array $row ): int {
		$dbw->newInsertQueryBuilder()
			->insertInto( self::TABLE )
			->row( $row )
			->caller( __METHOD__ )
			->execute();
		return $dbw->insertId();
	}

	/**
	 * Change the status of a comment, but only if it currently has one of $fromStatuses.
	 *
	 * @param IDatabase $dbw
	 * @param int $id
	 * @param string[] $fromStatuses
	 * @param string $toStatus
	 * @param int|null $actorId Who made the change, null for automatic changes
	 * @param string|null $timestamp TS_MW, null only when an undo puts back a missing time
	 * @param array $extraSet More columns to set
	 * @return bool Whether the row changed
	 */
	public function changeStatus(
		IDatabase $dbw,
		int $id,
		array $fromStatuses,
		string $toStatus,
		?int $actorId,
		?string $timestamp,
		array $extraSet = []
	): bool {
		$dbw->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( [
				'gtp_status' => $toStatus,
				'gtp_status_actor' => $actorId,
				'gtp_status_timestamp' => $timestamp === null ? null : $dbw->timestamp( $timestamp ),
			] + $extraSet )
			->where( [ 'gtp_id' => $id, 'gtp_status' => $fromStatuses ] )
			->caller( __METHOD__ )
			->execute();
		$changed = $dbw->affectedRows() > 0;
		$this->displayCache->clear( $id );
		return $changed;
	}

	/**
	 * Add $delta to a counter with relative SQL, never going below zero.
	 */
	public function adjustCounter( IDatabase $dbw, int $id, string $column, int $delta ): void {
		if ( !in_array( $column, self::COUNTERS, true ) ) {
			throw new \InvalidArgumentException( "Unknown counter $column" );
		}
		if ( $delta === 0 ) {
			return;
		}
		if ( $delta > 0 ) {
			$set = "$column = $column + $delta";
		} else {
			$abs = -$delta;
			$set = "$column = CASE WHEN $column > $abs THEN $column - $abs ELSE 0 END";
		}
		$dbw->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( $set )
			->where( [ 'gtp_id' => $id ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Set or clear the pin timestamp.
	 *
	 * @return bool Whether the row changed
	 */
	public function setPinned( IDatabase $dbw, int $id, ?string $timestamp ): bool {
		$dbw->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( [ 'gtp_pinned' => $timestamp === null ? null : $dbw->timestamp( $timestamp ) ] )
			->where( [
				'gtp_id' => $id,
				$timestamp === null
					? $dbw->expr( 'gtp_pinned', '!=', null )
					: $dbw->expr( 'gtp_pinned', '=', null ),
			] )
			->caller( __METHOD__ )
			->execute();
		$changed = $dbw->affectedRows() > 0;
		$this->displayCache->clear( $id );
		return $changed;
	}

	/**
	 * Count comments by an actor with the given statuses, stopping at $limit.
	 */
	public function countByActor( IReadableDatabase $db, int $actorId, array $statuses, int $limit ): int {
		return (int)$db->newSelectQueryBuilder()
			->select( '1' )
			->from( self::TABLE )
			->where( [ 'gtp_actor' => $actorId, 'gtp_status' => $statuses ] )
			->limit( $limit )
			->caller( __METHOD__ )
			->fetchRowCount();
	}

	/**
	 * Recent comments by an actor on a page, newest first, for the duplicate check.
	 *
	 * @return PostRecord[]
	 */
	public function getRecentByActorOnPage(
		IReadableDatabase $db,
		int $actorId,
		int $pageId,
		string $since
	): array {
		$res = $this->newSelectQueryBuilder( $db )
			->where( [
				'gtp_actor' => $actorId,
				'gtp_page' => $pageId,
				$db->expr( 'gtp_created', '>=', $db->timestamp( $since ) ),
				'gtp_status' => [ PostStatus::VISIBLE, PostStatus::HELD ],
			] )
			->orderBy( 'gtp_created', SelectQueryBuilder::SORT_DESC )
			->limit( 50 )
			->caller( __METHOD__ )
			->fetchResultSet();
		$posts = [];
		foreach ( $res as $row ) {
			$posts[] = PostRecord::newFromRow( $row );
		}
		return $posts;
	}

	/**
	 * Move all comments of one page ID to another (after an undelete gave the page a new ID).
	 */
	public function movePage( IDatabase $dbw, int $fromPageId, int $toPageId, int $seqOffset ): void {
		$this->displayCache->clear();
		if ( $seqOffset > 0 ) {
			$dbw->newUpdateQueryBuilder()
				->update( self::TABLE )
				->set( [ 'gtp_page' => $toPageId, "gtp_seq = gtp_seq + $seqOffset" ] )
				->where( [ 'gtp_page' => $fromPageId ] )
				->caller( __METHOD__ )
				->execute();
		} else {
			$dbw->newUpdateQueryBuilder()
				->update( self::TABLE )
				->set( [ 'gtp_page' => $toPageId ] )
				->where( [ 'gtp_page' => $fromPageId ] )
				->caller( __METHOD__ )
				->execute();
		}
	}

	/**
	 * @param IReadableDatabase $db
	 * @param int $pageId
	 * @return int Highest floor number used on the page, 0 if none
	 */
	public function getMaxSeq( IReadableDatabase $db, int $pageId ): int {
		return (int)$db->newSelectQueryBuilder()
			->select( 'MAX(gtp_seq)' )
			->from( self::TABLE )
			->where( [ 'gtp_page' => $pageId ] )
			->caller( __METHOD__ )
			->fetchField();
	}
}
