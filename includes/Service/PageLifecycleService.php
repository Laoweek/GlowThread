<?php

namespace MediaWiki\Extension\GlowThread\Service;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\GlowThread\Cdn\CdnPurger;
use MediaWiki\Extension\GlowThread\Echo\ErasedCommentEvents;
use MediaWiki\Extension\GlowThread\Query\ListCache;
use MediaWiki\Extension\GlowThread\Store\PageStateStore;
use MediaWiki\Extension\GlowThread\Store\PostStore;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\User\ActorNormalization;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * Keeps comments attached to their page across deletion and undeletion, and purges the lists
 * of a page when it moves (its new title may have other read rights) and of a user's pages when a
 * hideuser block hides or shows their name. Changes to users that any list may show (hidden,
 * renamed, merged) also purge the wiki's cache of the lists (ListCache).
 *
 * Comments are keyed by page ID. Deleting a page removes the page row, so its comments stop
 * being listed, and nothing is erased. Undeleting usually restores the old page ID, and the
 * comments come back as they were. When the old ID is taken (the title was re-created in the
 * meantime), the revisions are merged into the existing page and the comments follow them:
 * their floor numbers are shifted above the floors already used on that page, and the page keeps
 * the stricter of the two comment states (PageStateStore::movePage()).
 *
 * Only the comments of page IDs whose revisions this undeletion restores come back. Those IDs
 * are read from the archive in the PageUndelete hook, before the archive rows are gone:
 * PageUndeleteComplete's $restoredPageIds cannot be used, because UndeletePage builds it from
 * archive rows that RevisionStore::newRevisionFromArchiveRow() has already rewritten to the new
 * page ID (1.43 and 1.46). Comments of an old page ID whose last deletion was a suppression are
 * moved only by an unsuppressing undeletion by a user with suppressrevision, otherwise they stay
 * detached, like the revisions that stay suppressed. (A page that gets its suppressed old ID back
 * needs an unsuppressing undeletion anyway: core refuses it otherwise with undeleterevdel.)
 */
class PageLifecycleService {

	/** Most pages whose lists are purged when a user's name is hidden or shown again */
	public const HIDE_USER_PURGE_LIMIT = 500;

	/** @var array<string,array{ids:int[],unsuppress:bool}> Title key => what this undeletion restores */
	private array $pendingRestores = [];

	/**
	 * @param IConnectionProvider $dbProvider
	 * @param PostStore $postStore
	 * @param PageStateStore $pageStateStore
	 * @param CdnPurger $cdnPurger
	 * @param ActorNormalization $actorNormalization
	 * @param ListCache $listCache
	 * @param bool $echoLoaded Whether Echo is loaded, see onPageUndeleted()
	 */
	public function __construct(
		private readonly IConnectionProvider $dbProvider,
		private readonly PostStore $postStore,
		private readonly PageStateStore $pageStateStore,
		private readonly CdnPurger $cdnPurger,
		private readonly ActorNormalization $actorNormalization,
		private readonly ListCache $listCache,
		private readonly bool $echoLoaded = false
	) {
	}

	public function onPageDeleted( int $pageId ): void {
		$this->cdnPurger->purgePage( $pageId );
	}

	/**
	 * A page was moved. Anonymous readers may not be allowed to read its new title (a namespace
	 * with read restrictions), so its cached lists must go.
	 */
	public function onPageMoved( int $pageId ): void {
		$this->cdnPurger->purgePage( $pageId );
	}

