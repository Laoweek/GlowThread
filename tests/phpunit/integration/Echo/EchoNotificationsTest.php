<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Echo;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\GlowThread\Echo\EchoSetupHooks;
use MediaWiki\Extension\GlowThread\Echo\LegacyPresentationModel;
use MediaWiki\Extension\GlowThread\Model\HoldReason;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Extension\GlowThread\Tests\Integration\ReporterTestTrait;
use MediaWiki\Extension\GlowThread\Text\TextProcessor;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use TestUser;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * GlowThread's Echo notifications end to end: comments posted and moderated through the
 * services, notifications stored by Echo and rendered through Echo's API.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Echo\NotificationHooks
 * @covers \MediaWiki\Extension\GlowThread\Echo\EchoSetupHooks
 * @covers \MediaWiki\Extension\GlowThread\Echo\GlowThreadPresentationModel
 * @covers \MediaWiki\Extension\GlowThread\Echo\CommentPresentationModel
 * @covers \MediaWiki\Extension\GlowThread\Echo\ModerationPresentationModel
 * @covers \MediaWiki\Extension\GlowThread\Echo\LegacyPresentationModel
 * @covers \MediaWiki\Extension\GlowThread\Echo\ErasedCommentEvents
 * @covers \MediaWiki\Extension\GlowThread\Service\PageLifecycleService
 */
class EchoNotificationsTest extends ApiTestCase {
	use GlowThreadTestTrait;
	use ReporterTestTrait;

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

	/**
	 * Whether Echo hid the event (event_deleted). Event::isDeleted() compares with the integer 1,
	 * which fails for the string the database returns.
	 */
	private function isHidden( Event $event ): bool {
		return (int)$this->getDb()->newSelectQueryBuilder()->select( 'event_deleted' )->from( 'echo_event' )
			->where( [ 'event_id' => $event->getId() ] )->caller( __METHOD__ )->fetchField() === 1;
	}

	/**
	 * The user's notifications as Echo's API renders them (notformat=model), newest first.
	 *
	 * @return array[] Each with type and the model output under '*'
	 */
	private function render( User $user, bool $bundle = false ): array {
		DeferredUpdates::doUpdates();
		$result = $this->doApiRequest( [
			'action' => 'query',
			'meta' => 'notifications',
			'notformat' => 'model',
			'notprop' => 'list',
			'notlimit' => 50,
			'notbundle' => $bundle ? 1 : 0,
			'uselang' => 'en',
		], null, false, $user )[0];
		return $result['query']['notifications']['list'];
	}

	private function renderOne( User $user, string $type ): array {
		$items = array_values( array_filter( $this->render( $user ), static fn ( $n ) => $n['type'] === $type ) );
		$this->assertCount( 1, $items, "one $type notification" );
		return $items[0]['*'];
	}

	/**
	 * A user with a short name, so Echo shows it untruncated.
	 */
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

	public function testReplyRendersExcerptAndPermalinkUntilDeleted() {
		$page = $this->gtPage();
		$alice = $this->named( 'GtAlice' );
		$bob = $this->named( 'GtBob' );
		$top = $this->gtPost( $alice, $page, 'Top' );
		$reply = $this->gtPost( $bob, $page, 'Hi ' . self::token( $alice ) . " <b>bold</b>\nline two", $top );

		$this->assertCount( 1, $this->events( $alice, EchoSetupHooks::TYPE_REPLY ) );
		$this->assertSame( [], $this->events( $alice, EchoSetupHooks::TYPE_MENTION ), 'reply and mention dedupe' );
		$this->assertSame( [], $this->events( $bob, EchoSetupHooks::TYPE_REPLY ), 'nothing to the replier' );

		$model = $this->renderOne( $alice, EchoSetupHooks::TYPE_REPLY );
		$this->assertStringContainsString( $bob->getName(), $model['header'] );
		$this->assertStringContainsString( 'GlowThread test page', $model['header'] );
		$this->assertStringContainsString( 'Hi @GtAlice &lt;b&gt;bold&lt;/b&gt; line two', $model['body'] );
		$this->assertStringNotContainsString( '<b>', $model['body'] );
		$this->assertMatchesRegularExpression(
			'/Special:GlowThreadLink\/' . $reply->getId() . '(?!\d)/', $model['links']['primary']['url']
		);

		// Once deleted, the text is no longer shown and the link goes to the page
		$this->gtModerate( 'delete', [ $reply ] );
		$model = $this->renderOne( $alice, EchoSetupHooks::TYPE_REPLY );
		$this->assertSame( 'This comment is no longer available.', $model['body'] );
		$this->assertStringNotContainsString( 'bold', json_encode( $model ) );
		$this->assertStringNotContainsString( 'GlowThreadLink', $model['links']['primary']['url'] );
		$this->assertStringNotContainsString( '#gt-', $model['links']['primary']['url'] );

		// Erased: the notification is hidden (see testErasedCommentNotificationsAreHidden)
		$this->gtModerate( 'erase', [ $reply ] );
		$event = $this->events( $alice, EchoSetupHooks::TYPE_REPLY )[0];
		$this->assertTrue( $this->isHidden( $event ), 'and the notification itself is hidden' );
	}

