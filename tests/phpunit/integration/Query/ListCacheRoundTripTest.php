<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Query;

use GuzzleHttp\Psr7\Uri;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\GlowThread\Query\Cursor;
use MediaWiki\Extension\GlowThread\Rest\ListHandler;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiIntegrationTestCase;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * The object cache round trips of each kind of list request, reads and writes of ListCache's keys
 * (the code before the cache made none): a request that the cache cannot answer for a while makes
 * none after the first, and nothing is written during the hold-off of a purge. Each request counts
 * with its updates after the response. The web server's own cache (APCu on a wiki) lasts from
 * request to request, as it does on a server.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Query\ListCache
 * @covers \MediaWiki\Extension\GlowThread\Rest\ListHandler
 */
class ListCacheRoundTripTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;
	use HandlerTestTrait;

	/** Seconds of one half-life, with $wgGlowThreadHotHalfLife = 0.01 */
	private const HALF_LIFE = 864;

	private float $now;

	/** Backend of the object cache, counting the round trips for ListCache's keys */
	private HashBagOStuff $store;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
		$this->overrideConfigValues( [
			'GlowThreadHotHalfLife' => 0.01,
			'GlowThreadPopularCount' => 0,
		] );
		// Behind the real clock and half a second in, see ListCacheTest
		$this->now = intdiv( time(), 86400 ) * 86400 - 60 * 86400 + 0.5;
		ConvertibleTimestamp::setFakeTime( fn () => (int)$this->now );
		$this->store = new class extends HashBagOStuff {
			public int $reads = 0;
			public int $writes = 0;
			private int $depth = 0;

			/**
			 * Count a call for one of ListCache's keys, not the calls it makes itself
			 *
			 * @param string $counter
			 * @param string[] $keys
			 * @param callable $call
			 * @return mixed
			 */
			private function count( string $counter, array $keys, callable $call ) {
				if ( !$this->depth && preg_grep( '/glowthread-list/', $keys ) ) {
					$this->$counter++;
				}
				$this->depth++;
				try {
					return $call();
				} finally {
					$this->depth--;
				}
			}

			/** @inheritDoc */
			protected function doGet( $key, $flags = 0, &$casToken = null ) {
				return $this->count( 'reads', [ $key ], function () use ( $key, $flags, &$casToken ) {
					return parent::doGet( $key, $flags, $casToken );
				} );
			}

			/** @inheritDoc */
			protected function doGetMulti( array $keys, $flags = 0 ) {
				return $this->count( 'reads', $keys, fn () => parent::doGetMulti( $keys, $flags ) );
			}

			/** @inheritDoc */
			protected function doSet( $key, $value, $exptime = 0, $flags = 0 ) {
				return $this->count( 'writes', [ $key ], fn () => parent::doSet( $key, $value, $exptime, $flags ) );
			}

			/** @inheritDoc */
			protected function doAdd( $key, $value, $exptime = 0, $flags = 0 ) {
				return $this->count( 'writes', [ $key ], fn () => parent::doAdd( $key, $value, $exptime, $flags ) );
			}

			/** @inheritDoc */
			protected function doDelete( $key, $flags = 0 ) {
				return $this->count( 'writes', [ $key ], fn () => parent::doDelete( $key, $flags ) );
			}

			/** @inheritDoc */
			protected function doCas( $casToken, $key, $value, $exptime = 0, $flags = 0 ) {
				return $this->count(
					'writes', [ $key ], fn () => parent::doCas( $casToken, $key, $value, $exptime, $flags )
				);
			}
		};
		$wanCache = new WANObjectCache( [ 'cache' => $this->store ] );
		$wanCache->setMockTime( $this->now );
		$this->setService( 'MainWANObjectCache', $wanCache );
		// One server cache for all requests, as APCu on a web server
		$this->setService( 'LocalServerObjectCache', new HashBagOStuff() );
		// The check key of all pages exists on a running wiki: one made later counts as purged then
		$this->getServiceContainer()->get( 'GlowThread.ListCache' )->touchAll();
	}

	/**
	 * The round trips of one request and its updates after the response, in a new request's
	 * services
	 *
	 * @param callable $request
	 * @return array{0:int,1:int} Reads, writes
	 */
	private function roundTrips( callable $request ): array {
		DeferredUpdates::doUpdates();
		$this->resetServices();
		[ $reads, $writes ] = [ $this->store->reads, $this->store->writes ];
		$scope = DeferredUpdates::preventOpportunisticUpdates();
		$request();
		unset( $scope );
		DeferredUpdates::doUpdates();
		return [ $this->store->reads - $reads, $this->store->writes - $writes ];
	}

	/**
	 * A list request through the route
	 *
	 * @param ProperPageIdentity $page
	 * @param Authority|null $authority Null for an anonymous reader
	 * @param string|null $cursor
	 * @return array The response
	 */
	private function get( ProperPageIdentity $page, ?Authority $authority = null, ?string $cursor = null ): array {
		$query = [ 'sort' => 'hot' ] + ( $cursor ? [ 'cursor' => $cursor ] : [] );
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
			$authority ?? $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' )
		);
		$this->assertSame( 200, $response->getStatusCode() );
		return json_decode( $response->getBody()->getContents(), true );
	}

	/**
	 * A page with three comments, past the hold-off of their purges
	 */
	private function makePage(): ProperPageIdentity {
		$page = $this->gtPage( 'GlowThread round trips' );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->now += 60;
			$this->gtPost( $this->gtUser(), $page, "Comment $i" );
		}
		$this->now += 60;
		return $page;
	}

	public function testAnonymousFirstPage() {
		$page = $this->makePage();
		$this->assertSame( [ 2, 1 ], $this->roundTrips( fn () => $this->get( $page ) ),
			'a miss: the response, the summary (none), the response stored' );
		$this->assertSame( [ 1, 0 ], $this->roundTrips( fn () => $this->get( $page ) ), 'a hit' );

		$liker = $this->gtUser();
		$top = $this->get( $page )['comments'][0]['id'];
		$this->assertSame( [ 0, 1 ], $this->roundTrips( function () use ( $liker, $top ) {
			$this->assertStatusGood( $this->gtService( 'ReactionService' )->setLike( $liker, $top, true ) );
		} ), 'a write touches its check key once, when it commits' );

		$this->assertSame( [ 1, 0 ], $this->roundTrips( fn () => $this->get( $page ) ),
			'in the hold-off: the read that sees the purge, nothing stored' );
		$this->now += 5;
		$this->assertSame( [ 0, 0 ], $this->roundTrips( fn () => $this->get( $page ) ), 'then nothing' );

		$this->now += 60;
		$this->assertSame( [ 3, 1 ], $this->roundTrips( fn () => $this->get( $page ) ),
			'past it, a miss, whose response replaces the old one: read once more, after the response' );
		$this->assertSame( [ 1, 0 ], $this->roundTrips( fn () => $this->get( $page ) ), 'a hit' );
	}

	public function testAnonymousCursorPage() {
		$page = $this->makePage();
		$cursor = Cursor::encode( 'hot', [ 'n' => (int)$this->now, 'o' => 1 ] );
		$this->assertSame( [ 1, 0 ], $this->roundTrips( fn () => $this->get( $page, null, $cursor ) ),
			'finds no summary' );
		$this->assertSame( [ 0, 0 ], $this->roundTrips( fn () => $this->get( $page, null, $cursor ) ),
			'and does not look again for a while' );
		$this->now += 61;
		$this->assertSame( [ 1, 0 ], $this->roundTrips( fn () => $this->get( $page, null, $cursor ) ), 'then again' );

		// A logged-in reader makes the summary, which the cursor pages then read
		$reader = $this->gtUser();
		$this->assertSame( [ 1, 1 ], $this->roundTrips( fn () => $this->get( $page, $reader ) ) );
		$this->assertSame( [ 1, 0 ], $this->roundTrips( fn () => $this->get( $page, null, $cursor ) ) );
	}

	public function testFirstPageThatCannotBeKept() {
		// B with one like, A a half-life newer without: tied, an order for each time only
		$page = $this->gtPage( 'GlowThread round trips, tied' );
		$b = $this->gtPost( $this->gtUser(), $page, 'B' );
		$this->now += self::HALF_LIFE;
		$this->gtPost( $this->gtUser(), $page, 'A' );
		$this->assertStatusGood( $this->gtService( 'ReactionService' )->setLike( $this->gtUser(), $b->getId(), true ) );
		$this->now += 60;
		$this->assertSame( [ 2, 0 ], $this->roundTrips( fn () => $this->get( $page ) ),
			'the response, the summary (none), nothing stored' );
		$this->now += 1;
		$this->assertSame( [ 0, 0 ], $this->roundTrips( fn () => $this->get( $page ) ),
			'computed for a while without reading' );
		$this->now += 60;
		$this->assertSame( [ 2, 0 ], $this->roundTrips( fn () => $this->get( $page ) ), 'then looked at again' );
	}

	public function testLoggedIn() {
		$page = $this->makePage();
		$reader = $this->gtUser();
		$this->assertSame( [ 1, 1 ], $this->roundTrips( fn () => $this->get( $page, $reader ) ),
			'no summary: one made after the response' );
		$this->assertSame( [ 1, 0 ], $this->roundTrips( fn () => $this->get( $page, $reader ) ), 'the summary' );

		$this->gtPost( $this->gtUser(), $page, 'A purge' );
		$this->assertSame( [ 1, 0 ], $this->roundTrips( fn () => $this->get( $page, $reader ) ),
			'in the hold-off: the read that sees the purge, nothing made' );
		$this->now += 5;
		$this->assertSame( [ 0, 0 ], $this->roundTrips( fn () => $this->get( $page, $reader ) ), 'then nothing' );
		$this->now += 60;
		$this->assertSame( [ 2, 1 ], $this->roundTrips( fn () => $this->get( $page, $reader ) ),
			'made again, in place of the old one: read once more, after the response' );
	}
}