	/**
	 * Before an undeletion: remember which archived page IDs it restores.
	 *
	 * @param ProperPageIdentity $page
	 * @param string[] $timestamps Revisions to restore, empty for all
	 * @param int[] $fileVersions File versions to restore, empty for all
	 * @param bool $unsuppress Whether the undeletion also removes suppression
	 */
	public function onPageUndeleting(
		ProperPageIdentity $page,
		array $timestamps,
		array $fileVersions,
		bool $unsuppress = false
	): void {
		$key = $this->getTitleKey( $page );
		if ( $timestamps === [] && $fileVersions !== [] ) {
			// Only file versions: no revision comes back
			$this->pendingRestores[$key] = [ 'ids' => [], 'unsuppress' => $unsuppress ];
			return;
		}
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$conds = [
			'ar_namespace' => $page->getNamespace(),
			'ar_title' => $page->getDBkey(),
			$dbw->expr( 'ar_page_id', '>', 0 ),
		];
		if ( $timestamps ) {
			$conds['ar_timestamp'] = array_map( [ $dbw, 'timestamp' ], $timestamps );
		}
		$this->pendingRestores[$key] = [
			'ids' => array_map( 'intval', $dbw->newSelectQueryBuilder()
				->select( 'ar_page_id' )
				->distinct()
				->from( 'archive' )
				->where( $conds )
				->caller( __METHOD__ )
				->fetchFieldValues() ),
			'unsuppress' => $unsuppress,
		];
	}

	/**
	 * After an undeletion: bring back the comments of the page IDs it restored.
	 *
	 * With Echo, also hide again the notifications of erased comments, and of comments their
	 * author deleted. When an undeletion creates the page, Echo shows all notifications of the
	 * restored page ID again (its ArticleUndelete handler), including those hidden when a comment
	 * was erased or deleted by its author. Notifications about comments someone else deleted, or
	 * about held comments, may show again: they render a neutral text while readers cannot see
	 * the comment.
	 *
	 * @param ProperPageIdentity $page The page after undeletion
	 * @param Authority $restorer
	 * @return string|null The comment state the page took over from a restored earlier page,
	 *   because it was stricter than its own (PageState::LOCKED or DISABLED), else null
	 */
	public function onPageUndeleted( ProperPageIdentity $page, Authority $restorer ): ?string {
		$newId = $page->getId();
		$key = $this->getTitleKey( $page );
		$pending = $this->pendingRestores[$key] ?? [ 'ids' => [], 'unsuppress' => false ];
		unset( $this->pendingRestores[$key] );
		$canUnsuppress = $pending['unsuppress'] && $restorer->isAllowed( 'suppressrevision' );
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$stateBefore = null;
		foreach ( array_unique( $pending['ids'] ) as $oldId ) {
			// The page's own ID needs nothing: its comments are back with it
			if ( $oldId <= 0 || $oldId === $newId || $this->pageExists( $oldId )
				|| ( !$canUnsuppress && $this->wasSuppressed( $oldId ) )
			) {
				continue;
			}
			$stateBefore ??= $this->pageStateStore->getState( $newId, true );
			$dbw->startAtomic( __METHOD__ );
			$targetSeq = max(
				$this->pageStateStore->get( $newId, true )['seq'],
				$this->postStore->getMaxSeq( $dbw, $newId )
			);
			$sourceSeq = max(
				$this->pageStateStore->get( $oldId, true )['seq'],
				$this->postStore->getMaxSeq( $dbw, $oldId )
			);
			$this->postStore->movePage( $dbw, $oldId, $newId, $targetSeq );
			$this->pageStateStore->movePage( $dbw, $oldId, $newId, $targetSeq + $sourceSeq );
			$dbw->endAtomic( __METHOD__ );
			$this->cdnPurger->purgePage( $oldId );
		}
		$this->cdnPurger->purgePage( $newId );
		if ( $this->echoLoaded ) {
			$this->hideErasedCommentEvents( array_merge( [ $newId ], $pending['ids'] ) );
		}
		$state = $stateBefore === null ? null : $this->pageStateStore->getState( $newId, true );
		if ( $state === $stateBefore ) {
			return null;
		}
		// The article's HTML says whether comments are open
		$this->cdnPurger->purgeArticle( $page );
		return $state;
	}

