<?php

namespace MediaWiki\Extension\GlowThread\Hooks;

use MediaWiki\Extension\GlowThread\Model\PostStatus;
use MediaWiki\Extension\GlowThread\Service\PageLifecycleService;
use MediaWiki\Extension\GlowThread\Store\PageStateStore;
use MediaWiki\Extension\GlowThread\Store\PostStore;
use MediaWiki\Extension\GlowThread\Store\ReactionStore;
use MediaWiki\Extension\GlowThread\Store\ReportStore;
use MediaWiki\Extension\GlowThread\Store\SettingsStore;
use MediaWiki\Extension\GlowThread\Text\TextProcessor;
use MediaWiki\User\User;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\LikeValue;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Extension:UserMerge support. Merging one account into another moves GlowThread's actor columns
 * to the account that stays: comments, likes and reports, and the users recorded for status
 * changes, resolved reports, page states and settings. The glowthread_author log
 * relation moves too, so the comment log and the author record name the account that stays.
 * Mentions of the old account (<@user ID> tokens in the text) are rewritten to the account that
 * stays, and the cached lists of that account's pages are purged.
 *
 * Likes and reports have the actor in a unique key: both accounts may have liked or reported
 * the same comment. UserMerge could only skip such rows (UPDATE IGNORE, which PostgreSQL does not
 * have). So GlowThread moves these two tables itself (UNIQUE_KEY_COLUMNS), after keeping one row
 * of each such pair and correcting the comment's counters.
 *
 * UserMerge's hook interfaces are not implemented, because UserMerge may not be installed.
 * HookContainer calls the methods by name.
 */
class UserMergeHooks {

	/** Table => actor column in a unique key, moved by onMergeAccountFromTo() */
	public const UNIQUE_KEY_COLUMNS = [
		ReactionStore::TABLE => 'gtr_actor',
		ReportStore::TABLE => 'gtrp_actor',
	];

	/** Comments read at once while rewriting mentions */
	private const MENTION_BATCH = 200;

	public function __construct(
		private readonly IConnectionProvider $dbProvider,
		private readonly PageLifecycleService $lifecycle,
		private readonly PostStore $postStore
	) {
	}

	/**
	 * GlowThread's other actor columns, in UserMerge's format. A batch key lets UserMerge move
	 * many rows in batches.
	 *
	 * @param array[] &$updateFields
	 */
	public function onUserMergeAccountFields( array &$updateFields ): void {
		$updateFields[] = [ PostStore::TABLE, 'batchKey' => 'gtp_id', 'actorId' => 'gtp_actor',
			'actorStage' => SCHEMA_COMPAT_NEW ];
		$updateFields[] = [ PostStore::TABLE, 'batchKey' => 'gtp_id', 'actorId' => 'gtp_status_actor',
			'actorStage' => SCHEMA_COMPAT_NEW ];
		$updateFields[] = [ ReportStore::TABLE, 'batchKey' => 'gtrp_id', 'actorId' => 'gtrp_resolved_actor',
			'actorStage' => SCHEMA_COMPAT_NEW ];
		$updateFields[] = [ PageStateStore::TABLE, 'batchKey' => 'gtpg_page', 'actorId' => 'gtpg_actor',
			'actorStage' => SCHEMA_COMPAT_NEW ];
		$updateFields[] = [ SettingsStore::TABLE, 'actorId' => 'gts_actor',
			'actorStage' => SCHEMA_COMPAT_NEW ];
	}

	/**
	 * After UserMerge moved the other columns, while the old account's actor still exists: move
	 * the likes and reports (UNIQUE_KEY_COLUMNS) and the glowthread_author log relation
	 * (ModerationLogger), which UserMerge does not know about, rewrite mentions of the old
	 * account, and purge the lists that show the old name.
	 *
	 * @param User $oldUser
	 * @param User $newUser
	 */
	public function onMergeAccountFromTo( $oldUser, $newUser ): void {
		$oldActorId = $oldUser->getActorId();
		$newActorId = $newUser->getActorId();
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->startAtomic( __METHOD__ );
		// An account without an actor never wrote anything, but may have been mentioned
		if ( $oldActorId && $newActorId && $oldActorId !== $newActorId ) {
			$this->mergeLikes( $dbw, $oldActorId, $newActorId );
			$this->mergeReports( $dbw, $oldActorId, $newActorId );
			foreach ( self::UNIQUE_KEY_COLUMNS as $table => $column ) {
				$dbw->newUpdateQueryBuilder()
					->update( $table )
					->set( [ $column => $newActorId ] )
					->where( [ $column => $oldActorId ] )
					->caller( __METHOD__ )
					->execute();
			}
			$dbw->newUpdateQueryBuilder()
				->update( 'log_search' )
				->set( [ 'ls_value' => (string)$newActorId ] )
				->where( [ 'ls_field' => 'glowthread_author', 'ls_value' => (string)$oldActorId ] )
				->caller( __METHOD__ )
				->execute();
		}
		$this->rewriteMentions( $dbw, $oldUser->getId(), $newUser->getId() );
		$dbw->endAtomic( __METHOD__ );
		$this->lifecycle->onAccountsMerged( $newUser );
	}

