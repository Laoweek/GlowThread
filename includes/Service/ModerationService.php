<?php

namespace MediaWiki\Extension\GlowThread\Service;

use DatabaseLogEntry;
use LogEventsList;
use LogPage;
use MediaWiki\Api\ApiMessage;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\GlowThread\Cdn\CdnPurger;
use MediaWiki\Extension\GlowThread\HookRunner;
use MediaWiki\Extension\GlowThread\Log\LegacyLogType;
use MediaWiki\Extension\GlowThread\Log\ModerationLogger;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Model\PostStatus;
use MediaWiki\Extension\GlowThread\Model\ReportReason;
use MediaWiki\Extension\GlowThread\Policy\PageEligibility;
use MediaWiki\Extension\GlowThread\Policy\WriteGuard;
use MediaWiki\Extension\GlowThread\Store\PostStore;
use MediaWiki\Extension\GlowThread\Store\ReportStore;
use MediaWiki\Extension\GlowThread\Text\MentionResolver;
use MediaWiki\Permissions\Authority;
use MediaWiki\User\ActorNormalization;
use MediaWiki\User\ActorStore;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use StatusValue;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Moderation actions on comments, and authors deleting their own comments.
 *
 * Every change is logged in Special:Log (type glowthread) in the same transaction, purges the
 * cached list of the page when readers see a difference, and runs GlowThreadPostModerated
 * (and GlowThreadPostApproved for the comments an approval lets readers see) after the
 * response is sent.
 *
 * Restoring puts a deleted comment back to the status it had before the deletion, as its delete
 * log entry recorded it: a comment deleted while held is held again, not published.
 *
 * Erasing also hides the author as performer of the comment's earlier public log entries (an
 * author who deleted their own comment, or a moderator who acted on their own comment), so no
 * public entry ties the erased comment to its author. Each erased comment counts against the
 * glowthread-erase rate limit, which sysops cannot bypass.
 *
 * "undo" reverses one logged action exactly, from its log entry: the status before (a held
 * comment stays held with its hold reasons), the reports it resolved, the pin as it was. It
 * refuses when the comment changed since. Erasing cannot be undone.
 */
class ModerationService {

	public const ACTIONS = [ 'approve', 'delete', 'restore', 'erase', 'pin', 'unpin', 'dismiss', 'undo' ];
	/** Most comments one request may act on */
	public const MAX_IDS = 50;

	/** Log subtype for each action. FlowThread's names where FlowThread had the action. */
	private const LOG_ACTIONS = [
		'approve' => 'approve',
		'delete' => 'delete',
		'restore' => 'recover',
		'erase' => 'erase',
		'pin' => 'pin',
		'unpin' => 'unpin',
		'dismiss' => 'markchecked',
	];

	public function __construct(
		private readonly WriteGuard $guard,
		private readonly PostStore $postStore,
		private readonly ReportStore $reportStore,
		private readonly PageEligibility $pageEligibility,
		private readonly ActorNormalization $actorNormalization,
		private readonly ActorStore $actorStore,
		private readonly ModerationLogger $logger,
		private readonly CdnPurger $cdnPurger,
		private readonly HookRunner $hookRunner,
		private readonly MentionResolver $mentionResolver
	) {
	}

