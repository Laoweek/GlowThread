<?php

namespace MediaWiki\Extension\GlowThread\Rest;

use MediaWiki\Extension\GlowThread\Filter\WordFilter;
use MediaWiki\Extension\GlowThread\Policy\RateLimitErrors;
use MediaWiki\Extension\GlowThread\Settings\SettingsService;
use MediaWiki\Rest\Response;

/**
 * GET /glowthread/v1/settings: settings with defaults and limits, the word-filter status and
 * the effective rate limits (read only, they live in LocalSettings.php).
 */
class SettingsHandler extends GlowThreadHandler {

	public function __construct(
		private readonly SettingsService $settings,
		private readonly WordFilter $wordFilter,
		private readonly RateLimitErrors $rateLimits
	) {
	}

	public function run(): Response {
		$authority = $this->getAuthority();
		if ( !$authority->isAllowedAny( 'glowthread-moderate', 'glowthread-configure' ) ) {
			return $this->error( 403, 'permission-denied' );
		}
		$parsed = $this->wordFilter->parse();
		return $this->privateJson( [
			'settings' => $this->settings->describe(),
			'canConfigure' => $authority->isAllowed( 'glowthread-configure' ),
			'filter' => [
				'page' => 'MediaWiki:GlowThread-filter',
				'rules' => count( $parsed['rules'] ),
				'invalid' => $parsed['invalid'],
			],
			'rateLimits' => $this->rateLimits->describe(),
		] );
	}
}
