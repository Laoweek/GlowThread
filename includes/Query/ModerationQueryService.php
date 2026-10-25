<?php

namespace MediaWiki\Extension\GlowThread\Query;

use DatabaseLogEntry;
use LogEventsList;
use LogPage;
use MediaWiki\Block\DatabaseBlockStore;
use MediaWiki\Extension\GlowThread\GlowThreadConfig;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Model\PostStatus;
use MediaWiki\Extension\GlowThread\Model\ReportReason;
use MediaWiki\Extension\GlowThread\Store\PageStateStore;
use MediaWiki\Extension\GlowThread\Store\PostStore;
use MediaWiki\Extension\GlowThread\Store\ReportStore;
use MediaWiki\Extension\GlowThread\Text\TextProcessor;
use MediaWiki\Page\PageStore;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\TitleFactory;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\User\ActorStore;
use MediaWiki\User\Registration\UserRegistrationLookup;
use MediaWiki\User\UserEditTracker;
use MediaWiki\User\UserGroupManager;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Read side of the moderation hub: the queue, user history and page history. Callers check
 * glowthread-moderate first.
 *
 * The comments, page histories and log entries listed are limited to pages whose comments the
 * viewer may see (canSeePages()). The counts include every page.
 */
class ModerationQueryService {

	public const TABS = [ 'reported', 'held', 'deleted', 'all' ];
	/** Queue orders: most open reports first, newest first, oldest first */
	public const SORTS = [ 'reports', 'new', 'old' ];
	/** Open reports shown per queue item */
	private const REPORTS_PER_ITEM = 10;
	/** Log entries shown in histories */
	private const LOG_LIMIT = 20;
	/** Rows read at most for one page of a list, when rows on hidden pages are skipped */
	private const MAX_SCAN = 500;

	public function __construct(
		private readonly PostStore $postStore,
		private readonly ReportStore $reportStore,
		private readonly PageStateStore $pageStateStore,
		private readonly CommentSerializer $serializer,
		private readonly ActorInfoLookup $actorInfoLookup,
		private readonly ActorStore $actorStore,
		private readonly PageStore $pageStore,
		private readonly TitleFactory $titleFactory,
		private readonly TitleFormatter $titleFormatter,
		private readonly UserRegistrationLookup $userRegistrationLookup,
		private readonly UserEditTracker $userEditTracker,
		private readonly UserGroupManager $userGroupManager,
		private readonly DatabaseBlockStore $blockStore,
		private readonly ViewerFactory $viewerFactory,
		private readonly GlowThreadConfig $config
	) {
	}

	public function newViewer( Authority $authority ): Viewer {
		return $this->viewerFactory->newViewer( $authority );
	}

