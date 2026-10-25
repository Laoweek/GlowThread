<?php

namespace MediaWiki\Extension\GlowThread\Compat;

use MediaWiki\Extension\GlowThread\GlowThreadConfig;
use MediaWiki\Extension\GlowThread\Model\PageState;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Model\PostStatus;
use MediaWiki\Extension\GlowThread\Policy\PageEligibility;
use MediaWiki\Extension\GlowThread\Query\ActorInfoLookup;
use MediaWiki\Extension\GlowThread\Query\CommentSerializer;
use MediaWiki\Extension\GlowThread\Query\ModerationQueryService;
use MediaWiki\Extension\GlowThread\Query\Viewer;
use MediaWiki\Extension\GlowThread\Store\PageStateStore;
use MediaWiki\Extension\GlowThread\Store\PostStore;
use MediaWiki\Extension\GlowThread\Text\TextProcessor;
use MediaWiki\Page\PageStore;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Title\TitleFactory;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\User\ActorStore;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\LikeValue;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Comment lists in the shape of FlowThread's action=flowthread API (type=list and
 * type=listall), read from GlowThread's tables. Read only.
 *
 * A comment in that shape: id (the FlowThread ID of imported comments, the GlowThread ID as a
 * string for new ones), userid (0 for IP and hidden authors), username, text (HTML built from
 * segments, fully escaped), timestamp (Unix seconds), parentid (id of the comment it is shown
 * under, '' for top-level comments), like (count), myatt (0 none, 1 liked, 2 reported).
 */
class LegacyListService {

	/** Replies loaded for one list page */
	public const MAX_REPLIES = 300;
	/** Text bytes of the replies of one list page; replies beyond it are left out */
	public const MAX_REPLY_TEXT_BYTES = 262144;
	/**
	 * Rows listall reads at most for one request. More than the deepest offset plus the largest
	 * page of the API module, so it only cuts lists where many comments are on hidden pages.
	 */
	private const MAX_SCAN = 20000;

	/** FlowThread's status codes, for listall */
	private const LEGACY_STATUS = [
		PostStatus::VISIBLE => 0,
		PostStatus::DELETED => 1,
		PostStatus::ERASED => 1,
		PostStatus::HELD => 2,
	];

	public function __construct(
		private readonly GlowThreadConfig $config,
		private readonly PostStore $postStore,
		private readonly PageStateStore $pageStateStore,
		private readonly CommentSerializer $serializer,
		private readonly ActorStore $actorStore,
		private readonly ActorInfoLookup $actorInfoLookup,
		private readonly PageStore $pageStore,
		private readonly TitleFactory $titleFactory,
		private readonly TitleFormatter $titleFormatter,
		private readonly PageEligibility $pageEligibility
	) {
	}

