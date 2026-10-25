<?php

namespace MediaWiki\Extension\GlowThread\Log;

use LogEntryBase;
use LogPage;
use ManualLogEntry;
use MapCacheLRU;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Model\PostStatus;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageReference;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Writes Special:Log entries of type glowthread and the restricted glowthread-private type.
 * (FlowThread's old entries are of type comments, see LegacyLogType.)
 *
 * Comment entries target the page and carry the parameters
 * 4::comment (ID), 5:user-link:author (author name, left out where it must stay hidden) and
 * 6::seq (floor number), plus what an exact undo needs: 7::old (the status before a delete, which
 * a restore goes back to, the pin time before an unpin), 7::new (on recover entries, the status
 * the restore set) and, on glowthread/undo entries, 7::undone (the log ID undone). They are
 * searchable through log_search relations glowthread_post (comment ID), glowthread_author (author
 * actor ID, left out where the author is) and glowthread_undo (on undo entries, the log ID
 * undone). Entries of type glowthread go to RecentChanges while the publicLog setting is on,
 * although the type is restricted (LogVisibilityHooks decides who sees them), and CheckUser
 * records them either way (publish()). Automatic holds carry the IP address 127.0.0.1 there, not
 * the request's (SoftwareLogEntry).
 *
 * An action on many comments costs no query per entry for the target page (remembered per page
 * for a few seconds) or for publishing: LogAuthorLookup is told about each comment logged, so
 * formatting the entry for RecentChanges does not read the comment again.
 */
class ModerationLogger {

	public const TYPE = 'glowthread';
	public const PRIVATE_TYPE = 'glowthread-private';

	/** System user named as the performer of automatic holds */
	public const SYSTEM_USER = 'GlowThread';

	/** Seconds a target page is reused, so long-running scripts see moves and deletions soon */
	private const TARGET_TTL = 5;

	/** @var MapCacheLRU Page ID => log target */
	private MapCacheLRU $targets;

	public function __construct(
		private readonly TitleFactory $titleFactory,
		/** The GlowThread.LogAuthorLookup service when null */
		private readonly ?LogAuthorLookup $authorLookup = null
	) {
		$this->targets = new MapCacheLRU( 100 );
	}

	/**
	 * Log an action on one comment.
	 *
	 * @param IDatabase $dbw Write in the caller's transaction
	 * @param string $action Log subtype (delete, recover, erase, approve, markchecked, pin, unpin,
	 *   autohold)
	 * @param PostRecord $post
	 * @param string|null $authorName Null to leave the author out
	 * @param UserIdentity $performer
	 * @param string $reason
	 * @param bool $private Write to the restricted glowthread-private log instead
	 * @param int $deleted LogPage::DELETED_* bits to set on the new entry
	 * @param array $extraParams More parameters (7::old, 7::undone)
	 * @param array $extraRelations More log_search relations (glowthread_undo)
	 * @param string|null $timestamp TS_MW time of the entry, the time of the change it records
	 * @return int Log ID
	 */
	public function logComment(
		IDatabase $dbw,
		string $action,
		PostRecord $post,
		?string $authorName,
		UserIdentity $performer,
		string $reason,
		bool $private = false,
		int $deleted = 0,
		array $extraParams = [],
		array $extraRelations = [],
		?string $timestamp = null
	): int {
		$type = $private ? self::PRIVATE_TYPE : self::TYPE;
		// An automatic hold is the software's, not the action of the request's user
		$entry = $action === 'autohold' ? new SoftwareLogEntry( $type, $action ) : new ManualLogEntry( $type, $action );
		$entry->setPerformer( $performer );
		$entry->setDeleted( $deleted );
		if ( $timestamp !== null ) {
			$entry->setTimestamp( $timestamp );
		}
		$entry->setTarget( $this->getTarget( $post->getPageId() ) );
		$entry->setComment( $reason );
		$params = [ '4::comment' => $post->getId() ];
		if ( $authorName !== null ) {
			$params['5:user-link:author'] = $authorName;
		}
		$params['6::seq'] = $post->getSeq();
		$entry->setParameters( $params + $extraParams );
		$relations = [ 'glowthread_post' => (string)$post->getId() ];
		if ( $authorName !== null ) {
			// Not on the public erase entry, so the erased comment cannot be tied to its author
			$relations['glowthread_author'] = (string)$post->getActorId();
		}
		$entry->setRelations( $relations + $extraRelations );
		$logId = $entry->insert( $dbw );
		// Publishing formats the entry, which asks whether the comment is erased and its author hidden
		( $this->authorLookup ?? MediaWikiServices::getInstance()->getService( 'GlowThread.LogAuthorLookup' ) )
			->rememberComment( $post, $authorName );
		$this->publish( $entry, $logId );
		return $logId;
	}

	/**
	 * Log an automatic hold by reports (glowthread/autohold). The performer is the system user
	 * SYSTEM_USER, never a reporter: who reported the comment is for moderators, in the queue.
	 *
	 * The name is reserved (ReservedNamesHooks). An account that has it anyway, registered
	 * before GlowThread was installed, belongs to a person: it is never taken over, and the
	 * hold is logged as core's maintenance user instead. (Like any account with a reserved
	 * name, it cannot log in until it is renamed.)
	 *
	 * @param IDatabase $dbw Write in the caller's transaction
	 * @param PostRecord $post
	 * @param string|null $authorName
	 * @param string $timestamp TS_MW time of the hold
	 * @return int Log ID
	 */
	public function logAutoHold( IDatabase $dbw, PostRecord $post, ?string $authorName, string $timestamp ): int {
		return $this->logComment(
			$dbw, 'autohold', $post, $authorName, self::getAutoHoldPerformer(), '', false, 0, [], [], $timestamp
		);
	}

	/**
	 * The performer of automatic holds: the system user SYSTEM_USER, or core's maintenance user
	 * when a person's account has that name (see logAutoHold()). Created when missing.
	 * update.php creates it ahead of time (SchemaHooks::createSystemUser()), so that two first
	 * holds at the same moment do not both create it.
	 */
	public static function getAutoHoldPerformer(): User {
		return User::newSystemUser( self::SYSTEM_USER )
			?? User::newSystemUser( User::MAINTENANCE_SCRIPT_USER, [ 'steal' => true ] );
	}

	/**
	 * Whether a glowthread log entry was already undone (a glowthread/undo entry names it).
	 */
	public function isUndone( IReadableDatabase $db, int $logId ): bool {
		return (bool)$db->newSelectQueryBuilder()
			->select( '1' )
			->from( 'log_search' )
			->join( 'logging', null, 'log_id = ls_log_id' )
			->where( [
				'ls_field' => 'glowthread_undo',
				'ls_value' => (string)$logId,
				'log_type' => self::TYPE,
				'log_action' => 'undo',
			] )
			->caller( __METHOD__ )
			->fetchField();
	}

	/**
	 * Whether the comment has entries of these actions after $logId that were not undone.
	 *
	 * @param IReadableDatabase $db
	 * @param int $commentId
	 * @param int $logId
	 * @param string[] $actions
	 */
	public function hasLaterEntries( IReadableDatabase $db, int $commentId, int $logId, array $actions ): bool {
		$later = $db->newSelectQueryBuilder()
			->select( 'log_id' )
			->from( 'log_search' )
			->join( 'logging', null, 'log_id = ls_log_id' )
			->where( [
				'ls_field' => 'glowthread_post',
				'ls_value' => (string)$commentId,
				'log_type' => self::TYPE,
				'log_action' => $actions,
				$db->expr( 'log_id', '>', $logId ),
			] )
			->caller( __METHOD__ )
			->fetchFieldValues();
		foreach ( $later as $laterId ) {
			if ( !$this->isUndone( $db, (int)$laterId ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The status deleted comments had before their deletion, from the glowthread/delete entry
	 * written with it (7::old, at the comment's status time, not undone). Comments without such an
	 * entry are left out: FlowThread's deletions and imported comments.
	 *
	 * @param IReadableDatabase $db
	 * @param array<int,string> $deletedAt Comment ID => TS_MW time of the deletion (its status time)
	 * @param bool $lock Read with a shared lock (in the transaction that changes the comment), so
	 *   the latest committed entries count, not an older snapshot of the transaction
	 * @return array<int,string> Comment ID => PostStatus::VISIBLE or PostStatus::HELD
	 */
	public function getStatusesBeforeDeletion(
		IReadableDatabase $db,
		array $deletedAt,
		bool $lock = false
	): array {
		if ( !$deletedAt ) {
			return [];
		}
		$query = $db->newSelectQueryBuilder()
			->select( [ 'log_id', 'ls_value', 'log_timestamp', 'log_params' ] )
			->from( 'log_search' )
			->join( 'logging', null, 'log_id = ls_log_id' )
			->where( [
				'ls_field' => 'glowthread_post',
				'ls_value' => array_map( 'strval', array_keys( $deletedAt ) ),
				'log_type' => self::TYPE,
				'log_action' => 'delete',
			] )
			// The newest entry of that time wins
			->orderBy( 'log_id' )
			->caller( __METHOD__ );
		if ( $lock ) {
			$query->lockInShareMode();
		}
		$entries = [];
		foreach ( $query->fetchResultSet() as $row ) {
			$time = ConvertibleTimestamp::convert( TS_MW, $row->log_timestamp );
			if ( $time === ( $deletedAt[(int)$row->ls_value] ?? null ) ) {
				$entries[(int)$row->log_id] = $row;
			}
		}
		if ( !$entries ) {
			return [];
		}
		// An undone deletion is not the current one, even when it has the same time (to the second)
		$undone = $db->newSelectQueryBuilder()
			->select( 'ls_value' )
			->from( 'log_search' )
			->join( 'logging', null, 'log_id = ls_log_id' )
			->where( [
				'ls_field' => 'glowthread_undo',
				'ls_value' => array_map( 'strval', array_keys( $entries ) ),
				'log_type' => self::TYPE,
				'log_action' => 'undo',
			] )
			->caller( __METHOD__ );
		if ( $lock ) {
			$undone->lockInShareMode();
		}
		foreach ( $undone->fetchFieldValues() as $logId ) {
			unset( $entries[(int)$logId] );
		}
		$statuses = [];
		foreach ( $entries as $row ) {
			$old = LogEntryBase::extractParams( $row->log_params )['7::old'] ?? null;
			if ( in_array( $old, [ PostStatus::VISIBLE, PostStatus::HELD ], true ) ) {
				$statuses[(int)$row->ls_value] = $old;
			}
		}
		return $statuses;
	}

	/**
	 * After an erase: hide the performer and the reason of the comment's public entries that its
	 * author performed (a self-deletion, or a moderator acting on their own comment), in Special:Log,
	 * list=logevents and RecentChanges, the way core's log revision deletion does. Readers without
	 * deletedhistory then see neither who acted nor why.
	 *
	 * @param IDatabase $dbw In the erase transaction
	 * @param int $commentId
	 * @param int $authorActorId
	 */
	public function hideAuthorAsPerformer( IDatabase $dbw, int $commentId, int $authorActorId ): void {
		$logIds = $dbw->newSelectQueryBuilder()
			->select( 'log_id' )
			->from( 'logging' )
			->join( 'log_search', null, 'ls_log_id = log_id' )
			->where( [
				'ls_field' => 'glowthread_post',
				'ls_value' => (string)$commentId,
				'log_type' => self::TYPE,
				'log_actor' => $authorActorId,
			] )
			->caller( __METHOD__ )
			->fetchFieldValues();
		if ( !$logIds ) {
			return;
		}
		$bits = LogPage::DELETED_USER | LogPage::DELETED_COMMENT;
		$dbw->newUpdateQueryBuilder()
			->update( 'logging' )
			->set( [ 'log_deleted = ' . $dbw->bitOr( 'log_deleted', $bits ) ] )
			->where( [ 'log_id' => array_map( 'intval', $logIds ) ] )
			->caller( __METHOD__ )
			->execute();
		$dbw->newUpdateQueryBuilder()
			->update( 'recentchanges' )
			->set( [ 'rc_deleted = ' . $dbw->bitOr( 'rc_deleted', $bits ) ] )
			->where( [ 'rc_logid' => array_map( 'intval', $logIds ) ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Log a page state change: enable (open), lock (locked) or disable (disabled).
	 */
	public function logPageState(
		IDatabase $dbw,
		string $action,
		PageReference $page,
		UserIdentity $performer,
		string $reason
	): int {
		$entry = new ManualLogEntry( self::TYPE, $action );
		$entry->setPerformer( $performer );
		$entry->setTarget( $page );
		$entry->setComment( $reason );
		$logId = $entry->insert( $dbw );
		$this->publish( $entry, $logId );
		return $logId;
	}

	/**
	 * Log a settings change.
	 *
	 * @param UserIdentity $performer
	 * @param array $changes List of [name, old value, new value]
	 * @param string $reason
	 * @return int Log ID
	 */
	public function logSettings( UserIdentity $performer, array $changes, string $reason ): int {
		$entry = new ManualLogEntry( self::TYPE, 'settings' );
		$entry->setPerformer( $performer );
		$entry->setTarget( SpecialPage::getTitleFor( 'GlowThread', 'settings' ) );
		$entry->setComment( $reason );
		$entry->setParameters( [ '4::changes' => $changes ] );
		$logId = $entry->insert();
		$this->publish( $entry, $logId );
		return $logId;
	}

	/**
	 * Add an entry to RecentChanges while the publicLog setting is on. The glowthread type is
	 * restricted, and ManualLogEntry::publish() leaves restricted types out of RecentChanges, so
	 * its rows are inserted here. The glowthread-private type stays out.
	 *
	 * While the setting is off the entry gets no row at all, and goes to CheckUser directly. A
	 * row would reach readers past LogVisibilityHooks: saving it sends watchlist emails and moves
	 * the watchers' changed markers, and RecentChange_save tells other extensions about it
	 * (Echo's watchlist notifications, for one), none of which check log restrictions.
	 */
	private function publish( ManualLogEntry $entry, int $logId ): void {
		if ( $entry->getType() !== self::TYPE ) {
			$entry->publish( $logId );
			return;
		}
		// Built now, as core does, so that rc_bot follows the performer's rights in this request
		$rc = $entry->getRecentChange( $logId );
		$rc->addTags( $entry->getTags() );
		$services = MediaWikiServices::getInstance();
		DeferredUpdates::addCallableUpdate(
			static function () use ( $rc ) {
				$services = MediaWikiServices::getInstance();
				if ( !$services->getService( 'GlowThread.SettingsService' )->get( 'publicLog' ) ) {
					// The service is fetched by name because its class moved between CheckUser branches
					if ( ExtensionRegistry::getInstance()->isLoaded( 'CheckUser' )
						&& $services->hasService( 'CheckUserInsert' )
					) {
						$services->getService( 'CheckUserInsert' )->updateCheckUserData( $rc );
					}
					return;
				}
				if ( method_exists( $services, 'getRecentChangeFactory' ) ) {
					// MediaWiki 1.45 and later, where RecentChange::save() is deprecated
					$services->getRecentChangeFactory()->insertRecentChange( $rc );
				} else {
					$rc->save();
				}
			},
			DeferredUpdates::POSTSEND,
			$services->getConnectionProvider()->getPrimaryDatabase()
		);
	}

	/**
	 * The page as log target, or Special:GlowThread/page/<id> when the page no longer exists.
	 */
	private function getTarget( int $pageId ): PageReference {
		$target = $this->targets->get( $pageId, self::TARGET_TTL );
		if ( !$target ) {
			$target = $this->titleFactory->newFromID( $pageId ) ?? $this->titleFactory->newFromLinkTarget(
				SpecialPage::getTitleValueFor( 'GlowThread', 'page/' . $pageId )
			);
			$this->targets->set( $pageId, $target );
		}
		return $target;
	}
}