	/**
	 * The moderation queue.
	 *
	 * @param Viewer $viewer
	 * @param string $tab One of TABS
	 * @param array{pageId?:int,user?:string,reporter?:string,q?:string} $filters
	 * @param string $cursor
	 * @param int $limit
	 * @param string|null $sort One of SORTS, null for the tab's default (reports for the
	 *   reported tab, new for the others)
	 * @return array<string,mixed>|false False for a bad cursor
	 */
	public function getQueue(
		Viewer $viewer,
		string $tab,
		array $filters,
		string $cursor,
		int $limit,
		?string $sort = null
	) {
		$sort ??= $tab === 'reported' ? 'reports' : 'new';
		$db = $this->postStore->getReplica();
		// The default orders keep their cursor kinds of before (q-reported, q-<tab>)
		$kind = $sort === ( $tab === 'reported' ? 'reports' : 'new' ) ? 'q-' . $tab : "q-$tab-$sort";
		$after = null;
		if ( $cursor !== '' ) {
			$after = $sort === 'reports'
				? Cursor::decode( $cursor, $kind, [ 'r' => 'int', 'i' => 'int' ] )
				: Cursor::decode( $cursor, $kind, [ 'i' => 'int' ] );
			if ( !$after ) {
				return false;
			}
		}
		$qb = $this->postStore->newSelectQueryBuilder( $db )->caller( __METHOD__ );
		$this->applyTab( $db, $qb, $tab );
		$filterStatus = $this->applyFilters( $db, $qb, $filters, $viewer );
		// A page whose comments the viewer may not see has nothing to list
		$visible = isset( $filters['pageId'] ) ? $this->canSee( $viewer, [ $filters['pageId'] ] ) : [];
		if ( $filterStatus === false || in_array( false, $visible, true ) ) {
			return [
				'tab' => $tab, 'sort' => $sort, 'items' => [], 'next' => null, 'counts' => $this->getQueueCounts( $db ),
			];
		}
		// $afterPosition: the rows after a cursor position, in the order of the sort
		if ( $sort === 'reports' ) {
			$qb->orderBy( [ 'gtp_open_report_count', 'gtp_id' ], SelectQueryBuilder::SORT_DESC );
			$afterPosition = static fn ( array $at ) => $db->orExpr( [
				$db->expr( 'gtp_open_report_count', '<', $at['r'] ),
				$db->expr( 'gtp_open_report_count', '=', $at['r'] )->and( 'gtp_id', '<', $at['i'] ),
			] );
		} elseif ( $sort === 'old' ) {
			$qb->orderBy( 'gtp_id', SelectQueryBuilder::SORT_ASC );
			$afterPosition = static fn ( array $at ) => $db->expr( 'gtp_id', '>', $at['i'] );
		} else {
			$qb->orderBy( 'gtp_id', SelectQueryBuilder::SORT_DESC );
			$afterPosition = static fn ( array $at ) => $db->expr( 'gtp_id', '<', $at['i'] );
		}
		$position = static fn ( PostRecord $post ) => $sort === 'reports'
			? [ 'r' => $post->getOpenReportCount(), 'i' => $post->getId() ]
			: [ 'i' => $post->getId() ];
		if ( $after ) {
			$qb->andWhere( $afterPosition( $after ) );
		}
		[ $posts, $last ] = $this->fetchVisible(
			$viewer, $qb, $limit, static fn ( PostRecord $post ) => $afterPosition( $position( $post ) ), $visible
		);
		return [
			'tab' => $tab,
			'sort' => $sort,
			'items' => $this->describeItems( $db, $viewer, $posts ),
			'next' => $last ? Cursor::encode( $kind, $position( $last ) ) : null,
			'counts' => $this->getQueueCounts( $db ),
		];
	}

	/**
	 * Up to $limit comments of a query that are on pages whose comments the viewer may see
	 * (canSeePages()). Rows on other pages are skipped and more rows are read in their place, so
	 * the list comes back short only at the end of the query or after MAX_SCAN rows. In the
	 * second case the next page goes on after the last row read.
	 *
	 * @param Viewer $viewer
	 * @param SelectQueryBuilder $qb The query, ordered and after the cursor, without a limit
	 * @param int $limit
	 * @param callable(PostRecord):IExpression $afterPost The rows after a comment, in the query's order
	 * @param array<int,bool> &$visible canSeePages() answers known so far, extended with the
	 *   pages checked here, so the caller can reuse them
	 * @return array{0:PostRecord[],1:?PostRecord} The comments, and the comment the next page
	 *   starts after (null when there are no more)
	 */
	private function fetchVisible(
		Viewer $viewer,
		SelectQueryBuilder $qb,
		int $limit,
		callable $afterPost,
		array &$visible
	): array {
		$posts = [];
		$last = null;
		$scanned = 0;
		// The first batch is what the list needed before; it is enough unless rows are skipped
		$batch = $limit + 1;
		while ( true ) {
			$round = clone $qb;
			if ( $last ) {
				$round->andWhere( $afterPost( $last ) );
			}
			$rows = [];
			foreach ( $round->limit( $batch )->fetchResultSet() as $row ) {
				$rows[] = PostRecord::newFromRow( $row );
			}
			$pageIds = array_map( static fn ( PostRecord $post ) => $post->getPageId(), $rows );
			$visible += $this->canSee( $viewer, array_diff( $pageIds, array_keys( $visible ) ) );
			foreach ( $rows as $post ) {
				if ( $visible[$post->getPageId()] ) {
					if ( count( $posts ) === $limit ) {
						// One more comment to show: there is a next page
						return [ $posts, end( $posts ) ];
					}
					$posts[] = $post;
				}
				$last = $post;
			}
			$scanned += count( $rows );
			if ( count( $rows ) < $batch ) {
				return [ $posts, null ];
			}
			if ( $scanned >= self::MAX_SCAN ) {
				return [ $posts, $last ];
			}
			$batch = min( $batch * 2, self::MAX_SCAN - $scanned );
		}
	}

