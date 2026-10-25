<?php

namespace MediaWiki\Extension\GlowThread\Tests\Unit\Log;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\GlowThread\Hooks\LogVisibilityHooks;
use MediaWiki\Extension\GlowThread\Log\CommentsLogFormatter;
use MediaWiki\Extension\GlowThread\Log\LegacyLogType;
use MediaWikiUnitTestCase;

/**
 * FlowThread's comments log type is registered only on wikis that used FlowThread, and never
 * over another extension's type of the same name. Elsewhere its old entries are hidden.
 *
 * @covers \MediaWiki\Extension\GlowThread\Log\LegacyLogType
 */
class LegacyLogTypeTest extends MediaWikiUnitTestCase {

	private const SETTINGS = [
		'LogTypes', 'FilterLogTypes', 'ActionFilteredLogs', 'LogActionsHandlers', 'LogRestrictions',
	];

	/**
	 * @return array<string,array> The log settings after the extension callback
	 */
	private function register( bool $legacyCompat, array $logTypes, array $handlers = [] ): array {
		$GLOBALS['wgGlowThreadLegacyCompat'] = $legacyCompat;
		$GLOBALS['wgLogTypes'] = $logTypes;
		$GLOBALS['wgFilterLogTypes'] = [];
		$GLOBALS['wgActionFilteredLogs'] = [];
		$GLOBALS['wgLogActionsHandlers'] = $handlers;
		$GLOBALS['wgLogRestrictions'] = [ 'suppress' => 'suppressionlog' ];
		LegacyLogType::onRegistration();
		$settings = [];
		foreach ( self::SETTINGS as $name ) {
			$settings[$name] = $GLOBALS["wg$name"];
		}
		return $settings;
	}

	/**
	 * With $wgGlowThreadLegacyCompat off, FlowThread's old entries are hidden from everyone
	 * (security review 2026-10-10, I6). The formatter still handles those left in RecentChanges.
	 */
	public function testFreshWikiDoesNotGetTheType() {
		$settings = $this->register( false, [ 'glowthread' ] );
		$this->assertSame( [ 'glowthread' ], $settings['LogTypes'] );
		$this->assertSame(
			[ 'suppress' => 'suppressionlog', 'comments' => LegacyLogType::HIDDEN_RIGHT ],
			$settings['LogRestrictions']
		);
		$this->assertSame( CommentsLogFormatter::class, $settings['LogActionsHandlers']['comments/*']['class'] );
		$this->assertFalse( LegacyLogType::isRegistered( new HashConfig( $settings ) ) );
	}

	public function testRegisteredWithLegacyCompat() {
		$settings = $this->register( true, [ 'glowthread' ] );
		$this->assertSame( [ 'glowthread', 'comments' ], $settings['LogTypes'] );
		$this->assertSame( CommentsLogFormatter::class, $settings['LogActionsHandlers']['comments/*']['class'] );
		$this->assertArrayHasKey( 'comments', $settings['ActionFilteredLogs'] );
		$this->assertSame(
			[ 'suppress' => 'suppressionlog', 'comments' => LogVisibilityHooks::VIEW_RIGHT ],
			$settings['LogRestrictions']
		);
		$this->assertTrue( LegacyLogType::isRegistered( new HashConfig( $settings ) ) );
	}

	public function testAddToShowsHiddenEntries() {
		$hidden = LegacyLogType::hideIn( [ 'LogTypes' => [], 'LogRestrictions' => [] ] );
		$this->assertSame(
			[ 'comments' => LogVisibilityHooks::VIEW_RIGHT ],
			LegacyLogType::addTo( $hidden )['LogRestrictions']
		);
	}

	public function testLeftToAnotherExtension() {
		// FlowThread itself, or Extension:Comments, registered a type of that name
		$handlers = [ 'comments/*' => 'LogFormatter' ];
		foreach ( [ true, false ] as $legacyCompat ) {
			$settings = $this->register( $legacyCompat, [ 'glowthread', 'comments' ], $handlers );
			$this->assertSame( [ 'glowthread', 'comments' ], $settings['LogTypes'] );
			$this->assertSame( [ 'comments/*' => 'LogFormatter' ], $settings['LogActionsHandlers'] );
			$this->assertSame( [], $settings['ActionFilteredLogs'] );
			$this->assertSame( [ 'suppress' => 'suppressionlog' ], $settings['LogRestrictions'] );
			$this->assertFalse( LegacyLogType::isRegistered( new HashConfig( $settings ) ) );
		}
	}
}
