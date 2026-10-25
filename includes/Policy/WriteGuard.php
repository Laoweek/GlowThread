<?php

namespace MediaWiki\Extension\GlowThread\Policy;

use MediaWiki\Api\ApiMessage;
use MediaWiki\Extension\GlowThread\Filter\SpamChecker;
use MediaWiki\Extension\GlowThread\Filter\WordFilter;
use MediaWiki\Extension\GlowThread\GlowThreadConfig;
use MediaWiki\Extension\GlowThread\Model\HoldReason;
use MediaWiki\Extension\GlowThread\Model\PageState;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Model\PostStatus;
use MediaWiki\Extension\GlowThread\Query\ModerationQueryService;
use MediaWiki\Extension\GlowThread\Settings\SettingsService;
use MediaWiki\Extension\GlowThread\Store\PageStateStore;
use MediaWiki\Extension\GlowThread\Store\PostStore;
use MediaWiki\Extension\GlowThread\Text\MentionResolver;
use MediaWiki\Extension\GlowThread\Text\TextProcessor;
use MediaWiki\MediaWikiServices;
use MediaWiki\Message\Message;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageStore;
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\User\ActorNormalization;
use MediaWiki\User\Registration\UserRegistrationLookup;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use Psr\Log\LoggerInterface;
use StatusValue;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ReadOnlyMode;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * The checks every write goes through, in this order:
 *
 * 1. read access to the page, then rights and blocks: Authority::authorizeWrite() on the
 *    target page, so partial blocks apply only to the pages they cover. Moderation uses
 *    authorizeAction() without a page, so a moderator under any block cannot moderate.
 * 2. read-only mode
 * 3. page eligibility and page state (locked, disabled)
 * 4. rate limits ($wgRateLimits keys named after the right; authorizeWrite counts them, and
 *    noratelimit is honoured except where the key sets &can-bypass false). A tripped limit is
 *    the error ratelimited with data retryAfter (RateLimitErrors).
 * 5. content: raw size, length cap, mention cap, duplicate text, parent comment
 * 6. holds, which never reject: word filter, SpamBlacklist, new-account gate, a held parent.
 *    Only the cap on comments waiting for review rejects (checkHeldLimit).
 *
 * Errors are ApiMessage instances with stable codes, listed in docs/api.md.
 */
class WriteGuard {

	/**
	 * Bytes of raw input allowed beyond 4 bytes per code point of the length cap, for characters
	 * that normalisation removes (invisible characters, trailing spaces, extra blank lines)
	 */
	public const RAW_SLACK_BYTES = 4096;

	/** Rate-limit key counting erased comments, one per comment. Sysops cannot bypass it. */
	public const ERASE_LIMIT = 'glowthread-erase';

	/**
	 * Beyond the new-account gate, a reporter counts toward an automatic hold only with an
	 * account this many days old and this many visible comments. Fixed on purpose, not
	 * settings: the gate alone let accounts registered a day ahead and never used hide comments.
	 */
	public const REPORTER_MIN_DAYS = 7;
	public const REPORTER_MIN_VISIBLE = 1;

	public function __construct(
		private readonly PageEligibility $pageEligibility,
		private readonly PageStateStore $pageStateStore,
		private readonly ReadOnlyMode $readOnlyMode,
		private readonly SettingsService $settings,
		private readonly TextProcessor $textProcessor,
		private readonly MentionResolver $mentionResolver,
		private readonly PostStore $postStore,
		private readonly WordFilter $wordFilter,
		private readonly SpamChecker $spamChecker,
		private readonly UserFactory $userFactory,
		private readonly UserRegistrationLookup $userRegistrationLookup,
		private readonly ActorNormalization $actorNormalization,
		private readonly GlowThreadConfig $config,
		private readonly RateLimitErrors $rateLimitErrors,
		private readonly LoggerInterface $logger,
		/** Core's PageStore service when null */
		private readonly ?PageStore $pageStore = null
	) {
	}