	/**
	 * type=list: visible top-level comments of a page, newest first, each followed (after all
	 * top-level ones) by every visible reply shown under it, parents before children.
	 *
	 * @param Viewer $viewer
	 * @param ProperPageIdentity $page A page the viewer may read
	 * @param int $offset Top-level comments to skip
	 * @param int $limit Top-level comments to return
	 * @param string $hiddenName Shown instead of hidden author names
	 * @return array{posts:array[],popular:array[],count:int}
	 */
	public function getPageList(
		Viewer $viewer,
		ProperPageIdentity $page,
		int $offset,
		int $limit,
		string $hiddenName
	): array {
		$pageId = $page->getId();
		// As if there were no comments where the article does not show them: comments turned
		// off, or a page where comments do not appear (PageEligibility)
		if ( $this->pageStateStore->getState( $pageId ) === PageState::DISABLED
			|| !$this->pageEligibility->isEligible( $page )
		) {
			return [ 'posts' => [], 'popular' => [], 'count' => 0 ];
		}
		$db = $this->postStore->getReplica();
		$topLevel = [ 'gtp_page' => $pageId, 'gtp_status' => PostStatus::VISIBLE, 'gtp_depth' => 0 ];
		$count = (int)$db->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( PostStore::TABLE )
			->where( $topLevel )
			->caller( __METHOD__ )
			->fetchField();
		$top = $this->records( $this->postStore->newSelectQueryBuilder( $db )
			->where( $topLevel )
			->orderBy( [ 'gtp_created', 'gtp_id' ], SelectQueryBuilder::SORT_DESC )
			->offset( $offset )
			->limit( $limit )
			->caller( __METHOD__ )
			->fetchResultSet() );

		$replies = [];
		if ( $top ) {
			$listed = [];
			foreach ( $top as $post ) {
				$listed[$post->getId()] = true;
			}
			$candidates = $this->records( $this->postStore->newSelectQueryBuilder( $db )
				->where( [
					'gtp_thread' => array_keys( $listed ),
					'gtp_status' => PostStatus::VISIBLE,
				] )
				->orderBy( [ 'gtp_depth', 'gtp_created', 'gtp_id' ], SelectQueryBuilder::SORT_ASC )
				->limit( self::MAX_REPLIES )
				->caller( __METHOD__ )
				->fetchResultSet() );
			// Depth 1 comes before depth 2, so a reply is kept only after its display parent was
			// kept. Replies under a removed comment are dropped, as old clients need the parent.
			// The page stops at MAX_REPLY_TEXT_BYTES of reply text, so one busy page cannot make
			// the uncached legacy route send megabytes.
			$bytes = 0;
			foreach ( $candidates as $reply ) {
				$bytes += strlen( $reply->getText() );
				if ( $bytes > self::MAX_REPLY_TEXT_BYTES ) {
					break;
				}
				if ( isset( $listed[$reply->getParentId()] ) ) {
					$replies[] = $reply;
					$listed[$reply->getId()] = true;
				}
			}
		}
		$popular = $this->fetchPopular( $db, $pageId );

		$posts = array_merge( $top, $replies );
		$items = $this->toLegacy( array_merge( $posts, $popular ), $viewer, $hiddenName, false );
		return [
			'posts' => array_map( static fn ( PostRecord $p ) => $items[$p->getId()], $posts ),
			'popular' => array_map( static fn ( PostRecord $p ) => $items[$p->getId()], $popular ),
			'count' => $count,
		];
	}

