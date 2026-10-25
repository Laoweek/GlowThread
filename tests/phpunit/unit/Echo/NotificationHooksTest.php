<?php

namespace MediaWiki\Extension\GlowThread\Tests\Unit\Echo;

use MediaWiki\Block\AbstractBlock;
use MediaWiki\Block\BlockManager;
use MediaWiki\Extension\GlowThread\Echo\EchoSetupHooks;
use MediaWiki\Extension\GlowThread\Echo\NotificationHooks;
use MediaWiki\Extension\GlowThread\Model\HoldReason;
use MediaWiki\Extension\GlowThread\Model\PageState;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Model\PostStatus;
use MediaWiki\Extension\GlowThread\Settings\SettingsService;
use MediaWiki\Extension\GlowThread\Store\PageStateStore;
use MediaWiki\Extension\GlowThread\Store\PostStore;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;

/**
 * Who gets which notification. Echo is replaced by a recorder of Event::create() calls.
 *
 * @covers \MediaWiki\Extension\GlowThread\Echo\NotificationHooks
 */
class NotificationHooksTest extends MediaWikiUnitTestCase {

	/** @var array[] Event::create() info arrays */
	private array $events = [];
	/** @var int[] User IDs that cannot read the page */
	private array $cannotRead = [];
	private ?AbstractBlock $authorBlock = null;
	private int $maxMentions = 5;
	/** Whether the comment and the comments it is shown under are visible */
	private bool $publiclyVisible = true;
	private string $pageState = PageState::OPEN;

