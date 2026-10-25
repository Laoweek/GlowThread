<?php

namespace MediaWiki\Extension\GlowThread\Tests\Unit\Query;

use MediaWiki\Extension\GlowThread\Query\CommentSerializer;
use MediaWikiUnitTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * CommentSerializer::iso() formats TS_MW times itself: exactly as ConvertibleTimestamp does.
 *
 * @covers \MediaWiki\Extension\GlowThread\Query\CommentSerializer
 */
class CommentSerializerIsoTest extends MediaWikiUnitTestCase {

	public static function provideTimestamps(): array {
		return [
			'TS_MW' => [ '20261008120000' ],
			'end of a leap day' => [ '20240229235959' ],
			'year 1000' => [ '10000101000000' ],
			'year 9999' => [ '99991231235959' ],
			'no leap day' => [ '20230229120000' ],
			'month 13' => [ '20261308120000' ],
			'hour 24' => [ '20261008240000' ],
			'second 60' => [ '20261008120060' ],
			'year before 1000' => [ '09991231235959' ],
			'TS_POSTGRES' => [ '2026-10-08 12:00:00+00' ],
		];
	}

	/**
	 * @dataProvider provideTimestamps
	 */
	public function testIsoAsConvertibleTimestamp( string $timestamp ) {
		$this->assertSame(
			ConvertibleTimestamp::convert( TS_ISO_8601, $timestamp ),
			CommentSerializer::iso( $timestamp )
		);
	}

	public function testIsoOfManyTimes() {
		mt_srand( 42 );
		for ( $i = 0; $i < 2000; $i++ ) {
			$timestamp = gmdate( 'YmdHis', mt_rand( -30610224000, 253402300799 ) );
			$this->assertSame(
				ConvertibleTimestamp::convert( TS_ISO_8601, $timestamp ),
				CommentSerializer::iso( $timestamp ),
				$timestamp
			);
		}
		$this->assertNull( CommentSerializer::iso( null ) );
	}
}
