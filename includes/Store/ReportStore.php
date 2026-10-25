<?php

namespace MediaWiki\Extension\GlowThread\Store;

use MediaWiki\Extension\GlowThread\Model\ReportReason;
use stdClass;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * Row access for glowthread_report.
 */
class ReportStore {

	public const TABLE = 'glowthread_report';

	/**
	 * File a report unless this actor already reported the comment.
	 *
	 * @return bool Whether a new report was stored
	 */
	public function add(
		IDatabase $dbw,
		int $postId,
		int $actorId,
		string $reason,
		?string $note,
		string $timestamp
	): bool {
		$dbw->newInsertQueryBuilder()
			->insertInto( self::TABLE )
			->ignore()
			->row( [
				'gtrp_post' => $postId,
				'gtrp_actor' => $actorId,
				'gtrp_reason' => $reason,
				'gtrp_note' => $note,
				'gtrp_created' => $dbw->timestamp( $timestamp ),
			] )
			->caller( __METHOD__ )
			->execute();
		return $dbw->affectedRows() > 0;
	}

	/**
	 * Turn the actor's open report imported from FlowThread (reason legacy) into a report filed
	 * now, when they report the comment again: imported reports do not count toward the
	 * automatic hold, reports filed here do.
	 *
	 * @param IDatabase $dbw
	 * @param int $postId
	 * @param int $actorId
	 * @param string $reason
	 * @param string|null $note
	 * @param string $timestamp
	 * @return bool Whether there was such a report
	 */
	public function replaceLegacy(
		IDatabase $dbw,
		int $postId,
		int $actorId,
		string $reason,
		?string $note,
		string $timestamp
	): bool {
		$dbw->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( [ 'gtrp_reason' => $reason, 'gtrp_note' => $note, 'gtrp_created' => $dbw->timestamp( $timestamp ) ] )
			->where( [
				'gtrp_post' => $postId,
				'gtrp_actor' => $actorId,
				'gtrp_reason' => ReportReason::LEGACY,
				'gtrp_resolved_at' => null,
			] )
			->caller( __METHOD__ )
			->execute();
		return $dbw->affectedRows() > 0;
	}

	/**
	 * Reporters for the automatic hold. Reports imported from FlowThread (reason legacy) are left
	 * out: FlowThread never acted on reports, so they piled up on contested comments.
	 *
	 * @return int[] Actor IDs with an open report on the comment, filed in GlowThread
	 */
	public function getOpenReporterActorIds( IReadableDatabase $db, int $postId ): array {
		return array_map( 'intval', $db->newSelectQueryBuilder()
			->select( 'gtrp_actor' )
			->from( self::TABLE )
			->where( [
				'gtrp_post' => $postId,
				'gtrp_resolved_at' => null,
				$db->expr( 'gtrp_reason', '!=', ReportReason::LEGACY ),
			] )
			->caller( __METHOD__ )
			->fetchFieldValues() );
	}

	/**
	 * Whether a moderator closed reports on the comment and kept it: approved it while it had
	 * reports, or dismissed them. An undo reopens the reports, and then this is false again.
	 */
	public function hasDismissed( IReadableDatabase $db, int $postId ): bool {
		return (bool)$db->newSelectQueryBuilder()
			->select( '1' )
			->from( self::TABLE )
			->where( [ 'gtrp_post' => $postId, 'gtrp_outcome' => ReportReason::OUTCOME_DISMISSED ] )
			->limit( 1 )
			->caller( __METHOD__ )
			->fetchField();
	}

	/**
	 * Resolve every open report on a comment.
	 *
	 * @return int[] IDs of the reports resolved
	 */
	public function resolveOpen(
		IDatabase $dbw,
		int $postId,
		int $resolverActorId,
		string $timestamp,
		string $outcome
	): array {
		$ids = array_map( 'intval', $dbw->newSelectQueryBuilder()
			->select( 'gtrp_id' )
			->from( self::TABLE )
			->where( [ 'gtrp_post' => $postId, 'gtrp_resolved_at' => null ] )
			->forUpdate()
			->caller( __METHOD__ )
			->fetchFieldValues() );
		if ( !$ids ) {
			return [];
		}
		$dbw->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( [
				'gtrp_resolved_actor' => $resolverActorId,
				'gtrp_resolved_at' => $dbw->timestamp( $timestamp ),
				'gtrp_outcome' => $outcome,
			] )
			->where( [ 'gtrp_id' => $ids, 'gtrp_resolved_at' => null ] )
			->caller( __METHOD__ )
			->execute();
		return $ids;
	}

