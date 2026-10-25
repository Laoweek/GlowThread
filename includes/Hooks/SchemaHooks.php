<?php

namespace MediaWiki\Extension\GlowThread\Hooks;

use MediaWiki\Extension\GlowThread\Log\ModerationLogger;
use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

/**
 * Creates the GlowThread tables in update.php, then the system user. No services: this hook runs
 * before the service container exists. The update callback runs later, once it does.
 */
class SchemaHooks implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = dirname( __DIR__, 2 ) . '/sql/' . $updater->getDB()->getType();
		$updater->addExtensionTable( 'glowthread_post', "$dir/tables-generated.sql" );
		$updater->addExtensionUpdate( [ [ self::class, 'createSystemUser' ] ] );
	}

	/**
	 * Create the performer of automatic holds ahead of time (ModerationLogger::getAutoHoldPerformer()),
	 * so that the first holds do not create it: two at the same moment could deadlock. Runs on
	 * every update and changes nothing once the user exists.
	 *
	 * @param DatabaseUpdater $updater
	 */
	public static function createSystemUser( DatabaseUpdater $updater ): void {
		$name = ModerationLogger::getAutoHoldPerformer()->getName();
		$updater->output( "...automatic holds are logged as \"$name\".\n" );
	}
}