	/**
	 * Echo's API returned the author of an erased
	 * comment as the agent of its reply and mention notifications. Erasing now hides those
	 * events with Echo's own moderation, so they are no longer listed at all.
	 */
	public function testErasedCommentNotificationsAreHidden() {
		$page = $this->gtPage();
		$victim = $this->named( 'GtAlice' );
		$author = $this->named( 'GtBob' );
		$bystander = $this->named( 'GtCarol' );
		$top = $this->gtPost( $victim, $page, 'top' );
		$reply = $this->gtPost( $author, $page, 'abusive reply ' . self::token( $bystander ), $top );
		// On another page, so Echo does not bundle the two replies
		$otherAuthor = $this->named( 'GtDan' );
		$otherTop = $this->gtPost( $victim, $this->gtPage( 'GlowThread other page' ), 'other top' );
		$other = $this->gtPost( $otherAuthor, $this->gtPage( 'GlowThread other page' ), 'a normal reply', $otherTop );
		$this->assertCount( 2, array_filter( $this->render( $victim ),
			static fn ( $n ) => $n['type'] === EchoSetupHooks::TYPE_REPLY ) );

		$this->gtModerate( 'delete', [ $reply ] );
		$this->gtModerate( 'erase', [ $reply ] );
		$mentions = array_filter( $this->render( $bystander ),
			static fn ( $n ) => $n['type'] === EchoSetupHooks::TYPE_MENTION );
		$this->assertSame( [], $mentions, 'the mention by the erased comment is gone, agent and all' );
		$this->assertTrue( $this->isHidden( $this->events( $victim, EchoSetupHooks::TYPE_REPLY )[0] ) );
		$this->assertTrue( $this->isHidden( $this->events( $bystander, EchoSetupHooks::TYPE_MENTION )[0] ) );
		// The other reply's notification is untouched
		$replies = array_values( array_filter( $this->render( $victim ),
			static fn ( $n ) => $n['type'] === EchoSetupHooks::TYPE_REPLY ) );
		$this->assertCount( 1, $replies );
		$this->assertSame( $otherAuthor->getName(), $replies[0]['agent']['name'] );
		$this->assertFalse( $this->isHidden( $this->events( $victim, EchoSetupHooks::TYPE_REPLY )[1] ) );
		$this->assertSame( $other->getId(), (int)$this->events( $victim, EchoSetupHooks::TYPE_REPLY )[1]
			->getExtraParam( 'comment-id' ) );
	}

	/**
	 * Notifications are created after the response is sent (a POSTSEND deferred update), not
	 * while the author waits.
	 */
	public function testNotificationsAreSentAfterTheResponse() {
		$page = $this->gtPage();
		$alice = $this->named( 'GtAlice' );
		$bob = $this->named( 'GtBob' );
		$top = $this->gtPost( $alice, $page, 'Top' );
		DeferredUpdates::doUpdates();
		$scope = DeferredUpdates::preventOpportunisticUpdates();
		$this->gtPost( $bob, $page, 'reply', $top );
		$count = fn () => (int)$this->getDb()->newSelectQueryBuilder()->select( 'COUNT(*)' )
			->from( 'echo_event' )->where( [ 'event_type' => EchoSetupHooks::TYPE_REPLY ] )
			->caller( __METHOD__ )->fetchField();
		$this->assertSame( 0, $count(), 'nothing before the response is sent' );
		$this->assertNotEmpty( DeferredUpdates::getPendingUpdates( DeferredUpdates::POSTSEND ) );
		DeferredUpdates::doUpdates( DeferredUpdates::PRESEND );
		$this->assertSame( 0, $count(), 'not a pre-send update either' );
		unset( $scope );
		DeferredUpdates::doUpdates( DeferredUpdates::POSTSEND );
		$this->assertSame( 1, $count() );
	}

