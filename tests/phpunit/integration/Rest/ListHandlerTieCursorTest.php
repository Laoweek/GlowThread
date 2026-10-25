<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Rest;

use GuzzleHttp\Psr7\Uri;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\GlowThread\Query\Cursor;
use MediaWiki\Extension\GlowThread\Rest\ListHandler;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiIntegrationTestCase;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * The hot cursor of an anonymous first page computed by the request holds the time the page was
 * ranked for, as without the cache, also when the clock moves on to the next second during the
 * request. Two tied comments, at the end of the page, are ranked differently from one second to
 * the next: a cursor of the later second would show one of them twice and the other never.
 * (From a review's scratch test.)
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Rest\ListHandler
 * @covers \MediaWiki\Extension\GlowThread\Query\ListCache
 */
class ListHandlerTieCursorTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;
	use HandlerTestTrait;

	/** Seconds of one half-life, with $wgGlowThreadHotHalfLife = 0.01 */
	private const HALF_LIFE = 864;

	/** The second of the clock until $switchAt reads of it, the next one after */
	private int $second = 0;

	private int $reads = 0;

	private int $switchAt = PHP_INT_MAX;

	private float $cacheNow = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
		$this->overrideConfigValues( [
			'GlowThreadHotHalfLife' => 0.01,
			'GlowThreadPopularCount' => 0,
		] );
		// Behind the real clock, see ListCacheTest
		$this->second = intdiv( time(), 86400 ) * 86400 - 60 * 86400;
		ConvertibleTimestamp::setFakeTime( function () {
			return $this->reads++ < $this->switchAt ? $this->second : $this->second + 1;
		} );
		$this->cacheNow = $this->second + 0.5;
		$wanCache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$wanCache->setMockTime( $this->cacheNow );
		$this->setService( 'MainWANObjectCache', $wanCache );
		// The check key of all pages exists on a running wiki: one made later counts as purged then
		$this->getServiceContainer()->get( 'GlowThread.ListCache' )->touchAll();
	}

	/**
	 * The anonymous hot list of one comment a page, through the route
	 */
	private function get( ProperPageIdentity $page, ?string $cursor = null ): array {
		$query = [ 'sort' => 'hot', 'limit' => 1 ] + ( $cursor ? [ 'cursor' => $cursor ] : [] );
		$purger = $this->gtService( 'CdnPurger' );
		$handler = ListHandler::factory(
			$this->gtService( 'Config' ),
			$purger,
			$this->gtService( 'ListCache' ),
			$this->gtService( 'PageEligibility' ),
			$this->getServiceContainer()->getTitleFormatter()
		);
		$response = $this->executeHandler(
			$handler,
			new RequestData( [
				'uri' => new Uri( $purger->getListRoutePath( $page->getId() ) . '?' . http_build_query( $query ) ),
				'pathParams' => [ 'pageId' => $page->getId() ],
				'queryParams' => $query,
				'headers' => [ 'Host' => $purger->getPurgeHost() ],
			] ),
			[], [], [], [],
			$this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' )
		);
		return json_decode( $response->getBody()->getContents(), true );
	}

	/**
	 * The first comment of the anonymous hot list ranked at $second, by the code before the cache
	 */
	private function firstAt( ProperPageIdentity $page, int $second ): int {
		$saved = [ $this->second, $this->switchAt ];
		[ $this->second, $this->switchAt ] = [ $second, PHP_INT_MAX ];
		$anon = $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' );
		$query = $this->gtBaseQueryService();
		$first = $query->getPageList( $query->newViewer( $anon ), $page, 'hot', null, 1 )['comments'][0]['id'];
		[ $this->second, $this->switchAt ] = $saved;
		return $first;
	}

	public function testCursorHoldsTheTimeOfTheRanking() {
		// B with one like, A a half-life newer without: tied
		$page = $this->gtPage( 'GlowThread tie cursor' );
		$b = $this->gtPost( $this->gtUser(), $page, 'B, older' );
		$this->second += self::HALF_LIFE;
		$this->gtPost( $this->gtUser(), $page, 'A, newer' );
		$this->assertStatusGood( $this->gtService( 'ReactionService' )->setLike( $this->gtUser(), $b->getId(), true ) );
		$this->second += 60;
		DeferredUpdates::doUpdates();
		// A second after which the order is the other one
		$at = $this->second;
		while ( $this->firstAt( $page, $at ) === $this->firstAt( $page, $at + 1 ) && $at < $this->second + 200 ) {
			$at++;
		}
		$this->assertNotSame( $this->firstAt( $page, $at ), $this->firstAt( $page, $at + 1 ), 'a change found' );

		// The clock moves on at every point of the request, in turn
		$times = [];
		for ( $switchAt = 1; $switchAt < 150; $switchAt++ ) {
			$this->resetServices();
			[ $this->second, $this->cacheNow, $this->reads, $this->switchAt ] = [ $at, $at + 0.5, 0, $switchAt ];
			$first = $this->get( $page );
			$shown = $first['comments'][0]['id'];
			$rankedAt = $shown === $this->firstAt( $page, $at ) ? $at : $at + 1;
			$cursor = Cursor::decode( $first['next'], 'hot', [ 'n' => 'int', 'o' => 'int' ] );
			$this->assertSame( $rankedAt, $cursor['n'], "clock moved after $switchAt reads" );
			$times[$rankedAt] = true;

			// The next page shows the other comment, as without the cache
			$this->resetServices();
			[ $this->second, $this->switchAt ] = [ $at + 5, PHP_INT_MAX ];
			$second = $this->get( $page, $first['next'] );
			$this->assertNotSame( $shown, $second['comments'][0]['id'], "clock moved after $switchAt reads" );
		}
		$this->assertCount( 2, $times, 'ranked at both seconds' );
	}
}
