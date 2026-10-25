<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Echo;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\GlowThread\Echo\EchoSetupHooks;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Extension\GlowThread\Text\TextProcessor;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use TestUser;

/**
 * glowthread-userpage: a comment on User:Name tells Name, as FlowThread's flowthread_userpage did.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Echo\NotificationHooks
 * @covers \MediaWiki\Extension\GlowThread\Echo\EchoSetupHooks
 * @covers \MediaWiki\Extension\GlowThread\Echo\CommentPresentationModel
 */
class UserPageNotificationTest extends ApiTestCase {
	use GlowThreadTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'Echo' );
		$this->setUpGlowThread();
		$this->overrideConfigValues( [
			'EchoUseJobQueue' => false,
			'GlowThreadNamespaces' => [ NS_MAIN => true, NS_USER => true ],
		] );
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

	private function named( string $name ): User {
		return ( new TestUser( $name ) )->getUser();
	}

	public function testCommentOnAUserPageNotifiesItsOwner() {
		$alice = $this->named( 'GtAlice' );
		$bob = $this->named( 'GtBob' );
		$post = $this->gtPost( $bob, $this->gtPage( 'User:GtAlice' ), 'Hello <b>there</b>' );

		$events = $this->events( $alice, EchoSetupHooks::TYPE_USERPAGE );
		$this->assertCount( 1, $events );
		$this->assertSame( $post->getId(), (int)$events[0]->getExtraParam( 'comment-id' ) );
		$this->assertSame( $bob->getId(), $events[0]->getAgent()->getId() );

		DeferredUpdates::doUpdates();
		$list = $this->doApiRequest( [
			'action' => 'query', 'meta' => 'notifications', 'notformat' => 'model', 'notprop' => 'list',
			'uselang' => 'en',
		], null, false, $alice )[0]['query']['notifications']['list'];
		// Echo may also tell Alice that her user page was created
		$list = array_values( array_filter( $list, static fn ( $n ) => $n['type'] === EchoSetupHooks::TYPE_USERPAGE ) );
		$this->assertCount( 1, $list );
		$model = $list[0]['*'];
		$this->assertStringContainsString( 'commented', $model['header'] );
		$this->assertStringContainsString( 'your user page', $model['header'] );
		$this->assertSame( 'edit-user-talk', $model['icon'] );
		$this->assertStringContainsString( '&lt;b&gt;there&lt;/b&gt;', $model['body'] );
		$this->assertMatchesRegularExpression(
			'/Special:GlowThreadLink\/' . $post->getId() . '(?!\d)/', $model['links']['primary']['url']
		);
	}

	public function testOwnCommentNotifiesNobody() {
		$alice = $this->named( 'GtAlice' );
		$this->gtPost( $alice, $this->gtPage( 'User:GtAlice' ), 'My own page' );
		$this->assertSame( [], $this->events( $alice, EchoSetupHooks::TYPE_USERPAGE ) );
	}

	public function testSubpagesAndOtherPagesDoNotNotify() {
		$alice = $this->named( 'GtAlice' );
		$bob = $this->named( 'GtBob' );
		$this->gtPost( $bob, $this->gtPage( 'User:GtAlice/Sandbox' ), 'On a subpage' );
		$this->gtPost( $bob, $this->gtPage( 'GtAlice' ), 'Same name, main namespace' );
		$this->assertSame( [], $this->events( $alice, EchoSetupHooks::TYPE_USERPAGE ) );
	}

	/**
	 * The owner gets one notification per comment: a reply or a mention wins.
	 */
	public function testReplyAndMentionComeFirst() {
		$alice = $this->named( 'GtAlice' );
		$bob = $this->named( 'GtBob' );
		$page = $this->gtPage( 'User:GtAlice' );
		$top = $this->gtPost( $alice, $page, 'Welcome' );
		$this->gtPost( $bob, $page, 'Thanks', $top );
		$this->gtPost( $bob, $page, 'Hi ' . TextProcessor::mentionToken( $alice->getId() ) );

		$this->assertCount( 1, $this->events( $alice, EchoSetupHooks::TYPE_REPLY ) );
		$this->assertCount( 1, $this->events( $alice, EchoSetupHooks::TYPE_MENTION ) );
		$this->assertSame( [], $this->events( $alice, EchoSetupHooks::TYPE_USERPAGE ) );

		// A reply to someone else on the page still tells the owner, once
		$carol = $this->named( 'GtCarol' );
		$other = $this->gtPost( $carol, $page, 'Carol here' );
		$this->gtPost( $bob, $page, 'Hi Carol', $other );
		$this->assertCount( 1, $this->events( $carol, EchoSetupHooks::TYPE_REPLY ) );
		$this->assertCount( 2, $this->events( $alice, EchoSetupHooks::TYPE_USERPAGE ), 'Carol\'s and Bob\'s' );
	}

	public function testHeldCommentNotifiesWhenApproved() {
		$alice = $this->named( 'GtAlice' );
		$bob = $this->named( 'GtBob' );
		$this->gtSetFilter( 'holdword' );
		$held = $this->gtPost( $bob, $this->gtPage( 'User:GtAlice' ), 'holdword hello' );
		$this->assertTrue( $held->isHeld() );
		$this->assertSame( [], $this->events( $alice, EchoSetupHooks::TYPE_USERPAGE ) );

		$this->gtModerate( 'approve', [ $held ] );
		$this->assertCount( 1, $this->events( $alice, EchoSetupHooks::TYPE_USERPAGE ) );
	}

	public function testErasingHidesIt() {
		$alice = $this->named( 'GtAlice' );
		$bob = $this->named( 'GtBob' );
		$post = $this->gtPost( $bob, $this->gtPage( 'User:GtAlice' ), 'abusive' );
		$this->gtModerate( 'delete', [ $post ] );
		$this->gtModerate( 'erase', [ $post ] );
		$event = $this->events( $alice, EchoSetupHooks::TYPE_USERPAGE )[0];
		$deleted = $this->getDb()->newSelectQueryBuilder()->select( 'event_deleted' )->from( 'echo_event' )
			->where( [ 'event_id' => $event->getId() ] )->caller( __METHOD__ )->fetchField();
		$this->assertSame( 1, (int)$deleted );
	}
}
