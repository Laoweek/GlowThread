<?php

namespace MediaWiki\Extension\GlowThread\Store;

use MediaWiki\Extension\GlowThread\Query\ListCache;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Row access for glowthread_setting. Values are stored as JSON.
 *
 * Reads from the replica are cached in the WAN object cache until a setting changes (set()
 * and delete() purge it): every list request and every write reads the settings. A change also
 * purges the cached lists (ListCache), which show some settings to the browser.
 */
class SettingsStore {

	public const TABLE = 'glowthread_setting';

	private ListCache $listCache;

	/**
	 * @param IConnectionProvider $dbProvider
	 * @param WANObjectCache $wanCache
	 * @param ListCache|null $listCache Null for one on the same cache
	 */
	public function __construct(
		private readonly IConnectionProvider $dbProvider,
		private readonly WANObjectCache $wanCache,
		?ListCache $listCache = null
	) {
		$this->listCache = $listCache ?? new ListCache( $wanCache, $dbProvider );
	}

	/**
	 * @param bool $primary Read from the primary, bypassing the cache
	 * @return array<string,array{value:mixed,actor:int,timestamp:string}>
	 */
	public function getAll( bool $primary = false ): array {
		if ( $primary ) {
			return $this->load( $this->dbProvider->getPrimaryDatabase() );
		}
		return $this->wanCache->getWithSetCallback(
			$this->getCacheKey(),
			WANObjectCache::TTL_DAY,
			function ( $old, &$ttl, array &$setOpts ) {
				$db = $this->dbProvider->getReplicaDatabase();
				$setOpts += CacheSetOptions::forRead( $db );
				return $this->load( $db );
			}
		);
	}

	private function getCacheKey(): string {
		return $this->wanCache->makeKey( 'glowthread-settings' );
	}

	/**
	 * Purge the cached settings at once and again when the caller's transaction commits (in
	 * web requests that is at the end of the request), and the cached lists.
	 */
	private function invalidate( IDatabase $dbw ): void {
		$key = $this->getCacheKey();
		$wanCache = $this->wanCache;
		$wanCache->delete( $key );
		$dbw->onTransactionPreCommitOrIdle( static function () use ( $wanCache, $key ) {
			$wanCache->delete( $key );
		}, __METHOD__ );
		$this->listCache->touchAll();
	}

	/**
	 * @param \Wikimedia\Rdbms\IReadableDatabase $db
	 * @return array<string,array{value:mixed,actor:int,timestamp:string}>
	 */
	private function load( $db ): array {
		$res = $db->newSelectQueryBuilder()
			->select( [ 'gts_name', 'gts_value', 'gts_actor', 'gts_timestamp' ] )
			->from( self::TABLE )
			->caller( __METHOD__ )
			->fetchResultSet();
		$settings = [];
		foreach ( $res as $row ) {
			$value = json_decode( (string)$row->gts_value, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				continue;
			}
			$settings[(string)$row->gts_name] = [
				'value' => $value,
				'actor' => (int)$row->gts_actor,
				'timestamp' => ConvertibleTimestamp::convert( TS_MW, $row->gts_timestamp ),
			];
		}
		return $settings;
	}

	/**
	 * Store a value, replacing any earlier one.
	 *
	 * @param IDatabase $dbw
	 * @param string $name
	 * @param mixed $value JSON-encodable
	 * @param int $actorId
	 * @param string $timestamp
	 */
	public function set( IDatabase $dbw, string $name, $value, int $actorId, string $timestamp ): void {
		$row = [
			'gts_name' => $name,
			'gts_value' => json_encode( $value, JSON_UNESCAPED_UNICODE ),
			'gts_actor' => $actorId,
			'gts_timestamp' => $dbw->timestamp( $timestamp ),
		];
		$dbw->newInsertQueryBuilder()
			->insertInto( self::TABLE )
			->row( $row )
			->onDuplicateKeyUpdate()
			->uniqueIndexFields( [ 'gts_name' ] )
			->set( [
				'gts_value' => $row['gts_value'],
				'gts_actor' => $row['gts_actor'],
				'gts_timestamp' => $row['gts_timestamp'],
			] )
			->caller( __METHOD__ )
			->execute();
		$this->invalidate( $dbw );
	}

	/**
	 * Remove an override so the configured default applies again.
	 */
	public function delete( IDatabase $dbw, string $name ): void {
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( self::TABLE )
			->where( [ 'gts_name' => $name ] )
			->caller( __METHOD__ )
			->execute();
		$this->invalidate( $dbw );
	}

	public function getPrimary(): IDatabase {
		return $this->dbProvider->getPrimaryDatabase();
	}
}
