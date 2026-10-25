<?php

namespace MediaWiki\Extension\GlowThread\Policy;

use MediaWiki\Api\ApiMessage;
use MediaWiki\Permissions\Authority;
use StatusValue;

/**
 * GlowThread's rate limits as configured: the ratelimited error of the write modules, with
 * retryAfter (whole seconds the client should wait before trying again), and the effective
 * limits for the settings screen.
 *
 *
 * MediaWiki's rate limiter does not say when the next request will be let through, so
 * retryAfter is the longest window among the $wgRateLimits conditions of the action that can
 * apply to this kind of user (anon, ip, subnet, ip-all and subnet-all for anonymous users;
 * user, newbie, user-global, ip-all and subnet-all for accounts). It is an upper bound. With the
 * default limits every condition of an action has the same window, so it is that window.
 */
class RateLimitErrors {

	/** Used when the action has no window in $wgRateLimits (a limit set by a PingLimiter hook) */
	private const DEFAULT_RETRY_AFTER = 60;

	private const ANON_TYPES = [ 'anon', 'ip', 'subnet', 'ip-all', 'subnet-all' ];

	/** GlowThread's $wgRateLimits keys, in the order the settings screen lists them */
	public const KEYS = [
		'glowthread-post', 'glowthread-react', 'glowthread-report',
		'glowthread-moderate', 'glowthread-erase', 'glowthread-configure',
	];
	private const USER_TYPES = [ 'user', 'newbie', 'user-global', 'ip', 'subnet', 'ip-all', 'subnet-all' ];

	/**
	 * @param array $rateLimits $wgRateLimits
	 */
	public function __construct( private readonly array $rateLimits ) {
	}

	public function getRetryAfter( string $action, Authority $authority ): int {
		$conditions = $this->rateLimits[$action] ?? [];
		$retryAfter = 0;
		foreach ( $authority->isRegistered() ? self::USER_TYPES : self::ANON_TYPES as $type ) {
			$condition = $conditions[$type] ?? null;
			if ( is_array( $condition ) && isset( $condition[1] ) ) {
				$retryAfter = max( $retryAfter, (int)$condition[1] );
			}
		}
		return $retryAfter > 0 ? $retryAfter : self::DEFAULT_RETRY_AFTER;
	}

	/**
	 * The effective limits of GlowThread's keys (LocalSettings merged with the defaults).
	 *
	 * @return array[] List of [ 'key' => string, 'limits' => object type => [ count, seconds ]
	 *   (an object also when empty, for JSON), 'canBypass' => bool (false when noratelimit
	 *   does not exempt sysops) ]
	 */
	public function describe(): array {
		$out = [];
		foreach ( self::KEYS as $key ) {
			$conditions = $this->rateLimits[$key] ?? null;
			if ( !is_array( $conditions ) ) {
				continue;
			}
			$limits = [];
			foreach ( $conditions as $type => $condition ) {
				if ( is_string( $type ) && $type[0] !== '&' && is_array( $condition ) && count( $condition ) >= 2 ) {
					$limits[$type] = [ (int)$condition[0], (int)$condition[1] ];
				}
			}
			$out[] = [
				'key' => $key,
				'limits' => (object)$limits,
				'canBypass' => ( $conditions['&can-bypass'] ?? true ) !== false,
			];
		}
		return $out;
	}

	/**
	 * The error for a tripped limit: code ratelimited, data retryAfter.
	 */
	public function newStatus( string $action, Authority $authority ): StatusValue {
		return StatusValue::newFatal( ApiMessage::create(
			'apierror-ratelimited',
			'ratelimited',
			[ 'retryAfter' => $this->getRetryAfter( $action, $authority ) ]
		) );
	}
}
