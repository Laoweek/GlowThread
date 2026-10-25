<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Policy;

use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWikiIntegrationTestCase;

/**
 * Security review W7: moderators could act by ID on comments of pages they may not read. The
 * moderation actions now follow the rule of the moderation routes.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Policy\WriteGuard
 */
class ModerationReadAccessTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
	}

	public function testOnlyOnPagesTheModeratorCanRead() {
		$hidden = $this->gtPage( 'Staff only page' );
		$open = $this->gtPage();
		$guard = $this->gtService( 'WriteGuard' );
		$sysop = $this->getTestSysop()->getUser();
		$this->assertTrue( $guard->canModerateOn( $sysop, $hidden->getId() ) );

		// A read restriction such as Lockdown
		$this->setTemporaryHook( 'getUserPermissionsErrors',
			static function ( $title, $user, $action, &$result ) use ( $hidden ) {
				if ( $action === 'read' && $title->getArticleID() === $hidden->getId() ) {
					$result = [ 'badaccess-group0' ];
					return false;
				}
				return true;
			}
		);
		$this->assertFalse( $guard->canModerateOn( $sysop, $hidden->getId() ) );
		$this->assertTrue( $guard->canModerateOn( $sysop, $open->getId() ) );
	}

	/**
	 * Comments of a deleted page stay open to moderation, unless its deletion was a suppression
	 * and the moderator may not see suppressed revisions.
	 */
	public function testDeletedAndSuppressedPages() {
		$deleted = $this->gtPage( 'Deleted page' );
		$suppressed = $this->gtPage( 'Suppressed page' );
		$services = $this->getServiceContainer();
		$oversighter = $this->getTestUser( [ 'sysop', 'suppress' ] )->getAuthority();
		foreach ( [ [ $deleted, false ], [ $suppressed, true ] ] as [ $page, $suppress ] ) {
			$this->assertStatusGood( $services->getDeletePageFactory()
				->newDeletePage( $services->getWikiPageFactory()->newFromID( $page->getId() ), $oversighter )
				->setSuppress( $suppress )
				->deleteUnsafe( 'test' ) );
		}
		$guard = $this->gtService( 'WriteGuard' );
		$sysop = $this->getTestSysop()->getUser();
		$this->assertTrue( $guard->canModerateOn( $sysop, $deleted->getId() ) );
		$this->assertFalse( $guard->canModerateOn( $sysop, $suppressed->getId() ) );
		$this->assertTrue( $guard->canModerateOn( $oversighter, $suppressed->getId() ) );
	}
}
