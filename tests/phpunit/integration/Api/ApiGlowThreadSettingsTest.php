<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Api;

/**
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Api\ApiGlowThreadSettings
 * @covers \MediaWiki\Extension\GlowThread\Settings\SettingsService
 */
class ApiGlowThreadSettingsTest extends GlowThreadApiTestCase {

	private function set( array $settings, $performer = null ): array {
		return $this->gtApi( [
			'action' => 'glowthreadsettings', 'settings' => json_encode( $settings ), 'reason' => 'test',
		], $performer ?? $this->getTestSysop()->getUser() )['glowthreadsettings'];
	}

	public function testChangeAndResetIsLoggedWithOldAndNewValues() {
		// Not set in LocalSettings, so it starts at the built-in 24 and can be changed
		$this->overrideConfigValue( 'GlowThreadNewAccountHours', null );
		$result = $this->set( [ 'newAccountHours' => 48, 'reportReasons' => [ 'other', 'spam' ] ] );
		$this->assertSame( [
			[ 'name' => 'newAccountHours', 'old' => 24, 'new' => 48 ],
			[ 'name' => 'reportReasons', 'old' => [ 'spam', 'abuse', 'offtopic', 'spoiler', 'misinfo', 'other' ],
				'new' => [ 'spam', 'other' ] ],
		], $result['changed'] );
		$this->assertSame( 48, $this->gtService( 'SettingsService' )->get( 'newAccountHours' ) );

		// The result names its log entry
		$this->assertSame( (int)$this->gtLogRows( 'glowthread', 'settings' )[0]->log_id, $result['logId'] );
		$params = unserialize( $this->gtLogRows( 'glowthread', 'settings' )[0]->log_params );
		$this->assertSame( [ [ 'newAccountHours', 24, 48 ],
			[ 'reportReasons', [ 'spam', 'abuse', 'offtopic', 'spoiler', 'misinfo', 'other' ], [ 'spam', 'other' ] ] ],
			$params['4::changes'] );

		$reset = $this->set( [ 'newAccountHours' => null ] );
		$this->assertSame( [ [ 'name' => 'newAccountHours', 'old' => 48, 'new' => 24 ] ], $reset['changed'] );
		$this->assertCount( 2, $this->gtLogRows( 'glowthread', 'settings' ) );
	}

	/**
	 * Settings changes count against glowthread-configure, which sysops cannot bypass, and the
	 * error says how long to wait.
	 */
	public function testRateLimitedWithRetryAfter() {
		$this->overrideConfigValue( 'RateLimits', [
			'glowthread-configure' => [ 'user' => [ 1, 60 ], '&can-bypass' => false ],
		] );
		$this->set( [ 'maxLength' => 900 ] );
		$error = $this->gtApiErrorArray( [
			'action' => 'glowthreadsettings', 'settings' => json_encode( [ 'maxLength' => 800 ] ),
		], $this->getTestSysop()->getUser() );
		$this->assertSame( 'ratelimited', $error['code'] );
		$this->assertSame( 60, $error['data']['retryAfter'] );
	}

	/**
	 * The answer to a save shows the saved values, also while the request's transaction is
	 * still open (web requests), when the shared cache still holds the old ones.
	 */
	public function testSaveAnswersWithTheNewValuesInsideATransaction() {
		$settings = $this->gtService( 'SettingsService' );
		$this->assertSame( 1000, $settings->describe()['maxLength']['value'], 'warms the cache' );
		$dbw = $this->getDb();
		$dbw->begin( __METHOD__ );
		try {
			$this->assertStatusGood( $settings->update(
				[ 'maxLength' => 777 ], $this->getTestSysop()->getAuthority(), ''
			) );
			$this->assertSame( 777, $settings->describe()['maxLength']['value'] );
			$this->assertSame( 777, $settings->get( 'maxLength' ) );
		} finally {
			$dbw->commit( __METHOD__ );
		}
	}

