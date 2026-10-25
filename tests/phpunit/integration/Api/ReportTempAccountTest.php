<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Api;

use MediaWiki\Extension\GlowThread\Model\PostStatus;
use MediaWiki\Extension\GlowThread\Policy\WriteGuard;
use MediaWiki\Extension\GlowThread\Tests\Integration\ReporterTestTrait;
use MediaWiki\MainConfigNames;
use MediaWiki\Request\FauxRequest;
use MediaWiki\User\User;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Temporary accounts counted toward automatic holds once they were a week old with a visible
 * comment. Anyone gets a new one by clearing their cookies, so they never count now. Their
 * reports still reach the moderation queue.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Policy\WriteGuard
 * @covers \MediaWiki\Extension\GlowThread\Service\ReportService
 */
class ReportTempAccountTest extends GlowThreadApiTestCase {
	use ReporterTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$tempUsers = $this->getServiceContainer()->getMainConfig()->get( MainConfigNames::AutoCreateTempUser );
		$this->overrideConfigValues( [
			MainConfigNames::AutoCreateTempUser => [ 'enabled' => true ] + $tempUsers,
			'GlowThreadAutoHoldReporters' => 2,
		] );
		$this->setGroupPermissions( [ 'temp' => [ 'glowthread-post' => true, 'glowthread-report' => true ] ] );
	}

	/**
	 * Through the service: an API request as a temporary account fails the CSRF check on
	 * MediaWiki 1.46 in tests.
	 */
	private function report( User $performer, int $id ): array {
		$status = $this->gtService( 'ReportService' )->report( $performer, $id, 'spam', '' );
		$this->assertStatusGood( $status );
		return $status->getValue();
	}

	/**
	 * A temporary account that is otherwise like gtEstablishedReporter(): registered a day more
	 * than REPORTER_MIN_DAYS ago, with a visible comment.
	 */
	private function oldTempAccount(): User {
		$previous = ConvertibleTimestamp::setFakeTime(
			ConvertibleTimestamp::time() - ( WriteGuard::REPORTER_MIN_DAYS + 1 ) * 86400
		);
		try {
			$status = $this->getServiceContainer()->getTempUserCreator()->create( null, new FauxRequest() );
		} finally {
			ConvertibleTimestamp::setFakeTime( $previous );
		}
		$this->assertStatusGood( $status );
		$user = $status->getUser();
		$this->assertTrue( $user->isTemp() );
		$this->gtPost( $user, $this->gtPage( 'GlowThread reporter page' ), 'A comment of my own' );
		return $user;
	}

	public function testTemporaryAccountsDoNotCountTowardAutomaticHolds() {
		$temp = $this->oldTempAccount();
		$actorId = $this->getServiceContainer()->getActorNormalization()->acquireActorId( $temp, $this->getDb() );
		$this->assertFalse( $this->gtService( 'WriteGuard' )->isEstablishedReporter( $temp, $actorId ) );

		$post = $this->gtPost( $this->gtUser(), $this->gtPage(), 'x' );
		$this->assertFalse( $this->report( $temp, $post->getId() )['held'] );
		$this->assertFalse( $this->report( $this->gtEstablishedReporter(), $post->getId() )['held'],
			'one eligible reporter of the two needed' );
		$this->assertSame( PostStatus::VISIBLE, $this->gtReload( $post )->getStatus() );
		$this->assertSame( 2, $this->gtReload( $post )->getOpenReportCount(), 'both reports reach the queue' );
		$this->assertTrue( $this->report( $this->gtEstablishedReporter(), $post->getId() )['held'] );
	}
}
