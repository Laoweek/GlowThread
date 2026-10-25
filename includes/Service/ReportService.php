<?php

namespace MediaWiki\Extension\GlowThread\Service;

use MediaWiki\Api\ApiMessage;
use MediaWiki\Block\BlockManager;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\GlowThread\Cdn\CdnPurger;
use MediaWiki\Extension\GlowThread\HookRunner;
use MediaWiki\Extension\GlowThread\Log\ModerationLogger;
use MediaWiki\Extension\GlowThread\Model\HoldReason;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Model\PostStatus;
use MediaWiki\Extension\GlowThread\Policy\PageEligibility;
use MediaWiki\Extension\GlowThread\Policy\WriteGuard;
use MediaWiki\Extension\GlowThread\Settings\SettingsService;
use MediaWiki\Extension\GlowThread\Store\PostStore;
use MediaWiki\Extension\GlowThread\Store\ReportStore;
use MediaWiki\Extension\GlowThread\Text\TextProcessor;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Title\Title;
use MediaWiki\User\ActorNormalization;
use MediaWiki\User\ActorStore;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use StatusValue;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Reports. One report per user and comment. Enough distinct eligible reporters hold the comment.
 *
 * A reporter counts toward the automatic hold only if they are not the author, not blocked from
 * reporting on the page (sitewide, page, namespace or action blocks), and established
 * (WriteGuard::isEstablishedReporter: glowthread-trusted, or past the new-account gate with an
 * account a week old and a visible comment), so accounts registered ahead and never used cannot
 * hide a comment. Reports imported from FlowThread do not count until their reporter reports the
 * comment again. Pinned comments, comments by users with glowthread-moderate or
 * glowthread-trusted, and comments a moderator already kept (approved, restored, or with reports
 * dismissed) are never held automatically: their reports only go to the moderation queue. An
 * automatic hold is logged as glowthread/autohold.
 */
class ReportService {

	/** Longest report note, in code points */
	public const MAX_NOTE_LENGTH = 500;

	public function __construct(
		private readonly WriteGuard $guard,
		private readonly PostStore $postStore,
		private readonly ReportStore $reportStore,
		private readonly PageEligibility $pageEligibility,
		private readonly SettingsService $settings,
		private readonly TextProcessor $textProcessor,
		private readonly ActorNormalization $actorNormalization,
		private readonly ActorStore $actorStore,
		private readonly BlockManager $blockManager,
		private readonly CdnPurger $cdnPurger,
		private readonly HookRunner $hookRunner,
		private readonly UserFactory $userFactory,
		/** The GlowThread.ModerationLogger service when null */
		private readonly ?ModerationLogger $logger = null
	) {
	}

	/**
	 * @param Authority $authority
	 * @param int $postId
	 * @param string $reason One of the enabled reasons
	 * @param string $note Optional free text
	 * @return StatusValue Value: [ 'already' => bool, 'held' => bool ]
	 */
	public function report( Authority $authority, int $postId, string $reason, string $note ): StatusValue {
		$post = $this->postStore->getById( $postId, true );
		$page = $post ? $this->pageEligibility->getPage( $post->getPageId() ) : null;
		// A hidden comment is answered like a missing one, before the page checks, so that the
		// error does not tell whether it exists or what state its page is in.
		if ( !$post || !$page || !$this->postStore->isPubliclyVisible( $post, true ) ) {
			return StatusValue::newFatal( ApiMessage::create( 'glowthread-error-comment-missing', 'commentnotfound' ) );
		}
		$status = $this->guard->checkWrite( $authority, 'glowthread-report', $page, true );
		if ( !$status->isOK() ) {
			return $status;
		}
		if ( !in_array( $reason, $this->settings->get( 'reportReasons' ), true ) ) {
			return StatusValue::newFatal( ApiMessage::create( 'glowthread-error-report-reason', 'badreason' ) );
		}
		$note = $this->textProcessor->normalize( $note );
		if ( $this->textProcessor->length( $note ) > self::MAX_NOTE_LENGTH ) {
			return StatusValue::newFatal( ApiMessage::create(
				[ 'glowthread-error-note-too-long', self::MAX_NOTE_LENGTH ], 'notetoolong'
			) );
		}
		$dbw = $this->postStore->getPrimary();
		$reporter = $authority->getUser();
		$actorId = $this->actorNormalization->acquireActorId( $reporter, $dbw );
		if ( $actorId === $post->getActorId() ) {
			return StatusValue::newFatal( ApiMessage::create( 'glowthread-error-own-report', 'ownreport' ) );
		}

		$now = ConvertibleTimestamp::now( TS_MW );
		$held = false;
		$dbw->startAtomic( __METHOD__ );
		$storedNote = $note === '' ? null : $note;
		$added = $this->reportStore->add( $dbw, $postId, $actorId, $reason, $storedNote, $now );
		// Reporting again a comment reported in FlowThread files the report here: it now counts
		$filed = $added || $this->reportStore->replaceLegacy( $dbw, $postId, $actorId, $reason, $storedNote, $now );
		if ( $added ) {
			$this->postStore->adjustCounter( $dbw, $postId, 'gtp_open_report_count', 1 );
		}
		if ( $filed ) {
			$held = $this->maybeAutoHold( $dbw, $post, $page, $actorId, $now );
		}
		$dbw->endAtomic( __METHOD__ );

		if ( $held ) {
			$this->cdnPurger->purgePage( $post->getPageId() );
		}
		if ( $filed ) {
			$fresh = $this->postStore->getById( $postId, true ) ?? $post;
			$hookRunner = $this->hookRunner;
			DeferredUpdates::addCallableUpdate(
				static function () use ( $hookRunner, $fresh, $page, $reporter, $reason, $held ) {
					$hookRunner->onGlowThreadReportFiled( $fresh, $page, $reporter, $reason, $held );
				},
				DeferredUpdates::POSTSEND,
				$dbw
			);
		}
		return StatusValue::newGood( [ 'already' => !$filed, 'held' => $held ] );
	}