	public function testHeldReplyNotifiesOnlyAfterApproval() {
		$page = $this->gtPage();
		$alice = $this->named( 'GtAlice' );
		$bob = $this->named( 'GtBob' );
		$carol = $this->named( 'GtCarol' );
		$top = $this->gtPost( $alice, $page, 'Top' );
		$held = $this->makeHeld( $page, $bob, self::token( $carol ) . ' look', $top );

		$this->assertSame( [], $this->events( $alice, EchoSetupHooks::TYPE_REPLY ) );
		$this->assertSame( [], $this->events( $carol, EchoSetupHooks::TYPE_MENTION ) );

		$this->gtModerate( 'approve', [ $held ] );
		$this->assertCount( 1, $this->events( $alice, EchoSetupHooks::TYPE_REPLY ) );
		$this->assertCount( 1, $this->events( $carol, EchoSetupHooks::TYPE_MENTION ) );
		$approved = $this->events( $bob, EchoSetupHooks::TYPE_MODERATION );
		$this->assertCount( 1, $approved );
		$this->assertSame( 'approve', $approved[0]->getExtraParam( 'action' ) );

		$model = $this->renderOne( $bob, EchoSetupHooks::TYPE_MODERATION );
		$this->assertStringContainsString( 'was approved', $model['header'] );
		$this->assertStringContainsString( 'look', $model['body'] );
		$this->assertMatchesRegularExpression(
			'/Special:GlowThreadLink\/' . $held->getId() . '(?!\d)/', $model['links']['primary']['url']
		);
	}

	/**
	 * A reply under the author's own held comment is held too, so it notifies nobody until a
	 * moderator approves it, like any held comment.
	 */
	public function testReplyUnderOwnHeldCommentNotifiesOnApproval() {
		$page = $this->gtPage();
		$alice = $this->named( 'GtAlice' );
		$carol = $this->named( 'GtCarol' );
		$held = $this->makeHeld( $page, $alice, 'Top', null );
		$reply = $this->gtPost( $alice, $page, 'More ' . self::token( $carol ), $held );
		$this->assertTrue( $reply->isHeld() );
		$this->assertSame( [ HoldReason::PARENT_HELD ], $reply->getHoldReasons() );
		$this->assertSame( [], $this->events( $carol, EchoSetupHooks::TYPE_MENTION ) );

		$this->gtModerate( 'approve', [ $held, $reply ] );
		$this->assertCount( 1, $this->events( $carol, EchoSetupHooks::TYPE_MENTION ) );
	}

	public function testApprovingAReportHoldDoesNotRepeatNotifications() {
		$this->overrideConfigValue( 'GlowThreadAutoHoldReporters', 1 );
		$page = $this->gtPage();
		$alice = $this->named( 'GtAlice' );
		$bob = $this->named( 'GtBob' );
		$carol = $this->named( 'GtCarol' );
		$top = $this->gtPost( $alice, $page, 'Top' );
		$reply = $this->gtPost( $bob, $page, 'Reply ' . self::token( $carol ), $top );
		$this->assertStatusGood(
			$this->gtService( 'ReportService' )->report( $this->gtEstablishedReporter(), $reply->getId(), 'spam', '' )
		);
		$this->assertTrue( $this->gtReload( $reply )->isHeld() );

		$this->gtModerate( 'approve', [ $reply ] );
		$this->assertCount( 1, $this->events( $alice, EchoSetupHooks::TYPE_REPLY ) );
		$this->assertCount( 1, $this->events( $carol, EchoSetupHooks::TYPE_MENTION ) );
		$this->assertCount( 1, $this->events( $bob, EchoSetupHooks::TYPE_MODERATION ), 'the author is told' );
	}

	public function testSelfMentionIsIgnored() {
		$page = $this->gtPage();
		$alice = $this->named( 'GtAlice' );
		$this->gtPost( $alice, $page, 'Note to self ' . self::token( $alice ) );
		$this->assertSame( [], $this->events( $alice, EchoSetupHooks::TYPE_MENTION ) );
	}

