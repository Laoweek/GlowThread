<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Api;

/**
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Api\ApiGlowThreadReact
 * @covers \MediaWiki\Extension\GlowThread\Service\ReactionService
 */
class ApiGlowThreadReactTest extends GlowThreadApiTestCase {

	private function react( $performer, int $id, int $value ): array {
		return $this->gtApi(
			[ 'action' => 'glowthreadreact', 'id' => $id, 'value' => $value ], $performer
		)['glowthreadreact'];
	}

	public function testLikeIsIdempotent() {
		$page = $this->gtPage();
		$post = $this->gtPost( $this->gtUser(), $page, 'like me' );
		$fan = $this->gtUser();
		$other = $this->gtUser();

		$first = $this->react( $fan, $post->getId(), 1 );
		$this->assertTrue( $first['liked'] );
		$this->assertTrue( $first['changed'] );
		$this->assertSame( 1, $first['likes'] );

		$again = $this->react( $fan, $post->getId(), 1 );
		$this->assertFalse( $again['changed'] );
		$this->assertSame( 1, $again['likes'], 'repeating the call does not count twice' );

		$this->assertSame( 2, $this->react( $other, $post->getId(), 1 )['likes'] );

		$off = $this->react( $fan, $post->getId(), 0 );
		$this->assertFalse( $off['liked'] );
		$this->assertSame( 1, $off['likes'] );
		$offAgain = $this->react( $fan, $post->getId(), 0 );
		$this->assertFalse( $offAgain['changed'] );
		$this->assertSame( 1, $offAgain['likes'], 'never below the real count' );
		$this->assertSame( 1, $this->gtReload( $post )->getLikeCount() );
	}

	public function testOwnCommentCannotBeLiked() {
		$page = $this->gtPage();
		$author = $this->gtUser();
		$post = $this->gtPost( $author, $page, 'mine' );
		$this->assertSame( 'ownlike', $this->gtApiError(
			[ 'action' => 'glowthreadreact', 'id' => $post->getId(), 'value' => 1 ], $author
		) );
	}

	public function testDeletedOrHeldCommentsCannotBeLiked() {
		$this->gtSetFilter( 'holdme' );
		$page = $this->gtPage();
		$held = $this->gtPost( $this->gtUser(), $page, 'holdme' );
		$gone = $this->gtPost( $this->gtUser(), $page, 'gone' );
		$this->gtModerate( 'delete', [ $gone ] );
		foreach ( [ $held, $gone ] as $post ) {
			$this->assertSame( 'commentnotfound', $this->gtApiError(
				[ 'action' => 'glowthreadreact', 'id' => $post->getId(), 'value' => 1 ], $this->gtUser()
			) );
		}
	}

	public function testBlockedUserCannotLike() {
		$page = $this->gtPage();
		$post = $this->gtPost( $this->gtUser(), $page, 'x' );
		$user = $this->gtUser();
		$this->gtBlockSitewide( $user );
		$this->assertStringContainsString( 'blocked', $this->gtApiError(
			[ 'action' => 'glowthreadreact', 'id' => $post->getId(), 'value' => 1 ], $user
		) );
	}

	public function testLikesAreAllowedOnLockedPages() {
		$page = $this->gtPage();
		$post = $this->gtPost( $this->gtUser(), $page, 'x' );
		$this->gtSetPageState( $page, 'locked' );
		$this->assertSame( 1, $this->react( $this->gtUser(), $post->getId(), 1 )['likes'] );
	}

	/**
	 * Two accounts created a minute earlier used to move any comment to the top of the hot
	 * order and into the top comments block. Accounts the new-account gate would hold cannot
	 * like now, but can still remove a like, and the list tells the client why.
	 */
	public function testGatedAccountsCannotLike() {
		$page = $this->gtPage();
		$post = $this->gtPost( $this->gtUser(), $page, 'x' );
		$newbie = $this->gtUser();
		// A like from before the account was gated (the gate is off in setUp)
		$this->assertSame( 1, $this->react( $newbie, $post->getId(), 1 )['likes'] );
		$this->overrideConfigValues( [
			'GlowThreadNewAccountHours' => 24,
			'GlowThreadNewAccountApproved' => 1,
		] );
		$this->assertTrue( $this->gtService( 'WriteGuard' )->isGatedNewAccount( $newbie, null ) );

		$this->assertSame( 'newaccount', $this->gtApiError(
			[ 'action' => 'glowthreadreact', 'id' => $post->getId(), 'value' => 1 ], $this->gtUser()
		) );
		$this->assertSame( 0, $this->react( $newbie, $post->getId(), 0 )['likes'], 'unlike still works' );
		$this->assertSame( 'newaccount', $this->gtApiError(
			[ 'action' => 'glowthreadreact', 'id' => $post->getId(), 'value' => 1 ], $newbie
		) );
		$this->assertSame( 0, $this->gtReload( $post )->getLikeCount() );

		$query = $this->gtService( 'CommentQueryService' );
		$list = $query->getPageList( $query->newViewer( $newbie ), $page, 'new', null, 20 );
		$this->assertFalse( $list['viewer']['canReact'] );
		$this->assertSame( 'newaccount', $list['viewer']['reactBlockedReason'] );
		$this->assertFalse( $list['comments'][0]['viewer']['canLike'] );
		// The gate is the reason only when nothing else stops the like
		$this->gtBlockSitewide( $newbie );
		$blocked = $this->getServiceContainer()->getUserFactory()->newFromId( $newbie->getId() );
		$list = $query->getPageList( $query->newViewer( $blocked ), $page, 'new', null, 20 );
		$this->assertFalse( $list['viewer']['canReact'] );
		$this->assertNull( $list['viewer']['reactBlockedReason'] );

		// Trusted users skip the gate
		$sysop = $this->getTestSysop()->getUser();
		$this->assertSame( 1, $this->react( $sysop, $post->getId(), 1 )['likes'] );
		$list = $query->getPageList( $query->newViewer( $sysop ), $page, 'new', null, 20 );
		$this->assertTrue( $list['viewer']['canReact'] );
		$this->assertNull( $list['viewer']['reactBlockedReason'] );
		$anon = $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' );
		$list = $query->getPageList( $query->newViewer( $anon ), $page, 'new', null, 20 );
		$this->assertNull( $list['viewer']['reactBlockedReason'] );
	}

	public function testValueMustBeZeroOrOne() {
		$page = $this->gtPage();
		$post = $this->gtPost( $this->gtUser(), $page, 'x' );
		$this->assertSame( 'badvalue', $this->gtApiError(
			[ 'action' => 'glowthreadreact', 'id' => $post->getId(), 'value' => 'true' ], $this->gtUser()
		) );
	}
}