	/**
	 * Steps 1 to 4 for a reader write on a page (post, react, report, self-delete).
	 *
	 * @param Authority $authority
	 * @param string $right glowthread-post, glowthread-react or glowthread-report
	 * @param PageIdentity $page
	 * @param bool $allowLocked Whether the write is allowed on locked pages (likes, reports, self-delete)
	 */
	public function checkWrite(
		Authority $authority,
		string $right,
		PageIdentity $page,
		bool $allowLocked
	): StatusValue {
		// Writing on a page needs reading it: PermissionManager checks read access only for the read action
		$read = PermissionStatus::newEmpty();
		if ( !$authority->authorizeRead( 'read', $page, $read ) ) {
			return $read;
		}
		$permission = PermissionStatus::newEmpty();
		$authority->authorizeWrite( $right, $page, $permission );
		$denied = $this->withoutRateLimit( $permission );
		if ( $denied ) {
			return $denied;
		}
		$common = $this->checkReadOnly();
		if ( !$common->isOK() ) {
			return $common;
		}
		$reason = $this->pageEligibility->getIneligibleReason( $page );
		if ( $reason !== null ) {
			$missing = $reason === PageEligibility::REASON_MISSING;
			return StatusValue::newFatal( ApiMessage::create(
				$missing ? 'glowthread-error-page-missing' : 'glowthread-error-page-ineligible',
				$missing ? 'pagenotfound' : 'pageineligible'
			) );
		}
		$state = $this->pageStateStore->getState( $page->getId(), true );
		if ( $state === PageState::DISABLED ) {
			return StatusValue::newFatal( ApiMessage::create( 'glowthread-error-disabled', 'commentsdisabled' ) );
		}
		if ( $state === PageState::LOCKED && !$allowLocked ) {
			return StatusValue::newFatal( ApiMessage::create( 'glowthread-error-locked', 'commentslocked' ) );
		}
		if ( $permission->isRateLimitExceeded() ) {
			return $this->rateLimitErrors->newStatus( $right, $authority );
		}
		return StatusValue::newGood();
	}

	/**
	 * Steps 1, 2 and 4 for moderation and configuration. Any block, also a partial one, stops it.
	 *
	 * @param Authority $authority
	 * @param string $right glowthread-moderate, glowthread-erase or glowthread-configure
	 */
	public function checkModerate( Authority $authority, string $right ): StatusValue {
		$permission = PermissionStatus::newEmpty();
		$authority->authorizeAction( $right, $permission );
		$denied = $this->withoutRateLimit( $permission );
		if ( $denied ) {
			return $denied;
		}
		$common = $this->checkReadOnly();
		if ( !$common->isOK() ) {
			return $common;
		}
		if ( $permission->isRateLimitExceeded() ) {
			return $this->rateLimitErrors->newStatus( $right, $authority );
		}
		return StatusValue::newGood();
	}

	/**
	 * Whether a moderator may act on the comments of a page: the rule of the moderation routes
	 * (ModerationQueryService::canSeePages()). They may read the page, or for a deleted page, its
	 * last deletion was not a suppression hidden from them. Otherwise moderation answers as for
	 * a missing comment, so that it does not tell that the comment exists.
	 */
	public function canModerateOn( Authority $authority, int $pageId ): bool {
		return ModerationQueryService::canSeePages(
			$this->pageStore ?? MediaWikiServices::getInstance()->getPageStore(),
			$this->postStore->getPrimary(),
			$authority,
			[ $pageId ]
		)[$pageId] ?? false;
	}

	/**
	 * glowthread-erase, a block of any kind and read-only mode, after checkModerate() for
	 * glowthread-moderate. The erase limit is only looked at here (an exhausted limit stops the
	 * request). takeEraseQuota() counts each comment.
	 */
	public function checkErase( Authority $authority ): StatusValue {
		$permission = PermissionStatus::newEmpty();
		$authority->isDefinitelyAllowed( 'glowthread-erase', $permission );
		$denied = $this->withoutRateLimit( $permission );
		if ( $denied ) {
			return $denied;
		}
		$common = $this->checkReadOnly();
		if ( !$common->isOK() ) {
			return $common;
		}
		if ( $permission->isRateLimitExceeded() ) {
			$this->logEraseLimit( $authority );
			return $this->rateLimitErrors->newStatus( self::ERASE_LIMIT, $authority );
		}
		return StatusValue::newGood();
	}

