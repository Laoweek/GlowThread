<?php

namespace MediaWiki\Extension\GlowThread\Store;

use MapCacheLRU;
use MediaWiki\Extension\GlowThread\Model\PageState;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Row access for glowthread_page: per-page state and the floor-number counter.
 * A page without a row is open and has no floors yet.
 *
 * getState() from the replica is cached: in this process for a few seconds, and in the WAN
 * object cache until the state changes (setState() and movePage() purge it). Every list
 * request needs the state, so this saves a query on each. Article views read the state and the
 * floor counter together (getViewFacts()).
 */
class PageStateStore {

	public const TABLE = 'glowthread_page';

	/** Seconds a state is reused within one process (long-running scripts) */
	private const PROCESS_TTL = 5;

	/** How much each state hides, for movePage() */
	private const STRICTNESS = [ PageState::OPEN => 0, PageState::LOCKED => 1, PageState::DISABLED => 2 ];

	private MapCacheLRU $states;

	public function __construct(
		private readonly IConnectionProvider $dbProvider,
		private readonly WANObjectCache $wanCache
	) {
		$this->states = new MapCacheLRU( 1000 );
	}

	/**
	 * @param int $pageId
	 * @param bool $primary
	 * @return array{state:string,actor:?int,timestamp:?string,seq:int}
	 */
	public function get( int $pageId, bool $primary = false ): array {
		$db = $primary ? $this->dbProvider->getPrimaryDatabase() : $this->dbProvider->getReplicaDatabase();
		$row = $db->newSelectQueryBuilder()
			->select( [ 'gtpg_state', 'gtpg_actor', 'gtpg_timestamp', 'gtpg_seq' ] )
			->from( self::TABLE )
			->where( [ 'gtpg_page' => $pageId ] )
			->caller( __METHOD__ )
			->fetchRow();
		if ( !$row ) {
			return [ 'state' => PageState::OPEN, 'actor' => null, 'timestamp' => null, 'seq' => 0 ];
		}
		$state = (string)$row->gtpg_state;
		return [
			'state' => in_array( $state, PageState::ALL, true ) ? $state : PageState::OPEN,
			'actor' => $row->gtpg_actor === null ? null : (int)$row->gtpg_actor,
			'timestamp' => $row->gtpg_timestamp === null
				? null : ConvertibleTimestamp::convert( TS_MW, $row->gtpg_timestamp ),
			'seq' => (int)$row->gtpg_seq,
		];
	}

	/**
	 * @param int $pageId
	 * @param bool $primary Read from the primary, bypassing the caches (for writes)
	 */
	public function getState( int $pageId, bool $primary = false ): string {
		if ( $primary ) {
			return $this->get( $pageId, true )['state'];
		}
		$state = $this->states->get( $pageId, self::PROCESS_TTL );
		if ( $state === null ) {
			$state = $this->wanCache->getWithSetCallback(
				$this->getCacheKey( $pageId ),
				WANObjectCache::TTL_DAY,
				function ( $old, &$ttl, array &$setOpts ) use ( $pageId ) {
					$setOpts += CacheSetOptions::forRead( $this->dbProvider->getReplicaDatabase() );
					return $this->get( $pageId )['state'];
				}
			);
			$this->states->set( $pageId, $state );
		}
		return $state;
	}

	private function getCacheKey( int $pageId ): string {
		return $this->wanCache->makeKey( 'glowthread-pagestate', $pageId );
	}

	/**
	 * WAN cache key of getViewFacts(). CdnPurger::purgePage() deletes it too, so that the floor
	 * counter follows a busy discussion.
	 */
	public static function makeViewKey( WANObjectCache $wanCache, int $pageId ): string {
		return $wanCache->makeKey( 'glowthread-page-view', $pageId );
	}

	/**
	 * What an article view needs, from one row and one cache key: the state, and the floor
	 * counter (comments ever posted, which sizes the space reserved for the section). Cached for
	 * an hour, and purged by a change of the state and by every write that changes what readers
	 * see on the page.
	 *
	 * @return array{state:string,seq:int}
	 */
	public function getViewFacts( int $pageId ): array {
		return $this->wanCache->getWithSetCallback(
			self::makeViewKey( $this->wanCache, $pageId ),
			WANObjectCache::TTL_HOUR,
			function ( $old, &$ttl, array &$setOpts ) use ( $pageId ) {
				$setOpts += CacheSetOptions::forRead( $this->dbProvider->getReplicaDatabase() );
				$row = $this->get( $pageId );
				return [ 'state' => $row['state'], 'seq' => $row['seq'] ];
			}
		);
	}

	/**
	 * Forget the cached state of a page after a write to it: in this process, and in the WAN
	 * cache both at once and again when the caller's transaction commits. In web requests the
	 * transaction commits at the end, and readers in between must not cache the old state (the
	 * purge's hold-off covers the time until commit and replication).
	 */
	public function invalidate( IDatabase $dbw, int $pageId ): void {
		$this->states->clear( $pageId );
		$keys = [ $this->getCacheKey( $pageId ), self::makeViewKey( $this->wanCache, $pageId ) ];
		$wanCache = $this->wanCache;
		foreach ( $keys as $key ) {
			$wanCache->delete( $key );
		}
		$dbw->onTransactionPreCommitOrIdle( static function () use ( $wanCache, $keys ) {
			foreach ( $keys as $key ) {
				$wanCache->delete( $key );
			}
		}, __METHOD__ );
	}

