<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration;

use MediaWikiIntegrationTestCase;

/**
 * Bot passwords and OAuth consumers get GlowThread's rights through core's grants
 * (extension.json GrantPermissions).
 *
 * @coversNothing
 */
class GrantPermissionsTest extends MediaWikiIntegrationTestCase {

	public function testGrants() {
		$grants = $this->getServiceContainer()->getGrantsInfo();
		$this->assertSame( [], array_diff(
			[ 'glowthread-post', 'glowthread-react', 'glowthread-report' ],
			$grants->getGrantRights( [ 'editpage' ] )
		) );
		$this->assertContains( 'glowthread-configure', $grants->getGrantRights( [ 'editsiteconfig' ] ) );
		// Hiding and restoring comments can be undone, like deleting pages.
		$this->assertContains( 'glowthread-moderate', $grants->getGrantRights( [ 'delete' ] ) );
	}

	/**
	 * Erasing cannot be undone, so it is not part of "Delete pages" (risk "vandalism") but of
	 * "Hide users and suppress revisions" (risk "security").
	 */
	public function testEraseNeedsTheOversightGrant() {
		$grants = $this->getServiceContainer()->getGrantsInfo();
		$this->assertNotContains( 'glowthread-erase', $grants->getGrantRights( [ 'delete' ] ) );
		$this->assertContains( 'glowthread-erase', $grants->getGrantRights( [ 'oversight' ] ) );
		$withErase = array_filter(
			$grants->getRightsByGrant(),
			static fn ( $rights ) => in_array( 'glowthread-erase', $rights, true )
		);
		$this->assertSame( [ 'oversight' ], array_keys( $withErase ) );
		$this->assertSame( 'security', $grants->getRiskGroupsByGrant()['oversight'] );
	}
}
