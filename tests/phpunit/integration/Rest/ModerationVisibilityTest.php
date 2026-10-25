<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Rest;

use MediaWiki\Extension\GlowThread\Model\ReportReason;
use MediaWiki\Extension\GlowThread\Rest\PageHistoryHandler;
use MediaWiki\Extension\GlowThread\Rest\QueueHandler;
use MediaWiki\Extension\GlowThread\Rest\UserHistoryHandler;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Permissions\Authority;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * The moderation routes show comments only on pages whose comments the viewer may see: pages
 * they may read, and deleted pages unless the deletion was a suppression they may not see.
 * Reports imported from FlowThread name no reporter.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Query\ModerationQueryService
 * @covers \MediaWiki\Extension\GlowThread\Rest\QueueHandler
 * @covers \MediaWiki\Extension\GlowThread\Rest\UserHistoryHandler
 * @covers \MediaWiki\Extension\GlowThread\Rest\PageHistoryHandler
 */
class ModerationVisibilityTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;
	use HandlerTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
		// Moderators without any right over deleted or suppressed content
		$this->setGroupPermissions( 'commentmod', 'glowthread-moderate', true );
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
		return [ $response->getStatusCode(), json_decode( $response->getBody()->getContents(), true ) ];
	}

	private function queue( array $query, Authority $authority ): array {
		[ $code, $data ] = $this->call(
			new QueueHandler( $this->gtService( 'ModerationQueryService' ) ), [], $query, $authority
		);
		$this->assertSame( 200, $code, json_encode( $query ) );
		return $data;
	}

	private function userHistory( string $name, array $query, Authority $authority ): array {
		[ $code, $data ] = $this->call(
			new UserHistoryHandler( $this->gtService( 'ModerationQueryService' ) ),
			[ 'name' => $name ], $query, $authority
		);
		$this->assertSame( 200, $code );
		return $data;
	}

	/**
	 * @return array{0:int,1:array}
	 */
	private function pageHistory( int $pageId, Authority $authority ): array {
		return $this->call(
			new PageHistoryHandler( $this->gtService( 'ModerationQueryService' ) ),
			[ 'pageId' => $pageId ], [], $authority
		);
	}

	private static function ids( array $items ): array {
		return array_column( array_column( $items, 'comment' ), 'id' );
	}

	/**
	 * A per-page read restriction from an extension such as Lockdown, for one user.
	 *
	 * @param string $hook
	 * @param int $pageId
	 * @param string $userName
	 * @param int &$calls Counts the checks of that page for that user
	 */
	private function denyRead( string $hook, int $pageId, string $userName, int &$calls = 0 ): void {
		$this->setTemporaryHook( $hook,
			static function ( $title, $user, $action, &$result ) use ( $pageId, $userName, &$calls ) {
				if ( $action === 'read' && $title->getArticleID() === $pageId && $user->getName() === $userName ) {
					$calls++;
					$result = false;
					return false;
				}
			}
		);
	}

	private function deleteTestPage( int $pageId, bool $suppress ): void {
		$services = $this->getServiceContainer();
		$page = $services->getWikiPageFactory()->newFromID( $pageId );
		$this->assertStatusGood( $services->getDeletePageFactory()
			->newDeletePage( $page, $this->getTestUser( [ 'sysop', 'suppress' ] )->getAuthority() )
			->setSuppress( $suppress )
			->deleteUnsafe( 'test' ) );
	}

	/**
	 * Undeletes a page, lifting suppression, and returns its page ID.
	 */
	private function undeleteTestPage( string $title ): int {
		$services = $this->getServiceContainer();
		$this->assertStatusGood( $services->getUndeletePageFactory()
			->newUndeletePage(
				$services->getWikiPageFactory()->newFromTitle( Title::newFromText( $title ) ),
				$this->getTestUser( [ 'sysop', 'suppress' ] )->getAuthority()
			)
			->setUnsuppress( true )
			->undeleteUnsafe( 'test' ) );
		return $services->getPageStore()->getPageByText( $title )->getId();
	}

	public static function provideHooks() {
		return [ [ 'getUserPermissionsErrors' ], [ 'getUserPermissionsErrorsExpensive' ] ];
	}

	/**
	 * Security review R1: a moderator who cannot read a page read its comments, held and
	 * deleted ones included, its title and log reasons through the moderation routes.
	 *
	 * @dataProvider provideHooks
	 */
	public function testModerationRoutesRespectPageReadRestriction( string $hook ) {
		$staff = $this->gtPage( 'Staff only page' );
		$open = $this->gtPage();
		$author = $this->gtUser();
		$this->gtPost( $author, $staff, 'STAFF-ONLY-SECRET text' );
		$deleted = $this->gtPost( $author, $staff, 'STAFF-ONLY-DELETED text' );
		$this->gtModerate( 'delete', [ $deleted ], 'STAFF-REASON' );
		$visible = $this->gtPost( $author, $open, 'OPEN-PAGE-TEXT' );
		$mod = $this->gtUser( [ 'commentmod' ] );
		$calls = 0;
		$this->denyRead( $hook, $staff->getId(), $mod->getName(), $calls );
		$this->assertFalse( $mod->authorizeRead( 'read', $staff ), 'the moderator cannot read the page' );
		$callsPerCheck = $calls;
		$this->assertGreaterThan( 0, $callsPerCheck );

		$json = json_encode( $this->queue( [ 'tab' => 'all' ], $mod ) );
		$this->assertStringNotContainsString( 'STAFF-', $json, 'queue' );
		$this->assertStringNotContainsString( 'Staff only page', $json, 'queue: no title' );
		$this->assertStringContainsString( 'OPEN-PAGE-TEXT', $json );
		$this->assertStringNotContainsString( 'STAFF-', json_encode( $this->queue( [ 'tab' => 'deleted' ], $mod ) ) );
		$filtered = $this->queue( [ 'tab' => 'all', 'pageid' => $staff->getId() ], $mod );
		$this->assertSame( [], $filtered['items'] );
		$this->assertNull( $filtered['next'] );

		$calls = 0;
		$user = $this->userHistory( $author->getName(), [], $mod );
		$this->assertSame( $callsPerCheck, $calls, 'one check of the page for comments and log entries' );
		$this->assertSame( [ $visible->getId() ], self::ids( $user['comments'] ), 'user history' );
		$this->assertSame( [], $user['moderation'], 'user history: no log entry of the page' );
		$this->assertStringNotContainsString( 'STAFF-', json_encode( $user ) );

		[ $code, $history ] = $this->pageHistory( $staff->getId(), $mod );
		$this->assertSame( 404, $code, 'page history' );
		$this->assertSame( 'page-not-found', $history['errorKey'] );
		$this->assertStringNotContainsString( 'STAFF-', json_encode( $history ) );
		[ $code ] = $this->pageHistory( $open->getId(), $mod );
		$this->assertSame( 200, $code );

		// A moderator who can read the page still sees everything
		$sysop = $this->getTestSysop()->getUser();
		$this->assertStringContainsString(
			'STAFF-ONLY-SECRET', json_encode( $this->queue( [ 'tab' => 'all' ], $sysop ) )
		);
		$this->assertStringContainsString(
			'STAFF-REASON', json_encode( $this->userHistory( $author->getName(), [], $sysop )['moderation'] )
		);
		[ $code, $history ] = $this->pageHistory( $staff->getId(), $sysop );
		$this->assertSame( 200, $code );
		$this->assertSame( 'STAFF-REASON', $history['moderation'][0]['reason'] );
	}

	/**
	 * Skipped comments are replaced from later rows: no page comes back short while visible
	 * comments remain, and the cursor goes on after the skipped rows.
	 */
	public function testPagesAreFilledAcrossHiddenComments() {
		$open = $this->gtPage();
		$staff = $this->gtPage( 'Staff only page' );
		$author = $this->gtUser();
		// Oldest first: R1, U1, R2, U2, U3, U4
		$r1 = $this->gtPost( $author, $open, 'R1' );
		$u1 = $this->gtPost( $author, $staff, 'U1' );
		$r2 = $this->gtPost( $author, $open, 'R2' );
		$hidden = [];
		foreach ( [ 'U2', 'U3', 'U4' ] as $text ) {
			$hidden[$text] = $this->gtPost( $author, $staff, $text );
		}
		// Open reports, for sort=reports: U3 (2), U1 (1), R1 (1), then U4, U2, R2 (0)
		foreach ( [ [ $hidden['U3'], 2 ], [ $u1, 1 ], [ $r1, 1 ] ] as [ $post, $reports ] ) {
			$this->getDb()->newUpdateQueryBuilder()
				->update( 'glowthread_post' )
				->set( [ 'gtp_open_report_count' => $reports ] )
				->where( [ 'gtp_id' => $post->getId() ] )
				->caller( __METHOD__ )
				->execute();
		}
		$mod = $this->gtUser( [ 'commentmod' ] );
		$this->denyRead( 'getUserPermissionsErrors', $staff->getId(), $mod->getName() );
		$newest = [ $r2->getId(), $r1->getId() ];

		$pageThrough = static function ( callable $get, string $listKey ): array {
			$pages = [];
			$cursor = '';
			do {
				$data = $get( $cursor );
				$pages[] = self::ids( $data[$listKey] );
				$cursor = (string)$data['next'];
			} while ( $cursor !== '' && count( $pages ) < 10 );
			return $pages;
		};
		foreach ( [ 'new' => $newest, 'old' => array_reverse( $newest ), 'reports' => array_reverse( $newest ) ]
			as $sort => $order
		) {
			$this->assertSame(
				[ [ $order[0] ], [ $order[1] ] ],
				$pageThrough( fn ( $cursor ) => $this->queue(
					[ 'tab' => 'all', 'sort' => $sort, 'limit' => 1, 'cursor' => $cursor ], $mod
				), 'items' ),
				"queue, sort $sort, one per page"
			);
		}
		$this->assertSame( [ $newest ], $pageThrough(
			fn ( $cursor ) => $this->queue( [ 'tab' => 'all', 'limit' => 2, 'cursor' => $cursor ], $mod ), 'items'
		), 'queue, two per page' );
		$this->assertSame( [ [ $newest[0] ], [ $newest[1] ] ], $pageThrough(
			fn ( $cursor ) => $this->userHistory( $author->getName(), [ 'limit' => 1, 'cursor' => $cursor ], $mod ),
			'comments'
		), 'user history' );
	}

	/**
	 * A long run of hidden comments stops the scan after 500 rows: that page is empty, and its
	 * cursor leads on to the visible comment behind them.
	 */
	public function testScanStopsAfterMaxRowsAndGoesOn() {
		$open = $this->gtPage();
		$staff = $this->gtPage( 'Staff only page' );
		$visible = $this->gtPost( $this->gtUser(), $open, 'VISIBLE' );
		$hidden = $this->gtPost( $this->gtUser(), $staff, 'HIDDEN' );
		// 600 more hidden comments, newer than the visible one
		$db = $this->getDb();
		$template = (array)$db->newSelectQueryBuilder()
			->select( '*' )
			->from( 'glowthread_post' )
			->where( [ 'gtp_id' => $hidden->getId() ] )
			->caller( __METHOD__ )
			->fetchRow();
		unset( $template['gtp_id'] );
		for ( $chunk = 0; $chunk < 6; $chunk++ ) {
			$rows = [];
			for ( $i = 1; $i <= 100; $i++ ) {
				$rows[] = [ 'gtp_seq' => (int)$template['gtp_seq'] + $chunk * 100 + $i ] + $template;
			}
			$db->newInsertQueryBuilder()
				->insertInto( 'glowthread_post' )
				->rows( $rows )
				->caller( __METHOD__ )
				->execute();
		}
		$mod = $this->gtUser( [ 'commentmod' ] );
		$this->denyRead( 'getUserPermissionsErrors', $staff->getId(), $mod->getName() );

		$first = $this->queue( [ 'tab' => 'all' ], $mod );
		$this->assertSame( [], $first['items'] );
		$this->assertNotNull( $first['next'] );
		$rest = $this->queue( [ 'tab' => 'all', 'cursor' => $first['next'] ], $mod );
		$this->assertSame( [ $visible->getId() ], self::ids( $rest['items'] ) );
		$this->assertNull( $rest['next'] );
	}

	/**
	 * Security review I5: comments of a page deleted with suppression stayed readable to every
	 * moderator. Comments of a page deleted without suppression stay listed.
	 */
	public function testCommentsOfASuppressedPageAreHidden() {
		$this->setGroupPermissions( [
			'commentoversight' => [ 'glowthread-moderate' => true, 'viewsuppressed' => true ],
		] );
		$author = $this->gtUser();
		$suppressed = $this->gtPage( 'Suppressed page' );
		$suppressedId = $suppressed->getId();
		$marker = $this->gtPost( $author, $suppressed, 'SUPPRESSED-PAGE-MARKER' );
		$gone = $this->gtPost( $author, $suppressed, 'SUPPRESSED-DELETED' );
		$this->gtModerate( 'delete', [ $gone ], 'SUPPRESSED-REASON' );
		$deleted = $this->gtPage( 'Deleted page' );
		$deletedId = $deleted->getId();
		$this->gtPost( $author, $deleted, 'DELETED-PAGE-MARKER' );
		$this->deleteTestPage( $suppressedId, true );
		$this->deleteTestPage( $deletedId, false );
		$mod = $this->gtUser( [ 'commentmod' ] );
		$oversighter = $this->gtUser( [ 'commentoversight' ] );
		// Logged after the deletion, so the entry names Special:GlowThread/page/<id>, not the page.
		// Only a moderator who may see suppressed revisions can still act on these comments.
		$this->assertStatusGood( $this->gtService( 'ModerationService' )->moderate(
			$oversighter, 'delete', [ $marker->getId() ], 'SUPPRESSED-LATER-REASON'
		) );
		$this->assertTrue( $this->gtReload( $marker )->isDeleted() );

		$history = json_encode( $this->userHistory( $author->getName(), [], $mod ) );
		$this->assertStringNotContainsString( 'SUPPRESSED-', $history, 'user history' );
		$this->assertStringContainsString( 'DELETED-PAGE-MARKER', $history, 'a plain deletion stays listed' );
		$this->assertSame( [], $this->queue( [ 'tab' => 'all', 'pageid' => $suppressedId ], $mod )['items'] );
		$this->assertStringNotContainsString(
			'SUPPRESSED-', json_encode( $this->queue( [ 'tab' => 'deleted' ], $mod ) )
		);
		$this->assertSame( 404, $this->pageHistory( $suppressedId, $mod )[0] );
		$this->assertSame( 200, $this->pageHistory( $deletedId, $mod )[0] );

		// Holders of viewsuppressed see them, as on Special:Undelete
		$history = json_encode( $this->userHistory( $author->getName(), [], $oversighter ) );
		$this->assertStringContainsString( 'SUPPRESSED-PAGE-MARKER', $history );
		$this->assertStringContainsString( 'SUPPRESSED-REASON', $history );
		$this->assertStringContainsString( 'SUPPRESSED-LATER-REASON', $history );
		$this->assertCount( 2, $this->queue( [ 'tab' => 'all', 'pageid' => $suppressedId ], $oversighter )['items'] );
		$this->assertSame( 200, $this->pageHistory( $suppressedId, $oversighter )[0] );
	}

	/**
	 * The last deletion of a page decides: suppressed, undeleted and deleted again without
	 * suppression is listed, and the other way round is hidden.
	 */
	public function testTheLastDeletionDecides() {
		$author = $this->gtUser();
		$first = $this->gtPage( 'Suppressed first' );
		$this->gtPost( $author, $first, 'SUPPRESSED-FIRST' );
		$this->deleteTestPage( $first->getId(), true );
		$this->deleteTestPage( $this->undeleteTestPage( 'Suppressed first' ), false );
		$last = $this->gtPage( 'Suppressed last' );
		$this->gtPost( $author, $last, 'SUPPRESSED-LAST' );
		$this->deleteTestPage( $last->getId(), false );
		$this->deleteTestPage( $this->undeleteTestPage( 'Suppressed last' ), true );

		$history = json_encode( $this->userHistory( $author->getName(), [], $this->gtUser( [ 'commentmod' ] ) ) );
		$this->assertStringContainsString( 'SUPPRESSED-FIRST', $history );
		$this->assertStringNotContainsString( 'SUPPRESSED-LAST', $history );
	}

	/**
	 * Security review I8: reports imported from FlowThread named their reporters, and the
	 * reporter filter listed what one user reported there. FlowThread showed only counts.
	 */
	public function testImportedReportsNameNoReporter() {
		$post = $this->gtPost( $this->gtUser(), $this->gtPage(), 'Reported long ago and now' );
		$legacyReporter = $this->gtUser();
		$reporter = $this->gtUser();
		$this->gtService( 'ReportService' )->report( $reporter, $post->getId(), 'spam', '' );
		// A report as the importer writes it
		$db = $this->getDb();
		$db->newInsertQueryBuilder()
			->insertInto( 'glowthread_report' )
			->row( [
				'gtrp_post' => $post->getId(),
				'gtrp_actor' => $this->getServiceContainer()->getActorNormalization()
					->acquireActorId( $legacyReporter, $db ),
				'gtrp_reason' => ReportReason::LEGACY,
				'gtrp_note' => null,
				'gtrp_created' => null,
			] )
			->caller( __METHOD__ )
			->execute();
		$db->newUpdateQueryBuilder()
			->update( 'glowthread_post' )
			->set( [ 'gtp_open_report_count' => 2 ] )
			->where( [ 'gtp_id' => $post->getId() ] )
			->caller( __METHOD__ )
			->execute();
		$sysop = $this->getTestSysop()->getUser();

		$queue = $this->queue( [ 'tab' => 'reported' ], $sysop );
		$this->assertSame( [ $post->getId() ], self::ids( $queue['items'] ) );
		$reports = $queue['items'][0]['reports'];
		$this->assertSame(
			[ ReportReason::LEGACY, 'spam' ], array_column( $reports, 'reason' ), 'reasons and count stay'
		);
		$this->assertNull( $reports[0]['reporter']['name'] );
		$this->assertTrue( $reports[0]['reporter']['hidden'] );
		$this->assertSame( $reporter->getName(), $reports[1]['reporter']['name'] );
		$this->assertStringNotContainsString( json_encode( $legacyReporter->getName() ), json_encode( $queue ) );

		$this->assertSame(
			[], $this->queue( [ 'tab' => 'reported', 'reporter' => $legacyReporter->getName() ], $sysop )['items']
		);
		$this->assertSame( [ $post->getId() ], self::ids(
			$this->queue( [ 'tab' => 'reported', 'reporter' => $reporter->getName() ], $sysop )['items']
		) );
	}
}
