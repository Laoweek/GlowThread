<?php

namespace MediaWiki\Extension\GlowThread\Tests\Unit\Echo;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\GlowThread\Echo\EchoSetupHooks;
use MediaWiki\Extension\GlowThread\GlowThreadConfig;
use MediaWikiUnitTestCase;

/**
 * FlowThread's notification types are registered only on wikis that used FlowThread.
 *
 * @covers \MediaWiki\Extension\GlowThread\Echo\EchoSetupHooks
 */
class EchoSetupHooksTest extends MediaWikiUnitTestCase {

	/**
	 * @param bool $legacyCompat
	 * @param array $categories Categories registered before, by other extensions
	 * @return array{0:array,1:array} Notification types and categories after the hook
	 */
	private function register( bool $legacyCompat, array $categories = [] ): array {
		$hooks = new EchoSetupHooks( new GlowThreadConfig(
			new HashConfig( [ 'GlowThreadLegacyCompat' => $legacyCompat ] )
		) );
		$notifications = [];
		$icons = [];
		$hooks->onBeforeCreateEchoEvent( $notifications, $categories, $icons );
		return [ $notifications, $categories ];
	}

	public function testFreshWikiGetsOnlyGlowThreadTypes() {
		[ $notifications, $categories ] = $this->register( false );
		$this->assertSame( [ EchoSetupHooks::CATEGORY ], array_keys( $categories ) );
		$this->assertSame(
			[ EchoSetupHooks::CATEGORY ],
			array_values( array_unique( array_column( $notifications, 'category' ) ) )
		);
	}

	public function testLegacyCompatAddsFlowThreadTypes() {
		[ $notifications, $categories ] = $this->register( true );
		$this->assertArrayHasKey( EchoSetupHooks::TYPE_REPLY, $notifications );
		foreach ( array_keys( EchoSetupHooks::LEGACY_TYPES ) as $type ) {
			$this->assertSame( EchoSetupHooks::LEGACY_CATEGORY, $notifications[$type]['category'], $type );
		}
		// Kept out of Special:Preferences
		$this->assertSame( [ 'all' ], $categories[EchoSetupHooks::LEGACY_CATEGORY]['no-dismiss'] );
	}

	public function testFlowThreadsOwnRegistrationIsKept() {
		$flowThread = [ EchoSetupHooks::LEGACY_CATEGORY => [ 'priority' => 4 ] ];
		[ $notifications, $categories ] = $this->register( true, $flowThread );
		$this->assertSame( $flowThread, array_intersect_key( $categories, $flowThread ) );
		$this->assertArrayNotHasKey( 'flowthread_reply', $notifications );
		$this->assertArrayHasKey( EchoSetupHooks::TYPE_REPLY, $notifications );
	}
}
