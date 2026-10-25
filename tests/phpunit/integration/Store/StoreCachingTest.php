<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Store;

use MediaWiki\Extension\GlowThread\Store\PageStateStore;
use MediaWiki\Extension\GlowThread\Store\SettingsStore;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWikiIntegrationTestCase;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * Page states and settings are cached in the WAN object cache (and
 * page states in the process), and every change purges them.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Store\PageStateStore
 * @covers \MediaWiki\Extension\GlowThread\Store\SettingsStore
 */
class StoreCachingTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;

	private WANObjectCache $wanCache;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
		$this->wanCache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		// After a purge, values read during the hold-off are not cached at all
		$this->wanCache->useInterimHoldOffCaching( false );
	}

	public function testPageStateIsCachedAndPurgedOnChange() {
		$page = $this->gtPage();
		$provider = $this->getServiceContainer()->getConnectionProvider();
		$store = new PageStateStore( $provider, $this->wanCache );
		$this->assertSame( 'open', $store->getState( $page->getId() ) );
		$this->assertSame( 'open',
			$this->wanCache->get( $this->wanCache->makeKey( 'glowthread-pagestate', $page->getId() ) ) );

		// Another request (a new store on the same cache) changes the state
		$writer = new PageStateStore( $provider, $this->wanCache );
		$writer->setState( $this->getDb(), $page->getId(), 'locked', 1, '20261009000000' );
		$this->assertSame( 'locked', $writer->getState( $page->getId() ), 'the writer sees its change at once' );
		$this->assertFalse(
			$this->wanCache->get( $this->wanCache->makeKey( 'glowthread-pagestate', $page->getId() ) ),
			'the shared copy is purged'
		);
		$reader = new PageStateStore( $provider, $this->wanCache );
		$this->assertSame( 'locked', $reader->getState( $page->getId() ) );
	}

	public function testSettingsAreCachedAndPurgedOnChange() {
		$provider = $this->getServiceContainer()->getConnectionProvider();
		$store = new SettingsStore( $provider, $this->wanCache );
		$this->assertSame( [], $store->getAll() );
		$key = $this->wanCache->makeKey( 'glowthread-settings' );
		$this->assertSame( [], $this->wanCache->get( $key ) );

		$store->set( $this->getDb(), 'maxLength', 500, 1, '20261009000000' );
		$this->assertFalse( $this->wanCache->get( $key ), 'purged' );
		$this->assertSame( 500, ( new SettingsStore( $provider, $this->wanCache ) )->getAll()['maxLength']['value'] );

		$store->delete( $this->getDb(), 'maxLength' );
		$this->assertSame( [], ( new SettingsStore( $provider, $this->wanCache ) )->getAll() );
	}

	public function testSettingsChangeThroughTheServiceIsVisible() {
		$settings = $this->gtService( 'SettingsService' );
		$this->assertStatusGood( $settings->update(
			[ 'maxLength' => 321 ], $this->getTestSysop()->getAuthority(), ''
		) );
		$this->assertSame( 321, $settings->get( 'maxLength' ) );
	}
}
