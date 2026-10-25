<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Query;

use MediaWiki\Extension\GlowThread\GlowThreadConfig;
use MediaWiki\Extension\GlowThread\Model\PageState;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Model\PostStatus;
use MediaWiki\Extension\GlowThread\Policy\PageEligibility;
use MediaWiki\Extension\GlowThread\Policy\WriteGuard;
use MediaWiki\Extension\GlowThread\Query\CommentSerializer;
use MediaWiki\Extension\GlowThread\Query\Cursor;
use MediaWiki\Extension\GlowThread\Query\HotRanker;
use MediaWiki\Extension\GlowThread\Query\Viewer;
use MediaWiki\Extension\GlowThread\Query\ViewerFactory;
use MediaWiki\Extension\GlowThread\Settings\SettingsService;
use MediaWiki\Extension\GlowThread\Store\PageStateStore;
use MediaWiki\Extension\GlowThread\Store\PostStore;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Title\TitleFormatter;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\ReadOnlyMode;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Test oracle: the read side as CommentQueryService did it before ListCache (commit 318794a).
 * Tests compare the lists of the current code with the lists this class reads from the
 * database. Its popular block cuts candidates with equal like counts as the query plan gives
 * them (the current code takes the newest).
 *
 * The read side: page lists (hot and new), pinned and popular comments, two visible reply
 * levels with previews, single comments, threads and reply pages.
 *
 * Nothing here writes to the database or touches the session, so anonymous responses stay
 * cacheable.
 *
 * Lists load only the replies they return: the first replyPreview children of each comment
 * (the database numbers and counts the children of each parent with window functions, so
 * MySQL 8.0, MariaDB 10.2, SQLite 3.25 or PostgreSQL is needed), plus their counts.
 */
class BaseCommentQueryService {

	public const SORT_HOT = 'hot';
	public const SORT_NEW = 'new';
	public const SORTS = [ self::SORT_HOT, self::SORT_NEW ];

	/** Upper bound of top-level comments ranked for hot sort */
	private const HOT_CANDIDATE_LIMIT = 5000;
	/** Deepest offset hot sort pages to */
	public const HOT_MAX_OFFSET = 1000;
	/** Replies loaded for one thread view */
	public const THREAD_LIMIT = 500;
	/** Pinned comments shown */
	private const PINNED_LIMIT = 10;
	/** Popular candidates considered */
	private const POPULAR_CANDIDATES = 500;

	public function __construct(
		private readonly GlowThreadConfig $config,
		private readonly PostStore $postStore,
		private readonly PageStateStore $pageStateStore,
		private readonly PageEligibility $pageEligibility,
		private readonly CommentSerializer $serializer,
		private readonly HotRanker $hotRanker,
		private readonly SettingsService $settings,
		private readonly ViewerFactory $viewerFactory,
		private readonly TitleFormatter $titleFormatter,
		private readonly WriteGuard $guard,
		private readonly ReadOnlyMode $readOnlyMode
	) {
	}

	public function newViewer( Authority $authority ): Viewer {
		return $this->viewerFactory->newViewer( $authority );
	}

	/**
	 * The page if it exists and the reader may read it.
	 */
	public function getPageForRead( int $pageId, Authority $authority ): ?ProperPageIdentity {
		$page = $this->pageEligibility->getPage( $pageId );
		if ( !$page || !$authority->authorizeRead( 'read', $page ) ) {
			return null;
		}
		return $page;
	}

