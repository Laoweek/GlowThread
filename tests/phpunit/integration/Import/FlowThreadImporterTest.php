<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Import;

use ManualLogEntry;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\GlowThread\Import\FlowThreadImporter;
use MediaWiki\Extension\GlowThread\Import\ImportOptions;
use MediaWiki\Extension\GlowThread\Import\ImportReport;
use MediaWiki\Extension\GlowThread\Import\LegacyHtmlConverter;
use MediaWiki\Extension\GlowThread\Import\LegacyId;
use MediaWiki\Extension\GlowThread\Import\LegacySource;
use MediaWiki\Extension\GlowThread\Log\LegacyLogType;
use MediaWiki\Extension\GlowThread\Log\ModerationLogger;
use MediaWiki\Extension\GlowThread\Model\HoldReason;
use MediaWiki\Extension\GlowThread\Model\PageState;
use MediaWiki\Extension\GlowThread\Model\PostStatus;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Extension\GlowThread\Text\TextProcessor;
use MediaWiki\MainConfigNames;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * Hand-written FlowThread rows (no real comments) through the whole importer.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Import\FlowThreadImporter
 * @covers \MediaWiki\Extension\GlowThread\Import\LegacySource
 * @covers \MediaWiki\Extension\GlowThread\Import\ImportOptions
 * @covers \MediaWiki\Extension\GlowThread\Import\ImportReport
 */
class FlowThreadImporterTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;

	private const WRAP = '<div class="mw-content-ltr mw-parser-output" lang="en" dir="ltr">';
	private const IP = '203.0.113.9';
	private const NOW = '20261009000000';
	/** 2025-03-09 04:49:16 UTC in milliseconds */
	private const T0 = 1741495756000;

	private const GT_TABLES = [ 'glowthread_post', 'glowthread_reaction', 'glowthread_report', 'glowthread_page' ];

	private int $pageA;
	private int $pageB;
	private User $alice;
	private User $bob;
	private User $sysop;

	protected function setUp(): void {
		parent::setUp();
		$db = $this->getDb();
		if ( !in_array( $db->getType(), [ 'mysql', 'sqlite' ], true ) ) {
			$this->markTestSkipped( 'FlowThread only ever ran on MySQL and SQLite' );
		}
		// IP actors exist only while temporary accounts are off (the 1.46 installer turns them on)
		$this->setTemporaryAccounts( false );
		$this->createLegacyTables();
		$this->pageA = $this->getExistingTestPage( Title::newFromText( 'GlowThread import A' ) )->getId();
		$this->pageB = $this->getExistingTestPage( Title::newFromText( 'GlowThread import B' ) )->getId();
		$this->alice = $this->getMutableTestUser()->getUser();
		$this->bob = $this->getMutableTestUser()->getUser();
		$this->sysop = $this->getTestSysop()->getUser();
	}

	protected function tearDown(): void {
		$db = $this->getDb();
		foreach ( [ LegacySource::POST_TABLE, LegacySource::ATTITUDE_TABLE, LegacySource::CONTROL_TABLE ] as $table ) {
			$db->query( 'DROP TABLE IF EXISTS ' . $db->tableName( $table ), __METHOD__ );
		}
		parent::tearDown();
	}

	private function setTemporaryAccounts( bool $enabled ): void {
		$config = $this->getServiceContainer()->getMainConfig()->get( MainConfigNames::AutoCreateTempUser );
		$this->overrideConfigValue(
			MainConfigNames::AutoCreateTempUser, [ 'enabled' => $enabled, 'known' => $enabled ] + $config
		);
	}

	private function createLegacyTables(): void {
		$db = $this->getDb();
		$post = $db->tableName( LegacySource::POST_TABLE );
		$att = $db->tableName( LegacySource::ATTITUDE_TABLE );
		$ctrl = $db->tableName( LegacySource::CONTROL_TABLE );
		foreach ( [ $post, $att, $ctrl ] as $table ) {
			$db->query( "DROP TABLE IF EXISTS $table", __METHOD__ );
		}
		if ( $db->getType() === 'mysql' ) {
			// FlowThread's own sql/mysql.sql and sql/control.sql
			$db->query( "CREATE TABLE $post (flowthread_id BINARY(11) NOT NULL PRIMARY KEY, " .
				'flowthread_pageid INT(10) UNSIGNED NOT NULL, flowthread_userid INT(10) UNSIGNED NOT NULL, ' .
				'flowthread_username VARCHAR(255) NOT NULL, flowthread_text TEXT NOT NULL, ' .
				'flowthread_parentid BINARY(11), flowthread_status TINYINT(1) UNSIGNED NOT NULL, ' .
				'flowthread_like INT(4) NOT NULL, flowthread_report INT(4) NOT NULL) DEFAULT CHARSET=utf8mb4',
				__METHOD__ );
			$db->query( "CREATE TABLE $att (flowthread_att_id BINARY(11) NOT NULL, " .
				'flowthread_att_type TINYINT(1) UNSIGNED NOT NULL, flowthread_att_userid INT(10) UNSIGNED NOT NULL)',
				__METHOD__ );
			$db->query( "CREATE TABLE $ctrl (flowthread_ctrl_pageid INT UNSIGNED NOT NULL PRIMARY KEY, " .
				'flowthread_ctrl_status INT NOT NULL)', __METHOD__ );
		} else {
			$db->query( "CREATE TABLE $post (flowthread_id BLOB NOT NULL PRIMARY KEY, " .
				'flowthread_pageid INTEGER NOT NULL, flowthread_userid INTEGER NOT NULL, ' .
				'flowthread_username TEXT NOT NULL, flowthread_text TEXT NOT NULL, flowthread_parentid BLOB, ' .
				'flowthread_status INTEGER NOT NULL, flowthread_like INTEGER NOT NULL, ' .
				'flowthread_report INTEGER NOT NULL)', __METHOD__ );
			$db->query( "CREATE TABLE $att (flowthread_att_id BLOB NOT NULL, flowthread_att_type INTEGER NOT NULL, " .
				'flowthread_att_userid INTEGER NOT NULL)', __METHOD__ );
			$db->query( "CREATE TABLE $ctrl (flowthread_ctrl_pageid INTEGER NOT NULL PRIMARY KEY, " .
				'flowthread_ctrl_status INTEGER NOT NULL)', __METHOD__ );
		}
	}

	/**
	 * A FlowThread ID made the way GlobalIdGenerator::newTimestampedUID88() makes them.
	 */
	private static function legacyId( int $seconds, int $counter = 0 ): string {
		return hex2bin( sprintf( '%012x%02x%08x', ( ( self::T0 + $seconds * 1000 ) << 2 ) | ( $counter >> 8 ),
			$counter & 0xff, 0x0a0b0c0d ) );
	}

	private function addPost(
		int $seconds, int $page, ?User $user, string $html, ?string $parent = null, int $status = 0,
		int $like = 0, int $report = 0, string $anonName = self::IP
	): string {
		$id = self::legacyId( $seconds );
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( LegacySource::POST_TABLE )
			->row( [
				'flowthread_id' => $id,
				'flowthread_pageid' => $page,
				'flowthread_userid' => $user ? $user->getId() : 0,
				'flowthread_username' => $user ? $user->getName() : $anonName,
				'flowthread_text' => $html,
				'flowthread_parentid' => $parent,
				'flowthread_status' => $status,
				'flowthread_like' => $like,
				'flowthread_report' => $report,
			] )
			->caller( __METHOD__ )
			->execute();
		return $id;
	}

	private function addAttitude( string $postId, int $type, int $userId ): void {
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( LegacySource::ATTITUDE_TABLE )
			->row( [
				'flowthread_att_id' => $postId, 'flowthread_att_type' => $type, 'flowthread_att_userid' => $userId,
			] )
			->caller( __METHOD__ )
			->execute();
	}

	private function addControl( int $page, int $status ): void {
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( LegacySource::CONTROL_TABLE )
			->row( [ 'flowthread_ctrl_pageid' => $page, 'flowthread_ctrl_status' => $status ] )
			->caller( __METHOD__ )
			->execute();
	}

	private function import(
		string $mode, array $options = [], ?FlowThreadImporter &$importer = null, ?callable $waitForReplication = null
	): ImportReport {
		$services = $this->getServiceContainer();
		$importer = new FlowThreadImporter(
			$services->getConnectionProvider(),
			new LegacyHtmlConverter(
				$services->get( 'GlowThread.TextProcessor' ), 'https://wiki.example', '/wiki/$1', '/index.php',
				// The canonical User namespace and its local name on a Chinese wiki
				[ 'User', '用户' ]
			),
			$services->getActorNormalization(),
			$services->get( 'GlowThread.PageStateStore' ),
			$services->getTempUserConfig(),
			$services->getUserIdentityLookup(),
			$services->get( 'GlowThread.TextProcessor' ),
			$waitForReplication
		);
		return $importer->run(
			new LegacySource( $this->getDb() ),
			new ImportOptions( ...$options ),
			$mode,
			$this->sysop,
			self::NOW
		);
	}

	/**
	 * @return array<string,\stdClass> Legacy hex => glowthread_post row
	 */
	private function importedRows(): array {
		$rows = [];
		$res = $this->getDb()->newSelectQueryBuilder()
			->select( '*' )
			->from( 'glowthread_post' )
			->where( $this->getDb()->expr( 'gtp_legacy_id', '!=', null ) )
			->caller( __METHOD__ )
			->fetchResultSet();
		foreach ( $res as $row ) {
			$rows[(string)$row->gtp_legacy_id] = $row;
		}
		return $rows;
	}

	/**
	 * @return array<string,array> Every row of the GlowThread tables, for before/after comparisons
	 */
	private function snapshot(): array {
		$snapshot = [];
		foreach ( self::GT_TABLES as $table ) {
			$rows = [];
			foreach ( $this->getDb()->newSelectQueryBuilder()->select( '*' )->from( $table )
				->caller( __METHOD__ )->fetchResultSet() as $row
			) {
				$rows[] = (array)$row;
			}
			$snapshot[$table] = $rows;
		}
		return $snapshot;
	}

	/**
	 * @return array<string,array> Every row of the FlowThread tables
	 */
	private function legacySnapshot(): array {
		$snapshot = [];
		foreach ( [ LegacySource::POST_TABLE, LegacySource::ATTITUDE_TABLE, LegacySource::CONTROL_TABLE ] as $table ) {
			$rows = [];
			foreach ( $this->getDb()->newSelectQueryBuilder()->select( '*' )->from( $table )
				->caller( __METHOD__ )->fetchResultSet() as $row
			) {
				$rows[] = array_map( 'bin2hex', array_map( 'strval', (array)$row ) );
			}
			$snapshot[$table] = $rows;
		}
		return $snapshot;
	}

	private function actorName( int $actorId ): string {
		return (string)$this->getDb()->newSelectQueryBuilder()
			->select( 'actor_name' )->from( 'actor' )->where( [ 'actor_id' => $actorId ] )
			->caller( __METHOD__ )->fetchField();
	}

	private function actorOf( User $user ): int {
		return $this->getServiceContainer()->getActorNormalization()->findActorId( $user, $this->getDb() );
	}

	/**
	 * A small thread with every placement case on page A, and one comment on page B.
	 *
	 * @return array<string,string> Name => raw FlowThread ID
	 */
	private function seedThread(): array {
		$a = $this->pageA;
		$ids = [];
		$ids['top'] = $this->addPost( 0, $a, $this->alice, self::WRAP . "<p>Top comment\n</p></div>", null, 0, 2, 1 );
		$ids['r1'] = $this->addPost( 1, $a, null, 'Reply one', $ids['top'] );
		$ids['r2'] = $this->addPost( 2, $a, $this->bob, 'Reply two', $ids['r1'] );
		$ids['r3'] = $this->addPost( 3, $a, $this->alice,
			'@<a href="/wiki/User:' . rawurlencode( $this->bob->getName() ) . '">' . $this->bob->getName()
				. '</a> Reply three',
			$ids['r2'] );
		$ids['top2'] = $this->addPost( 4, $a, $this->bob, 'Another comment' );
		$ids['b'] = $this->addPost( 5, $this->pageB, null, 'A comment on page B' );
		$this->addAttitude( $ids['top'], LegacySource::ATTITUDE_LIKE, $this->bob->getId() );
		$this->addAttitude( $ids['top'], LegacySource::ATTITUDE_LIKE, $this->bob->getId() );
		$this->addAttitude( $ids['top'], LegacySource::ATTITUDE_LIKE, $this->sysop->getId() );
		$this->addAttitude( $ids['top'], LegacySource::ATTITUDE_REPORT, $this->alice->getId() );
		return $ids;
	}

	public function testImportsPlacementFloorsAuthorsAndText() {
		$ids = $this->seedThread();
		$report = $this->import( FlowThreadImporter::MODE_APPLY );
		$this->assertSame( [], $report->getMismatches() );
		$this->assertSame( 6, $report->get( 'comments', 'inserted' ) );

		$rows = $this->importedRows();
		$this->assertCount( 6, $rows );
		$top = $rows[bin2hex( $ids['top'] )];
		$r1 = $rows[bin2hex( $ids['r1'] )];
		$r2 = $rows[bin2hex( $ids['r2'] )];
		$r3 = $rows[bin2hex( $ids['r3'] )];
		$top2 = $rows[bin2hex( $ids['top2'] )];

		$this->assertSame( 'Top comment', $top->gtp_text, 'Wrapper removed' );
		$this->assertSame( '20250309044916', wfTimestamp( TS_MW, $top->gtp_created ) );
		$this->assertSame( '20250309044919', wfTimestamp( TS_MW, $r3->gtp_created ) );
		$this->assertSame( LegacyId::fromBinary( $ids['top'] )->getHex(), (string)$top->gtp_legacy_id );

		// Placement: depth 3 is shown at depth 2 under the depth-1 ancestor
		$this->assertSame( [ null, null, null, 0 ],
			[ $top->gtp_parent, $top->gtp_reply_to, $top->gtp_thread, (int)$top->gtp_depth ] );
		$this->assertSame( [ $top->gtp_id, $top->gtp_id, $top->gtp_id, 1 ],
			[ $r1->gtp_parent, $r1->gtp_reply_to, $r1->gtp_thread, (int)$r1->gtp_depth ] );
		$this->assertSame( [ $r1->gtp_id, $r1->gtp_id, $top->gtp_id, 2 ],
			[ $r2->gtp_parent, $r2->gtp_reply_to, $r2->gtp_thread, (int)$r2->gtp_depth ] );
		$this->assertSame( [ $r1->gtp_id, $r2->gtp_id, $top->gtp_id, 2 ],
			[ $r3->gtp_parent, $r3->gtp_reply_to, $r3->gtp_thread, (int)$r3->gtp_depth ] );
		$this->assertSame( TextProcessor::mentionToken( $this->bob->getId() ) . ' Reply three', $r3->gtp_text );

		// Floors per page in creation order, and the page counter continues above them
		$this->assertSame( [ 1, 2, 3, 4, 5 ], array_map( 'intval',
			[ $top->gtp_seq, $r1->gtp_seq, $r2->gtp_seq, $r3->gtp_seq, $top2->gtp_seq ] ) );
		$this->assertSame( 1, (int)$rows[bin2hex( $ids['b'] )]->gtp_seq );
		$pageStates = $this->getServiceContainer()->get( 'GlowThread.PageStateStore' );
		$this->assertSame( 5, $pageStates->get( $this->pageA )['seq'] );

		// Authors: registered users by user ID, anonymous authors as IP actors
		$this->assertSame( $this->actorOf( $this->alice ), (int)$top->gtp_actor );
		$this->assertSame( self::IP, $this->actorName( (int)$r1->gtp_actor ) );

		// Likes deduplicated with a null timestamp, the report open, counters from the rows
		$this->assertSame( 2, (int)$top->gtp_like_count );
		$this->assertSame( 1, (int)$top->gtp_open_report_count );
		$this->assertSame( 1, (int)$top->gtp_reply_count );
		$this->assertSame( 2, (int)$r1->gtp_reply_count, 'r2 and r3 are both shown under r1' );
		$this->assertSame( 1, $report->get( 'likes', 'skipped: exact duplicate' ) );
		$likes = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'gtr_type', 'gtr_timestamp' ] )->from( 'glowthread_reaction' )
			->where( [ 'gtr_post' => $top->gtp_id ] )->caller( __METHOD__ )->fetchResultSet();
		$this->assertSame( 2, $likes->numRows() );
		foreach ( $likes as $like ) {
			$this->assertSame( 'like', (string)$like->gtr_type );
			$this->assertNull( $like->gtr_timestamp );
		}
		$reportRow = $this->getDb()->newSelectQueryBuilder()
			->select( '*' )->from( 'glowthread_report' )->where( [ 'gtrp_post' => $top->gtp_id ] )
			->caller( __METHOD__ )->fetchRow();
		$this->assertSame( 'legacy', (string)$reportRow->gtrp_reason );
		$this->assertNull( $reportRow->gtrp_created );
		$this->assertNull( $reportRow->gtrp_resolved_at );
		$this->assertSame( $this->actorOf( $this->alice ), (int)$reportRow->gtrp_actor );

		// One summary log entry, nothing in recent changes
		$this->assertSame( 1, (int)$this->getDb()->newSelectQueryBuilder()->select( 'COUNT(*)' )->from( 'logging' )
			->where( [ 'log_type' => ModerationLogger::TYPE, 'log_action' => 'import' ] )->caller( __METHOD__ )
			->fetchField() );
		$this->assertSame( 0, (int)$this->getDb()->newSelectQueryBuilder()->select( 'COUNT(*)' )
			->from( 'recentchanges' )->where( [ 'rc_log_type' => ModerationLogger::TYPE ] )->caller( __METHOD__ )
			->fetchField() );
	}

	public function testStatusesAndHiddenComments() {
		$a = $this->pageA;
		$deletedTop = $this->addPost( 0, $a, $this->alice, 'Deleted', null, LegacySource::STATUS_DELETED, 0, 1 );
		$underDeleted = $this->addPost( 1, $a, $this->bob, 'Under the deleted one', $deletedTop );
		$spam = $this->addPost( 2, $a, $this->bob, 'Spam', null, LegacySource::STATUS_SPAM );
		$top = $this->addPost( 3, $a, $this->alice, 'Top level' );
		$d1 = $this->addPost( 4, $a, $this->bob, 'Level one', $top );
		$d2Deleted = $this->addPost( 5, $a, $this->alice, 'Level two, deleted', $d1, LegacySource::STATUS_DELETED );
		$d3 = $this->addPost( 6, $a, $this->bob, 'Level three', $d2Deleted );
		$orphan = $this->addPost( 7, $a, $this->bob, 'Orphan', self::legacyId( 900 ) );
		$this->addAttitude( $deletedTop, LegacySource::ATTITUDE_REPORT, $this->bob->getId() );

		$this->import( FlowThreadImporter::MODE_APPLY );
		$rows = $this->importedRows();
		$status = static fn ( string $id ) => (string)$rows[bin2hex( $id )]->gtp_status;
		$this->assertSame( 'deleted', $status( $deletedTop ) );
		$this->assertSame( 'visible', $status( $underDeleted ), 'Hidden by its deleted parent in both systems' );
		$this->assertSame( 'held', $status( $spam ) );
		$this->assertSame( 'filter', (string)$rows[bin2hex( $spam )]->gtp_hold_reason );
		$this->assertSame( 'deleted', $status( $d3 ), 'FlowThread hid it, GlowThread would show it under d1' );
		$this->assertSame( 'deleted', $status( $orphan ) );
		$this->assertNull( $rows[bin2hex( $orphan )]->gtp_parent );
		$this->assertSame( 0, (int)$rows[bin2hex( $orphan )]->gtp_depth );

		// The report on the deleted comment is resolved as upheld by the importer
		$reportRow = $this->getDb()->newSelectQueryBuilder()->select( '*' )->from( 'glowthread_report' )
			->caller( __METHOD__ )->fetchRow();
		$this->assertSame( 'upheld', (string)$reportRow->gtrp_outcome );
		$this->assertSame( $this->actorOf( $this->sysop ), (int)$reportRow->gtrp_resolved_actor );
		$this->assertSame( self::NOW, wfTimestamp( TS_MW, $reportRow->gtrp_resolved_at ) );
		$this->assertSame( 0, (int)$rows[bin2hex( $deletedTop )]->gtp_open_report_count );
		$this->assertSame( 1, (int)$rows[bin2hex( $deletedTop )]->gtp_reply_count,
			'reply_count counts visible children, whatever the parent\'s own status' );
	}

	public function testOptionsChangeTheDecisions() {
		$a = $this->pageA;
		$deletedTop = $this->addPost( 0, $a, $this->alice, 'Deleted', null, LegacySource::STATUS_DELETED );
		$spam = $this->addPost( 1, $a, $this->bob, 'Spam', null, LegacySource::STATUS_SPAM );
		$top = $this->addPost( 2, $a, $this->alice, 'Top level' );
		$d1 = $this->addPost( 3, $a, $this->bob, 'Level one', $top );
		$d2Deleted = $this->addPost( 4, $a, $this->alice, 'Level two', $d1, LegacySource::STATUS_DELETED );
		$d3 = $this->addPost( 5, $a, $this->bob, 'Level three', $d2Deleted );
		$empty = $this->addPost( 6, $a, $this->bob, self::WRAP . '<p>' );
		$spoiler = $this->addPost( 7, $a, $this->bob, 'Spoiler: <span class="heimu">secret</span>' );
		$this->addAttitude( $deletedTop, LegacySource::ATTITUDE_REPORT, $this->bob->getId() );
		$this->addAttitude( $top, LegacySource::ATTITUDE_LIKE, $this->alice->getId() );
		$this->addControl( $a, LegacySource::CONTROL_OPTED_OUT );

		$this->import( FlowThreadImporter::MODE_APPLY, [
			'spam' => 'deleted', 'optedOut' => 'open', 'reportsOnDeleted' => 'open', 'emptyText' => 'delete',
			'hidden' => 'visible', 'spoilers' => 'hold', 'selfAttitudes' => 'drop',
		] );
		$rows = $this->importedRows();
		$status = static fn ( string $id ) => (string)$rows[bin2hex( $id )]->gtp_status;
		$this->assertSame( 'deleted', $status( $spam ) );
		$this->assertSame( 'visible', $status( $d3 ) );
		$this->assertSame( 'deleted', $status( $empty ) );
		$this->assertSame( 'held', $status( $spoiler ) );
		$this->assertSame( 1, (int)$rows[bin2hex( $deletedTop )]->gtp_open_report_count, 'Report left open' );
		$this->assertSame( 0, (int)$rows[bin2hex( $top )]->gtp_like_count, 'Self-like dropped' );
		$this->assertSame( 'open', $this->getServiceContainer()->get( 'GlowThread.PageStateStore' )
			->get( $this->pageA )['state'] );
	}

	public function testPageSettingsAndMissingUsers() {
		$this->addControl( $this->pageA, LegacySource::CONTROL_DISABLED );
		$this->addControl( $this->pageB, LegacySource::CONTROL_OPTED_OUT );
		$entry = new ManualLogEntry( LegacyLogType::TYPE, 'disable' );
		$entry->setPerformer( $this->sysop );
		$entry->setTarget( Title::newFromID( $this->pageA ) );
		$entry->setTimestamp( '20260507120000' );
		$entry->insert();
		$gone = $this->addPost( 0, $this->pageA, null, 'Old account', null, 0, 0, 0, 'Gone user' );
		$this->getDb()->newUpdateQueryBuilder()->update( LegacySource::POST_TABLE )
			->set( [ 'flowthread_userid' => 987654 ] )->where( [ 'flowthread_id' => $gone ] )
			->caller( __METHOD__ )->execute();
		$notIp = $this->addPost( 1, $this->pageA, null, 'Odd anonymous name', null, 0, 0, 0, 'not an ip' );

		$report = $this->import( FlowThreadImporter::MODE_APPLY );
		$store = $this->getServiceContainer()->get( 'GlowThread.PageStateStore' );
		$stateA = $store->get( $this->pageA );
		$this->assertSame( 'locked', $stateA['state'] );
		$this->assertSame( $this->actorOf( $this->sysop ), $stateA['actor'] );
		$this->assertSame( '20260507120000', $stateA['timestamp'] );
		$this->assertSame( 'locked', $store->get( $this->pageB )['state'] );
		$this->assertNull( $store->get( $this->pageB )['actor'] );

		$rows = $this->importedRows();
		$this->assertSame( 'imported>Gone user', $this->actorName( (int)$rows[bin2hex( $gone )]->gtp_actor ) );
		$this->assertSame( 'imported>not an ip', $this->actorName( (int)$rows[bin2hex( $notIp )]->gtp_actor ) );
		$this->assertSame( 1, $report->get( 'authors', 'users missing, external actor used' ) );
	}

	public function testRerunChangesNothingAndVerifyPasses() {
		$this->seedThread();
		$this->addControl( $this->pageB, LegacySource::CONTROL_DISABLED );
		$legacyBefore = $this->legacySnapshot();
		$this->import( FlowThreadImporter::MODE_APPLY );
		$after = $this->snapshot();

		$second = $this->import( FlowThreadImporter::MODE_APPLY );
		$this->assertSame( 0, $second->get( 'comments', 'inserted' ) );
		$this->assertSame( 6, $second->get( 'comments', 'already imported, unchanged' ) );
		$this->assertEquals( $after, $this->snapshot(), 'A second run changes nothing' );
		$this->assertSame( 1, (int)$this->getDb()->newSelectQueryBuilder()->select( 'COUNT(*)' )->from( 'logging' )
			->where( [ 'log_type' => ModerationLogger::TYPE, 'log_action' => 'import' ] )->caller( __METHOD__ )
			->fetchField(),
			'No log entry when nothing was added' );

		$verify = $this->import( FlowThreadImporter::MODE_VERIFY );
		$this->assertSame( [], $verify->getMismatches() );
		$this->assertSame(
			$verify->get( 'visibility', 'comments readers see in FlowThread' ),
			$verify->get( 'visibility', 'comments readers see in GlowThread (rows)' )
		);
		$this->assertSame( $legacyBefore, $this->legacySnapshot(), 'FlowThread tables untouched' );
	}

	public function testDryRunWritesNothing() {
		$this->seedThread();
		$actorsBefore = (int)$this->getDb()->newSelectQueryBuilder()->select( 'COUNT(*)' )->from( 'actor' )
			->caller( __METHOD__ )->fetchField();
		$before = $this->snapshot();
		$report = $this->import( FlowThreadImporter::MODE_DRY_RUN );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( $actorsBefore, (int)$this->getDb()->newSelectQueryBuilder()->select( 'COUNT(*)' )
			->from( 'actor' )->caller( __METHOD__ )->fetchField() );
		$this->assertSame( 6, $report->get( 'comments', 'would insert' ) );
		$this->assertSame( 1, $report->get( 'authors', 'would be IP actors created' ) );
		$this->assertSame( 2, $report->get( 'likes', 'likes to import' ) );
		$this->assertSame( 2, $report->get( 'likes', 'would insert' ) );
		$this->assertSame( [], $report->getMismatches() );
	}

	public function testVerifyFindsDifferencesAndUpdateRepairsThem() {
		$ids = $this->seedThread();
		$this->import( FlowThreadImporter::MODE_APPLY );
		$hex = bin2hex( $ids['top'] );
		$this->getDb()->newUpdateQueryBuilder()->update( 'glowthread_post' )
			->set( [ 'gtp_text' => 'changed', 'gtp_like_count' => 9 ] )->where( [ 'gtp_legacy_id' => $hex ] )
			->caller( __METHOD__ )->execute();
		$this->getDb()->newDeleteQueryBuilder()->deleteFrom( 'glowthread_reaction' )
			->where( [ 'gtr_actor' => $this->actorOf( $this->sysop ) ] )->caller( __METHOD__ )->execute();

		$verify = $this->import( FlowThreadImporter::MODE_VERIFY );
		$messages = implode( "\n", $verify->getMismatches() );
		$this->assertStringContainsString( "comment $hex differs: gtp_text", $messages );
		$this->assertStringContainsString( 'counters differ', $messages );
		// A like missing on a comment imported earlier may have been taken back: counted, not a mismatch
		$this->assertStringNotContainsString( 'like on', $messages );
		$this->assertSame( 1, $verify->get( 'likes', 'not added: comment imported earlier' ) );

		$plain = $this->import( FlowThreadImporter::MODE_APPLY );
		$this->assertSame( 1, $plain->get( 'comments', 'already imported, differs from FlowThread now' ) );
		$this->assertSame( 'changed', (string)$this->importedRows()[$hex]->gtp_text, 'Kept without --update' );
		$this->assertSame( 1, (int)$this->importedRows()[$hex]->gtp_like_count, 'Like not added, counter fixed' );

		$this->import( FlowThreadImporter::MODE_APPLY, [ 'update' => true ] );
		$this->assertSame( 'Top comment', (string)$this->importedRows()[$hex]->gtp_text );
		$this->assertSame( 1, (int)$this->importedRows()[$hex]->gtp_like_count, 'Not added with --update either' );
		$this->assertSame( [], $this->import( FlowThreadImporter::MODE_VERIFY )->getMismatches() );
	}

	public function testSinglePageAndTakenFloors() {
		$ids = $this->seedThread();
		// A GlowThread comment already holds floor 1 on page B
		$this->getDb()->newInsertQueryBuilder()->insertInto( 'glowthread_post' )->row( [
			'gtp_page' => $this->pageB, 'gtp_actor' => $this->actorOf( $this->alice ), 'gtp_depth' => 0,
			'gtp_seq' => 1, 'gtp_text' => 'New', 'gtp_created' => $this->getDb()->timestamp( self::NOW ),
			'gtp_status' => 'visible',
		] )->caller( __METHOD__ )->execute();

		$report = $this->import( FlowThreadImporter::MODE_APPLY, [ 'pageId' => $this->pageB ] );
		$rows = $this->importedRows();
		$this->assertCount( 1, $rows, 'Only page B' );
		$this->assertSame( 2, (int)$rows[bin2hex( $ids['b'] )]->gtp_seq );
		$this->assertSame( 1, $report->get( 'floors', 'floors out of creation order (planned floor taken)' ) );
		$pageStates = $this->getServiceContainer()->get( 'GlowThread.PageStateStore' );
		$this->assertSame( 2, $pageStates->get( $this->pageB )['seq'] );

		// The moved floor is final: later runs and verify accept it
		$this->assertSame( [], $this->import( FlowThreadImporter::MODE_VERIFY, [ 'pageId' => $this->pageB ] )
			->getMismatches() );
		$second = $this->import( FlowThreadImporter::MODE_APPLY, [ 'pageId' => $this->pageB, 'update' => true ] );
		$this->assertSame( 1, $second->get( 'comments', 'already imported, unchanged' ) );
	}

	public function testStopsBeforeWritingWhenIpActorsCannotBeCreated() {
		$this->seedThread();
		$this->setTemporaryAccounts( true );
		$before = $this->snapshot();
		$report = $this->import( FlowThreadImporter::MODE_APPLY );
		$this->assertCount( 1, $report->getMismatches() );
		$this->assertStringContainsString( 'Temporary accounts are enabled', $report->getMismatches()[0] );
		$this->assertSame( $before, $this->snapshot(), 'Nothing written' );
	}

	public function testUpdateAppliesNewChoicesAndRecordsThem() {
		$deleted = $this->addPost( 0, $this->pageA, $this->alice, 'Deleted', null, LegacySource::STATUS_DELETED );
		$this->addAttitude( $deleted, LegacySource::ATTITUDE_REPORT, $this->bob->getId() );
		$this->import( FlowThreadImporter::MODE_APPLY, [ 'reportsOnDeleted' => 'open' ] );
		$openReports = fn () => (int)$this->getDb()->newSelectQueryBuilder()->select( 'COUNT(*)' )
			->from( 'glowthread_report' )->where( [ 'gtrp_resolved_at' => null ] )->caller( __METHOD__ )->fetchField();
		$this->assertSame( 1, $openReports() );

		// Verify with other choices says so first
		$verify = $this->import( FlowThreadImporter::MODE_VERIFY );
		$this->assertStringContainsString( 'Choices differ from the last import: reportsOnDeleted',
			$verify->getMismatches()[0] );

		// Without --update nothing changes, with it the report is resolved and the new choices recorded
		$this->import( FlowThreadImporter::MODE_APPLY );
		$this->assertSame( 1, $openReports() );
		$this->import( FlowThreadImporter::MODE_APPLY, [ 'update' => true ] );
		$this->assertSame( 0, $openReports() );
		$this->assertSame( [], $this->import( FlowThreadImporter::MODE_VERIFY )->getMismatches() );
	}

	public function testPageReopenedInFlowThread() {
		$this->addPost( 0, $this->pageA, $this->alice, 'Comment' );
		$this->addControl( $this->pageA, LegacySource::CONTROL_DISABLED );
		// FlowThread logged who turned comments off: the imported state is attributed to them,
		// which must not make it look like a change made in GlowThread
		$entry = new ManualLogEntry( LegacyLogType::TYPE, 'disable' );
		$entry->setPerformer( $this->sysop );
		$entry->setTarget( Title::newFromID( $this->pageA ) );
		$entry->insert();
		$this->import( FlowThreadImporter::MODE_APPLY );
		$store = $this->getServiceContainer()->get( 'GlowThread.PageStateStore' );
		$this->assertSame( 'locked', $store->get( $this->pageA )['state'] );
		$this->assertSame( $this->actorOf( $this->sysop ), $store->get( $this->pageA )['actor'] );

		$this->getDb()->newDeleteQueryBuilder()->deleteFrom( LegacySource::CONTROL_TABLE )
			->where( [ 'flowthread_ctrl_pageid' => $this->pageA ] )->caller( __METHOD__ )->execute();
		$this->assertStringContainsString( 'state locked, FlowThread says open',
			implode( "\n", $this->import( FlowThreadImporter::MODE_VERIFY )->getMismatches() ) );
		$this->import( FlowThreadImporter::MODE_APPLY );
		$this->assertSame( 'open', $store->get( $this->pageA )['state'] );
		$this->assertSame( [], $this->import( FlowThreadImporter::MODE_VERIFY )->getMismatches() );
	}

	/**
	 * FlowThread kept showing the comments on pages it closed, so they are imported as locked:
	 * readers see the same comments. Imported as disabled on request, they are hidden, and the
	 * visibility counts say so.
	 */
	public function testClosedPagesStayReadable() {
		$this->addPost( 0, $this->pageA, $this->alice, 'On a page an admin closed' );
		$this->addPost( 1, $this->pageB, $this->alice, 'On a page that opted out' );
		$this->addControl( $this->pageA, LegacySource::CONTROL_DISABLED );
		$this->addControl( $this->pageB, LegacySource::CONTROL_OPTED_OUT );
		$store = $this->getServiceContainer()->get( 'GlowThread.PageStateStore' );

		$apply = $this->import( FlowThreadImporter::MODE_APPLY );
		$this->assertSame( PageState::LOCKED, $store->get( $this->pageA, true )['state'] );
		$this->assertSame( PageState::LOCKED, $store->get( $this->pageB, true )['state'] );
		$this->assertSame( 2, $apply->get( 'visibility', 'comments readers see in FlowThread' ) );
		$this->assertSame( 2, $apply->get( 'visibility', 'comments readers see in GlowThread (rows)' ) );

		$disabled = [ 'disabledPages' => 'disabled', 'optedOut' => 'open', 'update' => true ];
		$this->import( FlowThreadImporter::MODE_APPLY, $disabled );
		$this->assertSame( PageState::DISABLED, $store->get( $this->pageA, true )['state'] );
		$this->assertSame( PageState::OPEN, $store->get( $this->pageB, true )['state'] );
		$verify = $this->import( FlowThreadImporter::MODE_VERIFY, $disabled );
		$this->assertSame( [], $verify->getMismatches() );
		$this->assertSame( 2, $verify->get( 'visibility', 'comments readers see in FlowThread' ) );
		$this->assertSame( 1, $verify->get( 'visibility', 'comments readers see in GlowThread (planned)' ) );
		$this->assertSame( 1, $verify->get( 'visibility', 'comments readers see in GlowThread (rows)' ) );
	}

	/**
	 * A page state that a moderator set in GlowThread after the import is kept without --update,
	 * and the dry run, the run and verify all agree on that.
	 */
	public function testPageStateChangedInGlowThreadIsKeptInEveryMode() {
		$this->addPost( 0, $this->pageA, $this->alice, 'Comment' );
		$this->addPost( 1, $this->pageB, $this->alice, 'Comment' );
		// FlowThread has no setting for page A and turned comments off on page B
		$this->addControl( $this->pageB, LegacySource::CONTROL_DISABLED );
		$this->import( FlowThreadImporter::MODE_APPLY );
		$states = $this->getServiceContainer()->get( 'GlowThread.PageStateService' );
		foreach ( [ $this->pageA, $this->pageB ] as $page ) {
			$this->assertStatusGood( $states->setState( $this->sysop, $page, PageState::DISABLED, '' ) );
		}

		$dryRun = $this->import( FlowThreadImporter::MODE_DRY_RUN );
		$this->assertSame( 2, $dryRun->get( 'pages', 'state changed in GlowThread, kept' ) );
		$this->assertSame( 0, $dryRun->get( 'pages', 'would set open' ) );
		$this->assertSame( 0, $dryRun->get( 'pages', 'would set locked' ) );
		$apply = $this->import( FlowThreadImporter::MODE_APPLY );
		$this->assertSame( 2, $apply->get( 'pages', 'state changed in GlowThread, kept' ) );
		$store = $this->getServiceContainer()->get( 'GlowThread.PageStateStore' );
		$this->assertSame( PageState::DISABLED, $store->get( $this->pageA, true )['state'] );
		$this->assertSame( PageState::DISABLED, $store->get( $this->pageB, true )['state'] );
		$verify = $this->import( FlowThreadImporter::MODE_VERIFY );
		$this->assertSame( [], $verify->getMismatches() );
		$this->assertSame( 2, $verify->get( 'pages', 'state changed in GlowThread, kept' ) );
	}

	/**
	 * A comment erased in GlowThread after the switch stays erased, with its text gone, in every
	 * mode and with --update too (finding I1 of the 2026-10-10 review).
	 */
	public function testEraseInGlowThreadIsKeptByUpdate() {
		$this->setUpGlowThread();
		$id = $this->addPost( 0, $this->pageA, $this->alice, '<p>Private address: 1 Main Street</p>' );
		$this->import( FlowThreadImporter::MODE_APPLY );
		$post = $this->gtService( 'PostStore' )->getByLegacyId( bin2hex( $id ) );
		$this->gtModerate( 'delete', [ $post ] );
		$this->gtModerate( 'erase', [ $this->gtReload( $post ) ] );
		$before = $this->snapshot();

		foreach ( [ FlowThreadImporter::MODE_DRY_RUN, FlowThreadImporter::MODE_APPLY, FlowThreadImporter::MODE_VERIFY ]
			as $mode
		) {
			$report = $this->import( $mode, [ 'update' => true ] );
			$this->assertSame( 1, $report->get( 'comments', 'changed in GlowThread, kept' ), $mode );
			$this->assertSame( 0, $report->get( 'comments', 'already imported, differs from FlowThread now' ), $mode );
			$this->assertSame( [], $report->getMismatches(), $mode );
		}
		$this->assertEquals( $before, $this->snapshot(), '--update wrote nothing' );
		$this->assertSame( PostStatus::ERASED, $this->gtReload( $post )->getStatus() );
		$this->assertSame( '', $this->gtReload( $post )->getText() );

		// A report that shows up later is closed, whatever --reports-on-deleted says
		$this->addAttitude( $id, LegacySource::ATTITUDE_REPORT, $this->bob->getId() );
		$this->import( FlowThreadImporter::MODE_APPLY, [ 'update' => true, 'reportsOnDeleted' => 'open' ] );
		$this->assertSame( 0, $this->gtReload( $post )->getOpenReportCount() );
	}

	/**
	 * Statuses GlowThread set after the switch (a moderator's deletion and restore, the author's
	 * own deletion, an automatic hold), report resolutions made there and a page a moderator
	 * closed are kept with --update, and the dry run, the run and verify agree (finding I1).
	 */
	public function testModerationInGlowThreadIsKeptByUpdate() {
		$this->setUpGlowThread();
		$ids = [
			'deleted' => $this->addPost( 0, $this->pageA, $this->alice, 'Abusive remark' ),
			'self' => $this->addPost( 1, $this->pageA, $this->bob, 'Something Bob regrets' ),
			'restored' => $this->addPost( 2, $this->pageA, $this->alice, 'Deleted in FlowThread', null,
				LegacySource::STATUS_DELETED ),
			'held' => $this->addPost( 3, $this->pageA, $this->alice, 'Held by reports' ),
			'checked' => $this->addPost( 4, $this->pageA, $this->alice, 'Reported, checked' ),
			'gone' => $this->addPost( 5, $this->pageA, $this->alice, 'Reported, checked, report gone' ),
		];
		foreach ( [ 'restored', 'checked', 'gone' ] as $name ) {
			$this->addAttitude( $ids[$name], LegacySource::ATTITUDE_REPORT, $this->bob->getId() );
		}
		$this->import( FlowThreadImporter::MODE_APPLY );
		$store = $this->gtService( 'PostStore' );
		$posts = array_map( static fn ( string $id ) => $store->getByLegacyId( bin2hex( $id ) ), $ids );

		$this->gtModerate( 'delete', [ $posts['deleted'] ] );
		$this->assertStatusGood(
			$this->gtService( 'ModerationService' )->selfDelete( $this->bob, $posts['self']->getId(), '' )
		);
		$this->gtModerate( 'restore', [ $posts['restored'] ] );
		// As ReportService holds a comment: no actor, only a time
		$this->assertTrue( $store->changeStatus(
			$store->getPrimary(), $posts['held']->getId(), [ PostStatus::VISIBLE ], PostStatus::HELD, null, self::NOW,
			[ 'gtp_hold_reason' => HoldReason::REPORTS ]
		) );
		$this->gtModerate( 'dismiss', [ $posts['checked'], $posts['gone'] ] );
		$this->assertStatusGood( $this->getServiceContainer()->get( 'GlowThread.PageStateService' )
			->setState( $this->sysop, $this->pageA, PageState::DISABLED, '' ) );
		// A dismissed report that FlowThread no longer has is kept too: the log entry names it
		$this->getDb()->newDeleteQueryBuilder()->deleteFrom( LegacySource::ATTITUDE_TABLE )
			->where( [ 'flowthread_att_id' => $ids['gone'] ] )->caller( __METHOD__ )->execute();
		$before = $this->snapshot();

		foreach ( [ FlowThreadImporter::MODE_DRY_RUN, FlowThreadImporter::MODE_APPLY, FlowThreadImporter::MODE_VERIFY ]
			as $mode
		) {
			$report = $this->import( $mode, [ 'update' => true ] );
			$this->assertSame( 4, $report->get( 'comments', 'changed in GlowThread, kept' ), $mode );
			$this->assertSame( 3, $report->get( 'reports', 'changed in GlowThread, kept' ), $mode );
			$this->assertSame( 1, $report->get( 'pages', 'state changed in GlowThread, kept' ), $mode );
			$this->assertSame( [], $report->getMismatches(), $mode );
		}
		$this->assertEquals( $before, $this->snapshot(), '--update wrote nothing' );
		$this->assertSame( PostStatus::DELETED, $this->gtReload( $posts['deleted'] )->getStatus() );
		$this->assertSame( PostStatus::DELETED, $this->gtReload( $posts['self'] )->getStatus() );
		$this->assertSame( PostStatus::VISIBLE, $this->gtReload( $posts['restored'] )->getStatus() );
		$this->assertSame( PostStatus::HELD, $this->gtReload( $posts['held'] )->getStatus() );
	}

	/**
	 * A comment GlowThread moved to the page's new ID after an undelete stays there, because
	 * FlowThread never gives a comment another page ID. Every mode keeps it, with --update too,
	 * and the floor and visibility checks follow the page it is on.
	 */
	public function testPageMovedByGlowThreadIsKeptByUpdate() {
		$this->setUpGlowThread();
		$hex = bin2hex( $this->addPost( 0, $this->pageA, $this->alice, 'On a page deleted and restored' ) );
		$this->import( FlowThreadImporter::MODE_APPLY );
		// As PageLifecycleService moves comments when an undelete gives page A the ID of page B,
		// whose floors end at 4
		$dbw = $this->getDb();
		$this->gtService( 'PostStore' )->movePage( $dbw, $this->pageA, $this->pageB, 4 );
		$this->gtService( 'PageStateStore' )->raiseSeqFloor( $dbw, $this->pageB, 5 );
		$before = $this->snapshot();

		foreach ( [ FlowThreadImporter::MODE_DRY_RUN, FlowThreadImporter::MODE_APPLY, FlowThreadImporter::MODE_VERIFY ]
			as $mode
		) {
			$report = $this->import( $mode, [ 'update' => true ] );
			$this->assertSame( [ "$hex: gtp_page" ], $report->getIds( 'changed in GlowThread, kept' ), $mode );
			$this->assertSame( 0, $report->get( 'comments', 'already imported, differs from FlowThread now' ), $mode );
			$this->assertSame( [], $report->getMismatches(), $mode );
		}
		$this->assertEquals( $before, $this->snapshot(), '--update wrote nothing' );
		$this->assertSame( $this->pageB, (int)$this->importedRows()[$hex]->gtp_page );
	}

	/**
	 * A link to a user page becomes that user's mention token, looked up by the exact name. A
	 * hidden user leaves no name, and a name that is no user stays text. A token typed as text in
	 * FlowThread stays as typed and is a mention now. The dry run, the run and verify agree.
	 */
	public function testUserLinksBecomeMentionTokens() {
		$hidden = $this->getMutableTestUser()->getUser();
		$this->assertStatusGood( $this->getServiceContainer()->getBlockUserFactory()->newBlockUser(
			$hidden, $this->getTestUser( [ 'sysop', 'suppress' ] )->getAuthority(), 'infinity', '',
			[ 'isHideUser' => true, 'isAutoblocking' => false ]
		)->placeBlock() );
		$link = static fn ( string $name ): string =>
			'<a href="/wiki/User:' . rawurlencode( str_replace( ' ', '_', $name ) ) . '">' . $name . '</a>';
		$hex = bin2hex( $this->addPost( 0, $this->pageA, $this->alice,
			'@' . $link( $this->bob->getName() ) . ' and ' . $link( $hidden->getName() ) . ' and '
				. $link( 'Nobody here' ) . ' and &lt;@' . $this->alice->getId() . '&gt;' ) );
		foreach ( [ FlowThreadImporter::MODE_DRY_RUN, FlowThreadImporter::MODE_APPLY, FlowThreadImporter::MODE_VERIFY ]
			as $mode
		) {
			$report = $this->import( $mode );
			$this->assertSame( 1, $report->get( 'mentions', 'names of users, now mentions' ), $mode );
			$this->assertSame( 1, $report->get( 'mentions', 'names of hidden users, now @…' ), $mode );
			$this->assertSame( 1, $report->get( 'mentions', 'names that are no user, kept as text' ), $mode );
			$this->assertSame( 2, $report->get( 'mentions', 'distinct users mentioned' ), $mode );
			$this->assertSame( [], $report->getMismatches(), $mode );
		}
		$this->assertSame(
			TextProcessor::mentionToken( $this->bob->getId() ) . ' and @… and @Nobody here and '
				. TextProcessor::mentionToken( $this->alice->getId() ),
			(string)$this->importedRows()[$hex]->gtp_text
		);
	}

	/**
	 * Comments imported before mentions were tokens hold "@Name". --update rewrites them to tokens,
	 * except where GlowThread changed the comment.
	 */
	public function testUpdateTurnsOldMentionsIntoTokens() {
		$this->setUpGlowThread();
		$html = '@<a href="/wiki/User:' . rawurlencode( str_replace( ' ', '_', $this->bob->getName() ) ) . '">'
			. $this->bob->getName() . '</a> thanks';
		$ids = [
			'plain' => bin2hex( $this->addPost( 0, $this->pageA, $this->alice, $html ) ),
			'moderated' => bin2hex( $this->addPost( 1, $this->pageA, $this->alice, $html ) ),
		];
		$this->import( FlowThreadImporter::MODE_APPLY );
		$old = '@' . str_replace( ' ', '_', $this->bob->getName() ) . ' thanks';
		$this->getDb()->newUpdateQueryBuilder()->update( 'glowthread_post' )->set( [ 'gtp_text' => $old ] )
			->where( [ 'gtp_legacy_id' => array_values( $ids ) ] )->caller( __METHOD__ )->execute();
		$this->gtModerate( 'delete', [ $this->gtService( 'PostStore' )->getByLegacyId( $ids['moderated'] ) ] );

		$this->assertStringContainsString( "comment {$ids['plain']} differs: gtp_text",
			implode( "\n", $this->import( FlowThreadImporter::MODE_VERIFY )->getMismatches() ) );
		$this->import( FlowThreadImporter::MODE_APPLY, [ 'update' => true ] );
		$rows = $this->importedRows();
		$this->assertSame( TextProcessor::mentionToken( $this->bob->getId() ) . ' thanks',
			(string)$rows[$ids['plain']]->gtp_text );
		$this->assertSame( $old, (string)$rows[$ids['moderated']]->gtp_text, 'Kept: GlowThread changed it' );
		$this->assertSame( [], $this->import( FlowThreadImporter::MODE_VERIFY )->getMismatches() );
	}

	/**
	 * Text cut to fit the column never ends in part of a mention token.
	 */
	public function testShortenedTextDoesNotSplitAMentionToken() {
		$hex = bin2hex( $this->addPost( 0, $this->pageA, $this->alice,
			str_repeat( 'a', 65524 ) . '&lt;@5&gt;' . str_repeat( 'b', 100 ) ) );
		$this->import( FlowThreadImporter::MODE_APPLY );
		$this->assertSame( str_repeat( 'a', 65524 ) . '…', (string)$this->importedRows()[$hex]->gtp_text );
	}

	/**
	 * A like its user removed in GlowThread, and an imported report that a user merge dropped, are
	 * never added again: likes and reports come only with their comment. Every mode agrees, with
	 * --update too, and verify counts them without failing.
	 */
	public function testLikesAndReportsTakenBackStayGone() {
		$this->setUpGlowThread();
		$ids = $this->seedThread();
		$this->import( FlowThreadImporter::MODE_APPLY );
		$top = $this->gtService( 'PostStore' )->getByLegacyId( bin2hex( $ids['top'] ) );
		$this->assertStatusGood( $this->gtService( 'ReactionService' )->setLike( $this->bob, $top->getId(), false ) );
		// What UserMerge does with the report of the account that goes when both accounts reported
		$this->getDb()->newDeleteQueryBuilder()->deleteFrom( 'glowthread_report' )
			->where( [ 'gtrp_post' => $top->getId() ] )->caller( __METHOD__ )->execute();
		$this->getDb()->newUpdateQueryBuilder()->update( 'glowthread_post' )->set( [ 'gtp_open_report_count' => 0 ] )
			->where( [ 'gtp_id' => $top->getId() ] )->caller( __METHOD__ )->execute();
		$before = $this->snapshot();

		foreach ( [ FlowThreadImporter::MODE_DRY_RUN, FlowThreadImporter::MODE_APPLY, FlowThreadImporter::MODE_VERIFY ]
			as $mode
		) {
			$report = $this->import( $mode, [ 'update' => true ] );
			$this->assertSame( 1, $report->get( 'likes', 'not added: comment imported earlier' ), $mode );
			$this->assertSame( 1, $report->get( 'reports', 'not added: comment imported earlier' ), $mode );
			$this->assertSame( [], $report->getMismatches(), $mode );
		}
		$this->assertEquals( $before, $this->snapshot(), 'Nothing added back' );
	}

	/**
	 * A run that stopped after a batch continues with the next run, and the comments of the
	 * finished batch keep their likes and reports: they were written with them.
	 */
	public function testStoppedRunContinuesWithLikesAndReports() {
		$this->seedThread();
		// Stop at the first wait for replication after a comment batch
		$stopped = false;
		try {
			$this->import( FlowThreadImporter::MODE_APPLY, [ 'batchSize' => 1 ], $importer,
				function () use ( &$stopped ): void {
					if ( !$stopped && $this->importedRows() ) {
						$stopped = true;
						throw new \RuntimeException( 'Stopped' );
					}
				}
			);
			$this->fail( 'The first run should have stopped' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'Stopped', $e->getMessage() );
		}
		$this->assertCount( 1, $this->importedRows(), 'One batch of one comment' );
		$this->import( FlowThreadImporter::MODE_APPLY );
		$this->assertSame( 2, (int)$this->getDb()->newSelectQueryBuilder()->select( 'COUNT(*)' )
			->from( 'glowthread_reaction' )->caller( __METHOD__ )->fetchField() );
		$this->assertSame( 1, (int)$this->getDb()->newSelectQueryBuilder()->select( 'COUNT(*)' )
			->from( 'glowthread_report' )->caller( __METHOD__ )->fetchField() );
		$this->assertSame( [], $this->import( FlowThreadImporter::MODE_VERIFY )->getMismatches() );
	}

	/**
	 * Converted text that outgrows the text column (non-ASCII link targets are percent-encoded)
	 * is cut at a character boundary and listed, the same way in every mode (finding I12).
	 */
	public function testTextLongerThanTheColumnIsShortened() {
		$html = '<p><a rel="nofollow" class="external text" href="https://example.org/' . str_repeat( '中', 6000 )
			. '">x</a>' . "\n</p><p>" . str_repeat( '文', 14000 ) . "\n</p>";
		$hex = bin2hex( $this->addPost( 0, $this->pageA, $this->alice, $html ) );
		foreach ( [ FlowThreadImporter::MODE_DRY_RUN, FlowThreadImporter::MODE_APPLY, FlowThreadImporter::MODE_VERIFY ]
			as $mode
		) {
			$report = $this->import( $mode );
			$this->assertSame( 1, $report->get( 'text', 'shortened to fit the text column' ), $mode );
			$this->assertSame( [], $report->getMismatches(), $mode );
		}
		$text = (string)$this->importedRows()[$hex]->gtp_text;
		$this->assertLessThanOrEqual( 65530, strlen( $text ) );
		$this->assertTrue( mb_check_encoding( $text, 'UTF-8' ), 'Cut at a character boundary' );
		$this->assertStringEndsWith( '…', $text );
	}

	/**
	 * Planning keeps a fixed amount per comment, so a deep reply chain (only crafted data has one,
	 * FlowThread capped the depth) needs memory in proportion to its length (finding I13).
	 */
	public function testDeepReplyChainNeedsLinearMemory() {
		$bytes = [];
		$offset = 0;
		foreach ( [ 1000, 3000 ] as $depth ) {
			$this->createLegacyTables();
			$parent = null;
			for ( $i = 0; $i < $depth; $i++ ) {
				$parent = $this->addPost( $offset + $i, $this->pageA, null, 'r', $parent );
			}
			$offset += $depth;
			gc_collect_cycles();
			$before = memory_get_usage();
			$this->import( FlowThreadImporter::MODE_DRY_RUN, [], $importer );
			$bytes[$depth] = memory_get_usage() - $before;
			unset( $importer );
		}
		// Full ancestor lists took 7.5 times as much (16.6 and 125.7 MB), linear growth is 3 times
		$this->assertLessThan( 5, $bytes[3000] / max( 1, $bytes[1000] ) );
	}

	/**
	 * A comment in which nothing would show (a joiner and tag characters) converts to empty text,
	 * follows --empty, and the dry run, the run and verify agree on it.
	 */
	public function testInvisibleTextBecomesEmptyInEveryMode() {
		$hex = bin2hex( $this->addPost( 0, $this->pageA, $this->alice, "\u{200D}\u{E0068}\u{E0069}" ) );
		foreach ( [ FlowThreadImporter::MODE_DRY_RUN, FlowThreadImporter::MODE_APPLY, FlowThreadImporter::MODE_VERIFY ]
			as $mode
		) {
			$report = $this->import( $mode );
			$this->assertSame( 1, $report->get( 'text', 'warning empty: comments' ), $mode );
			$this->assertSame( [], $report->getMismatches(), $mode );
		}
		$row = $this->importedRows()[$hex];
		$this->assertSame( '', (string)$row->gtp_text );
		$this->assertSame( PostStatus::VISIBLE, (string)$row->gtp_status, 'Kept, the default of --empty' );
	}

	/**
	 * A comment on a page that no longer exists is imported as FlowThread kept it, and listed.
	 */
	public function testCommentsOnMissingPagesAreListed() {
		$hex = bin2hex( $this->addPost( 0, 987654321, $this->alice, 'On a deleted page' ) );
		$report = $this->import( FlowThreadImporter::MODE_DRY_RUN );
		$this->assertSame( 1, $report->get( 'pages', 'comments on pages that do not exist' ) );
		$this->assertSame( [ $hex ], $report->getIds( 'on a page that does not exist' ) );
	}

	public function testResumedRunWritesTheLogEntry() {
		$this->seedThread();
		// FlowThread's own import entries (type comments) do not count as GlowThread's
		$flowThreadImport = new ManualLogEntry( LegacyLogType::TYPE, 'import' );
		$flowThreadImport->setPerformer( $this->sysop );
		$flowThreadImport->setTarget( Title::newFromID( $this->pageA ) );
		$flowThreadImport->setParameters( [ '4::count' => 3 ] );
		$flowThreadImport->insert();
		// A first run that stopped before its log entry
		$this->import( FlowThreadImporter::MODE_APPLY, [ 'log' => false ] );
		$this->import( FlowThreadImporter::MODE_APPLY );
		$row = $this->getDb()->newSelectQueryBuilder()->select( 'log_params' )->from( 'logging' )
			->where( [ 'log_type' => ModerationLogger::TYPE, 'log_action' => 'import' ] )->caller( __METHOD__ )
			->fetchRow();
		$params = \LogEntryBase::extractParams( $row->log_params );
		$this->assertSame( 6, $params['4::count'], 'All imported comments' );
		$this->assertSame( 'deleted', json_decode( $params['5::options'], true )['hidden'] );
	}

	public function testLikesOfMissingUsersAreSkipped() {
		$post = $this->addPost( 0, $this->pageA, null, 'Old account', null, 0, 1, 0, 'Gone user' );
		$this->getDb()->newUpdateQueryBuilder()->update( LegacySource::POST_TABLE )
			->set( [ 'flowthread_userid' => 987654 ] )->where( [ 'flowthread_id' => $post ] )
			->caller( __METHOD__ )->execute();
		$this->addAttitude( $post, LegacySource::ATTITUDE_LIKE, 987654 );
		$this->addAttitude( $post, LegacySource::ATTITUDE_LIKE, 987655 );
		$report = $this->import( FlowThreadImporter::MODE_APPLY );
		$this->assertSame( 2, $report->get( 'likes', 'skipped: user missing' ) );
		$this->assertSame( 0, (int)$this->importedRows()[bin2hex( $post )]->gtp_like_count );
	}

	public function testMissingTablesAreReported() {
		$this->getDb()->query( 'DROP TABLE ' . $this->getDb()->tableName( LegacySource::CONTROL_TABLE ), __METHOD__ );
		$report = $this->import( FlowThreadImporter::MODE_DRY_RUN );
		$this->assertSame( [ 'FlowThread tables missing: ' . LegacySource::CONTROL_TABLE ], $report->getMismatches() );
	}

	/**
	 * An applied run writes straight to the tables, so it purges the wiki's cache of the lists
	 * (ListCache) of every page after each write batch and at its end. A dry run leaves it.
	 */
	public function testApplyPurgesTheCachedLists() {
		// An hour back: the cache refuses values read (by the real clock) after its own time
		$now = microtime( true ) - 3600;
		$wanCache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$wanCache->setMockTime( $now );
		$this->setService( 'MainWANObjectCache', $wanCache );
		// The check key of all pages exists on a running wiki: one made later counts as purged then
		$this->getServiceContainer()->get( 'GlowThread.ListCache' )->touchAll();
		$computed = 0;
		$get = function () use ( &$computed ) {
			$this->getServiceContainer()->get( 'GlowThread.ListCache' )->getAnonResponse(
				$this->pageA,
				'hot',
				[],
				static function () use ( &$computed ) {
					$computed++;
					$facts = [ 'hidden' => [], 'missing' => [], 'from' => 0, 'until' => PHP_INT_MAX ];
					return [ [ 'next' => null ], $facts ];
				},
				'json_encode',
				false
			);
			// The request ends: its updates after the response store the list
			DeferredUpdates::doUpdates();
		};
		$this->seedThread();
		$now += 60;
		$get();
		$now += 60;
		$this->import( FlowThreadImporter::MODE_DRY_RUN );
		$now += 60;
		$get();
		$this->assertSame( 1, $computed, 'a dry run writes nothing' );

		// Each wait for replication (after a write batch) reads the list as a reader would
		$recomputed = [];
		$importer = null;
		$this->import( FlowThreadImporter::MODE_APPLY, [], $importer, static function () use (
			&$now, &$computed, &$recomputed, $get
		) {
			$now += 60;
			$before = $computed;
			$get();
			$recomputed[] = $computed - $before;
		} );
		$this->assertGreaterThan( 1, count( $recomputed ) );
		$this->assertContains( 1, $recomputed, 'a write batch purges the lists' );
		$computedDuringRun = $computed;
		$now += 60;
		$get();
		$this->assertSame( $computedDuringRun + 1, $computed, 'the end of the run purges the lists' );
	}
}
