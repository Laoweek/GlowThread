<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Api;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Model\PostStatus;

/**
 * GlowThreadPostApproved runs for the comments an approval lets readers see, and undoing an
 * unpin pins only visible comments.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Service\ModerationService
 */
class ApiGlowThreadModerateHooksTest extends GlowThreadApiTestCase {

	/** @var int[] IDs of the comments GlowThreadPostApproved ran for, in order */
	private array $approved = [];

	protected function setUp(): void {
		parent::setUp();
		$this->setTemporaryHook( 'GlowThreadPostApproved', function ( PostRecord $post ) {
			$this->assertTrue( $this->gtService( 'PostStore' )->isPubliclyVisible( $post, true ), 'readers see it' );
			$this->approved[] = $post->getId();
		} );
	}

	/**
	 * A new account's comment and its own reply under it, both held.
	 *
	 * @return PostRecord[] Parent, reply
	 */
	private function heldParentAndReply(): array {
		$this->overrideConfigValues( [ 'GlowThreadNewAccountHours' => 24, 'GlowThreadNewAccountApproved' => 1 ] );
		$page = $this->gtPage();
		$newbie = $this->gtUser();
		$parent = $this->gtPost( $newbie, $page, 'held top-level' );
		$reply = $this->gtPost( $newbie, $page, 'held reply', $parent );
		$this->assertSame( PostStatus::HELD, $parent->getStatus() );
		$this->assertSame( PostStatus::HELD, $reply->getStatus() );
		return [ $parent, $reply ];
	}

	public function testApprovedHookWaitsUntilReadersSeeTheReply() {
		[ $parent, $reply ] = $this->heldParentAndReply();
		$this->gtModerate( 'approve', [ $reply ] );
		DeferredUpdates::doUpdates();
		$this->assertSame( [], $this->approved, 'not while the parent is held' );

		$this->gtModerate( 'approve', [ $parent ] );
		DeferredUpdates::doUpdates();
		$this->assertSame( [ $parent->getId(), $reply->getId() ], $this->approved, 'the reply with its parent' );
	}

	public function testOneBatchRunsTheHookOncePerComment() {
		[ $parent, $reply ] = $this->heldParentAndReply();
		$this->gtModerate( 'approve', [ $reply, $parent ] );
		DeferredUpdates::doUpdates();
		$this->assertSame( [ $reply->getId(), $parent->getId() ], $this->approved );
	}

	/**
	 * Replies that were never held notified when they were saved: approving the comment above
	 * them again (after an undo) runs the hook for that comment only.
	 */
	public function testRepliesThatWereNeverHeldAreNotAnnouncedAgain() {
		$this->gtSetFilter( 'holdword' );
		$page = $this->gtPage();
		$top = $this->gtPost( $this->gtUser(), $page, 'holdword top' );
		$approval = $this->gtService( 'ModerationService' )->moderate(
			$this->getTestSysop()->getAuthority(), 'approve', [ $top->getId() ], ''
		)->getValue()[0]['logId'];
		$this->gtPost( $this->gtUser(), $page, 'a plain reply', $top );
		$this->assertStatusGood( $this->gtService( 'ModerationService' )->moderate(
			$this->getTestSysop()->getAuthority(), 'undo', [ $top->getId() ], '', [ $approval ]
		) );
		$this->assertTrue( $this->gtReload( $top )->isHeld() );
		DeferredUpdates::doUpdates();
		$this->approved = [];

		$this->gtModerate( 'approve', [ $top ] );
		DeferredUpdates::doUpdates();
		$this->assertSame( [ $top->getId() ], $this->approved );
	}

	/**
	 * Undoing an earlier unpin does not pin a comment that was erased (or deleted) since.
	 */
	public function testUndoOfUnpinDoesNotRepinAnErasedComment() {
		$page = $this->gtPage();
		$sysop = $this->getTestSysop()->getUser();
		$post = $this->gtPost( $this->gtUser(), $page, 'announcement' );
		$this->gtModerate( 'pin', [ $post ] );
		$unpin = $this->gtApi(
			[ 'action' => 'glowthreadmoderate', 'do' => 'unpin', 'ids' => $post->getId() ], $sysop
		)['glowthreadmoderate']['results'][0];
		$this->gtModerate( 'delete', [ $post ] );
		$this->gtModerate( 'erase', [ $post ] );

		$undo = $this->gtApi( [
			'action' => 'glowthreadmoderate', 'do' => 'undo', 'ids' => $post->getId(), 'logids' => $unpin['logId'],
		], $sysop )['glowthreadmoderate'];
		$this->assertSame( 'badstatus', $undo['results'][0]['code'] );
		$this->assertFalse( $this->gtReload( $post )->isPinned() );
	}
}
