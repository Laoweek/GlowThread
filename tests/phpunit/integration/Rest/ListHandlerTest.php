<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Rest;

use GuzzleHttp\Psr7\Uri;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Query\Cursor;
use MediaWiki\Extension\GlowThread\Rest\ListHandler;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Rest\RequestData;
use MediaWiki\Rest\ResponseInterface;
use MediaWiki\Session\Session;
use MediaWiki\Session\SessionId;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiIntegrationTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Rest\ListHandler
 * @covers \MediaWiki\Extension\GlowThread\Query\CommentQueryService
 * @covers \MediaWiki\Extension\GlowThread\Query\CommentSerializer
 */
class ListHandlerTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;
	use HandlerTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
	}

	private function newSession( bool $persistent ): Session {
		$session = $this->createMock( Session::class );
		$session->method( 'getSessionId' )->willReturn( new SessionId( 'test' ) );
		$session->method( 'isPersistent' )->willReturn( $persistent );
		return $session;
	}

	/**
	 * Run the handler the way the REST module does, including its cache-control step.
	 */
	private function newHandler(): ListHandler {
		return ListHandler::factory(
			$this->gtService( 'Config' ),
			$this->gtService( 'CdnPurger' ),
			$this->gtService( 'ListCache' ),
			$this->gtService( 'PageEligibility' ),
			$this->getServiceContainer()->getTitleFormatter()
		);
	}

	/**
	 * @param ProperPageIdentity $page
	 * @param array $query
	 * @param Authority $authority
	 * @param bool $persistentSession
	 * @param string|null $rawUri Request URI as sent, when it differs from the parsed parameters
	 * @param string|null $host Host header, by default the one purges carry ($wgServer's)
	 */
	private function get(
		ProperPageIdentity $page,
		array $query,
		Authority $authority,
		bool $persistentSession = false,
		?string $rawUri = null,
		?string $host = null
	): ResponseInterface {
		$handler = $this->newHandler();
		$rawUri ??= $this->gtService( 'CdnPurger' )->getListRoutePath( $page->getId() )
			. ( $query ? '?' . http_build_query( $query ) : '' );
		$response = $this->executeHandler(
			$handler,
			new RequestData( [
				'uri' => new Uri( $rawUri ),
				'pathParams' => [ 'pageId' => $page->getId() ],
				'queryParams' => $query,
				'headers' => [ 'Host' => $host ?? $this->gtService( 'CdnPurger' )->getPurgeHost() ],
			] ),
			[], [], [], [],
			$authority,
			$this->newSession( $persistentSession )
		);
		$handler->applyCacheControl( $response );
		return $response;
	}

	private function getData( ProperPageIdentity $page, array $query, Authority $authority ): array {
		$response = $this->get( $page, $query, $authority, $authority->isRegistered() );
		$this->assertSame( 200, $response->getStatusCode() );
		return json_decode( $response->getBody()->getContents(), true );
	}

	private function anon(): Authority {
		return $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' );
	}

	/**
	 * A page with: a visible comment by Alice with a visible reply and a deleted reply, a held
	 * comment by Bob, and a deleted comment by Carol.
	 *
	 * @return array{page:ProperPageIdentity,alice:\MediaWiki\User\User,bob:\MediaWiki\User\User,posts:PostRecord[]}
	 */
	private function makeFixture(): array {
		$this->gtSetFilter( 'holdme' );
		$page = $this->gtPage();
		$alice = $this->gtUser();
		$bob = $this->gtUser();
		$carol = $this->gtUser();
		$visible = $this->gtPost( $alice, $page, 'Visible 😀 by Alice' );
		$reply = $this->gtPost( $bob, $page, 'Visible reply', $visible );
		$deletedReply = $this->gtPost( $carol, $page, 'Deleted reply', $visible );
		$held = $this->gtPost( $bob, $page, 'holdme by Bob' );
		$deleted = $this->gtPost( $carol, $page, 'Deleted by mods' );
		$this->gtModerate( 'delete', [ $deleted, $deletedReply ] );
		return [
			'page' => $page, 'alice' => $alice, 'bob' => $bob,
			'posts' => [
				'visible' => $visible,
				'reply' => $reply,
				'deletedReply' => $deletedReply,
				'held' => $held,
				'deleted' => $deleted,
			],
		];
	}

	private static function ids( array $comments ): array {
		return array_column( $comments, 'id' );
	}

	public function testAnonymousSeesOnlyVisibleComments() {
		$f = $this->makeFixture();
		$data = $this->getData( $f['page'], [ 'sort' => 'new' ], $this->anon() );
		$this->assertSame( [ $f['posts']['visible']->getId() ], self::ids( $data['comments'] ) );
		$top = $data['comments'][0];
		$this->assertSame( [ $f['posts']['reply']->getId() ], self::ids( $top['replies'] ) );
		$this->assertSame( 1, $top['replyCount'] );
		$this->assertSame( 2, $data['count'] );
		$this->assertArrayNotHasKey( 'mod', $top );
		$this->assertSame( 'Visible 😀 by Alice', $top['segments'][0]['text'] );
		$this->assertFalse( $data['viewer']['canPost'] );
		$this->assertSame( 'login', $data['viewer']['postBlockedReason'] );
		$this->assertFalse( $top['viewer']['own'] );
		$this->assertFalse( $top['viewer']['canLike'] );
	}

	public function testAuthorSeesOwnHeldComment() {
		$f = $this->makeFixture();
		$bobView = $this->getData( $f['page'], [ 'sort' => 'new' ], $f['bob'] );
		$this->assertSame(
			[ $f['posts']['held']->getId(), $f['posts']['visible']->getId() ],
			self::ids( $bobView['comments'] )
		);
		$this->assertSame( 'held', $bobView['comments'][0]['status'] );
		$this->assertTrue( $bobView['comments'][0]['viewer']['own'] );
		$this->assertTrue( $bobView['comments'][0]['viewer']['canDelete'] );

		$aliceView = $this->getData( $f['page'], [ 'sort' => 'new' ], $f['alice'] );
		$this->assertSame( [ $f['posts']['visible']->getId() ], self::ids( $aliceView['comments'] ) );
		$this->assertTrue( $aliceView['viewer']['canPost'] );
		$this->assertTrue( $aliceView['comments'][0]['viewer']['own'] );
		$this->assertFalse( $aliceView['comments'][0]['viewer']['canLike'], 'no liking your own comment' );
	}

	public function testModeratorSeesEverythingWithExtras() {
		$f = $this->makeFixture();
		$data = $this->getData( $f['page'], [ 'sort' => 'new' ], $this->getTestSysop()->getUser() );
		$this->assertSame( [
			$f['posts']['deleted']->getId(), $f['posts']['held']->getId(), $f['posts']['visible']->getId(),
		], self::ids( $data['comments'] ) );
		$top = $data['comments'][2];
		$this->assertSame(
			[ $f['posts']['reply']->getId(), $f['posts']['deletedReply']->getId() ],
			self::ids( $top['replies'] )
		);
		$this->assertSame( [ 'filter' ], $data['comments'][1]['mod']['holdReasons'] );
		$this->assertSame(
			[ [ 'line' => 1, 'rule' => 'holdme', 'type' => 'literal' ] ],
			$data['comments'][1]['mod']['holdMatches']
		);
		$this->assertSame( [], $data['comments'][2]['mod']['holdMatches'] );
		$this->assertSame( 'deleted', $data['comments'][0]['status'] );
		$this->assertNotNull( $data['comments'][0]['mod']['statusBy'] );
		$this->assertTrue( $data['viewer']['canModerate'] );
		$this->assertSame( 2, $data['count'], 'the count is what readers see' );
	}

	public function testModeratorsSeeAuthorAgeAndBlocks() {
		$page = $this->gtPage();
		$users = [
			'sitewide' => $this->gtUser(),
			'partial' => $this->gtUser(),
			'none' => $this->gtUser(),
		];
		foreach ( $users as $user ) {
			$this->gtPost( $user, $page, 'by ' . $user->getName() );
		}
		$this->gtBlockSitewide( $users['sitewide'] );
		$this->gtBlockFromPage( $users['partial'], $this->gtPage( 'GlowThread other page' ) );

		$data = $this->getData( $page, [ 'sort' => 'new' ], $this->getTestSysop()->getUser() );
		$authors = array_column( array_column( $data['comments'], 'author' ), null, 'name' );
		$registrations = $this->getServiceContainer()->getUserRegistrationLookup();
		foreach ( $users as $state => $user ) {
			$author = $authors[$user->getName()];
			$this->assertSame( $state === 'none' ? null : $state, $author['blocked'], $state );
			$this->assertSame(
				wfTimestamp( TS_ISO_8601, $registrations->getRegistration( $user ) ),
				$author['registration']
			);
		}

		$reader = $this->getData( $page, [ 'sort' => 'new' ], $this->gtUser() );
		$this->assertArrayNotHasKey( 'registration', $reader['comments'][0]['author'] );
		$this->assertArrayNotHasKey( 'blocked', $reader['comments'][0]['author'] );
	}

	public function testReportCountsAndReasonsForModerators() {
		$page = $this->gtPage();
		$post = $this->gtPost( $this->gtUser(), $page, 'reported' );
		$reports = $this->gtService( 'ReportService' );
		$reports->report( $this->gtUser(), $post->getId(), 'spam', 'buy now' );
		$reports->report( $this->gtUser(), $post->getId(), 'spam', '' );
		$reports->report( $this->gtUser(), $post->getId(), 'abuse', '' );
		$data = $this->getData( $page, [ 'sort' => 'hot' ], $this->getTestSysop()->getUser() );
		$mod = $data['comments'][0]['mod'];
		$this->assertSame( 3, $mod['openReports'] );
		$this->assertEqualsCanonicalizing(
			[ [ 'reason' => 'spam', 'count' => 2 ], [ 'reason' => 'abuse', 'count' => 1 ] ],
			$mod['reportReasons']
		);
	}

	public function testAnonymousFirstPageIsCacheable() {
		$page = $this->gtPage();
		$this->gtPost( $this->gtUser(), $page, 'x' );
		foreach ( [ 'hot', 'new' ] as $sort ) {
			$response = $this->get( $page, [ 'sort' => $sort ], $this->anon() );
			$this->assertSame( 'public, s-maxage=300, max-age=0', $response->getHeaderLine( 'Cache-Control' ) );
			$this->assertSame( '', $response->getHeaderLine( 'Set-Cookie' ) );
			$this->assertStringNotContainsStringIgnoringCase( 'cookie', $response->getHeaderLine( 'Vary' ) );
		}
	}

	public function testOtherResponsesArePrivate() {
		$page = $this->gtPage();
		for ( $i = 0; $i < 3; $i++ ) {
			$this->gtPost( $this->gtUser(), $page, "comment $i" );
		}
		$private = '/^private/';
		// Logged in
		$this->assertMatchesRegularExpression( $private,
			$this->get( $page, [ 'sort' => 'hot' ], $this->gtUser(), true )->getHeaderLine( 'Cache-Control' ) );
		// Anonymous reader with a session cookie
		$this->assertMatchesRegularExpression( $private,
			$this->get( $page, [ 'sort' => 'hot' ], $this->anon(), true )->getHeaderLine( 'Cache-Control' ) );
		// Not the canonical URL
		$this->assertMatchesRegularExpression( $private,
			$this->get( $page, [], $this->anon() )->getHeaderLine( 'Cache-Control' ) );
		$this->assertMatchesRegularExpression( $private,
			$this->get( $page, [ 'sort' => 'hot', 'limit' => 2 ], $this->anon() )->getHeaderLine( 'Cache-Control' ) );
		// Deeper pages
		$firstResponse = $this->get( $page, [ 'sort' => 'new', 'limit' => 2 ], $this->anon() );
		$first = json_decode( $firstResponse->getBody()->getContents(), true );
		$this->assertNotNull( $first['next'] );
		$this->assertMatchesRegularExpression( $private, $this->get(
			$page, [ 'sort' => 'new', 'cursor' => $first['next'] ], $this->anon()
		)->getHeaderLine( 'Cache-Control' ) );
	}

	public function testReadsWriteNothing() {
		$page = $this->gtPage();
		$this->gtPost( $this->gtUser(), $page, 'x' );
		$count = fn ( $table ) => (int)$this->getDb()->newSelectQueryBuilder()
			->select( 'COUNT(*)' )->from( $table )->caller( __METHOD__ )->fetchField();
		$tables = [ 'actor', 'glowthread_post', 'glowthread_page', 'glowthread_reaction', 'logging' ];
		$reader = $this->gtUser();
		$otherIp = $this->getServiceContainer()->getUserFactory()->newAnonymous( '192.0.2.9' );
		$before = array_map( $count, $tables );
		$this->get( $page, [ 'sort' => 'hot' ], $this->anon() );
		$this->get( $page, [ 'sort' => 'new' ], $otherIp );
		$this->get( $page, [ 'sort' => 'hot' ], $reader, true );
		$this->assertSame( $before, array_map( $count, $tables ), 'a write would make MediaWiki set a cookie' );
	}

	public function testOnlyTheExactCanonicalUrlIsCacheable() {
		$page = $this->gtPage();
		$path = $this->gtService( 'CdnPurger' )->getListRoutePath( $page->getId() );
		$zeroPadded = str_replace( '/page/' . $page->getId() . '/', '/page/0' . $page->getId() . '/', $path );
		foreach ( [
			"$zeroPadded?sort=hot",
			"$path?%73ort=hot",
			"$path?sort=hot&",
			"$path?sort=new",
		] as $uri ) {
			$this->assertMatchesRegularExpression( '/^private/',
				$this->get( $page, [ 'sort' => 'hot' ], $this->anon(), false, $uri )->getHeaderLine( 'Cache-Control' ),
				$uri
			);
		}
		$canonical = $this->get( $page, [ 'sort' => 'hot' ], $this->anon(), false, "$path?sort=hot" );
		$this->assertStringStartsWith( 'public', $canonical->getHeaderLine( 'Cache-Control' ) );
	}

	/**
	 * A copy cached under another host name (an IP address, a second
	 * domain) is never reached by purges, so it must not be public.
	 */
	public function testOnlyThePurgedHostIsCacheable() {
		$this->overrideConfigValues( [ 'Server' => 'http://localhost:8250', 'InternalServer' => false ] );
		$page = $this->gtPage();
		$this->assertSame( 'localhost:8250', $this->gtService( 'CdnPurger' )->getPurgeHost() );
		foreach ( [ '127.0.0.1:8250', 'localhost', 'localhost:9999', 'LOCALHOST:8250', 'evil.example', '' ] as $host ) {
			$this->assertMatchesRegularExpression( '/^private/',
				$this->get( $page, [ 'sort' => 'hot' ], $this->anon(), false, null, $host )
					->getHeaderLine( 'Cache-Control' ),
				"Host: $host"
			);
		}
		$this->assertStringStartsWith( 'public', $this->get( $page, [ 'sort' => 'hot' ], $this->anon(), false, null,
			'localhost:8250' )->getHeaderLine( 'Cache-Control' ) );

		// With an internal server, purges carry its host
		$this->overrideConfigValues( [ 'InternalServer' => 'http://www.example.org' ] );
		$this->assertSame( 'www.example.org', $this->gtService( 'CdnPurger' )->getPurgeHost() );
		$this->assertMatchesRegularExpression( '/^private/', $this->get( $page, [ 'sort' => 'hot' ], $this->anon(),
			false, null, 'localhost:8250' )->getHeaderLine( 'Cache-Control' ) );
	}

	public function testCacheCanBeTurnedOff() {
		$this->overrideConfigValue( 'GlowThreadCdnMaxAge', 0 );
		$page = $this->gtPage();
		$this->assertMatchesRegularExpression( '/^private/',
			$this->get( $page, [ 'sort' => 'hot' ], $this->anon() )->getHeaderLine( 'Cache-Control' ) );
	}

	public function testNotCacheableWithoutCdn() {
		$this->overrideConfigValue( 'UseCdn', false );
		$page = $this->gtPage();
		$this->assertMatchesRegularExpression( '/^private/',
			$this->get( $page, [ 'sort' => 'hot' ], $this->anon() )->getHeaderLine( 'Cache-Control' ) );
	}

	public function testNewSortPagesWithCursor() {
		$page = $this->gtPage();
		$ids = [];
		for ( $i = 0; $i < 5; $i++ ) {
			ConvertibleTimestamp::setFakeTime( 1791000000 + $i * 60 );
			$ids[] = $this->gtPost( $this->gtUser(), $page, "comment $i" )->getId();
		}
		ConvertibleTimestamp::setFakeTime( false );
		$seen = [];
		$query = [ 'sort' => 'new', 'limit' => 2 ];
		do {
			$data = $this->getData( $page, $query, $this->anon() );
			$seen = array_merge( $seen, self::ids( $data['comments'] ) );
			$query = [ 'sort' => 'new', 'limit' => 2, 'cursor' => $data['next'] ];
		} while ( $data['next'] !== null );
		$this->assertSame( array_reverse( $ids ), $seen );
	}

	public function testHotSortRanksLikesWithDecay() {
		$page = $this->gtPage();
		$now = 1791000000;
		ConvertibleTimestamp::setFakeTime( $now - 400 * 86400 );
		$old = $this->gtPost( $this->gtUser(), $page, 'old and popular' );
		ConvertibleTimestamp::setFakeTime( $now - 86400 );
		$liked = $this->gtPost( $this->gtUser(), $page, 'recent with likes' );
		ConvertibleTimestamp::setFakeTime( $now - 3600 );
		$fresh = $this->gtPost( $this->gtUser(), $page, 'fresh' );
		$reactions = $this->gtService( 'ReactionService' );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertStatusGood( $reactions->setLike( $this->gtUser(), $liked->getId(), true ) );
			$this->assertStatusGood( $reactions->setLike( $this->gtUser(), $old->getId(), true ) );
		}
		ConvertibleTimestamp::setFakeTime( $now );
		$data = $this->getData( $page, [ 'sort' => 'hot' ], $this->anon() );
		ConvertibleTimestamp::setFakeTime( false );
		$this->assertSame( [ $liked->getId(), $fresh->getId(), $old->getId() ], self::ids( $data['comments'] ) );
	}

	public function testHotCursorPagesWithoutRepeats() {
		$page = $this->gtPage();
		for ( $i = 0; $i < 5; $i++ ) {
			$this->gtPost( $this->gtUser(), $page, "comment $i" );
		}
		$first = $this->getData( $page, [ 'sort' => 'hot', 'limit' => 3 ], $this->anon() );
		$second = $this->getData( $page, [ 'sort' => 'hot', 'limit' => 3, 'cursor' => $first['next'] ], $this->anon() );
		$this->assertCount( 3, $first['comments'] );
		$this->assertCount( 2, $second['comments'] );
		$this->assertSame( [], array_intersect( self::ids( $first['comments'] ), self::ids( $second['comments'] ) ) );
		$this->assertNull( $second['next'] );
	}

	public static function provideBadCursors() {
		yield 'not a cursor' => [ 'new', 'not-a-cursor' ];
		// Fourteen digits that are not a date made the database layer throw
		yield 'month 13' => [ 'new', Cursor::encode( 'new', [ 'c' => '99991399999999', 'i' => 1 ] ) ];
		yield 'all zeros' => [ 'new', Cursor::encode( 'new', [ 'c' => '00000000000000', 'i' => 1 ] ) ];
		yield 'hot offset too deep' => [ 'hot', Cursor::encode( 'hot', [ 'n' => 1791000000, 'o' => 5000 ] ) ];
		yield 'hot cursor on new' => [ 'new', Cursor::encode( 'hot', [ 'n' => 1791000000, 'o' => 20 ] ) ];
	}

	/**
	 * @dataProvider provideBadCursors
	 */
	public function testBadCursor( string $sort, string $cursor ) {
		$page = $this->gtPage();
		$this->gtPost( $this->gtUser(), $page, 'x' );
		$response = $this->get( $page, [ 'sort' => $sort, 'cursor' => $cursor ], $this->anon() );
		$this->assertSame( 400, $response->getStatusCode() );
		$this->assertSame( 'bad-cursor', json_decode( $response->getBody()->getContents(), true )['errorKey'] );
		$this->assertMatchesRegularExpression( '/^private/', $response->getHeaderLine( 'Cache-Control' ) );
	}

	public function testPinnedAndPopular() {
		$page = $this->gtPage();
		$pinned = $this->gtPost( $this->gtUser(), $page, 'notice' );
		$popular = $this->gtPost( $this->gtUser(), $page, 'popular one' );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->gtPost( $this->gtUser(), $page, "filler $i" );
		}
		$reactions = $this->gtService( 'ReactionService' );
		$reactions->setLike( $this->gtUser(), $popular->getId(), true );
		$reactions->setLike( $this->gtUser(), $popular->getId(), true );
		$this->gtModerate( 'pin', [ $pinned ] );
		$new = $this->getData( $page, [ 'sort' => 'new', 'limit' => 2 ], $this->anon() );
		$this->assertSame( [ $pinned->getId() ], self::ids( $new['pinned'] ) );
		$this->assertNotContains( $pinned->getId(), self::ids( $new['comments'] ) );
		$this->assertSame( [ $popular->getId() ], self::ids( $new['popular'] ) );

		// On the hot first page the popular comment is already shown, so no popular block
		$hot = $this->getData( $page, [ 'sort' => 'hot' ], $this->anon() );
		$this->assertSame( [], $hot['popular'] );
	}

	public function testDisabledPageHidesCommentsFromReaders() {
		$page = $this->gtPage();
		$this->gtPost( $this->gtUser(), $page, 'x' );
		$this->gtSetPageState( $page, 'disabled' );
		$anon = $this->getData( $page, [ 'sort' => 'hot' ], $this->anon() );
		$this->assertSame( 'disabled', $anon['page']['state'] );
		$this->assertSame( [], $anon['comments'] );
		$this->assertSame( 'disabled', $anon['viewer']['postBlockedReason'] );
		$mod = $this->getData( $page, [ 'sort' => 'hot' ], $this->getTestSysop()->getUser() );
		$this->assertCount( 1, $mod['comments'] );
	}

	public function testUnreadablePageIs404() {
		$page = $this->gtPage();
		$this->setGroupPermissions( '*', 'read', false );
		$response = $this->get( $page, [ 'sort' => 'hot' ], $this->anon() );
		$this->assertSame( 404, $response->getStatusCode() );
	}

	public function testMissingPageIs404() {
		$handler = $this->newHandler();
		$response = $this->executeHandler(
			$handler,
			new RequestData( [ 'pathParams' => [ 'pageId' => 999999 ], 'queryParams' => [ 'sort' => 'hot' ] ] ),
			[], [], [], [], $this->anon(), $this->newSession( false )
		);
		$this->assertSame( 404, $response->getStatusCode() );
		$this->assertSame( 'page-not-found', json_decode( $response->getBody()->getContents(), true )['errorKey'] );
	}

	public function testHiddenUserNameIsNotShown() {
		$page = $this->gtPage();
		$hidden = $this->gtUser();
		$this->gtPost( $hidden, $page, 'from a user who will be hidden' );
		$this->assertStatusGood( $this->getServiceContainer()->getBlockUserFactory()->newBlockUser(
			$hidden,
			$this->getTestUser( [ 'sysop', 'suppress' ] )->getAuthority(),
			'infinity',
			'',
			[ 'isHideUser' => true, 'isAutoblocking' => false ]
		)->placeBlock() );
		$data = $this->getData( $page, [ 'sort' => 'hot' ], $this->anon() );
		$this->assertNull( $data['comments'][0]['author']['name'] );
		$this->assertTrue( $data['comments'][0]['author']['hidden'] );
		$this->assertStringNotContainsString( $hidden->getName(), json_encode( $data ) );

		$suppressorUser = $this->getTestUser( [ 'sysop', 'suppress' ] )->getUser();
		$suppressor = $this->getData( $page, [ 'sort' => 'hot' ], $suppressorUser );
		$this->assertSame( $hidden->getName(), $suppressor['comments'][0]['author']['name'] );
	}

	public function testErasedCommentHidesAuthorAndText() {
		$page = $this->gtPage();
		$author = $this->gtUser();
		$post = $this->gtPost( $author, $page, 'private stuff' );
		$moderation = $this->gtService( 'ModerationService' );
		$sysop = $this->getTestSysop()->getAuthority();
		$moderation->moderate( $sysop, 'delete', [ $post->getId() ], '' );
		$moderation->moderate( $sysop, 'erase', [ $post->getId() ], '' );
		$this->setGroupPermissions( 'commentmod', 'glowthread-moderate', true );
		$data = $this->getData( $page, [ 'sort' => 'hot' ], $this->gtUser( [ 'commentmod' ] ) );
		$this->assertSame( 'erased', $data['comments'][0]['status'] );
		$this->assertSame( [], $data['comments'][0]['segments'] );
		$this->assertNull( $data['comments'][0]['author']['name'] );
		$this->assertSame( [], $this->getData( $page, [ 'sort' => 'hot' ], $this->anon() )['comments'] );
	}
}
