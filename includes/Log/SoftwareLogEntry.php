<?php

namespace MediaWiki\Extension\GlowThread\Log;

use ManualLogEntry;

/**
 * A log entry the software makes on its own in someone else's request, such as an automatic hold
 * in the request of the report that caused it. Its RecentChanges row carries the IP address
 * 127.0.0.1, as CheckUser records it (CheckUserHooks), instead of the request's: rc_ip would keep
 * the reporter's address under the system user, and CheckUser's populateCheckUserTable.php copies
 * rc_ip when CheckUser is installed later.
 *
 * ModerationLogger builds the row with getRecentChange(), so this changes nothing else about how
 * the entry is published.
 */
class SoftwareLogEntry extends ManualLogEntry {

	public const IP = '127.0.0.1';

	/** @inheritDoc */
	public function getRecentChange( $newId = 0 ) {
		$rc = parent::getRecentChange( $newId );
		// RecentChange::setAttribute() is from MediaWiki 1.45
		$rc->setAttribs( [ 'rc_ip' => self::IP ] + $rc->getAttributes() );
		return $rc;
	}
}
