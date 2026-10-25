<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Hooks;

use MediaWiki\Extension\GlowThread\Model\PageState;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * An undeletion that merges a deleted page into a page re-created at its title keeps the stricter
 * comment state of the two, and logs it when the page gets a stricter state (security review
 * 2026-10-10, I4).
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Hooks\PageLifecycleHooks
 * @covers \MediaWiki\Extension\GlowThread\Service\PageLifecycleService
 * @covers \MediaWiki\Extension\GlowThread\Store\PageStateStore
 */
class UndeleteMergeStateTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
	}

	private function anonListJson( int $pageId ): string {
		$query = $this->gtService( 'CommentQueryService' );
		$page = $this->getServiceContainer()->getPageStore()->getPageById( $pageId );
		$anon = $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' );
		return json_encode( $query->getPageList( $query->newViewer( $anon ), $page, 'new', null, 20 ) );
	}

	/**
	 * Delete the page with the given state, re-create its title with another state, and restore
	 * the deleted revisions into it.
	 *
	 * @return ProperPageIdentity The merged page
	 */
	private function mergeDeletedInto( string $dbkey, string $oldState, string $newState ): ProperPageIdentity {
		$services = $this->getServiceContainer();
		$sysop = $this->getTestSysop()->getAuthority();
		$page = $this->gtPage( $dbkey );
		$this->gtPost( $this->gtUser(), $page, 'OLD-PAGE-MARKER' );
		if ( $oldState !== PageState::OPEN ) {
			$this->gtSetPageState( $page, $oldState );
		}
		$this->assertStatusGood( $services->getDeletePageFactory()
			->newDeletePage( $services->getWikiPageFactory()->newFromTitle( $page ), $sysop )
			->deleteUnsafe( 'test' ) );

		// makeTitle(), not newFromText(): the latter caches Title objects with their old page ID
		$this->editPage( Title::makeTitle( NS_MAIN, $dbkey ), 'recreated' );
		$newPage = $services->getPageStore()->getPageByName( NS_MAIN, $dbkey );
		$this->assertNotSame( $page->getId(), $newPage->getId() );
		$this->gtPost( $this->gtUser(), $newPage, 'new page comment' );
		if ( $newState !== PageState::OPEN ) {
			$this->gtSetPageState( $newPage, $newState );
		}

		$title = Title::makeTitle( NS_MAIN, $dbkey );
		$this->assertStatusGood( $services->getUndeletePageFactory()
			->newUndeletePage( $services->getWikiPageFactory()->newFromTitle( $title ), $sysop )
			->undeleteUnsafe( 'test' ) );
		$merged = $services->getPageStore()->getPageByName( NS_MAIN, $dbkey );
		$this->assertSame( $newPage->getId(), $merged->getId() );
		return $merged;
	}

	private function state( ProperPageIdentity $page ): string {
		return $this->gtService( 'PageStateStore' )->getState( $page->getId(), true );
	}

	/**
	 * @return string[] Actions of the page state entries logged on a page
	 */
	private function stateLog( int $pageId ): array {
		return $this->getDb()->newSelectQueryBuilder()
			->select( 'log_action' )
			->from( 'logging' )
			->where( [
				'log_type' => 'glowthread',
				'log_action' => [ 'enable', 'lock', 'disable' ],
				'log_page' => $pageId,
			] )
			->orderBy( 'log_id' )
			->caller( __METHOD__ )
			->fetchFieldValues();
	}

	public function testClosedPageStaysClosedAfterMerge() {
		$page = $this->mergeDeletedInto( 'GlowThread_closed_merge', PageState::DISABLED, PageState::OPEN );
		$this->assertSame( PageState::DISABLED, $this->state( $page ) );
		$this->assertStringNotContainsString( 'OLD-PAGE-MARKER', $this->anonListJson( $page->getId() ),
			'readers do not see the comments that Closed hid' );

		// Moderators see why in the log of the page
		$this->assertSame( [ 'disable' ], $this->stateLog( $page->getId() ) );
		$row = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'actor_name', 'comment_text' ] )
			->from( 'logging' )
			->join( 'actor', null, 'actor_id = log_actor' )
			->join( 'comment', null, 'comment_id = log_comment_id' )
			->where( [ 'log_type' => 'glowthread', 'log_action' => 'disable', 'log_page' => $page->getId() ] )
			->caller( __METHOD__ )
			->fetchRow();
		$this->assertSame( $this->getTestSysop()->getUser()->getName(), $row->actor_name, 'the restorer' );
		$this->assertSame(
			wfMessage( 'glowthread-log-undelete-state' )->inContentLanguage()->text(), $row->comment_text
		);
	}

	public function testLockedPageStaysLockedAfterMerge() {
		$page = $this->mergeDeletedInto( 'GlowThread_locked_merge', PageState::LOCKED, PageState::OPEN );
		$this->assertSame( PageState::LOCKED, $this->state( $page ) );
		$this->assertSame( [ 'lock' ], $this->stateLog( $page->getId() ) );
	}

	public function testStricterRecreatedPageKeepsItsState() {
		$page = $this->mergeDeletedInto( 'GlowThread_stricter_target', PageState::LOCKED, PageState::DISABLED );
		$this->assertSame( PageState::DISABLED, $this->state( $page ) );
		// Only the moderator's own entry, nothing from the merge
		$this->assertSame( [ 'disable' ], $this->stateLog( $page->getId() ) );
	}

	public function testOpenPagesStayOpen() {
		$page = $this->mergeDeletedInto( 'GlowThread_open_merge', PageState::OPEN, PageState::OPEN );
		$this->assertSame( PageState::OPEN, $this->state( $page ) );
		$this->assertStringContainsString( 'OLD-PAGE-MARKER', $this->anonListJson( $page->getId() ) );
		$this->assertSame( [], $this->stateLog( $page->getId() ) );
	}
}
