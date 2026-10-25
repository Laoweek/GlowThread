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
 * Reports are CheckUser private events with the reporter's IP address, like new comments, so a
 * checkuser can link accounts that mass-report comments to hide them.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\CheckUser\CheckUserHooks
 */
class CheckUserReportTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;

	private const IP = '198.51.100.77';

	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'CheckUser' );
		$this->setUpGlowThread();
		$request = new FauxRequest();
		$request->setIP( self::IP );
		RequestContext::getMain()->setRequest( $request );
	}

	/**
	 * @return \stdClass[] cu_private_event rows of glowthread/report
	 */
	private function reportEvents(): array {
		DeferredUpdates::doUpdates();
		return iterator_to_array( $this->getDb()->newSelectQueryBuilder()
			->select( [ 'cupe_actor', 'cupe_page', 'cupe_ip_hex', 'cupe_params', 'cupe_namespace', 'cupe_title' ] )
			->from( 'cu_private_event' )
			->where( [ 'cupe_log_type' => 'glowthread', 'cupe_log_action' => 'report' ] )
			->caller( __METHOD__ )
			->fetchResultSet(), false );
	}

	public function testReportIsRecordedWithTheReportersIp() {
		$page = $this->gtPage();
		$post = $this->gtPost( $this->gtUser(), $page, 'target' );
		$reporter = $this->gtUser();
		$this->assertStatusGood( $this->gtService( 'ReportService' )->report(
			$reporter, $post->getId(), 'abuse', 'a note about someone'
		) );

		$events = $this->reportEvents();
		$this->assertCount( 1, $events );
		$actorId = $this->getServiceContainer()->getActorNormalization()->findActorId( $reporter, $this->getDb() );
		$this->assertSame( $actorId, (int)$events[0]->cupe_actor );
		$this->assertSame( $page->getId(), (int)$events[0]->cupe_page );
		$this->assertSame( IPUtils::toHex( self::IP ), $events[0]->cupe_ip_hex );
		$params = unserialize( $events[0]->cupe_params );
		$this->assertSame( [ '4::comment' => $post->getId(), '6::seq' => $post->getSeq() ], $params, 'no note' );

		// A repeated report stores nothing and records nothing
		$this->gtService( 'ReportService' )->report( $reporter, $post->getId(), 'spam', '' );
		$this->assertCount( 1, $this->reportEvents() );
		$this->assertSame( [], $this->gtLogRows( 'glowthread', 'report' ), 'not in Special:Log' );
	}

	public function testReportRendersInCheckUser() {
		$post = $this->gtPost( $this->gtUser(), $this->gtPage(), 'target' );
		$this->gtService( 'ReportService' )->report( $this->gtUser(), $post->getId(), 'spam', '' );
		$row = $this->reportEvents()[0];
		$entry = new ManualLogEntry( 'glowthread', 'report' );
		$entry->setPerformer( $this->getTestSysop()->getUser() );
		$entry->setTarget( Title::makeTitle( (int)$row->cupe_namespace, $row->cupe_title ) );
		$entry->setParameters( unserialize( $row->cupe_params ) );
		$formatter = $this->getServiceContainer()->getLogFormatterFactory()->newFromEntry( $entry );
		$formatter->setContext( RequestContext::getMain() );
		// Special:CheckUser shows the HTML action text
		$html = $formatter->getActionText();
		$this->assertStringContainsString( 'reported', $html );
		$this->assertStringContainsString( 'GlowThreadLink/' . $post->getId(), $html );
		$text = $formatter->getPlainActionText();
		$this->assertStringContainsString( (string)$post->getSeq(), $text );
		$this->assertStringContainsString( 'GlowThread test page', $text );
	}
}