	/**
	 * Apply one action to several comments. Each comment succeeds or fails on its own.
	 *
	 * @param Authority $authority
	 * @param string $action One of ACTIONS
	 * @param int[] $ids
	 * @param string $reason
	 * @param int[] $logIds For undo: the log entry to undo for each comment, in the order of $ids
	 * @return StatusValue Value: list of [ 'id' => int, 'result' => 'success'|'nochange'|'error',
	 *   'status' => ?string (after), 'old' => ?string (before), 'logId' => ?int (the entry
	 *   written), 'code' => ?string ]
	 */
	public function moderate(
		Authority $authority,
		string $action,
		array $ids,
		string $reason,
		array $logIds = []
	): StatusValue {
		if ( !in_array( $action, self::ACTIONS, true ) ) {
			throw new \InvalidArgumentException( "Unknown moderation action $action" );
		}
		$status = $this->guard->checkModerate( $authority, 'glowthread-moderate' );
		if ( $status->isOK() && $action === 'erase' ) {
			$status = $this->guard->checkErase( $authority );
		}
		if ( !$status->isOK() ) {
			return $status;
		}
		$undoOf = [];
		if ( $action === 'undo' ) {
			$ids = array_map( 'intval', $ids );
			$logIds = array_map( 'intval', $logIds );
			if ( !$ids || count( $ids ) !== count( $logIds ) ) {
				return StatusValue::newFatal( ApiMessage::create( 'glowthread-error-undo-logids', 'badlogids' ) );
			}
			$ids = array_slice( $ids, 0, self::MAX_IDS );
			$undoOf = array_slice( $logIds, 0, self::MAX_IDS );
		} else {
			$ids = array_slice( array_values( array_unique( array_map( 'intval', $ids ) ) ), 0, self::MAX_IDS );
		}
		$dbw = $this->postStore->getPrimary();
		$performer = $authority->getUser();
		$actorId = $this->actorNormalization->acquireActorId( $performer, $dbw );
		$now = ConvertibleTimestamp::now( TS_MW );

		$results = [];
		$purge = [];
		$events = [];
		$eraseLimited = false;
		// Page ID => whether the moderator may act there. Comments of one page share the answer.
		$canModerate = [];
		foreach ( $ids as $index => $id ) {
			$dbw->startAtomic( __METHOD__, IDatabase::ATOMIC_CANCELABLE );
			$post = $this->postStore->getById( $id, true, true );
			if ( $post ) {
				$canModerate[$post->getPageId()] ??= $this->guard->canModerateOn( $authority, $post->getPageId() );
				// On a page the moderator may not read, the comment is answered as missing
				if ( !$canModerate[$post->getPageId()] ) {
					$post = null;
				}
			}
			if ( !$post ) {
				$outcome = [ 'error' => 'commentnotfound' ];
			} elseif ( $action === 'undo' ) {
				$outcome = $this->undo( $dbw, $undoOf[$index], $post, $actorId, $authority, $reason, $now );
			} elseif ( $action === 'erase' && $post->isDeleted()
				&& ( $eraseLimited || !$this->guard->takeEraseQuota( $authority ) )
			) {
				// Each erased comment counts. The ones beyond the limit stay deleted.
				$eraseLimited = true;
				$outcome = [ 'error' => 'ratelimited' ];
			} else {
				$outcome = $this->apply( $dbw, $action, $post, $actorId, $performer, $reason, $now );
			}
			if ( isset( $outcome['error'] ) ) {
				$dbw->cancelAtomic( __METHOD__ );
				$results[] = [
					'id' => $id,
					'result' => 'error',
					'status' => $post?->getStatus(),
					'old' => $post?->getStatus(),
					'logId' => null,
					'code' => $outcome['error'],
				];
				continue;
			}
			$dbw->endAtomic( __METHOD__ );
			// The comment as the action left it, read again only when the action logged nothing
			$after = $outcome['post'] ?? $this->postStore->getById( $id, true );
			$results[] = [
				'id' => $id,
				'result' => $outcome['changed'] ? 'success' : 'nochange',
				'status' => $after?->getStatus(),
				'old' => $post->getStatus(),
				'logId' => $outcome['changed'] ? $outcome['logId'] : null,
				'code' => null,
			];
			if ( $outcome['changed'] && $after ) {
				if ( $outcome['purge'] ) {
					$purge[$after->getPageId()] = true;
				}
				$events[] = [ $after, $outcome['logId'] ];
			}
		}

		foreach ( array_keys( $purge ) as $pageId ) {
			$this->cdnPurger->purgePage( $pageId );
		}
		$this->runHooksAfterCommit( $dbw, $events, $action, $performer, $reason );
		return StatusValue::newGood( $results );
	}