	/**
	 * Comments with their page, the comment they reply to and open reports, so each item can
	 * be judged in context.
	 *
	 * @param IReadableDatabase $db
	 * @param Viewer $viewer
	 * @param PostRecord[] $posts
	 * @return array[]
	 */
	public function describeItems( IReadableDatabase $db, Viewer $viewer, array $posts ): array {
		if ( !$posts ) {
			return [];
		}
		$contextIds = [];
		foreach ( $posts as $post ) {
			if ( $post->getReplyToId() ) {
				$contextIds[] = $post->getReplyToId();
			}
		}
		$contextPosts = $this->postStore->getByIds( $contextIds );
		$serialized = $this->serializer->serializeAll( array_merge( $posts, array_values( $contextPosts ) ), $viewer );
		$reportedIds = [];
		foreach ( $posts as $post ) {
			if ( $post->getOpenReportCount() > 0 ) {
				$reportedIds[] = $post->getId();
			}
		}
		$reportRows = $this->reportStore->getOpenReports( $db, $reportedIds, count( $posts ) * self::REPORTS_PER_ITEM );
		$reporters = $this->actorInfoLookup->getInfo(
			array_map( static fn ( $r ) => (int)$r->gtrp_actor, $reportRows )
		);
		$reportsByPost = [];
		foreach ( $reportRows as $row ) {
			$postId = (int)$row->gtrp_post;
			if ( count( $reportsByPost[$postId] ?? [] ) >= self::REPORTS_PER_ITEM ) {
				continue;
			}
			$reportsByPost[$postId][] = [
				'id' => (int)$row->gtrp_id,
				'reason' => (string)$row->gtrp_reason,
				'note' => $row->gtrp_note === null ? null : (string)$row->gtrp_note,
				'created' => $row->gtrp_created === null
					? null : CommentSerializer::iso( ConvertibleTimestamp::convert( TS_MW, $row->gtrp_created ) ),
				// Reports imported from FlowThread name no reporter: FlowThread showed only counts
				'reporter' => $this->serializer->formatAuthor(
					$reporters[(int)$row->gtrp_actor] ?? null,
					$viewer,
					(string)$row->gtrp_reason === ReportReason::LEGACY
				),
			];
		}
		$pages = $this->describePages( array_map( static fn ( $p ) => $p->getPageId(), $posts ) );
		$items = [];
		foreach ( $posts as $post ) {
			$replyToId = $post->getReplyToId();
			$items[] = [
				'comment' => $serialized[$post->getId()],
				'page' => $pages[$post->getPageId()],
				'context' => $replyToId && isset( $serialized[$replyToId] ) ? $serialized[$replyToId] : null,
				'reports' => $reportsByPost[$post->getId()] ?? [],
			];
		}
		return $items;
	}

	/**
	 * Tab badge counts. Reported and held count only comments on existing pages.
	 *
	 * @return array<string,int>
	 */
	public function getQueueCounts( IReadableDatabase $db ): array {
		$counts = [];
		foreach ( [ 'reported', 'held', 'deleted' ] as $tab ) {
			$qb = $db->newSelectQueryBuilder()
				->select( 'COUNT(*)' )
				->from( PostStore::TABLE )
				->caller( __METHOD__ );
			$this->applyTab( $db, $qb, $tab );
			$counts[$tab] = (int)$qb->fetchField();
		}
		return $counts;
	}

