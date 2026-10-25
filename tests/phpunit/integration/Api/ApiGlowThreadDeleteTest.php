<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Api;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\GlowThread\Model\PostStatus;
use MediaWiki\MainConfigNames;
use MediaWiki\Request\FauxRequest;
use MediaWiki\User\UserIdentityValue;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Api\ApiGlowThreadDelete
 * @covers \MediaWiki\Extension\GlowThread\Service\ModerationService::selfDelete
 */
class ApiGlowThreadDeleteTest extends GlowThreadApiTestCase {

	public function testAuthorDeletesOwnComment() {
		$page = $this->gtPage();
		$author = $this->gtUser();
		$top = $this->gtPost( $this->gtUser(), $page, 'top' );
		$reply = $this->gtPost( $author, $page, 'mine', $top );
		$seen = null;
		$this->setTemporaryHook( 'GlowThreadPostModerated', static function ( $post, $p, $action ) use ( &$seen ) {
			$seen = $action;
		} );
		$result = $this->gtApi(
			[ 'action' => 'glowthreaddelete', 'id' => $reply->getId() ], $author
		)['glowthreaddelete'];
		$this->assertSame( PostStatus::DELETED, $result['status'] );
		$this->assertSame( 0, $this->gtReload( $top )->getReplyCount() );
		$this->assertSame( 'selfdelete', $seen );
		$this->assertCount( 1, $this->gtLogRows( 'glowthread', 'delete' ) );
	}

	public function testOthersCannotUseSelfDelete() {
		$page = $this->gtPage();
		$post = $this->gtPost( $this->gtUser(), $page, 'not yours' );
		$this->assertSame( 'notauthor', $this->gtApiError(
			[ 'action' => 'glowthreaddelete', 'id' => $post->getId() ], $this->gtUser()
		) );
		// Moderators too: they moderate through glowthreadmoderate, which is logged as moderation
		$this->assertSame( 'notauthor', $this->gtApiError(
			[ 'action' => 'glowthreaddelete', 'id' => $post->getId() ], $this->getTestSysop()->getUser()
		) );
	}

	/**
	 * Someone else's comment that the requester cannot see is answered
	 * like a missing one, so the error does not confirm that a hidden comment exists.
	 */
	public function testHiddenCommentsOfOthersLookMissing() {
		$this->gtSetFilter( 'holdme' );
		$page = $this->gtPage();
		$held = $this->gtPost( $this->gtUser(), $page, 'holdme' );
		$deleted = $this->gtPost( $this->gtUser(), $page, 'soon deleted' );
		$this->gtModerate( 'delete', [ $deleted ] );
		$reader = $this->gtUser();
		foreach ( [ $held->getId(), $deleted->getId(), 999999 ] as $id ) {
			$this->assertSame( 'commentnotfound', $this->gtApiError(
				[ 'action' => 'glowthreaddelete', 'id' => $id ], $reader
			), "comment $id" );
		}
		// Moderators see these comments, so for them it is not their comment
		$this->assertSame( 'notauthor', $this->gtApiError(
			[ 'action' => 'glowthreaddelete', 'id' => $held->getId() ], $this->getTestSysop()->getUser()
		) );
		// A comment on a locked page: the answer does not depend on the page state either
		$this->gtSetPageState( $page, 'locked' );
		$this->assertSame( 'commentnotfound', $this->gtApiError(
			[ 'action' => 'glowthreaddelete', 'id' => $held->getId() ], $reader
		) );
	}

	/**
	 * Self-delete needs an account. Anonymous users share IP actors with the
	 * legacy anonymous comments of that address, even if anonymous commenting is turned on.
	 */
	public function testAnonymousUsersCannotDeleteLegacyIpComments() {
		// IP actors exist only while temporary accounts are off (the import runs before they are on)
		$tempConfig = $this->getServiceContainer()->getMainConfig()->get( MainConfigNames::AutoCreateTempUser );
		$this->overrideConfigValue(
			MainConfigNames::AutoCreateTempUser, [ 'enabled' => false, 'known' => false ] + $tempConfig
		);
		$page = $this->gtPage();
		$ip = '203.0.113.7';
		$dbw = $this->getDb();
		$actorId = $this->getServiceContainer()->getActorNormalization()
			->acquireActorId( new UserIdentityValue( 0, $ip ), $dbw );
		$id = $this->gtService( 'PostStore' )->insert( $dbw, [
			'gtp_page' => $page->getId(), 'gtp_actor' => $actorId, 'gtp_depth' => 0, 'gtp_seq' => 99,
			'gtp_text' => 'legacy anon comment', 'gtp_created' => $dbw->timestamp( ConvertibleTimestamp::now() ),
			'gtp_status' => PostStatus::VISIBLE,
		] );
		$this->setGroupPermissions( '*', 'glowthread-post', true );
		$request = new FauxRequest();
		$request->setIP( $ip );
		RequestContext::getMain()->setRequest( $request );
		$authority = $this->getServiceContainer()->getUserFactory()->newAnonymous( $ip );
		$status = $this->gtService( 'ModerationService' )->selfDelete( $authority, $id, '' );
		$this->assertStatusError( 'glowthread-error-not-author', $status );
		$this->assertSame( PostStatus::VISIBLE, $this->gtService( 'PostStore' )->getById( $id, true )->getStatus() );
	}

	public function testBlockedAuthorCannotDelete() {
		$page = $this->gtPage();
		$author = $this->gtUser();
		$post = $this->gtPost( $author, $page, 'mine' );
		$this->gtBlockSitewide( $author );
		$this->assertStringContainsString( 'blocked', $this->gtApiError(
			[ 'action' => 'glowthreaddelete', 'id' => $post->getId() ], $author
		) );
	}

	public function testAlreadyDeleted() {
		$page = $this->gtPage();
		$author = $this->gtUser();
		$post = $this->gtPost( $author, $page, 'mine' );
		$this->gtApi( [ 'action' => 'glowthreaddelete', 'id' => $post->getId() ], $author );
		$this->assertSame( 'badstatus', $this->gtApiError(
			[ 'action' => 'glowthreaddelete', 'id' => $post->getId() ], $author
		) );
	}
}