	/**
	 * type=listall, for moderators: comments of the pages whose comments they may see
	 * (ModerationQueryService::canSeePages()) as a flat list, newest first.
	 *
	 * @param Viewer $viewer A moderator
	 * @param array{filter?:?string,pageId?:?int,user?:?string,keyword?:?string} $filters
	 *   filter: all, deleted, spam (held), reported, or null for visible comments
	 * @param bool $newer Oldest first instead
	 * @param int $offset
	 * @param int $limit
	 * @param string $hiddenName Shown instead of hidden author names
	 * @return array{more:bool,posts:array[]}
	 */
	public function getAll(
		Viewer $viewer,
		array $filters,
		bool $newer,
		int $offset,
		int $limit,
		string $hiddenName
	): array {
		$db = $this->postStore->getReplica();
		$qb = $db->newSelectQueryBuilder()
			->select( [ 'gtp_id', 'gtp_page' ] )
			->from( PostStore::TABLE )
			->orderBy( 'gtp_id', $newer ? SelectQueryBuilder::SORT_ASC : SelectQueryBuilder::SORT_DESC )
			->caller( __METHOD__ );
		switch ( $filters['filter'] ?? null ) {
			case 'all':
				break;
			case 'deleted':
				$qb->andWhere( [ 'gtp_status' => [ PostStatus::DELETED, PostStatus::ERASED ] ] );
				break;
			case 'spam':
				$qb->andWhere( [ 'gtp_status' => PostStatus::HELD ] );
				break;
			case 'reported':
				$qb->andWhere( [
					'gtp_status' => [ PostStatus::VISIBLE, PostStatus::HELD ],
					$db->expr( 'gtp_open_report_count', '>', 0 ),
				] );
				break;
			default:
				$qb->andWhere( [ 'gtp_status' => PostStatus::VISIBLE ] );
		}
		if ( !empty( $filters['pageId'] ) ) {
			$qb->andWhere( [ 'gtp_page' => (int)$filters['pageId'] ] );
		}
		if ( ( $filters['user'] ?? '' ) !== '' ) {
			$actorId = $this->actorStore->findActorIdByName( (string)$filters['user'], $db );
			$info = $actorId ? ( $this->actorInfoLookup->getInfo( [ $actorId ] )[$actorId] ?? null ) : null;
			// A hidden user is answered like an unknown one, and erased comments are tied to
			// their author only for holders of glowthread-erase (as in the moderation queue).
			if ( !$info || ( $info['hidden'] && !$viewer->canSeeHiddenUsers ) ) {
				return [ 'more' => false, 'posts' => [] ];
			}
			$qb->andWhere( [ 'gtp_actor' => $actorId ] );
			if ( !$viewer->canErase ) {
				$qb->andWhere( $db->expr( 'gtp_status', '!=', PostStatus::ERASED ) );
			}
		}
		if ( ( $filters['keyword'] ?? '' ) !== '' ) {
			$keyword = (string)$filters['keyword'];
			$condition = $db->expr(
				'gtp_text',
				IExpression::LIKE,
				new LikeValue( $db->anyString(), $keyword, $db->anyString() )
			);
			// A user's name also finds the comments that mention them, stored as <@ID>
			$mentioned = ModerationQueryService::findMentionedUserId(
				$this->actorStore, $this->actorInfoLookup, $db, $keyword, $viewer
			);
			if ( $mentioned ) {
				$condition = $db->orExpr( [ $condition, $db->expr(
					'gtp_text',
					IExpression::LIKE,
					new LikeValue( $db->anyString(), TextProcessor::mentionToken( $mentioned ), $db->anyString() )
				) ] );
			}
			$qb->andWhere( $condition );
		}
		// Comments on pages the viewer may not see are left out (type=list refuses unreadable
		// pages), and offset counts only the others, so the list tells nothing about them. The
		// IDs and pages of the rows are read in batches, up to MAX_SCAN rows, then the full
		// comments of the page.
		$visible = [];
		if ( !empty( $filters['pageId'] ) ) {
			$visible = ModerationQueryService::canSeePages(
				$this->pageStore, $db, $viewer->authority, [ (int)$filters['pageId'] ]
			);
			if ( !$visible[(int)$filters['pageId']] ) {
				return [ 'more' => false, 'posts' => [] ];
			}
		}
		$ids = [];
		$skip = $offset;
		$read = 0;
		$lastId = null;
		$batch = min( $offset + $limit + 1, self::MAX_SCAN );
		while ( true ) {
			$round = clone $qb;
			if ( $lastId !== null ) {
				$round->andWhere( $db->expr( 'gtp_id', $newer ? '>' : '<', $lastId ) );
			}
			$rows = iterator_to_array( $round->limit( $batch )->fetchResultSet(), false );
			$visible += ModerationQueryService::canSeePages(
				$this->pageStore,
				$db,
				$viewer->authority,
				array_diff( array_map( static fn ( $row ) => (int)$row->gtp_page, $rows ), array_keys( $visible ) )
			);
			foreach ( $rows as $row ) {
				if ( !$visible[(int)$row->gtp_page] ) {
					continue;
				}
				if ( $skip > 0 ) {
					$skip--;
					continue;
				}
				$ids[] = (int)$row->gtp_id;
				if ( count( $ids ) > $limit ) {
					break 2;
				}
			}
			$read += count( $rows );
			if ( count( $rows ) < $batch || $read >= self::MAX_SCAN ) {
				break;
			}
			$lastId = (int)end( $rows )->gtp_id;
			$batch = min( $batch * 2, self::MAX_SCAN - $read );
		}
		$more = count( $ids ) > $limit;
		$byId = $this->postStore->getByIds( array_slice( $ids, 0, $limit ) );
		$posts = [];
		foreach ( array_slice( $ids, 0, $limit ) as $id ) {
			if ( isset( $byId[$id] ) ) {
				$posts[] = $byId[$id];
			}
		}

		$items = $this->toLegacy( $posts, $viewer, $hiddenName, true );
		return [
			'more' => $more,
			'posts' => array_map( static fn ( PostRecord $p ) => $items[$p->getId()], $posts ),
		];
	}

	/**
	 * Popular comments as FlowThread picked them: most likes first, at least the configured
	 * number of likes, visible with every comment they are shown under visible.
	 *
	 * @return PostRecord[]
	 */
	private function fetchPopular( IReadableDatabase $db, int $pageId ): array {
		$count = $this->config->getPopularCount();
		if ( $count <= 0 ) {
			return [];
		}
		$candidates = $this->records( $this->postStore->newSelectQueryBuilder( $db )
			->where( [
				'gtp_page' => $pageId,
				'gtp_status' => PostStatus::VISIBLE,
				$db->expr( 'gtp_like_count', '>=', $this->config->getPopularMinLikes() ),
			] )
			->orderBy( [ 'gtp_like_count', 'gtp_id' ], SelectQueryBuilder::SORT_DESC )
			->limit( $count * 5 )
			->caller( __METHOD__ )
			->fetchResultSet() );
		$ancestorIds = [];
		foreach ( $candidates as $post ) {
			array_push( $ancestorIds, ...array_filter( [ $post->getParentId(), $post->getThreadId() ] ) );
		}
		$ancestors = $this->postStore->getByIds( $ancestorIds );
		$picks = [];
		foreach ( $candidates as $post ) {
			foreach ( array_filter( [ $post->getParentId(), $post->getThreadId() ] ) as $id ) {
				if ( !isset( $ancestors[$id] ) || !$ancestors[$id]->isVisible() ) {
					continue 2;
				}
			}
			$picks[] = $post;
			if ( count( $picks ) >= $count ) {
				break;
			}
		}
		return $picks;
	}

