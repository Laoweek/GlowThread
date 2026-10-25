<?php

namespace MediaWiki\Extension\GlowThread\Hooks;

use MediaWiki\Api\ApiQueryBase;
use MediaWiki\Api\Hook\ApiQueryBaseBeforeQueryHook;
use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\GlowThread\Settings\SettingsService;
use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\Hook\UserGetRightsHook;
use MediaWiki\SpecialPage\Hook\ChangesListSpecialPageQueryHook;
use MediaWiki\User\UserIdentity;
use MediaWiki\Watchlist\WatchedItemQueryServiceExtension;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;

/**
 * Who sees GlowThread's log entries (the glowthread type, and FlowThread's comments type while
 * GlowThread shows it). $wgLogRestrictions ties those types to VIEW_RIGHT, which hides them
 * in Special:Log, list=logevents and log formatting. Everyone gets the right while the
 * publicLog setting is on, otherwise only users who can moderate, erase or configure. The right
 * is in the basic grant, so bot passwords and OAuth consumers keep it.
 *
 * Core never puts restricted types in RecentChanges, so ModerationLogger inserts their rows
 * itself, and only while the setting is on. The query hooks below keep the rows from before the
 * setting was turned off (and FlowThread's) out of Special:RecentChanges,
 * Special:RecentChangesLinked, Special:Watchlist, list=recentchanges and list=watchlist for users
 * without the right, because core does not check log restrictions there.
 *
 * The watchlist service hook is registered without its interface, whose namespace moved between
 * MediaWiki 1.43 and 1.46.
 */
class LogVisibilityHooks implements
	UserGetRightsHook,
	ChangesListSpecialPageQueryHook,
	ApiQueryBaseBeforeQueryHook,
	WatchedItemQueryServiceExtension
{

	public const VIEW_RIGHT = 'glowthread-viewlog';

	/** Rights that always see the log */
	private const STAFF_RIGHTS = [ 'glowthread-moderate', 'glowthread-erase', 'glowthread-configure' ];

	/** API modules that list RecentChanges rows */
	private const API_MODULES = [ 'recentchanges', 'watchlist' ];

	public function __construct(
		private readonly SettingsService $settings,
		private readonly Config $mainConfig,
		private readonly IConnectionProvider $connectionProvider
	) {
	}

	/** @inheritDoc */
	public function onUserGetRights( $user, &$rights ) {
		if ( array_intersect( self::STAFF_RIGHTS, $rights ) || $this->settings->get( 'publicLog' ) ) {
			$rights[] = self::VIEW_RIGHT;
		}
	}

	/** @inheritDoc */
	public function onChangesListSpecialPageQuery(
		$name, &$tables, &$fields, &$conds, &$query_options, &$join_conds, $opts
	) {
		$this->hideRows( RequestContext::getMain()->getAuthority(), $conds );
	}

	/** @inheritDoc */
	public function onApiQueryBaseBeforeQuery(
		$module, &$tables, &$fields, &$conds, &$query_options, &$join_conds, &$hookData
	) {
		if ( $module instanceof ApiQueryBase && in_array( $module->getModuleName(), self::API_MODULES, true ) ) {
			$this->hideRows( $module->getAuthority(), $conds );
		}
	}

	/**
	 * WatchedItemQueryServiceExtensions: list=watchlist on MediaWiki 1.43 queries through this service.
	 *
	 * @param WatchedItemQueryServiceExtension[] &$extensions
	 * @param mixed $watchedItemQueryService
	 */
	public function onWatchedItemQueryServiceExtensions( &$extensions, $watchedItemQueryService ): void {
		$extensions[] = $this;
	}

	/** @inheritDoc */
	public function modifyWatchedItemsWithRCInfoQuery( UserIdentity $user, array $options,
		IReadableDatabase $db, array &$tables, array &$fields, array &$conds, array &$dbOptions,
		array &$joinConds
	) {
		// The viewer, who may read someone else's watchlist with its token
		$this->hideRows( RequestContext::getMain()->getAuthority(), $conds );
	}

	/** @inheritDoc */
	public function modifyWatchedItemsWithRCInfo( UserIdentity $user, array $options, IReadableDatabase $db,
		array &$items, $res, &$startFrom
	) {
	}

	private function hideRows( Authority $viewer, array &$conds ): void {
		if ( $viewer->isAllowed( self::VIEW_RIGHT ) ) {
			return;
		}
		$types = array_keys( $this->mainConfig->get( MainConfigNames::LogRestrictions ), self::VIEW_RIGHT, true );
		if ( !$types ) {
			return;
		}
		$dbr = $this->connectionProvider->getReplicaDatabase();
		// Edits and page creations have no log type
		$conds[] = $dbr->expr( 'rc_log_type', '=', null )->or( 'rc_log_type', '!=', $types );
	}
}