	/**
	 * Hold a visible comment once enough distinct eligible users reported it (see the class
	 * comment for who is eligible and which comments are never held this way).
	 */
	private function maybeAutoHold(
		IDatabase $dbw,
		PostRecord $post,
		ProperPageIdentity $page,
		int $newReporterActorId,
		string $now
	): bool {
		$threshold = (int)$this->settings->get( 'autoHoldReporters' );
		if ( $threshold <= 0 ) {
			return false;
		}
		// Read again, locked: a moderator's decision made since the comment was loaded counts
		$post = $this->postStore->getById( $post->getId(), true, true );
		if ( !$post || $post->isPinned() ) {
			return false;
		}
		// Reports and decisions committed while this request waited for the lock: this
		// transaction's snapshot may be older (MySQL). This report is not committed yet.
		$latest = $this->postStore->getPrimaryLatest();
		$reporters = array_diff(
			array_unique( array_merge(
				$this->reportStore->getOpenReporterActorIds( $latest, $post->getId() ), [ $newReporterActorId ]
			) ),
			[ $post->getActorId() ]
		);
		// Cheapest checks first
		if ( count( $reporters ) < $threshold || $this->isKeptByModerator( $post, $latest )
			|| $this->isTrustedAuthor( $post, $dbw )
		) {
			return false;
		}
		$title = Title::newFromPageIdentity( $page );
		$users = $this->loadRegisteredUsers( $reporters, $dbw );
		$eligible = 0;
		foreach ( $reporters as $reporterActorId ) {
			$reporter = $users[(int)$reporterActorId] ?? null;
			if ( $reporter && $this->isEligibleReporter( $reporter, (int)$reporterActorId, $title )
				&& ++$eligible >= $threshold
			) {
				break;
			}
		}
		if ( $eligible < $threshold ) {
			return false;
		}
		$reasons = array_unique( array_merge( $post->getHoldReasons(), [ HoldReason::REPORTS ] ) );
		$changed = $this->postStore->changeStatus(
			$dbw, $post->getId(), [ PostStatus::VISIBLE ], PostStatus::HELD, null, $now,
			[ 'gtp_hold_reason' => implode( ',', $reasons ) ]
		);
		if ( $changed && $post->getParentId() !== null ) {
			$this->postStore->adjustCounter( $dbw, $post->getParentId(), 'gtp_reply_count', -1 );
		}
		if ( $changed ) {
			$author = $this->actorStore->getActorById( $post->getActorId(), $dbw );
			$logger = $this->logger ?? MediaWikiServices::getInstance()->getService( 'GlowThread.ModerationLogger' );
			$logger->logAutoHold( $dbw, $post, $author?->getName(), $now );
		}
		return $changed;
	}

	/**
	 * Whether a moderator already kept the comment: approved or restored it (a visible comment
	 * whose status a moderator set), or dismissed its reports. Reports filed after that only go
	 * to the moderation queue, so the same comment cannot be hidden again after every approval.
	 */
	private function isKeptByModerator( PostRecord $post, IReadableDatabase $db ): bool {
		return ( $post->isVisible() && $post->getStatusActorId() !== null )
			|| $this->reportStore->hasDismissed( $db, $post->getId() );
	}

	/**
	 * Comments by moderators and trusted users are not held by reports.
	 */
	private function isTrustedAuthor( PostRecord $post, IDatabase $dbw ): bool {
		$author = $this->actorStore->getActorById( $post->getActorId(), $dbw );
		return $author !== null && $author->isRegistered()
			&& $this->userFactory->newFromUserIdentity( $author )
				->isAllowedAny( 'glowthread-moderate', 'glowthread-trusted' );
	}

	/**
	 * The registered users among these actors, with their user rows, loaded with one query.
	 *
	 * @param int[] $actorIds
	 * @param IDatabase $dbw
	 * @return array<int,User> Actor ID => user
	 */
	private function loadRegisteredUsers( array $actorIds, IDatabase $dbw ): array {
		$users = [];
		$res = User::newQueryBuilder( $dbw )
			->where( [ 'user_actor.actor_id' => array_values( array_map( 'intval', $actorIds ) ) ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		foreach ( $res as $row ) {
			$users[(int)$row->actor_id] = $this->userFactory->newFromRow( $row );
		}
		return $users;
	}

	/**
	 * A reporter who is established and not blocked from reporting on the page (sitewide, page,
	 * namespace and action blocks, as PermissionManager applies them). The block is looked up
	 * last: reporters who do not count are mostly new accounts, which fail the first check.
	 */
	private function isEligibleReporter( User $reporter, int $actorId, Title $title ): bool {
		if ( !$this->guard->isEstablishedReporter( $reporter, $actorId ) ) {
			return false;
		}
		$block = $this->blockManager->getBlock( $reporter, null );
		return !( $block && ( $block->appliesToTitle( $title ) || $block->appliesToRight( 'glowthread-report' ) ) );
	}
}
