<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Rest;

use ArrayObject;
use MediaWiki\Extension\GlowThread\Query\CommentSerializer;
use MediaWiki\Extension\GlowThread\Query\Cursor;
use MediaWiki\Extension\GlowThread\Query\Viewer;
use MediaWiki\Extension\GlowThread\Rest\CommentHandler;
use MediaWiki\Extension\GlowThread\Rest\FloorHandler;
use MediaWiki\Extension\GlowThread\Rest\PageHistoryHandler;
use MediaWiki\Extension\GlowThread\Rest\QueueHandler;
use MediaWiki\Extension\GlowThread\Rest\RepliesHandler;
use MediaWiki\Extension\GlowThread\Rest\SettingsHandler;
use MediaWiki\Extension\GlowThread\Rest\ThreadHandler;
use MediaWiki\Extension\GlowThread\Rest\UserHistoryHandler;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\Authority;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWiki\User\Registration\UserRegistrationLookup;
use MediaWiki\User\UserIdentityValue;
use MediaWikiIntegrationTestCase;

/**
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Rest\CommentHandler
 * @covers \MediaWiki\Extension\GlowThread\Rest\FloorHandler
 * @covers \MediaWiki\Extension\GlowThread\Rest\ThreadHandler
 * @covers \MediaWiki\Extension\GlowThread\Rest\RepliesHandler
 * @covers \MediaWiki\Extension\GlowThread\Rest\QueueHandler
 * @covers \MediaWiki\Extension\GlowThread\Rest\UserHistoryHandler
 * @covers \MediaWiki\Extension\GlowThread\Rest\PageHistoryHandler
 * @covers \MediaWiki\Extension\GlowThread\Rest\SettingsHandler
 * @covers \MediaWiki\Extension\GlowThread\Query\ModerationQueryService
 */
class OtherHandlersTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;
	use HandlerTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
	}

	/**
	 * @return array{0:int,1:array}
	 */
	private function call( Handler $handler, array $path, array $query, Authority $authority ): array {
		$response = $this->executeHandler(
			$handler,
			new RequestData( [ 'pathParams' => $path, 'queryParams' => $query ] ),
			[], [], [], [], $authority
		);
		$this->assertMatchesRegularExpression( '/^private/', $response->getHeaderLine( 'Cache-Control' ) );
		return [ $response->getStatusCode(), json_decode( $response->getBody()->getContents(), true ) ];
	}

	private static function itemIds( array $data ): array {
		return array_column( array_column( $data['items'], 'comment' ), 'id' );
	}

	private function anon(): Authority {
		return $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' );
	}

	public function testCommentAndFloor() {
		$page = $this->gtPage();
		$first = $this->gtPost( $this->gtUser(), $page, 'first' );
		$second = $this->gtPost( $this->gtUser(), $page, 'second 🎉' );
		$query = $this->gtService( 'CommentQueryService' );

		[ $code, $data ] = $this->call( new CommentHandler( $query ), [ 'id' => $second->getId() ], [], $this->anon() );
		$this->assertSame( 200, $code );
		$this->assertSame( 2, $data['comment']['seq'] );
		$this->assertSame( 'second 🎉', $data['comment']['segments'][0]['text'] );
		$this->assertSame( $page->getId(), $data['page']['id'] );

		[ $code, $data ] = $this->call(
			new FloorHandler( $query ), [ 'pageId' => $page->getId(), 'seq' => 1 ], [], $this->anon()
		);
		$this->assertSame( 200, $code );
		$this->assertSame( $first->getId(), $data['comment']['id'] );

		$this->gtModerate( 'delete', [ $first ] );
		[ $code, $data ] = $this->call( new CommentHandler( $query ), [ 'id' => $first->getId() ], [], $this->anon() );
		$this->assertSame( 404, $code );
		$this->assertSame( 'comment-not-found', $data['errorKey'] );
		[ $code ] = $this->call(
			new CommentHandler( $query ), [ 'id' => $first->getId() ], [], $this->getTestSysop()->getUser()
		);
		$this->assertSame( 200, $code, 'moderators can open deleted comments' );
	}

	public function testRepliesUnderDeletedCommentAreHiddenFromReaders() {
		$page = $this->gtPage();
		$top = $this->gtPost( $this->gtUser(), $page, 'top' );
		$reply = $this->gtPost( $this->gtUser(), $page, 'reply', $top );
		$this->gtModerate( 'delete', [ $top ] );
		$handler = new CommentHandler( $this->gtService( 'CommentQueryService' ) );
		[ $code ] = $this->call( $handler, [ 'id' => $reply->getId() ], [], $this->anon() );
		$this->assertSame( 404, $code );
	}

	public function testThreadAndReplies() {
		$page = $this->gtPage();
		$top = $this->gtPost( $this->gtUser(), $page, 'top' );
		$replies = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$replies[] = $this->gtPost( $this->gtUser(), $page, "reply $i", $top );
		}
		$deep = $this->gtPost( $this->gtUser(), $page, 'deep', $replies[0] );
		$query = $this->gtService( 'CommentQueryService' );

		[ $code, $thread ] = $this->call( new ThreadHandler( $query ), [ 'id' => $deep->getId() ], [], $this->anon() );
		$this->assertSame( 200, $code );
		$this->assertSame( $deep->getId(), $thread['focus'] );
		$this->assertSame( $top->getId(), $thread['thread']['id'] );
		$this->assertCount( 5, $thread['thread']['replies'], 'the whole conversation, not a preview' );
		$this->assertSame( $deep->getId(), $thread['thread']['replies'][0]['replies'][0]['id'] );
		$this->assertSame( 6, $thread['thread']['totalReplies'] );
		$this->assertFalse( $thread['truncated'] );

		$handler = new RepliesHandler( $query );
		[ , $page1 ] = $this->call( $handler, [ 'id' => $top->getId() ], [ 'limit' => 3 ], $this->anon() );
		$this->assertSame( array_map( static fn ( $p ) => $p->getId(), array_slice( $replies, 0, 3 ) ),
			array_column( $page1['replies'], 'id' ) );
		$this->assertSame( 1, $page1['replies'][0]['replyCount'] );
		[ , $page2 ] = $this->call( new RepliesHandler( $query ), [ 'id' => $top->getId() ],
			[ 'limit' => 3, 'cursor' => $page1['next'] ], $this->anon() );
		$this->assertSame( array_map( static fn ( $p ) => $p->getId(), array_slice( $replies, 3 ) ),
			array_column( $page2['replies'], 'id' ) );
		$this->assertNull( $page2['next'] );
	}

	public function testListPreviewIsLimited() {
		$this->overrideConfigValue( 'GlowThreadReplyPreview', 2 );
		$page = $this->gtPage();
		$top = $this->gtPost( $this->gtUser(), $page, 'top' );
		for ( $i = 0; $i < 4; $i++ ) {
			$this->gtPost( $this->gtUser(), $page, "reply $i", $top );
		}
		$list = $this->gtService( 'CommentQueryService' )->getPageList(
			$this->gtService( 'CommentQueryService' )->newViewer( $this->anon() ), $page, 'hot', null, 20
		);
		$this->assertCount( 2, $list['comments'][0]['replies'] );
		$this->assertSame( 4, $list['comments'][0]['replyCount'] );
	}

	private function list( $page, Authority $viewer, string $sort = 'new', int $limit = 20 ): array {
		$query = $this->gtService( 'CommentQueryService' );
		return $query->getPageList( $query->newViewer( $viewer ), $page, $sort, null, $limit );
	}

	/**
	 * The list returns replyPreview replies per level with exact
	 * counts, without loading the other replies.
	 */
	public function testListLoadsOnlyPreviewRepliesWithCounts() {
		$this->overrideConfigValue( 'GlowThreadReplyPreview', 3 );
		// Record which comments reach the serializer (each loaded row is serialized)
		$serialized = new ArrayObject();
		$this->setService( 'GlowThread.CommentSerializer', static fn ( $s ) => new class(
			$s->get( 'GlowThread.TextProcessor' ), $s->get( 'GlowThread.MentionResolver' ),
			$s->get( 'GlowThread.ActorInfoLookup' ), $s->get( 'GlowThread.PostStore' ),
			$s->get( 'GlowThread.ReactionStore' ), $s->get( 'GlowThread.ReportStore' ), $serialized
		) extends CommentSerializer {
			public function __construct( $a, $b, $c, $d, $e, $f, private ArrayObject $record ) {
				parent::__construct( $a, $b, $c, $d, $e, $f );
			}

			public function serializeAll( array $posts, Viewer $viewer, array $pageFlags = [] ): array {
				foreach ( $posts as $post ) {
					$this->record[] = $post->getId();
				}
				return parent::serializeAll( $posts, $viewer, $pageFlags );
			}
		} );
		$page = $this->gtPage();
		$top = $this->gtPost( $this->gtUser(), $page, 'top' );
		$level1 = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$level1[] = $this->gtPost( $this->gtUser(), $page, "reply $i", $top );
		}
		for ( $i = 0; $i < 4; $i++ ) {
			$this->gtPost( $this->gtUser(), $page, "deep $i", $level1[0] );
		}
		$deletedDeep = $this->gtPost( $this->gtUser(), $page, 'deep deleted', $level1[1] );
		$this->gtModerate( 'delete', [ $deletedDeep ] );

		$serialized->exchangeArray( [] );
		$tree = $this->list( $page, $this->anon() )['comments'][0];
		$this->assertSame( array_map( static fn ( $p ) => $p->getId(), array_slice( $level1, 0, 3 ) ),
			array_column( $tree['replies'], 'id' ) );
		// The top-level comment, 3 replies and 3 replies to the first one: not the other 3
		$this->assertCount( 7, $serialized->getArrayCopy(), 'only the returned comments are loaded' );
		$this->assertSame( 5, $tree['replyCount'] );
		$this->assertSame( 9, $tree['totalReplies'] );
		$this->assertSame( 4, $tree['replies'][0]['replyCount'] );
		$this->assertCount( 3, $tree['replies'][0]['replies'] );
		$this->assertSame( 0, $tree['replies'][1]['replyCount'] );

		// Moderators also count the deleted reply they see
		$modTree = $this->list( $page, $this->getTestSysop()->getUser() )['comments'][0];
		$this->assertSame( 1, $modTree['replies'][1]['replyCount'] );
		$this->assertSame( 'deleted', $modTree['replies'][1]['replies'][0]['status'] );

		// With no preview, the counts are still there
		$this->overrideConfigValue( 'GlowThreadReplyPreview', 0 );
		$bare = $this->list( $page, $this->anon() )['comments'][0];
		$this->assertSame( [], $bare['replies'] );
		$this->assertSame( 5, $bare['replyCount'] );
		$this->assertSame( 9, $bare['totalReplies'] );
	}

	/**
	 * Product decision and frontend question 6: replies under a removed comment stay hidden
	 * from readers everywhere (list, counts, single comment, floor, thread, replies, popular).
	 */
	public function testRepliesUnderARemovedParentAreHiddenEverywhere() {
		$page = $this->gtPage();
		$top = $this->gtPost( $this->gtUser(), $page, 'top' );
		$parent = $this->gtPost( $this->gtUser(), $page, 'parent', $top );
		$child = $this->gtPost( $this->gtUser(), $page, 'child', $parent );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->gtService( 'ReactionService' )->setLike( $this->gtUser(), $child->getId(), true );
		}
		$this->gtModerate( 'delete', [ $parent ] );
		$anon = $this->anon();
		$query = $this->gtService( 'CommentQueryService' );

		$list = $this->list( $page, $anon, 'new', 1 );
		$this->assertSame( 1, $list['count'], 'only the top-level comment counts' );
		$this->assertSame( [], $list['comments'][0]['replies'] );
		$this->assertSame( 0, $list['comments'][0]['totalReplies'] );
		$this->assertSame( [], $list['popular'] );
		[ $code ] = $this->call( new CommentHandler( $query ), [ 'id' => $child->getId() ], [], $anon );
		$this->assertSame( 404, $code );
		[ $code ] = $this->call( new FloorHandler( $query ),
			[ 'pageId' => $page->getId(), 'seq' => $child->getSeq() ], [], $anon );
		$this->assertSame( 404, $code );
		[ $code ] = $this->call( new ThreadHandler( $query ), [ 'id' => $child->getId() ], [], $anon );
		$this->assertSame( 404, $code );
		[ $code ] = $this->call( new RepliesHandler( $query ), [ 'id' => $parent->getId() ], [], $anon );
		$this->assertSame( 404, $code );
		[ , $thread ] = $this->call( new ThreadHandler( $query ), [ 'id' => $top->getId() ], [], $anon );
		$this->assertSame( [], $thread['thread']['replies'] );

		// Moderators see the removed parent with its reply
		$mod = $this->list( $page, $this->getTestSysop()->getUser() );
		$this->assertSame( $child->getId(), $mod['comments'][0]['replies'][0]['replies'][0]['id'] );
	}

	/**
	 * The popular block takes comments whose raw like count reaches
	 * $wgGlowThreadPopularMinLikes. The decayed score only orders them.
	 */
	public function testPopularThresholdUsesRawLikes() {
		$this->overrideConfigValue( 'GlowThreadPopularMinLikes', 2 );
		$page = $this->gtPage();
		$now = 1791000000;
		\Wikimedia\Timestamp\ConvertibleTimestamp::setFakeTime( $now - 400 * 86400 );
		$old = $this->gtPost( $this->gtUser(), $page, 'old, two likes' );
		\Wikimedia\Timestamp\ConvertibleTimestamp::setFakeTime( $now - 3600 );
		$this->gtPost( $this->gtUser(), $page, 'newer' );
		for ( $i = 0; $i < 2; $i++ ) {
			$this->gtService( 'ReactionService' )->setLike( $this->gtUser(), $old->getId(), true );
		}
		\Wikimedia\Timestamp\ConvertibleTimestamp::setFakeTime( $now );
		// Decayed score 2 x 0.5^(400/90), about 0.09, is far below 2 likes
		$list = $this->list( $page, $this->anon(), 'new', 1 );
		\Wikimedia\Timestamp\ConvertibleTimestamp::setFakeTime( false );
		$this->assertSame( [ $old->getId() ], array_column( $list['popular'], 'id' ) );
	}

	/**
	 * The viewer hints do not turn a rate limit into "no right". A blocked
	 * user and read-only mode still turn them off.
	 */
	public function testViewerHintsIgnoreRateLimits() {
		$this->overrideConfigValue( MainConfigNames::RateLimits, [
			'glowthread-post' => [ 'user' => [ 2, 60 ] ],
			'glowthread-react' => [ 'user' => [ 1, 60 ] ],
			'glowthread-report' => [ 'user' => [ 1, 86400 ] ],
		] );
		$page = $this->gtPage();
		$user = $this->gtUser();
		$posting = $this->gtService( 'PostingService' );
		$other = $this->gtPost( $this->gtUser(), $page, 'someone else' );
		$this->assertStatusGood( $posting->post( $user, $page->getId(), 'one', null ) );
		// Listing does not count toward the limit: the second post still goes through
		$this->list( $page, $user );
		$this->list( $page, $user );
		$this->assertStatusGood( $posting->post( $user, $page->getId(), 'two', null ) );
		$this->assertStatusError( 'apierror-ratelimited', $posting->post( $user, $page->getId(), 'three', null ) );
		$this->gtService( 'ReactionService' )->setLike( $user, $other->getId(), true );
		$reported = $this->gtPost( $this->gtUser(), $page, 'x' );
		$this->gtService( 'ReportService' )->report( $user, $reported->getId(), 'spam', '' );

		$viewer = $this->list( $page, $user )['viewer'];
		$this->assertTrue( $viewer['canPost'] );
		$this->assertNull( $viewer['postBlockedReason'] );
		$this->assertTrue( $viewer['canReact'] );
		$this->assertTrue( $viewer['canReport'] );

		$this->overrideConfigValue( MainConfigNames::ReadOnly, 'maintenance' );
		$readOnly = $this->list( $page, $user )['viewer'];
		$this->assertFalse( $readOnly['canPost'] );
		$this->assertSame( 'readonly', $readOnly['postBlockedReason'] );
		$this->assertFalse( $readOnly['canReact'] );
		$this->overrideConfigValue( MainConfigNames::ReadOnly, false );

		$blockedUser = $this->gtUser();
		$this->gtBlockFromPage( $blockedUser, $page );
		$blocked = $this->list( $page, $blockedUser )['viewer'];
		$this->assertFalse( $blocked['canPost'] );
		$this->assertSame( 'blocked', $blocked['postBlockedReason'] );
	}

	/**
	 * Crafted reply cursors are a 400, not a 500.
	 */
	public function testBadRepliesCursor() {
		$page = $this->gtPage();
		$top = $this->gtPost( $this->gtUser(), $page, 'top' );
		$this->gtPost( $this->gtUser(), $page, 'reply', $top );
		foreach ( [ '99991399999999', '00000000000000' ] as $ts ) {
			[ $code, $data ] = $this->call( new RepliesHandler( $this->gtService( 'CommentQueryService' ) ),
				[ 'id' => $top->getId() ], [ 'cursor' => Cursor::encode( 'replies', [ 'c' => $ts, 'i' => 1 ] ) ],
				$this->anon() );
			$this->assertSame( 400, $code );
			$this->assertSame( 'bad-cursor', $data['errorKey'] );
		}
	}

	public function testModerationRoutesNeedTheRight() {
		$service = $this->gtService( 'ModerationQueryService' );
		$page = $this->gtPage();
		foreach ( [
			[ new QueueHandler( $service ), [] ],
			[ new UserHistoryHandler( $service ), [ 'name' => 'Nobody' ] ],
			[ new PageHistoryHandler( $service ), [ 'pageId' => $page->getId() ] ],
			[ $this->newSettingsHandler(), [] ],
		] as [ $handler, $path ] ) {
			[ $code, $data ] = $this->call( $handler, $path, [], $this->gtUser() );
			$this->assertSame( 403, $code );
			$this->assertSame( 'permission-denied', $data['errorKey'] );
		}
	}

	public function testQueueTabsAndFilters() {
		$this->gtSetFilter( 'holdme' );
		$page = $this->gtPage();
		$other = $this->gtPage( 'GlowThread queue other' );
		$alice = $this->gtUser();
		$reported = $this->gtPost( $alice, $page, 'reported one' );
		$held = $this->gtPost( $this->gtUser(), $page, 'holdme' );
		$deleted = $this->gtPost( $alice, $other, 'deleted one' );
		$this->gtService( 'ReportService' )->report( $this->gtUser(), $reported->getId(), 'spam', 'ad' );
		$this->gtModerate( 'delete', [ $deleted ] );
		$sysop = $this->getTestSysop()->getUser();
		$service = $this->gtService( 'ModerationQueryService' );

		[ , $data ] = $this->call( new QueueHandler( $service ), [], [ 'tab' => 'reported' ], $sysop );
		$this->assertSame( [ $reported->getId() ], self::itemIds( $data ) );
		$this->assertSame( 'spam', $data['items'][0]['reports'][0]['reason'] );
		$this->assertSame( 'ad', $data['items'][0]['reports'][0]['note'] );
		$this->assertSame( [ 'reported' => 1, 'held' => 1, 'deleted' => 1 ], $data['counts'] );

		[ , $data ] = $this->call( new QueueHandler( $service ), [], [ 'tab' => 'held' ], $sysop );
		$this->assertSame( [ $held->getId() ], self::itemIds( $data ) );

		[ , $data ] = $this->call( new QueueHandler( $service ), [], [ 'tab' => 'deleted' ], $sysop );
		$this->assertSame( [ $deleted->getId() ], self::itemIds( $data ) );

		[ , $data ] = $this->call(
			new QueueHandler( $service ), [], [ 'tab' => 'all', 'user' => $alice->getName() ], $sysop
		);
		$this->assertSame( [ $deleted->getId(), $reported->getId() ], self::itemIds( $data ) );

		[ , $data ] = $this->call(
			new QueueHandler( $service ), [], [ 'tab' => 'all', 'pageid' => $other->getId() ], $sysop
		);
		$this->assertSame( [ $deleted->getId() ], self::itemIds( $data ) );

		[ , $data ] = $this->call( new QueueHandler( $service ), [], [ 'tab' => 'all', 'q' => 'reported' ], $sysop );
		$this->assertSame( [ $reported->getId() ], self::itemIds( $data ) );

		[ , $data ] = $this->call( new QueueHandler( $service ), [], [ 'tab' => 'all', 'limit' => 2 ], $sysop );
		$this->assertCount( 2, $data['items'] );
		[ , $rest ] = $this->call(
			new QueueHandler( $service ), [], [ 'tab' => 'all', 'limit' => 2, 'cursor' => $data['next'] ], $sysop
		);
		$this->assertCount( 1, $rest['items'] );
	}

	/**
	 * Queue order, case-insensitive text search and one
	 * reporter's open reports.
	 */
	public function testQueueSortSearchAndReporter() {
		$page = $this->gtPage();
		$posts = [];
		foreach ( [ 'First SPAM text', 'second 普通 text', 'Third spam text' ] as $text ) {
			$posts[] = $this->gtPost( $this->gtUser(), $page, $text );
		}
		$frequent = $this->gtUser();
		$reports = $this->gtService( 'ReportService' );
		$reports->report( $frequent, $posts[0]->getId(), 'spam', '' );
		$reports->report( $frequent, $posts[2]->getId(), 'spam', '' );
		$reports->report( $this->gtUser(), $posts[2]->getId(), 'spam', '' );
		$reports->report( $this->gtUser(), $posts[1]->getId(), 'spam', '' );
		$sysop = $this->getTestSysop()->getUser();
		$service = $this->gtService( 'ModerationQueryService' );
		$ids = static fn ( array $list ) => array_map( static fn ( $p ) => $p->getId(), $list );
		$get = function ( array $query ) use ( $service, $sysop ) {
			[ $code, $data ] = $this->call( new QueueHandler( $service ), [], $query, $sysop );
			$this->assertSame( 200, $code, json_encode( $query ) );
			return $data;
		};

		$this->assertSame( 'reports', $get( [ 'tab' => 'reported' ] )['sort'] );
		$this->assertSame( [ $posts[2]->getId(), $posts[1]->getId(), $posts[0]->getId() ],
			self::itemIds( $get( [ 'tab' => 'reported' ] ) ) );
		$this->assertSame( array_reverse( $ids( $posts ) ), self::itemIds( $get( [ 'tab' => 'all' ] ) ) );
		$this->assertSame( $ids( $posts ), self::itemIds( $get( [ 'tab' => 'all', 'sort' => 'old' ] ) ) );
		$this->assertSame( [ $posts[2]->getId(), $posts[1]->getId(), $posts[0]->getId() ],
			self::itemIds( $get( [ 'tab' => 'reported', 'sort' => 'new' ] ) ) );
		// Paging through the oldest-first order
		$first = $get( [ 'tab' => 'all', 'sort' => 'old', 'limit' => 2 ] );
		$this->assertSame( array_slice( $ids( $posts ), 0, 2 ), self::itemIds( $first ) );
		$rest = $get( [ 'tab' => 'all', 'sort' => 'old', 'limit' => 2, 'cursor' => $first['next'] ] );
		$this->assertSame( [ $posts[2]->getId() ], self::itemIds( $rest ) );
		// A cursor of another order is refused
		[ $code ] = $this->call( new QueueHandler( $service ), [],
			[ 'tab' => 'all', 'sort' => 'new', 'cursor' => $first['next'] ], $sysop );
		$this->assertSame( 400, $code );

		$this->assertSame( [ $posts[2]->getId(), $posts[0]->getId() ],
			self::itemIds( $get( [ 'tab' => 'all', 'q' => 'Spam' ] ) ), 'case-insensitive' );
		// i18n: search finds multibyte (CJK) text
		$this->assertSame( [ $posts[1]->getId() ], self::itemIds( $get( [ 'tab' => 'all', 'q' => '普通' ] ) ) );

		$this->assertSame( [ $posts[2]->getId(), $posts[0]->getId() ],
			self::itemIds( $get( [ 'tab' => 'reported', 'reporter' => $frequent->getName() ] ) ) );
		$this->assertSame( [], $get( [ 'tab' => 'reported', 'reporter' => 'No such user 12345' ] )['items'] );
		// Only open reports count: after a dismissal the reporter's report there is closed
		$this->gtModerate( 'dismiss', [ $posts[0] ] );
		$this->assertSame( [ $posts[2]->getId() ],
			self::itemIds( $get( [ 'tab' => 'all', 'reporter' => $frequent->getName() ] ) ) );

		// Most reports first on a tab that includes unreported comments: pages through r=0 too
		$seen = [];
		$cursor = '';
		do {
			$data = $get( [ 'tab' => 'all', 'sort' => 'reports', 'limit' => 1, 'cursor' => $cursor ] );
			$seen = array_merge( $seen, self::itemIds( $data ) );
			$cursor = (string)$data['next'];
		} while ( $cursor !== '' );
		$this->assertSame( [ $posts[2]->getId(), $posts[1]->getId(), $posts[0]->getId() ], $seen );
	}

	public function testUserAndPageHistory() {
		$page = $this->gtPage();
		$alice = $this->gtUser();
		$post = $this->gtPost( $alice, $page, 'mine' );
		$this->gtPost( $alice, $page, 'mine too' );
		$reporter = $this->gtUser();
		$this->gtService( 'ReportService' )->report( $reporter, $post->getId(), 'spam', '' );
		$this->gtModerate( 'delete', [ $post ], 'spam' );
		$service = $this->gtService( 'ModerationQueryService' );
		$sysop = $this->getTestSysop()->getUser();

		[ , $user ] = $this->call( new UserHistoryHandler( $service ), [ 'name' => $alice->getName() ], [], $sysop );
		$this->assertSame( $alice->getName(), $user['user']['name'] );
		$this->assertSame( [ 'visible' => 1, 'held' => 0, 'deleted' => 1, 'erased' => 0 ], $user['counts'] );
		$this->assertCount( 2, $user['comments'] );
		$this->assertSame( 'delete', $user['moderation'][0]['action'] );
		$this->assertSame( $post->getId(), $user['moderation'][0]['commentId'] );
		$this->assertSame( [], $user['user']['blocks'] );
		$this->assertNotNull( $user['user']['registration'] );

		[ , $reporterData ] = $this->call(
			new UserHistoryHandler( $service ), [ 'name' => $reporter->getName() ], [], $sysop
		);
		$this->assertSame( [ 'filed' => 1, 'open' => 0, 'upheld' => 1, 'dismissed' => 0 ], $reporterData['reports'] );

		$this->gtBlockSitewide( $alice );
		[ , $user ] = $this->call( new UserHistoryHandler( $service ), [ 'name' => $alice->getName() ], [], $sysop );
		$this->assertTrue( $user['user']['blocks'][0]['sitewide'] );

		[ $code ] = $this->call( new UserHistoryHandler( $service ), [ 'name' => 'No such user 123' ], [], $sysop );
		$this->assertSame( 404, $code );

		[ , $history ] = $this->call( new PageHistoryHandler( $service ), [ 'pageId' => $page->getId() ], [], $sysop );
		$this->assertSame( 'open', $history['page']['state'] );
		$this->assertSame( 1, $history['counts']['visible'] );
		$this->assertSame( 1, $history['counts']['deleted'] );
		$this->assertSame( 2, $history['page']['floors'] );
		$this->assertSame( 'delete', $history['moderation'][0]['action'] );
	}

	/**
	 * FlowThread's old entries for the page (log type comments) are in the page history only
	 * where GlowThread registers their type, so never another extension's entries of that name.
	 */
	public function testPageHistoryShowsFlowThreadEntriesWithLegacyCompat() {
		$page = $this->gtPage();
		$sysop = $this->getTestSysop()->getUser();
		$this->gtSetPageState( $page, 'locked' );
		$legacy = new \ManualLogEntry( 'comments', 'disable' );
		$legacy->setPerformer( $sysop );
		$legacy->setTarget( $page );
		$legacy->insert();
		$actions = function () use ( $page, $sysop ) {
			[ , $history ] = $this->call(
				new PageHistoryHandler( $this->gtService( 'ModerationQueryService' ) ),
				[ 'pageId' => $page->getId() ], [], $sysop
			);
			return array_column( $history['moderation'], 'action' );
		};
		$this->assertSame( [ 'lock' ], $actions() );
		$this->gtRegisterLegacyLogType();
		$this->assertSame( [ 'disable', 'lock' ], $actions() );
	}

	/**
	 * An IP address's history lists the blocks of that address but not autoblocks: an
	 * autoblock's reason names the blocked account, which would tie the address to it.
	 */
	public function testIpHistoryLeavesOutAutoblocks() {
		$services = $this->getServiceContainer();
		// IP actors exist only while temporary accounts are off (the 1.46 installer turns them on)
		$tempUsers = $services->getMainConfig()->get( MainConfigNames::AutoCreateTempUser );
		$this->overrideConfigValue(
			MainConfigNames::AutoCreateTempUser, [ 'enabled' => false, 'known' => false ] + $tempUsers
		);
		$ip = '203.0.113.42';
		$services->getActorNormalization()->acquireActorId( UserIdentityValue::newAnonymous( $ip ), $this->getDb() );
		$account = $this->gtUser();
		$sysop = $this->getTestSysop();
		$status = $services->getBlockUserFactory()->newBlockUser(
			$account, $sysop->getAuthority(), 'infinity', 'account block', [ 'isAutoblocking' => true ]
		)->placeBlock();
		$this->assertStatusGood( $status );
		$this->assertNotFalse( $services->getDatabaseBlockStore()->doAutoblock( $status->getValue(), $ip ) );
		$this->assertStatusGood( $services->getBlockUserFactory()->newBlockUser(
			$ip, $sysop->getAuthority(), '1 day', 'address block'
		)->placeBlock() );

		[ , $data ] = $this->call(
			new UserHistoryHandler( $this->gtService( 'ModerationQueryService' ) ), [ 'name' => $ip ], [],
			$sysop->getUser()
		);
		$this->assertSame( [ 'address block' ], array_column( $data['user']['blocks'], 'reason' ) );
		$this->assertStringNotContainsString( $account->getName(), json_encode( $data ) );
	}

	/**
	 * The registration date comes from UserRegistrationLookup (User::getRegistration() is
	 * deprecated since 1.45). On 1.43 that method does not ask the lookup.
	 */
	public function testUserHistoryRegistrationUsesUserRegistrationLookup() {
		$lookup = $this->createMock( UserRegistrationLookup::class );
		$lookup->method( 'getRegistration' )->willReturn( '20010115000000' );
		$this->setService( 'UserRegistrationLookup', $lookup );
		$alice = $this->gtUser();
		[ , $data ] = $this->call(
			new UserHistoryHandler( $this->gtService( 'ModerationQueryService' ) ),
			[ 'name' => $alice->getName() ], [], $this->getTestSysop()->getUser()
		);
		$this->assertSame( '2001-01-15T00:00:00Z', $data['user']['registration'] );
	}

	private function newSettingsHandler(): SettingsHandler {
		return new SettingsHandler(
			$this->gtService( 'SettingsService' ),
			$this->gtService( 'WordFilter' ),
			$this->gtService( 'RateLimitErrors' )
		);
	}

	public function testSettingsRoute() {
		$this->overrideConfigValue( MainConfigNames::RateLimits, [
			'glowthread-post' => [ 'user' => [ 6, 60 ], 'ip-all' => [ 12, 60 ] ],
			'glowthread-erase' => [ 'user' => [ 30, 3600 ], '&can-bypass' => false ],
			'edit' => [ 'user' => [ 8, 60 ] ],
		] );
		$this->gtSetFilter( "ok\n/(bad/" );
		[ $code, $data ] = $this->call(
			$this->newSettingsHandler(),
			[], [], $this->getTestSysop()->getUser()
		);
		$this->assertSame( 200, $code );
		$this->assertSame( 1000, $data['settings']['maxLength']['value'] );
		$this->assertTrue( $data['settings']['maxLength']['editable'] );
		// Set in LocalSettings by the test setup
		$this->assertFalse( $data['settings']['newAccountHours']['editable'] );
		$this->assertSame( 1, $data['filter']['rules'] );
		$this->assertSame( 2, $data['filter']['invalid'][0]['line'] );
		$this->assertTrue( $data['canConfigure'] );
		// The effective limits of GlowThread's keys only
		$this->assertSame( [
			[
				'key' => 'glowthread-post',
				'limits' => [ 'user' => [ 6, 60 ], 'ip-all' => [ 12, 60 ] ],
				'canBypass' => true,
			],
			[ 'key' => 'glowthread-erase', 'limits' => [ 'user' => [ 30, 3600 ] ], 'canBypass' => false ],
		], $data['rateLimits'] );
	}

	public function testErasedCommentsAreNotTiedToTheirAuthorWithoutEraseRight() {
		$this->setGroupPermissions( 'commentmod', 'glowthread-moderate', true );
		$page = $this->gtPage();
		$alice = $this->gtUser();
		$erased = $this->gtPost( $alice, $page, 'to be erased' );
		$kept = $this->gtPost( $alice, $page, 'kept' );
		$this->gtModerate( 'delete', [ $erased ] );
		$this->gtModerate( 'erase', [ $erased ] );
		$service = $this->gtService( 'ModerationQueryService' );
		$mod = $this->gtUser( [ 'commentmod' ] );

		[ , $user ] = $this->call( new UserHistoryHandler( $service ), [ 'name' => $alice->getName() ], [], $mod );
		$this->assertSame( [ $kept->getId() ], self::itemIds( [ 'items' => $user['comments'] ] ) );
		$this->assertNull( $user['counts']['erased'] );
		$this->assertSame( [], array_filter(
			$user['moderation'], static fn ( $e ) => $e['commentId'] === $erased->getId()
		), 'no log entry ties the erased comment to Alice' );

		[ , $queue ] = $this->call(
			new QueueHandler( $service ), [], [ 'tab' => 'all', 'user' => $alice->getName() ], $mod
		);
		$this->assertSame( [ $kept->getId() ], self::itemIds( $queue ) );

		// Holders of glowthread-erase see everything
		$sysop = $this->getTestSysop()->getUser();
		[ , $user ] = $this->call( new UserHistoryHandler( $service ), [ 'name' => $alice->getName() ], [], $sysop );
		$this->assertSame( 1, $user['counts']['erased'] );
		$this->assertContains( $erased->getId(), self::itemIds( [ 'items' => $user['comments'] ] ) );
	}

	public function testQueueUserFilterDoesNotConfirmHiddenUsers() {
		$page = $this->gtPage();
		$hidden = $this->gtUser();
		$this->gtPost( $hidden, $page, 'x' );
		$other = $this->gtPost( $this->gtUser(), $page, 'reported by the hidden user' );
		$this->gtService( 'ReportService' )->report( $hidden, $other->getId(), 'spam', '' );
		$this->assertStatusGood( $this->getServiceContainer()->getBlockUserFactory()->newBlockUser(
			$hidden, $this->getTestUser( [ 'sysop', 'suppress' ] )->getAuthority(), 'infinity', '',
			[ 'isHideUser' => true, 'isAutoblocking' => false ]
		)->placeBlock() );
		$service = $this->gtService( 'ModerationQueryService' );
		[ , $queue ] = $this->call( new QueueHandler( $service ), [], [ 'tab' => 'all', 'user' => $hidden->getName() ],
			$this->getTestSysop()->getUser() );
		$this->assertSame( [], $queue['items'] );
		[ , $queue ] = $this->call( new QueueHandler( $service ), [],
			[ 'tab' => 'all', 'reporter' => $hidden->getName() ], $this->getTestSysop()->getUser() );
		$this->assertSame( [], $queue['items'], 'the reporter filter does not confirm them either' );
		// Their comment is listed without name, account age or block
		[ , $queue ] = $this->call( new QueueHandler( $service ), [], [ 'tab' => 'all', 'sort' => 'old' ],
			$this->getTestSysop()->getUser() );
		$author = $queue['items'][0]['comment']['author'];
		$this->assertTrue( $author['hidden'] );
		$this->assertNull( $author['registration'] );
		$this->assertNull( $author['blocked'] );
		[ $code ] = $this->call( new UserHistoryHandler( $service ), [ 'name' => $hidden->getName() ], [],
			$this->getTestSysop()->getUser() );
		$this->assertSame( 404, $code );
	}

	public function testSuppressedPerformerIsHiddenFromSysops() {
		$page = $this->gtPage();
		$post = $this->gtPost( $this->gtUser(), $page, 'x' );
		$formerMod = $this->gtUser( [ 'sysop' ] );
		$this->assertStatusGood( $this->gtService( 'ModerationService' )->moderate(
			$formerMod, 'delete', [ $post->getId() ], 'gone'
		) );
		$this->assertStatusGood( $this->getServiceContainer()->getBlockUserFactory()->newBlockUser(
			$formerMod, $this->getTestUser( [ 'sysop', 'suppress' ] )->getAuthority(), 'infinity', '',
			[ 'isHideUser' => true, 'isAutoblocking' => false ]
		)->placeBlock() );
		$service = $this->gtService( 'ModerationQueryService' );
		[ , $history ] = $this->call( new PageHistoryHandler( $service ), [ 'pageId' => $page->getId() ], [],
			$this->getTestSysop()->getUser() );
		$this->assertNull( $history['moderation'][0]['performer'] );
		[ , $history ] = $this->call( new PageHistoryHandler( $service ), [ 'pageId' => $page->getId() ], [],
			$this->getTestUser( [ 'sysop', 'suppress' ] )->getUser() );
		$this->assertSame( $formerMod->getName(), $history['moderation'][0]['performer'] );
	}
}