	/**
	 * A mention of a user whose name has an apostrophe shows that name, and the comma after it
	 * stays text. Typed without picking, the name is plain text and notifies nobody.
	 */
	public function testMentionOfNameWithApostrophe() {
		$page = $this->gtPage();
		$obrien = $this->named( "O'Brien" );
		$bob = $this->named( 'GtBob' );
		$post = fn ( string $text ) => $this->doApiRequestWithToken( [
			'action' => 'glowthreadpost',
			'pageid' => $page->getId(),
			'text' => $text,
		], null, $bob )[0]['glowthreadpost'];
		$result = $post( 'Thanks ' . self::token( $obrien ) . ', see you' );
		$this->assertSame( [
			[ 'type' => 'text', 'text' => 'Thanks ' ],
			[
				'type' => 'mention', 'text' => "@O'Brien", 'user' => "O'Brien", 'id' => $obrien->getId(),
				'exists' => true,
			],
			[ 'type' => 'text', 'text' => ', see you' ],
		], $result['comment']['segments'] );
		$this->assertCount( 1, $this->events( $obrien, EchoSetupHooks::TYPE_MENTION ) );

		$typed = $post( "Thanks again @O'Brien" );
		$this->assertSame( [ [ 'type' => 'text', 'text' => "Thanks again @O'Brien" ] ], $typed['comment']['segments'] );
		$this->assertCount( 1, $this->events( $obrien, EchoSetupHooks::TYPE_MENTION ), 'no second notification' );
	}

	public function testHeldMentionOfNameWithApostropheNotifiesOnApproval() {
		$page = $this->gtPage();
		$obrien = $this->named( "O'Brien" );
		$held = $this->makeHeld( $page, $this->named( 'GtBob' ), self::token( $obrien ) . "'s idea", null );
		$this->assertSame( [], $this->events( $obrien, EchoSetupHooks::TYPE_MENTION ) );
		$this->gtModerate( 'approve', [ $held ] );
		$this->assertCount( 1, $this->events( $obrien, EchoSetupHooks::TYPE_MENTION ) );
	}

	public function testMentionCapAppliesWhenApproving() {
		$page = $this->gtPage();
		$users = [ $this->named( 'GtCarol' ), $this->named( 'GtDan' ), $this->named( 'GtEve' ) ];
		$tokens = implode( ' ', array_map( static fn ( User $u ) => self::token( $u ), $users ) );
		$held = $this->makeHeld( $page, $this->named( 'GtBob' ), $tokens, null );

		// The cap was lowered while the comment waited for review
		$this->overrideConfigValue( 'GlowThreadMaxMentions', 1 );
		$this->gtModerate( 'approve', [ $held ] );
		$this->assertCount( 1, $this->events( $users[0], EchoSetupHooks::TYPE_MENTION ) );
		$this->assertSame( [], $this->events( $users[1], EchoSetupHooks::TYPE_MENTION ) );
		$this->assertSame( [], $this->events( $users[2], EchoSetupHooks::TYPE_MENTION ) );
	}

	public function testModerationNotices() {
		$page = $this->gtPage();
		$bob = $this->named( 'GtBob' );
		$post = $this->gtPost( $bob, $page, 'Comment' );

		$this->gtModerate( 'delete', [ $post ], 'Off topic' );
		$model = $this->renderOne( $bob, EchoSetupHooks::TYPE_MODERATION );
		$this->assertStringContainsString( 'was removed by a moderator', $model['header'] );
		$this->assertSame( 'Reason: Off topic', $model['body'] );
		$this->assertStringNotContainsString( 'GlowThreadLink', $model['links']['primary']['url'] );
		$this->assertStringNotContainsString( '#gt-', $model['links']['primary']['url'] );

		$this->gtModerate( 'restore', [ $post ] );
		$this->gtModerate( 'pin', [ $post ] );
		$this->gtModerate( 'delete', [ $post ] );
		$this->gtModerate( 'erase', [ $post ] );
		$actions = array_map(
			static fn ( Event $e ) => $e->getExtraParam( 'action' ),
			$this->events( $bob, EchoSetupHooks::TYPE_MODERATION )
		);
		$this->assertSame( [ 'delete', 'restore', 'delete' ], $actions, 'no notice for pin or erase' );

		// The author's own deletion notifies nobody
		$other = $this->gtPost( $bob, $page, 'Mine' );
		$this->assertStatusGood(
			$this->gtService( 'ModerationService' )->selfDelete( $bob, $other->getId(), '' )
		);
		$this->assertCount( 3, $this->events( $bob, EchoSetupHooks::TYPE_MODERATION ) );
	}

