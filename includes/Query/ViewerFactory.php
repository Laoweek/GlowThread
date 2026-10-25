<?php

namespace MediaWiki\Extension\GlowThread\Query;

use MediaWiki\Permissions\Authority;
use MediaWiki\User\ActorNormalization;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Builds Viewer objects from read-only lookups (no actor is created for the reader).
 */
class ViewerFactory {

	public function __construct(
		private readonly ActorNormalization $actorNormalization,
		private readonly IConnectionProvider $dbProvider
	) {
	}

	public function newViewer( Authority $authority ): Viewer {
		$actorId = null;
		if ( $authority->isRegistered() ) {
			$actorId = $this->actorNormalization->findActorId(
				$authority->getUser(), $this->dbProvider->getReplicaDatabase()
			);
		}
		return new Viewer(
			$authority,
			$actorId ?: null,
			$authority->isAllowed( 'glowthread-moderate' ),
			$authority->isAllowed( 'glowthread-erase' ),
			$authority->isAllowed( 'hideuser' )
		);
	}
}
