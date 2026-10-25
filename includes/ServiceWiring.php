<?php

use MediaWiki\Extension\GlowThread\Cdn\CdnPurger;
use MediaWiki\Extension\GlowThread\Filter\SpamChecker;
use MediaWiki\Extension\GlowThread\Filter\WordFilter;
use MediaWiki\Extension\GlowThread\GlowThreadConfig;
use MediaWiki\Extension\GlowThread\HookRunner;
use MediaWiki\Extension\GlowThread\Log\LogAuthorLookup;
use MediaWiki\Extension\GlowThread\Log\ModerationLogger;
use MediaWiki\Extension\GlowThread\Policy\PageEligibility;
use MediaWiki\Extension\GlowThread\Policy\RateLimitErrors;
use MediaWiki\Extension\GlowThread\Policy\WriteGuard;
use MediaWiki\Extension\GlowThread\Query\ActorInfoLookup;
use MediaWiki\Extension\GlowThread\Query\CommentQueryService;
use MediaWiki\Extension\GlowThread\Query\CommentSerializer;
use MediaWiki\Extension\GlowThread\Query\HotRanker;
use MediaWiki\Extension\GlowThread\Query\ListCache;
use MediaWiki\Extension\GlowThread\Query\ModerationQueryService;
use MediaWiki\Extension\GlowThread\Query\ViewerFactory;
use MediaWiki\Extension\GlowThread\Service\ModerationService;
use MediaWiki\Extension\GlowThread\Service\PageLifecycleService;
use MediaWiki\Extension\GlowThread\Service\PageStateService;
use MediaWiki\Extension\GlowThread\Service\PostingService;
use MediaWiki\Extension\GlowThread\Service\ReactionService;
use MediaWiki\Extension\GlowThread\Service\ReportService;
use MediaWiki\Extension\GlowThread\Settings\SettingsService;
use MediaWiki\Extension\GlowThread\Store\PageStateStore;
use MediaWiki\Extension\GlowThread\Store\PostStore;
use MediaWiki\Extension\GlowThread\Store\ReactionStore;
use MediaWiki\Extension\GlowThread\Store\ReportStore;
use MediaWiki\Extension\GlowThread\Store\SettingsStore;
use MediaWiki\Extension\GlowThread\Text\MentionResolver;
use MediaWiki\Extension\GlowThread\Text\TextProcessor;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;

