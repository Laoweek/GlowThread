<?php

namespace MediaWiki\Extension\GlowThread\Hooks;

use ManualLogEntry;
use MediaWiki\Block\Block;
use MediaWiki\Extension\GlowThread\Log\LogAuthorLookup;
use MediaWiki\Extension\GlowThread\Log\ModerationLogger;
use MediaWiki\Extension\GlowThread\Model\PageState;
use MediaWiki\Extension\GlowThread\Service\PageLifecycleService;
use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\Hook\PageUndeleteCompleteHook;
use MediaWiki\Page\Hook\PageUndeleteHook;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\RenameUser\Hook\RenameUserCompleteHook;
use MediaWiki\Revision\RevisionRecord;
use StatusValue;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Page deletion hides comments (their page is gone), undeletion brings them back. A page move
 * purges the page's cached lists. A hideuser block, placed, changed or lifted, purges the cached
 * lists of the user's pages.
 *
 * The block hooks (BlockIpComplete, UnblockUserComplete) moved namespace between 1.43 and 1.46,
 * so their interfaces are not implemented. HookContainer calls the methods by name. They run
 * only for blocks placed or lifted through BlockUser and UnblockUser (Special:Block, the API).
 * Core has no hook for blocks written straight to the block store, as CentralAuth's global
 * suppression does: those lists update within $wgGlowThreadCdnMaxAge (and within an hour in the
 * wiki's own cache of the lists, ListCache).
 *
 * A rename purges the wiki's cache of the lists too: lists show current names.
 */
class PageLifecycleHooks implements
	PageDeleteCompleteHook,
	PageUndeleteHook,
	PageUndeleteCompleteHook,
	PageMoveCompleteHook,
	RenameUserCompleteHook
{

	/** Log subtype of the comment states an undeletion can carry over */
	private const LOG_ACTIONS = [
		PageState::LOCKED => 'lock',
		PageState::DISABLED => 'disable',
	];

	public function __construct(
		private readonly PageLifecycleService $lifecycle,
		private readonly LogAuthorLookup $logAuthorLookup,
		private readonly ModerationLogger $moderationLogger,
		private readonly IConnectionProvider $dbProvider
	) {
	}

	/** @inheritDoc */
	public function onPageDeleteComplete(
		ProperPageIdentity $page,
		Authority $deleter,
		string $reason,
		int $pageID,
		RevisionRecord $deletedRev,
		ManualLogEntry $logEntry,
		int $archivedRevisionCount
	) {
		$this->lifecycle->onPageDeleted( $pageID );
	}

	/** @inheritDoc */
	public function onPageUndelete(
		ProperPageIdentity $page,
		Authority $performer,
		string $reason,
		bool $unsuppress,
		array $timestamps,
		array $fileVersions,
		StatusValue $status
	) {
		$this->lifecycle->onPageUndeleting( $page, $timestamps, $fileVersions, $unsuppress );
	}

	/** @inheritDoc */
	public function onPageUndeleteComplete(
		ProperPageIdentity $page,
		Authority $restorer,
		string $reason,
		RevisionRecord $restoredRev,
		ManualLogEntry $logEntry,
		int $restoredRevisionCount,
		bool $created,
		array $restoredPageIds
	): void {
		$state = $this->lifecycle->onPageUndeleted( $page, $restorer );
		if ( $state !== null ) {
			// The page took over the stricter state of an earlier page merged into it: log it, so
			// moderators see why its comments were locked or turned off
			$this->moderationLogger->logPageState(
				$this->dbProvider->getPrimaryDatabase(),
				self::LOG_ACTIONS[$state],
				$page,
				$restorer->getUser(),
				wfMessage( 'glowthread-log-undelete-state' )->inContentLanguage()->text()
			);
		}
	}

	/** @inheritDoc */
	public function onPageMoveComplete( $old, $new, $user, $pageid, $redirid, $reason, $revision ) {
		$this->lifecycle->onPageMoved( $pageid );
	}

	/** @inheritDoc */
	public function onRenameUserComplete( int $uid, string $old, string $new ): void {
		$this->lifecycle->onUserRenamed();
	}

	/**
	 * A block was placed or changed. The name is hidden or shown again when the block (or the
	 * block it replaced) hides it.
	 *
	 * @param Block $block
	 * @param \MediaWiki\User\User $user Who placed it
	 * @param Block|null $priorBlock
	 */
	public function onBlockIpComplete( $block, $user, $priorBlock ) {
		if ( $block->getHideName() || ( $priorBlock && $priorBlock->getHideName() ) ) {
			$this->onHideUserChanged( $block );
		}
	}

	/**
	 * A block was lifted.
	 *
	 * @param Block $block
	 * @param \MediaWiki\User\User $user Who lifted it
	 */
	public function onUnblockUserComplete( $block, $user ) {
		if ( $block->getHideName() ) {
			$this->onHideUserChanged( $block );
		}
	}

	/**
	 * @param Block $block
	 */
	private function onHideUserChanged( $block ): void {
		$target = $block->getTargetUserIdentity();
		if ( $target ) {
			$this->logAuthorLookup->forgetName( $target->getName() );
			$this->lifecycle->onHideUserChanged( $target );
		}
	}
}
