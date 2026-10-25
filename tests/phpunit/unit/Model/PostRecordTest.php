<?php

namespace MediaWiki\Extension\GlowThread\Tests\Unit\Model;

use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWikiUnitTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\GlowThread\Model\PostRecord
 */
class PostRecordTest extends MediaWikiUnitTestCase {

	public function testNewFromRow() {
		$post = PostRecord::newFromRow( (object)[
			'gtp_id' => '12', 'gtp_page' => '3', 'gtp_actor' => '4', 'gtp_parent' => '10',
			'gtp_reply_to' => '11', 'gtp_thread' => '9', 'gtp_depth' => '2', 'gtp_seq' => '7',
			'gtp_text' => "hi 😀", 'gtp_created' => '20261008120000', 'gtp_edited' => null,
			'gtp_status' => 'held', 'gtp_hold_reason' => 'filter,newaccount', 'gtp_status_actor' => null,
			'gtp_status_timestamp' => null, 'gtp_like_count' => '5', 'gtp_reply_count' => '1',
			'gtp_open_report_count' => '2', 'gtp_pinned' => null, 'gtp_legacy_id' => 'abc',
			'gtp_hold_detail' => null,
		] );
		$this->assertSame( 12, $post->getId() );
		$this->assertSame( 10, $post->getParentId() );
		$this->assertSame( 11, $post->getReplyToId() );
		$this->assertSame( 9, $post->getRootId() );
		$this->assertFalse( $post->isTopLevel() );
		$this->assertTrue( $post->isHeld() );
		$this->assertSame( [ 'filter', 'newaccount' ], $post->getHoldReasons() );
		$this->assertSame( "hi 😀", $post->getText() );
		$this->assertSame( '20261008120000', $post->getCreated() );
		$this->assertFalse( $post->isPinned() );
	}

	public function testTopLevelIsOwnRoot() {
		$post = PostRecord::newFromRow( (object)[
			'gtp_id' => 5, 'gtp_page' => 3, 'gtp_actor' => 4, 'gtp_parent' => null,
			'gtp_reply_to' => null, 'gtp_thread' => null, 'gtp_depth' => 0, 'gtp_seq' => 1,
			'gtp_text' => 'x', 'gtp_created' => '20261008120000', 'gtp_edited' => null,
			'gtp_status' => 'visible', 'gtp_hold_reason' => null, 'gtp_status_actor' => null,
			'gtp_status_timestamp' => null, 'gtp_like_count' => 0, 'gtp_reply_count' => 0,
			'gtp_open_report_count' => 0, 'gtp_pinned' => '20261008130000', 'gtp_legacy_id' => null,
			'gtp_hold_detail' => null,
		] );
		$this->assertSame( 5, $post->getRootId() );
		$this->assertTrue( $post->isTopLevel() );
		$this->assertSame( [], $post->getHoldReasons() );
		$this->assertTrue( $post->isPinned() );
		$this->assertSame( [], $post->getHoldMatches() );
	}

	public function testHoldMatchesAreReadFromTheDetail() {
		$row = [
			'gtp_id' => 5, 'gtp_page' => 3, 'gtp_actor' => 4, 'gtp_parent' => null,
			'gtp_reply_to' => null, 'gtp_thread' => null, 'gtp_depth' => 0, 'gtp_seq' => 1,
			'gtp_text' => 'x', 'gtp_created' => '20261008120000', 'gtp_edited' => null,
			'gtp_status' => 'held', 'gtp_hold_reason' => 'filter', 'gtp_status_actor' => null,
			'gtp_status_timestamp' => null, 'gtp_like_count' => 0, 'gtp_reply_count' => 0,
			'gtp_open_report_count' => 0, 'gtp_pinned' => null, 'gtp_legacy_id' => null,
			'gtp_hold_detail' => '{"filter":[{"line":"2","rule":"cheap pills","type":"literal"},{"bad":1}]}',
		];
		$this->assertSame(
			[ [ 'line' => 2, 'rule' => 'cheap pills', 'type' => 'literal' ] ],
			PostRecord::newFromRow( (object)$row )->getHoldMatches()
		);
		foreach ( [ null, '', 'not json', '{"filter":"x"}' ] as $detail ) {
			$row['gtp_hold_detail'] = $detail;
			$this->assertSame( [], PostRecord::newFromRow( (object)$row )->getHoldMatches() );
		}
	}

	public static function provideTimestamps(): array {
		return [
			'TS_MW' => [ '20261008120000' ],
			'first second of a year' => [ '20270101000000' ],
			'leap day' => [ '20240229235959' ],
			'year 1000' => [ '10000101000000' ],
			'no leap day' => [ '20230229120000' ],
			'month 13' => [ '20261308120000' ],
			'day 0' => [ '20261000120000' ],
			'hour 24' => [ '20261008240000' ],
			'minute 60' => [ '20261008126000' ],
			'second 60' => [ '20261008120060' ],
			'year before 1000' => [ '09991231235959' ],
			'TS_POSTGRES' => [ '2026-10-08 12:00:00+00' ],
			'TS_ISO_8601' => [ '2026-10-08T12:00:00Z' ],
		];
	}

	/**
	 * Timestamps that need no conversion come out as ConvertibleTimestamp would make them
	 *
	 * @dataProvider provideTimestamps
	 */
	public function testTimestampsAsConvertibleTimestampGivesThem( string $timestamp ) {
		$converted = ConvertibleTimestamp::convert( TS_MW, $timestamp );
		$post = PostRecord::newFromRow( (object)[
			'gtp_id' => 5, 'gtp_page' => 3, 'gtp_actor' => 4, 'gtp_parent' => null,
			'gtp_reply_to' => null, 'gtp_thread' => null, 'gtp_depth' => 0, 'gtp_seq' => 1,
			'gtp_text' => 'x', 'gtp_created' => $timestamp, 'gtp_edited' => $timestamp,
			'gtp_status' => 'visible', 'gtp_hold_reason' => null, 'gtp_status_actor' => null,
			'gtp_status_timestamp' => $timestamp, 'gtp_like_count' => 0, 'gtp_reply_count' => 0,
			'gtp_open_report_count' => 0, 'gtp_pinned' => $timestamp, 'gtp_legacy_id' => null,
			'gtp_hold_detail' => null,
		] );
		$this->assertSame( $converted ?: '19700101000000', $post->getCreated() );
		$this->assertSame( $converted ?: null, $post->getEdited() );
		$this->assertSame( $converted ?: null, $post->getStatusTimestamp() );
		$this->assertSame( $converted ?: null, $post->getPinned() );
		if ( PostRecord::isPlainTimestamp( $timestamp ) ) {
			$this->assertSame( $converted, $timestamp, 'plain timestamps convert to themselves' );
		}
	}
}