	/**
	 * A comment deleted while held goes back to held when restored. Its author is not told it
	 * was restored, because readers still cannot see it. The approval sends the usual notices.
	 */
	public function testRestoreToHeldNotifiesOnlyOnApproval() {
		$page = $this->gtPage();
		$alice = $this->named( 'GtAlice' );
		$bob = $this->named( 'GtBob' );
		$top = $this->gtPost( $alice, $page, 'Top' );
		$held = $this->makeHeld( $page, $bob, 'reply', $top );
		$actions = fn () => array_map(
			static fn ( Event $e ) => $e->getExtraParam( 'action' ),
			$this->events( $bob, EchoSetupHooks::TYPE_MODERATION )
		);

		$this->gtModerate( 'delete', [ $held ] );
		$this->gtModerate( 'restore', [ $held ] );
		$this->assertTrue( $this->gtReload( $held )->isHeld() );
		$this->assertSame( [ 'delete' ], $actions(), 'no restore notice while it is held' );
		$this->assertSame( [], $this->events( $alice, EchoSetupHooks::TYPE_REPLY ) );

		$this->gtModerate( 'approve', [ $held ] );
		$this->assertSame( [ 'delete', 'approve' ], $actions() );
		$this->assertCount( 1, $this->events( $alice, EchoSetupHooks::TYPE_REPLY ) );
	}

	private function deleteTitle( string $dbkey ): void {
		$services = $this->getServiceContainer();
		$page = $services->getPageStore()->getPageByName( NS_MAIN, $dbkey );
		$sysop = $this->getTestSysop()->getAuthority();
		$this->assertStatusGood( $services->getDeletePageFactory()
			->newDeletePage( $services->getWikiPageFactory()->newFromTitle( $page ), $sysop )
			->deleteUnsafe( 'test' ) );
		DeferredUpdates::doUpdates();
	}

	/**
	 * @param string $dbkey
	 * @param string[] $timestamps Revisions to restore, empty for all
	 * @return int The page ID after the undeletion
	 */
	private function undeleteTitle( string $dbkey, array $timestamps = [] ): int {
		$services = $this->getServiceContainer();
		// makeTitle(), not newFromText(): the latter caches Title objects with their old page ID
		$title = Title::makeTitle( NS_MAIN, $dbkey );
		$sysop = $this->getTestSysop()->getAuthority();
		$this->assertStatusGood( $services->getUndeletePageFactory()
			->newUndeletePage( $services->getWikiPageFactory()->newFromTitle( $title ), $sysop )
			->setUndeleteOnlyTimestamps( $timestamps )
			->undeleteUnsafe( 'test' ) );
		DeferredUpdates::doUpdates();
		return $services->getPageStore()->getPageByName( NS_MAIN, $dbkey )->getId();
	}

