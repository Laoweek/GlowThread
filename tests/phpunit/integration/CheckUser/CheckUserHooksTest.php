<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\CheckUser;

use ManualLogEntry;
use MediaWiki\Context\RequestContext;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use Wikimedia\IPUtils;

/**
 * New comments are CheckUser private events with the author's IP and user agent, without a
 * RecentChanges or Special:Log row. Moderation entries reach CheckUser through RecentChanges, or
 * directly while the publicLog setting keeps them out of it.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\CheckUser\CheckUserHooks
 */
class CheckUserHooksTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;

	private const IP = '198.51.100.23';
	private const XFF = '203.0.113.9';
	private const UA = 'GlowThreadTest/1.0 (CheckUser check)';

	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'CheckUser' );
		$this->setUpGlowThread();
		$request = new FauxRequest();
		$request->setIP( self::IP );
		$request->setHeader( 'User-Agent', self::UA );
		$request->setHeader( 'X-Forwarded-For', self::XFF );
		RequestContext::getMain()->setRequest( $request );
	}

	/**
	 * cu_private_event rows for glowthread/post, with the user agent resolved on every schema
	 * (REL1_43 keeps it in cupe_agent, later branches in cu_useragent).
	 *
	 * @return array[]
	 */
	private function privateEvents(): array {
		DeferredUpdates::doUpdates();
		$db = $this->getDb();
		$rows = $db->newSelectQueryBuilder()
			->select( '*' )
			->from( 'cu_private_event' )
			->where( [ 'cupe_log_type' => 'glowthread', 'cupe_log_action' => 'post' ] )
			->orderBy( 'cupe_id' )
			->caller( __METHOD__ )
			->fetchResultSet();
		$out = [];
		foreach ( $rows as $row ) {
			$agent = $row->cupe_agent ?? '';
			if ( ( $agent === '' || $agent === null ) && !empty( $row->cupe_agent_id ) ) {
				$agent = $db->newSelectQueryBuilder()
					->select( 'cuua_text' )
					->from( 'cu_useragent' )
					->where( [ 'cuua_id' => $row->cupe_agent_id ] )
					->caller( __METHOD__ )
					->fetchField();
			}
			$out[] = [
				'actor' => (int)$row->cupe_actor,
				'page' => (int)$row->cupe_page,
				'ipHex' => $row->cupe_ip_hex,
				'xff' => $row->cupe_xff,
				'agent' => $agent,
				'params' => unserialize( $row->cupe_params ),
			];
		}
		return $out;
	}

	public function testNewCommentIsRecordedWithIpAndUserAgent() {
		$page = $this->gtPage();
		$author = $this->gtUser();
		$post = $this->gtPost( $author, $page, 'Hello' );

		$events = $this->privateEvents();
		$this->assertCount( 1, $events );
		$event = $events[0];
		$actorId = $this->getServiceContainer()->getActorNormalization()
			->findActorId( $author, $this->getDb() );
		$this->assertSame( $actorId, $event['actor'] );
		$this->assertSame( $page->getId(), $event['page'] );
		$this->assertSame( IPUtils::toHex( self::IP ), $event['ipHex'] );
		$this->assertSame( self::XFF, $event['xff'] );
		$this->assertSame( self::UA, $event['agent'] );
		$this->assertSame( $post->getId(), $event['params']['4::comment'] );
		$this->assertSame( $post->getSeq(), $event['params']['6::seq'] );
	}

	/**
	 * The CheckUser record is written after the response is sent
	 * (a POSTSEND deferred update), still in the author's request, so it keeps their IP address,
	 * X-Forwarded-For and user agent.
	 */
	public function testRecordIsWrittenAfterTheResponseWithTheAuthorsRequest() {
		$page = $this->gtPage();
		DeferredUpdates::doUpdates();
		$count = fn () => (int)$this->getDb()->newSelectQueryBuilder()->select( 'COUNT(*)' )
			->from( 'cu_private_event' )->where( [ 'cupe_log_type' => 'glowthread', 'cupe_log_action' => 'post' ] )
			->caller( __METHOD__ )->fetchField();
		$scope = DeferredUpdates::preventOpportunisticUpdates();
		$post = $this->gtPost( $this->gtUser(), $page, 'Hello' );
		$this->assertSame( 0, $count(), 'nothing before the response is sent' );
		DeferredUpdates::doUpdates( DeferredUpdates::PRESEND );
		$this->assertSame( 0, $count(), 'not a pre-send update either' );
		unset( $scope );
		DeferredUpdates::doUpdates( DeferredUpdates::POSTSEND );
		$events = $this->privateEvents();
		$this->assertCount( 1, $events );
		$this->assertSame( IPUtils::toHex( self::IP ), $events[0]['ipHex'] );
		$this->assertSame( self::XFF, $events[0]['xff'] );
		$this->assertSame( self::UA, $events[0]['agent'] );
		$this->assertSame( $post->getId(), $events[0]['params']['4::comment'] );
	}

	public function testHeldCommentIsRecordedToo() {
		$this->gtSetFilter( 'holdword' );
		$post = $this->gtPost( $this->gtUser(), $this->gtPage(), 'holdword' );
		$this->assertTrue( $post->isHeld() );
		$this->assertCount( 1, $this->privateEvents() );
	}

	public function testNoRecentChangeOrLogRowForPosts() {
		$this->gtPost( $this->gtUser(), $this->gtPage(), 'Hello' );
		DeferredUpdates::doUpdates();
		$this->assertSame( 0, (int)$this->getDb()->newSelectQueryBuilder()
			->select( 'COUNT(*)' )->from( 'recentchanges' )
			->where( [ 'rc_log_type' => 'glowthread' ] )
			->caller( __METHOD__ )->fetchField() );
		$this->assertSame( [], $this->gtLogRows( 'glowthread', 'post' ) );
	}

	public static function providePublicLog() {
		yield 'public log' => [ true ];
		yield 'log for moderators only' => [ false ];
	}

	/**
	 * @dataProvider providePublicLog
	 */
	public function testModerationReachesCheckUser( bool $publicLog ) {
		$this->overrideConfigValue( 'GlowThreadPublicLog', $publicLog );
		$post = $this->gtPost( $this->gtUser(), $this->gtPage(), 'Hello' );
		$this->gtModerate( 'delete', [ $post ], 'Spam' );
		DeferredUpdates::doUpdates();
		$logId = (int)$this->gtLogRows( 'glowthread', 'delete' )[0]->log_id;
		$this->assertSame( [ IPUtils::toHex( self::IP ) ], $this->getDb()->newSelectQueryBuilder()
			->select( 'cule_ip_hex' )->from( 'cu_log_event' )
			->where( [ 'cule_log_id' => $logId ] )
			->caller( __METHOD__ )->fetchFieldValues() );
	}

	public function testEntryRendersInCheckUser() {
		$post = $this->gtPost( $this->gtUser(), $this->gtPage(), 'Hello' );
		$row = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'cupe_log_type', 'cupe_log_action', 'cupe_params', 'cupe_namespace', 'cupe_title' ] )
			->from( 'cu_private_event' )
			->caller( __METHOD__ )
			->fetchRow();
		// Special:CheckUser formats private events with the log formatter of their type/action
		$entry = new ManualLogEntry( $row->cupe_log_type, $row->cupe_log_action );
		$entry->setPerformer( $this->getTestSysop()->getUser() );
		$entry->setTarget( Title::makeTitle( (int)$row->cupe_namespace, $row->cupe_title ) );
		$entry->setParameters( unserialize( $row->cupe_params ) );
		$formatter = $this->getServiceContainer()->getLogFormatterFactory()->newFromEntry( $entry );
		$formatter->setContext( RequestContext::getMain() );
		$text = $formatter->getPlainActionText();
		$this->assertStringContainsString( (string)$post->getSeq(), $text );
		$this->assertStringContainsString( 'GlowThread test page', $text );
	}
}