	/**
	 * The author deletes their own comment.
	 *
	 * @return StatusValue Value: the comment after deletion
	 */
	public function selfDelete( Authority $authority, int $postId, string $reason ): StatusValue {
		$post = $this->postStore->getById( $postId, true );
		$page = $post ? $this->pageEligibility->getPage( $post->getPageId() ) : null;
		if ( !$post || !$page ) {
			return StatusValue::newFatal( ApiMessage::create( 'glowthread-error-comment-missing', 'commentnotfound' ) );
		}
		$author = $authority->getUser();
		$dbw = $this->postStore->getPrimary();
		// Only accounts: anonymous users share IP actors with every legacy comment of that address
		$actorId = $author->isRegistered() ? $this->actorNormalization->findActorId( $author, $dbw ) : null;
		if ( !$actorId || $actorId !== $post->getActorId() ) {
			// Someone else's comment that the requester cannot see is answered as missing, so the
			// error does not confirm that a held, deleted or erased comment exists.
			$seen = $authority->isAllowed( 'glowthread-moderate' )
				|| $this->postStore->isPubliclyVisible( $post, true );
			return StatusValue::newFatal( $seen
				? ApiMessage::create( 'glowthread-error-not-author', 'notauthor' )
				: ApiMessage::create( 'glowthread-error-comment-missing', 'commentnotfound' )
			);
		}
		$status = $this->guard->checkWrite( $authority, 'glowthread-post', $page, true );
		if ( !$status->isOK() ) {
			return $status;
		}
		$now = ConvertibleTimestamp::now( TS_MW );
		$dbw->startAtomic( __METHOD__, IDatabase::ATOMIC_CANCELABLE );
		$locked = $this->postStore->getById( $postId, true, true );
		$before = $locked?->getStatus();
		$prev = $locked ? self::prevStatus( $locked ) : '';
		$wasVisible = $locked && $locked->isVisible();
		$changed = $locked && $this->postStore->changeStatus(
			$dbw, $postId, [ PostStatus::VISIBLE, PostStatus::HELD ], PostStatus::DELETED, $actorId, $now
		);
		if ( !$changed ) {
			$dbw->cancelAtomic( __METHOD__ );
			return StatusValue::newFatal( ApiMessage::create(
				[ 'glowthread-error-bad-status', 'delete', $locked ? $locked->getStatus() : '' ], 'badstatus'
			) );
		}
		if ( $wasVisible && $locked->getParentId() !== null ) {
			$this->postStore->adjustCounter( $dbw, $locked->getParentId(), 'gtp_reply_count', -1 );
		}
		$logId = $this->logger->logComment(
			$dbw, 'delete', $locked, $author->getName(), $author, $reason, false, 0,
			[ '7::old' => $before, '9::prev' => $prev ], [], $now
		);
		$dbw->endAtomic( __METHOD__ );

		if ( $wasVisible ) {
			$this->cdnPurger->purgePage( $post->getPageId() );
		}
		$after = $this->postStore->getById( $postId, true );
		$this->runHooksAfterCommit( $dbw, [ [ $after, $logId ] ], 'selfdelete', $author, $reason );
		return StatusValue::newGood( $after );
	}

