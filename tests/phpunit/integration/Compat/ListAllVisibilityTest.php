<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Compat;

use MediaWiki\Api\ApiUsageException;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Permissions\Authority;
use MediaWiki\Tests\Api\ApiTestCase;

/**
 * action=flowthread&type=listall lists only comments on pages whose comments the moderator may
 * see, as the moderation queue does.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Compat\LegacyListService
 * @covers \MediaWiki\Extension\GlowThread\Query\ModerationQueryService
 */
class ListAllVisibilityTest extends ApiTestCase {
	use GlowThreadTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
		$this->overrideConfigValue( 'GlowThreadLegacyCompat', true );
		$this->setGroupPermissions( 'commentmod', 'glowthread-moderate', true );
	}

	private function call( array $params, Authority $performer ): array {
		return $this->doApiRequest( $params + [ 'action' => 'flowthread' ], null, false, $performer )[0]['flowthread'];
	}

	/**
	 * Security review I9: listall returned the comments and title of a page the moderator
	 * cannot read, while type=list refused that page.
	 */
	public function testListAllLeavesOutUnreadablePages() {
		$staff = $this->gtPage( 'Staff only page' );
		$open = $this->gtPage();
		// Oldest first: visible, hidden, visible, hidden
		$older = $this->gtPost( $this->gtUser(), $open, 'OPEN-PAGE-TEXT older' );
		$this->gtPost( $this->gtUser(), $staff, 'STAFF-ONLY-SECRET one' );
		$newer = $this->gtPost( $this->gtUser(), $open, 'OPEN-PAGE-TEXT newer' );
		$this->gtPost( $this->gtUser(), $staff, 'STAFF-ONLY-SECRET two' );
		$visibleIds = [ (string)$newer->getId(), (string)$older->getId() ];
		$mod = $this->gtUser( [ 'commentmod' ] );
		$staffId = $staff->getId();
		$modName = $mod->getName();
		$this->setTemporaryHook( 'getUserPermissionsErrors',
			static function ( $title, $user, $action, &$result ) use ( $staffId, $modName ) {
				if ( $action === 'read' && $title->getArticleID() === $staffId && $user->getName() === $modName ) {
					$result = false;
					return false;
				}
			}
		);
		try {
			$this->call( [ 'type' => 'list', 'pageid' => $staffId ], $mod );
			$this->fail( 'type=list refuses the page' );
		} catch ( ApiUsageException $e ) {
			// Expected
		}

		$all = $this->call( [ 'type' => 'listall', 'filter' => 'all' ], $mod );
		$this->assertSame( $visibleIds, array_column( $all['posts'], 'id' ) );
		$this->assertStringNotContainsString( 'Staff only page', json_encode( $all ) );
		$this->assertSame(
			[], $this->call( [ 'type' => 'listall', 'filter' => 'all', 'pageid' => $staffId ], $mod )['posts']
		);

		// Offsets count only the comments shown, so no page is short and none tells how many
		// hidden comments match
		foreach ( [ 0, 1 ] as $offset ) {
			$page = $this->call( [ 'type' => 'listall', 'filter' => 'all', 'limit' => 1, 'offset' => $offset ], $mod );
			$this->assertSame( [ $visibleIds[$offset] ], array_column( $page['posts'], 'id' ), "offset $offset" );
			$this->assertSame( $offset === 0, $page['more'], "offset $offset" );
		}
		$keyword = $this->call( [ 'type' => 'listall', 'filter' => 'all', 'limit' => 1, 'keyword' => 'STAFF' ], $mod );
		$this->assertSame( [ [], false ], [ $keyword['posts'], $keyword['more'] ] );

		// A moderator who can read the page still sees it
		$sysopAll = $this->call( [ 'type' => 'listall', 'filter' => 'all' ], $this->getTestSysop()->getUser() );
		$this->assertCount( 4, $sysopAll['posts'] );
		$this->assertContains( 'Staff only page', array_column( $sysopAll['posts'], 'title' ) );
	}

	/**
	 * Security review I5, for listall: comments of a page deleted with suppression are left out
	 * for moderators who may not see suppressed revisions.
	 */
	public function testListAllLeavesOutSuppressedPages() {
		$page = $this->gtPage( 'Suppressed page' );
		$this->gtPost( $this->gtUser(), $page, 'SUPPRESSED-PAGE-MARKER' );
		$services = $this->getServiceContainer();
		$this->assertStatusGood( $services->getDeletePageFactory()
			->newDeletePage(
				$services->getWikiPageFactory()->newFromID( $page->getId() ),
				$this->getTestUser( [ 'sysop', 'suppress' ] )->getAuthority()
			)
			->setSuppress( true )
			->deleteUnsafe( 'test' ) );

		$params = [ 'type' => 'listall', 'filter' => 'all' ];
		$this->assertSame( [], $this->call( $params, $this->gtUser( [ 'commentmod' ] ) )['posts'] );
		$this->setGroupPermissions( 'commentmod', 'viewsuppressed', true );
		$this->assertCount( 1, $this->call( $params, $this->gtUser( [ 'commentmod' ] ) )['posts'] );
	}
}
