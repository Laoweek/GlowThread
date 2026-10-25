<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration;

use MediaWiki\Cache\HTMLCacheUpdater;
use MediaWiki\Extension\GlowThread\Cdn\CdnPurger;
use MediaWikiIntegrationTestCase;

/**
 * Every write that changes what anonymous readers see purges both canonical first-page URLs,
 * except likes, whose counts may lag by up to the CDN max age.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Cdn\CdnPurger
 */
class CdnPurgeTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;

	/** @var string[][] */
	private array $purged = [];

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
		$this->overrideConfigValues( [
			'InternalServer' => 'http://internal.example',
			'RestPath' => '/w/rest.php',
		] );
		$updater = $this->createMock( HTMLCacheUpdater::class );
		$updater->method( 'purgeUrls' )->willReturnCallback( function ( $urls ) {
			$this->purged[] = (array)$urls;
		} );
		$this->setService( 'GlowThread.CdnPurger', static fn ( $services ) => new CdnPurger(
			$services->get( 'GlowThread.Config' ), $services->getUrlUtils(), $updater,
			$services->getMainWANObjectCache()
		) );
	}

	public function testUrlForm() {
		$purger = $this->gtService( 'CdnPurger' );
		$this->assertSame( [
			'hot' => '/w/rest.php/glowthread/v1/page/42/comments?sort=hot',
			'new' => '/w/rest.php/glowthread/v1/page/42/comments?sort=new',
		], $purger->getListPaths( 42 ) );
		$this->assertSame( [
			'http://internal.example/w/rest.php/glowthread/v1/page/42/comments?sort=hot',
			'http://internal.example/w/rest.php/glowthread/v1/page/42/comments?sort=new',
		], $purger->getPurgeUrls( 42 ) );
	}

	public function testWritesPurge() {
		$page = $this->gtPage();
		$expected = [
			"http://internal.example/w/rest.php/glowthread/v1/page/{$page->getId()}/comments?sort=hot",
			"http://internal.example/w/rest.php/glowthread/v1/page/{$page->getId()}/comments?sort=new",
		];
		$post = $this->gtPost( $this->gtUser(), $page, 'x' );
		$this->assertSame( [ $expected ], $this->purged, 'new visible comment' );

		// Likes do not purge (a like and unlike would be two bans each time).
		// Like counts in the cached anonymous list may lag by up to $wgGlowThreadCdnMaxAge.
		$this->purged = [];
		$liker = $this->gtUser();
		$this->gtService( 'ReactionService' )->setLike( $liker, $post->getId(), true );
		$this->gtService( 'ReactionService' )->setLike( $liker, $post->getId(), false );
		$this->assertSame( [], $this->purged, 'like and unlike' );

		$this->purged = [];
		$this->gtService( 'ReportService' )->report( $this->gtUser(), $post->getId(), 'spam', '' );
		$this->assertSame( [], $this->purged, 'a report alone changes nothing readers see' );

		$this->purged = [];
		$this->gtModerate( 'delete', [ $post ] );
		$this->assertSame( [ $expected ], $this->purged, 'deletion' );

		$this->purged = [];
		$this->gtSetPageState( $page, 'locked' );
		$this->assertContains( $expected, $this->purged, 'page state' );
	}

	/**
	 * A hideuser block (placed, changed or lifted) purges the cached lists
	 * of the pages where the user commented, so the name does not stay in the cache.
	 */
	public function testHideUserBlockPurgesTheUsersPages() {
		$pageA = $this->gtPage( 'GlowThread hide A' );
		$pageB = $this->gtPage( 'GlowThread hide B' );
		$other = $this->gtPage( 'GlowThread hide other' );
		$user = $this->gtUser();
		$this->gtPost( $user, $pageA, 'a' );
		$this->gtPost( $user, $pageB, 'b' );
		$this->gtPost( $this->gtUser(), $other, 'not theirs' );
		$listUrl = static fn ( $page ) =>
			"http://internal.example/w/rest.php/glowthread/v1/page/{$page->getId()}/comments?sort=hot";
		$suppressor = $this->getTestUser( [ 'sysop', 'suppress' ] )->getAuthority();

		$this->purged = [];
		$this->assertStatusGood( $this->getServiceContainer()->getBlockUserFactory()->newBlockUser(
			$user, $suppressor, 'infinity', '', [ 'isHideUser' => true, 'isAutoblocking' => false ]
		)->placeBlock() );
		$purged = array_merge( ...$this->purged );
		$this->assertContains( $listUrl( $pageA ), $purged );
		$this->assertContains( $listUrl( $pageB ), $purged );
		$this->assertNotContains( $listUrl( $other ), $purged );

		$this->purged = [];
		$this->assertStatusGood( $this->getServiceContainer()->getUnblockUserFactory()
			->newUnblockUser( $user, $suppressor, '' )->unblock() );
		$this->assertContains( $listUrl( $pageA ), array_merge( ...$this->purged ) );

		// An ordinary block hides nothing, so it purges nothing
		$this->purged = [];
		$this->gtBlockSitewide( $user );
		$this->assertSame( [], $this->purged );
	}

	public function testHeldCommentDoesNotPurge() {
		$this->gtSetFilter( 'holdme' );
		$page = $this->gtPage();
		$this->purged = [];
		$this->gtPost( $this->gtUser(), $page, 'holdme' );
		$this->assertSame( [], $this->purged );
	}
}
