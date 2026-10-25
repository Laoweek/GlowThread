<?php

namespace MediaWiki\Extension\GlowThread;

use MediaWiki\Config\Config;
use MediaWiki\Extension\GlowThread\Log\LegacyLogType;
use MediaWiki\Extension\GlowThread\Log\ModerationLogger;

/**
 * Typed access to the $wgGlowThread* configuration variables.
 *
 * Settings that can be overridden on-wiki are read through SettingsService instead, which
 * uses the values here as defaults.
 */
class GlowThreadConfig {

	public function __construct( private readonly Config $config ) {
	}

	/**
	 * @return int[] Namespace IDs where comments may appear
	 */
	public function getNamespaces(): array {
		$namespaces = [];
		foreach ( (array)$this->config->get( 'GlowThreadNamespaces' ) as $ns => $enabled ) {
			if ( $enabled ) {
				$namespaces[] = (int)$ns;
			}
		}
		return $namespaces;
	}

	public function getPageSize(): int {
		return max( 1, min( 50, (int)$this->config->get( 'GlowThreadPageSize' ) ) );
	}

	public function getReplyPreview(): int {
		return max( 0, min( 20, (int)$this->config->get( 'GlowThreadReplyPreview' ) ) );
	}

	public function getPopularCount(): int {
		return max( 0, min( 10, (int)$this->config->get( 'GlowThreadPopularCount' ) ) );
	}

	public function getPopularMinLikes(): int {
		return max( 1, (int)$this->config->get( 'GlowThreadPopularMinLikes' ) );
	}

	/**
	 * Half-life of the like score in days.
	 */
	public function getHotHalfLife(): float {
		return max( 0.01, (float)$this->config->get( 'GlowThreadHotHalfLife' ) );
	}

	/**
	 * s-maxage of the cacheable responses. 0 (not cacheable) unless $wgUseCdn is on: like
	 * MediaWiki's own s-maxage headers, so a cache the wiki does not purge never keeps comments.
	 */
	public function getCdnMaxAge(): int {
		if ( !$this->config->get( 'UseCdn' ) ) {
			return 0;
		}
		return max( 0, (int)$this->config->get( 'GlowThreadCdnMaxAge' ) );
	}

	/**
	 * The fixed colour scheme ($wgGlowThreadTheme): 'night' or 'day', or 'auto' to follow the skin
	 * and the reader's system setting. Unknown values count as 'auto'.
	 */
	public function getTheme(): string {
		$theme = $this->config->get( 'GlowThreadTheme' );
		return in_array( $theme, [ 'night', 'day' ], true ) ? $theme : 'auto';
	}

	/**
	 * The class for the html element that fixes the colour scheme, or null for 'auto'.
	 */
	public function getThemeClass(): ?string {
		$theme = $this->getTheme();
		return $theme === 'auto' ? null : "ext-glowthread-$theme";
	}

	public function isLegacyCompatEnabled(): bool {
		return (bool)$this->config->get( 'GlowThreadLegacyCompat' );
	}

	/**
	 * Log types whose entries the moderation history shows: GlowThread's, and FlowThread's old
	 * comments type while GlowThread registers it (LegacyLogType).
	 *
	 * @return string[]
	 */
	public function getHistoryLogTypes(): array {
		return LegacyLogType::isRegistered( $this->config )
			? [ ModerationLogger::TYPE, LegacyLogType::TYPE ]
			: [ ModerationLogger::TYPE ];
	}

	/**
	 * Most comments one author may have waiting for review. 0 means no cap.
	 */
	public function getMaxHeldPerUser(): int {
		return max( 0, (int)$this->config->get( 'GlowThreadMaxHeldPerUser' ) );
	}

	/**
	 * Raw default for an on-wiki setting, see SettingsService::DEFINITIONS.
	 *
	 * @param string $name Config variable name without the wg prefix
	 * @return mixed
	 */
	public function getRaw( string $name ) {
		return $this->config->get( $name );
	}

	/**
	 * @return array<string,bool> Setting name => editable on-wiki
	 */
	public function getOnWikiSettings(): array {
		return array_map( 'boolval', (array)$this->config->get( 'GlowThreadOnWikiSettings' ) );
	}

	/**
	 * The configuration list responses depend on, for the keys of the cached lists (ListCache):
	 * a change here makes new keys. On-wiki settings changes purge the lists instead.
	 *
	 * @return array
	 */
	public function getOutputConfig(): array {
		$names = [
			'GlowThreadNamespaces', 'GlowThreadPageSize', 'GlowThreadReplyPreview', 'GlowThreadPopularCount',
			'GlowThreadPopularMinLikes', 'GlowThreadHotHalfLife', 'GlowThreadOnWikiSettings',
			// The defaults of the on-wiki settings
			'GlowThreadMaxLength', 'GlowThreadMaxMentions', 'GlowThreadDuplicateWindow',
			'GlowThreadNewAccountHours', 'GlowThreadNewAccountApproved', 'GlowThreadAutoHoldReporters',
			'GlowThreadReportReasons',
			// Which accounts are temporary (the author's temp flag)
			'AutoCreateTempUser',
			// Raised by administrators to discard cached output
			'CacheEpoch',
		];
		$values = [];
		foreach ( $names as $name ) {
			$values[] = $this->config->get( $name );
		}
		return $values;
	}

	/**
	 * Path of rest.php ($wgRestPath), used to build the canonical list URLs.
	 */
	public function getRestPath(): string {
		return (string)$this->config->get( 'RestPath' );
	}
}
