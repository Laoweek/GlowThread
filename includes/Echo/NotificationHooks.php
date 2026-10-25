<?php

namespace MediaWiki\Extension\GlowThread\Echo;

use MediaWiki\Block\BlockManager;
use MediaWiki\Extension\GlowThread\Hook\GlowThreadPostApprovedHook;
use MediaWiki\Extension\GlowThread\Hook\GlowThreadPostModeratedHook;
use MediaWiki\Extension\GlowThread\Hook\GlowThreadPostSavedHook;
use MediaWiki\Extension\GlowThread\Model\HoldReason;
use MediaWiki\Extension\GlowThread\Model\PageState;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Settings\SettingsService;
use MediaWiki\Extension\GlowThread\Store\PageStateStore;
use MediaWiki\Extension\GlowThread\Store\PostStore;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use Wikimedia\WRStats\LimitCondition;
use Wikimedia\WRStats\LocalEntityKey;
use Wikimedia\WRStats\WRStatsRateLimiter;

/**
 * Sends Echo notifications for new comments and moderation outcomes.
 *
 * - glowthread-reply: to the author of the comment answered.
 * - glowthread-mention: to mentioned users (at most the mention cap), except whoever already
 *   gets the reply notification.
 * - glowthread-userpage: to the owner of the user page (User:Name, not its subpages) the comment
 *   is on, unless they already get the reply or a mention notification for it.
 * - glowthread-moderation: to the author when a moderator deletes, restores or approves their
 *   comment. A restore that makes the comment held again sends nothing.
 *
 * Nobody is notified about their own comment or action. A comment notifies only once readers can
 * see it: not while it or a comment it is shown under is held (approval sends what was held
 * back), not when comments are turned off on the page, and not again when a comment held by
 * reports or an imported FlowThread comment is approved, or when an approval is repeated after
 * an undo (a comment notifies once). A replier blocked from the page notifies nobody, and only
 * users who can read the page are notified. One author notifies one user at most
 * MAX_PER_RECIPIENT times an hour with comment notifications, the rest are dropped. Does
 * nothing when Echo is not loaded. Event extra data holds only ints and strings, because Echo
 * on 1.46 stores it as JSON.
 *
 * When a comment is erased or its author deletes it, the reply and mention notifications it
 * caused (and FlowThread's for an imported comment) are hidden with Echo's own moderation
 * (event_deleted), the way Echo hides notifications about deleted pages, so Echo's API stops
 * naming the comment's author as their agent (ErasedCommentEvents).
 */
