<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Query;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Model\PostStatus;
use MediaWiki\Extension\GlowThread\Query\CommentQueryService;
use MediaWiki\Extension\GlowThread\Store\PostStore;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWikiIntegrationTestCase;
use Psr\Log\AbstractLogger;
use ReflectionProperty;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * The read routes run a fixed number of GlowThread queries, however many comments a page has:
 * every lookup is batched for the whole response. Queries of the cached page state and settings
 * are left out, as they depend on the object cache. Lists are counted twice: with the page
 * summary to make (ListCache), and with the summary cached.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Query\CommentQueryService
 * @covers \MediaWiki\Extension\GlowThread\Query\CommentSerializer
 */
class ReadQueryCountTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;

	/** Callers whose queries depend on the object cache */
	private const CACHED = [
		'MediaWiki\Extension\GlowThread\Store\PageStateStore::get',
		'MediaWiki\Extension\GlowThread\Store\SettingsStore::load',
	];

	/** Time of the object cache */
	private float $now;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
		// An object cache that lasts across the requests of a test, on a clock the test moves.
		// The clock starts an hour back: the cache refuses values read (by the real clock) after
		// its own time.
		$this->now = microtime( true ) - 3600;
		ConvertibleTimestamp::setFakeTime( fn () => (int)$this->now );
		$wanCache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$wanCache->setMockTime( $this->now );
		$this->setService( 'MainWANObjectCache', $wanCache );
		// The check key of all pages exists on a running wiki: one made later counts as purged then
		$this->getServiceContainer()->get( 'GlowThread.ListCache' )->touchAll();
	}

	/**
	 * GlowThread's queries while $callback runs, by caller, in the services' fresh state (no
	 * names or mentions remembered from earlier calls).
	 *
	 * @param callable $callback
	 * @return string[] Caller of each query, short class name and method
	 */
	private function gtQueries( callable $callback ): array {
		// The previous request ends: its updates after the response store what it computed
		DeferredUpdates::doUpdates();
		$this->resetServices();
		// As in a web request, the updates after the response wait for its end, also in the CLI
		$scope = DeferredUpdates::preventOpportunisticUpdates();
		return $this->recordQueries( fn () => $callback( $this->gtService( 'CommentQueryService' ) ) );
	}

	/**
	 * GlowThread's queries in the updates after the response of the last request (gtQueries())
	 *
	 * @return string[] As gtQueries()
	 */
	private function gtPostSendQueries(): array {
		// Without the transaction round of the update itself, named after it
		return array_values( array_filter(
			$this->recordQueries( static fn () => DeferredUpdates::doUpdates() ),
			static fn ( $caller ) => $caller !== 'ListCache->store'
		) );
	}

	/**
	 * @param callable $callback
	 * @return string[] As gtQueries()
	 */
	private function recordQueries( callable $callback ): array {
		$conn = $this->getServiceContainer()->getDBLoadBalancerFactory()->getMainLB()
			->getConnectionInternal( DB_REPLICA );
		$property = new ReflectionProperty( $conn, 'logger' );
		$previous = $property->getValue( $conn );
		$logger = new class extends AbstractLogger {
			/** @var string[] */
			public array $callers = [];

			/** @inheritDoc */
			public function log( $level, $message, array $context = [] ) {
				$method = $context['method'] ?? '';
				if ( ( $context['db_log_category'] ?? '' ) === 'query'
					&& str_starts_with( $method, 'MediaWiki\Extension\GlowThread\\' )
				) {
					$this->callers[] = $method;
				}
			}
		};
		$conn->setLogger( $logger );
		$conn->setFlag( DBO_DEBUG );
		try {
			$callback();
		} finally {
			$conn->clearFlag( DBO_DEBUG );
			$conn->setLogger( $previous );
		}
		$callers = array_values( array_diff( $logger->callers, self::CACHED ) );
		return array_map( static fn ( $c ) => substr( $c, strrpos( $c, '\\' ) + 1 ), $callers );
	}

	private function anon(): Authority {
		return $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' );
	}

	/**
	 * A page with $threads top-level comments, each with a liked reply and a reply to that reply.
	 *
	 * @param int $threads
	 * @param string $title
	 * @return array{page:ProperPageIdentity,top:PostRecord,reply:PostRecord,deep:PostRecord}
	 */
	private function makePage( int $threads, string $title = 'GlowThread test page' ): array {
		$page = $this->gtPage( $title );
		$users = [ $this->gtUser(), $this->gtUser(), $this->gtUser() ];
		$reactions = $this->gtService( 'ReactionService' );
		for ( $i = 0; $i < $threads; $i++ ) {
			$top = $this->gtPost( $users[$i % 3], $page, "top $i" );
			$reply = $this->gtPost( $users[( $i + 1 ) % 3], $page, "reply $i", $top );
			$deep = $this->gtPost( $users[( $i + 2 ) % 3], $page, "deep $i", $reply );
			$this->assertStatusGood( $reactions->setLike( $users[$i % 3], $reply->getId(), true ) );
			$this->assertStatusGood( $reactions->setLike( $users[( $i + 2 ) % 3], $reply->getId(), true ) );
		}
		return [ 'page' => $page, 'top' => $top, 'reply' => $reply, 'deep' => $deep ];
	}

	public function testListQueriesDoNotGrowWithThePage() {
		$this->overrideConfigValue( 'GlowThreadPopularMinLikes', 2 );
		$small = $this->makePage( 2, 'GlowThread small page' )['page'];
		$big = $this->makePage( 25, 'GlowThread busy page' )['page'];
		// Past the hold-off of the purges the posts made
		$this->now += 60;
		// The page summary: the count, the top-level comments (pinned and hot candidates in one
		// scan), the popular picks
		$summary = [
			'CommentQueryService::countVisible',
			'CommentQueryService::scanTopLevel',
			'CommentQueryService::pickPopular',
			'PostStore::getVisibleIds',
		];
		// The comments shown: pinned, hot and popular ones at once, then two levels of replies
		$list = [
			'PostStore::getByIds',
			'CommentQueryService::fetchChildren',
			'CommentQueryService::fetchChildren',
			'ActorInfoLookup::getInfo',
		];
		$reader = $this->gtUser();
		foreach ( [ $small, $big ] as $page ) {
			// A logged-in reader's list makes the summary, after the response
			$this->gtQueries( static function ( CommentQueryService $q ) use ( $page, $reader ) {
				$q->getPageList( $q->newViewer( $reader ), $page, 'hot', null, 20 );
			} );
			$this->assertSame( $summary, $this->gtPostSendQueries(), 'summary made' );
			$this->assertSame( $list, $this->gtQueries( function ( CommentQueryService $q ) use ( $page ) {
				$q->getPageList( $q->newViewer( $this->anon() ), $page, 'hot', null, 20 );
			} ), 'summary cached' );
		}
	}

	/**
	 * A list that the cache does not answer runs no more queries than the code before the cache
	 * (BaseCommentQueryService): an anonymous miss never makes a summary, a logged-in reader's
	 * request makes it after the response, and while a purge is in its hold-off none is made (it
	 * could not be stored).
	 */
	public function testMissesQueryNoMoreThanBefore() {
		$this->overrideConfigValue( 'GlowThreadPopularMinLikes', 2 );
		$page = $this->makePage( 25, 'GlowThread busy page' )['page'];
		$user = $this->gtUser();
		$cache = fn () => $this->gtService( 'ListCache' );
		// Hot sort shows the popular picks among its replies, new sort with limit 1 does not
		foreach ( [ 'hot' => 20, 'new' => 1 ] as $sort => $limit ) {
			$this->now += 60;
			$list = static function ( $query, $authority ) use ( $page, $sort, $limit ) {
				$query->getPageList( $query->newViewer( $authority ), $page, $sort, null, $limit );
			};
			$anonymousMiss = function () use ( $cache, $page, $sort, $limit ) {
				$cache()->getAnonResponse(
					$page->getId(),
					$sort,
					// A key of its own each time: never cached
					[ mt_rand() ],
					function () use ( $page, $sort, $limit ) {
						$query = $this->gtService( 'CommentQueryService' );
						$viewer = $query->newViewer( $this->anon() );
						$data = $query->getPageList( $viewer, $page, $sort, null, $limit, $facts );
						return [ $data, $facts ];
					},
					'json_encode',
					false
				);
			};
			$before = $this->gtQueries( fn () => $list( $this->gtBaseQueryService(), $this->anon() ) );
			$beforeUser = $this->gtQueries( fn () => $list( $this->gtBaseQueryService(), $user ) );

			$queries = $this->gtQueries( $anonymousMiss );
			$this->assertLessThanOrEqual( count( $before ), count( $queries ), "$sort, anonymous" );
			$this->assertSame( [], $this->gtPostSendQueries(), 'makes no summary' );

			// A write purges the page: its hold-off follows
			$cache()->touchPage( $page->getId() );
			$queries = $this->gtQueries( $anonymousMiss );
			$this->assertLessThanOrEqual( count( $before ), count( $queries ), "$sort, anonymous, hold-off" );
			$queries = $this->gtQueries( static fn ( CommentQueryService $query ) => $list( $query, $user ) );
			$this->assertLessThanOrEqual( count( $beforeUser ), count( $queries ), "$sort, logged in, hold-off" );
			$this->assertSame( [], $this->gtPostSendQueries(), 'makes no summary in the hold-off' );

			// After it, a logged-in reader's list makes the summary, after the response
			$this->now += 60;
			$queries = $this->gtQueries( static fn ( CommentQueryService $query ) => $list( $query, $user ) );
			$this->assertLessThanOrEqual( count( $beforeUser ), count( $queries ), "$sort, logged in, after hold-off" );
			$this->assertContains( 'CommentQueryService::scanTopLevel', $this->gtPostSendQueries(), 'makes it' );

			// Once made, the summary serves the lists at no more cost
			$queries = $this->gtQueries( static fn ( CommentQueryService $query ) => $list( $query, $user ) );
			$this->assertNotContains( 'CommentQueryService::scanTopLevel', $queries, 'summary cached' );
			$this->assertLessThanOrEqual( count( $beforeUser ), count( $queries ), "$sort, logged in, summary cached" );
			$queries = $this->gtQueries( $anonymousMiss );
			$this->assertNotContains( 'CommentQueryService::scanTopLevel', $queries, 'summary cached' );
			$this->assertLessThanOrEqual( count( $before ), count( $queries ), "$sort, anonymous, summary cached" );
		}
	}

	public function testSummaryWithoutPopularBlockQueriesNoMoreThanBefore() {
		$this->overrideConfigValue( 'GlowThreadPopularCount', 0 );
		$page = $this->makePage( 5 )['page'];
		$user = $this->gtUser();
		$this->now += 60;
		$list = static function ( $query ) use ( $page, $user ) {
			$query->getPageList( $query->newViewer( $user ), $page, 'hot', null, 20 );
		};
		$before = $this->gtQueries( fn () => $list( $this->gtBaseQueryService() ) );
		$this->assertLessThanOrEqual( count( $before ), count( $this->gtQueries( $list ) ) );
		$made = $this->gtPostSendQueries();
		$this->assertContains( 'CommentQueryService::scanTopLevel', $made, 'summary made' );
		$this->assertNotContains( 'CommentQueryService::pickPopular', $made );
	}

	/**
	 * With as many visible comments as hot sort ranks (5000), one scan of the top-level comments
	 * could stop short: the summary reads the pinned comments and the candidates as the database
	 * path does, and scans nothing more.
	 */
	public function testSummaryOfABigPageQueriesNoMoreThanBefore() {
		$page = $this->gtPage( 'GlowThread big page' );
		$actor = $this->getServiceContainer()->getActorNormalization()
			->acquireActorId( $this->gtUser(), $this->getDb() );
		$rows = [];
		for ( $i = 1; $i <= 5000; $i++ ) {
			$rows[] = [
				'gtp_page' => $page->getId(),
				'gtp_actor' => $actor,
				'gtp_depth' => 0,
				'gtp_seq' => $i,
				'gtp_text' => "Comment $i",
				'gtp_created' => $this->getDb()->timestamp( (int)$this->now - 86400 + $i ),
				'gtp_status' => PostStatus::VISIBLE,
				'gtp_like_count' => $i % 3,
			];
		}
		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$this->getDb()->newInsertQueryBuilder()
				->insertInto( PostStore::TABLE )
				->rows( $chunk )
				->caller( __METHOD__ )
				->execute();
		}
		$this->gtService( 'ListCache' )->touchPage( $page->getId() );
		$this->now += 60;
		$user = $this->gtUser();
		$list = static function ( $query ) use ( $page, $user ) {
			$query->getPageList( $query->newViewer( $user ), $page, 'hot', null, 20 );
		};
		$before = $this->gtQueries( fn () => $list( $this->gtBaseQueryService() ) );
		$this->assertLessThanOrEqual( count( $before ), count( $this->gtQueries( $list ) ) );
		$this->assertSame( [
			'CommentQueryService::countVisible',
			'CommentQueryService::fetchPinned',
			// The candidates, read once
			'CommentQueryService::scanTopLevel',
			'CommentQueryService::pickPopular',
		], $this->gtPostSendQueries(), 'summary made' );
	}

	public function testThreadLoadsEachCommentOnce() {
		[ 'deep' => $deep ] = $this->makePage( 3 );
		$this->assertSame( [
			'PostStore::getById',
			// The ancestors, which include the top-level comment
			'PostStore::getByIds',
			'CommentQueryService::fetchThreadReplies',
			'ActorInfoLookup::getInfo',
		], $this->gtQueries( function ( CommentQueryService $q ) use ( $deep ) {
			$this->assertNotNull( $q->getThread( $q->newViewer( $this->anon() ), $deep->getId() ) );
		} ) );

		// Moderators skip the ancestor check, so the top-level comment is loaded on its own
		$this->assertSame( [
			'PostStore::getById',
			'PostStore::getById',
			'CommentQueryService::fetchThreadReplies',
			'ActorInfoLookup::getInfo',
			'ReactionStore::getReactedPostIds',
			'ReportStore::getReportedPostIds',
		], $this->gtQueries( function ( CommentQueryService $q ) use ( $deep ) {
			$viewer = $q->newViewer( $this->getTestSysop()->getUser() );
			$this->assertNotNull( $q->getThread( $viewer, $deep->getId() ) );
		} ) );
	}

	public function testRepliedToCommentIsNotLoadedAgain() {
		[ 'top' => $top, 'deep' => $deep ] = $this->makePage( 1 );
		// The reply answers its parent, which the visibility check loaded
		$this->assertSame( [
			'PostStore::getById',
			'PostStore::getByIds',
			'ActorInfoLookup::getInfo',
		], $this->gtQueries( function ( CommentQueryService $q ) use ( $deep ) {
			$data = $q->getComment( $q->newViewer( $this->anon() ), $deep->getId() );
			$this->assertTrue( $data['comment']['replyTo']['visible'] );
		} ) );

		// The replies answer the comment whose replies they are
		$this->assertSame( [
			'PostStore::getById',
			'CommentQueryService::getReplies',
			'CommentQueryService::fetchChildren',
			'ActorInfoLookup::getInfo',
		], $this->gtQueries( function ( CommentQueryService $q ) use ( $top ) {
			$data = $q->getReplies( $q->newViewer( $this->anon() ), $top->getId(), '', 20 );
			$this->assertSame( $top->getId(), $data['replies'][0]['replyTo']['id'] );
		} ) );
	}
}