	/**
	 * @return array{changed?:bool,purge?:bool,logId?:int,error?:string,post?:?PostRecord} post: the
	 *   comment after the change, when one was logged
	 */
	private function apply(
		IDatabase $dbw,
		string $action,
		PostRecord $post,
		int $actorId,
		UserIdentity $performer,
		string $reason,
		string $now
	): array {
		$id = $post->getId();
		$status = $post->getStatus();
		$purge = false;
		// What an exact undo needs besides the entry's time: for status changes who set the
		// status before and when, so an undo puts back statusBy and statusAt too
		$undoParams = in_array( $action, [ 'approve', 'delete', 'restore' ], true )
			? [ '9::prev' => self::prevStatus( $post ) ]
			: [];
		switch ( $action ) {
			case 'approve':
				if ( !$this->postStore->changeStatus(
					$dbw, $id, [ PostStatus::HELD ], PostStatus::VISIBLE, $actorId, $now
				) ) {
					return [ 'error' => 'badstatus' ];
				}
				$this->adjustParent( $dbw, $post, 1 );
				$undoParams += $this->resolveReports( $dbw, $id, $actorId, $now, ReportReason::OUTCOME_DISMISSED );
				$purge = true;
				break;
			case 'delete':
				if ( !$this->postStore->changeStatus(
					$dbw, $id, [ PostStatus::VISIBLE, PostStatus::HELD ], PostStatus::DELETED, $actorId, $now
				) ) {
					return [ 'error' => 'badstatus' ];
				}
				if ( $status === PostStatus::VISIBLE ) {
					$this->adjustParent( $dbw, $post, -1 );
					$purge = true;
				}
				$undoParams['7::old'] = $status;
				$undoParams += $this->resolveReports( $dbw, $id, $actorId, $now, ReportReason::OUTCOME_UPHELD );
				break;
			case 'restore':
				// Back to the status before the deletion: a comment deleted while it was held is
				// held again (hold reasons kept), not published. Visible when no deletion entry
				// says (FlowThread's deletions).
				$to = PostStatus::VISIBLE;
				$deletedAt = $post->getStatusTimestamp();
				if ( $post->isDeleted() && $deletedAt !== null ) {
					$to = $this->logger->getStatusesBeforeDeletion( $dbw, [ $id => $deletedAt ], true )[$id]
						?? PostStatus::VISIBLE;
				}
				if ( !$this->postStore->changeStatus(
					$dbw, $id, [ PostStatus::DELETED ], $to, $actorId, $now
				) ) {
					return [ 'error' => 'badstatus' ];
				}
				if ( $to === PostStatus::VISIBLE ) {
					$this->adjustParent( $dbw, $post, 1 );
					$purge = true;
				}
				// The status this restore set, so that an undo can tell it is still as it was
				$undoParams['7::new'] = $to;
				break;
			case 'erase':
				if ( !$this->postStore->changeStatus(
					$dbw, $id, [ PostStatus::DELETED ], PostStatus::ERASED, $actorId, $now,
					[ 'gtp_text' => '', 'gtp_pinned' => null ]
				) ) {
					return [ 'error' => 'badstatus' ];
				}
				$undoParams += $this->resolveReports( $dbw, $id, $actorId, $now, ReportReason::OUTCOME_UPHELD );
				// Report notes often quote the erased text
				$this->reportStore->clearNotes( $dbw, $id );
				break;
			case 'pin':
				if ( !$post->isTopLevel() ) {
					return [ 'error' => 'cannotpin' ];
				}
				if ( !$post->isVisible() ) {
					return [ 'error' => 'badstatus' ];
				}
				if ( !$this->postStore->setPinned( $dbw, $id, $now ) ) {
					return [ 'changed' => false ];
				}
				$purge = true;
				break;
			case 'unpin':
				if ( !$this->postStore->setPinned( $dbw, $id, null ) ) {
					return [ 'changed' => false ];
				}
				$purge = $post->isVisible();
				$undoParams['7::old'] = $post->getPinned();
				break;
			case 'dismiss':
				$undoParams = $this->resolveReports( $dbw, $id, $actorId, $now, ReportReason::OUTCOME_DISMISSED );
				if ( !$undoParams ) {
					return [ 'changed' => false ];
				}
				break;
		}

		$fresh = $this->postStore->getById( $id, true );
		$logPost = $fresh ?? $post;
		$author = $this->actorStore->getActorById( $post->getActorId(), $dbw );
		$authorName = $author ? $author->getName() : null;
		$logId = $this->logger->logComment(
			$dbw,
			self::LOG_ACTIONS[$action],
			$logPost,
			$action === 'erase' ? null : $authorName,
			$performer,
			$reason,
			false,
			// A moderator erasing their own comment: the public entry must not name them either
			$action === 'erase' && $actorId === $post->getActorId()
				? LogPage::DELETED_USER | LogPage::DELETED_COMMENT
				: 0,
			$undoParams,
			[],
			$now
		);
		if ( $action === 'erase' ) {
			// Who wrote an erased comment is recorded where only glowthread-erase holders can read it.
			$this->logger->logComment( $dbw, 'erase', $logPost, $authorName, $performer, $reason, true );
			$this->logger->hideAuthorAsPerformer( $dbw, $id, $post->getActorId() );
		}
		return [ 'changed' => true, 'purge' => $purge, 'logId' => $logId, 'post' => $fresh ];
	}