	/**
	 * What the viewer may do on a page, for the viewer block of the list response and the
	 * per-comment flags. These are hints for the UI. The write modules check again.
	 *
	 * For logged-in viewers the hints follow rights, blocks (partial blocks only on the pages
	 * they cover) and read-only mode, but not rate limits: a throttled writer still has the
	 * right, and the write module answers ratelimited with the wait. Nothing here counts toward
	 * a rate limit.
	 *
	 * @return array<string,mixed>
	 */
	public function getPagePermissions( Viewer $viewer, ProperPageIdentity $page, string $state ): array {
		$authority = $viewer->authority;
		$eligible = $this->pageEligibility->isEligible( $page );
		$readOnly = !$viewer->isAnon() && $this->readOnlyMode->isReadOnly();
		$blockedReason = null;
		if ( !$eligible ) {
			$blockedReason = 'ineligible';
		} elseif ( $state === PageState::DISABLED ) {
			$blockedReason = 'disabled';
		} elseif ( $readOnly ) {
			$blockedReason = 'readonly';
		} elseif ( $state === PageState::LOCKED ) {
			$blockedReason = 'locked';
		}
		if ( $viewer->isAnon() ) {
			// The same answer for every anonymous reader, so the response can be cached.
			$can = static fn ( string $right ) => $authority->isAllowed( $right );
		} else {
			$guard = $this->guard;
			$can = static fn ( string $right ) => !$readOnly
				&& $guard->isAllowedIgnoringRateLimit( $authority, $right, $page );
		}
		$canPostRight = $can( 'glowthread-post' );
		if ( $blockedReason === null && !$canPostRight ) {
			if ( $viewer->isAnon() ) {
				$blockedReason = 'login';
			} elseif ( $authority->getBlock() ) {
				$blockedReason = 'blocked';
			} else {
				$blockedReason = 'rights';
			}
		}
		$open = $eligible && $state !== PageState::DISABLED;
		$canReact = $open && $can( 'glowthread-react' );
		// Accounts the new-account gate would hold cannot like: their likes would rank comments.
		// Only the reason when nothing else (page, rights, block) already stops the like.
		$reactGated = $canReact && !$viewer->isAnon()
			&& $this->guard->isGatedNewAccount( $authority, $viewer->actorId );
		return [
			'loggedIn' => !$viewer->isAnon(),
			'canPost' => $blockedReason === null,
			'postBlockedReason' => $blockedReason,
			'canReact' => $canReact && !$reactGated,
			'reactBlockedReason' => $reactGated ? 'newaccount' : null,
			'canReport' => $open && $can( 'glowthread-report' ),
			'canModerate' => $viewer->isModerator && !$viewer->isAnon() && $authority->getBlock() === null,
			'canErase' => $viewer->canErase && !$viewer->isAnon() && $authority->getBlock() === null,
			'canConfigure' => !$viewer->isAnon() && $authority->isAllowed( 'glowthread-configure' ),
		];
	}

	/**
	 * The comment list of a page.
	 *
	 * @param Viewer $viewer
	 * @param ProperPageIdentity $page Existing page
	 * @param string $sort hot or new
	 * @param array|null $cursor Decoded cursor (see decodeListCursor), null for the first page
	 * @param int $limit Top-level comments per page
	 * @return array<string,mixed>
	 */
	public function getPageList(
		Viewer $viewer,
		ProperPageIdentity $page,
		string $sort,
		?array $cursor,
		int $limit
	): array {
		$pageId = $page->getId();
		$state = $this->pageStateStore->getState( $pageId );
		$permissions = $this->getPagePermissions( $viewer, $page, $state );
		$pageFlags = [ $pageId => $permissions ];
		$db = $this->postStore->getReplica();
		$firstPage = $cursor === null;

		$response = [
			'page' => [
				'id' => $pageId,
				'title' => $this->titleFormatter->getPrefixedText( $page ),
				'state' => $state,
			],
			'sort' => $sort,
			'viewer' => $permissions,
			'config' => $this->getClientConfig(),
			'count' => 0,
			'pinned' => [],
			'popular' => [],
			'comments' => [],
			'next' => null,
		];
		// Comments are off there (disabled, or a page outside the comment namespaces): readers
		// get no comments, moderators still see them to clean up
		if ( !$this->showsComments( $viewer, $page, $state ) ) {
			return $response;
		}
		$response['count'] = $this->countVisible( $db, $pageId );

		$pinned = $firstPage ? $this->fetchPinned( $db, $viewer, $pageId ) : [];
		if ( $sort === self::SORT_NEW ) {
			[ $main, $next ] = $this->fetchNew( $db, $viewer, $pageId, $cursor, $limit );
		} else {
			[ $main, $next ] = $this->fetchHot( $db, $viewer, $pageId, $cursor, $limit );
		}
		$topLevel = array_merge( $pinned, $main );
		$preview = $this->config->getReplyPreview();
		$level1 = $this->fetchChildren( $db, $viewer, self::ids( $topLevel ), $preview );
		$level2 = $this->fetchChildren( $db, $viewer, self::ids( $level1['posts'] ), $preview );
		$replies = array_merge( $level1['posts'], $level2['posts'] );
		// The stored counters count visible replies, which is what anonymous readers see. Others
		// may also see held (their own) or removed (moderators) replies: count those exactly.
		$grandchildren = $viewer->isAnon()
			? $level1['grandchildren']
			: $this->countGrandchildren( $db, $viewer, self::ids( $topLevel ) );

		$popular = [];
		if ( $firstPage ) {
			$shownIds = array_map( static fn ( $p ) => $p->getId(), array_merge( $topLevel, $replies ) );
			$popular = $this->fetchPopular( $db, $viewer, $pageId, $shownIds );
		}

		$serialized = $this->serializer->serializeAll(
			array_merge( $topLevel, $replies, $popular ), $viewer, $pageFlags
		);
		$trees = $this->buildTrees( $topLevel, $replies, $serialized, $viewer, $preview, [
			'children' => $level1['counts'] + $level2['counts'],
			'grandchildren' => $grandchildren,
		] );
		$response['pinned'] = array_slice( $trees, 0, count( $pinned ) );
		$response['comments'] = array_slice( $trees, count( $pinned ) );
		$response['popular'] = array_map( static fn ( $p ) => $serialized[$p->getId()], $popular );
		$response['next'] = $next;
		return $response;
	}