	/**
	 * Everything a moderator needs to judge a user.
	 *
	 * @return array<string,mixed>|null|false Null if unknown or hidden from the viewer, false for a bad cursor
	 */
	public function getUserHistory( Viewer $viewer, string $name, string $cursor, int $limit ) {
		$db = $this->postStore->getReplica();
		$identity = $this->actorStore->getUserIdentityByName( $name );
		if ( !$identity ) {
			return null;
		}
		$actorId = $this->actorStore->findActorId( $identity, $db );
		$info = $actorId ? ( $this->actorInfoLookup->getInfo( [ $actorId ] )[$actorId] ?? null ) : null;
		if ( $info && $info['hidden'] && !$viewer->canSeeHiddenUsers ) {
			return null;
		}
		$after = null;
		if ( $cursor !== '' ) {
			$after = Cursor::decode( $cursor, 'user', [ 'i' => 'int' ] );
			if ( !$after ) {
				return false;
			}
		}

		$user = [
			'name' => $identity->getName(),
			'id' => $identity->getId(),
			'actorId' => $actorId ?: null,
			'registered' => $identity->isRegistered(),
			'registration' => null,
			'editCount' => null,
			'groups' => [],
			'blocks' => [],
		];
		if ( $identity->isRegistered() ) {
			$registration = $this->userRegistrationLookup->getRegistration( $identity );
			$user['registration'] = $registration ? CommentSerializer::iso( $registration ) : null;
			$user['editCount'] = $this->userEditTracker->getUserEditCount( $identity );
			$user['groups'] = array_values( $this->userGroupManager->getUserGroups( $identity ) );
			$user['blocks'] = $this->describeBlocks( [ 'bt_user' => $identity->getId() ] );
		} else {
			$user['blocks'] = $this->describeBlocks( [ 'bt_address' => $identity->getName() ] );
		}

		$counts = array_fill_keys( PostStatus::ALL, 0 );
		$comments = [];
		$next = null;
		$moderation = [];
		$reports = [ 'filed' => 0, 'open' => 0, 'upheld' => 0, 'dismissed' => 0 ];
		if ( $actorId ) {
			$res = $db->newSelectQueryBuilder()
				->select( [ 'gtp_status', 'n' => 'COUNT(*)' ] )
				->from( PostStore::TABLE )
				->where( [ 'gtp_actor' => $actorId ] )
				->groupBy( 'gtp_status' )
				->caller( __METHOD__ )
				->fetchResultSet();
			foreach ( $res as $row ) {
				$counts[(string)$row->gtp_status] = (int)$row->n;
			}
			if ( !$viewer->canErase ) {
				// Who wrote an erased comment is for glowthread-erase holders only
				$counts[PostStatus::ERASED] = null;
			}
			$qb = $this->postStore->newSelectQueryBuilder( $db )
				->where( [ 'gtp_actor' => $actorId ] )
				->orderBy( 'gtp_id', SelectQueryBuilder::SORT_DESC )
				->caller( __METHOD__ );
			if ( $after ) {
				$qb->andWhere( $db->expr( 'gtp_id', '<', $after['i'] ) );
			}
			if ( !$viewer->canErase ) {
				$qb->andWhere( $db->expr( 'gtp_status', '!=', PostStatus::ERASED ) );
			}
			$visible = [];
			[ $posts, $last ] = $this->fetchVisible(
				$viewer,
				$qb,
				$limit,
				static fn ( PostRecord $post ) => $db->expr( 'gtp_id', '<', $post->getId() ),
				$visible
			);
			if ( $last ) {
				$next = Cursor::encode( 'user', [ 'i' => $last->getId() ] );
			}
			$comments = $this->describeItems( $db, $viewer, $posts );
			$reports = $this->reportStore->getStatsByActor( $db, $actorId );
			$moderation = $this->getLogEntries(
				$db, $viewer, [ 'ls_field' => 'glowthread_author', 'ls_value' => (string)$actorId ], true, $visible
			);
		}
		return [
			'user' => $user,
			'counts' => $counts,
			'reports' => $reports,
			'comments' => $comments,
			'next' => $next,
			'moderation' => $moderation,
		];
	}