	/**
	 * Undo one logged action on a comment (locked by the caller), exactly.
	 *
	 * @return array{changed?:bool,purge?:bool,logId?:int,error?:string,post?:?PostRecord} post: the
	 *   comment after the change, when one was logged
	 */
	private function undo(
		IDatabase $dbw,
		int $logId,
		PostRecord $post,
		int $actorId,
		Authority $authority,
		string $reason,
		string $now
	): array {
		$row = DatabaseLogEntry::newSelectQueryBuilder( $dbw )
			->where( [ 'log_id' => $logId ] )
			->caller( __METHOD__ )
			->fetchRow();
		$entry = $row ? DatabaseLogEntry::newFromRow( $row ) : null;
		if ( !$entry || !in_array( $entry->getType(), [ ModerationLogger::TYPE, LegacyLogType::TYPE ], true )
			// An entry whose action is hidden from this moderator cannot be named again
			|| !LogEventsList::userCanBitfield( $entry->getDeleted(), LogPage::DELETED_ACTION, $authority )
		) {
			return [ 'error' => 'badlogid' ];
		}
		$params = $entry->getParameters();
		if ( $entry->getType() !== ModerationLogger::TYPE || !isset( $params['4::comment'] ) ) {
			// FlowThread's entries name no GlowThread comment
			return [ 'error' => 'cannotundo' ];
		}
		if ( (int)$params['4::comment'] !== $post->getId() ) {
			return [ 'error' => 'badlogid' ];
		}
		if ( $this->logger->isUndone( $dbw, $logId ) ) {
			return [ 'changed' => false ];
		}
		$id = $post->getId();
		$at = ConvertibleTimestamp::convert( TS_MW, $entry->getTimestamp() );
		$old = $params['7::old'] ?? null;
		$reportIds = array_filter( array_map( 'intval', explode( ',', (string)( $params['8::reports'] ?? '' ) ) ) );
		// Who set the status before and when
		$prev = self::parsePrevStatus( $params['9::prev'] ?? null );
		$subtype = $entry->getSubtype();
		if ( $prev === null && in_array( $subtype, [ 'approve', 'delete', 'recover' ], true ) ) {
			return [ 'error' => 'cannotundo' ];
		}
		// Each case checks that the comment is still as that entry left it. An undo puts back
		// the status time too, so several entries of one comment can be undone newest first.
		$unchanged = $post->getStatusTimestamp() === $at;
		$purge = false;
		switch ( $subtype ) {
			case 'approve':
				if ( !$post->isVisible() || !$unchanged ) {
					return [ 'error' => 'badstatus' ];
				}
				$this->postStore->changeStatus( $dbw, $id, [ PostStatus::VISIBLE ], PostStatus::HELD, ...$prev );
				$this->adjustParent( $dbw, $post, -1 );
				$this->reopenReports( $dbw, $id, $reportIds, $at, ReportReason::OUTCOME_DISMISSED );
				$purge = true;
				break;
			case 'delete':
				if ( !in_array( $old, [ PostStatus::VISIBLE, PostStatus::HELD ], true ) ) {
					return [ 'error' => 'cannotundo' ];
				}
				if ( !$post->isDeleted() || !$unchanged ) {
					return [ 'error' => 'badstatus' ];
				}
				$this->postStore->changeStatus( $dbw, $id, [ PostStatus::DELETED ], $old, ...$prev );
				if ( $old === PostStatus::VISIBLE ) {
					$this->adjustParent( $dbw, $post, 1 );
					$purge = true;
				}
				$this->reopenReports( $dbw, $id, $reportIds, $at, ReportReason::OUTCOME_UPHELD );
				break;
			case 'recover':
				// The status the restore set: held again for a comment deleted while held
				if ( $post->getStatus() !== ( $params['7::new'] ?? null ) || !$unchanged ) {
					return [ 'error' => 'badstatus' ];
				}
				$this->postStore->changeStatus( $dbw, $id, [ $post->getStatus() ], PostStatus::DELETED, ...$prev );
				if ( $post->isVisible() ) {
					$this->adjustParent( $dbw, $post, -1 );
					$purge = true;
				}
				break;
			case 'pin':
				if ( $post->getPinned() !== $at || !$this->postStore->setPinned( $dbw, $id, null ) ) {
					return [ 'error' => 'badstatus' ];
				}
				$purge = $post->isVisible();
				break;
			case 'unpin':
				if ( !is_string( $old ) || !preg_match( '/^\d{14}$/', $old ) ) {
					return [ 'error' => 'cannotundo' ];
				}
				// Only visible comments are pinned, as with pin. Pinned or unpinned again since (and
				// not undone): the pin time is no longer this one's
				if ( !$post->isVisible()
					|| $this->logger->hasLaterEntries( $dbw, $id, $logId, [ 'pin', 'unpin' ] )
					|| !$this->postStore->setPinned( $dbw, $id, $old )
				) {
					return [ 'error' => 'badstatus' ];
				}
				$purge = $post->isVisible();
				break;
			case 'markchecked':
				if ( !$reportIds ) {
					return [ 'error' => 'cannotundo' ];
				}
				// Not on a comment removed or otherwise changed since: its reports stay closed
				if ( $post->isDeleted() || $post->isErased()
					|| ( $post->getStatusTimestamp() !== null && $post->getStatusTimestamp() > $at )
					|| !$this->reopenReports( $dbw, $id, $reportIds, $at, ReportReason::OUTCOME_DISMISSED )
				) {
					return [ 'error' => 'badstatus' ];
				}
				break;
			default:
				// erase (irreversible), undo, and FlowThread's entries
				return [ 'error' => 'cannotundo' ];
		}
		$author = $this->actorStore->getActorById( $post->getActorId(), $dbw );
		$fresh = $this->postStore->getById( $id, true );
		$logPost = $fresh ?? $post;
		$newLogId = $this->logger->logComment(
			$dbw,
			'undo',
			$logPost,
			$author ? $author->getName() : null,
			$authority->getUser(),
			$reason,
			false,
			0,
			[ '7::undone' => $logId ],
			[ 'glowthread_undo' => (string)$logId ],
			$now
		);
		return [ 'changed' => true, 'purge' => $purge, 'logId' => $newLogId, 'post' => $fresh ];
	}

