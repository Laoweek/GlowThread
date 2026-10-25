<?php

namespace MediaWiki\Extension\GlowThread\Import;

use InvalidArgumentException;

/**
 * A FlowThread comment ID: 11 bytes (88 bits) from GlobalIdGenerator::newTimestampedUID88().
 *
 * Big-endian bit layout: 46 bits of milliseconds since the Unix epoch, a 10-bit counter, then a
 * 32-bit node ID of the generating host. Byte order, and the order of the lowercase hex form, is
 * therefore creation order to the millisecond. Inside one millisecond the counter decides, and it
 * starts at a random offset and wraps at 1024, so two IDs from the same millisecond can sort
 * against their creation order. MediaWiki accepts the same limit for these IDs.
 *
 * FlowThread has no timestamp column. The creation time exists only inside this ID.
 */
final class LegacyId {

	public const BINARY_LENGTH = 11;
	public const HEX_LENGTH = 22;

	/** IDs dated before this (2016-01-01 00:00:00 UTC) are implausible: FlowThread came later */
	public const EARLIEST_PLAUSIBLE = 1451606400;

	/** Problem codes returned by getProblem() */
	public const PROBLEM_TOO_EARLY = 'before-2016';
	public const PROBLEM_FUTURE = 'future';

	/** @var string 22 lowercase hex digits */
	private string $hex;

	private function __construct( string $hex ) {
		$this->hex = $hex;
	}

	/**
	 * @param string $binary The 11 raw bytes of flowthread_id, flowthread_parentid or flowthread_att_id
	 */
	public static function fromBinary( string $binary ): self {
		if ( strlen( $binary ) !== self::BINARY_LENGTH ) {
			throw new InvalidArgumentException(
				'A FlowThread ID has ' . self::BINARY_LENGTH . ' bytes, got ' . strlen( $binary )
			);
		}
		return new self( bin2hex( $binary ) );
	}

	/**
	 * @param string $hex 22 hex digits in either case, as from SQL HEX() or FlowThread's UID::getHex()
	 */
	public static function fromHex( string $hex ): self {
		if ( strlen( $hex ) !== self::HEX_LENGTH || !ctype_xdigit( $hex ) ) {
			throw new InvalidArgumentException( 'A FlowThread hex ID has ' . self::HEX_LENGTH . ' hex digits' );
		}
		return new self( strtolower( $hex ) );
	}

	/**
	 * Accepts either form: 11 raw bytes or 22 hex digits.
	 */
	public static function fromString( string $value ): self {
		return strlen( $value ) === self::BINARY_LENGTH ? self::fromBinary( $value ) : self::fromHex( $value );
	}

	/**
	 * @return string 22 lowercase hex digits, the form stored in glowthread_post.gtp_legacy_id
	 */
	public function getHex(): string {
		return $this->hex;
	}

	public function getBinary(): string {
		return hex2bin( $this->hex );
	}

	/**
	 * @return int Milliseconds since the Unix epoch (UTC)
	 */
	public function getMilliseconds(): int {
		// The first 12 hex digits are 48 bits: the 46-bit time and the top 2 counter bits
		return intdiv( (int)hexdec( substr( $this->hex, 0, 12 ) ), 4 );
	}

	/**
	 * @return int The 10-bit tie-breaking counter (0 to 1023)
	 */
	public function getCounter(): int {
		return ( ( (int)hexdec( $this->hex[11] ) & 3 ) << 8 ) | (int)hexdec( substr( $this->hex, 12, 2 ) );
	}

	/**
	 * @return int The 32-bit node ID of the host that made the ID
	 */
	public function getNode(): int {
		return (int)hexdec( substr( $this->hex, 14, 8 ) );
	}

	/**
	 * @return int Seconds since the Unix epoch, rounded down (what FlowThread's UID::getTimestamp() returns)
	 */
	public function getUnixTime(): int {
		return intdiv( $this->getMilliseconds(), 1000 );
	}

	/**
	 * @return string The creation time as a MediaWiki timestamp (TS_MW, YYYYMMDDHHMMSS in UTC)
	 */
	public function getTimestamp(): string {
		return gmdate( 'YmdHis', $this->getUnixTime() );
	}

	/**
	 * Why the decoded time cannot be a real FlowThread creation time, if it cannot. Rows made by
	 * Special:FlowThreadImport keep whatever ID their JSON had, so a bad date points at those.
	 *
	 * @param int $now Current Unix time
	 * @return string|null PROBLEM_TOO_EARLY, PROBLEM_FUTURE, or null when the date is plausible
	 */
	public function getProblem( int $now ): ?string {
		$time = $this->getUnixTime();
		if ( $time < self::EARLIEST_PLAUSIBLE ) {
			return self::PROBLEM_TOO_EARLY;
		}
		if ( $time > $now ) {
			return self::PROBLEM_FUTURE;
		}
		return null;
	}

	/**
	 * Creation order: negative if $a sorts before $b, 0 for the same ID. Usable with usort().
	 * Exact to the millisecond, so two IDs from the same second keep their order here even though
	 * getTimestamp() is equal. Within one millisecond the order is the counter's (see the class).
	 */
	public static function compare( self $a, self $b ): int {
		return strcmp( $a->hex, $b->hex ) <=> 0;
	}

	public function equals( self $other ): bool {
		return $this->hex === $other->hex;
	}
}