/** @phpcs-require-sorted-array */
return [
	'GlowThread.ActorInfoLookup' => static function ( MediaWikiServices $services ): ActorInfoLookup {
		return new ActorInfoLookup(
			$services->getConnectionProvider(),
			$services->getHideUserUtils(),
			$services->getUserNameUtils()
		);
	},
	'GlowThread.CdnPurger' => static function ( MediaWikiServices $services ): CdnPurger {
		return new CdnPurger(
			$services->get( 'GlowThread.Config' ),
			$services->getUrlUtils(),
			// The service was renamed in 1.46; the getter works on every version.
			$services->getHtmlCacheUpdater(),
			$services->getMainWANObjectCache(),
			$services->get( 'GlowThread.ListCache' )
		);
	},
	'GlowThread.CommentQueryService' => static function ( MediaWikiServices $services ): CommentQueryService {
		return new CommentQueryService(
			$services->get( 'GlowThread.Config' ),
			$services->get( 'GlowThread.PostStore' ),
			$services->get( 'GlowThread.PageStateStore' ),
			$services->get( 'GlowThread.PageEligibility' ),
			$services->get( 'GlowThread.CommentSerializer' ),
			$services->get( 'GlowThread.HotRanker' ),
			$services->get( 'GlowThread.SettingsService' ),
			$services->get( 'GlowThread.ViewerFactory' ),
			$services->getTitleFormatter(),
			// Made only when needed: it brings the write checks with it
			static fn () => $services->get( 'GlowThread.WriteGuard' ),
			$services->getReadOnlyMode(),
			$services->get( 'GlowThread.ListCache' )
		);
	},
	'GlowThread.CommentSerializer' => static function ( MediaWikiServices $services ): CommentSerializer {
		return new CommentSerializer(
			$services->get( 'GlowThread.TextProcessor' ),
			$services->get( 'GlowThread.MentionResolver' ),
			$services->get( 'GlowThread.ActorInfoLookup' ),
			$services->get( 'GlowThread.PostStore' ),
			$services->get( 'GlowThread.ReactionStore' ),
			$services->get( 'GlowThread.ReportStore' ),
			// For moderators only
			static fn () => $services->getDatabaseBlockStore(),
			static fn () => $services->get( 'GlowThread.ModerationLogger' )
		);
	},
	'GlowThread.Config' => static function ( MediaWikiServices $services ): GlowThreadConfig {
		return new GlowThreadConfig( $services->getMainConfig() );
	},
	'GlowThread.HookRunner' => static function ( MediaWikiServices $services ): HookRunner {
		return new HookRunner( $services->getHookContainer() );
	},
	'GlowThread.HotRanker' => static function ( MediaWikiServices $services ): HotRanker {
		return new HotRanker( $services->get( 'GlowThread.Config' )->getHotHalfLife() );
	},
	'GlowThread.ListCache' => static function ( MediaWikiServices $services ): ListCache {
		return new ListCache(
			$services->getMainWANObjectCache(),
			$services->getConnectionProvider(),
			$services->get( 'GlowThread.Config' ),
			$services->getChronologyProtector(),
			$services->getDBLoadBalancer(),
			$services->getLocalServerObjectCache()
		);
	},
	'GlowThread.LogAuthorLookup' => static function ( MediaWikiServices $services ): LogAuthorLookup {
		return new LogAuthorLookup( $services->getUserFactory(), $services->get( 'GlowThread.PostStore' ) );
	},
	'GlowThread.MentionResolver' => static function ( MediaWikiServices $services ): MentionResolver {
		return new MentionResolver( $services->getUserIdentityLookup(), $services->get( 'GlowThread.TextProcessor' ) );
	},
	'GlowThread.ModerationLogger' => static function ( MediaWikiServices $services ): ModerationLogger {
		return new ModerationLogger( $services->getTitleFactory() );
	},
	'GlowThread.ModerationQueryService' => static function ( MediaWikiServices $services ): ModerationQueryService {
		return new ModerationQueryService(
			$services->get( 'GlowThread.PostStore' ),
			$services->get( 'GlowThread.ReportStore' ),
			$services->get( 'GlowThread.PageStateStore' ),
			$services->get( 'GlowThread.CommentSerializer' ),
			$services->get( 'GlowThread.ActorInfoLookup' ),
			$services->getActorStore(),
			$services->getPageStore(),
			$services->getTitleFactory(),
			$services->getTitleFormatter(),
			$services->getUserRegistrationLookup(),
			$services->getUserEditTracker(),
			$services->getUserGroupManager(),
			$services->getDatabaseBlockStore(),
			$services->get( 'GlowThread.ViewerFactory' ),
			$services->get( 'GlowThread.Config' )
		);
	},
	'GlowThread.ModerationService' => static function ( MediaWikiServices $services ): ModerationService {
		return new ModerationService(
			$services->get( 'GlowThread.WriteGuard' ),
			$services->get( 'GlowThread.PostStore' ),
			$services->get( 'GlowThread.ReportStore' ),
			$services->get( 'GlowThread.PageEligibility' ),
			$services->getActorNormalization(),
			$services->getActorStore(),
			$services->get( 'GlowThread.ModerationLogger' ),
			$services->get( 'GlowThread.CdnPurger' ),
			$services->get( 'GlowThread.HookRunner' ),
			$services->get( 'GlowThread.MentionResolver' )
		);
	},
	'GlowThread.PageEligibility' => static function ( MediaWikiServices $services ): PageEligibility {
		return new PageEligibility(
			$services->get( 'GlowThread.Config' ),
			$services->getNamespaceInfo(),
			$services->getTitleFactory(),
			$services->getPageStore(),
			$services->getMainWANObjectCache()
		);
	},
	'GlowThread.PageLifecycleService' => static function ( MediaWikiServices $services ): PageLifecycleService {
		return new PageLifecycleService(
			$services->getConnectionProvider(),
			$services->get( 'GlowThread.PostStore' ),
			$services->get( 'GlowThread.PageStateStore' ),
			$services->get( 'GlowThread.CdnPurger' ),
			$services->getActorNormalization(),
			$services->get( 'GlowThread.ListCache' ),
			ExtensionRegistry::getInstance()->isLoaded( 'Echo' )
		);
	},
	'GlowThread.PageStateService' => static function ( MediaWikiServices $services ): PageStateService {
		return new PageStateService(
			$services->get( 'GlowThread.WriteGuard' ),
			$services->get( 'GlowThread.PageStateStore' ),
			$services->get( 'GlowThread.PageEligibility' ),
			$services->getActorNormalization(),
			$services->get( 'GlowThread.ModerationLogger' ),
			$services->get( 'GlowThread.CdnPurger' ),
			$services->getConnectionProvider()
		);
	},
	'GlowThread.PageStateStore' => static function ( MediaWikiServices $services ): PageStateStore {
		return new PageStateStore( $services->getConnectionProvider(), $services->getMainWANObjectCache() );
	},
	'GlowThread.PostingService' => static function ( MediaWikiServices $services ): PostingService {
		return new PostingService(
			$services->get( 'GlowThread.WriteGuard' ),
			$services->get( 'GlowThread.TextProcessor' ),
			$services->get( 'GlowThread.PostStore' ),
			$services->get( 'GlowThread.PageStateStore' ),
			$services->get( 'GlowThread.PageEligibility' ),
			$services->getActorNormalization(),
			$services->getActorStore(),
			$services->get( 'GlowThread.CdnPurger' ),
			$services->get( 'GlowThread.HookRunner' )
		);
	},
	'GlowThread.PostStore' => static function ( MediaWikiServices $services ): PostStore {
		return new PostStore( $services->getConnectionProvider() );
	},
	'GlowThread.RateLimitErrors' => static function ( MediaWikiServices $services ): RateLimitErrors {
		return new RateLimitErrors( (array)$services->getMainConfig()->get( 'RateLimits' ) );
	},
	'GlowThread.ReactionService' => static function ( MediaWikiServices $services ): ReactionService {
		return new ReactionService(
			$services->get( 'GlowThread.WriteGuard' ),
			$services->get( 'GlowThread.PostStore' ),
			$services->get( 'GlowThread.ReactionStore' ),
			$services->get( 'GlowThread.PageEligibility' ),
			$services->getActorNormalization(),
			$services->get( 'GlowThread.ListCache' )
		);
	},
	'GlowThread.ReactionStore' => static function (): ReactionStore {
		return new ReactionStore();
	},
	'GlowThread.ReportService' => static function ( MediaWikiServices $services ): ReportService {
		return new ReportService(
			$services->get( 'GlowThread.WriteGuard' ),
			$services->get( 'GlowThread.PostStore' ),
			$services->get( 'GlowThread.ReportStore' ),
			$services->get( 'GlowThread.PageEligibility' ),
			$services->get( 'GlowThread.SettingsService' ),
			$services->get( 'GlowThread.TextProcessor' ),
			$services->getActorNormalization(),
			$services->getActorStore(),
			$services->getBlockManager(),
			$services->get( 'GlowThread.CdnPurger' ),
			$services->get( 'GlowThread.HookRunner' ),
			$services->getUserFactory(),
			$services->get( 'GlowThread.ModerationLogger' )
		);
	},
	'GlowThread.ReportStore' => static function (): ReportStore {
		return new ReportStore();
	},
	'GlowThread.SettingsService' => static function ( MediaWikiServices $services ): SettingsService {
		return new SettingsService(
			$services->get( 'GlowThread.Config' ),
			$services->get( 'GlowThread.SettingsStore' ),
			$services->getActorNormalization(),
			$services->get( 'GlowThread.ModerationLogger' ),
			$services->getReadOnlyMode(),
			$services->get( 'GlowThread.RateLimitErrors' )
		);
	},
	'GlowThread.SettingsStore' => static function ( MediaWikiServices $services ): SettingsStore {
		return new SettingsStore(
			$services->getConnectionProvider(),
			$services->getMainWANObjectCache(),
			$services->get( 'GlowThread.ListCache' )
		);
	},
	'GlowThread.SpamChecker' => static function (): SpamChecker {
		return new SpamChecker( ExtensionRegistry::getInstance()->isLoaded( 'SpamBlacklist' ) );
	},
	'GlowThread.TextProcessor' => static function (): TextProcessor {
		return new TextProcessor();
	},
	'GlowThread.ViewerFactory' => static function ( MediaWikiServices $services ): ViewerFactory {
		return new ViewerFactory( $services->getActorNormalization(), $services->getConnectionProvider() );
	},
	'GlowThread.WordFilter' => static function ( MediaWikiServices $services ): WordFilter {
		return new WordFilter(
			$services->getPageStore(),
			$services->getRevisionLookup(),
			$services->getMainWANObjectCache()
		);
	},
	'GlowThread.WriteGuard' => static function ( MediaWikiServices $services ): WriteGuard {
		return new WriteGuard(
			$services->get( 'GlowThread.PageEligibility' ),
			$services->get( 'GlowThread.PageStateStore' ),
			$services->getReadOnlyMode(),
			$services->get( 'GlowThread.SettingsService' ),
			$services->get( 'GlowThread.TextProcessor' ),
			$services->get( 'GlowThread.MentionResolver' ),
			$services->get( 'GlowThread.PostStore' ),
			$services->get( 'GlowThread.WordFilter' ),
			$services->get( 'GlowThread.SpamChecker' ),
			$services->getUserFactory(),
			$services->getUserRegistrationLookup(),
			$services->getActorNormalization(),
			$services->get( 'GlowThread.Config' ),
			$services->get( 'GlowThread.RateLimitErrors' ),
			LoggerFactory::getInstance( 'GlowThread' ),
			$services->getPageStore()
		);
	},
];