	/**
	 * Count one erased comment against the glowthread-erase limit, which noratelimit does not
	 * bypass. A fresh User object per call (newFromId, which is never cached), because a user's
	 * own Authority counts an action at most once per request outside of tests.
	 *
	 * @return bool False when the limit is reached (nothing was counted)
	 */
	public function takeEraseQuota( Authority $authority ): bool {
		// Only accounts get this far: erasing needs glowthread-erase
		$user = $this->userFactory->newFromId( $authority->getUser()->getId() );
		if ( $user->pingLimiter( self::ERASE_LIMIT ) ) {
			$this->logEraseLimit( $authority );
			return false;
		}
		return true;
	}

	private function logEraseLimit( Authority $authority ): void {
		$this->logger->warning( 'Erase limit reached by {user}', [
			'user' => $authority->getUser()->getName(),
			'limit' => self::ERASE_LIMIT,
		] );
	}

	/**
	 * The ratelimited error with retryAfter, for limits the caller counts itself.
	 */
	public function newRateLimitedStatus( string $action, Authority $authority ): StatusValue {
		return $this->rateLimitErrors->newStatus( $action, $authority );
	}

	/**
	 * Whether the viewer may use a right on a page, for the UI hints of the list. Rights, blocks
	 * (partial ones only on the pages they cover) and page restrictions count. The rate limit is
	 * neither counted nor taken into account: being throttled for a minute does not mean the
	 * viewer lacks the right, and the write module reports the wait when it happens.
	 */
	public function isAllowedIgnoringRateLimit( Authority $authority, string $right, PageIdentity $page ): bool {
		$permission = PermissionStatus::newEmpty();
		if ( $authority->definitelyCan( $right, $page, $permission ) ) {
			return true;
		}
		return $permission->isRateLimitExceeded() && $this->withoutRateLimit( $permission ) === null;
	}

	public function checkReadOnly(): StatusValue {
		if ( $this->readOnlyMode->isReadOnly() ) {
			return StatusValue::newFatal( ApiMessage::create(
				'apierror-readonly', 'readonly', [ 'readonlyreason' => $this->readOnlyMode->getReason() ]
			) );
		}
		return StatusValue::newGood();
	}

	/**
	 * Cheap size check on the raw input, before it is normalised: at most 4 bytes per code point
	 * of the length cap, plus RAW_SLACK_BYTES for characters that normalisation removes.
	 */
	public function checkRawSize( string $raw ): StatusValue {
		$maxLength = (int)$this->settings->get( 'maxLength' );
		if ( strlen( $raw ) > 4 * $maxLength + self::RAW_SLACK_BYTES ) {
			return StatusValue::newFatal( ApiMessage::create(
				[ 'glowthread-error-too-long', Message::numParam( $maxLength ) ], 'toolong', [ 'max' => $maxLength ]
			) );
		}
		return StatusValue::newGood();
	}

	/**
	 * A comment that would be held is refused while its author already has
	 * $wgGlowThreadMaxHeldPerUser comments waiting for review.
	 *
	 * Call it in the atomic section that inserts the comment. It locks the author until the
	 * transaction ends and counts what other transactions committed, so posts sent at the
	 * same moment cannot all pass the cap.
	 */
	public function checkHeldLimit( IDatabase $dbw, int $actorId ): StatusValue {
		$max = $this->config->getMaxHeldPerUser();
		if ( $max <= 0 ) {
			return StatusValue::newGood();
		}
		$this->postStore->lockActor( $dbw, $actorId );
		if ( $this->postStore->countByActor(
			$this->postStore->getPrimaryLatest(), $actorId, [ PostStatus::HELD ], $max
		) >= $max ) {
			return StatusValue::newFatal( ApiMessage::create(
				[ 'glowthread-error-too-many-held', Message::numParam( $max ) ], 'toomanyheld', [ 'max' => $max ]
			) );
		}
		return StatusValue::newGood();
	}

