<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Service;

use MediaWiki\Block\BlockManager;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Policy\WriteGuard;
use MediaWiki\Extension\GlowThread\Service\ReportService;
use MediaWiki\Extension\GlowThread\Store\PostStore;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Extension\GlowThread\Tests\Integration\ReporterTestTrait;
use MediaWikiIntegrationTestCase;
use Wikimedia\ScopedCallback;

/**
 * Regression guards for the cost of writes: lookups that must not be repeated per comment or
 * per reporter.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Service\ModerationService
 * @covers \MediaWiki\Extension\GlowThread\Service\ReportService
 * @covers \MediaWiki\Extension\GlowThread\Policy\WriteGuard
 */
class WritePathQueryCountTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;
	use ReporterTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
	}

	/**
	 * A post store that counts getById() calls
	 */
	private function newCountingPostStore(): PostStore {
		return new class( $this->getServiceContainer()->getConnectionProvider() ) extends PostStore {
			public int $byIdCalls = 0;

			public function getById( int $id, bool $primary = false, bool $forUpdate = false ): ?PostRecord {
				$this->byIdCalls++;
				return parent::getById( $id, $primary, $forUpdate );
			}
		};
	}

	public static function provideBulkActions(): array {
		return [
			'delete visible comments' => [ 'delete', false, 'deleted' ],
			'approve held comments' => [ 'approve', true, 'visible' ],
		];
	}

	/**
	 * Inside the moderation transaction, which keeps every row it touched locked until the end
	 * of the request, each comment is read twice (locked, then as the action left it), read access
	 * to its page is checked once per page, and the hook arguments are left for after the commit.
	 *
	 * @dataProvider provideBulkActions
	 */
	public function testBulkModerationReadsEachCommentTwiceAndEachPageOnce(
		string $action, bool $held, string $statusAfter
	) {
		if ( $held ) {
			$this->gtSetFilter( 'holdword' );
		}
		$page = $this->gtPage();
		$posts = [];
		for ( $i = 0; $i < 5; $i++ ) {
			// One author each: an author may have only a few held comments
			$posts[] = $this->gtPost( $this->gtUser(), $page, ( $held ? 'holdword ' : '' ) . "Comment $i" );
		}

		$real = $this->gtService( 'WriteGuard' );
		$pageChecks = 0;
		$guard = $this->createMock( WriteGuard::class );
		$guard->method( 'checkModerate' )->willReturnCallback( [ $real, 'checkModerate' ] );
		$guard->method( 'canModerateOn' )->willReturnCallback(
			static function ( ...$args ) use ( $real, &$pageChecks ) {
				$pageChecks++;
				return $real->canModerateOn( ...$args );
			}
		);
		$this->setService( 'GlowThread.WriteGuard', $guard );
		$store = $this->newCountingPostStore();
		$this->setService( 'GlowThread.PostStore', $store );

		// As in a web request: the post-send updates (the hooks) wait until after moderate()
		$noUpdates = DeferredUpdates::preventOpportunisticUpdates();
		$status = $this->gtService( 'ModerationService' )->moderate(
			$this->getTestSysop()->getAuthority(),
			$action,
			array_map( static fn ( PostRecord $p ) => $p->getId(), $posts ),
			''
		);
		$byIdCalls = $store->byIdCalls;
		ScopedCallback::consume( $noUpdates );
		// The hooks then run with arguments read after the commit
		DeferredUpdates::doUpdates();

		$this->assertStatusGood( $status );
		$this->assertSame( [ 'success' ], array_unique( array_column( $status->getValue(), 'result' ) ) );
		$this->assertSame( [ $statusAfter ], array_unique( array_column( $status->getValue(), 'status' ) ) );
		$this->assertSame( 1, $pageChecks, 'read access to the page is checked once' );
		$this->assertSame( 10, $byIdCalls, 'each comment is read locked, then once after the change' );
	}

	public function testReportersOnTheirFirstWeekAreRuledOutWithoutBlockLookups() {
		$this->overrideConfigValue( 'GlowThreadAutoHoldReporters', 3 );
		$post = $this->gtPost( $this->gtUser(), $this->gtPage(), 'A contested comment' );
		$services = $this->getServiceContainer();
		$real = $services->getBlockManager();
		$blockLookups = 0;
		$blockManager = $this->createMock( BlockManager::class );
		$blockManager->method( 'getBlock' )->willReturnCallback(
			static function ( ...$args ) use ( $real, &$blockLookups ) {
				$blockLookups++;
				return $real->getBlock( ...$args );
			}
		);
		$reports = new ReportService(
			$this->gtService( 'WriteGuard' ),
			$this->gtService( 'PostStore' ),
			$this->gtService( 'ReportStore' ),
			$this->gtService( 'PageEligibility' ),
			$this->gtService( 'SettingsService' ),
			$this->gtService( 'TextProcessor' ),
			$services->getActorNormalization(),
			$services->getActorStore(),
			$blockManager,
			$this->gtService( 'CdnPurger' ),
			$this->gtService( 'HookRunner' ),
			$services->getUserFactory()
		);

		// Accounts registered today, reporting one after another: every report from the third
		// on checks all reporters again
		for ( $i = 0; $i < 5; $i++ ) {
			$status = $reports->report( $this->gtUser(), $post->getId(), 'spam', '' );
			$this->assertStatusGood( $status );
			$this->assertFalse( $status->getValue()['held'] );
		}
		$this->assertSame( 0, $blockLookups, 'a reporter whose account does not count needs no block lookup' );
		$this->assertTrue( $this->gtReload( $post )->isVisible() );

		// Established reporters are still checked for blocks (each report checks the ones so
		// far: 1, then 2, then 3), and three of them hold the comment
		for ( $i = 0; $i < 3; $i++ ) {
			$status = $reports->report( $this->gtEstablishedReporter(), $post->getId(), 'spam', '' );
			$this->assertStatusGood( $status );
		}
		$this->assertTrue( $status->getValue()['held'] );
		$this->assertSame( 6, $blockLookups );
		$this->assertTrue( $this->gtReload( $post )->isHeld() );
	}
}
