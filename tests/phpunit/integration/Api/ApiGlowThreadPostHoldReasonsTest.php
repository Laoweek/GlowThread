<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Api;

use MediaWiki\Extension\GlowThread\Model\HoldReason;
use MediaWiki\Permissions\Authority;

/**
 * The post response tells an author only the hold reasons about their account or their held
 * parent, never which content check held the comment, so spammers cannot tune text against
 * the word filter or SpamBlacklist. Moderators get every reason.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Api\ApiGlowThreadPost
 */
class ApiGlowThreadPostHoldReasonsTest extends GlowThreadApiTestCase {

	private function post( Authority $author, string $text, ?int $replyTo = null ): array {
		$params = [ 'action' => 'glowthreadpost', 'pageid' => $this->gtPage()->getId(), 'text' => $text ];
		if ( $replyTo !== null ) {
			$params['replyto'] = $replyTo;
		}
		return $this->gtApi( $params, $author )['glowthreadpost'];
	}

	public function testAuthorIsNotToldWhichContentCheckHeldTheComment() {
		$this->gtSetFilter( 'badword' );
		$result = $this->post( $this->gtUser(), 'this has a badword in it' );
		$this->assertSame( 'held', $result['status'] );
		$this->assertSame( [], $result['holdReasons'] );
		$this->assertArrayNotHasKey( 'mod', $result['comment'], 'nor in the comment' );
		$this->assertSame( [ HoldReason::FILTER ], $this->gtService( 'PostStore' )
			->getById( $result['id'] )->getHoldReasons(), 'stored for moderators' );
	}

	public function testAccountReasonsAreKept() {
		$this->overrideConfigValues( [ 'GlowThreadNewAccountHours' => 24, 'GlowThreadNewAccountApproved' => 1 ] );
		$this->gtSetFilter( 'badword' );
		$author = $this->gtUser();
		$held = $this->post( $author, 'a badword from a new account' );
		$this->assertSame( [ HoldReason::NEW_ACCOUNT ], $held['holdReasons'] );
		$reply = $this->post( $author, 'more', $held['id'] );
		$this->assertSame( [ HoldReason::NEW_ACCOUNT, HoldReason::PARENT_HELD ], $reply['holdReasons'] );
	}

	public function testModeratorsGetEveryReason() {
		$this->gtSetFilter( 'badword' );
		$result = $this->post( $this->getTestSysop()->getUser(), 'a badword by a moderator' );
		$this->assertSame( 'held', $result['status'] );
		$this->assertSame( [ HoldReason::FILTER ], $result['holdReasons'] );
	}
}
