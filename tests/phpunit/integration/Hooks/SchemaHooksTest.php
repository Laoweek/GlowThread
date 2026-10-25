<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Hooks;

use MediaWiki\Extension\GlowThread\Hooks\SchemaHooks;
use MediaWiki\Extension\GlowThread\Log\ModerationLogger;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;

/**
 * The update callback of update.php: the system user that logs automatic holds is created
 * ahead of time (two first holds at the same moment could deadlock creating it).
 *
 * The callbacks run the way DatabaseUpdater::runUpdates() runs extension updates, with a mock
 * updater that holds the test database.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Hooks\SchemaHooks
 * @covers \MediaWiki\Extension\GlowThread\Log\ModerationLogger
 */
class SchemaHooksTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
	}

	/**
	 * Run the update callbacks the hook registers, in order.
	 */
	private function runUpdates( DatabaseUpdater $updater ): void {
		$updates = [];
		$updater->method( 'addExtensionUpdate' )->willReturnCallback(
			static function ( array $update ) use ( &$updates ) {
				$updates[] = $update;
			}
		);
		( new SchemaHooks() )->onLoadExtensionSchemaUpdates( $updater );
		$this->assertNotEmpty( $updates );
		foreach ( $updates as $params ) {
			$callback = array_shift( $params );
			$callback( $updater, ...$params );
		}
	}

	public function testUpdateCreatesTheSystemUser() {
		$this->assertFalse( User::newFromName( ModerationLogger::SYSTEM_USER )->isRegistered() );
		$updater = $this->createMock( DatabaseUpdater::class );
		$updater->method( 'getDB' )->willReturn( $this->getDb() );
		$this->runUpdates( $updater );

		$systemUser = User::newFromName( ModerationLogger::SYSTEM_USER );
		$this->assertTrue( $systemUser->isRegistered() );
		$this->assertTrue( $systemUser->isSystemUser() );
	}
}
