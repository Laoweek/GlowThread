<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Hooks;

use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Hooks\PageLifecycleHooks
 * @covers \MediaWiki\Extension\GlowThread\Service\PageLifecycleService
 */
class PageLifecycleTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
	}

	private function listCount( int $pageId ): ?int {
		$query = $this->gtService( 'CommentQueryService' );
		$page = $this->getServiceContainer()->getPageStore()->getPageById( $pageId );
		if ( !$page ) {
			return null;
		}
		$anon = $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' );
		return count( $query->getPageList( $query->newViewer( $anon ), $page, 'new', null, 20 )['comments'] );
	}

	public function testDeleteHidesAndUndeleteRestores() {
		$page = $this->gtPage( 'GlowThread lifecycle page' );
		$pageId = $page->getId();
		$this->gtPost( $this->gtUser(), $page, 'one' );
		$this->gtPost( $this->gtUser(), $page, 'two' );
		$sysop = $this->getTestSysop()->getAuthority();
		$services = $this->getServiceContainer();

		$this->assertStatusGood( $services->getDeletePageFactory()
			->newDeletePage( $services->getWikiPageFactory()->newFromTitle( $page ), $sysop )
			->deleteUnsafe( 'test' ) );
		$this->assertNull( $this->listCount( $pageId ), 'the page is gone, so are its comments' );
		$this->assertSame( 2, (int)$this->getDb()->newSelectQueryBuilder()->select( 'COUNT(*)' )
			->from( 'glowthread_post' )->where( [ 'gtp_page' => $pageId ] )->caller( __METHOD__ )->fetchField(),
			'nothing is erased' );

		// makeTitle(), not newFromText(): the latter caches Title objects with their old page ID
		$title = Title::makeTitle( NS_MAIN, 'GlowThread_lifecycle_page' );
		$this->assertStatusGood( $services->getUndeletePageFactory()
			->newUndeletePage( $services->getWikiPageFactory()->newFromTitle( $title ), $sysop )
			->undeleteUnsafe( 'test' ) );
		$restoredId = Title::makeTitle( NS_MAIN, 'GlowThread_lifecycle_page' )->getArticleID();
		$this->assertSame( $pageId, $restoredId, 'undelete reuses the free page ID' );
		$this->assertSame( 2, $this->listCount( $restoredId ) );
	}

	public function testUndeleteIntoRecreatedPageMovesComments() {
		$page = $this->gtPage( 'GlowThread recreated page' );
		$oldId = $page->getId();
		$this->gtPost( $this->gtUser(), $page, 'old one' );
		$this->gtPost( $this->gtUser(), $page, 'old two' );
		$sysop = $this->getTestSysop()->getAuthority();
		$services = $this->getServiceContainer();
		$services->getDeletePageFactory()
			->newDeletePage( $services->getWikiPageFactory()->newFromTitle( $page ), $sysop )
			->deleteUnsafe( 'test' );

		// Re-created with a new ID, and commented on
		$this->editPage( Title::makeTitle( NS_MAIN, 'GlowThread_recreated_page' ), 'new content' );
		$newPage = $services->getPageStore()->getPageByName( NS_MAIN, 'GlowThread_recreated_page' );
		$this->assertNotSame( $oldId, $newPage->getId() );
		$this->gtPost( $this->gtUser(), $newPage, 'new one' );

		// Restoring the old revisions merges them into the existing page
		$this->assertStatusGood( $services->getUndeletePageFactory()
			->newUndeletePage(
				$services->getWikiPageFactory()->newFromTitle(
					Title::makeTitle( NS_MAIN, 'GlowThread_recreated_page' )
				),
				$sysop
			)
			->undeleteUnsafe( 'test' ) );
		$this->assertSame( 3, $this->listCount( $newPage->getId() ) );
		$seqs = $this->getDb()->newSelectQueryBuilder()->select( 'gtp_seq' )->from( 'glowthread_post' )
			->where( [ 'gtp_page' => $newPage->getId() ] )->orderBy( 'gtp_seq' )
			->caller( __METHOD__ )->fetchFieldValues();
		$this->assertSame( [ '1', '2', '3' ], array_map( 'strval', $seqs ), 'floors stay unique' );
		$next = $this->gtPost( $this->gtUser(), $newPage, 'after merge' );
		$this->assertSame( 4, $next->getSeq() );
	}

	private function delete( string $dbkey, $performer, bool $suppress = false ): void {
		$services = $this->getServiceContainer();
		$page = $services->getPageStore()->getPageByName( NS_MAIN, $dbkey );
		$this->assertStatusGood( $services->getDeletePageFactory()
			->newDeletePage( $services->getWikiPageFactory()->newFromTitle( $page ), $performer )
			->setSuppress( $suppress )
			->deleteUnsafe( 'test' ) );
	}

	private function undelete( string $dbkey, $performer, array $timestamps = [] ): int {
		$services = $this->getServiceContainer();
		$this->assertStatusGood( $services->getUndeletePageFactory()
			->newUndeletePage(
				$services->getWikiPageFactory()->newFromTitle( Title::makeTitle( NS_MAIN, $dbkey ) ), $performer
			)
			->setUndeleteOnlyTimestamps( $timestamps )
			->undeleteUnsafe( 'test' ) );
		return $services->getPageStore()->getPageByName( NS_MAIN, $dbkey )->getId();
	}

	/**
	 * Only the comments of page IDs whose revisions this undeletion restores
	 * come back. A routine clean-up that restores one good revision of a later incarnation of the
	 * title must not bring back the comments of an earlier, deliberately deleted incarnation.
	 */
	public function testPartialUndeleteRestoresOnlyItsOwnPageIds() {
		$dbkey = 'GlowThread_two_incarnations';
		$sysop = $this->getTestSysop()->getAuthority();
		ConvertibleTimestamp::setFakeTime( '20260101000000' );
		$first = $this->gtPage( 'GlowThread two incarnations' );
		$this->gtPost( $this->gtUser(), $first, 'removed with the first page' );
		$this->delete( $dbkey, $sysop );
		ConvertibleTimestamp::setFakeTime( '20260601000000' );
		$good = $this->editPage( Title::makeTitle( NS_MAIN, $dbkey ), 'new article' )->getNewRevision();
		$second = $this->getServiceContainer()->getPageStore()->getPageByName( NS_MAIN, $dbkey );
		$this->gtPost( $this->gtUser(), $second, 'on the second page' );
		ConvertibleTimestamp::setFakeTime( '20260602000000' );
		$this->editPage( Title::makeTitle( NS_MAIN, $dbkey ), 'vandalism' );
		$this->delete( $dbkey, $sysop );
		ConvertibleTimestamp::setFakeTime( '20260603000000' );
		$restoredId = $this->undelete( $dbkey, $sysop, [ $good->getTimestamp() ] );
		ConvertibleTimestamp::setFakeTime( false );

		$this->assertSame( $second->getId(), $restoredId );
		$this->assertSame( 1, $this->listCount( $restoredId ), 'only the second page\'s comment' );
		$this->assertSame( 1, (int)$this->getDb()->newSelectQueryBuilder()->select( 'COUNT(*)' )
			->from( 'glowthread_post' )->where( [ 'gtp_page' => $first->getId() ] )->caller( __METHOD__ )->fetchField(),
			'the first page\'s comment stays where it was' );
	}

	/**
	 * A page deleted with suppression, re-created,
	 * vandalised and cleaned up by a plain sysop. The suppressed page's comments stay hidden.
	 */
	public function testPlainSysopCannotBringBackCommentsOfASuppressedPage() {
		$dbkey = 'GlowThread_review_lifecycle';
		ConvertibleTimestamp::setFakeTime( '20260101000000' );
		$page = $this->gtPage( 'GlowThread review lifecycle' );
		$this->gtPost( $this->gtUser(), $page, 'private information about someone' );
		$this->delete( $dbkey, $this->getTestUser( [ 'sysop', 'suppress' ] )->getAuthority(), true );
		ConvertibleTimestamp::setFakeTime( '20260601000000' );
		$good = $this->editPage( Title::makeTitle( NS_MAIN, $dbkey ), 'new article' )->getNewRevision();
		ConvertibleTimestamp::setFakeTime( '20260602000000' );
		$this->editPage( Title::makeTitle( NS_MAIN, $dbkey ), 'vandalism' );
		$sysop = $this->getTestSysop()->getAuthority();
		$this->delete( $dbkey, $sysop );
		ConvertibleTimestamp::setFakeTime( '20260603000000' );
		$restoredId = $this->undelete( $dbkey, $sysop, [ $good->getTimestamp() ] );
		ConvertibleTimestamp::setFakeTime( false );

		$this->assertNotSame( $page->getId(), $restoredId );
		$this->assertSame( 0, $this->listCount( $restoredId ), 'the suppressed page\'s comment stays hidden' );
	}

	public static function provideRestorersWhoDoNotUnsuppress() {
		yield 'plain sysop' => [ [ 'sysop' ] ];
		yield 'suppressor without unsuppress' => [ [ 'sysop', 'suppress' ] ];
	}

	/**
	 * A sysop may restore every revision of a title, including those of an earlier page deleted
	 * with suppression (they come back still suppressed). That page's comments stay detached:
	 * moving them needs an unsuppressing undeletion by a user with suppressrevision.
	 *
	 * @dataProvider provideRestorersWhoDoNotUnsuppress
	 */
	public function testRestoringEverythingLeavesTheSuppressedPagesCommentsOut( array $groups ) {
		$dbkey = 'GlowThread_restore_everything';
		ConvertibleTimestamp::setFakeTime( '20260101000000' );
		$first = $this->gtPage( 'GlowThread restore everything' );
		$this->gtPost( $this->gtUser(), $first, 'private' );
		$this->delete( $dbkey, $this->getTestUser( [ 'sysop', 'suppress' ] )->getAuthority(), true );
		ConvertibleTimestamp::setFakeTime( '20260601000000' );
		$this->editPage( Title::makeTitle( NS_MAIN, $dbkey ), 'new article' );
		$second = $this->getServiceContainer()->getPageStore()->getPageByName( NS_MAIN, $dbkey );
		$this->gtPost( $this->gtUser(), $second, 'public' );
		$restorer = $this->getTestUser( $groups )->getAuthority();
		$this->delete( $dbkey, $restorer );
		ConvertibleTimestamp::setFakeTime( '20260603000000' );
		$restoredId = $this->undelete( $dbkey, $restorer );
		ConvertibleTimestamp::setFakeTime( false );

		$this->assertSame( $second->getId(), $restoredId );
		$this->assertSame( 1, $this->listCount( $restoredId ), 'only the second page\'s comment' );
		$this->assertSame( 1, (int)$this->getDb()->newSelectQueryBuilder()->select( 'COUNT(*)' )
			->from( 'glowthread_post' )->where( [ 'gtp_page' => $first->getId() ] )
			->caller( __METHOD__ )->fetchField() );
	}

	public function testUndeleteAfterSuppressedDeletion() {
		$page = $this->gtPage( 'GlowThread suppressed page' );
		$this->gtPost( $this->gtUser(), $page, 'old' );
		$services = $this->getServiceContainer();
		$suppressor = $this->getTestUser( [ 'sysop', 'suppress' ] )->getAuthority();
		$this->assertStatusGood( $services->getDeletePageFactory()
			->newDeletePage( $services->getWikiPageFactory()->newFromTitle( $page ), $suppressor )
			->setSuppress( true )
			->deleteUnsafe( 'test' ) );
		$this->editPage( Title::makeTitle( NS_MAIN, 'GlowThread_suppressed_page' ), 'new content' );
		$newPage = $services->getPageStore()->getPageByName( NS_MAIN, 'GlowThread_suppressed_page' );
		$this->assertStatusGood( $services->getUndeletePageFactory()
			->newUndeletePage(
				$services->getWikiPageFactory()->newFromTitle(
					Title::makeTitle( NS_MAIN, 'GlowThread_suppressed_page' )
				),
				$suppressor
			)
			->setUnsuppress( true )
			->undeleteUnsafe( 'test' ) );
		$this->assertSame( 1, $this->listCount( $newPage->getId() ) );
	}
}
