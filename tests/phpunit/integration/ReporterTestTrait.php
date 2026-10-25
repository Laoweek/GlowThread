<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration;

use MediaWiki\Extension\GlowThread\Policy\WriteGuard;
use MediaWiki\User\User;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Reporters whose reports count toward automatic holds. For test classes that also use
 * GlowThreadTestTrait.
 */
trait ReporterTestTrait {

	/**
	 * A user whose reports count toward an automatic hold (WriteGuard::isEstablishedReporter):
	 * registered a day more than REPORTER_MIN_DAYS ago, with a visible comment on another page.
	 */
	protected function gtEstablishedReporter(): User {
		$previous = ConvertibleTimestamp::setFakeTime(
			ConvertibleTimestamp::time() - ( WriteGuard::REPORTER_MIN_DAYS + 1 ) * 86400
		);
		try {
			$user = $this->gtUser();
		} finally {
			ConvertibleTimestamp::setFakeTime( $previous );
		}
		$this->gtPost( $user, $this->gtPage( 'GlowThread reporter page' ), 'A comment of my own' );
		return $user;
	}
}