	/**
	 * Log parameter 9::prev of status changes: who set the status before and when.
	 */
	private static function prevStatus( PostRecord $post ): string {
		return ( $post->getStatusActorId() ?? '' ) . '|' . ( $post->getStatusTimestamp() ?? '' );
	}

	/**
	 * @param mixed $value Log parameter 9::prev
	 * @return array{0:?int,1:?string}|null Actor ID and TS_MW time, null when missing or malformed
	 */
	private static function parsePrevStatus( $value ): ?array {
		if ( !is_string( $value ) || !preg_match( '/^(\d*)\|(\d{14})?$/', $value, $m ) ) {
			return null;
		}
		return [ $m[1] === '' ? null : (int)$m[1], ( $m[2] ?? '' ) === '' ? null : $m[2] ];
	}

	/**
	 * Reopen the reports one action resolved (by their IDs, still resolved with that outcome at
	 * that time), and raise the open report counter again.
	 *
	 * @param IDatabase $dbw
	 * @param int $id
	 * @param int[] $reportIds
	 * @param string $at
	 * @param string $outcome
	 * @return int Reports reopened
	 */
	private function reopenReports( IDatabase $dbw, int $id, array $reportIds, string $at, string $outcome ): int {
		$reopened = $this->reportStore->reopenResolved( $dbw, $id, $reportIds, $at, $outcome );
		if ( $reopened ) {
			$this->postStore->adjustCounter( $dbw, $id, 'gtp_open_report_count', $reopened );
		}
		return $reopened;
	}

	/**
	 * Keep the display parent's count of visible children in step.
	 */
	private function adjustParent( IDatabase $dbw, PostRecord $post, int $delta ): void {
		if ( $post->getParentId() !== null ) {
			$this->postStore->adjustCounter( $dbw, $post->getParentId(), 'gtp_reply_count', $delta );
		}
	}

	/**
	 * Resolve open reports and lower the open report counter.
	 *
	 * @return array The log parameter naming the resolved reports, for an exact undo, or []
	 *   when there were none
	 */
	private function resolveReports( IDatabase $dbw, int $id, int $actorId, string $now, string $outcome ): array {
		$resolved = $this->reportStore->resolveOpen( $dbw, $id, $actorId, $now, $outcome );
		if ( !$resolved ) {
			return [];
		}
		$this->postStore->adjustCounter( $dbw, $id, 'gtp_open_report_count', -count( $resolved ) );
		return [ '8::reports' => implode( ',', $resolved ) ];
	}

