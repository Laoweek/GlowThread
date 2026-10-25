<?php

namespace MediaWiki\Extension\GlowThread\Query;

use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Time-decayed like scores.
 *
 * hot score     = (likes + 1) * 0.5 ^ (age in days / half-life)
 * popular score =  likes      * 0.5 ^ (age in days / half-life)
 *
 * The +1 keeps comments without likes in newest-first order. Ties go to the newer comment.
 *
 * The order of two comments does not depend on the time it is computed for: the ratio of their
 * scores is ( likes + 1 ) / ( likes' + 1 ) * 0.5 ^ ( ( created' - created ) / half-life), without
 * the time. So an order computed once holds later too (ListCache keeps it), as long as no comment
 * is dated after the time of either order (those count as just posted until then), no comment
 * is older than STABLE_HALF_LIVES half-lives at either time (getStableUntil()), and no two
 * neighbours tie (mayTie()).
 */
class HotRanker {

	/**
	 * Half-lives within which a score keeps its precision: 0.5 ^ 1000 is about 1e-301, far above
	 * the smallest normal float (2 ^ -1022). An older comment's factor loses precision and then
	 * becomes 0 (after about 1075 half-lives), so the order of such comments changes with the
	 * time.
	 */
	private const STABLE_HALF_LIVES = 1000;

	/**
	 * Relative difference up to which two scores count as tied. Rounding moves a score by less
	 * than 1e-12 of it within STABLE_HALF_LIVES; scores that differ but are not tied differ by far
	 * more (their times would have to be a fraction of a second from a tie).
	 */
	private const TIE = 1e-9;

	/**
	 * @param float $halfLifeDays
	 */
	public function __construct( private readonly float $halfLifeDays ) {
	}

	/**
	 * @param string $created TS_MW, or any other format ConvertibleTimestamp reads
	 * @param int $now
	 */
	public function decay( string $created, int $now ): float {
		return $this->decayAt( self::toUnix( $created ), $now );
	}

	/**
	 * decay() of a Unix time
	 */
	private function decayAt( int $created, int $now ): float {
		$age = max( 0, $now - $created ) / 86400;
		return 0.5 ** ( $age / $this->halfLifeDays );
	}

	/**
	 * Unix time of a timestamp. TS_MW, the common case, is read directly: ranking converts
	 * thousands of timestamps per request, and ConvertibleTimestamp's parsing costs several times
	 * more. Years before 1000 take the slow path, as gmmktime() reads years up to 100 as 19xx or 20xx.
	 */
	public static function toUnix( string $timestamp ): int {
		if ( strlen( $timestamp ) === 14 && ctype_digit( $timestamp ) && $timestamp[0] !== '0' ) {
			return gmmktime(
				(int)substr( $timestamp, 8, 2 ), (int)substr( $timestamp, 10, 2 ), (int)substr( $timestamp, 12, 2 ),
				(int)substr( $timestamp, 4, 2 ), (int)substr( $timestamp, 6, 2 ), (int)substr( $timestamp, 0, 4 )
			);
		}
		return (int)ConvertibleTimestamp::convert( TS_UNIX, $timestamp );
	}

	/**
	 * The time before which an order computed for an earlier time, from candidates of which
	 * $oldest is the oldest, is the order of every time up to it (see the class comment).
	 *
	 * @param int $oldest Unix time of the oldest candidate
	 * @return int Unix time
	 */
	public function getStableUntil( int $oldest ): int {
		return $oldest + (int)floor( self::STABLE_HALF_LIVES * 86400 * $this->halfLifeDays );
	}

	public function hotScore( int $likes, string $created, int $now ): float {
		return ( $likes + 1 ) * $this->decay( $created, $now );
	}

	public function popularScore( int $likes, string $created, int $now ): float {
		return $likes * $this->decay( $created, $now );
	}

	/**
	 * popularScore() of a Unix time
	 */
	public function popularScoreAt( int $likes, int $created, int $now ): float {
		return $likes * $this->decayAt( $created, $now );
	}

	/**
	 * Whether two scores next to each other in an order may come out in the other order at
	 * another time: equal but for rounding, from different times (the ratio of their likes, or
	 * likes + 1, is a power of 2, and their times are that many half-lives apart). Scores from the
	 * same likes and time are equal at every time.
	 *
	 * @param float $higher
	 * @param float $lower
	 * @param int|string $createdHigher The time of the higher score, as Unix time or TS_MW
	 * @param int|string $createdLower The time of the lower score, in the same form
	 */
	public static function mayTie( float $higher, float $lower, $createdHigher, $createdLower ): bool {
		return $higher > 0 && $higher - $lower <= $higher * self::TIE && $createdHigher !== $createdLower;
	}

	/**
	 * Order candidates by hot score.
	 *
	 * @param array<int,array{id:int,likes:int,created:string}> $candidates
	 * @param int $now Unix time the scores are computed for (kept in the cursor, so pages agree)
	 * @param array{newest:int,oldest:int,tie:int}|null &$span Set, when given, to what tells
	 *   whether the order holds at other times (see the class comment): the Unix times of the
	 *   newest and the oldest candidate, and the first position, among the first $checked + 1,
	 *   whose candidate ties with the one before (mayTie()), else PHP_INT_MAX. Null for no
	 *   candidates.
	 * @param int $checked How many of the first positions are checked for ties
	 * @return int[] IDs, best first
	 */
	public function rank( array $candidates, int $now, ?array &$span = null, int $checked = PHP_INT_MAX ): array {
		$ids = [];
		$scores = [];
		$created = [];
		$times = [];
		foreach ( $candidates as $c ) {
			// hotScore(), with the time converted once
			$time = self::toUnix( $c['created'] );
			$ids[] = $c['id'];
			$scores[] = ( $c['likes'] + 1 ) * $this->decayAt( $time, $now );
			$created[] = $c['created'];
			$times[] = $time;
		}
		// Best score first, then the newer comment, then the higher ID. Several times faster than
		// usort() with a comparator on thousands of candidates.
		array_multisort(
			$scores, SORT_DESC, SORT_NUMERIC,
			$created, SORT_DESC, SORT_REGULAR,
			$ids, SORT_DESC, SORT_NUMERIC
		);
		if ( func_num_args() > 2 ) {
			$span = null;
			if ( $times ) {
				$span = [ 'newest' => max( $times ), 'oldest' => min( $times ), 'tie' => PHP_INT_MAX ];
				$last = min( count( $scores ) - 1, $checked );
				for ( $i = 1; $i <= $last; $i++ ) {
					if ( self::mayTie( $scores[$i - 1], $scores[$i], $created[$i - 1], $created[$i] ) ) {
						$span['tie'] = $i;
						break;
					}
				}
			}
		}
		return $ids;
	}
}