	/**
	 * Settings the browser needs to validate input and draw the UI.
	 *
	 * @return array<string,mixed>
	 */
	public function getClientConfig(): array {
		return [
			'maxLength' => $this->settings->get( 'maxLength' ),
			'maxMentions' => $this->settings->get( 'maxMentions' ),
			'reportReasons' => $this->settings->get( 'reportReasons' ),
			'pageSize' => $this->config->getPageSize(),
			'replyPreview' => $this->config->getReplyPreview(),
		];
	}

	/**
	 * @param string $sort
	 * @param string $cursor Raw cursor from the request
	 * @return array|null|false Decoded cursor, null for none, false if invalid
	 */
	public function decodeListCursor( string $sort, string $cursor ) {
		if ( $cursor === '' ) {
			return null;
		}
		if ( $sort === self::SORT_NEW ) {
			$data = Cursor::decode( $cursor, 'new', [ 'c' => 'ts', 'i' => 'int' ] );
		} else {
			$data = Cursor::decode( $cursor, 'hot', [ 'n' => 'int', 'o' => 'int' ] );
			if ( $data && $data['o'] > self::HOT_MAX_OFFSET ) {
				return false;
			}
		}
		return $data ?? false;
	}

	/**
	 * A single comment, if the viewer may see it.
	 *
	 * @return array<string,mixed>|null
	 */
	public function getComment( Viewer $viewer, int $id ): ?array {
		$post = $this->postStore->getById( $id );
		return $post ? $this->getCommentFromRecord( $viewer, $post ) : null;
	}