	/**
	 * Comment state of one page: page state, counts by status, recent moderation.
	 *
	 * @return array<string,mixed>|null Null if the viewer may not see the page's comments
	 */
	public function getPageHistory( Viewer $viewer, int $pageId ): ?array {
		if ( !$this->canSee( $viewer, [ $pageId ] )[$pageId] ) {
			return null;
		}
		$db = $this->postStore->getReplica();
		$state = $this->pageStateStore->get( $pageId );
		$counts = array_fill_keys( PostStatus::ALL, 0 );
		$res = $db->newSelectQueryBuilder()
			->select( [ 'gtp_status', 'n' => 'COUNT(*)' ] )
			->from( PostStore::TABLE )
			->where( [ 'gtp_page' => $pageId ] )
			->groupBy( 'gtp_status' )
			->caller( __METHOD__ )
			->fetchResultSet();
		foreach ( $res as $row ) {
			$counts[(string)$row->gtp_status] = (int)$row->n;
		}
		$reported = (int)$db->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( PostStore::TABLE )
			->where( [ 'gtp_page' => $pageId, $db->expr( 'gtp_open_report_count', '>', 0 ) ] )
			->caller( __METHOD__ )
			->fetchField();
		$changedBy = $state['actor']
			? ( $this->actorInfoLookup->getInfo( [ $state['actor'] ] )[$state['actor']] ?? null )
			: null;
		return [
			'page' => $this->describePages( [ $pageId ] )[$pageId] + [
				'state' => $state['state'],
				'stateChangedBy' => $changedBy ? $this->serializer->formatAuthor( $changedBy, $viewer, false ) : null,
				'stateChangedAt' => CommentSerializer::iso( $state['timestamp'] ),
				'floors' => $state['seq'],
			],
			'counts' => $counts + [ 'reported' => $reported ],
			'moderation' => $this->getLogEntries( $db, $viewer, [ 'log_page' => $pageId ] ),
		];
	}

	private function applyTab( IReadableDatabase $db, SelectQueryBuilder $qb, string $tab ): void {
		switch ( $tab ) {
			case 'reported':
				$qb->join( 'page', null, 'page_id = gtp_page' );
				$qb->andWhere( [
					$db->expr( 'gtp_open_report_count', '>', 0 ),
					'gtp_status' => [ PostStatus::VISIBLE, PostStatus::HELD ],
				] );
				break;
			case 'held':
				$qb->join( 'page', null, 'page_id = gtp_page' );
				$qb->andWhere( [ 'gtp_status' => PostStatus::HELD ] );
				break;
			case 'deleted':
				$qb->andWhere( [ 'gtp_status' => [ PostStatus::DELETED, PostStatus::ERASED ] ] );
				break;
		}
	}

