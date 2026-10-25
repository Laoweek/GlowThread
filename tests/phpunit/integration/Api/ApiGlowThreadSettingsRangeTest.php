<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Api;

/**
 * On the wiki, autoHoldReporters is 0 (off) or at least 2, so one account alone cannot hide a
 * comment with a report.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Settings\SettingsService
 */
class ApiGlowThreadSettingsRangeTest extends GlowThreadApiTestCase {

	private function set( $value ): array {
		return $this->gtApi( [
			'action' => 'glowthreadsettings', 'settings' => json_encode( [ 'autoHoldReporters' => $value ] ),
		], $this->getTestSysop()->getUser() )['glowthreadsettings'];
	}

	public function testOneReporterIsRefused() {
		$this->assertSame( 'badsettingvalue', $this->gtApiError( [
			'action' => 'glowthreadsettings', 'settings' => json_encode( [ 'autoHoldReporters' => 1 ] ),
		], $this->getTestSysop()->getUser() ) );
		$this->assertCount( 0, $this->gtLogRows( 'glowthread', 'settings' ) );
		$status = $this->gtService( 'SettingsService' )->validateValue( 'autoHoldReporters', 1 );
		$this->assertStatusMessage( 'glowthread-error-setting-range-off', $status );
		$this->assertStringContainsString(
			'must be 0 (off) or between 2 and 100',
			wfMessage( $status->getMessages()[0] )->inLanguage( 'en' )->text()
		);
		$this->assertSame( 'badsettingvalue', $this->gtApiError( [
			'action' => 'glowthreadsettings', 'settings' => json_encode( [ 'autoHoldReporters' => -1 ] ),
		], $this->getTestSysop()->getUser() ) );
	}

	public function testOffAndTwoAreAccepted() {
		$settings = $this->gtService( 'SettingsService' );
		$this->set( 0 );
		$this->assertSame( 0, $settings->get( 'autoHoldReporters' ) );
		$this->set( 2 );
		$this->assertSame( 2, $settings->get( 'autoHoldReporters' ) );
		$described = $settings->describe()['autoHoldReporters'];
		$this->assertSame( [ 0, 2, 100 ], [ $described['min'], $described['minOn'], $described['max'] ] );
		$this->assertArrayNotHasKey( 'minOn', $settings->describe()['maxLength'] );
	}

	/**
	 * The operator can still choose 1 in LocalSettings.php. Values below 0 mean off.
	 */
	public function testLocalSettingsDefault() {
		$settings = $this->gtService( 'SettingsService' );
		$this->overrideConfigValue( 'GlowThreadAutoHoldReporters', 1 );
		$this->assertSame( 1, $settings->getDefault( 'autoHoldReporters' ) );
		$this->overrideConfigValue( 'GlowThreadAutoHoldReporters', -5 );
		$this->assertSame( 0, $settings->getDefault( 'autoHoldReporters' ) );
		$this->overrideConfigValue( 'GlowThreadAutoHoldReporters', 500 );
		$this->assertSame( 100, $settings->getDefault( 'autoHoldReporters' ) );
	}
}
