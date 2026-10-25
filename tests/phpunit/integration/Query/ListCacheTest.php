<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Query;

use GuzzleHttp\Psr7\Uri;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\GlowThread\Hooks\UserMergeHooks;
use MediaWiki\Extension\GlowThread\Query\ListCache;
use MediaWiki\Extension\GlowThread\Rest\ListHandler;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Extension\GlowThread\Tests\Integration\ReporterTestTrait;
use MediaWiki\Extension\GlowThread\Text\TextProcessor;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\RenameUser\RenameuserSQL;
use MediaWiki\Rest\RequestData;
use MediaWiki\Rest\ResponseFactory;
use MediaWiki\Rest\ResponseInterface;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\ChronologyProtector;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * The anonymous first pages that ListCache keeps are the lists computed from the database byte
 * for byte, right after every kind of write that changes what readers see.
 *
 * The object cache runs on a clock the test moves (as ConvertibleTimestamp's), past the hold-off
 * of every purge before each request: a list cached before a write is then fresh unless the
 * write purged it.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Query\ListCache
 * @covers \MediaWiki\Extension\GlowThread\Rest\ListHandler
 * @covers \MediaWiki\Extension\GlowThread\Query\CommentQueryService
 * @covers \MediaWiki\Extension\GlowThread\Cdn\CdnPurger
 * @covers \MediaWiki\Extension\GlowThread\Service\ReactionService
 * @covers \MediaWiki\Extension\GlowThread\Service\PageLifecycleService
 * @covers \MediaWiki\Extension\GlowThread\Hooks\PageLifecycleHooks
 * @covers \MediaWiki\Extension\GlowThread\Store\SettingsStore
 */
class ListCacheTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;
	use HandlerTestTrait;
	use ReporterTestTrait;

	/** Time of the object cache and of ConvertibleTimestamp */
	private float $now;

	/** Query services the list handler made */
	private int $queryServices = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
		$this->overrideConfigValues( [
			'GlowThreadAutoHoldReporters' => 2,
			'GlowThreadPopularMinLikes' => 1,
		] );
		// Yesterday noon: the minutes the test moves on stay on one day, and the clock stays
		// behind the real one, by which the database layer dates reads (the cache refuses values
		// read after its own time). Half a second in: the cache keeps purge times in whole
		// seconds and treats a value of that very instant as purged.
		$this->now = intdiv( time(), 86400 ) * 86400 - 43200 + 0.5;
		ConvertibleTimestamp::setFakeTime( fn () => (int)$this->now );
		$wanCache = new WANObjectCache( [
			'cache' => new HashBagOStuff(),
			'logger' => LoggerFactory::getInstance( 'objectcache' ),
		] );
		$wanCache->setMockTime( $this->now );
		$this->setService( 'MainWANObjectCache', $wanCache );
		// The check key of all pages exists on a running wiki: one made later counts as purged then
		$this->getServiceContainer()->get( 'GlowThread.ListCache' )->touchAll();
	}

	/**
	 * End the request (its updates after the response, such as storing a list in the cache),
	 * move the clocks on, past the hold-off of the purges so far, and start a new request (fresh
	 * services, as the next web request would have).
	 */
	private function nextRequest( int $seconds = 60 ): void {
		DeferredUpdates::doUpdates();
		$this->now += $seconds;
		$this->resetServices();
	}

	private function newHandler(): ListHandler {
		return new ListHandler(
			function () {
				$this->queryServices++;
				return $this->gtService( 'CommentQueryService' );
			},
			$this->gtService( 'Config' ),
			$this->gtService( 'CdnPurger' ),
			$this->gtService( 'ListCache' ),
			$this->gtService( 'PageEligibility' ),
			$this->getServiceContainer()->getTitleFormatter()
		);
	}

	private function anonGet( int $pageId, string $sort, ?int $limit = null ): ResponseInterface {
		$query = [ 'sort' => $sort ] + ( $limit ? [ 'limit' => $limit ] : [] );
		$purger = $this->gtService( 'CdnPurger' );
		return $this->executeHandler(
			$this->newHandler(),
			new RequestData( [
				'uri' => new Uri( $purger->getListRoutePath( $pageId ) . '?' . http_build_query( $query ) ),
				'pathParams' => [ 'pageId' => $pageId ],
				'queryParams' => $query,
				'headers' => [ 'Host' => $purger->getPurgeHost() ],
			] ),
			[], [], [], [],
			$this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' )
		);
	}

	/**
	 * The body the list route sends an anonymous reader, through ListCache
	 */
	private function cachedBody( int $pageId, string $sort, ?int $limit = null ): string {
		$response = $this->anonGet( $pageId, $sort, $limit );
		$this->assertSame( 200, $response->getStatusCode() );
		return $response->getBody()->getContents();
	}

	/**
	 * The body computed from the database at this moment, by the code before ListCache
	 */
	private function freshBody( int $pageId, string $sort, ?int $limit = null ): string {
		$query = $this->gtBaseQueryService();
		$anon = $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' );
		$page = $query->getPageForRead( $pageId, $anon );
		$this->assertNotNull( $page );
		$data = $query->getPageList(
			$query->newViewer( $anon ), $page, $sort, null, $limit ?? $this->gtService( 'Config' )->getPageSize()
		);
		return ( new ResponseFactory( [] ) )->encodeJson( $data );
	}

	/**
	 * A page with a pinned comment, a reply, likes, a held comment, a deleted comment and a
	 * mention of an account that does not exist yet.
	 *
	 * @return array
	 */
	private function makeFixture(): array {
		$this->gtSetFilter( 'holdword' );
		$page = $this->gtPage( 'GlowThread list cache' );
		$alice = $this->gtUser();
		$bob = $this->gtUser();
		$carol = $this->gtUser();
		$posts = [];
		$posts['first'] = $this->gtPost( $alice, $page, 'First by Alice' );
		$this->now += 10;
		$posts['reply'] = $this->gtPost( $bob, $page, 'Reply by Bob', $posts['first'] );
		$this->now += 10;
		$posts['second'] = $this->gtPost( $carol, $page, 'Second by Carol' );
		$this->now += 10;
		$posts['pinned'] = $this->gtPost( $bob, $page, 'Pinned by Bob' );
		$this->gtModerate( 'pin', [ $posts['pinned'] ] );
		$posts['held'] = $this->gtPost( $alice, $page, 'holdword by Alice' );
		$posts['deleted'] = $this->gtPost( $carol, $page, 'Deleted by Carol' );
		$deletion = $this->gtService( 'ModerationService' )->moderate(
			$this->getTestSysop()->getAuthority(), 'delete', [ $posts['deleted']->getId() ], ''
		);
		$this->assertStatusGood( $deletion );
		// The next account will get this ID (the IDs of new accounts only go up)
		$nextUserId = $this->gtUser()->getId() + 1;
		$posts['mention'] = $this->gtPost( $carol, $page, 'Hello ' . TextProcessor::mentionToken( $nextUserId ) );
		$reactions = $this->gtService( 'ReactionService' );
		$this->assertStatusGood( $reactions->setLike( $bob, $posts['first']->getId(), true ) );
		$this->assertStatusGood( $reactions->setLike( $carol, $posts['first']->getId(), true ) );
		return [
			'page' => $page,
			'alice' => $alice,
			'bob' => $bob,
			'carol' => $carol,
			'posts' => $posts,
			'deleteLogId' => $deletion->getValue()[0]['logId'],
			'nextUserId' => $nextUserId,
		];
	}

	public static function provideWrites(): array {
		// Write => whether anonymous readers see a change
		return [
			'visible comment' => [ 'postVisible', true ],
			'held comment' => [ 'postHeld', false ],
			'approve' => [ 'approve', true ],
			'delete' => [ 'delete', true ],
			'restore' => [ 'restore', true ],
			'pin' => [ 'pin', true ],
			'unpin' => [ 'unpin', true ],
			'undo' => [ 'undo', true ],
			'deleted by the author' => [ 'selfDelete', true ],
			'held by reports' => [ 'reportAutoHold', true ],
			'page state' => [ 'pageState', true ],
			'page move' => [ 'pageMove', true ],
			'undeletion into the page' => [ 'undeleteMerge', true ],
			'like' => [ 'like', true ],
			'unlike' => [ 'unlike', true ],
			'hideuser block' => [ 'hideUser', true ],
			'hideuser block lifted' => [ 'unhideUser', true ],
			'rename' => [ 'rename', true ],
			'merged accounts' => [ 'mergeUsers', true ],
			'settings' => [ 'settings', true ],
			'account created' => [ 'createUser', true ],
		];
	}

	/**
	 * @dataProvider provideWrites
	 */
	public function testCachedListMatchesTheDatabaseAfterWrite( string $write, bool $changes ) {
		$f = $this->makeFixture();
		$prepare = 'prepare' . ucfirst( $write );
		if ( method_exists( $this, $prepare ) ) {
			$this->$prepare( $f );
		}
		$pageId = $f['page']->getId();
		$this->nextRequest();
		$before = [];
		foreach ( [ 'hot', 'new' ] as $sort ) {
			$before[$sort] = $this->cachedBody( $pageId, $sort );
			$this->assertSame( $this->freshBody( $pageId, $sort ), $before[$sort], "$sort before" );
			// Limit 1: a hot list with a cursor
			$this->cachedBody( $pageId, $sort, 1 );
		}
		$this->nextRequest();
		$this->queryServices = 0;
		$this->assertSame( $before['hot'], $this->cachedBody( $pageId, 'hot' ) );
		$this->assertSame( 0, $this->queryServices, 'answered from the cache' );

		$this->nextRequest();
		$this->$write( $f );
		$this->nextRequest();
		foreach ( [ 'hot', 'new' ] as $sort ) {
			$fresh = $this->freshBody( $pageId, $sort );
			$this->assertSame( $fresh, $this->cachedBody( $pageId, $sort ), "$sort after $write" );
			$this->assertSame(
				$this->freshBody( $pageId, $sort, 1 ), $this->cachedBody( $pageId, $sort, 1 ), "$sort, limit 1"
			);
			if ( $changes ) {
				$this->assertNotSame( $before[$sort], $fresh, 'the write changes the list' );
			} else {
				$this->assertSame( $before[$sort], $fresh, 'the write changes nothing readers see' );
			}
		}
	}

	private function postVisible( array $f ): void {
		$this->gtPost( $f['carol'], $f['page'], 'A new comment' );
	}

	private function postHeld( array $f ): void {
		$this->assertTrue( $this->gtPost( $f['carol'], $f['page'], 'holdword again' )->isHeld() );
	}

	private function approve( array $f ): void {
		$this->gtModerate( 'approve', [ $f['posts']['held'] ] );
	}

	private function delete( array $f ): void {
		$this->gtModerate( 'delete', [ $f['posts']['second'] ] );
	}

	private function restore( array $f ): void {
		$this->gtModerate( 'restore', [ $f['posts']['deleted'] ] );
	}

	private function pin( array $f ): void {
		$this->gtModerate( 'pin', [ $f['posts']['second'] ] );
	}

	private function unpin( array $f ): void {
		$this->gtModerate( 'unpin', [ $f['posts']['pinned'] ] );
	}

	private function undo( array $f ): void {
		$status = $this->gtService( 'ModerationService' )->moderate(
			$this->getTestSysop()->getAuthority(),
			'undo',
			[ $f['posts']['deleted']->getId() ],
			'',
			[ $f['deleteLogId'] ]
		);
		$this->assertStatusGood( $status );
		$this->assertSame( 'success', $status->getValue()[0]['result'] );
	}

	private function selfDelete( array $f ): void {
		$this->assertStatusGood( $this->gtService( 'ModerationService' )->selfDelete(
			$f['carol'], $f['posts']['second']->getId(), ''
		) );
	}

	private function prepareReportAutoHold( array &$f ): void {
		$f['reporters'] = [ $this->gtEstablishedReporter(), $this->gtEstablishedReporter() ];
	}

	private function reportAutoHold( array $f ): void {
		$held = false;
		foreach ( $f['reporters'] as $reporter ) {
			$status = $this->gtService( 'ReportService' )->report(
				$reporter, $f['posts']['second']->getId(), 'spam', ''
			);
			$this->assertStatusGood( $status );
			$held = $status->getValue()['held'];
		}
		$this->assertTrue( $held );
	}

	private function pageState( array $f ): void {
		$this->gtSetPageState( $f['page'], 'locked' );
	}

	private function pageMove( array $f ): void {
		$this->assertStatusGood( $this->getServiceContainer()->getMovePageFactory()->newMovePage(
			Title::newFromPageIdentity( $f['page'] ), Title::makeTitle( NS_MAIN, 'GlowThread list cache moved' )
		)->move( $this->getTestSysop()->getUser(), 'test', false ) );
	}

	/**
	 * Delete a page with a comment and create its title again as the page of the fixture's
	 * comments, whose list the test then caches.
	 */
	private function prepareUndeleteMerge( array &$f ): void {
		$services = $this->getServiceContainer();
		$old = $this->gtPage( 'GlowThread list cache merged' );
		$this->gtPost( $f['alice'], $old, 'Comment of the deleted page' );
		$sysop = $this->getTestSysop()->getAuthority();
		$this->assertStatusGood( $services->getDeletePageFactory()
			->newDeletePage( $services->getWikiPageFactory()->newFromTitle( $old ), $sysop )
			->deleteUnsafe( 'test' ) );
		$this->editPage( Title::makeTitle( NS_MAIN, 'GlowThread_list_cache_merged' ), 'recreated' );
		$f['page'] = $services->getPageStore()->getPageByName( NS_MAIN, 'GlowThread_list_cache_merged' );
		$this->gtPost( $f['bob'], $f['page'], 'Comment of the new page' );
	}

	private function undeleteMerge( array $f ): void {
		$services = $this->getServiceContainer();
		$page = $services->getWikiPageFactory()->newFromTitle(
			Title::makeTitle( NS_MAIN, 'GlowThread_list_cache_merged' )
		);
		$this->assertStatusGood( $services->getUndeletePageFactory()
			->newUndeletePage( $page, $this->getTestSysop()->getAuthority() )
			->undeleteUnsafe( 'test' ) );
	}

	private function like( array $f ): void {
		$this->assertTrue( $this->gtService( 'ReactionService' )
			->setLike( $f['alice'], $f['posts']['second']->getId(), true )->getValue()['changed'] );
	}

	private function unlike( array $f ): void {
		$this->assertTrue( $this->gtService( 'ReactionService' )
			->setLike( $f['bob'], $f['posts']['first']->getId(), false )->getValue()['changed'] );
	}

	private function hide( User $user ): void {
		$this->assertStatusGood( $this->getServiceContainer()->getBlockUserFactory()->newBlockUser(
			$user,
			$this->getTestUser( [ 'sysop', 'suppress' ] )->getAuthority(),
			'infinity',
			'',
			[ 'isHideUser' => true, 'isAutoblocking' => false ]
		)->placeBlock() );
	}

	private function hideUser( array $f ): void {
		$this->hide( $f['alice'] );
	}

	private function prepareUnhideUser( array $f ): void {
		$this->hide( $f['alice'] );
	}

	private function unhideUser( array $f ): void {
		$this->assertStatusGood( $this->getServiceContainer()->getUnblockUserFactory()->newUnblockUser(
			$f['alice'], $this->getTestUser( [ 'sysop', 'suppress' ] )->getAuthority(), ''
		)->unblock() );
	}

	private function rename( array $f ): void {
		$rename = new RenameuserSQL(
			$f['bob']->getName(),
			'Renamed GlowThread user ' . mt_rand( 100000, 999999 ),
			$f['bob']->getId(),
			$this->getTestSysop()->getUser(),
			[ 'reason' => 'test' ]
		);
		// renameUser() replaced rename() in 1.44
		$this->assertTrue( method_exists( $rename, 'renameUser' ) ? $rename->renameUser()->isOK() : $rename->rename() );
	}

	/**
	 * Merge Carol into Alice as UserMerge does (UserMergeHooksTest): its fields, then
	 * MergeAccountFromTo.
	 */
	private function mergeUsers( array $f ): void {
		$handler = new UserMergeHooks(
			$this->getServiceContainer()->getConnectionProvider(),
			$this->gtService( 'PageLifecycleService' ),
			$this->gtService( 'PostStore' )
		);
		$fields = [];
		$handler->onUserMergeAccountFields( $fields );
		$actors = $this->getServiceContainer()->getActorNormalization();
		foreach ( $fields as $field ) {
			$this->getDb()->newUpdateQueryBuilder()
				->update( $field[0] )
				->set( [ $field['actorId'] => $actors->findActorId( $f['alice'], $this->getDb() ) ] )
				->where( [ $field['actorId'] => $actors->findActorId( $f['carol'], $this->getDb() ) ] )
				->caller( __METHOD__ )
				->execute();
		}
		$handler->onMergeAccountFromTo( $f['carol'], $f['alice'] );
	}

	private function settings( array $f ): void {
		$this->assertStatusGood( $this->gtService( 'SettingsService' )->update(
			[ 'maxLength' => 321 ], $this->getTestSysop()->getAuthority(), ''
		) );
	}

	/**
	 * An account is created with the ID that a comment already mentions. No hook is needed:
	 * every answer from the cache checks that such mentions still name nobody.
	 */
	private function createUser( array $f ): void {
		$user = User::createNew( 'GlowThread new account ' . mt_rand( 100000, 999999 ) );
		$this->assertSame( $f['nextUserId'], $user->getId() );
	}

	public function testHitNeedsNoQueryService() {
		$f = $this->makeFixture();
		$pageId = $f['page']->getId();
		$this->nextRequest();
		$this->queryServices = 0;
		$body = $this->cachedBody( $pageId, 'hot' );
		$this->assertSame( 1, $this->queryServices, 'a miss computes the list' );

		$this->nextRequest();
		$this->queryServices = 0;
		$this->assertSame( $body, $this->cachedBody( $pageId, 'hot' ) );
		$this->assertSame( 0, $this->queryServices, 'a hit makes no query service' );

		// Other sorts, limits and cursors are other lists
		$this->assertNotSame( $body, $this->cachedBody( $pageId, 'new' ) );
		$this->assertSame( 1, $this->queryServices );
	}

	public function testHitGivesTheCursorTheTimeOfTheRequest() {
		$page = $this->gtPage( 'GlowThread list cache cursor' );
		$user = $this->gtUser();
		foreach ( [ 'one', 'two', 'three' ] as $text ) {
			$this->gtPost( $user, $page, $text );
			$this->now += 3600;
		}
		$this->nextRequest();
		$primed = $this->cachedBody( $page->getId(), 'hot', 1 );
		$this->assertSame( $this->freshBody( $page->getId(), 'hot', 1 ), $primed );
		$this->assertNotNull( json_decode( $primed, true )['next'] );

		$this->nextRequest( 600 );
		$this->queryServices = 0;
		$body = $this->cachedBody( $page->getId(), 'hot', 1 );
		$this->assertSame( 0, $this->queryServices );
		$this->assertNotSame( $primed, $body, 'the cursor holds the time of this request' );
		$this->assertSame( $this->freshBody( $page->getId(), 'hot', 1 ), $body );
	}

	public function testListWithACommentDatedLaterIsNotCached() {
		$page = $this->gtPage( 'GlowThread list cache future' );
		$post = $this->gtPost( $this->gtUser(), $page, 'From the future' );
		// As an import of a wrongly dated comment would write it
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'glowthread_post' )
			->set( [ 'gtp_created' => $this->getDb()->timestamp( (int)$this->now + 86400 ) ] )
			->where( [ 'gtp_id' => $post->getId() ] )
			->caller( __METHOD__ )
			->execute();
		$this->gtService( 'ListCache' )->touchPage( $page->getId() );
		$this->nextRequest();
		$this->queryServices = 0;
		$this->assertSame( $this->freshBody( $page->getId(), 'hot' ), $this->cachedBody( $page->getId(), 'hot' ) );
		$this->nextRequest();
		$this->assertSame( $this->freshBody( $page->getId(), 'hot' ), $this->cachedBody( $page->getId(), 'hot' ) );
		$this->assertSame( 2, $this->queryServices, 'computed each time' );
	}

	public function testReadersWhoMayModerateAreNotCached() {
		$this->setGroupPermissions( '*', 'glowthread-moderate', true );
		$page = $this->gtPage( 'GlowThread list cache moderators' );
		$this->gtPost( $this->gtUser(), $page, 'A comment' );
		$this->nextRequest();
		$this->queryServices = 0;
		$this->cachedBody( $page->getId(), 'hot' );
		$this->nextRequest();
		$this->cachedBody( $page->getId(), 'hot' );
		$this->assertSame( 2, $this->queryServices );
	}

	public static function provideChangesAroundTheHooks(): array {
		return [
			'name hidden by a block written straight to the block store' => [ null, 'hideDirectly' ],
			'name shown again, its block removed straight from the block store'
				=> [ 'hideDirectly', 'unhideDirectly' ],
			'name changed straight in the database' => [ null, 'renameDirectly' ],
		];
	}

	/**
	 * Every answer from the cache checks the users it shows or hides: a name hidden, shown again
	 * or changed without any hook (CentralAuth's global suppression, scripts) shows at once.
	 *
	 * @dataProvider provideChangesAroundTheHooks
	 */
	public function testUsersAreCheckedOnEveryHit( ?string $prepare, string $change ) {
		$f = $this->makeFixture();
		$pageId = $f['page']->getId();
		if ( $prepare ) {
			$this->$prepare( $f['bob'] );
			$this->gtService( 'ListCache' )->touchAll();
		}
		$this->nextRequest();
		$before = $this->cachedBody( $pageId, 'new' );
		$this->nextRequest();
		$this->queryServices = 0;
		$this->assertSame( $before, $this->cachedBody( $pageId, 'new' ) );
		$this->assertSame( 0, $this->queryServices, 'answered from the cache' );

		$this->nextRequest();
		$this->$change( $f['bob'] );
		$this->nextRequest();
		$fresh = $this->freshBody( $pageId, 'new' );
		$this->assertNotSame( $before, $fresh );
		$this->assertSame( $fresh, $this->cachedBody( $pageId, 'new' ) );
		$this->nextRequest();
		$this->queryServices = 0;
		$this->assertSame( $fresh, $this->cachedBody( $pageId, 'new' ) );
		$this->assertSame( 0, $this->queryServices, 'the new list is cached for the next reader' );
	}

	/** The block hideDirectly() wrote */
	private ?DatabaseBlock $hideBlock = null;

	private function hideDirectly( User $user ): void {
		$store = $this->getServiceContainer()->getDatabaseBlockStore();
		$options = [
			'by' => $this->getTestUser( [ 'sysop', 'suppress' ] )->getUser(),
			'hideName' => true,
			'expiry' => 'infinity',
		];
		// DatabaseBlockStore::newUnsaved() came in 1.44
		$block = method_exists( $store, 'newUnsaved' )
			? $store->newUnsaved( $options + [ 'targetUser' => $user ] )
			: new DatabaseBlock( $options + [ 'address' => $user ] );
		$this->assertNotFalse( $store->insertBlock( $block ) );
		$this->hideBlock = $block;
	}

	private function unhideDirectly( User $user ): void {
		$this->assertTrue( $this->getServiceContainer()->getDatabaseBlockStore()->deleteBlock( $this->hideBlock ) );
	}

	/**
	 * A client that wrote in the last seconds (ChronologyProtector) reads the database, as it
	 * did before the cache: not a copy that may come from a replica without its write.
	 */
	public function testClientThatJustWroteSkipsTheCache() {
		$f = $this->makeFixture();
		$pageId = $f['page']->getId();
		$this->nextRequest();
		$this->cachedBody( $pageId, 'hot' );
		$user = $f['carol'];
		$query = $this->gtService( 'CommentQueryService' );
		$query->getPageList( $query->newViewer( $user ), $f['page'], 'hot', null, 20 );
		// A change that reached the database but not the cache, as a lagging replica would show
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'glowthread_post' )
			->set( [ 'gtp_like_count' => 9 ] )
			->where( [ 'gtp_id' => $f['posts']['second']->getId() ] )
			->caller( __METHOD__ )
			->execute();
		$this->nextRequest();
		$this->assertNotSame( $this->freshBody( $pageId, 'hot' ), $this->cachedBody( $pageId, 'hot' ), 'cached' );

		$chronologyProtector = $this->createMock( ChronologyProtector::class );
		$chronologyProtector->method( 'getTouched' )->willReturn( 1.0 );
		$this->setService( 'ChronologyProtector', $chronologyProtector );
		$this->nextRequest();
		$this->queryServices = 0;
		$this->assertSame( $this->freshBody( $pageId, 'hot' ), $this->cachedBody( $pageId, 'hot' ) );
		$this->assertSame( 1, $this->queryServices );
		$query = $this->gtService( 'CommentQueryService' );
		$base = $this->gtBaseQueryService();
		$this->assertSame(
			json_encode( $base->getPageList( $base->newViewer( $user ), $f['page'], 'hot', null, 20 ) ),
			json_encode( $query->getPageList( $query->newViewer( $user ), $f['page'], 'hot', null, 20 ) ),
			'logged in'
		);
	}

	/**
	 * A value read from a replica more than a few seconds behind is not stored, nor even made: it
	 * could miss a write whose purge's hold-off has ended
	 */
	public function testValueFromALaggingReplicaIsNotStored() {
		foreach ( [ [ 0, true ], [ 6, true ], [ 10, false ], [ false, false ] ] as $i => [ $lag, $stored ] ) {
			$replica = $this->createMock( IReadableDatabase::class );
			$replica->method( 'getSessionLagStatus' )->willReturn( [ 'lag' => $lag, 'since' => microtime( true ) ] );
			$provider = $this->createMock( IConnectionProvider::class );
			$provider->method( 'getReplicaDatabase' )->willReturn( $replica );
			$cache = new ListCache(
				$this->getServiceContainer()->getMainWANObjectCache(), $provider, $this->gtService( 'Config' )
			);
			$pageId = 900000 + $i;
			$made = 0;
			$build = static function () use ( &$made ) {
				$made++;
				return [ 'summary' ];
			};
			// The first read makes the page's check key, which counts as a purge then
			$this->assertNull( $cache->getSummary( $pageId, $build, true ) );
			$this->now += 60;
			// Made after the response
			$this->assertNull( $cache->getSummary( $pageId, $build, true ) );
			DeferredUpdates::doUpdates();
			$name = 'lag ' . var_export( $lag, true );
			$this->assertSame( $stored ? 1 : 0, $made, $name );
			$this->assertSame( $stored ? [ 'summary' ] : null, $cache->getSummary( $pageId, $build, true ), $name );
		}
	}

	/**
	 * During the hold-off of a purge it saw, a web server reads nothing more from the cache: no
	 * copy is up to date then. It reads again when the hold-off ends.
	 */
	public function testServerSkipsTheCacheInAHoldOffItSaw() {
		$store = new class extends HashBagOStuff {
			public int $reads = 0;

			/** @inheritDoc */
			protected function doGetMulti( array $keys, $flags = 0 ) {
				$this->reads++;
				return parent::doGetMulti( $keys, $flags );
			}
		};
		$wanCache = new WANObjectCache( [ 'cache' => $store ] );
		$wanCache->setMockTime( $this->now );
		$cache = new ListCache(
			$wanCache,
			$this->getServiceContainer()->getConnectionProvider(),
			$this->gtService( 'Config' ),
			null,
			null,
			new HashBagOStuff()
		);
		$pageId = 900100;
		$reads = static function () use ( $cache, $store, $pageId ) {
			$before = $store->reads;
			$cache->getSummary( $pageId, static fn () => [ 'summary' ], true );
			return $store->reads - $before;
		};
		$cache->touchPage( $pageId );
		$this->assertSame( 1, $reads(), 'the read that sees the purge' );
		$this->now += 5;
		$this->assertSame( 0, $reads(), 'none in its hold-off' );
		$this->now += 10;
		$this->assertSame( 1, $reads(), 'after it' );

		// A purge by a server whose clock is ahead: skipped for one hold-off from now at most
		$aheadNow = $this->now + 100;
		$ahead = new WANObjectCache( [ 'cache' => $store ] );
		$ahead->setMockTime( $aheadNow );
		( new ListCache( $ahead, $this->getServiceContainer()->getConnectionProvider() ) )->touchPage( $pageId );
		$this->assertSame( 1, $reads(), 'the read that sees the purge' );
		$this->now += 5;
		$this->assertSame( 0, $reads(), 'none for a while' );
		$this->now += 10;
		$this->assertSame( 1, $reads(), 'not longer than a hold-off' );
	}

	/**
	 * The facts that a stored list needs (CommentQueryService::getPageList()) are asked for only
	 * when the list may be stored: not during the hold-off of a purge
	 */
	public function testFactsAreAskedForOnlyForAListThatMayBeStored() {
		$pageId = 900200;
		$asked = [];
		$get = function () use ( $pageId, &$asked ) {
			$this->gtService( 'ListCache' )->getAnonResponse(
				$pageId,
				'new',
				[],
				static function ( bool $storable ) use ( &$asked ) {
					$asked[] = $storable;
					$facts = [ 'hidden' => [], 'missing' => [], 'from' => 0, 'until' => PHP_INT_MAX ];
					return [ [ 'comments' => [] ], $storable ? $facts : null ];
				},
				'json_encode',
				false
			);
		};
		$this->gtService( 'ListCache' )->touchPage( $pageId );
		$get();
		$this->nextRequest();
		$get();
		$this->assertSame( [ false, true ], $asked );
	}

	private function renameDirectly( User $user ): void {
		$name = 'Renamed directly ' . mt_rand( 100000, 999999 );
		$columns = [ 'user' => [ 'user_name', 'user_id' ], 'actor' => [ 'actor_name', 'actor_user' ] ];
		foreach ( $columns as $table => $fields ) {
			$this->getDb()->newUpdateQueryBuilder()
				->update( $table )
				->set( [ $fields[0] => $name ] )
				->where( [ $fields[1] => $user->getId() ] )
				->caller( __METHOD__ )
				->execute();
		}
	}

	public function testHeldCommentStaysOutOfTheCachedList() {
		$f = $this->makeFixture();
		$this->nextRequest();
		$body = $this->cachedBody( $f['page']->getId(), 'new' );
		$this->assertStringNotContainsString( 'holdword', $body );
		$this->assertStringNotContainsString( 'Deleted by Carol', $body );
		$this->assertStringContainsString( 'First by Alice', $body );
	}
}
