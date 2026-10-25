<?php

namespace MediaWiki\Extension\GlowThread\Query;

use Closure;
use MediaWiki\Extension\GlowThread\GlowThreadConfig;
use MediaWiki\Extension\GlowThread\Model\PageState;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Model\PostStatus;
use MediaWiki\Extension\GlowThread\Policy\PageEligibility;
use MediaWiki\Extension\GlowThread\Policy\WriteGuard;
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
 * The read side: page lists (hot and new), pinned and popular comments, two visible reply
 * levels with previews, single comments, threads and reply pages.
 *
 * Nothing here writes to the database or touches the session, so anonymous responses stay
 * cacheable.
 *
 * Lists load only the replies they return: the first replyPreview children of each comment
 * (the database numbers and counts the children of each parent with window functions, so
 * MySQL 8.0, MariaDB 10.2, SQLite 3.25 or PostgreSQL is needed), plus their counts.
 *
 * What lists show to everyone (the count, the pinned comments, the hot order and the popular
 * picks) comes from a summary of the page that ListCache keeps until the page changes. Each
 * viewer's own held comments are merged in from a small query of their own. Moderators, who see
 * every status, get their pinned and main comments from the database.
 */
class CommentQueryService {

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

	private ?WriteGuard $guard = null;

	/**
	 * @param GlowThreadConfig $config
	 * @param PostStore $postStore
	 * @param PageStateStore $pageStateStore
	 * @param PageEligibility $pageEligibility
	 * @param CommentSerializer $serializer
	 * @param HotRanker $hotRanker
	 * @param SettingsService $settings
	 * @param ViewerFactory $viewerFactory
	 * @param TitleFormatter $titleFormatter
	 * @param Closure $guardProvider Returns the WriteGuard, needed only for logged-in viewers
	 * @param ReadOnlyMode $readOnlyMode
	 * @param ListCache $listCache
	 * @param bool $useSummaries False to read every list from the database (tests compare the two)
	 */
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
		private readonly Closure $guardProvider,
		private readonly ReadOnlyMode $readOnlyMode,
		private readonly ListCache $listCache,
		private readonly bool $useSummaries = true
	) {
	}

	private function getGuard(): WriteGuard {
		$this->guard ??= ( $this->guardProvider )();
		return $this->guard;
	}

	public function newViewer( Authority $authority ): Viewer {
		return $this->viewerFactory->newViewer( $authority );
	}

	/**
	 * The page if it exists and the reader may read it.
	 */
	public function getPageForRead( int $pageId, Authority $authority ): ?ProperPageIdentity {
		return $this->pageEligibility->getPageForRead( $pageId, $authority );
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
			$guard = $this->getGuard();
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
			&& $this->getGuard()->isGatedNewAccount( $authority, $viewer->actorId );
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
	 * @param array|null &$facts When given, set to what ListCache needs to know about the list:
	 *   'hidden' and 'missing', the users it depends on without naming them (see
	 *   CommentSerializer::getLastUsers()), and 'from' and 'until', the Unix times between which
	 *   its order is the order of any time (see HotRanker)
	 * @return array<string,mixed>
	 */
	public function getPageList(
		Viewer $viewer,
		ProperPageIdentity $page,
		string $sort,
		?array $cursor,
		int $limit,
		?array &$facts = null
	): array {
		// Only ListCache asks: finding the users costs another look at every text
		$wantsFacts = func_num_args() > 5;
		$facts = [ 'hidden' => [], 'missing' => [], 'from' => 0, 'until' => PHP_INT_MAX ];
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
		// Without a summary up to date, the list reads the database as before, and a logged-in
		// reader's request makes the summary after the response
		$summary = $this->useSummaries && $this->listCache->mayUse()
			? $this->listCache->getSummary(
				$pageId,
				fn () => $this->buildSummary( $this->postStore->getReplica(), $pageId ),
				!$viewer->isAnon()
			)
			: null;

		$top = null;
		if ( $summary && !$viewer->isModerator ) {
			$top = $this->fetchTopLevelFromSummary( $db, $viewer, $pageId, $summary, $sort, $cursor, $limit );
			if ( $top === null ) {
				// The comments changed since the summary was made, and its purge was missed
				$this->listCache->purgeMissed( $pageId );
				$summary = null;
			}
		}
		if ( $top ) {
			[ $pinned, $main, $next, $loaded ] = $top;
			if ( $sort === self::SORT_HOT ) {
				// A tie among the comments of this page orders them for this time only
				self::narrow( $facts, $summary['hotMax'], ( $cursor['o'] ?? 0 ) + $limit < $summary['hotTie']
					? $summary['hotUntil']
					: ConvertibleTimestamp::time() );
			}
		} else {
			$loaded = [];
			$visibleTo = $this->statusCondition( $db, $viewer );
			$pinned = $firstPage ? $this->fetchPinned( $db, $visibleTo, $pageId ) : [];
			if ( $sort === self::SORT_NEW ) {
				[ $main, $next ] = $this->fetchNew( $db, $viewer, $pageId, $cursor, $limit );
			} elseif ( $wantsFacts ) {
				[ $main, $next ] = $this->fetchHot( $db, $viewer, $pageId, $cursor, $limit, $facts );
			} else {
				[ $main, $next ] = $this->fetchHot( $db, $viewer, $pageId, $cursor, $limit );
			}
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
			$shown = array_merge( $topLevel, $replies );
			$popular = $this->fetchPopular( $db, $pageId, $shown, $loaded, $summary, $facts );
			if ( $popular === null ) {
				// The picks no longer fit the comments: the summary is out of date, its count too
				$this->listCache->purgeMissed( $pageId );
				$summary = null;
				$popular = $this->fetchPopular( $db, $pageId, $shown, [], null, $facts );
			}
		}
		$response['count'] = $summary ? $summary['count'] : $this->countVisible( $db, $pageId );

		$serialized = $this->serializer->serializeAll(
			array_merge( $topLevel, $replies, $popular ), $viewer, $pageFlags
		);
		if ( $wantsFacts ) {
			$facts = $this->serializer->getLastUsers() + $facts;
		}
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
	public static function decodeListCursor( string $sort, string $cursor ) {
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
	 * @param IReadableDatabase $db
	 * @param array|\Wikimedia\Rdbms\IExpression $visibleTo From statusCondition()
	 * @param int $pageId
	 * @return PostRecord[]
	 */
	private function fetchPinned( IReadableDatabase $db, $visibleTo, int $pageId ): array {
		return $this->recordsFromResult( $this->postStore->newSelectQueryBuilder( $db )
			->where( [
				'gtp_page' => $pageId,
				'gtp_depth' => 0,
				$db->expr( 'gtp_pinned', '!=', null ),
			] )
			->andWhere( $visibleTo )
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
	private function fetchHot(
		IReadableDatabase $db, Viewer $viewer, int $pageId, ?array $cursor, int $limit, ?array &$facts = null
	): array {
		$now = $cursor ? $cursor['n'] : ConvertibleTimestamp::time();
		$offset = $cursor ? $cursor['o'] : 0;
		$res = $this->newTopLevelQuery( $db, $pageId, $this->statusCondition( $db, $viewer ) )
			->andWhere( [ 'gtp_pinned' => null ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$candidates = [];
		foreach ( $res as $row ) {
			$candidates[] = self::hotCandidate( $row );
		}
		if ( $facts === null ) {
			$ranked = $this->hotRanker->rank( $candidates, $now );
		} else {
			$ranked = $this->hotRanker->rank( $candidates, $now, $span, $offset + $limit );
			if ( $span ) {
				// A tie among the comments of this page orders them for this time only
				self::narrow( $facts, $span['newest'], $span['tie'] > $offset + $limit
					? $this->hotRanker->getStableUntil( $span['oldest'] )
					: $now );
			}
		}
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
	 * Top-level comments of a page with the columns hot sort ranks by, highest ID first, at most
	 * HOT_CANDIDATE_LIMIT. Read through the page index, see fetchHot().
	 *
	 * @param IReadableDatabase $db
	 * @param int $pageId
	 * @param array|\Wikimedia\Rdbms\IExpression $visibleTo From statusCondition()
	 * @return SelectQueryBuilder
	 */
	private function newTopLevelQuery( IReadableDatabase $db, int $pageId, $visibleTo ): SelectQueryBuilder {
		return $db->newSelectQueryBuilder()
			->select( [ 'gtp_id', 'gtp_like_count', 'gtp_created' ] )
			->from( PostStore::TABLE )
			->where( [
				'gtp_page' => $pageId,
				'gtp_depth' => 0,
			] )
			->andWhere( $visibleTo )
			->useIndex( 'gtp_page_status_depth_created' )
			->orderBy( 'gtp_id', SelectQueryBuilder::SORT_DESC )
			->limit( self::HOT_CANDIDATE_LIMIT );
	}

	/**
	 * @param \stdClass $row From newTopLevelQuery()
	 * @return array{id:int,likes:int,created:string} For HotRanker::rank()
	 */
	private static function hotCandidate( $row ): array {
		$created = (string)$row->gtp_created;
		return [
			'id' => (int)$row->gtp_id,
			'likes' => (int)$row->gtp_like_count,
			// Already TS_MW in MySQL, MariaDB and SQLite: skip the conversion for thousands of rows
			'created' => strlen( $created ) === 14 && ctype_digit( $created ) && $created[0] !== '0'
				? $created
				: ConvertibleTimestamp::convert( TS_MW, $created ),
		];
	}

	/**
	 * Narrow the times between which a list's order holds (getPageList()'s facts) to those of
	 * candidates ranked from $newest on, until $until.
	 *
	 * @param array &$facts
	 * @param int $newest Unix time of the newest candidate
	 * @param int $until From HotRanker::getStableUntil(), PHP_INT_MAX for no candidates
	 */
	private static function narrow( array &$facts, int $newest, int $until ): void {
		$facts['from'] = max( $facts['from'] ?? 0, $newest );
		$facts['until'] = min( $facts['until'] ?? PHP_INT_MAX, $until );
	}

	/**
	 * What the lists of a page show to everyone, computed from the database:
	 * - now: Unix time the order and the popular picks were computed for
	 * - count: as countVisible()
	 * - pinned: visible pinned top-level comments as [ ID, pin time ], as fetchPinned() orders
	 *   them, at most PINNED_LIMIT
	 * - ids, likes, created: the candidates of hot sort (visible, not pinned, top-level, as
	 *   fetchHot() reads them), best first: packed IDs (8 bytes each), packed like counts (4 bytes
	 *   each) and comma-separated TS_MW times
	 * - hotMax: Unix time of the newest candidate (0 for none)
	 * - hotUntil: Unix time from which the order may differ, as the oldest candidate's score
	 *   loses precision (HotRanker::getStableUntil(), PHP_INT_MAX for none)
	 * - hotTie: the first position whose candidate ties with the one before (HotRanker::mayTie(),
	 *   PHP_INT_MAX for none): their order holds for this time only
	 * - popular: the popular picks (pickPopular(): ID => ancestor IDs), before the check against
	 *   the comments a page shows
	 * - popMax, popUntil: the same as hotMax and hotUntil for the popular picks (pickPopular())
	 *
	 * Neither order depends on when it is computed (HotRanker), except for comments dated after
	 * that time, comments too old and ties: the readers compute again for a time that is not at
	 * or after hotMax and popMax, or not before hotUntil and popUntil, and for a page that reaches
	 * hotTie.
	 *
	 * @param IReadableDatabase $db
	 * @param int $pageId
	 * @return array
	 */
	private function buildSummary( IReadableDatabase $db, int $pageId ): array {
		$now = ConvertibleTimestamp::time();
		$count = $this->countVisible( $db, $pageId );
		[ $pins, $candidates ] = $this->scanTopLevel( $db, $pageId, $count );
		$byId = [];
		foreach ( $candidates as $candidate ) {
			$byId[$candidate['id']] = $candidate;
		}
		$ranked = $this->hotRanker->rank( $candidates, $now, $span );
		$likes = [];
		$created = [];
		foreach ( $ranked as $id ) {
			$likes[] = $byId[$id]['likes'];
			$created[] = $byId[$id]['created'];
		}
		[ $popular, $popMax, $popUntil ] = $this->config->getPopularCount() > 0
			? $this->pickPopular( $db, $pageId, $now )
			: [ [], 0, PHP_INT_MAX ];
		return [
			'now' => $now,
			'count' => $count,
			'pinned' => $pins,
			'ids' => pack( 'J*', ...$ranked ),
			'likes' => pack( 'N*', ...$likes ),
			'created' => implode( ',', $created ),
			'hotMax' => $span ? $span['newest'] : 0,
			'hotUntil' => $span ? $this->hotRanker->getStableUntil( $span['oldest'] ) : PHP_INT_MAX,
			'hotTie' => $span ? $span['tie'] : PHP_INT_MAX,
			'popular' => $popular,
			'popMax' => $popMax,
			'popUntil' => $popUntil,
		];
	}

	/**
	 * The visible top-level comments of a page, for the summary, in one scan: the pinned ones
	 * (newest pin first, then the higher ID, at most PINNED_LIMIT) and the candidates of hot
	 * sort. A scan that reaches HOT_CANDIDATE_LIMIT may have stopped before some of either, so
	 * on a page with that many visible comments the queries of fetchPinned() and fetchHot() run
	 * instead, as they do when a scan reaches it after all.
	 *
	 * @param IReadableDatabase $db
	 * @param int $pageId
	 * @param int $count The visible comments of the page (countVisible())
	 * @return array{0:array<int,array{0:int,1:string}>,1:array<int,array{id:int,likes:int,created:string}>}
	 *   Pins as [ ID, TS_MW pin time ], hot candidates
	 */
	private function scanTopLevel( IReadableDatabase $db, int $pageId, int $count ): array {
		$visible = [ 'gtp_status' => PostStatus::VISIBLE ];
		$pins = [];
		$candidates = [];
		$res = $count < self::HOT_CANDIDATE_LIMIT
			? $this->newTopLevelQuery( $db, $pageId, $visible )
				->select( 'gtp_pinned' )
				->caller( __METHOD__ )
				->fetchResultSet()
			: null;
		if ( $res && $res->numRows() < self::HOT_CANDIDATE_LIMIT ) {
			foreach ( $res as $row ) {
				if ( $row->gtp_pinned === null ) {
					$candidates[] = self::hotCandidate( $row );
				} else {
					$pins[] = [ (int)$row->gtp_id, (string)ConvertibleTimestamp::convert( TS_MW, $row->gtp_pinned ) ];
				}
			}
			return [ self::sortPins( $pins ), $candidates ];
		}
		foreach ( $this->fetchPinned( $db, $visible, $pageId ) as $post ) {
			$pins[] = [ $post->getId(), (string)$post->getPinned() ];
		}
		foreach ( $this->newTopLevelQuery( $db, $pageId, $visible )
			->andWhere( [ 'gtp_pinned' => null ] )
			->caller( __METHOD__ )
			->fetchResultSet() as $row
		) {
			$candidates[] = self::hotCandidate( $row );
		}
		return [ $pins, $candidates ];
	}

	/**
	 * @param array<int,array{0:int,1:string}> $pins [ ID, TS_MW pin time ]
	 * @return array<int,array{0:int,1:string}> Newest pin first, then the higher ID, as
	 *   fetchPinned() orders them, at most PINNED_LIMIT
	 */
	private static function sortPins( array $pins ): array {
		usort( $pins, static fn ( $a, $b ) => [ $b[1], $b[0] ] <=> [ $a[1], $a[0] ] );
		return array_slice( $pins, 0, self::PINNED_LIMIT );
	}

	/**
	 * The pinned and main comments of a list from the page summary, for a viewer who is not a
	 * moderator. The viewer's own held top-level comments, which the summary leaves out, are read
	 * and merged in the way fetchPinned() and fetchHot() would include them. New sort reads its
	 * page from the database (fetchNew()).
	 *
	 * The comments are then read by ID. If one of them no longer fits its place (a write since
	 * the summary was made), the summary is out of date and null is returned.
	 *
	 * @param IReadableDatabase $db
	 * @param Viewer $viewer
	 * @param int $pageId
	 * @param array $summary From buildSummary()
	 * @param string $sort
	 * @param array|null $cursor
	 * @param int $limit
	 * @return array{0:PostRecord[],1:PostRecord[],2:?string,3:array<int,PostRecord>}|null Pinned,
	 *   main, next cursor, and every comment read, by ID
	 */
	private function fetchTopLevelFromSummary(
		IReadableDatabase $db,
		Viewer $viewer,
		int $pageId,
		array $summary,
		string $sort,
		?array $cursor,
		int $limit
	): ?array {
		$firstPage = $cursor === null;
		$held = [];
		$heldPins = [];
		// ID => true for the viewer's held comments, the others come from the summary
		$heldIds = [];
		if ( $viewer->actorId && ( $firstPage || $sort === self::SORT_HOT ) ) {
			foreach ( $this->newTopLevelQuery( $db, $pageId, [
				'gtp_status' => PostStatus::HELD,
				'gtp_actor' => $viewer->actorId,
			] )
				->select( 'gtp_pinned' )
				->caller( __METHOD__ )
				->fetchResultSet() as $row
			) {
				$heldIds[(int)$row->gtp_id] = true;
				if ( $row->gtp_pinned === null ) {
					$held[] = self::hotCandidate( $row );
				} else {
					$heldPins[] = [
						(int)$row->gtp_id, (string)ConvertibleTimestamp::convert( TS_MW, $row->gtp_pinned )
					];
				}
			}
		}
		if ( $heldIds && (
			array_intersect_key( $heldIds, array_flip( array_column( $summary['pinned'], 0 ) ) )
			|| array_intersect_key( $heldIds, array_flip( self::unpackIds( $summary['ids'] ) ) )
		) ) {
			// A comment the summary has as visible is held now: the summary is out of date
			return null;
		}
		$pinnedIds = [];
		if ( $firstPage ) {
			$pins = $heldPins ? self::sortPins( array_merge( $summary['pinned'], $heldPins ) ) : $summary['pinned'];
			$pinnedIds = array_column( $pins, 0 );
		}
		$main = [];
		$mainIds = [];
		if ( $sort === self::SORT_NEW ) {
			[ $main, $next ] = $this->fetchNew( $db, $viewer, $pageId, $cursor, $limit );
		} else {
			[ $mainIds, $next ] = $this->rankFromSummary( $summary, $held, $cursor, $limit );
		}
		// The popular picks and their ancestors too, so that showing them needs no query of its own
		$picks = $firstPage ? $this->getSummaryPicks( $summary ) ?? [] : [];
		$records = $this->postStore->getByIds(
			array_merge( $pinnedIds, $mainIds, array_keys( $picks ), ...array_values( $picks ) )
		);
		// Each comment as the queries would have read it: a visible top-level comment of the page,
		// or a held one of the viewer, pinned or not as its place says
		$fits = static function ( ?PostRecord $post, bool $pinned ) use ( $heldIds, $viewer, $pageId ): bool {
			if ( !$post || $post->isPinned() !== $pinned || $post->getPageId() !== $pageId
				|| $post->getDepth() !== 0
			) {
				return false;
			}
			return isset( $heldIds[$post->getId()] ) ? $post->isHeld() && $viewer->isOwn( $post ) : $post->isVisible();
		};
		$pinned = [];
		foreach ( $pinnedIds as $id ) {
			if ( !$fits( $records[$id] ?? null, true ) ) {
				return null;
			}
			$pinned[] = $records[$id];
		}
		foreach ( $mainIds as $id ) {
			if ( !$fits( $records[$id] ?? null, false ) ) {
				return null;
			}
			$main[] = $records[$id];
		}
		return [ $pinned, $main, $next, $records ];
	}

	/**
	 * A page of hot sort from the summary. Its order holds at any time from the newest candidate
	 * on (HotRanker), so it is used as it is, unless a candidate is newer than the time of the
	 * order asked for (the cursor's) or the viewer's held comments join in: then the candidates
	 * are ranked for that time, as fetchHot() ranks them.
	 *
	 * @param array $summary From buildSummary()
	 * @param array $extra More candidates: the viewer's held comments
	 * @param array|null $cursor
	 * @param int $limit
	 * @return array{0:int[],1:?string} IDs on the page, next cursor
	 */
	private function rankFromSummary( array $summary, array $extra, ?array $cursor, int $limit ): array {
		$now = $cursor ? $cursor['n'] : ConvertibleTimestamp::time();
		$offset = $cursor ? $cursor['o'] : 0;
		if ( !$extra && $summary['hotMax'] <= min( $summary['now'], $now )
			&& max( $summary['now'], $now ) < $summary['hotUntil'] && $offset + $limit < $summary['hotTie']
		) {
			$total = intdiv( strlen( $summary['ids'] ), 8 );
			$pageIds = self::unpackIds( (string)substr( $summary['ids'], $offset * 8, $limit * 8 ) );
		} else {
			$candidates = array_merge( self::summaryCandidates( $summary ), $extra );
			if ( count( $candidates ) > self::HOT_CANDIDATE_LIMIT ) {
				// fetchHot() reads the highest IDs only
				usort( $candidates, static fn ( $a, $b ) => $b['id'] <=> $a['id'] );
				$candidates = array_slice( $candidates, 0, self::HOT_CANDIDATE_LIMIT );
			}
			$ranked = $this->hotRanker->rank( $candidates, $now );
			$total = count( $ranked );
			$pageIds = array_slice( $ranked, $offset, $limit );
		}
		$next = null;
		$nextOffset = $offset + $limit;
		if ( $total > $nextOffset && $nextOffset <= self::HOT_MAX_OFFSET ) {
			$next = Cursor::encode( 'hot', [ 'n' => $now, 'o' => $nextOffset ] );
		}
		return [ $pageIds, $next ];
	}

	/**
	 * @return int[]
	 */
	private static function unpackIds( string $packed ): array {
		return $packed === '' ? [] : array_values( unpack( 'J*', $packed ) );
	}

	/**
	 * @param array $summary From buildSummary()
	 * @return array<int,array{id:int,likes:int,created:string}>
	 */
	private static function summaryCandidates( array $summary ): array {
		$ids = self::unpackIds( $summary['ids'] );
		if ( !$ids ) {
			return [];
		}
		$likes = array_values( unpack( 'N*', $summary['likes'] ) );
		$created = explode( ',', $summary['created'] );
		$candidates = [];
		foreach ( $ids as $i => $id ) {
			$candidates[] = [ 'id' => $id, 'likes' => $likes[$i], 'created' => $created[$i] ];
		}
		return $candidates;
	}

	/**
	 * The popular picks of the summary, null when they must be picked again now: a candidate is
	 * dated after the time they were picked for, or after now.
	 *
	 * @param array|null $summary
	 * @return array<int,int[]>|null As pickPopular()
	 */
	private function getSummaryPicks( ?array $summary ): ?array {
		$now = ConvertibleTimestamp::time();
		if ( !$summary || $summary['popMax'] > min( $summary['now'], $now )
			|| max( $summary['now'], $now ) >= $summary['popUntil']
		) {
			return null;
		}
		return $summary['popular'];
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
	 * @param int $pageId
	 * @param PostRecord[] $shown Comments already on the first page
	 * @param array<int,PostRecord> $loaded Other comments read for this list, by ID
	 * @param array|null $summary The page summary, whose picks are used while they hold
	 * @param array &$facts getPageList()'s facts, narrowed to the times the picks hold
	 * @return PostRecord[]|null Null when the picks of the summary no longer fit the comments
	 *   (it is out of date)
	 */
	private function fetchPopular(
		IReadableDatabase $db, int $pageId, array $shown, array $loaded, ?array $summary, array &$facts
	): ?array {
		if ( $this->config->getPopularCount() <= 0 ) {
			return [];
		}
		$picks = $this->getSummaryPicks( $summary );
		if ( $picks === null ) {
			// Picked now, as before: the picks come with their rows, their ancestors checked
			[ $picks, $newest, $until, $records ] = $this->pickPopular( $db, $pageId, ConvertibleTimestamp::time() );
			self::narrow( $facts, $newest, $until );
			return array_diff( array_keys( $picks ), self::ids( $shown ) ) ? array_values( $records ) : [];
		}
		self::narrow( $facts, $summary['popMax'], $summary['popUntil'] );
		if ( !array_diff( array_keys( $picks ), self::ids( $shown ) ) ) {
			return [];
		}
		$records = $loaded;
		foreach ( $shown as $post ) {
			$records[$post->getId()] = $post;
		}
		// Only the picks become records, read with their ancestors
		$records += $this->postStore->getByIds( array_diff(
			array_merge( array_keys( $picks ), ...array_values( $picks ) ), array_keys( $records )
		) );
		$out = [];
		foreach ( $picks as $id => $ancestorIds ) {
			$post = $records[$id] ?? null;
			$fits = $post && $post->isVisible() && !$post->isPinned() && $post->getPageId() === $pageId;
			foreach ( $ancestorIds as $aid ) {
				$fits = $fits && isset( $records[$aid] ) && $records[$aid]->isVisible();
			}
			if ( !$fits ) {
				return null;
			}
			$out[] = $post;
		}
		return $out;
	}

	/**
	 * The popular picks of a page at a time, best first: the candidates with the most likes
	 * (POPULAR_CANDIDATES), visible, not pinned, with visible ancestors, by decayed like score.
	 *
	 * @param IReadableDatabase $db
	 * @param int $pageId
	 * @param int $now Unix time of the scores
	 * @return array{0:array<int,int[]>,1:int,2:int,3:array<int,PostRecord>} Pick ID => IDs of its
	 *   ancestors, best first; the Unix time of the newest candidate scored (0 for none); the Unix
	 *   time from which the picks may differ (HotRanker::getStableUntil() of the oldest candidate,
	 *   $now when two of them tie, or one ties with the next candidate, PHP_INT_MAX for none); and
	 *   the picks as records, best first
	 */
	private function pickPopular( IReadableDatabase $db, int $pageId, int $now ): array {
		$candidates = [];
		$ancestorIds = [];
		foreach ( $this->postStore->newSelectQueryBuilder( $db )
			->where( [
				'gtp_page' => $pageId,
				'gtp_status' => PostStatus::VISIBLE,
				'gtp_pinned' => null,
				$db->expr( 'gtp_like_count', '>=', $this->config->getPopularMinLikes() ),
			] )
			// Equal counts: the newer comment, then the higher ID, so that the cut at
			// POPULAR_CANDIDATES does not depend on the query plan
			->orderBy( [ 'gtp_like_count', 'gtp_created', 'gtp_id' ], SelectQueryBuilder::SORT_DESC )
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
			return [ [], 0, PHP_INT_MAX, [] ];
		}
		$visibleAncestors = array_flip( $this->postStore->getVisibleIds( $ancestorIds ) );
		$scores = [];
		$ids = [];
		$times = [];
		$rows = [];
		foreach ( $candidates as $row ) {
			foreach ( [ (int)$row->gtp_parent, (int)$row->gtp_thread ] as $aid ) {
				if ( $aid && !isset( $visibleAncestors[$aid] ) ) {
					continue 2;
				}
			}
			$id = (int)$row->gtp_id;
			$rows[$id] = $row;
			// popularScore(), with the time converted once
			$times[$id] = HotRanker::toUnix( (string)$row->gtp_created );
			$scores[] = $this->hotRanker->popularScoreAt( (int)$row->gtp_like_count, $times[$id], $now );
			$ids[] = $id;
		}
		if ( !$ids ) {
			return [ [], 0, PHP_INT_MAX, [] ];
		}
		// Best score first, then the higher ID
		array_multisort( $scores, SORT_DESC, SORT_NUMERIC, $ids, SORT_DESC, SORT_NUMERIC );
		$count = $this->config->getPopularCount();
		$until = $this->hotRanker->getStableUntil( min( $times ) );
		$last = min( count( $ids ) - 1, $count );
		for ( $i = 1; $i <= $last; $i++ ) {
			if ( HotRanker::mayTie( $scores[$i - 1], $scores[$i], $times[$ids[$i - 1]], $times[$ids[$i]] ) ) {
				$until = $now;
				break;
			}
		}
		$picks = [];
		$records = [];
		foreach ( array_slice( $ids, 0, $count ) as $id ) {
			$row = $rows[$id];
			$picks[$id] = array_values( array_unique( array_filter(
				[ (int)$row->gtp_parent, (int)$row->gtp_thread ]
			) ) );
			$records[$id] = PostRecord::newFromRow( $row );
		}
		return [ $picks, max( $times ), $until, $records ];
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
