<?php

namespace MediaWiki\Extension\GlowThread\Filter;

use MediaWiki\Extension\SpamBlacklist\BaseBlacklist;
use MediaWiki\ExternalLinks\LinkFilter;
use MediaWiki\User\User;

/**
 * Checks URLs against SpamBlacklist when that extension is loaded (soft dependency).
 */
class SpamChecker {

	public function __construct( private readonly bool $spamBlacklistLoaded ) {
	}

	public function isAvailable(): bool {
		return $this->spamBlacklistLoaded && class_exists( BaseBlacklist::class );
	}

	/**
	 * @param string[] $urls
	 * @param User $user
	 * @return string[] Blacklisted matches, empty when none or SpamBlacklist is not loaded
	 */
	public function getMatches( array $urls, User $user ): array {
		if ( !$urls || !$this->isAvailable() ) {
			return [];
		}
		// No title: every link is checked, not only links new to a page, and hits are not logged.
		$matches = BaseBlacklist::getSpamBlacklist()->filter(
			LinkFilter::getIndexedUrlsNonReversed( $urls ),
			null,
			$user,
			true
		);
		return is_array( $matches ) ? array_values( $matches ) : [];
	}
}
