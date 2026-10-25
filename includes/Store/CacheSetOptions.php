<?php

namespace MediaWiki\Extension\GlowThread\Store;

use Wikimedia\Rdbms\Database;
use Wikimedia\Rdbms\IReadableDatabase;

/**
 * WAN object cache set options for a value read from a database.
 *
 * Before MediaWiki 1.47, Database::getCacheSetOptions() tells the cache about replica lag and
 * uncommitted writes, so it caches such a value briefly or not at all. MediaWiki 1.47 refuses
 * cache writes during uncommitted writes on its own, ignores these options, and deprecated the
 * method (it warns, and fails tests). Remove this class once GlowThread requires 1.47.
 */
final class CacheSetOptions {

	/**
	 * @param IReadableDatabase $db The database the value was read from
	 * @return array Options to add to the $setOpts of a getWithSetCallback() callback
	 */
	public static function forRead( IReadableDatabase $db ): array {
		if ( version_compare( MW_VERSION, '1.47', '>=' ) ) {
			return [];
		}
		return Database::getCacheSetOptions( $db );
	}
}
