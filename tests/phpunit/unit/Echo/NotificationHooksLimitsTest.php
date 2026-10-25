<?php

namespace MediaWiki\Extension\GlowThread\Tests\Unit\Echo;

use MediaWiki\Block\BlockManager;
use MediaWiki\Extension\GlowThread\Echo\EchoSetupHooks;
use MediaWiki\Extension\GlowThread\Echo\NotificationHooks;
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
use Wikimedia\WRStats\ArrayStatsStore;
use Wikimedia\WRStats\LimitCondition;
use Wikimedia\WRStats\WRStatsFactory;
use Wikimedia\WRStats\WRStatsRateLimiter;

/**
 * Limits on comment notifications, with Echo replaced by recorders: a comment whose
 * notifications went out sends nothing on a later approval, one author notifies one user at
 * most MAX_PER_RECIPIENT times, and the author's own deletion hides what the comment sent.
 *
 * @covers \MediaWiki\Extension\GlowThread\Echo\NotificationHooks
 * @covers \MediaWiki\Extension\GlowThread\Echo\ErasedCommentEvents::appliesTo
 */
class NotificationHooksLimitsTest extends MediaWikiUnitTestCase {

	/** @var array[] Event::create() info arrays */
	private array $events = [];
	/** @var int[] IDs of the comments whose events were hidden */
	private array $hidden = [];
	private bool $wasNotified = false;

	private function newHooks( ?WRStatsRateLimiter $limiter = null ): NotificationHooks {
		$settings = $this->createMock( SettingsService::class );
		$settings->method( 'get' )->willReturn( 5 );
		$postStore = $this->createMock( PostStore::class );
		$postStore->method( 'isPubliclyVisible' )->willReturnCallback( static fn ( PostRecord $p ) => $p->isVisible() );
		$pageStateStore = $this->createMock( PageStateStore::class );
		$pageStateStore->method( 'getState' )->willReturn( PageState::OPEN );
		$userFactory = $this->createMock( UserFactory::class );
		$userFactory->method( 'newFromUserIdentity' )->willReturnCallback( function ( UserIdentity $identity ) {
			$user = $this->createMock( User::class );
			$user->method( 'getId' )->willReturn( $identity->getId() );
			$user->method( 'definitelyCan' )->willReturn( true );
			return $user;
		} );
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'newFromPageIdentity' )->willReturn( $this->createMock( Title::class ) );
		return new NotificationHooks(
			$settings,
			$postStore,
			$pageStateStore,
			$userFactory,
			$this->createMock( BlockManager::class ),
			$titleFactory,
			function ( array $info ) {
				$this->events[] = $info;
			},
			function ( PostRecord $post ) {
				$this->hidden[] = $post->getId();
			},
			fn () => $this->wasNotified,
			$limiter
		);
	}

	/**
	 * A comment by actor 101 (user 1), replying to comment 1.
	 */
	private function post( int $id, string $status = PostStatus::VISIBLE, ?int $statusActorId = null ): PostRecord {
		return new PostRecord(
			$id, 42, 101, 1, 1, 1, 1, $id, 'text', '20261008120000',
			null, $status, [], $statusActorId, null, 0, 0, 0, null, null
		);
	}

	private function page(): PageIdentityValue {
		return new PageIdentityValue( 42, NS_MAIN, 'Test', PageIdentityValue::LOCAL );
	}

	private function user( int $id ): UserIdentityValue {
		return new UserIdentityValue( $id, "User$id" );
	}

	private function reply( NotificationHooks $hooks, int $id ): void {
		$hooks->onGlowThreadPostSaved(
			$this->post( $id ), $this->page(), $this->user( 1 ), [ $this->user( 3 ) ],
			$this->post( 1 ), $this->user( 2 )
		);
	}

	public function testApprovalSendsNothingForACommentThatNotifiedBefore() {
		$hooks = $this->newHooks();
		$approve = fn () => $hooks->onGlowThreadPostApproved(
			$this->post( 2 ), $this->page(), $this->user( 1 ), [], $this->post( 1 ), $this->user( 2 ), $this->user( 9 )
		);
		$approve();
		$this->assertCount( 1, $this->events );
		$this->wasNotified = true;
		$approve();
		$this->assertCount( 1, $this->events, 'not again' );
	}

	public function testOneAuthorNotifiesOneUserAtMostThreeTimes() {
		$hooks = $this->newHooks(
			( new WRStatsFactory( new ArrayStatsStore() ) )->createRateLimiter(
				[ 'notify' => new LimitCondition( NotificationHooks::MAX_PER_RECIPIENT, 3600 ) ]
			)
		);
		for ( $id = 2; $id < 2 + NotificationHooks::MAX_PER_RECIPIENT + 2; $id++ ) {
			$this->reply( $hooks, $id );
		}
		$byType = [];
		foreach ( $this->events as $info ) {
			$byType[$info['type']] = ( $byType[$info['type']] ?? 0 ) + 1;
		}
		$this->assertSame( [
			EchoSetupHooks::TYPE_REPLY => NotificationHooks::MAX_PER_RECIPIENT,
			EchoSetupHooks::TYPE_MENTION => NotificationHooks::MAX_PER_RECIPIENT,
		], $byType, 'per recipient: user 2 by reply, user 3 by mention' );
	}

	public function testNoCapWithoutALimiter() {
		$hooks = $this->newHooks();
		for ( $id = 2; $id < 12; $id++ ) {
			$this->reply( $hooks, $id );
		}
		$this->assertCount( 20, $this->events );
	}

	public static function provideHiding() {
		// Comment author actor 101. Moderator actor 900.
		yield 'author deletes their comment' => [ 'selfdelete', PostStatus::DELETED, 101, true ];
		yield 'moderator deletes their own comment' => [ 'delete', PostStatus::DELETED, 101, true ];
		yield 'undo puts back the author\'s deletion' => [ 'undo', PostStatus::DELETED, 101, true ];
		yield 'erase' => [ 'erase', PostStatus::ERASED, 900, true ];
		yield 'moderator deletes someone else\'s comment' => [ 'delete', PostStatus::DELETED, 900, false ];
		yield 'pin does nothing' => [ 'pin', PostStatus::DELETED, 101, false ];
		yield 'restore' => [ 'restore', PostStatus::VISIBLE, 900, false ];
	}

	/**
	 * @dataProvider provideHiding
	 */
	public function testAuthorsOwnDeletionHidesTheCommentsNotifications(
		string $action, string $status, int $statusActorId, bool $hidden
	) {
		$this->newHooks()->onGlowThreadPostModerated(
			$this->post( 2, $status, $statusActorId ), $this->page(), $action, $this->user( 9 ), '', $this->user( 1 ), 7
		);
		$this->assertSame( $hidden ? [ 2 ] : [], $this->hidden );
	}
}