	/**
	 * @param PostRecord[] $posts
	 * @param Viewer $viewer
	 * @param string $hiddenName
	 * @param bool $withPage Add pageid, title, report and status (listall)
	 * @return array<int,array> FlowThread-shaped comments keyed by GlowThread ID
	 */
	private function toLegacy( array $posts, Viewer $viewer, string $hiddenName, bool $withPage ): array {
		if ( !$posts ) {
			return [];
		}
		$serialized = $this->serializer->serializeAll( $posts, $viewer );
		$ids = $this->legacyIds( $posts );
		$titles = $withPage ? $this->titles( $posts ) : [];
		$userUrl = fn ( string $name ) => (string)$this->titleFactory->makeTitleSafe( NS_USER, $name )?->getLocalURL();
		$out = [];
		foreach ( $posts as $post ) {
			$data = $serialized[$post->getId()];
			$author = $data['author'];
			$item = [
				'id' => $ids[$post->getId()],
				'userid' => $author['hidden'] ? 0 : $author['id'],
				'username' => $author['hidden'] ? $hiddenName : $author['name'],
				'text' => LegacyHtml::fromSegments( $data['segments'], $userUrl ),
				'timestamp' => (int)ConvertibleTimestamp::convert( TS_UNIX, $post->getCreated() ),
				'parentid' => $post->getParentId() === null ? '' : ( $ids[$post->getParentId()] ?? '' ),
				'like' => $post->getLikeCount(),
				'myatt' => $data['viewer']['liked'] ? 1 : ( $data['viewer']['reported'] ? 2 : 0 ),
			];
			if ( $withPage ) {
				$item['pageid'] = $post->getPageId();
				$item['title'] = $titles[$post->getPageId()] ?? null;
				$item['report'] = $post->getOpenReportCount();
				$item['status'] = self::LEGACY_STATUS[$post->getStatus()] ?? 0;
			}
			$out[$post->getId()] = $item;
		}
		return $out;
	}

	/**
	 * IDs in FlowThread's form for the comments and their display parents.
	 *
	 * @param PostRecord[] $posts
	 * @return array<int,string> GlowThread ID => FlowThread hex ID, or the GlowThread ID as a string
	 */
	private function legacyIds( array $posts ): array {
		$byId = [];
		foreach ( $posts as $post ) {
			$byId[$post->getId()] = $post;
		}
		$missing = [];
		foreach ( $posts as $post ) {
			if ( $post->getParentId() !== null && !isset( $byId[$post->getParentId()] ) ) {
				$missing[] = $post->getParentId();
			}
		}
		$byId += $this->postStore->getByIds( $missing );
		$ids = [];
		foreach ( $byId as $id => $post ) {
			$ids[$id] = $post->getLegacyId() ?? (string)$id;
		}
		return $ids;
	}

	/**
	 * @param PostRecord[] $posts
	 * @return array<int,string> Page ID => prefixed title, for pages that exist
	 */
	private function titles( array $posts ): array {
		$pageIds = array_values( array_unique( array_map( static fn ( PostRecord $p ) => $p->getPageId(), $posts ) ) );
		$titles = [];
		$records = $this->pageStore->newSelectQueryBuilder()
			->wherePageIds( $pageIds )
			->caller( __METHOD__ )
			->fetchPageRecords();
		foreach ( $records as $record ) {
			$titles[$record->getId()] = $this->titleFormatter->getPrefixedText( $record );
		}
		return $titles;
	}

	/**
	 * @param iterable $res
	 * @return PostRecord[]
	 */
	private function records( iterable $res ): array {
		$posts = [];
		foreach ( $res as $row ) {
			$posts[] = PostRecord::newFromRow( $row );
		}
		return $posts;
	}
}