	/**
	 * @return bool False when a filter cannot match anything
	 */
	private function applyFilters(
		IReadableDatabase $db,
		SelectQueryBuilder $qb,
		array $filters,
		Viewer $viewer
	): bool {
		if ( isset( $filters['pageId'] ) ) {
			$qb->andWhere( [ 'gtp_page' => $filters['pageId'] ] );
		}
		if ( isset( $filters['user'] ) && $filters['user'] !== '' ) {
			$actorId = $this->actorStore->findActorIdByName( $filters['user'], $db );
			if ( !$actorId ) {
				return false;
			}
			$info = $this->actorInfoLookup->getInfo( [ $actorId ] )[$actorId] ?? null;
			if ( $info && $info['hidden'] && !$viewer->canSeeHiddenUsers ) {
				// Answer as for an unknown user, so the filter does not confirm a hidden account
				return false;
			}
			$qb->andWhere( [ 'gtp_actor' => $actorId ] );
			if ( !$viewer->canErase ) {
				$qb->andWhere( $db->expr( 'gtp_status', '!=', PostStatus::ERASED ) );
			}
		}
		if ( isset( $filters['reporter'] ) && $filters['reporter'] !== '' ) {
			$reporterId = $this->actorStore->findActorIdByName( $filters['reporter'], $db );
			$info = $reporterId ? ( $this->actorInfoLookup->getInfo( [ $reporterId ] )[$reporterId] ?? null ) : null;
			if ( !$info || ( $info['hidden'] && !$viewer->canSeeHiddenUsers ) ) {
				return false;
			}
			// Comments with an open report by this user (one report per user and comment). Reports
			// imported from FlowThread are left out, as they name no reporter.
			$qb->join( ReportStore::TABLE, null, 'gtrp_post = gtp_id' );
			$qb->andWhere( [
				'gtrp_actor' => $reporterId,
				'gtrp_resolved_at' => null,
				$db->expr( 'gtrp_reason', '!=', ReportReason::LEGACY ),
			] );
		}
		if ( isset( $filters['q'] ) && $filters['q'] !== '' ) {
			$condition = self::textContains( $db, $filters['q'] );
			$mentioned = self::findMentionedUserId(
				$this->actorStore, $this->actorInfoLookup, $db, $filters['q'], $viewer
			);
			if ( $mentioned ) {
				$token = TextProcessor::mentionToken( $mentioned );
				$condition = $db->makeList( [
					$condition,
					'gtp_text' . $db->buildLike( $db->anyString(), $token, $db->anyString() ),
				], IReadableDatabase::LIST_OR );
			}
			$qb->andWhere( $condition );
		}
		return true;
	}

	/**
	 * The ID of the registered user named $name, so that a text search for a name also finds
	 * the comments that mention them: the text stores a mention as <@ID>
	 * (TextProcessor::mentionToken()), not the name. Null for any other search, and for a user
	 * hidden by a hideuser block unless the viewer may see hidden users (as in the user filter).
	 */
	public static function findMentionedUserId(
		ActorStore $actorStore,
		ActorInfoLookup $actorInfoLookup,
		IReadableDatabase $db,
		string $name,
		Viewer $viewer
	): ?int {
		$actorId = $actorStore->findActorIdByName( $name, $db );
		$info = $actorId ? ( $actorInfoLookup->getInfo( [ $actorId ] )[$actorId] ?? null ) : null;
		if ( !$info || $info['userId'] <= 0 || ( $info['hidden'] && !$viewer->canSeeHiddenUsers ) ) {
			return null;
		}
		return $info['userId'];
	}

	/**
	 * SQL condition: the comment text contains $q, ignoring the case of letters (CJK text has
	 * no case). On MySQL the text column is binary, so it is converted to text before LOWER().
	 * Postgres stores it as text already. SQLite's LOWER() changes ASCII letters only.
	 */
	public static function textContains( IReadableDatabase $db, string $q ): string {
		$text = $db->getType() === 'mysql' ? 'LOWER(CONVERT(gtp_text USING utf8mb4))' : 'LOWER(gtp_text)';
		return $text . $db->buildLike( $db->anyString(), mb_strtolower( $q, 'UTF-8' ), $db->anyString() );
	}

	/**
	 * @param int[] $pageIds
	 * @return array<int,array{id:int,title:?string,exists:bool}>
	 */
	private function describePages( array $pageIds ): array {
		$pageIds = array_values( array_unique( array_map( 'intval', $pageIds ) ) );
		$records = [];
		if ( $pageIds ) {
			// One query for all pages of the list
			foreach ( $this->pageStore->newSelectQueryBuilder()
				->wherePageIds( $pageIds )
				->caller( __METHOD__ )
				->fetchPageRecords() as $record
			) {
				$records[$record->getId()] = $record;
			}
		}
		$out = [];
		foreach ( $pageIds as $pageId ) {
			$page = $records[$pageId] ?? null;
			$out[$pageId] = [
				'id' => $pageId,
				'title' => $page ? $this->titleFormatter->getPrefixedText( $page ) : null,
				'exists' => (bool)$page,
			];
		}
		return $out;
	}