	/**
	 * A comment both accounts liked keeps the like of the account that stays, and one like less.
	 */
	private function mergeLikes( IDatabase $dbw, int $oldActorId, int $newActorId ): void {
		$res = $dbw->newSelectQueryBuilder()
			->select( [ 'post' => 'o.gtr_post', 'type' => 'o.gtr_type' ] )
			->from( ReactionStore::TABLE, 'o' )
			->join( ReactionStore::TABLE, 'n', [ 'n.gtr_post = o.gtr_post', 'n.gtr_type = o.gtr_type' ] )
			->where( [ 'o.gtr_actor' => $oldActorId, 'n.gtr_actor' => $newActorId ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		foreach ( $res as $row ) {
			$dbw->newDeleteQueryBuilder()
				->deleteFrom( ReactionStore::TABLE )
				->where( [ 'gtr_post' => (int)$row->post, 'gtr_actor' => $oldActorId, 'gtr_type' => $row->type ] )
				->caller( __METHOD__ )
				->execute();
			if ( $row->type === ReactionStore::TYPE_LIKE ) {
				$this->postStore->adjustCounter( $dbw, (int)$row->post, 'gtp_like_count', -1 );
			}
		}
	}

	/**
	 * A comment both accounts reported keeps one report: the open one, else the newer one (the
	 * one of the account that stays when they tie). Dropping an open report lowers the comment's
	 * open report count.
	 */
	private function mergeReports( IDatabase $dbw, int $oldActorId, int $newActorId ): void {
		$res = $dbw->newSelectQueryBuilder()
			->select( [
				'post' => 'o.gtrp_post',
				'old_id' => 'o.gtrp_id',
				'old_created' => 'o.gtrp_created',
				'old_resolved' => 'o.gtrp_resolved_at',
				'new_id' => 'n.gtrp_id',
				'new_created' => 'n.gtrp_created',
				'new_resolved' => 'n.gtrp_resolved_at',
			] )
			->from( ReportStore::TABLE, 'o' )
			->join( ReportStore::TABLE, 'n', 'n.gtrp_post = o.gtrp_post' )
			->where( [ 'o.gtrp_actor' => $oldActorId, 'n.gtrp_actor' => $newActorId ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		foreach ( $res as $row ) {
			$oldOpen = $row->old_resolved === null;
			$newOpen = $row->new_resolved === null;
			// Imported FlowThread reports have no time, so they count as the oldest
			$oldCreated = $row->old_created === null ? '' : ConvertibleTimestamp::convert( TS_MW, $row->old_created );
			$newCreated = $row->new_created === null ? '' : ConvertibleTimestamp::convert( TS_MW, $row->new_created );
			$keepOld = $oldOpen !== $newOpen ? $oldOpen : $oldCreated > $newCreated;
			$dbw->newDeleteQueryBuilder()
				->deleteFrom( ReportStore::TABLE )
				->where( [ 'gtrp_id' => (int)( $keepOld ? $row->new_id : $row->old_id ) ] )
				->caller( __METHOD__ )
				->execute();
			if ( $keepOld ? $newOpen : $oldOpen ) {
				$this->postStore->adjustCounter( $dbw, (int)$row->post, 'gtp_open_report_count', -1 );
			}
		}
	}

	/**
	 * Point the mentions of the old account (<@user ID>, TextProcessor::mentionToken()) at the
	 * account that stays. Only the exact token changes. Erased comments have no text to change.
	 */
	private function rewriteMentions( IDatabase $dbw, int $oldUserId, int $newUserId ): void {
		if ( !$oldUserId || !$newUserId || $oldUserId === $newUserId ) {
			return;
		}
		$oldToken = TextProcessor::mentionToken( $oldUserId );
		$newToken = TextProcessor::mentionToken( $newUserId );
		$mentions = $dbw->expr(
			'gtp_text', IExpression::LIKE, new LikeValue( $dbw->anyString(), $oldToken, $dbw->anyString() )
		);
		$lastId = 0;
		do {
			$res = $dbw->newSelectQueryBuilder()
				->select( [ 'gtp_id', 'gtp_text' ] )
				->from( PostStore::TABLE )
				->where( [
					$dbw->expr( 'gtp_id', '>', $lastId ),
					$dbw->expr( 'gtp_status', '!=', PostStatus::ERASED ),
					$mentions,
				] )
				->orderBy( 'gtp_id' )
				->limit( self::MENTION_BATCH )
				->caller( __METHOD__ )
				->fetchResultSet();
			foreach ( $res as $row ) {
				$lastId = (int)$row->gtp_id;
				$dbw->newUpdateQueryBuilder()
					->update( PostStore::TABLE )
					->set( [ 'gtp_text' => str_replace( $oldToken, $newToken, $row->gtp_text ) ] )
					->where( [ 'gtp_id' => $lastId ] )
					->caller( __METHOD__ )
					->execute();
			}
		} while ( $res->numRows() === self::MENTION_BATCH );
	}
}
