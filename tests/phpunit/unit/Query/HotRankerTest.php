<?php

namespace MediaWiki\Extension\GlowThread\Tests\Unit\Query;

use MediaWiki\Extension\GlowThread\Query\HotRanker;
use MediaWikiUnitTestCase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\GlowThread\Query\HotRanker
 */
class HotRankerTest extends MediaWikiUnitTestCase {

	private const NOW = 1791000000;

	private static function daysAgo( float $days ): string {
		return ConvertibleTimestamp::convert( TS_MW, (int)( self::NOW - $days * 86400 ) );
	}

	public function testDecayHalvesEachHalfLife() {
		$ranker = new HotRanker( 30 );
		$this->assertEqualsWithDelta( 1.0, $ranker->decay( self::daysAgo( 0 ), self::NOW ), 1e-9 );
		$this->assertEqualsWithDelta( 0.5, $ranker->decay( self::daysAgo( 30 ), self::NOW ), 1e-9 );
		$this->assertEqualsWithDelta( 0.25, $ranker->decay( self::daysAgo( 60 ), self::NOW ), 1e-9 );
	}

	public function testFutureTimestampsDoNotGrow() {
		$ranker = new HotRanker( 30 );
		$this->assertEqualsWithDelta( 1.0, $ranker->decay( self::daysAgo( -5 ), self::NOW ), 1e-9 );
	}

	public function testScores() {
		$ranker = new HotRanker( 90 );
		$this->assertEqualsWithDelta( 1.0, $ranker->hotScore( 0, self::daysAgo( 0 ), self::NOW ), 1e-9 );
		$this->assertEqualsWithDelta( 5.5, $ranker->hotScore( 10, self::daysAgo( 90 ), self::NOW ), 1e-9 );
		$this->assertSame( 0.0, $ranker->popularScore( 0, self::daysAgo( 1 ), self::NOW ) );
		$this->assertEqualsWithDelta( 2.0, $ranker->popularScore( 4, self::daysAgo( 90 ), self::NOW ), 1e-9 );
	}

	public function testRank() {
		$ranker = new HotRanker( 90 );
		$ranked = $ranker->rank( [
			[ 'id' => 1, 'likes' => 0, 'created' => self::daysAgo( 2 ) ],
			[ 'id' => 2, 'likes' => 0, 'created' => self::daysAgo( 1 ) ],
			// 21 likes a year ago: 22 * 2^-4.06 ~ 1.3, beats a fresh comment without likes
			[ 'id' => 3, 'likes' => 21, 'created' => self::daysAgo( 365 ) ],
			// 3 likes a week ago: 4 * 0.95 = 3.8
			[ 'id' => 4, 'likes' => 3, 'created' => self::daysAgo( 7 ) ],
			// 30 likes three years ago: decayed to almost nothing
			[ 'id' => 5, 'likes' => 30, 'created' => self::daysAgo( 1095 ) ],
		], self::NOW );
		$this->assertSame( [ 4, 3, 2, 1, 5 ], $ranked );
	}

	public function testDecayReadsOtherTimestampFormats() {
		$ranker = new HotRanker( 30 );
		$created = self::daysAgo( 30 );
		$expected = $ranker->decay( $created, self::NOW );
		foreach ( [ TS_POSTGRES, TS_ISO_8601, TS_DB ] as $format ) {
			$this->assertSame(
				$expected,
				$ranker->decay( ConvertibleTimestamp::convert( $format, $created ), self::NOW ),
				"format $format"
			);
		}
	}

	public function testDecayReadsEarlyYearsAsWritten() {
		// A long half-life, so that a date in year 50 does not decay to zero
		$ranker = new HotRanker( 1e6 );
		$this->assertSame(
			$ranker->decay( '0050-01-01T00:00:00Z', self::NOW ),
			$ranker->decay( '00500101000000', self::NOW )
		);
		$this->assertLessThan( 1.0, $ranker->decay( '00500101000000', self::NOW ) );
	}