	/**
	 * @param Viewer $viewer
	 * @param int[] $pageIds
	 * @return array<int,bool> See canSeePages()
	 */
	private function canSee( Viewer $viewer, array $pageIds ): array {
		return self::canSeePages( $this->pageStore, $this->postStore->getReplica(), $viewer->authority, $pageIds );
	}

	/**
	 * Whether a moderator may see the comments of each page, with one query for all pages and one
	 * check per page:
	 * - a page that exists: if they may read it, by the check of the reader routes;
	 * - a deleted page: yes, unless its last deletion was a suppression and they may not see
	 *   suppressed revisions (the check of Special:Undelete).
	 * Static, so that the compat module's listall shares it.
	 *
	 * @param PageStore $pageStore
	 * @param IReadableDatabase $db
	 * @param Authority $authority
	 * @param int[] $pageIds
	 * @return array<int,bool> Page ID => whether its comments may be shown
	 */
	public static function canSeePages(
		PageStore $pageStore,
		IReadableDatabase $db,
		Authority $authority,
		array $pageIds
	): array {
		$pageIds = array_values( array_unique( array_map( 'intval', $pageIds ) ) );
		$out = [];
		if ( !$pageIds ) {
			return $out;
		}
		foreach ( $pageStore->newSelectQueryBuilder()
			->wherePageIds( $pageIds )
			->caller( __METHOD__ )
			->fetchPageRecords() as $page
		) {
			$out[$page->getId()] = $authority->authorizeRead( 'read', $page );
		}
		$deleted = array_values( array_diff( $pageIds, array_keys( $out ) ) );
		$suppressed = [];
		if ( $deleted && !RevisionRecord::userCanBitfield(
			RevisionRecord::SUPPRESSED_ALL, RevisionRecord::DELETED_TEXT, $authority
		) ) {
			// Oldest first, so the last deletion of each page decides, as for undeletions
			// (PageLifecycleService::wasSuppressed())
			$res = $db->newSelectQueryBuilder()
				->select( [ 'log_page', 'log_type' ] )
				->from( 'logging' )
				->where( [
					'log_type' => [ 'delete', 'suppress' ],
					'log_action' => [ 'delete', 'delete_redir', 'delete_redir2' ],
					'log_page' => $deleted,
				] )
				->orderBy( [ 'log_timestamp', 'log_id' ], SelectQueryBuilder::SORT_ASC )
				->caller( __METHOD__ )
				->fetchResultSet();
			foreach ( $res as $row ) {
				$suppressed[(int)$row->log_page] = $row->log_type === 'suppress';
			}
		}
		foreach ( $deleted as $pageId ) {
			$out[$pageId] = !( $suppressed[$pageId] ?? false );
		}
		return $out;
	}

	/**
	 * Active blocks matching the conditions (bt_user or bt_address). Autoblocks are left out:
	 * their target is an IP address and their reason names the blocked account, so listing them
	 * under an address would tie it to that account. Core lists autoblocks only by ID.
	 *
	 * @param array $conds
	 * @return array[]
	 */
	private function describeBlocks( array $conds ): array {
		$out = [];
		foreach ( $this->blockStore->newListFromConds( $conds + [ 'bt_auto' => 0 ] ) as $block ) {
			$out[] = [
				'sitewide' => $block->isSitewide(),
				'expiry' => $block->getExpiry() === 'infinity'
					? 'infinity'
					: CommentSerializer::iso( ConvertibleTimestamp::convert( TS_MW, $block->getExpiry() ) ),
				'reason' => $block->getReasonComment()->text,
				'by' => $block->getBlocker() ? $block->getBlocker()->getName() : null,
			];
		}
		return $out;
	}