	/**
	 * Echo shows all notifications of an undeleted page again, including those GlowThread hid
	 * when a comment was erased. Those stay hidden, also for a comment erased while its page
	 * was deleted. Notifications about deleted or held comments show again with the neutral text.
	 */
	public function testUndeletingThePageKeepsErasedCommentNotificationsHidden() {
		$this->overrideConfigValue( 'GlowThreadAutoHoldReporters', 1 );
		$page = $this->gtPage( 'GlowThread undeleted page' );
		$alice = $this->named( 'GtAlice' );
		$carol = $this->named( 'GtCarol' );
		$dan = $this->named( 'GtDan' );
		$frank = $this->named( 'GtFrank' );
		$top = $this->gtPost( $alice, $page, 'top one' );
		$erased = $this->gtPost( $this->named( 'GtBob' ), $page, 'erased ' . self::token( $carol ), $top );
		$erasedLater = $this->gtPost( $this->named( 'GtIvy' ), $page, 'erased while the page is deleted', $top );
		$deleted = $this->gtPost( $this->named( 'GtEve' ), $page, 'deleted',
			$this->gtPost( $dan, $page, 'top two' ) );
		$held = $this->gtPost( $this->named( 'GtGina' ), $page, 'held',
			$this->gtPost( $frank, $page, 'top three' ) );
		$this->gtModerate( 'delete', [ $erased, $deleted ] );
		$this->gtModerate( 'erase', [ $erased ] );
		$this->assertStatusGood(
			$this->gtService( 'ReportService' )->report( $this->gtEstablishedReporter(), $held->getId(), 'spam', '' )
		);
		$this->assertTrue( $this->gtReload( $held )->isHeld() );
		$erasedEvents = array_merge(
			$this->events( $alice, EchoSetupHooks::TYPE_REPLY ),
			$this->events( $carol, EchoSetupHooks::TYPE_MENTION )
		);
		$this->assertCount( 3, $erasedEvents );
		$otherEvents = [
			$this->events( $dan, EchoSetupHooks::TYPE_REPLY )[0],
			$this->events( $frank, EchoSetupHooks::TYPE_REPLY )[0],
		];

		$this->deleteTitle( 'GlowThread_undeleted_page' );
		$this->assertTrue( $this->isHidden( $otherEvents[0] ), 'Echo hides the notifications of a deleted page' );
		$this->gtModerate( 'delete', [ $erasedLater ] );
		$this->gtModerate( 'erase', [ $erasedLater ] );
		$this->assertSame( $page->getId(), $this->undeleteTitle( 'GlowThread_undeleted_page' ) );

		foreach ( $erasedEvents as $event ) {
			$this->assertTrue( $this->isHidden( $event ), $event->getType() . ' of an erased comment' );
		}
		$this->assertSame( [], array_filter( $this->render( $carol ),
			static fn ( $n ) => $n['type'] === EchoSetupHooks::TYPE_MENTION ) );
		foreach ( [ $dan, $frank ] as $i => $user ) {
			$this->assertFalse( $this->isHidden( $otherEvents[$i] ) );
			$model = $this->renderOne( $user, EchoSetupHooks::TYPE_REPLY );
			$this->assertSame( 'This comment is no longer available.', $model['body'] );
			$this->assertStringNotContainsString( 'GlowThreadLink', $model['links']['primary']['url'] );
			$this->assertStringNotContainsString( '#gt-', $model['links']['primary']['url'] );
		}
	}

	/**
	 * Review: an undeletion that merges into a re-created page moves the comments to its page
	 * ID, while their notifications keep the old one. When a later undeletion gives the title
	 * its old page ID back, Echo shows those notifications again, also for a comment erased
	 * after the move. They are hidden again.
	 */
	public function testErasedCommentThatMovedStaysHiddenWhenItsOldPageIdComesBack() {
		$dbkey = 'GlowThread_moved_comments';
		$alice = $this->named( 'GtAlice' );
		ConvertibleTimestamp::setFakeTime( '20260101000000' );
		$first = $this->editPage( Title::makeTitle( NS_MAIN, $dbkey ), 'first' )->getNewRevision();
		ConvertibleTimestamp::setFakeTime( '20260102000000' );
		$second = $this->editPage( Title::makeTitle( NS_MAIN, $dbkey ), 'second' )->getNewRevision();
		$page = $this->getServiceContainer()->getPageStore()->getPageByName( NS_MAIN, $dbkey );
		$reply = $this->gtPost( $this->named( 'GtBob' ), $page, 'reply', $this->gtPost( $alice, $page, 'top' ) );
		$event = $this->events( $alice, EchoSetupHooks::TYPE_REPLY )[0];
		$this->deleteTitle( $dbkey );

		// Re-created, then the first revision restored into it: the comments move to its page ID
		ConvertibleTimestamp::setFakeTime( '20260103000000' );
		$this->editPage( Title::makeTitle( NS_MAIN, $dbkey ), 'recreated' );
		$newId = $this->undeleteTitle( $dbkey, [ $first->getTimestamp() ] );
		$this->assertNotSame( $page->getId(), $newId );
		$this->assertSame( $newId, $this->gtReload( $reply )->getPageId() );
		$this->gtModerate( 'delete', [ $reply ] );
		$this->gtModerate( 'erase', [ $reply ] );

		// Deleted again, then only the second revision restored: the old page ID is back
		$this->deleteTitle( $dbkey );
		$this->assertSame( $page->getId(), $this->undeleteTitle( $dbkey, [ $second->getTimestamp() ] ) );
		ConvertibleTimestamp::setFakeTime( false );
		$this->assertTrue( $this->isHidden( $event ) );
	}