	/**
	 * A single comment by page and floor number, if the viewer may see it.
	 *
	 * @return array<string,mixed>|null
	 */
	public function getCommentByFloor( Viewer $viewer, int $pageId, int $seq ): ?array {
		$post = $this->postStore->getByPageAndSeq( $pageId, $seq );
		return $post ? $this->getCommentFromRecord( $viewer, $post ) : null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function getCommentFromRecord( Viewer $viewer, PostRecord $post ): ?array {
		$context = $this->getVisibleContext( $viewer, $post );
		if ( $context === null ) {
			return null;
		}
		[ $page, $state, $ancestors ] = $context;
		$flags = [ $page->getId() => $this->getPagePermissions( $viewer, $page, $state ) ];
		$data = $this->serializer->serializeAllWithLoaded( [ $post ], $viewer, $flags, $ancestors )[$post->getId()];
		$data['replyCount'] = $post->getReplyCount();
		return [
			'comment' => $data,
			'page' => [
				'id' => $page->getId(),
				'title' => $this->titleFormatter->getPrefixedText( $page ),
				'state' => $state,
			],
		];
	}

	/**
	 * The whole conversation a comment belongs to: its top-level comment with every reply the
	 * viewer may see (bounded by THREAD_LIMIT).
	 *
	 * @return array<string,mixed>|null
	 */
	public function getThread( Viewer $viewer, int $id ): ?array {
		$post = $this->postStore->getById( $id );
		if ( !$post ) {
			return null;
		}
		$context = $this->getVisibleContext( $viewer, $post );
		if ( !$context ) {
			return null;
		}
		[ $page, $state, $ancestors ] = $context;
		if ( !$post->isTopLevel() ) {
			// The check above loaded the top-level comment and found that the viewer may see it.
			// For moderators, who see every status, it loaded nothing.
			$root = $ancestors[$post->getRootId()] ?? $this->postStore->getById( $post->getRootId() );
			if ( !$root ) {
				return null;
			}
			// A thread stays on its page, so its page and state are the root's. Only a root on
			// another page needs a check of its own.
			if ( $root->getPageId() !== $post->getPageId() ) {
				[ $page, $state ] = $this->getVisibleContext( $viewer, $root ) ?? [ null, null ];
				if ( !$page ) {
					return null;
				}
			}
		} else {
			$root = $post;
		}
		$db = $this->postStore->getReplica();
		$replies = $this->fetchThreadReplies( $db, $viewer, [ $root->getId() ], self::THREAD_LIMIT + 1 );
		$truncated = count( $replies ) > self::THREAD_LIMIT;
		$replies = array_slice( $replies, 0, self::THREAD_LIMIT );
		$flags = [ $page->getId() => $this->getPagePermissions( $viewer, $page, $state ) ];
		$serialized = $this->serializer->serializeAll( array_merge( [ $root ], $replies ), $viewer, $flags );
		$trees = $this->buildTrees( [ $root ], $replies, $serialized, $viewer, PHP_INT_MAX );
		return [
			'page' => [
				'id' => $page->getId(),
				'title' => $this->titleFormatter->getPrefixedText( $page ),
				'state' => $state,
			],
			'focus' => $post->getId(),
			'thread' => $trees[0],
			'truncated' => $truncated,
		];
	}

	/**
	 * Direct (display) children of a comment, oldest first, with cursor paging. Depth-1
	 * children carry a preview of their own replies.
	 *
	 * @return array<string,mixed>|null|false Null if the comment is not visible, false for a bad cursor
	 */
	public function getReplies( Viewer $viewer, int $id, string $cursor, int $limit ) {
		$post = $this->postStore->getById( $id );
		if ( !$post ) {
			return null;
		}
		$context = $this->getVisibleContext( $viewer, $post );
		if ( !$context ) {
			return null;
		}
		[ $page, $state ] = $context;
		$after = null;
		if ( $cursor !== '' ) {
			$after = Cursor::decode( $cursor, 'replies', [ 'c' => 'ts', 'i' => 'int' ] );
			if ( !$after ) {
				return false;
			}
		}
		$db = $this->postStore->getReplica();
		$qb = $this->postStore->newSelectQueryBuilder( $db )
			->where( [ 'gtp_parent' => $post->getId() ] )
			->andWhere( $this->statusCondition( $db, $viewer ) )
			->orderBy( [ 'gtp_created', 'gtp_id' ], SelectQueryBuilder::SORT_ASC )
			->limit( $limit + 1 )
			->caller( __METHOD__ );
		if ( $after ) {
			$ts = $db->timestamp( $after['c'] );
			$qb->andWhere( $db->orExpr( [
				$db->expr( 'gtp_created', '>', $ts ),
				$db->expr( 'gtp_created', '=', $ts )->and( 'gtp_id', '>', $after['i'] ),
			] ) );
		}
		$children = $this->recordsFromResult( $qb->fetchResultSet() );
		$next = null;
		if ( count( $children ) > $limit ) {
			$children = array_slice( $children, 0, $limit );
			$last = end( $children );
			$next = Cursor::encode( 'replies', [ 'c' => $last->getCreated(), 'i' => $last->getId() ] );
		}
		$preview = $this->config->getReplyPreview();
		$grandchildren = [ 'posts' => [], 'counts' => [] ];
		if ( $post->isTopLevel() && $children ) {
			$grandchildren = $this->fetchChildren( $db, $viewer, self::ids( $children ), $preview );
		}
		$flags = [ $page->getId() => $this->getPagePermissions( $viewer, $page, $state ) ];
		// The replies mostly answer the comment itself, which is loaded already
		$serialized = $this->serializer->serializeAllWithLoaded(
			array_merge( $children, $grandchildren['posts'] ), $viewer, $flags, [ $post ]
		);
		$byParent = [];
		foreach ( $grandchildren['posts'] as $g ) {
			$byParent[$g->getParentId()][] = $g;
		}
		$items = [];
		foreach ( $children as $child ) {
			$item = $serialized[$child->getId()];
			$item['replyCount'] = $grandchildren['counts'][$child->getId()] ?? 0;
			$item['replies'] = array_map(
				static fn ( $g ) => $serialized[$g->getId()] + [ 'replyCount' => 0, 'replies' => [] ],
				$byParent[$child->getId()] ?? []
			);
			$items[] = $item;
		}
		return [ 'parentId' => $post->getId(), 'replies' => $items, 'next' => $next ];
	}

	/**
	 * Whether the viewer gets the page's comments: not on a disabled page, and not on a page
	 * where comments do not appear (PageEligibility, the rule the article view uses), except
	 * for moderators.
	 */
	private function showsComments( Viewer $viewer, ProperPageIdentity $page, string $state ): bool {
		return $viewer->isModerator
			|| ( $state !== PageState::DISABLED && $this->pageEligibility->isEligible( $page ) );
	}

	/**
	 * The page and its state if the viewer may see the comment there, else null. Checks the
	 * comment's status, its ancestors' statuses, the page state and the page's eligibility.
	 *
	 * @return array{0:ProperPageIdentity,1:string,2:array<int,PostRecord>}|null The third item
	 *   holds the ancestors the check loaded, by ID (none for moderators), for reuse
	 */
	public function getVisibleContext( Viewer $viewer, PostRecord $post ): ?array {
		$page = $this->pageEligibility->getPage( $post->getPageId() );
		if ( !$page || !$viewer->canSeeStatus( $post ) ) {
			return null;
		}
		$state = $this->pageStateStore->getState( $page->getId() );
		if ( !$this->showsComments( $viewer, $page, $state ) ) {
			return null;
		}
		if ( !$viewer->authority->authorizeRead( 'read', $page ) ) {
			return null;
		}
		$ancestors = [];
		$ancestorIds = array_filter( [ $post->getParentId(), $post->getThreadId() ] );
		if ( $ancestorIds && !$viewer->isModerator ) {
			$ancestors = $this->postStore->getByIds( $ancestorIds );
			foreach ( array_unique( $ancestorIds ) as $aid ) {
				if ( !isset( $ancestors[$aid] ) || !$viewer->canSeeStatus( $ancestors[$aid] ) ) {
					return null;
				}
			}
		}
		return [ $page, $state, $ancestors ];
	}

	/**
	 * Comments readers can see on a page: visible, with every ancestor visible.
	 */
	public function countVisible( IReadableDatabase $db, int $pageId ): int {
		return (int)$db->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( PostStore::TABLE, 'p' )
			->leftJoin( PostStore::TABLE, 'r', 'r.gtp_id = p.gtp_thread' )
			->leftJoin( PostStore::TABLE, 'a', 'a.gtp_id = p.gtp_parent' )
			->where( [
				'p.gtp_page' => $pageId,
				'p.gtp_status' => PostStatus::VISIBLE,
				$db->orExpr( [
					$db->expr( 'p.gtp_thread', '=', null ),
					$db->expr( 'r.gtp_status', '=', PostStatus::VISIBLE ),
				] ),
				$db->orExpr( [
					$db->expr( 'p.gtp_parent', '=', null ),
					$db->expr( 'a.gtp_status', '=', PostStatus::VISIBLE ),
				] ),
			] )
			->caller( __METHOD__ )
			->fetchField();
	}

	/**
	 * SQL condition on gtp_status for what this viewer may see.
	 *
	 * @return array|\Wikimedia\Rdbms\IExpression
	 */
	public function statusCondition( IReadableDatabase $db, Viewer $viewer, string $prefix = '' ) {
		if ( $viewer->isModerator ) {
			return [];
		}
		if ( $viewer->actorId ) {
			return $db->orExpr( [
				$db->expr( "{$prefix}gtp_status", '=', PostStatus::VISIBLE ),
				$db->expr( "{$prefix}gtp_status", '=', PostStatus::HELD )
					->and( "{$prefix}gtp_actor", '=', $viewer->actorId ),
			] );
		}
		return [ "{$prefix}gtp_status" => PostStatus::VISIBLE ];
	}

	/**
	 * @return PostRecord[]
	 */
	private function fetchPinned( IReadableDatabase $db, Viewer $viewer, int $pageId ): array {
		return $this->recordsFromResult( $this->postStore->newSelectQueryBuilder( $db )
			->where( [
				'gtp_page' => $pageId,
				'gtp_depth' => 0,
				$db->expr( 'gtp_pinned', '!=', null ),
			] )
			->andWhere( $this->statusCondition( $db, $viewer ) )
			->orderBy( [ 'gtp_pinned', 'gtp_id' ], SelectQueryBuilder::SORT_DESC )
			->limit( self::PINNED_LIMIT )
			->caller( __METHOD__ )
			->fetchResultSet() );
	}

	/**
	 * Newest first, keyset paging on (created, id).
	 *
	 * @return array{0:PostRecord[],1:?string}
	 */
	private function fetchNew( IReadableDatabase $db, Viewer $viewer, int $pageId, ?array $cursor, int $limit ): array {
		$qb = $this->postStore->newSelectQueryBuilder( $db )
			->where( [
				'gtp_page' => $pageId,
				'gtp_depth' => 0,
				'gtp_pinned' => null,
			] )
			->andWhere( $this->statusCondition( $db, $viewer ) )
			->orderBy( [ 'gtp_created', 'gtp_id' ], SelectQueryBuilder::SORT_DESC )
			->limit( $limit + 1 )
			->caller( __METHOD__ );
		if ( $cursor ) {
			$ts = $db->timestamp( $cursor['c'] );
			$qb->andWhere( $db->orExpr( [
				$db->expr( 'gtp_created', '<', $ts ),
				$db->expr( 'gtp_created', '=', $ts )->and( 'gtp_id', '<', $cursor['i'] ),
			] ) );
		}
		$posts = $this->recordsFromResult( $qb->fetchResultSet() );
		$next = null;
		if ( count( $posts ) > $limit ) {
			$posts = array_slice( $posts, 0, $limit );
			$last = end( $posts );
			$next = Cursor::encode( 'new', [ 'c' => $last->getCreated(), 'i' => $last->getId() ] );
		}
		return [ $posts, $next ];
	}

	/**
	 * Hot sort: rank every top-level comment of the page (bounded) by decayed likes, then page
	 * by offset. The cursor keeps the time the ranking was computed for, so pages agree.
	 *
	 * The candidates are read through the page index (gtp_page_status_depth_created). Left to
	 * itself, MariaDB may walk gtp_status_id in ID order over the whole table to skip a sort, and
	 * read every visible comment of the wiki. MySQL and MariaDB take the hint, the others ignore it.
	 *
	 * @return array{0:PostRecord[],1:?string}
	 */
	private function fetchHot( IReadableDatabase $db, Viewer $viewer, int $pageId, ?array $cursor, int $limit ): array {
		$now = $cursor ? $cursor['n'] : ConvertibleTimestamp::time();
		$offset = $cursor ? $cursor['o'] : 0;
		$res = $db->newSelectQueryBuilder()
			->select( [ 'gtp_id', 'gtp_like_count', 'gtp_created' ] )
			->from( PostStore::TABLE )
			->where( [
				'gtp_page' => $pageId,
				'gtp_depth' => 0,
				'gtp_pinned' => null,
			] )
			->andWhere( $this->statusCondition( $db, $viewer ) )
			->useIndex( 'gtp_page_status_depth_created' )
			->orderBy( 'gtp_id', SelectQueryBuilder::SORT_DESC )
			->limit( self::HOT_CANDIDATE_LIMIT )
			->caller( __METHOD__ )
			->fetchResultSet();
		$candidates = [];
		foreach ( $res as $row ) {
			$created = (string)$row->gtp_created;
			$candidates[] = [
				'id' => (int)$row->gtp_id,
				'likes' => (int)$row->gtp_like_count,
				// Already TS_MW in MySQL, MariaDB and SQLite: skip the conversion for thousands of rows
				'created' => strlen( $created ) === 14 && ctype_digit( $created ) && $created[0] !== '0'
					? $created
					: ConvertibleTimestamp::convert( TS_MW, $created ),
			];
		}
		$ranked = $this->hotRanker->rank( $candidates, $now );
		$pageIds = array_slice( $ranked, $offset, $limit );
		$records = $this->postStore->getByIds( $pageIds );
		$posts = [];
		foreach ( $pageIds as $id ) {
			if ( isset( $records[$id] ) ) {
				$posts[] = $records[$id];
			}
		}
		$next = null;
		$nextOffset = $offset + $limit;
		if ( count( $ranked ) > $nextOffset && $nextOffset <= self::HOT_MAX_OFFSET ) {
			$next = Cursor::encode( 'hot', [ 'n' => $now, 'o' => $nextOffset ] );
		}
		return [ $posts, $next ];
	}

	/**
	 * The first $limit display children of each parent that the viewer may see, oldest first,
	 * with counts, in one query. The database numbers and counts the children of each parent
	 * (window functions), so only the rows that are returned reach PHP.
	 *
	 * @param IReadableDatabase $db
	 * @param Viewer $viewer
	 * @param int[] $parentIds
	 * @param int $limit Children kept per parent
	 * @return array{posts:PostRecord[],counts:array<int,int>,grandchildren:array<int,int>}
	 *   posts: the kept children, by parent then oldest first. counts: parent ID => children the
	 *   viewer may see. grandchildren: parent ID => sum of those children's stored reply counts
	 *   (visible replies under them).
	 */
	private function fetchChildren( IReadableDatabase $db, Viewer $viewer, array $parentIds, int $limit ): array {
		$out = [ 'posts' => [], 'counts' => [], 'grandchildren' => [] ];
		if ( !$parentIds ) {
			return $out;
		}
		// Numbering and counting need only the IDs: the text is read for the kept rows only
		$numbered = $db->newSelectQueryBuilder()
			->select( [
				'gt_id' => 'gtp_id',
				'gt_rank' => 'ROW_NUMBER() OVER (PARTITION BY gtp_parent ORDER BY gtp_created, gtp_id)',
				'gt_children' => 'COUNT(*) OVER (PARTITION BY gtp_parent)',
				'gt_grandchildren' => 'SUM(gtp_reply_count) OVER (PARTITION BY gtp_parent)',
			] )
			->from( PostStore::TABLE )
			->where( [ 'gtp_parent' => array_values( array_unique( $parentIds ) ) ] )
			->andWhere( $this->statusCondition( $db, $viewer ) )
			->caller( __METHOD__ );
		$res = $db->newSelectQueryBuilder()
			->select( array_merge( PostStore::FIELDS, [ 'gt_rank', 'gt_children', 'gt_grandchildren' ] ) )
			// Keep one row of each parent even when no child is kept, for its counts
			->from( $numbered, 'gt_numbered' )
			->join( PostStore::TABLE, null, 'gtp_id = gt_id' )
			->where( $db->expr( 'gt_rank', '<=', max( 1, $limit ) ) )
			->orderBy( [ 'gtp_parent', 'gtp_created', 'gtp_id' ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		foreach ( $res as $row ) {
			$parentId = (int)$row->gtp_parent;
			$out['counts'][$parentId] = (int)$row->gt_children;
			$out['grandchildren'][$parentId] = (int)$row->gt_grandchildren;
			if ( (int)$row->gt_rank <= $limit ) {
				$out['posts'][] = PostRecord::newFromRow( $row );
			}
		}
		return $out;
	}

	/**
	 * @return PostRecord[] Oldest first
	 */
	private function fetchThreadReplies( IReadableDatabase $db, Viewer $viewer, array $threadIds, int $limit ): array {
		return $this->recordsFromResult( $this->postStore->newSelectQueryBuilder( $db )
			->where( [ 'gtp_thread' => $threadIds ] )
			->andWhere( $this->statusCondition( $db, $viewer ) )
			->orderBy( [ 'gtp_created', 'gtp_id' ], SelectQueryBuilder::SORT_ASC )
			->limit( $limit )
			->caller( __METHOD__ )
			->fetchResultSet() );
	}

	/**
	 * Replies the viewer may see at the second level of each thread, under a first-level reply
	 * the viewer may see, in one grouped query.
	 *
	 * @param IReadableDatabase $db
	 * @param Viewer $viewer
	 * @param int[] $threadIds
	 * @return array<int,int> Thread ID => count
	 */
	private function countGrandchildren( IReadableDatabase $db, Viewer $viewer, array $threadIds ): array {
		if ( !$threadIds ) {
			return [];
		}
		$res = $db->newSelectQueryBuilder()
			->select( [ 'thread' => 'p.gtp_thread', 'n' => 'COUNT(*)' ] )
			->from( PostStore::TABLE, 'p' )
			->join( PostStore::TABLE, 'a', 'a.gtp_id = p.gtp_parent' )
			->where( [ 'p.gtp_thread' => $threadIds, 'p.gtp_depth' => 2 ] )
			->andWhere( $this->statusCondition( $db, $viewer, 'p.' ) )
			->andWhere( $this->statusCondition( $db, $viewer, 'a.' ) )
			->groupBy( 'p.gtp_thread' )
			->caller( __METHOD__ )
			->fetchResultSet();
		$counts = [];
		foreach ( $res as $row ) {
			$counts[(int)$row->thread] = (int)$row->n;
		}
		return $counts;
	}

	/**
	 * Popular block: the best decayed like scores among visible comments whose ancestors are
	 * visible. Empty when every pick is already shown on the first page.
	 *
	 * @param IReadableDatabase $db
	 * @param Viewer $viewer
	 * @param int $pageId
	 * @param int[] $shownIds Comments already on the first page
	 * @return PostRecord[]
	 */
	private function fetchPopular( IReadableDatabase $db, Viewer $viewer, int $pageId, array $shownIds ): array {
		$count = $this->config->getPopularCount();
		if ( $count <= 0 ) {
			return [];
		}
		// Scored from the rows: only the picks become records
		$candidates = [];
		$ancestorIds = [];
		foreach ( $this->postStore->newSelectQueryBuilder( $db )
			->where( [
				'gtp_page' => $pageId,
				'gtp_status' => PostStatus::VISIBLE,
				'gtp_pinned' => null,
				$db->expr( 'gtp_like_count', '>=', $this->config->getPopularMinLikes() ),
			] )
			->orderBy( 'gtp_like_count', SelectQueryBuilder::SORT_DESC )
			->limit( self::POPULAR_CANDIDATES )
			->caller( __METHOD__ )
			->fetchResultSet() as $row
		) {
			$candidates[] = $row;
			foreach ( [ (int)$row->gtp_parent, (int)$row->gtp_thread ] as $aid ) {
				if ( $aid ) {
					$ancestorIds[] = $aid;
				}
			}
		}
		if ( !$candidates ) {
			return [];
		}
		$visibleAncestors = array_flip( $this->postStore->getVisibleIds( $ancestorIds ) );
		$now = ConvertibleTimestamp::time();
		$rows = [];
		$scores = [];
		$ids = [];
		foreach ( $candidates as $row ) {
			foreach ( [ (int)$row->gtp_parent, (int)$row->gtp_thread ] as $aid ) {
				if ( $aid && !isset( $visibleAncestors[$aid] ) ) {
					continue 2;
				}
			}
			$rows[] = $row;
			$scores[] = $this->hotRanker->popularScore(
				(int)$row->gtp_like_count, (string)$row->gtp_created, $now
			);
			$ids[] = (int)$row->gtp_id;
		}
		// Best score first, then the higher ID
		$order = array_keys( $rows );
		array_multisort( $scores, SORT_DESC, SORT_NUMERIC, $ids, SORT_DESC, SORT_NUMERIC, $order );
		$picks = array_map(
			static fn ( $i ) => PostRecord::newFromRow( $rows[$i] ),
			array_slice( $order, 0, $count )
		);
		$pickIds = array_map( static fn ( $p ) => $p->getId(), $picks );
		if ( !array_diff( $pickIds, $shownIds ) ) {
			return [];
		}
		return $picks;
	}

	/**
	 * Nest replies under their top-level comments: depth 1 under the top-level comment,
	 * depth 2 under its depth-1 parent. Replies whose display parent the viewer cannot see are
	 * dropped.
	 *
	 * @param PostRecord[] $topLevel
	 * @param PostRecord[] $replies Oldest first
	 * @param array<int,array> $serialized
	 * @param Viewer $viewer
	 * @param int $preview Replies kept per level
	 * @param array|null $counts From fetchChildren when $replies are only the previews:
	 *   'children' (comment ID => children the viewer may see) and 'grandchildren' (top-level
	 *   ID => visible replies under its children). Null when $replies are all the replies, which
	 *   are then counted.
	 * @return array[]
	 */
	private function buildTrees(
		array $topLevel,
		array $replies,
		array $serialized,
		Viewer $viewer,
		int $preview,
		?array $counts = null
	): array {
		$children = [];
		foreach ( $replies as $reply ) {
			$children[$reply->getParentId()][] = $reply;
		}
		$trees = [];
		foreach ( $topLevel as $top ) {
			$item = $serialized[$top->getId()];
			$level1 = $children[$top->getId()] ?? [];
			$total = 0;
			$items = [];
			foreach ( $level1 as $index => $reply ) {
				$level2 = $children[$reply->getId()] ?? [];
				$total += count( $level2 );
				if ( $index >= $preview ) {
					continue;
				}
				$child = $serialized[$reply->getId()];
				$child['replyCount'] = $counts === null
					? count( $level2 )
					: ( $counts['children'][$reply->getId()] ?? 0 );
				$child['replies'] = array_map(
					static fn ( $g ) => $serialized[$g->getId()] + [ 'replyCount' => 0, 'replies' => [] ],
					array_slice( $level2, 0, $preview )
				);
				$items[] = $child;
			}
			if ( $counts === null ) {
				$item['replyCount'] = count( $level1 );
				$item['totalReplies'] = count( $level1 ) + $total;
			} else {
				$item['replyCount'] = $counts['children'][$top->getId()] ?? 0;
				$item['totalReplies'] = $item['replyCount'] + ( $counts['grandchildren'][$top->getId()] ?? 0 );
			}
			$item['replies'] = $items;
			$trees[] = $item;
		}
		return $trees;
	}

	/**
	 * @param PostRecord[] $posts
	 * @return int[]
	 */
	private static function ids( array $posts ): array {
		return array_map( static fn ( PostRecord $p ) => $p->getId(), $posts );
	}

	/**
	 * @param iterable $res
	 * @return PostRecord[]
	 */
	private function recordsFromResult( iterable $res ): array {
		$posts = [];
		foreach ( $res as $row ) {
			$posts[] = PostRecord::newFromRow( $row );
		}
		return $posts;
	}
}
