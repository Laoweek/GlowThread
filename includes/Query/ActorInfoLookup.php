<?php

namespace MediaWiki\Extension\GlowThread\Query;

use MediaWiki\Block\HideUserUtils;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\User\UserNameUtils;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Bulk lookup of actor names, including whether the user is hidden by a hideuser block, and
 * the account's registration time.
 */
class ActorInfoLookup {

	/** @var array<int,array{name:string,userId:int,hidden:bool,anon:bool,temp:bool,registration:?string}> */
	private array $cache = [];

	public function __construct(
		private readonly IConnectionProvider $dbProvider,
		private readonly HideUserUtils $hideUserUtils,
		private readonly UserNameUtils $userNameUtils
	) {
	}

	/**
	 * @param int[] $actorIds
	 * @return array<int,array{name:string,userId:int,hidden:bool,anon:bool,temp:bool,registration:?string}>
	 *   Keyed by actor ID. registration is TS_MW, null for IP actors and very old accounts.
	 */
	public function getInfo( array $actorIds ): array {
		$missing = array_values( array_diff(
			array_unique( array_filter( array_map( 'intval', $actorIds ) ) ),
			array_keys( $this->cache )
		) );
		if ( $missing ) {
			$db = $this->dbProvider->getReplicaDatabase();
			foreach ( array_chunk( $missing, 500 ) as $batch ) {
				$qb = $db->newSelectQueryBuilder()
					->select( [ 'actor_id', 'actor_name', 'actor_user', 'user_registration' ] )
					->from( 'actor' )
					->leftJoin( 'user', null, 'user_id = actor_user' )
					->where( [ 'actor_id' => $batch ] )
					->caller( __METHOD__ );
				$this->hideUserUtils->addFieldToBuilder( $qb, 'actor_user', 'hu_deleted' );
				foreach ( $qb->fetchResultSet() as $row ) {
					$name = (string)$row->actor_name;
					$userId = (int)$row->actor_user;
					$this->cache[(int)$row->actor_id] = [
						'name' => $name,
						'userId' => $userId,
						'hidden' => (bool)$row->hu_deleted,
						'anon' => $userId === 0,
						'temp' => $userId !== 0 && $this->userNameUtils->isTemp( $name ),
						'registration' => $row->user_registration === null
							? null : ( ConvertibleTimestamp::convert( TS_MW, $row->user_registration ) ?: null ),
					];
				}
			}
		}
		$out = [];
		foreach ( $actorIds as $id ) {
			if ( isset( $this->cache[$id] ) ) {
				$out[$id] = $this->cache[$id];
			}
		}
		return $out;
	}

	public function getUserIdentity( int $actorId ): ?UserIdentity {
		$info = $this->getInfo( [ $actorId ] )[$actorId] ?? null;
		return $info ? new UserIdentityValue( $info['userId'], $info['name'] ) : null;
	}
}