	/**
	 * @param IDatabase $dbw
	 * @param array<int,array{0:PostRecord,1:int}> $events Comment after the change, log ID
	 * @param string $action
	 * @param UserIdentity $performer
	 * @param string $reason
	 */
	private function runHooksAfterCommit(
		IDatabase $dbw,
		array $events,
		string $action,
		UserIdentity $performer,
		string $reason
	): void {
		if ( !$events ) {
			return;
		}
		$hookRunner = $this->hookRunner;
		// After the response is sent (and only if the write commits): Echo and other handlers
		// do not delay the moderator, and run in the moderator's request. The hook arguments are
		// looked up there too, after the commit, so the comments' rows are not locked meanwhile.
		DeferredUpdates::addCallableUpdate(
			function () use ( $dbw, $events, $hookRunner, $action, $performer, $reason ) {
				$prepared = [];
				$shownIds = [];
				$pages = [];
				foreach ( $events as [ $post, $logId ] ) {
					$pageId = $post->getPageId();
					if ( !array_key_exists( $pageId, $pages ) ) {
						$pages[$pageId] = $this->pageEligibility->getPage( $pageId );
					}
					$page = $pages[$pageId];
					$author = $this->actorStore->getActorById( $post->getActorId(), $dbw )
						?? new UserIdentityValue( 0, '' );
					$approvals = [];
					if ( $action === 'approve' && $page ) {
						foreach ( $this->getShownByApproval( $post ) as $shown ) {
							// Each comment once, also when one batch approves a reply and the comment above it
							if ( !isset( $shownIds[$shown->getId()] ) ) {
								$shownIds[$shown->getId()] = true;
								$approvals[] = $this->describeApproval( $shown, $dbw );
							}
						}
					}
					$prepared[] = [ $post, $page, $author, $logId, $approvals ];
				}
				foreach ( $prepared as [ $post, $page, $author, $logId, $approvals ] ) {
					foreach ( $approvals as [ $shown, $shownAuthor, $mentioned, $replyTo, $replyToAuthor ] ) {
						$hookRunner->onGlowThreadPostApproved(
							$shown, $page, $shownAuthor, $mentioned, $replyTo, $replyToAuthor, $performer
						);
					}
					$hookRunner->onGlowThreadPostModerated(
						$post, $page, $action, $performer, $reason, $author, $logId
					);
				}
			},
			DeferredUpdates::POSTSEND,
			$dbw
		);
	}

	/**
	 * The comments an approval lets readers see, for GlowThreadPostApproved: the approved comment
	 * once readers can see it (not while a comment it is shown under is still held), and the
	 * replies shown under it that were held and approved before it, which readers see now too.
	 *
	 * @return PostRecord[]
	 */
	private function getShownByApproval( PostRecord $post ): array {
		if ( !$this->postStore->isPubliclyVisible( $post, true ) ) {
			return [];
		}
		$dbw = $this->postStore->getPrimary();
		$res = $this->postStore->newSelectQueryBuilder( $dbw )
			->where( [
				$post->isTopLevel() ? 'gtp_thread' : 'gtp_parent' => $post->getId(),
				'gtp_status' => PostStatus::VISIBLE,
				$dbw->expr( 'gtp_hold_reason', '!=', null ),
			] )
			->orderBy( 'gtp_id' )
			->caller( __METHOD__ )
			->fetchResultSet();
		$shown = [ $post ];
		foreach ( $res as $row ) {
			$reply = PostRecord::newFromRow( $row );
			if ( $reply->getHoldReasons() && $this->postStore->isPubliclyVisible( $reply, true ) ) {
				$shown[] = $reply;
			}
		}
		return $shown;
	}

	/**
	 * The arguments of GlowThreadPostApproved for a comment readers can now see: the comment, its
	 * author, its mentions and its reply target.
	 *
	 * @return array{0:PostRecord,1:UserIdentity,2:UserIdentity[],3:?PostRecord,4:?UserIdentity}
	 */
	private function describeApproval( PostRecord $post, IDatabase $dbw ): array {
		$author = $this->actorStore->getActorById( $post->getActorId(), $dbw ) ?? new UserIdentityValue( 0, '' );
		$mentioned = [];
		foreach ( $this->mentionResolver->getMentionedUsers( $post->getText() ) as $user ) {
			if ( !$user->equals( $author ) ) {
				$mentioned[] = $user;
			}
		}
		$replyTo = $post->getReplyToId() ? $this->postStore->getById( $post->getReplyToId(), true ) : null;
		$replyToAuthor = $replyTo ? $this->actorStore->getActorById( $replyTo->getActorId(), $dbw ) : null;
		return [ $post, $author, $mentioned, $replyTo, $replyToAuthor ];
	}
}