	private function newHooks(): NotificationHooks {
		$settings = $this->createMock( SettingsService::class );
		$settings->method( 'get' )->willReturnCallback(
			fn ( string $name ) => $name === 'maxMentions' ? $this->maxMentions : null
		);
		$postStore = $this->createMock( PostStore::class );
		$postStore->method( 'isPubliclyVisible' )->willReturnCallback(
			fn ( PostRecord $post ) => $post->isVisible() && $this->publiclyVisible
		);
		$pageStateStore = $this->createMock( PageStateStore::class );
		$pageStateStore->method( 'getState' )->willReturnCallback( fn () => $this->pageState );
		$userFactory = $this->createMock( UserFactory::class );
		$userFactory->method( 'newFromUserIdentity' )->willReturnCallback( function ( UserIdentity $identity ) {
			$user = $this->createMock( User::class );
			$user->method( 'getId' )->willReturn( $identity->getId() );
			$user->method( 'getName' )->willReturn( $identity->getName() );
			$user->method( 'definitelyCan' )->willReturn( !in_array( $identity->getId(), $this->cannotRead, true ) );
			return $user;
		} );
		$blockManager = $this->createMock( BlockManager::class );
		$blockManager->method( 'getBlock' )->willReturnCallback( fn () => $this->authorBlock );
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'newFromPageIdentity' )->willReturn( $this->createMock( Title::class ) );
		return new NotificationHooks(
			$settings,
			$postStore,
			$pageStateStore,
			$userFactory,
			$blockManager,
			$titleFactory,
			function ( array $info ) {
				$this->events[] = $info;
			}
		);
	}

	private function post(
		int $id,
		string $status = PostStatus::VISIBLE,
		?int $replyTo = null,
		array $holdReasons = [],
		?string $legacyId = null
	): PostRecord {
		return new PostRecord(
			$id, 42, 100 + $id, $replyTo, $replyTo, $replyTo, $replyTo ? 1 : 0, $id, 'text', '20261008120000',
			null, $status, $holdReasons, null, null, 0, 0, 0, null, $legacyId
		);
	}

	private function page(): PageIdentityValue {
		return new PageIdentityValue( 42, NS_MAIN, 'Test', PageIdentityValue::LOCAL );
	}

	private function user( int $id ): UserIdentityValue {
		return new UserIdentityValue( $id, "User$id" );
	}

	/**
	 * @return array<string,array> Event type => recipients
	 */
	private function recipientsByType(): array {
		$out = [];
		foreach ( $this->events as $info ) {
			$out[$info['type']] = $info['extra'][NotificationHooks::RECIPIENTS];
		}
		return $out;
	}

	public function testReplyAndMentions() {
		$this->newHooks()->onGlowThreadPostSaved(
			$this->post( 2, PostStatus::VISIBLE, 1 ), $this->page(), $this->user( 1 ),
			[ $this->user( 3 ), $this->user( 4 ) ], $this->post( 1 ), $this->user( 2 )
		);
		$this->assertSame( [
			EchoSetupHooks::TYPE_REPLY => [ 2 ],
			EchoSetupHooks::TYPE_MENTION => [ 3, 4 ],
		], $this->recipientsByType() );
		$reply = $this->events[0];
		$this->assertSame( [ 'comment-id' => 2, 'reply-to-id' => 1, 'recipients' => [ 2 ] ], $reply['extra'] );
		$this->assertSame( 1, $reply['agent']->getId() );
	}

	public function testExtraHoldsOnlyIntsAndStrings() {
		$hooks = $this->newHooks();
		$hooks->onGlowThreadPostSaved(
			$this->post( 2, PostStatus::VISIBLE, 1 ), $this->page(), $this->user( 1 ),
			[ $this->user( 3 ) ], $this->post( 1 ), $this->user( 2 )
		);
		$hooks->onGlowThreadPostModerated(
			$this->post( 2, PostStatus::DELETED ), $this->page(), 'delete', $this->user( 9 ), 'Spam 😀',
			$this->user( 1 ), 7
		);
		$this->assertCount( 3, $this->events );
		foreach ( $this->events as $info ) {
			array_walk_recursive( $info['extra'], function ( $value ) {
				$this->assertTrue( is_int( $value ) || is_string( $value ), 'Echo 1.46 stores extra as JSON' );
			} );
			$this->assertSame( $info['extra'], json_decode( json_encode( $info['extra'] ), true ) );
		}
	}

	public function testHeldCommentNotifiesNobody() {
		$this->newHooks()->onGlowThreadPostSaved(
			$this->post( 2, PostStatus::HELD, 1 ), $this->page(), $this->user( 1 ),
			[ $this->user( 3 ) ], $this->post( 1 ), $this->user( 2 )
		);
		$this->assertSame( [], $this->events );
	}

	public function testApprovalSendsWhatSavingHeldBack() {
		$this->newHooks()->onGlowThreadPostApproved(
			$this->post( 2, PostStatus::VISIBLE, 1 ), $this->page(), $this->user( 1 ),
			[ $this->user( 3 ) ], $this->post( 1 ), $this->user( 2 ), $this->user( 9 )
		);
		$this->assertSame( [
			EchoSetupHooks::TYPE_REPLY => [ 2 ],
			EchoSetupHooks::TYPE_MENTION => [ 3 ],
		], $this->recipientsByType() );
	}

	public function testReplyUnderOwnHeldCommentNotifiesNobody() {
		// The author may reply under their own held comment. Readers cannot see that reply.
		$this->publiclyVisible = false;
		$this->newHooks()->onGlowThreadPostSaved(
			$this->post( 2, PostStatus::VISIBLE, 1 ), $this->page(), $this->user( 1 ),
			[ $this->user( 3 ) ], $this->post( 1, PostStatus::HELD ), $this->user( 1 )
		);
		$this->assertSame( [], $this->events );
	}

	public function testNothingWhileCommentsAreTurnedOff() {
		$this->pageState = PageState::DISABLED;
		$this->newHooks()->onGlowThreadPostApproved(
			$this->post( 2, PostStatus::VISIBLE, 1 ), $this->page(), $this->user( 1 ),
			[ $this->user( 3 ) ], $this->post( 1 ), $this->user( 2 ), $this->user( 9 )
		);
		$this->assertSame( [], $this->events );
	}

	public function testApprovalAfterReportHoldDoesNotRepeat() {
		$this->newHooks()->onGlowThreadPostApproved(
			$this->post( 2, PostStatus::VISIBLE, 1, [ HoldReason::REPORTS ] ), $this->page(), $this->user( 1 ),
			[ $this->user( 3 ) ], $this->post( 1 ), $this->user( 2 ), $this->user( 9 )
		);
		$this->assertSame( [], $this->events );
	}

	public function testApprovalOfImportedCommentDoesNotNotify() {
		$this->newHooks()->onGlowThreadPostApproved(
			$this->post( 2, PostStatus::VISIBLE, 1, [ HoldReason::FILTER ], '0605b2a3c4d5e6f7a8b9c0' ), $this->page(),
			$this->user( 1 ), [ $this->user( 3 ) ], $this->post( 1 ), $this->user( 2 ), $this->user( 9 )
		);
		$this->assertSame( [], $this->events );
	}

	public function testNoNotificationToSelf() {
		// Replying to oneself and mentioning oneself
		$this->newHooks()->onGlowThreadPostSaved(
			$this->post( 2, PostStatus::VISIBLE, 1 ), $this->page(), $this->user( 1 ),
			[ $this->user( 1 ) ], $this->post( 1 ), $this->user( 1 )
		);
		$this->assertSame( [], $this->events );
	}

	public function testReplyRecipientMentionedGetsOneNotification() {
		$this->newHooks()->onGlowThreadPostSaved(
			$this->post( 2, PostStatus::VISIBLE, 1 ), $this->page(), $this->user( 1 ),
			[ $this->user( 2 ), $this->user( 3 ), $this->user( 2 ) ], $this->post( 1 ), $this->user( 2 )
		);
		$this->assertSame( [
			EchoSetupHooks::TYPE_REPLY => [ 2 ],
			EchoSetupHooks::TYPE_MENTION => [ 3 ],
		], $this->recipientsByType() );
	}

	public function testMentionCap() {
		$this->maxMentions = 2;
		$this->newHooks()->onGlowThreadPostSaved(
			$this->post( 2 ), $this->page(), $this->user( 1 ),
			[ $this->user( 3 ), $this->user( 4 ), $this->user( 5 ), $this->user( 6 ) ], null, null
		);
		$this->assertSame( [ EchoSetupHooks::TYPE_MENTION => [ 3, 4 ] ], $this->recipientsByType() );
	}

	public function testMentionCapZeroSendsNoMentions() {
		$this->maxMentions = 0;
		$this->newHooks()->onGlowThreadPostSaved(
			$this->post( 2 ), $this->page(), $this->user( 1 ), [ $this->user( 3 ) ], null, null
		);
		$this->assertSame( [], $this->events );
	}

	public function testRecipientWhoCannotReadIsSkipped() {
		$this->cannotRead = [ 2, 4 ];
		$this->newHooks()->onGlowThreadPostSaved(
			$this->post( 2, PostStatus::VISIBLE, 1 ), $this->page(), $this->user( 1 ),
			[ $this->user( 3 ), $this->user( 4 ) ], $this->post( 1 ), $this->user( 2 )
		);
		$this->assertSame( [ EchoSetupHooks::TYPE_MENTION => [ 3 ] ], $this->recipientsByType() );
	}

	public function testBlockedReplierNotifiesNobody() {
		$block = $this->createMock( AbstractBlock::class );
		$block->method( 'appliesToTitle' )->willReturn( true );
		$this->authorBlock = $block;
		$this->newHooks()->onGlowThreadPostApproved(
			$this->post( 2, PostStatus::VISIBLE, 1 ), $this->page(), $this->user( 1 ),
			[ $this->user( 3 ) ], $this->post( 1 ), $this->user( 2 ), $this->user( 9 )
		);
		$this->assertSame( [], $this->events );
	}

	public function testBlockElsewhereDoesNotStopNotifications() {
		$block = $this->createMock( AbstractBlock::class );
		$block->method( 'appliesToTitle' )->willReturn( false );
		$this->authorBlock = $block;
		$this->newHooks()->onGlowThreadPostSaved(
			$this->post( 2, PostStatus::VISIBLE, 1 ), $this->page(), $this->user( 1 ),
			[], $this->post( 1 ), $this->user( 2 )
		);
		$this->assertSame( [ EchoSetupHooks::TYPE_REPLY => [ 2 ] ], $this->recipientsByType() );
	}

	public function testIpAuthorsAreNotNotified() {
		$this->newHooks()->onGlowThreadPostSaved(
			$this->post( 2, PostStatus::VISIBLE, 1 ), $this->page(), $this->user( 1 ),
			[], $this->post( 1 ), new UserIdentityValue( 0, '203.0.113.7' )
		);
		$this->assertSame( [], $this->events );
	}

	public static function provideModeration() {
		return [
			'delete' => [ 'delete', true ],
			'restore' => [ 'restore', true ],
			'approve' => [ 'approve', true ],
			'erase follows the delete notice' => [ 'erase', false ],
			'pin' => [ 'pin', false ],
			'unpin' => [ 'unpin', false ],
			'dismiss' => [ 'dismiss', false ],
			'self-delete' => [ 'selfdelete', false ],
		];
	}

	/**
	 * @dataProvider provideModeration
	 */
	public function testModerationNotice( string $action, bool $expected ) {
		$this->newHooks()->onGlowThreadPostModerated(
			$this->post( 2 ), $this->page(), $action, $this->user( 9 ), 'Reason', $this->user( 1 ), 5
		);
		if ( !$expected ) {
			$this->assertSame( [], $this->events );
			return;
		}
		$this->assertCount( 1, $this->events );
		$info = $this->events[0];
		$this->assertSame( EchoSetupHooks::TYPE_MODERATION, $info['type'] );
		$this->assertArrayNotHasKey( 'agent', $info, 'The moderator is not named' );
		$this->assertSame(
			[ 'comment-id' => 2, 'action' => $action, 'reason' => 'Reason', 'recipients' => [ 1 ] ],
			$info['extra']
		);
	}

	/**
	 * A restore that puts a comment deleted while held back to held tells the author nothing:
	 * readers still cannot see it. Its approval sends the notices later.
	 */
	public function testNoRestoreNoticeWhenTheCommentIsHeldAgain() {
		$this->newHooks()->onGlowThreadPostModerated(
			$this->post( 2, PostStatus::HELD, null, [ HoldReason::FILTER ] ), $this->page(), 'restore',
			$this->user( 9 ), '', $this->user( 1 ), 5
		);
		$this->assertSame( [], $this->events );
	}

	public function testNoModerationNoticeForOwnCommentOrMissingPage() {
		$hooks = $this->newHooks();
		$hooks->onGlowThreadPostModerated(
			$this->post( 2 ), $this->page(), 'delete', $this->user( 1 ), '', $this->user( 1 ), 5
		);
		$hooks->onGlowThreadPostModerated(
			$this->post( 2 ), null, 'delete', $this->user( 9 ), '', $this->user( 1 ), 5
		);
		$hooks->onGlowThreadPostModerated(
			$this->post( 2 ), $this->page(), 'delete', $this->user( 9 ), '',
			new UserIdentityValue( 0, '203.0.113.7' ), 5
		);
		$this->assertSame( [], $this->events );
	}

	public function testLongReasonIsCut() {
		// i18n: cut by characters, not bytes (a CJK character is three bytes)
		$this->newHooks()->onGlowThreadPostModerated(
			$this->post( 2 ), $this->page(), 'delete', $this->user( 9 ), str_repeat( '字', 600 ), $this->user( 1 ), 5
		);
		$this->assertSame( 500, mb_strlen( $this->events[0]['extra']['reason'] ) );
	}
}
