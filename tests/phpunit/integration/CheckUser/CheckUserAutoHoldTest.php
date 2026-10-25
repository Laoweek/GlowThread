<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\CheckUser;

use MediaWiki\Context\RequestContext;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Extension\GlowThread\Tests\Integration\ReporterTestTrait;
use MediaWiki\Request\FauxRequest;
use MediaWikiIntegrationTestCase;
use Wikimedia\IPUtils;

/**
 * An automatic hold is logged as the system user, in the request of the report that caused it.
 * CheckUser filed that reporter's IP address, XFF and user agent under the system user, so a
 * check of the system user listed every such reporter. The hold is recorded as done by the
 * software now: IP 127.0.0.1, no XFF and no user agent. The reporter's own report record keeps
 * their data.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\CheckUser\CheckUserHooks
 */
class CheckUserAutoHoldTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;
	use ReporterTestTrait;

	private const IP = '198.51.100.77';

	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'CheckUser' );
		$this->setUpGlowThread();
		$this->overrideConfigValue( 'GlowThreadAutoHoldReporters', 1 );
		$request = new FauxRequest();
		$request->setIP( self::IP );
		$request->setHeader( 'X-Forwarded-For', '203.0.113.9' );
		$request->setHeader( 'User-Agent', 'Reporter browser' );
		RequestContext::getMain()->setRequest( $request );
	}

	public static function providePublicLog() {
		yield 'public log' => [ true ];
		yield 'log for moderators only' => [ false ];
	}

	/**
	 * @dataProvider providePublicLog
	 */
	public function testAutoHoldIsRecordedWithoutTheReportersData( bool $publicLog ) {
		$this->overrideConfigValue( 'GlowThreadPublicLog', $publicLog );
		$post = $this->gtPost( $this->gtUser(), $this->gtPage(), 'target' );
		$this->assertStatusGood( $this->gtService( 'ReportService' )->report(
			$this->gtEstablishedReporter(), $post->getId(), 'spam', ''
		) );
		$this->assertTrue( $this->gtReload( $post )->isHeld() );
		DeferredUpdates::doUpdates();

		$db = $this->getDb();
		$logId = (int)$this->gtLogRows( 'glowthread', 'autohold' )[0]->log_id;
		// MediaWiki 1.43 keeps the user agent in the row, later versions in cu_useragent
		$hasAgentColumn = $db->fieldExists( 'cu_log_event', 'cule_agent' );
		$qb = $db->newSelectQueryBuilder()
			->select( [ 'cule_ip_hex', 'cule_xff', 'cule_xff_hex' ] )
			->from( 'cu_log_event' )
			->where( [ 'cule_log_id' => $logId ] )
			->caller( __METHOD__ );
		if ( $hasAgentColumn ) {
			$qb->field( 'cule_agent', 'agent' );
		} else {
			$qb->join( 'cu_useragent', null, 'cuua_id = cule_agent_id' )->field( 'cuua_text', 'agent' );
		}
		$rows = iterator_to_array( $qb->fetchResultSet(), false );
		$this->assertCount( 1, $rows );
		$this->assertSame( IPUtils::toHex( '127.0.0.1' ), $rows[0]->cule_ip_hex );
		$this->assertSame( '', $rows[0]->cule_xff );
		$this->assertNull( $rows[0]->cule_xff_hex );
		$this->assertSame( '', $rows[0]->agent );

		$reportIp = $db->newSelectQueryBuilder()
			->select( 'cupe_ip_hex' )
			->from( 'cu_private_event' )
			->where( [ 'cupe_log_type' => 'glowthread', 'cupe_log_action' => 'report' ] )
			->caller( __METHOD__ )
			->fetchField();
		$this->assertSame( IPUtils::toHex( self::IP ), $reportIp, 'the report record keeps the reporter\'s IP' );
	}
}