	public function testExtraIsJsonSafe() {
		$page = $this->gtPage();
		$alice = $this->named( 'GtAlice' );
		$bob = $this->named( 'GtBob' );
		$top = $this->gtPost( $alice, $page, 'Top' );
		$this->gtPost( $bob, $page, 'Reply', $top );
		$this->gtModerate( 'delete', [ $top ], 'Reason 😀' );
		$events = array_merge(
			$this->events( $alice, EchoSetupHooks::TYPE_REPLY ),
			$this->events( $alice, EchoSetupHooks::TYPE_MODERATION )
		);
		$this->assertCount( 2, $events );
		foreach ( $events as $event ) {
			$extra = $event->getExtra();
			$this->assertSame( $extra, json_decode( json_encode( $extra, JSON_THROW_ON_ERROR ), true ) );
			$this->assertIsInt( $extra['comment-id'] );
		}
	}

	public function testRepliesOnOnePageAreBundled() {
		$page = $this->gtPage();
		$alice = $this->named( 'GtAlice' );
		$top = $this->gtPost( $alice, $page, 'Top' );
		$this->gtPost( $this->named( 'GtBob' ), $page, 'First reply', $top );
		$this->gtPost( $this->named( 'GtCarol' ), $page, 'Second reply', $top );

		$items = array_values( array_filter(
			$this->render( $alice, true ),
			static fn ( $n ) => $n['type'] === EchoSetupHooks::TYPE_REPLY
		) );
		$this->assertCount( 1, $items );
		$this->assertStringContainsString( '2 new replies', $items[0]['*']['header'] );
	}

	/**
	 * Registers FlowThread's notification types as on a wiki with $wgGlowThreadLegacyCompat on.
	 * Echo runs BeforeCreateEchoEvent once during setup, before a test can change the setting.
	 */
	private function enableLegacyTypes(): void {
		global $wgEchoNotifications, $wgEchoNotificationCategories;
		$this->overrideConfigValue( 'GlowThreadLegacyCompat', true );
		$notifications = $wgEchoNotifications;
		$categories = $wgEchoNotificationCategories;
		$icons = [];
		( new EchoSetupHooks( $this->gtService( 'Config' ) ) )
			->onBeforeCreateEchoEvent( $notifications, $categories, $icons );
		$this->overrideConfigValues( [
			'EchoNotifications' => $notifications,
			'EchoNotificationCategories' => $categories,
		] );
	}

	/**
	 * Store a notification the way FlowThread did (PHP-serialized extra) and render it.
	 */
	private function renderLegacy(
		string $type, User $recipient, ProperPageIdentity $page, string $postId, User $agent
	): array {
		$this->enableLegacyTypes();
		$extra = $type === 'flowthread_delete'
			? [ 'notifyAgent' => true, 'postid' => $postId ]
			: [ 'target-user-id' => [ $recipient->getId() ], 'postid' => $postId ];
		$db = $this->getDb();
		$db->newInsertQueryBuilder()
			->insertInto( 'echo_event' )
			->row( [
				'event_type' => $type,
				'event_agent_id' => $agent->getId(),
				'event_extra' => serialize( $extra ),
				'event_page_id' => $page->getId(),
				'event_deleted' => 0,
			] )
			->caller( __METHOD__ )
			->execute();
		$db->newInsertQueryBuilder()
			->insertInto( 'echo_notification' )
			->row( [
				'notification_event' => $db->insertId(),
				'notification_user' => $recipient->getId(),
				'notification_timestamp' => $db->timestamp(),
				'notification_bundle_hash' => '',
			] )
			->caller( __METHOD__ )
			->execute();
		return $this->renderOne( $recipient, $type );
	}