	/**
	 * Hide the Echo notifications on these page IDs that are about erased comments, and comments
	 * their author deleted, in a deferred update. Echo's update that shows the notifications
	 * again is deferred too, and was queued earlier in the undeletion (ArticleUndelete runs
	 * before PageUndeleteComplete), so it runs first.
	 *
	 * @param int[] $pageIds The page and the page IDs the undeletion restored (Echo shows the
	 *  notifications of one of them again)
	 */
	private function hideErasedCommentEvents( array $pageIds ): void {
		DeferredUpdates::addCallableUpdate( function () use ( $pageIds ) {
			ErasedCommentEvents::hideOnPages( $pageIds, $this->dbProvider->getPrimaryDatabase() );
		} );
	}

	/**
	 * A hideuser block was placed, changed or lifted: purge the cached lists of the pages where
	 * the user commented from the CDN, most recent first, at most HIDE_USER_PURGE_LIMIT pages.
	 * Lists of other pages expire there within $wgGlowThreadCdnMaxAge. The wiki's cache of the
	 * lists is purged for every page, which also covers mentions of the user.
	 *
	 * @return int Pages purged from the CDN
	 */
	public function onHideUserChanged( UserIdentity $user ): int {
		$this->listCache->touchAll();
		$db = $this->dbProvider->getPrimaryDatabase();
		$actorId = $this->actorNormalization->findActorId( $user, $db );
		if ( !$actorId ) {
			return 0;
		}
		$res = $db->newSelectQueryBuilder()
			->select( [ 'gtp_page', 'gt_last' => 'MAX(gtp_id)' ] )
			->from( PostStore::TABLE )
			->where( [ 'gtp_actor' => $actorId ] )
			->groupBy( 'gtp_page' )
			->orderBy( 'gt_last', SelectQueryBuilder::SORT_DESC )
			->limit( self::HIDE_USER_PURGE_LIMIT )
			->caller( __METHOD__ )
			->fetchResultSet();
		$urls = [];
		foreach ( $res as $row ) {
			$urls = array_merge( $urls, $this->cdnPurger->getPurgeUrls( (int)$row->gtp_page ) );
		}
		$this->cdnPurger->purgeUrls( $urls );
		return $res->numRows();
	}

	/**
	 * Two accounts were merged (Extension:UserMerge). The comments of the old account now show
	 * the name of the account that stays, which a hideuser block that UserMerge moved over may
	 * hide. Purge the lists of that account's pages, as for a hideuser block.
	 *
	 * @return int Pages purged
	 */
	public function onAccountsMerged( UserIdentity $user ): int {
		return $this->onHideUserChanged( $user );
	}

	/**
	 * A user was renamed: lists show the new name, as author and in mentions.
	 */
	public function onUserRenamed(): void {
		$this->listCache->touchAll();
	}

	/**
	 * Whether the last deletion of a page ID was a suppression (suppress/delete).
	 */
	private function wasSuppressed( int $pageId ): bool {
		$type = $this->dbProvider->getPrimaryDatabase()->newSelectQueryBuilder()
			->select( 'log_type' )
			->from( 'logging' )
			->where( [
				'log_type' => [ 'delete', 'suppress' ],
				'log_action' => [ 'delete', 'delete_redir', 'delete_redir2' ],
				'log_page' => $pageId,
			] )
			->orderBy( [ 'log_timestamp', 'log_id' ], SelectQueryBuilder::SORT_DESC )
			->limit( 1 )
			->caller( __METHOD__ )
			->fetchField();
		return $type === 'suppress';
	}

	private function getTitleKey( ProperPageIdentity $page ): string {
		return $page->getNamespace() . ':' . $page->getDBkey();
	}

	private function pageExists( int $pageId ): bool {
		return (bool)$this->dbProvider->getPrimaryDatabase()->newSelectQueryBuilder()
			->select( '1' )
			->from( 'page' )
			->where( [ 'page_id' => $pageId ] )
			->caller( __METHOD__ )
			->fetchField();
	}
}