	public static function provideInvalid() {
		yield 'out of range' => [ [ 'maxLength' => 0 ], 'badsettingvalue' ];
		yield 'too large' => [ [ 'maxLength' => 100000 ], 'badsettingvalue' ];
		yield 'wrong type' => [ [ 'maxMentions' => 'five' ], 'badsettingvalue' ];
		yield 'float' => [ [ 'maxMentions' => 2.5 ], 'badsettingvalue' ];
		yield 'unknown reason' => [ [ 'reportReasons' => [ 'spam', 'hate' ] ], 'badsettingvalue' ];
		yield 'empty list' => [ [ 'reportReasons' => [] ], 'badsettingvalue' ];
		yield 'unknown setting' => [ [ 'colour' => 'pink' ], 'unknownsetting' ];
	}

	/**
	 * @dataProvider provideInvalid
	 */
	public function testValidation( array $settings, string $code ) {
		$this->assertSame( $code, $this->gtApiError( [
			'action' => 'glowthreadsettings', 'settings' => json_encode( $settings ),
		], $this->getTestSysop()->getUser() ) );
		$this->assertCount( 0, $this->gtLogRows( 'glowthread', 'settings' ) );
	}

	public function testNotAnObject() {
		foreach ( [ '[1,2]', '42', 'not json' ] as $json ) {
			$this->assertSame( 'badsettingsjson', $this->gtApiError( [
				'action' => 'glowthreadsettings', 'settings' => $json,
			], $this->getTestSysop()->getUser() ), $json );
		}
		$this->assertSame( 'nochange', $this->gtApiError( [
			'action' => 'glowthreadsettings', 'settings' => '{}',
		], $this->getTestSysop()->getUser() ) );
	}

	public function testOperatorCanLockAndUnlock() {
		$this->overrideConfigValue( 'GlowThreadOnWikiSettings', [ 'maxLength' => false, 'maxMentions' => true ] );
		$this->assertSame( 'settinglocked', $this->gtApiError( [
			'action' => 'glowthreadsettings', 'settings' => '{"maxLength":500}',
		], $this->getTestSysop()->getUser() ) );
		$this->assertSame( 'settinglocked', $this->gtApiError( [
			'action' => 'glowthreadsettings', 'settings' => '{"publicLog":false}',
		], $this->getTestSysop()->getUser() ), 'not in the map' );
		$this->set( [ 'maxMentions' => 2 ] );
		$this->assertSame( 2, $this->gtService( 'SettingsService' )->get( 'maxMentions' ) );
	}

	public function testLockedSettingIgnoresStoredOverride() {
		$this->set( [ 'maxLength' => 500 ] );
		$this->overrideConfigValue( 'GlowThreadOnWikiSettings', [ 'maxLength' => false ] );
		$this->assertSame( 1000, $this->gtService( 'SettingsService' )->get( 'maxLength' ) );
	}

	public function testValueSetInLocalSettingsLocksTheSetting() {
		$this->set( [ 'maxLength' => 500 ] );
		$this->overrideConfigValue( 'GlowThreadMaxLength', 1000 );
		$settings = $this->gtService( 'SettingsService' );
		$this->assertSame( 1000, $settings->get( 'maxLength' ) );
		$this->assertFalse( $settings->describe()['maxLength']['editable'] );
		$this->assertSame( 'settinglocked', $this->gtApiError( [
			'action' => 'glowthreadsettings', 'settings' => '{"maxLength":700}',
		], $this->getTestSysop()->getUser() ) );
	}

	public function testNeedsConfigureRightAndNoBlock() {
		$this->assertSame( 'permissiondenied', $this->gtApiError( [
			'action' => 'glowthreadsettings', 'settings' => '{"maxLength":500}',
		], $this->gtUser() ) );
		$admin = $this->gtUser( [ 'sysop' ] );
		$this->gtBlockSitewide( $admin );
		$this->assertStringContainsString( 'blocked', $this->gtApiError( [
			'action' => 'glowthreadsettings', 'settings' => '{"maxLength":500}',
		], $admin ) );
	}

	public function testSettingsApplyToPosting() {
		$this->set( [ 'maxLength' => 3 ] );
		$page = $this->gtPage();
		$this->assertSame( 'toolong', $this->gtApiError( [
			'action' => 'glowthreadpost', 'pageid' => $page->getId(), 'text' => 'four',
		], $this->gtUser() ) );
	}
}
