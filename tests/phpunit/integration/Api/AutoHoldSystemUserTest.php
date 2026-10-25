<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Api;

use DatabaseLogEntry;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\GlowThread\Log\ModerationLogger;
use MediaWiki\Extension\GlowThread\Tests\Integration\ReporterTestTrait;
use MediaWiki\MainConfigNames;
use MediaWiki\Request\FauxRequest;
use MediaWiki\User\User;
use TestUser;

/**
 * Automatic holds are logged as the system user ModerationLogger::SYSTEM_USER. Anyone could
 * register that name first, and an account that had it was taken over: its password and email
 * removed and its sessions ended, and the holds were logged in its name. The name is reserved
 * now, and an account that has it anyway (registered before GlowThread was installed) is left
 * alone: the hold is logged as core's maintenance user.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Hooks\ReservedNamesHooks
 * @covers \MediaWiki\Extension\GlowThread\Log\ModerationLogger
 */
class AutoHoldSystemUserTest extends GlowThreadApiTestCase {
	use ReporterTestTrait;

	public function testSystemUserNameIsReserved() {
		$userNameUtils = $this->getServiceContainer()->getUserNameUtils();
		$this->assertFalse( $userNameUtils->isUsable( ModerationLogger::SYSTEM_USER ) );
	}

	public function testAccountWithTheNameIsNotTakenOver() {
		$owner = ( new TestUser( ModerationLogger::SYSTEM_USER, '', 'owner@example.org' ) )->getUser();
		$password = fn () => $this->getDb()->newSelectQueryBuilder()
			->select( 'user_password' )->from( 'user' )->where( [ 'user_id' => $owner->getId() ] )
			->caller( __METHOD__ )->fetchField();
		$passwordBefore = $password();
		$this->overrideConfigValue( 'GlowThreadAutoHoldReporters', 1 );
		$post = $this->gtPost( $this->gtUser(), $this->gtPage(), 'x' );
		$this->assertStatusGood( $this->gtService( 'ReportService' )->report(
			$this->gtEstablishedReporter(), $post->getId(), 'spam', ''
		) );
		$this->assertTrue( $this->gtReload( $post )->isHeld(), 'the hold still works' );

		$rows = $this->gtLogRows( 'glowthread', 'autohold' );
		$this->assertCount( 1, $rows );
		$performer = DatabaseLogEntry::newFromId( (int)$rows[0]->log_id, $this->getDb() )->getPerformerIdentity();
		$this->assertSame( User::MAINTENANCE_SCRIPT_USER, $performer->getName() );
		$this->assertNotSame( $owner->getId(), $performer->getId() );
		$this->assertSame( $passwordBefore, $password(), 'the password is kept' );
		$stored = User::newFromId( $owner->getId() );
		$this->assertSame( 'owner@example.org', $stored->getEmail() );
		$this->assertFalse( $stored->isSystemUser() );
	}

	/**
	 * The hold is logged in the request of the report that caused it. Its RecentChanges row
	 * stored that reporter's IP address under the system user (rc_ip), which CheckUser's
	 * populateCheckUserTable.php copies. It carries 127.0.0.1 now, as CheckUser records it.
	 */
	public function testRecentChangesRowCarriesNoReporterIp() {
		$this->overrideConfigValues( [ MainConfigNames::PutIPinRC => true, 'GlowThreadAutoHoldReporters' => 1 ] );
		$request = new FauxRequest();
		$request->setIP( '198.51.100.77' );
		// Also $wgRequest, which RecentChange reads on MediaWiki 1.43
		$this->setRequest( $request );
		$post = $this->gtPost( $this->gtUser(), $this->gtPage(), 'x' );
		$this->assertStatusGood( $this->gtService( 'ReportService' )->report(
			$this->gtEstablishedReporter(), $post->getId(), 'spam', ''
		) );
		$this->gtModerate( 'approve', [ $post ] );
		DeferredUpdates::doUpdates();

		$ips = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'rc_log_action', 'rc_ip' ] )
			->from( 'recentchanges' )
			->where( [ 'rc_log_type' => ModerationLogger::TYPE, 'rc_log_action' => [ 'autohold', 'approve' ] ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$byAction = [];
		foreach ( $ips as $row ) {
			$byAction[$row->rc_log_action] = $row->rc_ip;
		}
		ksort( $byAction );
		$this->assertSame( [ 'approve' => '198.51.100.77', 'autohold' => '127.0.0.1' ], $byAction );
	}
}