	/**
	 * Recent entries of GlowThread's log (and FlowThread's, see GlowThreadConfig::getHistoryLogTypes()).
	 * Fields hidden with revision deletion or suppression are null unless the viewer may see them,
	 * as in Special:Log.
	 *
	 * @param IReadableDatabase $db
	 * @param Viewer $viewer
	 * @param array $conds Either log_page, or ls_field and ls_value
	 * @param bool $byAuthor The entries are selected by comment author: leave out entries about
	 *   comments that were erased since, unless the viewer can erase, and entries about comments
	 *   on pages whose comments the viewer may not see
	 * @param array<int,bool> &$visible With $byAuthor: canSeePages() answers known so far,
	 *   extended with the pages checked here
	 * @return array[]
	 */
	private function getLogEntries(
		IReadableDatabase $db,
		Viewer $viewer,
		array $conds,
		bool $byAuthor = false,
		array &$visible = []
	): array {
		$qb = DatabaseLogEntry::newSelectQueryBuilder( $db )
			->field( 'log_page' )
			->where( [ 'log_type' => $this->config->getHistoryLogTypes() ] )
			->orderBy( 'log_id', SelectQueryBuilder::SORT_DESC )
			->limit( self::LOG_LIMIT )
			->caller( __METHOD__ );
		if ( isset( $conds['ls_field'] ) ) {
			$qb->join( 'log_search', null, 'ls_log_id = log_id' );
		}
		$qb->andWhere( $conds );
		$authority = $viewer->authority;
		$logEntries = [];
		$commentIds = [];
		$pageIds = [];
		foreach ( $qb->fetchResultSet() as $row ) {
			$entry = DatabaseLogEntry::newFromRow( $row );
			$logEntries[] = $entry;
			$params = $entry->getParameters();
			if ( isset( $params['4::comment'] ) ) {
				$commentIds[] = (int)$params['4::comment'];
			}
			$pageIds[$entry->getId()] = (int)$row->log_page;
		}
		$erased = [];
		if ( $byAuthor ) {
			$posts = $this->postStore->getByIds( $commentIds );
			foreach ( $posts as $post ) {
				if ( $post->isErased() && !$viewer->canErase ) {
					$erased[$post->getId()] = true;
				}
			}
			// The page of an entry is its comment's page now: comments follow their page through
			// moves and undeletions, and entries made after a deletion have no log_page
			foreach ( $logEntries as $entry ) {
				$commentId = (int)( $entry->getParameters()['4::comment'] ?? 0 );
				if ( isset( $posts[$commentId] ) ) {
					$pageIds[$entry->getId()] = $posts[$commentId]->getPageId();
				}
			}
			$visible += $this->canSee( $viewer, array_diff( array_filter( $pageIds ), array_keys( $visible ) ) );
		}
		$entries = [];
		foreach ( $logEntries as $entry ) {
			$params = $entry->getParameters();
			$commentId = isset( $params['4::comment'] ) ? (int)$params['4::comment'] : null;
			if ( $commentId !== null && isset( $erased[$commentId] ) ) {
				continue;
			}
			if ( !( $visible[$pageIds[$entry->getId()]] ?? true ) ) {
				continue;
			}
			$bits = $entry->getDeleted();
			$canSeeAction = LogEventsList::userCanBitfield( $bits, LogPage::DELETED_ACTION, $authority );
			$entries[] = [
				'id' => $entry->getId(),
				'action' => $entry->getSubtype(),
				'timestamp' => CommentSerializer::iso( $entry->getTimestamp() ),
				'performer' => LogEventsList::userCanBitfield( $bits, LogPage::DELETED_USER, $authority )
					? $entry->getPerformerIdentity()->getName() : null,
				'commentId' => $canSeeAction ? $commentId : null,
				'seq' => $canSeeAction && isset( $params['6::seq'] ) ? (int)$params['6::seq'] : null,
				'reason' => LogEventsList::userCanBitfield( $bits, LogPage::DELETED_COMMENT, $authority )
					? $entry->getComment() : null,
			];
		}
		return $entries;
	}
}
