<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\ResourceLoader;

use MediaWiki\Extension\GlowThread\ResourceLoader\UiMessagesModule;
use MediaWikiIntegrationTestCase;

/**
 * The module reads its keys through the server's local cache, so it is made with the service
 * container (these tests were unit tests before that).
 *
 * @covers \MediaWiki\Extension\GlowThread\ResourceLoader\UiMessagesModule
 */
class UiMessagesModuleTest extends MediaWikiIntegrationTestCase {

	public function testModuleShipsKeysOfTheUiFile() {
		$dir = sys_get_temp_dir() . '/gt-ui-' . getmypid();
		mkdir( $dir );
		file_put_contents( "$dir/en.json", json_encode( [ '@metadata' => [], 'glowthread-ui-x' => 'X' ] ) );
		try {
			$module = new UiMessagesModule(
				[ 'uiMessagesFile' => 'en.json', 'messages' => [ 'core-msg' ] ], $dir, '/remote'
			);
			$this->assertSame( [ 'core-msg', 'glowthread-ui-x' ], $module->getMessages() );
		} finally {
			unlink( "$dir/en.json" );
			rmdir( $dir );
		}
	}

	public function testExcludePrefix() {
		$dir = sys_get_temp_dir() . '/gt-ui-x-' . getmypid();
		mkdir( $dir );
		file_put_contents( "$dir/en.json", json_encode( [
			'@metadata' => [], 'glowthread-ui-x' => 'X', 'glowthread-ui-admin-y' => 'Y',
		] ) );
		try {
			$module = new UiMessagesModule(
				[ 'uiMessagesFile' => 'en.json', 'uiMessagesExcludePrefix' => 'glowthread-ui-admin-' ], $dir, '/remote'
			);
			$this->assertSame( [ 'glowthread-ui-x' ], $module->getMessages() );
		} finally {
			unlink( "$dir/en.json" );
			rmdir( $dir );
		}
	}
}
