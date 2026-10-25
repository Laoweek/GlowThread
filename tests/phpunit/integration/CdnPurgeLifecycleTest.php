<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration;

use MediaWiki\Cache\HTMLCacheUpdater;
use MediaWiki\Extension\GlowThread\Cdn\CdnPurger;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * Purges that close gaps found by the security review of 2026-10-10: list purges ask for core's
 * rebound purge (I14), and a page move purges the page's lists (I16).
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Cdn\CdnPurger
 * @covers \MediaWiki\Extension\GlowThread\Hooks\PageLifecycleHooks
 * @covers \MediaWiki\Extension\GlowThread\Service\PageLifecycleService
 */
class CdnPurgeLifecycleTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;

	/** @var array[] Arguments of each HTMLCacheUpdater::purgeUrls() call */
	private array $calls = [];

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
		$this->overrideConfigValues( [
			'InternalServer' => 'http://internal.example',
			'RestPath' => '/w/rest.php',
		] );
		$updater = $this->createMock( HTMLCacheUpdater::class );
		$updater->method( 'purgeUrls' )->willReturnCallback( function ( ...$args ) {
			$this->calls[] = $args;
		} );
		$this->setService( 'GlowThread.CdnPurger', static fn ( $services ) => new CdnPurger(
			$services->get( 'GlowThread.Config' ), $services->getUrlUtils(), $updater,
			$services->getMainWANObjectCache()
		) );
	}

	/** @return string[] */
	private function purgedUrls(): array {
		return array_merge( [], ...array_map( static fn ( $args ) => (array)$args[0], $this->calls ) );
	}

	/**
	 * With $wgCdnReboundPurgeDelay set, core purges the lists again after that delay, so a list
	 * that a lagging replica answered right after the change does not stay cached.
	 */
	public function testListPurgesAskForReboundPurge() {
		$page = $this->gtPage( 'GlowThread rebound purge' );
		$user = $this->gtUser();
		$post = $this->gtPost( $user, $page, 'x' );
		$this->gtModerate( 'delete', [ $post ] );
		$this->gtSetPageState( $page, 'locked' );
		$this->assertStatusGood( $this->getServiceContainer()->getBlockUserFactory()->newBlockUser(
			$user,
			$this->getTestUser( [ 'sysop', 'suppress' ] )->getAuthority(),
			'infinity',
			'',
			[ 'isHideUser' => true, 'isAutoblocking' => false ]
		)->placeBlock() );

		$this->assertGreaterThanOrEqual( 4, count( $this->calls ) );
		foreach ( $this->calls as $args ) {
			$flags = $args[1] ?? null;
			$this->assertSame( HTMLCacheUpdater::PURGE_INTENT_TXROUND_REFLECTED, $flags, json_encode( $args ) );
		}
	}

	/**
	 * The new title may be in a namespace that anonymous readers cannot read, so the cached
	 * lists of the moved page must go.
	 */
	public function testMovePurgesTheList() {
		$page = $this->gtPage( 'GlowThread moved page' );
		$this->gtPost( $this->gtUser(), $page, 'x' );

		$this->calls = [];
		$target = Title::makeTitle( NS_PROJECT, 'GlowThread moved target' );
		$this->assertStatusGood( $this->getServiceContainer()->getMovePageFactory()
			->newMovePage( Title::newFromPageIdentity( $page ), $target )
			->move( $this->getTestSysop()->getUser(), 'test', false ) );

		$this->assertSame( $this->gtService( 'CdnPurger' )->getPurgeUrls( $page->getId() ), $this->purgedUrls() );
	}
}