	private function importAs( PostRecord $post, string $hex ): void {
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'glowthread_post' )
			->set( [ 'gtp_legacy_id' => $hex ] )
			->where( [ 'gtp_id' => $post->getId() ] )
			->caller( __METHOD__ )
			->execute();
	}

	public static function provideLegacyPayload() {
		$hex = '0605b2a3c4d5e6f7a8b9c0';
		return [
			'binary, as FlowThread stored it' => [ hex2bin( $hex ), $hex ],
			'hex' => [ $hex, $hex ],
			'upper-case hex' => [ strtoupper( $hex ), $hex ],
		];
	}

	/**
	 * @dataProvider provideLegacyPayload
	 */
	public function testLegacyReplyRendersImportedComment( string $postId, string $hex ) {
		$page = $this->gtPage();
		$alice = $this->named( 'GtAlice' );
		$bob = $this->named( 'GtBob' );
		$post = $this->gtPost( $bob, $page, 'Old <i>reply</i>' );
		$this->importAs( $post, $hex );

		$model = $this->renderLegacy( 'flowthread_reply', $alice, $page, $postId, $bob );
		$this->assertStringContainsString( $bob->getName(), $model['header'] );
		$this->assertStringContainsString( 'replied', $model['header'] );
		// The excerpt is wrapped in bidi embedding characters
		$this->assertStringContainsString( 'Old &lt;i&gt;reply&lt;/i&gt;', $model['body'] );
		$this->assertMatchesRegularExpression(
			'/Special:GlowThreadLink\/' . $post->getId() . '(?!\d)/', $model['links']['primary']['url']
		);
	}

	public function testLegacyNotificationWithoutImportedComment() {
		$page = $this->gtPage();
		$alice = $this->named( 'GtAlice' );
		$model = $this->renderLegacy(
			'flowthread_mention', $alice, $page, hex2bin( 'ffffffffffffffffffffff' ), $this->named( 'GtBob' )
		);
		$this->assertStringContainsString( 'mentioned', $model['header'] );
		$this->assertSame( 'This comment is no longer available.', $model['body'] );
		$this->assertStringNotContainsString( 'GlowThreadLink', $model['links']['primary']['url'] );
		$this->assertStringNotContainsString( '#gt-', $model['links']['primary']['url'] );
		$this->assertStringContainsString( 'GlowThread', $model['links']['primary']['url'] );
	}

	public function testLegacyDeleteAlert() {
		$page = $this->gtPage();
		$alice = $this->named( 'GtAlice' );
		$post = $this->gtPost( $alice, $page, 'Mine' );
		$this->importAs( $post, '0605b2a3c4d5e6f7a8b9c1' );
		$this->gtModerate( 'delete', [ $post ] );
		$model = $this->renderLegacy( 'flowthread_delete', $alice, $page, hex2bin( '0605b2a3c4d5e6f7a8b9c1' ), $alice );
		$this->assertStringContainsString( 'was deleted', $model['header'] );
		$this->assertSame( 'This comment is no longer available.', $model['body'] );
		$this->assertSame( [], $model['links']['secondary'] );
	}

	public function testLegacyIdDecoding() {
		$hex = '0605b2a3c4d5e6f7a8b9c0';
		$this->assertSame( $hex, LegacyPresentationModel::toLegacyHex( hex2bin( $hex ) ) );
		$this->assertSame( $hex, LegacyPresentationModel::toLegacyHex( strtoupper( $hex ) ) );
		$this->assertNull( LegacyPresentationModel::toLegacyHex( 'short' ) );
		$this->assertNull( LegacyPresentationModel::toLegacyHex( null ) );
		$this->assertNull( LegacyPresentationModel::toLegacyHex( str_repeat( 'z', 22 ) ) );
	}

	public function testTypesAreRegistered() {
		global $wgEchoNotifications, $wgEchoNotificationCategories;
		$types = [ EchoSetupHooks::TYPE_REPLY, EchoSetupHooks::TYPE_MENTION, EchoSetupHooks::TYPE_MODERATION ];
		foreach ( $types as $type ) {
			$this->assertSame( EchoSetupHooks::CATEGORY, $wgEchoNotifications[$type]['category'] );
		}
		$this->assertArrayHasKey( EchoSetupHooks::CATEGORY, $wgEchoNotificationCategories );
		$options = $this->getServiceContainer()->getUserOptionsLookup();
		$user = $this->named( 'GtAlice' );
		$this->assertTrue( (bool)$options->getOption( $user, 'echo-subscriptions-web-glowthread' ) );
		$this->assertFalse( (bool)$options->getOption( $user, 'echo-subscriptions-email-glowthread' ) );
	}
}
