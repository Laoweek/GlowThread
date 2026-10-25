<?php

namespace MediaWiki\Extension\GlowThread\Hooks;

use MediaWiki\Extension\GlowThread\Log\ModerationLogger;
use MediaWiki\User\Hook\UserGetReservedNamesHook;

/**
 * Reserves the name of the system user that logs automatic holds (ModerationLogger::SYSTEM_USER),
 * so that nobody can register it and appear to act for GlowThread.
 */
class ReservedNamesHooks implements UserGetReservedNamesHook {

	/** @inheritDoc */
	public function onUserGetReservedNames( &$reservedUsernames ) {
		$reservedUsernames[] = ModerationLogger::SYSTEM_USER;
	}
}
