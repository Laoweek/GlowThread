<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Log;

use ManualLogEntry;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Permissions\Authority;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\Title\Title;
use RecentChange;

/**
 * With $wgGlowThreadLegacyCompat off (the default, and the setting of these tests), FlowThread's
 * old log entries are hidden, as the docs say: list=logevents and Special:Log leave them out, and
 * their rows left in RecentChanges show no parameters (security review 2026-10-10, I6).
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Log\LegacyLogType
 * @covers \MediaWiki\Extension\GlowThread\Log\CommentsLogFormatter
 */
class LegacyLogHiddenTest extends ApiTestCase {
	use GlowThreadTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
	}

	public function testLegacyEntriesAreHiddenWithCompatOff() {
		$this->assertFalse( (bool)$this->getServiceContainer()->getMainConfig()->get( 'GlowThreadLegacyCompat' ) );

		$hidden = $this->gtUser();
		$this->assertStatusGood( $this->getServiceContainer()->getBlockUserFactory()->newBlockUser(
			$hidden,
			$this->getTestUser( [ 'sysop', 'suppress' ] )->getAuthority(),
			'infinity',
			'',
			[ 'isHideUser' => true, 'isAutoblocking' => false ]
		)->placeBlock() );
		// An entry FlowThread wrote, with its row in RecentChanges from that time (core adds no
		// row for a restricted type, so it is written directly)
		$entry = new ManualLogEntry( 'comments', 'delete' );
		$entry->setPerformer( $this->getTestSysop()->getUser() );
		$entry->setTarget( Title::newFromText( 'GlowThread legacy log page' ) );
		$entry->setParameters( [ '4::username' => $hidden->getName() ] );
		$logId = $entry->insert();
		$entry->getRecentChange( $logId )->save( RecentChange::SEND_NONE );

		$viewers = [
			'anonymous' => $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' ),
			'sysop' => $this->getTestSysop()->getAuthority(),
		];
		foreach ( $viewers as $who => $viewer ) {
			$events = $this->query(
				[ 'list' => 'logevents', 'leprop' => 'ids|type|details', 'lelimit' => 500 ], $viewer
			)['logevents'];
			$this->assertNotContains( $logId, array_column( $events, 'logid' ), $who );

			$changes = $this->query(
				[ 'list' => 'recentchanges', 'rctype' => 'log', 'rcprop' => 'ids|loginfo', 'rclimit' => 500 ],
				$viewer
			)['recentchanges'];
			$rows = array_values( array_filter( $changes, static fn ( $rc ) => ( $rc['logid'] ?? 0 ) === $logId ) );
			$this->assertCount( 1, $rows, $who );
			$this->assertSame( [], $rows[0]['logparams'] ?? [], $who );
			$this->assertStringNotContainsString( $hidden->getName(), json_encode( $changes ), $who );
		}
	}

	private function query( array $params, Authority $viewer ): array {
		return $this->doApiRequest( [ 'action' => 'query' ] + $params, null, false, $viewer )[0]['query'];
	}
}