	/**
	 * The order of a plain comparator sort: score, then creation time, then ID, all descending.
	 */
	public function testRankMatchesComparatorSort() {
		$ranker = new HotRanker( 90 );
		$candidates = [];
		for ( $i = 0; $i < 500; $i++ ) {
			// Scattered IDs, coarse times and few like counts, so that scores and times often tie
			$candidates[] = [
				'id' => ( $i * 7919 ) % 100003 + 1,
				'likes' => ( $i * 37 ) % 5,
				'created' => self::daysAgo( ( $i * 53 ) % 63 - 2 ),
			];
		}
		$scored = [];
		foreach ( $candidates as $c ) {
			$scored[] = [ $c['id'], $ranker->hotScore( $c['likes'], $c['created'], self::NOW ), $c['created'] ];
		}
		usort( $scored, static fn ( $a, $b ) => [ $b[1], $b[2], $b[0] ] <=> [ $a[1], $a[2], $a[0] ] );
		$this->assertSame( array_column( $scored, 0 ), $ranker->rank( $candidates, self::NOW ) );
	}

	/**
	 * The scores of Unix times are the floats decay() gave before they existed, bit for bit
	 */
	public function testScoresOfUnixTimesAreTheSame() {
		foreach ( [ 30, 90, 365.25 ] as $halfLife ) {
			$ranker = new HotRanker( $halfLife );
			for ( $i = 0; $i < 200; $i++ ) {
				$created = self::NOW + 86400 - ( $i * 7654321 ) % ( 400 * 86400 );
				$decay = 0.5 ** ( ( max( 0, self::NOW - $created ) / 86400 ) / $halfLife );
				$timestamp = ConvertibleTimestamp::convert( TS_MW, $created );
				$this->assertSame( $decay, $ranker->decay( $timestamp, self::NOW ) );
				$this->assertSame( 3 * $decay, $ranker->popularScoreAt( 3, $created, self::NOW ) );
				$this->assertSame(
					$ranker->popularScore( 3, $timestamp, self::NOW ),
					$ranker->popularScoreAt( 3, $created, self::NOW )
				);
			}
		}
	}

	public function testRankReportsTimesAndTies() {
		$ranker = new HotRanker( 30 );
		$candidates = [
			[ 'id' => 1, 'likes' => 9, 'created' => self::daysAgo( 1 ) ],
			// Tied: 1 * 0.5 ^ 1 and 2 * 0.5 ^ 2. At other times rounding may order them either way.
			[ 'id' => 2, 'likes' => 0, 'created' => self::daysAgo( 30 ) ],
			[ 'id' => 3, 'likes' => 1, 'created' => self::daysAgo( 60 ) ],
		];
		$ranker->rank( $candidates, self::NOW, $span );
		$this->assertSame(
			[ 'newest' => self::NOW - 86400, 'oldest' => self::NOW - 60 * 86400, 'tie' => 2 ],
			$span
		);
		$ranker->rank( $candidates, self::NOW, $span, 1 );
		$this->assertSame( PHP_INT_MAX, $span['tie'], 'not checked that far' );
		$ranker->rank( [ $candidates[0], [ 'id' => 4 ] + $candidates[0] ], self::NOW, $span );
		$this->assertSame( PHP_INT_MAX, $span['tie'], 'equal likes and times are equal at every time' );
		$candidates[2]['likes'] = 2;
		$ranker->rank( $candidates, self::NOW, $span );
		$this->assertSame( PHP_INT_MAX, $span['tie'], 'not tied' );
		$ranker->rank( [], self::NOW, $span );
		$this->assertNull( $span );
	}

	public function testMayTie() {
		$this->assertTrue( HotRanker::mayTie( 0.5, 0.5, 1, 2 ) );
		$this->assertTrue( HotRanker::mayTie( 0.5, 0.5 * ( 1 - 1e-13 ), '20260101000000', '20251201000000' ) );
		$this->assertFalse( HotRanker::mayTie( 0.5, 0.5, 1, 1 ), 'same time' );
		$this->assertFalse( HotRanker::mayTie( 0.0, 0.0, 1, 2 ), 'no likes: 0 at every time' );
		$this->assertFalse( HotRanker::mayTie( 0.5, 0.499, 1, 2 ) );
	}

	public function testTiesGoToNewer() {
		$ranker = new HotRanker( 90 );
		$created = self::daysAgo( 3 );
		$this->assertSame( [ 9, 8 ], $ranker->rank( [
			[ 'id' => 8, 'likes' => 1, 'created' => $created ],
			[ 'id' => 9, 'likes' => 1, 'created' => $created ],
		], self::NOW ) );
	}
}