	/**
	 * Blank the notes of every report on a comment. Erasing a comment does this, because the
	 * notes often quote the erased text.
	 */
	public function clearNotes( IDatabase $dbw, int $postId ): void {
		$dbw->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( [ 'gtrp_note' => null ] )
			->where( [ 'gtrp_post' => $postId, $dbw->expr( 'gtrp_note', '!=', null ) ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Reopen reports of a comment that one moderation action resolved, to undo it: those IDs,
	 * if they are still resolved with that outcome at that time.
	 *
	 * @param IDatabase $dbw
	 * @param int $postId
	 * @param int[] $reportIds
	 * @param string $timestamp
	 * @param string $outcome
	 * @return int Number of reports reopened
	 */
	public function reopenResolved(
		IDatabase $dbw,
		int $postId,
		array $reportIds,
		string $timestamp,
		string $outcome
	): int {
		if ( !$reportIds ) {
			return 0;
		}
		$dbw->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( [ 'gtrp_resolved_actor' => null, 'gtrp_resolved_at' => null, 'gtrp_outcome' => null ] )
			->where( [
				'gtrp_id' => $reportIds,
				'gtrp_post' => $postId,
				'gtrp_resolved_at' => $dbw->timestamp( $timestamp ),
				'gtrp_outcome' => $outcome,
			] )
			->caller( __METHOD__ )
			->execute();
		return $dbw->affectedRows();
	}

	/**
	 * @param IReadableDatabase $db
	 * @param int $actorId
	 * @param int[] $postIds
	 * @return int[] The subset of $postIds the actor reported (open or resolved)
	 */
	public function getReportedPostIds( IReadableDatabase $db, int $actorId, array $postIds ): array {
		if ( !$postIds || $actorId <= 0 ) {
			return [];
		}
		return array_map( 'intval', $db->newSelectQueryBuilder()
			->select( 'gtrp_post' )
			->from( self::TABLE )
			->where( [ 'gtrp_actor' => $actorId, 'gtrp_post' => $postIds ] )
			->caller( __METHOD__ )
			->fetchFieldValues() );
	}

	/**
	 * @param IReadableDatabase $db
	 * @param int[] $postIds
	 * @return array<int,array<string,int>> Post ID => reason => number of open reports
	 */
	public function getOpenReasonCounts( IReadableDatabase $db, array $postIds ): array {
		if ( !$postIds ) {
			return [];
		}
		$res = $db->newSelectQueryBuilder()
			->select( [ 'gtrp_post', 'gtrp_reason', 'n' => 'COUNT(*)' ] )
			->from( self::TABLE )
			->where( [ 'gtrp_post' => $postIds, 'gtrp_resolved_at' => null ] )
			->groupBy( [ 'gtrp_post', 'gtrp_reason' ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$counts = [];
		foreach ( $res as $row ) {
			$counts[(int)$row->gtrp_post][(string)$row->gtrp_reason] = (int)$row->n;
		}
		return $counts;
	}

	/**
	 * Open reports with notes, newest first, for the moderation queue.
	 *
	 * @param IReadableDatabase $db
	 * @param int[] $postIds
	 * @param int $limit Total rows
	 * @return stdClass[] Rows with gtrp_* fields
	 */
	public function getOpenReports( IReadableDatabase $db, array $postIds, int $limit ): array {
		if ( !$postIds ) {
			return [];
		}
		return iterator_to_array( $db->newSelectQueryBuilder()
			->select( [
				'gtrp_id', 'gtrp_post', 'gtrp_actor', 'gtrp_reason', 'gtrp_note', 'gtrp_created',
			] )
			->from( self::TABLE )
			->where( [ 'gtrp_post' => $postIds, 'gtrp_resolved_at' => null ] )
			->orderBy( 'gtrp_id', SelectQueryBuilder::SORT_DESC )
			->limit( $limit )
			->caller( __METHOD__ )
			->fetchResultSet(), false );
	}

	/**
	 * Reports filed by an actor in GlowThread, by outcome. Reports imported from FlowThread
	 * (reason legacy) are left out.
	 *
	 * @return array{filed:int,open:int,upheld:int,dismissed:int}
	 */
	public function getStatsByActor( IReadableDatabase $db, int $actorId ): array {
		$stats = [ 'filed' => 0, 'open' => 0, 'upheld' => 0, 'dismissed' => 0 ];
		$res = $db->newSelectQueryBuilder()
			->select( [ 'gtrp_outcome', 'n' => 'COUNT(*)' ] )
			->from( self::TABLE )
			->where( [ 'gtrp_actor' => $actorId, $db->expr( 'gtrp_reason', '!=', ReportReason::LEGACY ) ] )
			->groupBy( 'gtrp_outcome' )
			->caller( __METHOD__ )
			->fetchResultSet();
		foreach ( $res as $row ) {
			$n = (int)$row->n;
			$stats['filed'] += $n;
			if ( $row->gtrp_outcome === null ) {
				$stats['open'] += $n;
			} elseif ( $row->gtrp_outcome === ReportReason::OUTCOME_UPHELD ) {
				$stats['upheld'] += $n;
			} elseif ( $row->gtrp_outcome === ReportReason::OUTCOME_DISMISSED ) {
				$stats['dismissed'] += $n;
			}
		}
		return $stats;
	}
}
