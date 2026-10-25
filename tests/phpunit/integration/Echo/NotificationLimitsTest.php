<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Echo;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\GlowThread\Echo\EchoSetupHooks;
use MediaWiki\Extension\GlowThread\Echo\NotificationHooks;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Extension\GlowThread\Text\TextProcessor;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use TestUser;

/**
 * Limits on comment notifications: a comment notifies once (also when an approval is undone
 * and repeated), one author notifies one user at most 3 times an hour, mentions are bundled per
 * page, a reply approved under a held comment notifies when that comment is approved, and the
 * author's own deletion hides what the comment sent. The excerpt checks read access fully.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Echo\NotificationHooks
 * @covers \MediaWiki\Extension\GlowThread\Echo\ErasedCommentEvents
 * @covers \MediaWiki\Extension\GlowThread\Echo\EchoSetupHooks
 * @covers \MediaWiki\Extension\GlowThread\Echo\CommentPresentationModel
 * @covers \MediaWiki\Extension\GlowThread\Echo\GlowThreadPresentationModel
 * @covers \MediaWiki\Extension\GlowThread\Service\ModerationService
 */
class NotificationLimitsTest extends ApiTestCase {
	use GlowThreadTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'Echo' );
		$this->setUpGlowThread();
		$this->overrideConfigValue( 'EchoUseJobQueue', false );
	}

	/**
	 * @return Event[] Events of a type that notified the user, oldest first
	 */
	private function events( UserIdentity $user, string $type ): array {
		DeferredUpdates::doUpdates();
		$ids = $this->getDb()->newSelectQueryBuilder()
			->select( 'event_id' )
			->from( 'echo_notification' )
			->join( 'echo_event', null, 'event_id = notification_event' )
			->where( [ 'notification_user' => $user->getId(), 'event_type' => $type ] )
			->orderBy( 'event_id' )
			->caller( __METHOD__ )
			->fetchFieldValues();
		return array_map( static fn ( $id ) => Event::newFromID( (int)$id ), $ids );
	}

	private function isHidden( Event $event ): bool {
		return (int)$this->getDb()->newSelectQueryBuilder()->select( 'event_deleted' )->from( 'echo_event' )
			->where( [ 'event_id' => $event->getId() ] )->caller( __METHOD__ )->fetchField() === 1;
	}

	/**
	 * The user's notifications of one type as Echo's API renders them, newest first.
	 *
	 * @return array[] Model outputs
	 */
	private function render( User $user, string $type, bool $bundle = false ): array {
		DeferredUpdates::doUpdates();
		$list = $this->doApiRequest( [
			'action' => 'query', 'meta' => 'notifications', 'notformat' => 'model', 'notprop' => 'list',
			'notlimit' => 50, 'notbundle' => $bundle ? 1 : 0, 'uselang' => 'en',
		], null, false, $user )[0]['query']['notifications']['list'];
		return array_values( array_map(
			static fn ( $n ) => $n['*'],
			array_filter( $list, static fn ( $n ) => $n['type'] === $type )
		) );
	}

	private function named( string $name ): User {
		return ( new TestUser( $name ) )->getUser();
	}

	private static function token( UserIdentity $user ): string {
		return TextProcessor::mentionToken( $user->getId() );
	}

	private function makeHeld(
		ProperPageIdentity $page, User $author, string $text, ?PostRecord $replyTo
	): PostRecord {
		$this->gtSetFilter( 'holdword' );
		$post = $this->gtPost( $author, $page, "holdword $text", $replyTo );
		$this->assertTrue( $post->isHeld() );
		return $post;
	}

	/**
	 * @return int The log ID of the action
	 */
	private function moderate( string $action, PostRecord $post, array $logIds = [] ): int {
		$status = $this->gtService( 'ModerationService' )->moderate(
			$this->getTestSysop()->getAuthority(), $action, [ $post->getId() ], '', $logIds
		);
		$this->assertStatusGood( $status );
		$this->assertSame( 'success', $status->getValue()[0]['result'] );
		return (int)$status->getValue()[0]['logId'];
	}

	public function testApprovingAgainAfterAnUndoDoesNotNotifyTwice() {
		$page = $this->gtPage();
		$alice = $this->named( 'GtAlice' );
		$carol = $this->named( 'GtCarol' );
		$top = $this->gtPost( $alice, $page, 'Top' );
		$reply = $this->makeHeld( $page, $this->named( 'GtBob' ), 'reply ' . self::token( $carol ), $top );

		$approval = $this->moderate( 'approve', $reply );
		$this->moderate( 'undo', $reply, [ $approval ] );
		$this->assertTrue( $this->gtReload( $reply )->isHeld() );
		$this->moderate( 'approve', $reply );

		$this->assertCount( 1, $this->events( $alice, EchoSetupHooks::TYPE_REPLY ) );
		$this->assertCount( 1, $this->events( $carol, EchoSetupHooks::TYPE_MENTION ) );
	}

	/**
	 * A reply approved while the comment above it is held notifies nobody yet. It notifies
	 * when that comment is approved.
	 */
	public function testReplyApprovedUnderAHeldCommentNotifiesWithIt() {
		$page = $this->gtPage();
		$alice = $this->named( 'GtAlice' );
		$carol = $this->named( 'GtCarol' );
		$top = $this->gtPost( $alice, $page, 'Top' );
		$bob = $this->named( 'GtBob' );
		$held = $this->makeHeld( $page, $bob, 'answer', $top );
		$heldReply = $this->gtPost( $bob, $page, 'more ' . self::token( $carol ), $held );
		$this->assertTrue( $heldReply->isHeld(), 'held with its parent' );

		$this->moderate( 'approve', $heldReply );
		$this->assertSame( [], $this->events( $carol, EchoSetupHooks::TYPE_MENTION ), 'readers cannot see it yet' );
		$this->moderate( 'approve', $held );
		$this->assertCount( 1, $this->events( $alice, EchoSetupHooks::TYPE_REPLY ) );
		$this->assertCount( 1, $this->events( $carol, EchoSetupHooks::TYPE_MENTION ), 'sent with its parent' );
	}

	public function testOneAuthorNotifiesOneUserAtMostThreeTimesAnHour() {
		$page = $this->gtPage();
		$alice = $this->named( 'GtAlice' );
		$bob = $this->named( 'GtBob' );
		$top = $this->gtPost( $alice, $page, 'Top' );
		for ( $i = 1; $i <= NotificationHooks::MAX_PER_RECIPIENT + 2; $i++ ) {
			$this->gtPost( $bob, $page, "reply $i", $top );
		}
		$this->gtPost( $bob, $page, 'and a mention ' . self::token( $alice ) );
		$this->assertCount( NotificationHooks::MAX_PER_RECIPIENT, $this->events( $alice, EchoSetupHooks::TYPE_REPLY ) );
		$this->assertSame( [], $this->events( $alice, EchoSetupHooks::TYPE_MENTION ), 'mentions count too' );

		// Other authors and other recipients are not affected
		$this->gtPost( $this->named( 'GtCarol' ), $page, 'another reply', $top );
		$this->assertCount(
			NotificationHooks::MAX_PER_RECIPIENT + 1, $this->events( $alice, EchoSetupHooks::TYPE_REPLY )
		);
		$dan = $this->named( 'GtDan' );
		$this->gtPost( $bob, $page, 'hi ' . self::token( $dan ) );
		$this->assertCount( 1, $this->events( $dan, EchoSetupHooks::TYPE_MENTION ) );
	}

	public function testMentionsOnOnePageAreBundled() {
		$page = $this->gtPage();
		$carol = $this->named( 'GtCarol' );
		$this->gtPost( $this->named( 'GtAlice' ), $page, 'first ' . self::token( $carol ) );
		$this->gtPost( $this->named( 'GtBob' ), $page, 'second ' . self::token( $carol ) );

		$items = $this->render( $carol, EchoSetupHooks::TYPE_MENTION, true );
		$this->assertCount( 1, $items );
		$this->assertStringContainsString( '2 new mentions', $items[0]['header'] );
	}

	/**
	 * The author's own deletion hides the notifications the comment sent, as erasing does, and
	 * an undeletion of the page keeps them hidden. A moderator's deletion leaves them.
	 */
	public function testSelfDeletionHidesTheCommentsNotifications() {
		$page = $this->gtPage( 'GlowThread self-deletion page' );
		$victim = $this->named( 'GtAlice' );
		$harasser = $this->named( 'GtBob' );
		$post = $this->gtPost( $harasser, $page, 'hey ' . self::token( $victim ) );
		$other = $this->gtPost( $this->named( 'GtCarol' ), $page, 'hello ' . self::token( $victim ) );
		[ $mention, $kept ] = $this->events( $victim, EchoSetupHooks::TYPE_MENTION );

		$this->assertStatusGood( $this->gtService( 'ModerationService' )->selfDelete( $harasser, $post->getId(), '' ) );
		$this->moderate( 'delete', $other );
		DeferredUpdates::doUpdates();
		$this->assertTrue( $this->isHidden( $mention ), 'the author deleted it' );
		$this->assertFalse( $this->isHidden( $kept ), 'a moderator deleted it' );
		$this->assertStringNotContainsString( $harasser->getName(),
			json_encode( $this->render( $victim, EchoSetupHooks::TYPE_MENTION ) ) );

		$services = $this->getServiceContainer();
		$sysop = $this->getTestSysop()->getAuthority();
		$this->assertStatusGood( $services->getDeletePageFactory()
			->newDeletePage( $services->getWikiPageFactory()->newFromTitle( $page ), $sysop )
			->deleteUnsafe( 'test' ) );
		DeferredUpdates::doUpdates();
		$this->assertStatusGood( $services->getUndeletePageFactory()
			->newUndeletePage( $services->getWikiPageFactory()->newFromTitle(
				Title::makeTitle( NS_MAIN, 'GlowThread_self-deletion_page' )
			), $sysop )
			->undeleteUnsafe( 'test' ) );
		DeferredUpdates::doUpdates();
		$this->assertTrue( $this->isHidden( $mention ), 'still hidden after the undeletion' );
		$this->assertFalse( $this->isHidden( $kept ) );
	}

	/**
	 * The excerpt is shown only while the recipient can read the page, with the full check:
	 * read restrictions from getUserPermissionsErrorsExpensive handlers count too.
	 */
	public function testExcerptChecksReadAccessFully() {
		$page = $this->gtPage();
		$alice = $this->named( 'GtAlice' );
		$top = $this->gtPost( $alice, $page, 'Top by Alice' );
		$this->gtPost( $this->named( 'GtBob' ), $page, 'EXCERPT-SECRET reply', $top );
		$this->assertStringContainsString(
			'EXCERPT-SECRET', $this->render( $alice, EchoSetupHooks::TYPE_REPLY )[0]['body']
		);

		$pageId = $page->getId();
		$aliceId = $alice->getId();
		$this->setTemporaryHook( 'getUserPermissionsErrorsExpensive',
			static function ( $title, $user, $action, &$result ) use ( $pageId, $aliceId ) {
				if ( $action === 'read' && $title->getArticleID() === $pageId && $user->getId() === $aliceId ) {
					$result = false;
					return false;
				}
			}
		);
		$model = $this->render( $alice, EchoSetupHooks::TYPE_REPLY )[0];
		$this->assertSame( 'This comment is no longer available.', $model['body'] );
		$this->assertStringNotContainsString( 'GlowThreadLink', $model['links']['primary']['url'] );
	}
}
