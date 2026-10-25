<?php

namespace MediaWiki\Extension\GlowThread\Tests\Unit\Import;

use InvalidArgumentException;
use MediaWiki\Extension\GlowThread\Import\LegacyId;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\GlowThread\Import\LegacyId
 */
class LegacyIdTest extends MediaWikiUnitTestCase {

	/** 2025-03-09 04:49:16.123 UTC, counter 677, node 0xDEADBEEF (computed independently) */
	private const KNOWN_HEX = '0655e4f1956ea5deadbeef';

	/**
	 * The ID layout of GlobalIdGenerator::getTimestampedID88(): 46 bits of milliseconds, a 10-bit
	 * counter and a 32-bit node, big-endian.
	 */
	private static function makeHex( int $ms, int $counter, int $node ): string {
		return sprintf( '%012x%02x%08x', ( $ms << 2 ) | ( $counter >> 8 ), $counter & 0xff, $node );
	}

	public function testDecodesKnownId() {
		$id = LegacyId::fromHex( self::KNOWN_HEX );
		$this->assertSame( 1741495756123, $id->getMilliseconds() );
		$this->assertSame( 677, $id->getCounter() );
		$this->assertSame( 0xDEADBEEF, $id->getNode() );
		$this->assertSame( 1741495756, $id->getUnixTime() );
		$this->assertSame( '20250309044916', $id->getTimestamp() );
		$this->assertSame( self::KNOWN_HEX, self::makeHex( 1741495756123, 677, 0xDEADBEEF ) );
	}

	public function testMatchesFlowThreadTimestampFormula() {
		// FlowThread's UID::getTimestamp(): floor(hexdec(first 12 hex digits) / 4000)
		$hex = self::makeHex( 1600000000999, 1023, 7 );
		$this->assertSame(
			(int)floor( hexdec( substr( $hex, 0, 12 ) ) / 4000 ),
			LegacyId::fromHex( $hex )->getUnixTime()
		);
	}

	public function testBinaryAndHexFormsAgree() {
		$binary = hex2bin( self::KNOWN_HEX );
		$fromBinary = LegacyId::fromBinary( $binary );
		$fromHex = LegacyId::fromHex( strtoupper( self::KNOWN_HEX ) );
		$this->assertSame( self::KNOWN_HEX, $fromBinary->getHex() );
		$this->assertSame( self::KNOWN_HEX, $fromHex->getHex(), 'SQL HEX() output is upper case' );
		$this->assertSame( $binary, $fromHex->getBinary() );
		$this->assertTrue( $fromBinary->equals( $fromHex ) );
		$this->assertSame( self::KNOWN_HEX, LegacyId::fromString( $binary )->getHex() );
		$this->assertSame( self::KNOWN_HEX, LegacyId::fromString( self::KNOWN_HEX )->getHex() );
	}

	public function testLeadingZeroBytesSurvive() {
		// UID::generate() pads the hex form to 22 digits, so early IDs start with zero bytes
		$id = LegacyId::fromBinary( hex2bin( '0000000000040100000001' ) );
		$this->assertSame( '0000000000040100000001', $id->getHex() );
		$this->assertSame( 1, $id->getMilliseconds() );
		$this->assertSame( 1, $id->getCounter() );
		$this->assertSame( 1, $id->getNode() );
	}

	public static function provideInvalid() {
		yield 'ten bytes' => [ str_repeat( "\x01", 10 ) ];
		yield 'twelve bytes' => [ str_repeat( "\x01", 12 ) ];
		yield '21 hex digits' => [ substr( self::KNOWN_HEX, 1 ) ];
		yield '32 hex digits (128-bit ID)' => [ str_repeat( 'a', 32 ) ];
		yield 'not hex' => [ 'zz55e4f1956ea5deadbeef' ];
		yield 'empty' => [ '' ];
	}

	/**
	 * @dataProvider provideInvalid
	 */
	public function testRejectsMalformedIds( string $value ) {
		$this->expectException( InvalidArgumentException::class );
		LegacyId::fromString( $value );
	}

	public function testBinaryRejectsHexOfWrongLength() {
		$this->expectException( InvalidArgumentException::class );
		LegacyId::fromBinary( self::KNOWN_HEX );
	}

	public function testCreationOrderSurvivesSameSecond() {
		$ms = 1741495756000;
		$ids = [
			LegacyId::fromHex( self::makeHex( $ms + 999, 0, 1 ) ),
			LegacyId::fromHex( self::makeHex( $ms, 1, 0 ) ),
			LegacyId::fromHex( self::makeHex( $ms, 0, 0xFFFFFFFF ) ),
			LegacyId::fromHex( self::makeHex( $ms + 1, 0, 0 ) ),
			LegacyId::fromHex( self::makeHex( $ms, 300, 5 ) ),
		];
		$sorted = $ids;
		usort( $sorted, [ LegacyId::class, 'compare' ] );
		$this->assertSame(
			[ $ids[2], $ids[1], $ids[4], $ids[3], $ids[0] ],
			$sorted,
			'Milliseconds first, then the counter, then the node'
		);
		foreach ( $ids as $id ) {
			$this->assertSame( '20250309044916', $id->getTimestamp(), 'All five fall in one second' );
		}
		$this->assertSame( 0, LegacyId::compare( $ids[0], LegacyId::fromHex( $ids[0]->getHex() ) ) );

		$byHex = array_map( static fn ( LegacyId $id ) => $id->getHex(), $ids );
		sort( $byHex, SORT_STRING );
		$this->assertSame(
			array_map( static fn ( LegacyId $id ) => $id->getHex(), $sorted ),
			$byHex,
			'Sorting the hex strings gives the same order'
		);
	}

	public function testPlausibility() {
		$now = 1790000000;
		$this->assertNull( LegacyId::fromHex( self::KNOWN_HEX )->getProblem( $now ) );
		$this->assertSame(
			LegacyId::PROBLEM_TOO_EARLY,
			LegacyId::fromHex( self::makeHex( 1451606399999, 0, 1 ) )->getProblem( $now ),
			'The last millisecond of 2015'
		);
		$this->assertNull(
			LegacyId::fromHex( self::makeHex( 1451606400000, 0, 1 ) )->getProblem( $now ),
			'2016-01-01 00:00:00 UTC'
		);
		$this->assertSame(
			LegacyId::PROBLEM_TOO_EARLY,
			LegacyId::fromHex( str_repeat( '0', 22 ) )->getProblem( $now )
		);
		$this->assertSame(
			LegacyId::PROBLEM_FUTURE,
			LegacyId::fromHex( self::makeHex( ( $now + 1 ) * 1000, 0, 1 ) )->getProblem( $now )
		);
		$this->assertNull( LegacyId::fromHex( self::makeHex( $now * 1000 + 999, 0, 1 ) )->getProblem( $now ) );
	}

	public function testLargestId() {
		$id = LegacyId::fromHex( str_repeat( 'f', 22 ) );
		$this->assertSame( 2 ** 46 - 1, $id->getMilliseconds() );
		$this->assertSame( 1023, $id->getCounter() );
		$this->assertSame( 0xFFFFFFFF, $id->getNode() );
		$this->assertSame( LegacyId::PROBLEM_FUTURE, $id->getProblem( 1790000000 ) );
	}
}