	/**
	 * Step 5 for a new comment.
	 *
	 * @param Authority $authority
	 * @param PageIdentity $page
	 * @param string $text Normalised text
	 * @param PostRecord|null $replyTo The comment being replied to, loaded from the primary
	 * @return StatusValue On success the value is the list of mentioned UserIdentity objects
	 */
	public function checkContent(
		Authority $authority,
		PageIdentity $page,
		string $text,
		?PostRecord $replyTo
	): StatusValue {
		if ( $text === '' ) {
			return StatusValue::newFatal( ApiMessage::create( 'glowthread-error-empty', 'emptycomment' ) );
		}
		$maxLength = (int)$this->settings->get( 'maxLength' );
		if ( $this->textProcessor->length( $text ) > $maxLength ) {
			return StatusValue::newFatal( ApiMessage::create(
				[ 'glowthread-error-too-long', Message::numParam( $maxLength ) ], 'toolong', [ 'max' => $maxLength ]
			) );
		}

		$viewer = $authority->getUser();
		$mentioned = [];
		foreach ( $this->mentionResolver->getMentionedUsers( $text ) as $user ) {
			if ( !$user->equals( $viewer ) ) {
				$mentioned[] = $user;
			}
		}
		$maxMentions = (int)$this->settings->get( 'maxMentions' );
		if ( count( $mentioned ) > $maxMentions ) {
			return StatusValue::newFatal( ApiMessage::create(
				[ 'glowthread-error-too-many-mentions', Message::numParam( $maxMentions ) ],
				'toomanymentions',
				[ 'max' => $maxMentions ]
			) );
		}

		$actorId = $this->actorNormalization->findActorId( $viewer, $this->postStore->getPrimary() );
		$window = (int)$this->settings->get( 'duplicateWindow' );
		if ( $actorId && $window > 0 ) {
			$since = ConvertibleTimestamp::convert( TS_MW, ConvertibleTimestamp::time() - $window );
			$recent = $this->postStore->getRecentByActorOnPage(
				$this->postStore->getPrimary(), $actorId, $page->getId(), $since
			);
			$key = $this->textProcessor->comparisonKey( $text );
			foreach ( $recent as $post ) {
				if ( $this->textProcessor->comparisonKey( $post->getText() ) === $key ) {
					return StatusValue::newFatal( ApiMessage::create( 'glowthread-error-duplicate', 'duplicate' ) );
				}
			}
		}

		if ( $replyTo !== null ) {
			$parentStatus = $this->checkReplyTarget( $replyTo, $page, (int)$actorId );
			if ( !$parentStatus->isOK() ) {
				return $parentStatus;
			}
		}
		return StatusValue::newGood( $mentioned );
	}

	/**
	 * A reply target must be on the same page, and it and every comment it would be shown
	 * under must be visible, or held and written by the replier.
	 */
	private function checkReplyTarget( PostRecord $replyTo, PageIdentity $page, int $actorId ): StatusValue {
		$bad = StatusValue::newFatal( ApiMessage::create( 'glowthread-error-parent', 'badparent' ) );
		if ( $replyTo->getPageId() !== $page->getId() ) {
			return $bad;
		}
		$chain = [ $replyTo ];
		$ancestorIds = array_filter( [ $replyTo->getParentId(), $replyTo->getThreadId() ] );
		$ancestors = $this->postStore->getByIds( $ancestorIds, true );
		if ( count( $ancestors ) !== count( array_unique( $ancestorIds ) ) ) {
			return $bad;
		}
		foreach ( array_merge( $chain, array_values( $ancestors ) ) as $post ) {
			$usable = $post->getStatus() === PostStatus::VISIBLE
				|| ( $post->getStatus() === PostStatus::HELD && $actorId && $post->getActorId() === $actorId );
			if ( !$usable || $post->getPageId() !== $page->getId() ) {
				return $bad;
			}
		}
		return StatusValue::newGood();
	}

	/**
	 * Step 6: reasons to hold the comment for review. Never rejects.
	 *
	 * @param Authority $authority
	 * @param string $text Normalised text
	 * @param int|null $actorId Author's actor ID if known
	 * @param array[]|null &$filterMatches Set to the word filter rules that matched
	 *  ([ 'line', 'rule', 'type' ] each), empty when none did
	 * @return string[] HoldReason values, empty when the comment can be shown at once
	 */
	public function getHoldReasons(
		Authority $authority, string $text, ?int $actorId, ?array &$filterMatches = null
	): array {
		$reasons = [];
		$filter = $this->wordFilter->check( $text );
		$filterMatches = $filter['matches'];
		if ( $filter['matched'] ) {
			$reasons[] = HoldReason::FILTER;
		}
		if ( $filter['error'] ) {
			$reasons[] = HoldReason::FILTER_ERROR;
		}
		$user = $this->userFactory->newFromUserIdentity( $authority->getUser() );
		if ( $this->spamChecker->getMatches( $this->textProcessor->extractUrls( $text ), $user ) ) {
			$reasons[] = HoldReason::SPAM_BLACKLIST;
		}
		if ( $this->isGatedNewAccount( $authority, $actorId ) ) {
			$reasons[] = HoldReason::NEW_ACCOUNT;
		}
		return $reasons;
	}

