<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Api;

use MediaWiki\Title\Title;

/**
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Api\ApiGlowThreadPage
 * @covers \MediaWiki\Extension\GlowThread\Service\PageStateService
 */
class ApiGlowThreadPageTest extends GlowThreadApiTestCase {

	public function testSetStateIsLogged() {
		$page = $this->gtPage();
		$sysop = $this->getTestSysop()->getUser();
		$result = $this->gtApi( [
			'action' => 'glowthreadpage', 'pageid' => $page->getId(), 'state' => 'locked', 'reason' => 'flame war',
		], $sysop )['glowthreadpage'];
		$this->assertSame( [ 'result' => 'success', 'pageid' => $page->getId(), 'state' => 'locked',
			'old' => 'open', 'changed' => true ], $result );
		$this->assertCount( 1, $this->gtLogRows( 'glowthread', 'lock' ) );

		$same = $this->gtApi(
			[ 'action' => 'glowthreadpage', 'pageid' => $page->getId(), 'state' => 'locked' ], $sysop
		);
		$this->assertFalse( $same['glowthreadpage']['changed'] );
		$this->assertCount( 1, $this->gtLogRows( 'glowthread', 'lock' ), 'no entry when nothing changed' );

		$this->gtApi( [ 'action' => 'glowthreadpage', 'pageid' => $page->getId(), 'state' => 'disabled' ], $sysop );
		$this->gtApi( [ 'action' => 'glowthreadpage', 'pageid' => $page->getId(), 'state' => 'open' ], $sysop );
		$this->assertCount( 1, $this->gtLogRows( 'glowthread', 'disable' ) );
		$this->assertCount( 1, $this->gtLogRows( 'glowthread', 'enable' ) );
	}

	public function testNeedsModerateRight() {
		$page = $this->gtPage();
		$this->assertSame( 'permissiondenied', $this->gtApiError(
			[ 'action' => 'glowthreadpage', 'pageid' => $page->getId(), 'state' => 'locked' ], $this->gtUser()
		) );
	}

	public function testIneligiblePage() {
		$talk = $this->getExistingTestPage( Title::makeTitle( NS_TALK, 'GlowThread talk page' ) );
		$this->assertSame( 'pageineligible', $this->gtApiError(
			[ 'action' => 'glowthreadpage', 'pageid' => $talk->getId(), 'state' => 'locked' ],
			$this->getTestSysop()->getUser()
		) );
	}
}
