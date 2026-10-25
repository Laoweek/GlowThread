<?php

namespace MediaWiki\Extension\GlowThread\Tests\Unit\Query;

use MediaWiki\Extension\GlowThread\Query\Cursor;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\GlowThread\Query\Cursor
 */
class CursorTest extends MediaWikiUnitTestCase {

	private const SCHEMA = [ 'c' => 'ts', 'i' => 'int' ];

	public function testRoundTrip() {
		$cursor = Cursor::encode( 'new', [ 'c' => '20261008123456', 'i' => 42 ] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+$/', $cursor, 'URL-safe' );
		$this->assertSame( [ 'c' => '20261008123456', 'i' => 42 ], Cursor::decode( $cursor, 'new', self::SCHEMA ) );
	}

	public static function provideInvalid() {
		yield 'empty' => [ '' ];
		yield 'not base64url' => [ 'a+b/c=' ];
		yield 'not JSON' => [ rtrim( strtr( base64_encode( 'nope' ), '+/', '-_' ), '=' ) ];
		yield 'other kind' => [ Cursor::encode( 'hot', [ 'c' => '20261008123456', 'i' => 1 ] ) ];
		yield 'negative id' => [ Cursor::encode( 'new', [ 'c' => '20261008123456', 'i' => -1 ] ) ];
		yield 'string id' => [ Cursor::encode( 'new', [ 'c' => '20261008123456', 'i' => '1' ] ) ];
		yield 'bad timestamp' => [ Cursor::encode( 'new', [ 'c' => '2026-10-08', 'i' => 1 ] ) ];
		yield 'SQL in timestamp' => [ Cursor::encode( 'new', [ 'c' => "1' OR '1'='1", 'i' => 1 ] ) ];
		// Fourteen digits, but not a date: these made the database layer throw (HTTP 500)
		yield 'month 13, day 99' => [ Cursor::encode( 'new', [ 'c' => '99991399999999', 'i' => 1 ] ) ];
		yield 'all zeros' => [ Cursor::encode( 'new', [ 'c' => '00000000000000', 'i' => 1 ] ) ];
		yield 'February 31' => [ Cursor::encode( 'new', [ 'c' => '20260231000000', 'i' => 1 ] ) ];
		yield 'missing field' => [ Cursor::encode( 'new', [ 'c' => '20261008123456' ] ) ];
		yield 'too long' => [ str_repeat( 'a', 600 ) ];
	}

	/**
	 * @dataProvider provideInvalid
	 */
	public function testInvalid( string $cursor ) {
		$this->assertNull( Cursor::decode( $cursor, 'new', self::SCHEMA ) );
	}
}
