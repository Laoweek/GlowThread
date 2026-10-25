<?php

use MediaWiki\Extension\GlowThread\Compat\LegacyListService;
use MediaWiki\MediaWikiServices;

/** @phpcs-require-sorted-array */
return [
	'GlowThread.LegacyListService' => static function ( MediaWikiServices $services ): LegacyListService {
		return new LegacyListService(
			$services->get( 'GlowThread.Config' ),
			$services->get( 'GlowThread.PostStore' ),
			$services->get( 'GlowThread.PageStateStore' ),
			$services->get( 'GlowThread.CommentSerializer' ),
			$services->getActorStore(),
			$services->get( 'GlowThread.ActorInfoLookup' ),
			$services->getPageStore(),
			$services->getTitleFactory(),
			$services->getTitleFormatter(),
			$services->get( 'GlowThread.PageEligibility' )
		);
	},
];
