<?php

namespace MediaWiki\Extension\GlowThread\Store;

use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IReadableDatabase;

/**
 * Row access for glowthread_reaction (likes).
 */
class ReactionStore {

	public const TABLE = 'glowthread_reaction';
	public const TYPE_LIKE = 'like';

	/**
	 * Add a reaction if it does not exist yet.
	 *
	 * @return bool Whether a row was inserted
	 */
	public function add( IDatabase $dbw, int $postId, int $actorId, string $type, string $timestamp ): bool {
		$dbw->newInsertQueryBuilder()
			->insertInto( self::TABLE )
			->ignore()
			->row( [
				'gtr_post' => $postId,
				'gtr_actor' => $actorId,
				'gtr_type' => $type,
				'gtr_timestamp' => $dbw->timestamp( $timestamp ),
			] )
			->caller( __METHOD__ )
			->execute();
		return $dbw->affectedRows() > 0;
	}

	/**
	 * @return bool Whether a row was deleted
	 */
	public function remove( IDatabase $dbw, int $postId, int $actorId, string $type ): bool {
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( self::TABLE )
			->where( [ 'gtr_post' => $postId, 'gtr_actor' => $actorId, 'gtr_type' => $type ] )
			->caller( __METHOD__ )
			->execute();
		return $dbw->affectedRows() > 0;
	}

	/**
	 * @param IReadableDatabase $db
	 * @param int $actorId
	 * @param int[] $postIds
	 * @param string $type
	 * @return int[] The subset of $postIds the actor reacted to
	 */
	public function getReactedPostIds( IReadableDatabase $db, int $actorId, array $postIds, string $type ): array {
		if ( !$postIds || $actorId <= 0 ) {
			return [];
		}
		return array_map( 'intval', $db->newSelectQueryBuilder()
			->select( 'gtr_post' )
			->from( self::TABLE )
			->where( [ 'gtr_actor' => $actorId, 'gtr_post' => $postIds, 'gtr_type' => $type ] )
			->caller( __METHOD__ )
			->fetchFieldValues() );
	}
}
