<?php

namespace MediaWiki\Extension\GlowThread\Tests\Unit\ResourceLoader;

use MediaWiki\Extension\GlowThread\ResourceLoader\UiMessagesModule;
use MediaWikiUnitTestCase;
use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * @covers \MediaWiki\Extension\GlowThread\ResourceLoader\UiMessagesModule
 */
class UiMessagesModuleTest extends MediaWikiUnitTestCase {

	public function testReadKeysSkipsMetadata() {
		$path = tempnam( sys_get_temp_dir(), 'gt' );
		file_put_contents( $path, json_encode( [
			'@metadata' => [ 'authors' => [] ],
			'glowthread-ui-a' => 'A',
			'glowthread-ui-b' => 'B',
		] ) );
		try {
			$this->assertSame( [ 'glowthread-ui-a', 'glowthread-ui-b' ], UiMessagesModule::readKeys( $path ) );
		} finally {
			unlink( $path );
		}
	}

	public function testReadKeysOfMissingOrBadFile() {
		$this->assertSame( [], UiMessagesModule::readKeys( '/nonexistent/glowthread.json' ) );
	}

	public function testReadKeysCachedFollowsTheContent() {
		$path = tempnam( sys_get_temp_dir(), 'gt' );
		$cache = new HashBagOStuff();
		try {
			file_put_contents( $path, json_encode( [ 'glowthread-ui-a' => 'A' ] ) );
			touch( $path, 1000 );
			clearstatcache();
			$this->assertSame( [ 'glowthread-ui-a' ], UiMessagesModule::readKeysCached( $cache, $path ) );
			$this->assertSame( [ 'glowthread-ui-a' ], UiMessagesModule::readKeysCached( $cache, $path ) );

			// Other content with the same modification time, as a deploy can leave it
			file_put_contents( $path, json_encode( [ 'glowthread-ui-b' => 'B' ] ) );
			touch( $path, 1000 );
			clearstatcache();
			$this->assertSame( [ 'glowthread-ui-b' ], UiMessagesModule::readKeysCached( $cache, $path ) );
		} finally {
			unlink( $path );
		}
		$this->assertSame( [], UiMessagesModule::readKeysCached( $cache, '/nonexistent/glowthread.json' ) );
	}
}
