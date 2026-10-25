<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Query;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Query\CommentQueryService;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Page\ProperPageIdentity;
use MediaWikiIntegrationTestCase;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * After about 1075 half-lives the decay factor 0.5 ^ ( age / half-life ) is 0, and before that it
 * loses precision, so the order of very old comments changes with the time. A short half-life and
 * an imported history reach that. The cached orders are used only well before it (HotRanker::
 * getStableUntil()), later the order is computed for the time asked for, as before.
 * (From a review's scratch test.)
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Query\CommentQueryService
 * @covers \MediaWiki\Extension\GlowThread\Query\HotRanker
 * @covers \MediaWiki\Extension\GlowThread\Query\ListCache
 */
class DecayUnderflowTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;

	/** Seconds of one half-life, with $wgGlowThreadHotHalfLife = 0.01 */
	private const HALF_LIFE = 864;

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
	 * Comment A with one like, and B half a half-life newer without likes: A ranks first while
	 * both factors are above zero
	 *
	 * @return array{0:ProperPageIdentity,1:PostRecord,2:PostRecord}
	 */
	private function makePage(): array {
		$page = $this->gtPage( 'GlowThread underflow page' );
		$a = $this->gtPost( $this->gtUser(), $page, 'A, one like' );
		$this->now += self::HALF_LIFE / 2;
		$b = $this->gtPost( $this->gtUser(), $page, 'B, newer, no like' );
		$this->assertStatusGood( $this->gtService( 'ReactionService' )->setLike( $this->gtUser(), $a->getId(), true ) );
		return [ $page, $a, $b ];
	}

	/**
	 * The time when $post is $halfLives half-lives old
	 */
	private function when( PostRecord $post, float $halfLives ): float {
		return (int)ConvertibleTimestamp::convert( TS_UNIX, $post->getCreated() ) + $halfLives * self::HALF_LIFE + 0.5;
	}

	/**
	 * The list of a logged-in reader, whose first list makes the summary that the later ones use
	 *
	 * @param CommentQueryService|BaseCommentQueryService $query
	 * @param ProperPageIdentity $page
	 * @param string $sort
	 * @param int $limit
	 * @return array
	 */
	private function readerList( $query, ProperPageIdentity $page, string $sort = 'hot', int $limit = 20 ): array {
		return $query->getPageList(
			$query->newViewer( $this->getTestUser()->getUser() ), $page, $sort, null, $limit
		);
	}

	/**
	 * @param CommentQueryService|BaseCommentQueryService $query
	 * @param ProperPageIdentity $page
	 * @return int[]
	 */
	private function order( $query, ProperPageIdentity $page ): array {
		return array_column( $this->readerList( $query, $page )['comments'], 'id' );
	}

	public function testHotOrderAfterUnderflow() {
		[ $page, $a, $b ] = $this->makePage();
		$this->now = $this->when( $a, 1074.9 );
		$this->nextRequest();
		$cachedAt = $this->order( $this->gtService( 'CommentQueryService' ), $page );
		$this->assertSame( $this->order( $this->gtBaseQueryService(), $page ), $cachedAt, 'at summary time' );
		$this->assertSame( [ $a->getId(), $b->getId() ], $cachedAt );

		// Five minutes later, no write: A's factor underflowed to 0, B's did not
		$this->now += 300;
		$this->nextRequest();
		$fromDatabase = $this->order( $this->gtBaseQueryService(), $page );
		$this->assertSame( [ $b->getId(), $a->getId() ], $fromDatabase );
		$this->assertSame( $fromDatabase, $this->order( $this->gtService( 'CommentQueryService' ), $page ) );
	}

	public function testPopularAfterUnderflow() {
		$this->overrideConfigValue( 'GlowThreadPopularCount', 2 );
		[ $page, $a, $b ] = $this->makePage();
		$this->assertStatusGood( $this->gtService( 'ReactionService' )->setLike( $this->gtUser(), $a->getId(), true ) );
		$this->assertStatusGood( $this->gtService( 'ReactionService' )->setLike( $this->gtUser(), $b->getId(), true ) );
		$this->now += 60;
		// A third comment fills the first page of new sort, so the popular block shows
		$this->gtPost( $this->gtUser(), $page, 'C, newest' );
		$popular = fn ( $query ) => array_column( $this->readerList( $query, $page, 'new', 1 )['popular'], 'id' );

		$this->now = $this->when( $a, 1074.9 );
		$this->nextRequest();
		$this->assertSame( [ $a->getId(), $b->getId() ], $popular( $this->gtService( 'CommentQueryService' ) ) );
		$this->now += 300;
		$this->nextRequest();
		$fromDatabase = $popular( $this->gtBaseQueryService() );
		$this->assertSame( [ $b->getId(), $a->getId() ], $fromDatabase );
		$this->assertSame( $fromDatabase, $popular( $this->gtService( 'CommentQueryService' ) ) );
	}

	/**
	 * An anonymous list made from the popular picks of the summary lasts only until they may
	 * change, and is not kept after.
	 */
	public function testAnonymousListFromTheSummaryIsNotKeptPastThePopularStableTime() {
		$this->overrideConfigValue( 'GlowThreadPopularCount', 2 );
		[ $page, $a, $b ] = $this->makePage();
		$this->assertStatusGood( $this->gtService( 'ReactionService' )->setLike( $this->gtUser(), $a->getId(), true ) );
		$this->assertStatusGood( $this->gtService( 'ReactionService' )->setLike( $this->gtUser(), $b->getId(), true ) );
		$this->now += 60;
		// A third comment fills the first page of new sort, so the popular block shows
		$this->gtPost( $this->gtUser(), $page, 'C, newest' );
		$computed = 0;
		$get = function () use ( $page, &$computed ) {
			return $this->gtService( 'ListCache' )->getAnonResponse(
				$page->getId(),
				'new',
				[],
				function () use ( $page, &$computed ) {
					$computed++;
					$query = $this->gtService( 'CommentQueryService' );
					$anon = $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' );
					$data = $query->getPageList( $query->newViewer( $anon ), $page, 'new', null, 1, $facts );
					return [ $data, $facts ];
				},
				'json_encode',
				false
			);
		};
		// Half a half-life (432 seconds) before A reaches 1000 half-lives, a logged-in reader's
		// list makes the summary
		$this->now = $this->when( $a, 999.5 );
		$this->nextRequest();
		$this->readerList( $this->gtService( 'CommentQueryService' ), $page );
		$this->nextRequest();
		$get();
		$this->now += 300;
		$this->nextRequest();
		$get();
		$this->assertSame( 1, $computed, 'kept until then' );
		$this->now += 200;
		$this->nextRequest();
		$get();
		$this->assertSame( 2, $computed, 'computed again after it' );
	}

	/**
	 * The cached anonymous list lasts only until the order may change, and is not kept after.
	 */
	public function testAnonymousListIsNotKeptPastTheStableTime() {
		[ $page, $a ] = $this->makePage();
		$computed = 0;
		$get = function () use ( $page, &$computed ) {
			return $this->gtService( 'ListCache' )->getAnonResponse(
				$page->getId(),
				'hot',
				[],
				function () use ( $page, &$computed ) {
					$computed++;
					$query = $this->gtService( 'CommentQueryService' );
					$anon = $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' );
					$data = $query->getPageList( $query->newViewer( $anon ), $page, 'hot', null, 20, $users );
					return [ $data, $users ];
				},
				'json_encode',
				false
			);
		};
		// Half a half-life (432 seconds) before the limit of 1000 half-lives
		$this->now = $this->when( $a, 999.5 );
		$this->nextRequest();
		$get();
		$this->now += 300;
		$this->nextRequest();
		$get();
		$this->assertSame( 1, $computed, 'kept until the limit' );
		$this->now += 200;
		$this->nextRequest();
		$get();
		$this->assertSame( 2, $computed, 'computed again after it' );
		$this->now += 60;
		$this->nextRequest();
		$get();
		$this->assertSame( 3, $computed, 'and not kept from then on' );
	}
}
