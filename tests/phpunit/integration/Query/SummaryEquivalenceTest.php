<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Query;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\GlowThread\Model\PostStatus;
use MediaWiki\Extension\GlowThread\Query\CommentQueryService;
use MediaWiki\Extension\GlowThread\Store\PostStore;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Lists made from the page summary (ListCache) are the lists the code before it read from the
 * database (BaseCommentQueryService), for every kind of viewer: anonymous, logged in with held
 * comments of their own, logged in without any, and moderators; for both sorts, on the first page
 * and the pages after it.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Query\CommentQueryService
 * @covers \MediaWiki\Extension\GlowThread\Query\ListCache
 * @covers \MediaWiki\Extension\GlowThread\Query\HotRanker
 */
class SummaryEquivalenceTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;

	/** Time of the object cache and of ConvertibleTimestamp */
	private float $now;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
		$this->overrideConfigValues( [
			'GlowThreadPopularMinLikes' => 1,
			'GlowThreadPopularCount' => 4,
			// No cap on held comments per author
			'GlowThreadMaxHeldPerUser' => 0,
		] );
		// Far enough back that the days the fixture moves on stay behind the real clock, by which
		// the database layer dates reads (the cache refuses values read after its own time). Half a
		// second in: the cache keeps purge times in whole seconds.
		$this->now = intdiv( time(), 86400 ) * 86400 - 400 * 86400 + 0.5;
		ConvertibleTimestamp::setFakeTime( fn () => (int)$this->now );
		$wanCache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$wanCache->setMockTime( $this->now );
		$this->setService( 'MainWANObjectCache', $wanCache );
		// The check key of all pages exists on a running wiki: one made later counts as purged then
		$this->getServiceContainer()->get( 'GlowThread.ListCache' )->touchAll();
	}

	/**
	 * The current code reading every list from the database
	 */
	private function reference(): CommentQueryService {
		$services = $this->getServiceContainer();
		return new CommentQueryService(
			$this->gtService( 'Config' ),
			$this->gtService( 'PostStore' ),
			$this->gtService( 'PageStateStore' ),
			$this->gtService( 'PageEligibility' ),
			$this->gtService( 'CommentSerializer' ),
			$this->gtService( 'HotRanker' ),
			$this->gtService( 'SettingsService' ),
			$this->gtService( 'ViewerFactory' ),
			$services->getTitleFormatter(),
			fn () => $this->gtService( 'WriteGuard' ),
			$services->getReadOnlyMode(),
			$this->gtService( 'ListCache' ),
			false
		);
	}

	/**
	 * One page of a list, from the summary and from the database
	 *
	 * @return array{0:string,1:string,2:?string} Summary JSON, database JSON, next cursor
	 */
	private function both(
		Authority $authority, ProperPageIdentity $page, string $sort, ?string $cursor, int $limit
	): array {
		// The previous request ends: its updates after the response store what it computed
		DeferredUpdates::doUpdates();
		$this->resetServices();
		$out = [];
		foreach ( [ $this->gtService( 'CommentQueryService' ), $this->gtBaseQueryService() ] as $query ) {
			$decoded = $cursor === null ? null : CommentQueryService::decodeListCursor( $sort, $cursor );
			$out[] = $query->getPageList( $query->newViewer( $authority ), $page, $sort, $decoded, $limit );
		}
		return [ json_encode( $out[0] ), json_encode( $out[1] ), $out[1]['next'] ];
	}

	/**
	 * A logged-in reader's list, which makes the summary after the response (when none is up to
	 * date): the lists after it, of every viewer, come from it
	 */
	private function makeSummary( ProperPageIdentity $page ): void {
		$this->both( $this->getTestUser()->getUser(), $page, 'hot', null, 20 );
		DeferredUpdates::doUpdates();
	}

	/**
	 * Every page of both sorts, for each viewer, the same both ways
	 *
	 * @param ProperPageIdentity $page
	 * @param Authority[] $viewers
	 * @param int $limit
	 * @param int $maxPages
	 */
	private function assertSameLists( ProperPageIdentity $page, array $viewers, int $limit, int $maxPages = 10 ): void {
		$this->makeSummary( $page );
		foreach ( $viewers as $name => $viewer ) {
			foreach ( CommentQueryService::SORTS as $sort ) {
				$cursor = null;
				for ( $i = 0; $i < $maxPages; $i++ ) {
					[ $fromSummary, $fromDatabase, $cursor ] = $this->both( $viewer, $page, $sort, $cursor, $limit );
					$this->assertSame( $fromDatabase, $fromSummary, "$name, $sort, page $i" );
					if ( $cursor === null ) {
						break;
					}
				}
			}
		}
	}

	/**
	 * @return array{page:ProperPageIdentity,viewers:Authority[],held:User}
	 */
	private function makeFixture(): array {
		$this->gtSetFilter( 'holdword' );
		$page = $this->gtPage( 'GlowThread summary page' );
		$authors = [ $this->gtUser(), $this->gtUser(), $this->gtUser() ];
		$likers = [ $this->gtUser(), $this->gtUser(), $this->gtUser(), $this->gtUser() ];
		$held = $this->gtUser();
		$reactions = $this->gtService( 'ReactionService' );
		$tops = [];
		for ( $i = 0; $i < 14; $i++ ) {
			// Days apart, so that likes and age both count in the order
			$this->now += 86400 * ( 3 + ( $i * 7 ) % 11 );
			$tops[$i] = $this->gtPost( $authors[$i % 3], $page, "Top $i" );
			foreach ( array_slice( $likers, 0, ( $i * 3 ) % 5 ) as $liker ) {
				$this->assertStatusGood( $reactions->setLike( $liker, $tops[$i]->getId(), true ) );
			}
			if ( $i % 3 === 0 ) {
				$reply = $this->gtPost( $authors[( $i + 1 ) % 3], $page, "Reply $i", $tops[$i] );
				$this->assertStatusGood( $reactions->setLike( $likers[$i % 4], $reply->getId(), true ) );
				$deep = $this->gtPost( $authors[( $i + 2 ) % 3], $page, "Deep $i", $reply );
				$this->assertStatusGood( $reactions->setLike( $likers[( $i + 1 ) % 4], $deep->getId(), true ) );
			}
		}
		$this->gtModerate( 'pin', [ $tops[2], $tops[9] ] );
		$this->gtModerate( 'delete', [ $tops[4] ] );
		// Held comments of one viewer: top-level ones, one of them pinned (an undone approval
		// leaves the pin), and a reply
		$this->now += 3600;
		$pinnedHeld = $this->gtPost( $held, $page, 'holdword pinned' );
		$moderation = $this->gtService( 'ModerationService' );
		$sysop = $this->getTestSysop()->getAuthority();
		$approval = $moderation->moderate( $sysop, 'approve', [ $pinnedHeld->getId() ], '' );
		$this->assertStatusGood( $approval );
		$this->gtModerate( 'pin', [ $pinnedHeld ] );
		$status = $moderation->moderate(
			$sysop, 'undo', [ $pinnedHeld->getId() ], '', [ $approval->getValue()[0]['logId'] ]
		);
		$this->assertStatusGood( $status );
		$this->assertSame( 'success', $status->getValue()[0]['result'] );
		$this->assertTrue( $this->gtReload( $pinnedHeld )->isHeld() );
		$this->assertTrue( $this->gtReload( $pinnedHeld )->isPinned() );
		$this->gtPost( $held, $page, 'holdword top' );
		$this->now += 86400 * 40;
		$this->gtPost( $held, $page, 'holdword newer top' );
		$this->gtPost( $held, $page, 'holdword reply', $tops[0] );

		$services = $this->getServiceContainer();
		return [
			'page' => $page,
			'held' => $held,
			'viewers' => [
				'anonymous' => $services->getUserFactory()->newAnonymous( '127.0.0.1' ),
				'with held comments' => $held,
				'author' => $authors[0],
				'never acted' => $this->gtUser(),
				'moderator' => $this->getTestSysop()->getUser(),
			],
		];
	}

	public function testListsMatchForEveryViewer() {
		$f = $this->makeFixture();
		$this->now += 60;
		$this->assertSameLists( $f['page'], $f['viewers'], 3 );
		$this->assertSameLists( $f['page'], $f['viewers'], 20 );

		// Later, with the summary of then (it lasts an hour): hot order does not change with the time
		$this->now += 3000;
		$this->assertSameLists( $f['page'], $f['viewers'], 3 );
	}

	public function testCursorOlderThanNewComments() {
		$f = $this->makeFixture();
		$this->now += 60;
		$this->makeSummary( $f['page'] );
		$viewer = $f['viewers']['anonymous'];
		[ , , $cursor ] = $this->both( $viewer, $f['page'], 'hot', null, 3 );
		$this->assertNotNull( $cursor );
		// Comments posted after the first page was read count as just posted at its time
		$this->now += 86400;
		$author = $this->gtUser();
		$this->gtPost( $author, $f['page'], 'Posted after the first page' );
		$this->now += 60;
		$this->makeSummary( $f['page'] );
		foreach ( $f['viewers'] as $name => $authority ) {
			[ $fromSummary, $fromDatabase ] = $this->both( $authority, $f['page'], 'hot', $cursor, 3 );
			$this->assertSame( $fromDatabase, $fromSummary, $name );
		}
	}

	public function testOutdatedSummaryIsNotUsed() {
		$f = $this->makeFixture();
		$this->now += 60;
		$this->makeSummary( $f['page'] );
		$viewer = $f['viewers']['anonymous'];
		// A write between the summary and the comments read after it, as a write in another
		// request between the two could do
		$top = $this->getDb()->newSelectQueryBuilder()
			->select( 'gtp_id' )
			->from( PostStore::TABLE )
			->where( [ 'gtp_page' => $f['page']->getId(), 'gtp_depth' => 0, 'gtp_status' => PostStatus::VISIBLE ] )
			->orderBy( 'gtp_id' )
			->caller( __METHOD__ )
			->fetchField();
		$this->getDb()->newUpdateQueryBuilder()
			->update( PostStore::TABLE )
			->set( [ 'gtp_status' => PostStatus::DELETED ] )
			->where( [ 'gtp_id' => (int)$top ] )
			->caller( __METHOD__ )
			->execute();
		[ $fromSummary, $fromDatabase ] = $this->both( $viewer, $f['page'], 'hot', null, 20 );
		$this->assertSame( $fromDatabase, $fromSummary );
		$this->assertStringNotContainsString( '"id":' . (int)$top . ',', $fromSummary );
	}

	/**
	 * A summary found out of date purges the page: the count, which nothing checks, is right for
	 * the next reader too, and the summary is made again after the hold-off.
	 */
	public function testOutdatedSummaryPurgesThePage() {
		$f = $this->makeFixture();
		$this->now += 60;
		$this->makeSummary( $f['page'] );
		$anon = $f['viewers']['anonymous'];
		[ , $fromDatabase ] = $this->both( $anon, $f['page'], 'hot', null, 20 );
		// A comment of the hot list without likes, so never a popular pick: the list below, of new
		// sort, does not check it
		$unliked = array_filter(
			json_decode( $fromDatabase, true )['comments'], static fn ( $c ) => $c['likes'] === 0
		);
		$top = array_values( $unliked )[0]['id'];
		$this->getDb()->newUpdateQueryBuilder()
			->update( PostStore::TABLE )
			->set( [ 'gtp_status' => PostStatus::DELETED ] )
			->where( [ 'gtp_id' => $top ] )
			->caller( __METHOD__ )
			->execute();
		// Finds the summary out of date
		[ $fromSummary, $fromDatabase ] = $this->both( $anon, $f['page'], 'hot', null, 20 );
		$this->assertSame( $fromDatabase, $fromSummary );
		// The next reader's list does not show that comment, so only a purged summary gives the
		// right count
		[ $fromSummary, $fromDatabase ] = $this->both( $anon, $f['page'], 'new', null, 1 );
		$this->assertSame( json_decode( $fromDatabase, true )['count'], json_decode( $fromSummary, true )['count'] );
		$this->assertSame( $fromDatabase, $fromSummary );
		// Made again after the hold-off
		$this->now += 60;
		$this->makeSummary( $f['page'] );
		$this->assertNotNull(
			$this->gtService( 'ListCache' )->getSummary( $f['page']->getId(), static fn () => null, false )
		);
		[ $fromSummary, $fromDatabase ] = $this->both( $anon, $f['page'], 'new', null, 1 );
		$this->assertSame( $fromDatabase, $fromSummary );
	}

	/**
	 * Out of date in other ways: a visible comment of the viewer held since (it must not show
	 * twice), and a popular reply whose parent was removed since. The count is not checked: only
	 * an edit around the hooks, as here, leaves a summary out of date, and the first list that
	 * finds it so may show its count.
	 */
	public function testOutdatedSummaryInOtherWays() {
		$f = $this->makeFixture();
		// A reply at depth 2 with the most likes, among the popular picks
		$deepId = (int)$this->getDb()->newSelectQueryBuilder()
			->select( 'gtp_id' )
			->from( PostStore::TABLE )
			->where( [ 'gtp_page' => $f['page']->getId(), 'gtp_depth' => 2, 'gtp_status' => PostStatus::VISIBLE ] )
			->orderBy( 'gtp_id', 'DESC' )
			->caller( __METHOD__ )
			->fetchField();
		for ( $i = 0; $i < 6; $i++ ) {
			$this->assertStatusGood( $this->gtService( 'ReactionService' )->setLike( $this->gtUser(), $deepId, true ) );
		}
		$this->now += 60;
		$this->makeSummary( $f['page'] );
		$author = $f['viewers']['author'];
		foreach ( $f['viewers'] as $viewer ) {
			foreach ( CommentQueryService::SORTS as $sort ) {
				$this->both( $viewer, $f['page'], $sort, null, 20 );
			}
		}
		$actor = $this->getServiceContainer()->getActorNormalization()->findActorId( $author, $this->getDb() );
		$own = (int)$this->getDb()->newSelectQueryBuilder()
			->select( 'gtp_id' )
			->from( PostStore::TABLE )
			->where( [
				'gtp_page' => $f['page']->getId(), 'gtp_depth' => 0, 'gtp_actor' => $actor,
				'gtp_status' => PostStatus::VISIBLE, 'gtp_pinned' => null,
			] )
			->orderBy( 'gtp_id', 'DESC' )
			->caller( __METHOD__ )
			->fetchField();
		[ , $fromDatabase ] = $this->both( $f['viewers']['anonymous'], $f['page'], 'new', null, 3 );
		$popular = json_decode( $fromDatabase, true )['popular'];
		$deep = array_values( array_filter( $popular, static fn ( $c ) => $c['depth'] === 2 ) );
		$this->assertNotEmpty( $deep, 'a popular reply at depth 2' );
		foreach ( [ $own => PostStatus::HELD, $deep[0]['parentId'] => PostStatus::DELETED ] as $id => $status ) {
			$this->getDb()->newUpdateQueryBuilder()
				->update( PostStore::TABLE )
				->set( [ 'gtp_status' => $status ] )
				->where( [ 'gtp_id' => $id ] )
				->caller( __METHOD__ )
				->execute();
		}
		$withoutCount = static function ( string $json ): array {
			$list = json_decode( $json, true );
			unset( $list['count'] );
			return $list;
		};
		foreach ( $f['viewers'] as $name => $viewer ) {
			foreach ( CommentQueryService::SORTS as $sort ) {
				foreach ( [ 20, 3 ] as $limit ) {
					[ $fromSummary, $fromDatabase ] = $this->both( $viewer, $f['page'], $sort, null, $limit );
					$this->assertSame(
						$withoutCount( $fromDatabase ), $withoutCount( $fromSummary ), "$name, $sort, $limit"
					);
				}
			}
		}
	}

	/**
	 * More top-level comments than hot sort ranks (5000): the summary reads pinned comments and
	 * candidates as the database queries do, and merges the held comments of a viewer into the
	 * highest IDs.
	 */
	public function testPageWithMoreCommentsThanRanked() {
		$page = $this->gtPage( 'GlowThread summary big page' );
		$author = $this->gtUser();
		$held = $this->gtUser();
		$actors = $this->getServiceContainer()->getActorNormalization();
		$authorActor = $actors->acquireActorId( $author, $this->getDb() );
		$heldActor = $actors->acquireActorId( $held, $this->getDb() );
		$rows = [];
		$base = (int)$this->now;
		for ( $i = 1; $i <= 5010; $i++ ) {
			$status = PostStatus::VISIBLE;
			$actor = $authorActor;
			if ( $i === 3 || $i === 5009 ) {
				$status = PostStatus::HELD;
				$actor = $heldActor;
			}
			$rows[] = [
				'gtp_page' => $page->getId(),
				'gtp_actor' => $actor,
				'gtp_depth' => 0,
				'gtp_seq' => $i,
				'gtp_text' => "Comment $i",
				'gtp_created' => $this->getDb()->timestamp( $base + $i * 600 ),
				'gtp_status' => $status,
				// The newest held comment has the most likes: it leads its author's hot list
				'gtp_like_count' => $i === 5009 ? 12 : ( $i * 7 ) % 13,
				'gtp_pinned' => in_array( $i, [ 2, 3, 4000 ] )
					? $this->getDb()->timestamp( $base + 10000000 + $i )
					: null,
			];
		}
		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$this->getDb()->newInsertQueryBuilder()
				->insertInto( PostStore::TABLE )
				->rows( $chunk )
				->caller( __METHOD__ )
				->execute();
		}
		$this->now = $base + 5010 * 600 + 60.5;
		$viewers = [
			'anonymous' => $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' ),
			'with held comments' => $held,
			'moderator' => $this->getTestSysop()->getUser(),
		];
		$this->assertSameLists( $page, $viewers, 50, 3 );
	}

	/**
	 * More popular candidates than are scored (500) with equal like counts: the newest are
	 * scored, whatever plan the database picks, and the newest of those win.
	 */
	public function testPopularCandidatesBeyondTheCut() {
		$page = $this->gtPage( 'GlowThread popular cut' );
		$actor = $this->getServiceContainer()->getActorNormalization()
			->acquireActorId( $this->gtUser(), $this->getDb() );
		$base = (int)$this->now;
		$rows = [];
		for ( $i = 1; $i <= 600; $i++ ) {
			$rows[] = [
				'gtp_page' => $page->getId(),
				'gtp_actor' => $actor,
				'gtp_depth' => 0,
				'gtp_seq' => $i,
				'gtp_text' => "Comment $i",
				'gtp_created' => $this->getDb()->timestamp( $base + $i * 60 ),
				'gtp_status' => PostStatus::VISIBLE,
				'gtp_like_count' => 5,
			];
		}
		foreach ( array_chunk( $rows, 300 ) as $chunk ) {
			$this->getDb()->newInsertQueryBuilder()
				->insertInto( PostStore::TABLE )
				->rows( $chunk )
				->caller( __METHOD__ )
				->execute();
		}
		$this->now = $base + 600 * 60 + 60.5;
		$newest = array_map( 'intval', $this->getDb()->newSelectQueryBuilder()
			->select( 'gtp_id' )
			->from( PostStore::TABLE )
			->where( [ 'gtp_page' => $page->getId() ] )
			->orderBy( [ 'gtp_created', 'gtp_id' ], 'DESC' )
			->limit( 4 )
			->caller( __METHOD__ )
			->fetchFieldValues() );
		$anon = $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' );
		foreach ( [ $this->gtService( 'CommentQueryService' ), $this->reference() ] as $query ) {
			$list = $query->getPageList( $query->newViewer( $anon ), $page, 'new', null, 1 );
			$this->assertSame( $newest, array_column( $list['popular'], 'id' ) );
		}
	}
}