class NotificationHooks implements
	GlowThreadPostSavedHook,
	GlowThreadPostApprovedHook,
	GlowThreadPostModeratedHook
{

	/** Extra key holding the recipients' user IDs, read by Echo's locator */
	public const RECIPIENTS = 'recipients';

	/** Moderation actions the author is told about */
	public const MODERATION_ACTIONS = [ 'delete', 'restore', 'approve' ];

	/** Longest moderation reason kept in the notification, in characters */
	private const MAX_REASON = 500;

	/** Most comment notifications from one author to one user in CAP_WINDOW seconds */
	public const MAX_PER_RECIPIENT = 3;
	private const CAP_WINDOW = 3600;

	/** @var callable|null Creates an Echo event from Event::create() info, null without Echo */
	private $createEvent;

	/** @var callable|null Hides the Echo events of a comment (ErasedCommentEvents), null without Echo */
	private $hideEvents;

	/** @var callable|null Whether a comment's notifications were sent, null without Echo */
	private $wasNotified;

	/** Counts comment notifications per author and recipient, null without Echo */
	private ?WRStatsRateLimiter $limiter;

	/**
	 * @param SettingsService $settings
	 * @param PostStore $postStore
	 * @param PageStateStore $pageStateStore
	 * @param UserFactory $userFactory
	 * @param BlockManager $blockManager
	 * @param TitleFactory $titleFactory
	 * @param callable|null $createEvent For tests: receives the Event::create() info array
	 * @param callable|null $hideEvents For tests: receives the erased or self-deleted PostRecord
	 * @param callable|null $wasNotified For tests: receives the PostRecord and its author, returns
	 *   whether the comment's notifications were sent
	 * @param WRStatsRateLimiter|null $limiter For tests: the per-recipient cap (condition 'notify')
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly PostStore $postStore,
		private readonly PageStateStore $pageStateStore,
		private readonly UserFactory $userFactory,
		private readonly BlockManager $blockManager,
		private readonly TitleFactory $titleFactory,
		?callable $createEvent = null,
		?callable $hideEvents = null,
		?callable $wasNotified = null,
		?WRStatsRateLimiter $limiter = null
	) {
		if ( $createEvent === null && ExtensionRegistry::getInstance()->isLoaded( 'Echo' ) ) {
			$createEvent = static function ( array $info ): void {
				Event::create( $info );
			};
			$hideEvents ??= static function ( PostRecord $post ): void {
				ErasedCommentEvents::hide( [ $post ], [ $post->getPageId() ] );
			};
			$wasNotified ??= static function ( PostRecord $post, UserIdentity $author ): bool {
				return ErasedCommentEvents::wereSent( $post, $author );
			};
			// Counted in the MicroStash, like core's rate limits
			$limiter ??= MediaWikiServices::getInstance()->getWRStatsFactory()->createRateLimiter(
				[ 'notify' => new LimitCondition( self::MAX_PER_RECIPIENT, self::CAP_WINDOW ) ],
				[ 'GlowThread', 'notify' ]
			);
		}
		$this->createEvent = $createEvent;
		$this->hideEvents = $hideEvents;
		$this->wasNotified = $wasNotified;
		$this->limiter = $limiter;
	}

	/** @inheritDoc */
	public function onGlowThreadPostSaved(
		PostRecord $post,
		ProperPageIdentity $page,
		UserIdentity $author,
		array $mentionedUsers,
		?PostRecord $replyTo,
		?UserIdentity $replyToAuthor
	): void {
		// Held comments wait for GlowThreadPostApproved
		if ( $post->isVisible() ) {
			$this->notifyNewComment( $post, $page, $author, $mentionedUsers, $replyTo, $replyToAuthor );
		}
	}

	/** @inheritDoc */
	public function onGlowThreadPostApproved(
		PostRecord $post,
		ProperPageIdentity $page,
		UserIdentity $author,
		array $mentionedUsers,
		?PostRecord $replyTo,
		?UserIdentity $replyToAuthor,
		UserIdentity $moderator
	): void {
		// A comment held by reports was visible before, and FlowThread already notified about
		// imported comments: their notifications went out once. So did those of a comment
		// approved again after an undo, or shown again with the comment above it.
		if ( in_array( HoldReason::REPORTS, $post->getHoldReasons(), true ) || $post->getLegacyId() !== null
			|| ( $this->wasNotified && ( $this->wasNotified )( $post, $author ) )
		) {
			return;
		}
		if ( $post->isVisible() ) {
			$this->notifyNewComment( $post, $page, $author, $mentionedUsers, $replyTo, $replyToAuthor );
		}
	}

	/** @inheritDoc */
	public function onGlowThreadPostModerated(
		PostRecord $post,
		?ProperPageIdentity $page,
		string $action,
		UserIdentity $performer,
		string $reason,
		UserIdentity $author,
		int $logId
	): void {
		// Erased, or deleted by its author: a mention or reply does not outlive the comment
		if ( $this->hideEvents && ErasedCommentEvents::appliesTo( $post )
			&& in_array( $action, [ 'erase', 'selfdelete', 'delete', 'undo' ], true )
		) {
			( $this->hideEvents )( $post );
		}
		// Erasing follows a deletion the author was already told about. No notice for the
		// author's own actions, for IP authors (legacy anonymous comments) or on deleted pages.
		// A restore that makes the comment held again (it was deleted while held) is not news
		// yet: its approval sends the notices.
		if ( !$this->createEvent
			|| !$page
			|| !in_array( $action, self::MODERATION_ACTIONS, true )
			|| ( $action === 'restore' && !$post->isVisible() )
			|| !$author->isRegistered()
			|| $author->equals( $performer )
			|| !$this->canRead( $author, $page )
		) {
			return;
		}
		// No agent: the notice does not name the moderator (Special:Log/glowthread does).
		( $this->createEvent )( [
			'type' => EchoSetupHooks::TYPE_MODERATION,
			'title' => $this->titleFactory->newFromPageIdentity( $page ),
			'extra' => [
				'comment-id' => $post->getId(),
				'action' => $action,
				'reason' => mb_substr( $reason, 0, self::MAX_REASON ),
				self::RECIPIENTS => [ $author->getId() ],
			],
		] );
	}

	/**
	 * @param PostRecord $post Visible comment
	 * @param ProperPageIdentity $page
	 * @param UserIdentity $author
	 * @param UserIdentity[] $mentionedUsers
	 * @param PostRecord|null $replyTo
	 * @param UserIdentity|null $replyToAuthor
	 */
	private function notifyNewComment(
		PostRecord $post,
		ProperPageIdentity $page,
		UserIdentity $author,
		array $mentionedUsers,
		?PostRecord $replyTo,
		?UserIdentity $replyToAuthor
	): void {
		$title = $this->titleFactory->newFromPageIdentity( $page );
		if ( !$this->createEvent
			|| !$author->isRegistered()
			|| !$this->isSeenByReaders( $post )
			|| $this->isBlockedFrom( $author, $title )
		) {
			return;
		}
		$agent = $this->userFactory->newFromUserIdentity( $author );
		$notified = [ $author->getId() => true ];

		if ( $replyTo && $replyToAuthor && $replyToAuthor->isRegistered()
			&& !isset( $notified[$replyToAuthor->getId()] )
			&& $this->canRead( $replyToAuthor, $page )
			&& $this->withinCap( $author, $replyToAuthor )
		) {
			$notified[$replyToAuthor->getId()] = true;
			( $this->createEvent )( [
				'type' => EchoSetupHooks::TYPE_REPLY,
				'title' => $title,
				'agent' => $agent,
				'extra' => [
					'comment-id' => $post->getId(),
					'reply-to-id' => $replyTo->getId(),
					self::RECIPIENTS => [ $replyToAuthor->getId() ],
				],
			] );
		}

		$recipients = [];
		foreach ( $this->capMentions( $mentionedUsers, $author ) as $user ) {
			if ( !isset( $notified[$user->getId()] ) && $this->canRead( $user, $page )
				&& $this->withinCap( $author, $user )
			) {
				$notified[$user->getId()] = true;
				$recipients[] = $user->getId();
			}
		}
		if ( $recipients ) {
			( $this->createEvent )( [
				'type' => EchoSetupHooks::TYPE_MENTION,
				'title' => $title,
				'agent' => $agent,
				'extra' => [
					'comment-id' => $post->getId(),
					self::RECIPIENTS => $recipients,
				],
			] );
		}

		$owner = $this->getUserPageOwner( $title );
		if ( $owner && !isset( $notified[$owner->getId()] ) && $this->canRead( $owner, $page )
			&& $this->withinCap( $author, $owner )
		) {
			( $this->createEvent )( [
				'type' => EchoSetupHooks::TYPE_USERPAGE,
				'title' => $title,
				'agent' => $agent,
				'extra' => [
					'comment-id' => $post->getId(),
					self::RECIPIENTS => [ $owner->getId() ],
				],
			] );
		}
	}

	/**
	 * The account whose user page this is: User:Name, not a subpage, not an IP address.
	 */
	private function getUserPageOwner( Title $title ): ?UserIdentity {
		if ( $title->getNamespace() !== NS_USER || $title->isSubpage() ) {
			return null;
		}
		$owner = $this->userFactory->newFromName( $title->getText() );
		return $owner && $owner->isRegistered() ? $owner : null;
	}

	/**
	 * Distinct registered mentioned users other than the author, at most the mention cap, in
	 * the order they were mentioned.
	 *
	 * @param UserIdentity[] $mentionedUsers
	 * @param UserIdentity $author
	 * @return UserIdentity[]
	 */
	private function capMentions( array $mentionedUsers, UserIdentity $author ): array {
		$unique = [];
		foreach ( $mentionedUsers as $user ) {
			if ( $user->isRegistered() && !$user->equals( $author ) ) {
				$unique[$user->getId()] ??= $user;
			}
		}
		return array_slice( array_values( $unique ), 0, max( 0, (int)$this->settings->get( 'maxMentions' ) ) );
	}

	/**
	 * Whether readers see the comment: it and the comments it is shown under are visible (an
	 * author may reply under their own held comment) and comments are not turned off on the page.
	 */
	private function isSeenByReaders( PostRecord $post ): bool {
		return $this->postStore->isPubliclyVisible( $post, true )
			&& $this->pageStateStore->getState( $post->getPageId(), true ) !== PageState::DISABLED;
	}

	/**
	 * Sitewide blocks, and partial blocks covering the page or its namespace.
	 */
	private function isBlockedFrom( UserIdentity $user, Title $title ): bool {
		$block = $this->blockManager->getBlock( $user, null );
		return $block !== null && $block->appliesToTitle( $title );
	}

	/**
	 * Whether the author may still notify this user (MAX_PER_RECIPIENT an hour). Counts the
	 * notification when they may, so check it last.
	 */
	private function withinCap( UserIdentity $author, UserIdentity $recipient ): bool {
		return !$this->limiter || $this->limiter->tryIncr(
			'notify', new LocalEntityKey( [ $author->getId(), $recipient->getId() ] )
		)->isAllowed();
	}

	private function canRead( UserIdentity $user, ProperPageIdentity $page ): bool {
		return $this->userFactory->newFromUserIdentity( $user )->definitelyCan( 'read', $page );
	}
}