	/**
	 * New-account gate: hold when the account is younger than newAccountHours AND has fewer
	 * than newAccountApproved visible comments. glowthread-trusted skips the gate.
	 */
	public function isGatedNewAccount( Authority $authority, ?int $actorId ): bool {
		if ( $authority->isAllowed( 'glowthread-trusted' ) ) {
			return false;
		}
		$hours = (int)$this->settings->get( 'newAccountHours' );
		$approved = (int)$this->settings->get( 'newAccountApproved' );
		if ( $hours <= 0 || $approved <= 0 ) {
			return false;
		}
		$registration = $this->userRegistrationLookup->getRegistration( $authority->getUser() );
		if ( !$registration ) {
			// Accounts from before registration dates were recorded are old
			return false;
		}
		$age = ConvertibleTimestamp::time() - (int)ConvertibleTimestamp::convert( TS_UNIX, $registration );
		if ( $age >= $hours * 3600 ) {
			return false;
		}
		if ( !$actorId ) {
			return true;
		}
		$count = $this->postStore->countByActor(
			$this->postStore->getPrimary(), $actorId, [ PostStatus::VISIBLE ], $approved
		);
		return $count < $approved;
	}

	/**
	 * Whether a user's reports count toward an automatic hold (ReportService): glowthread-trusted,
	 * or past the new-account gate with an account at least REPORTER_MIN_DAYS old (or from before
	 * registration dates were recorded) and at least REPORTER_MIN_VISIBLE visible comment.
	 * Never a temporary account: anyone gets a new one by clearing their cookies.
	 */
	public function isEstablishedReporter( Authority $authority, int $actorId ): bool {
		if ( $authority->isTemp() ) {
			return false;
		}
		if ( $authority->isAllowed( 'glowthread-trusted' ) ) {
			return true;
		}
		// Before the new-account gate, which may count comments: either one alone rules a
		// younger account out
		$registration = $this->userRegistrationLookup->getRegistration( $authority->getUser() );
		if ( $registration && ConvertibleTimestamp::time()
			- (int)ConvertibleTimestamp::convert( TS_UNIX, $registration ) < self::REPORTER_MIN_DAYS * 86400
		) {
			return false;
		}
		if ( $this->isGatedNewAccount( $authority, $actorId ) ) {
			return false;
		}
		return $this->postStore->countByActor(
			$this->postStore->getPrimary(), $actorId, [ PostStatus::VISIBLE ], self::REPORTER_MIN_VISIBLE
		) >= self::REPORTER_MIN_VISIBLE;
	}

	/**
	 * The status without the rate-limit part, or null if nothing else is wrong.
	 */
	private function withoutRateLimit( PermissionStatus $permission ): ?StatusValue {
		if ( $permission->isOK() || !$permission->hasMessagesExcept( 'actionthrottledtext' ) ) {
			return null;
		}
		$denied = PermissionStatus::newEmpty();
		foreach ( $permission->getMessages() as $message ) {
			if ( $message->getKey() !== 'actionthrottledtext' ) {
				$denied->fatal( $message );
			}
		}
		$block = $permission->getBlock();
		if ( $block ) {
			$denied->setBlock( $block );
		}
		if ( $permission->getPermission() !== null ) {
			$denied->setPermission( $permission->getPermission() );
		}
		return $denied;
	}

	/**
	 * Whether a user is the author of a comment.
	 */
	public function isAuthor( UserIdentity $user, PostRecord $post ): bool {
		$actorId = $this->actorNormalization->findActorId( $user, $this->postStore->getReplica() );
		return $actorId !== null && $actorId === $post->getActorId();
	}
}
