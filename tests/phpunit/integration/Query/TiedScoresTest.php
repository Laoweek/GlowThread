<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Query;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\GlowThread\Query\CommentQueryService;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWikiIntegrationTestCase;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Two comments whose scores are equal (the ratio of their likes, or likes + 1, is 2 and they are
 * one half-life apart) come out in either order, depending on the rounding at each second. The
 * cached orders are not used for them: they are ranked for the time asked for, as before.
 * (From a review.)
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Query\CommentQueryService
 * @covers \MediaWiki\Extension\GlowThread\Query\HotRanker
 * @covers \MediaWiki\Extension\GlowThread\Query\ListCache
 */
class TiedScoresTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;

	/** Seconds of one half-life, with $wgGlowThreadHotHalfLife = 0.01 */
	private const HALF_LIFE = 864;

	/** Seconds compared: the order changes several times within them */
	private const SECONDS = 40;

	private float $now;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
		$this->overrideConfigValues( [
			'GlowThreadHotHalfLife' => 0.01,
			'GlowThreadPopularCount' => 0,
			'GlowThreadPopularMinLikes' => 1,
		] );
		// Behind the real clock and half a second in, see ListCacheTest
		$this->now = intdiv( time(), 86400 ) * 86400 - 60 * 86400 + 0.5;
		ConvertibleTimestamp::setFakeTime( fn () => (int)$this->now );
		$wanCache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$wanCache->setMockTime( $this->now );
		$this->setService( 'MainWANObjectCache', $wanCache );
		// The check key of all pages exists on a running wiki: one made later counts as purged then
		$this->getServiceContainer()->get( 'GlowThread.ListCache' )->touchAll();
	}

	/**
	 * End the request (its updates after the response) and start the next one
	 */
	private function nextRequest(): void {
		DeferredUpdates::doUpdates();
		$this->resetServices();
	}

	/**
	 * Comment B with $likes likes, and A one half-life newer with one like less: tied in hot sort
	 * for $likes = 1 and in the popular block for $likes = 2. Then a minute passes, past the
	 * hold-off of the purges.
	 */
	private function makePage( int $likes ): ProperPageIdentity {
		$page = $this->gtPage( 'GlowThread tie page' );
		$b = $this->gtPost( $this->gtUser(), $page, 'B, older' );
		$this->now += self::HALF_LIFE;
		$a = $this->gtPost( $this->gtUser(), $page, 'A, newer' );
		foreach ( [ [ $b, $likes ], [ $a, $likes - 1 ] ] as [ $post, $count ] ) {
			for ( $i = 0; $i < $count; $i++ ) {
				$this->assertStatusGood(
					$this->gtService( 'ReactionService' )->setLike( $this->gtUser(), $post->getId(), true )
				);
			}
		}
		$this->now += 60;
		$this->nextRequest();
		return $page;
	}

	/**
	 * @param CommentQueryService|BaseCommentQueryService $query
	 * @param ProperPageIdentity $page
	 * @param Authority $reader
	 * @param string $part 'comments' or 'popular'
	 * @param string $sort
	 * @param int $limit
	 * @return int[] The IDs in that part of the list
	 */
	private function ids(
		$query, ProperPageIdentity $page, Authority $reader, string $part, string $sort, int $limit
	): array {
		$list = $query->getPageList( $query->newViewer( $reader ), $page, $sort, null, $limit );
		return array_column( $list[$part], 'id' );
	}

	/**
	 * Anonymous first pages through ListCache, one a second: each the list of the code before the
	 * cache at that second, both orders seen, and none kept
	 */
	private function assertAnonymousNeverKept(
		ProperPageIdentity $page, string $sort, int $limit, string $part
	): void {
		$computed = 0;
		$seen = [];
		$anon = $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' );
		for ( $i = 0; $i < self::SECONDS; $i++ ) {
			$body = $this->gtService( 'ListCache' )->getAnonResponse(
				$page->getId(),
				$sort,
				[ $limit ],
				function () use ( $page, $anon, $sort, $limit, &$computed ) {
					$computed++;
					$query = $this->gtService( 'CommentQueryService' );
					$data = $query->getPageList( $query->newViewer( $anon ), $page, $sort, null, $limit, $facts );
					return [ $data, $facts ];
				},
				'json_encode',
				false
			)['body'];
			$ids = array_column( json_decode( $body, true )[$part], 'id' );
			$this->assertSame(
				$this->ids( $this->gtBaseQueryService(), $page, $anon, $part, $sort, $limit ), $ids, "at +$i s"
			);
			$seen[implode( ',', $ids )] = true;
			$this->now += 1;
			$this->nextRequest();
		}
		$this->assertCount( 2, $seen, 'both orders seen' );
		$this->assertSame( self::SECONDS, $computed, 'never kept' );
	}

	/**
	 * @dataProvider provideLimits
	 */
	public function testHotOrderOfTiedComments( int $limit ) {
		$page = $this->makePage( 1 );
		$reader = $this->gtUser();
		$orders = [];
		for ( $i = 0; $i < self::SECONDS; $i++ ) {
			// The first list makes the summary, the others read it
			$order = $this->ids( $this->gtService( 'CommentQueryService' ), $page, $reader, 'comments', 'hot', $limit );
			$this->assertSame(
				$this->ids( $this->gtBaseQueryService(), $page, $reader, 'comments', 'hot', $limit ),
				$order,
				"at +$i s"
			);
			$orders[implode( ',', $order )] = true;
			$this->now += 1;
			$this->nextRequest();
		}
		$this->assertCount( 2, $orders, 'both orders seen' );
	}

	/**
	 * The two tied comments in the page (limit 20) or at its end (limit 1, the second one is the
	 * first of the next page)
	 */
	public static function provideLimits() {
		return [ 'tie in the page' => [ 20 ], 'tie at its end' => [ 1 ] ];
	}

	/**
	 * @dataProvider provideAnonymousLists
	 */
	public function testAnonymousListOfTiedCommentsIsNotKept( bool $fromSummary, int $limit ) {
		$page = $this->makePage( 1 );
		if ( $fromSummary ) {
			// A logged-in reader's list makes the summary, from which the anonymous lists come
			$this->ids( $this->gtService( 'CommentQueryService' ), $page, $this->gtUser(), 'comments', 'hot', 20 );
			$this->nextRequest();
			$this->assertNotNull(
				$this->gtService( 'ListCache' )->getSummary( $page->getId(), static fn () => null, false )
			);
		}
		$this->assertAnonymousNeverKept( $page, 'hot', $limit, 'comments' );
	}

	public static function provideAnonymousLists() {
		return [
			'from the database, tie in the page' => [ false, 20 ],
			'from the database, tie at its end' => [ false, 1 ],
			'from the summary, tie in the page' => [ true, 20 ],
			'from the summary, tie at its end' => [ true, 1 ],
		];
	}

	public function testAnonymousPopularPicksOfTiedCommentsAreNotKept() {
		$this->overrideConfigValue( 'GlowThreadPopularCount', 1 );
		$page = $this->makePage( 2 );
		// A third comment fills the first page of new sort, so the popular block shows
		$this->gtPost( $this->gtUser(), $page, 'C, newest' );
		$this->now += 60;
		$this->nextRequest();
		$this->assertAnonymousNeverKept( $page, 'new', 1, 'popular' );
	}

	public function testPopularPicksOfTiedComments() {
		$this->overrideConfigValue( 'GlowThreadPopularCount', 1 );
		$page = $this->makePage( 2 );
		// A third comment fills the first page of new sort, so the popular block shows
		$this->gtPost( $this->gtUser(), $page, 'C, newest' );
		$this->now += 60;
		$this->nextRequest();
		$reader = $this->gtUser();
		// The first page of hot sort makes the summary, with its picks
		$this->ids( $this->gtService( 'CommentQueryService' ), $page, $reader, 'comments', 'hot', 20 );
		$picks = [];
		for ( $i = 0; $i < self::SECONDS; $i++ ) {
			$pick = $this->ids( $this->gtService( 'CommentQueryService' ), $page, $reader, 'popular', 'new', 1 );
			$this->assertSame(
				$this->ids( $this->gtBaseQueryService(), $page, $reader, 'popular', 'new', 1 ),
				$pick,
				"at +$i s"
			);
			$picks[implode( ',', $pick )] = true;
			$this->now += 1;
			$this->nextRequest();
		}
		$this->assertCount( 2, $picks, 'both picks seen' );
	}
}