	/**
	 * Set the state of a page.
	 *
	 * @return string The previous state
	 */
	public function setState( IDatabase $dbw, int $pageId, string $state, int $actorId, string $timestamp ): string {
		$this->ensureRow( $dbw, $pageId );
		$old = (string)$dbw->newSelectQueryBuilder()
			->select( 'gtpg_state' )
			->from( self::TABLE )
			->where( [ 'gtpg_page' => $pageId ] )
			->forUpdate()
			->caller( __METHOD__ )
			->fetchField();
		$dbw->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( [
				'gtpg_state' => $state,
				'gtpg_actor' => $actorId,
				'gtpg_timestamp' => $dbw->timestamp( $timestamp ),
			] )
			->where( [ 'gtpg_page' => $pageId ] )
			->caller( __METHOD__ )
			->execute();
		$this->invalidate( $dbw, $pageId );
		return in_array( $old, PageState::ALL, true ) ? $old : PageState::OPEN;
	}

	/**
	 * Hand out the next floor number for a page. The UPDATE locks the page row until the
	 * caller's transaction ends, so concurrent posts on one page get distinct numbers.
	 * Must be called inside an atomic section.
	 */
	public function nextSeq( IDatabase $dbw, int $pageId ): int {
		$this->incrementSeq( $dbw, $pageId );
		if ( !$dbw->affectedRows() ) {
			$this->ensureRow( $dbw, $pageId );
			$this->incrementSeq( $dbw, $pageId );
		}
		return (int)$dbw->newSelectQueryBuilder()
			->select( 'gtpg_seq' )
			->from( self::TABLE )
			->where( [ 'gtpg_page' => $pageId ] )
			->forUpdate()
			->caller( __METHOD__ )
			->fetchField();
	}

	/**
	 * Make sure the floor counter is at least $seq, for example after importing comments.
	 */
	public function raiseSeqFloor( IDatabase $dbw, int $pageId, int $seq ): void {
		$this->ensureRow( $dbw, $pageId );
		$dbw->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( [ 'gtpg_seq' => $seq ] )
			->where( [ 'gtpg_page' => $pageId, $dbw->expr( 'gtpg_seq', '<', $seq ) ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Move the state row of a page ID to another ID, after an undelete gave the page a new ID.
	 * If the target already has a row, the stricter state of the two wins (disabled, then locked,
	 * then open), with who set it and when, so comments that a closed page hid stay hidden. The
	 * target keeps the higher counter.
	 */
	public function movePage( IDatabase $dbw, int $fromPageId, int $toPageId, int $minSeq ): void {
		$from = $this->get( $fromPageId, true );
		$to = $this->get( $toPageId, true );
		$fromState = [
			'gtpg_state' => $from['state'],
			'gtpg_actor' => $from['actor'],
			'gtpg_timestamp' => $from['timestamp'] === null ? null : $dbw->timestamp( $from['timestamp'] ),
		];
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( self::TABLE )
			->where( [ 'gtpg_page' => $fromPageId ] )
			->caller( __METHOD__ )
			->execute();
		$dbw->newInsertQueryBuilder()
			->insertInto( self::TABLE )
			->ignore()
			->row( [ 'gtpg_page' => $toPageId ] + $fromState + [ 'gtpg_seq' => $from['seq'] ] )
			->caller( __METHOD__ )
			->execute();
		if ( self::STRICTNESS[$from['state']] > self::STRICTNESS[$to['state']] ) {
			$dbw->newUpdateQueryBuilder()
				->update( self::TABLE )
				->set( $fromState )
				->where( [ 'gtpg_page' => $toPageId ] )
				->caller( __METHOD__ )
				->execute();
		}
		$this->raiseSeqFloor( $dbw, $toPageId, $minSeq );
		$this->invalidate( $dbw, $fromPageId );
		$this->invalidate( $dbw, $toPageId );
	}

	public function getReplica(): IReadableDatabase {
		return $this->dbProvider->getReplicaDatabase();
	}

	private function incrementSeq( IDatabase $dbw, int $pageId ): void {
		$dbw->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( 'gtpg_seq = gtpg_seq + 1' )
			->where( [ 'gtpg_page' => $pageId ] )
			->caller( __METHOD__ )
			->execute();
	}

	private function ensureRow( IDatabase $dbw, int $pageId ): void {
		$dbw->newInsertQueryBuilder()
			->insertInto( self::TABLE )
			->ignore()
			->row( [ 'gtpg_page' => $pageId, 'gtpg_state' => PageState::OPEN, 'gtpg_seq' => 0 ] )
			->caller( __METHOD__ )
			->execute();
	}
}
