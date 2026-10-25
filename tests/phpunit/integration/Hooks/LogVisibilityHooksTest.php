<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Hooks;

use MediaWiki\Context\RequestContext;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\GlowThread\Hooks\LogVisibilityHooks;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\Authority;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Session\SessionId;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\Tests\Session\TestUtils;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use stdClass;
use Wikimedia\TestingAccessWrapper;

/**
 * With the publicLog setting off, GlowThread's log entries are left out of Special:Log,
 * Special:RecentChanges, watchlists, watchlist emails, Echo and the API for readers who cannot
 * moderate, and still shown to moderators in Special:Log.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Hooks\LogVisibilityHooks
 * @covers \MediaWiki\Extension\GlowThread\Log\ModerationLogger
 */
class LogVisibilityHooksTest extends ApiTestCase {
	use GlowThreadTestTrait;

	private const PAGE = 'GlowThread log visibility page';

	private User $reader;
	private int $logId;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
	}

	/** A moderation entry on PAGE, and an edit of PAGE, watched by $this->reader */
	private function moderate(): void {
		$this->reader = $this->gtUser();
		$post = $this->gtPost( $this->reader, $this->gtPage( self::PAGE ), 'Hello' );
		$this->gtModerate( 'delete', [ $post ] );
		$this->editPage( self::PAGE, 'Edited', '', NS_MAIN, $this->gtUser() );
		DeferredUpdates::doUpdates();
		$this->getServiceContainer()->getWatchlistManager()
			->addWatch( $this->reader, Title::newFromText( self::PAGE ) );
		$this->logId = (int)$this->gtLogRows( 'glowthread', 'delete' )[0]->log_id;
	}

	/** @return array<string,int[]> Log IDs each list shows the viewer, and whether it lists the edit */
	private function seenBy( Authority $viewer ): array {
		$logIds = array_column( $this->query(
			[ 'list' => 'logevents', 'leprop' => 'ids', 'lelimit' => 500 ], $viewer
		)['logevents'], 'logid' );
		$rc = $this->query(
			[ 'list' => 'recentchanges', 'rcprop' => 'ids|title|loginfo', 'rclimit' => 500 ], $viewer
		)['recentchanges'];
		$watchlist = $this->query(
			[ 'list' => 'watchlist', 'wlprop' => 'ids|title|loginfo', 'wllimit' => 500,
				'wlowner' => $this->reader->getName(), 'wltoken' => $this->watchlistToken() ],
			$viewer
		)['watchlist'];
		return [
			'logevents' => $logIds,
			'recentchanges' => array_column( $rc, 'logid' ),
			'watchlist' => array_column( $watchlist, 'logid' ),
			'special' => $this->renderRecentChanges( $viewer ),
			'editListed' => in_array( self::PAGE, array_column( $rc, 'title' ), true ),
		];
	}

	private function watchlistToken(): string {
		$options = $this->getServiceContainer()->getUserOptionsManager();
		$options->setOption( $this->reader, 'watchlisttoken', 'glowthread-test-token' );
		$options->saveOptions( $this->reader );
		return 'glowthread-test-token';
	}

	/** @return int[] The log IDs Special:RecentChanges links to */
	private function renderRecentChanges( Authority $viewer ): array {
		$context = RequestContext::getMain();
		$context->setRequest( new FauxRequest( [ 'days' => 1, 'limit' => 500, 'enhanced' => 0 ] ) );
		$context->setAuthority( $viewer );
		$context->setTitle( SpecialPage::getTitleFor( 'Recentchanges' ) );
		$page = $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'Recentchanges' );
		$page->setContext( $context );
		$page->execute( null );
		preg_match_all( '/data-mw-logid="(\d+)"/', $context->getOutput()->getHTML(), $m );
		return array_map( 'intval', $m[1] );
	}

	private function query( array $params, Authority $viewer ): array {
		return $this->doApiRequest( [ 'action' => 'query' ] + $params, null, false, $viewer )[0]['query'];
	}

	/**
	 * A user whose session allows only the rights of these grants, as a bot password or an OAuth
	 * consumer does.
	 *
	 * @param string[] $grants
	 */
	private function userWithGrants( array $grants ): User {
		$user = $this->gtUser();
		$backend = $this->getMockBuilder( stdClass::class )
			->addMethods( [ 'getAllowedUserRights', 'deregisterSession', 'getSessionId' ] )
			->getMock();
		$backend->method( 'getAllowedUserRights' )
			->willReturn( $this->getServiceContainer()->getGrantsInfo()->getGrantRights( $grants ) );
		$backend->method( 'getSessionId' )->willReturn( new SessionId( str_repeat( 'X', 32 ) ) );
		$request = $this->getMockBuilder( FauxRequest::class )->onlyMethods( [ 'getSession' ] )->getMock();
		$request->method( 'getSession' )->willReturn( TestUtils::getDummySession( $backend ) );
		TestingAccessWrapper::newFromObject( $user )->mRequest = $request;
		return $user;
	}

	public function testEveryoneSeesTheLogByDefault() {
		$this->moderate();
		$seen = $this->seenBy( $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' ) );
		foreach ( [ 'logevents', 'recentchanges', 'watchlist', 'special' ] as $list ) {
			$this->assertContains( $this->logId, $seen[$list], $list );
		}
	}

	public function testBotPasswordsAndOAuthSeeTheLogByDefault() {
		$this->moderate();
		$user = $this->userWithGrants( [ 'basic', 'editpage' ] );
		$this->assertTrue( $user->isAllowed( LogVisibilityHooks::VIEW_RIGHT ) );
		$this->assertContains( $this->logId, $this->seenBy( $user )['logevents'] );
	}

	/**
	 * Entries logged while the setting was on stay in RecentChanges, where readers no longer
	 * see them once it is off.
	 */
	public function testReadersDoNotSeeTheLogWhenTheSettingIsOff() {
		$this->moderate();
		$this->overrideConfigValue( 'GlowThreadPublicLog', false );
		$seen = $this->seenBy( $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' ) );
		foreach ( [ 'logevents', 'recentchanges', 'watchlist', 'special' ] as $list ) {
			$this->assertNotContains( $this->logId, $seen[$list], $list );
		}
		$this->assertTrue( $seen['editListed'] );
	}

	public function testModeratorsStillSeeTheLogWhenTheSettingIsOff() {
		$this->moderate();
		$this->overrideConfigValue( 'GlowThreadPublicLog', false );
		$seen = $this->seenBy( $this->getTestSysop()->getAuthority() );
		foreach ( [ 'logevents', 'recentchanges', 'watchlist', 'special' ] as $list ) {
			$this->assertContains( $this->logId, $seen[$list], $list );
		}
	}

	/**
	 * Entries logged while the setting is off get no RecentChanges row, which watchlist emails
	 * and other extensions would announce. Moderators find them in Special:Log.
	 */
	public function testEntriesLoggedWhileTheSettingIsOffStayOutOfRecentChanges() {
		$this->overrideConfigValue( 'GlowThreadPublicLog', false );
		$this->moderate();
		$seen = $this->seenBy( $this->getTestSysop()->getAuthority() );
		$this->assertContains( $this->logId, $seen['logevents'] );
		foreach ( [ 'recentchanges', 'watchlist', 'special' ] as $list ) {
			$this->assertNotContains( $this->logId, $seen[$list], $list );
		}
		$this->assertTrue( $seen['editListed'] );
	}

	public static function provideSetting() {
		yield 'on: as for other log entries' => [ true ];
		yield 'off' => [ false ];
	}

	/**
	 * @dataProvider provideSetting
	 */
	public function testWatchersAreToldOnlyWhileTheSettingIsOn( bool $publicLog ) {
		$echo = ExtensionRegistry::getInstance()->isLoaded( 'Echo' );
		$this->overrideConfigValues( [
			'GlowThreadPublicLog' => $publicLog,
			MainConfigNames::EnotifWatchlist => true,
			MainConfigNames::ShowUpdatedMarker => true,
		] );
		if ( $echo ) {
			$this->overrideConfigValue( 'EchoWatchlistNotifications', true );
		}
		$watcher = $this->gtUser();
		$post = $this->gtPost( $this->gtUser(), $this->gtPage( self::PAGE ), 'Hello' );
		$this->getServiceContainer()->getWatchlistManager()
			->addWatch( $watcher, Title::newFromText( self::PAGE ) );
		$emails = $this->getServiceContainer()->getJobQueueGroup()->get( 'enotifNotify' );
		$emailsBefore = $emails->getSize();

		$this->gtModerate( 'delete', [ $post ] );
		DeferredUpdates::doUpdates();

		$this->assertSame( $publicLog, $emails->getSize() > $emailsBefore, 'watchlist email' );
		$marker = $this->getDb()->newSelectQueryBuilder()
			->select( 'wl_notificationtimestamp' )
			->from( 'watchlist' )
			->where( [
				'wl_user' => $watcher->getId(),
				'wl_namespace' => NS_MAIN,
				'wl_title' => Title::newFromText( self::PAGE )->getDBkey(),
			] )
			->caller( __METHOD__ )
			->fetchField();
		$this->assertSame( $publicLog, $marker !== null, 'the changed marker of the watchlist' );
		if ( $echo ) {
			$events = $this->getDb()->newSelectQueryBuilder()
				->select( 'COUNT(*)' )
				->from( 'echo_event' )
				->where( [ 'event_type' => [ 'watchlist-change', 'minor-watchlist-change' ] ] )
				->caller( __METHOD__ )
				->fetchField();
			$this->assertSame( $publicLog, $events > 0, 'Echo watchlist notification' );
		}
	}
}
