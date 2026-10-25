<?php

namespace MediaWiki\Extension\GlowThread\Query;

use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Opaque paging cursors: base64url-encoded JSON with a kind marker. Cursors are not signed;
 * every field is validated when decoded, so a crafted cursor can only ask for another page.
 * A timestamp must be a real date that converts back to itself (99991399999999 or
 * 00000000000000 would make the database layer throw), so a bad cursor is a 400, never a 500.
 */
final class Cursor {

	/**
	 * @param string $kind
	 * @param array<string,int|string> $data
	 */
	public static function encode( string $kind, array $data ): string {
		$json = json_encode( [ 'k' => $kind ] + $data );
		return rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
	}

	/**
	 * @param string $cursor
	 * @param string $kind Expected kind
	 * @param array<string,string> $schema Field => 'int' (non-negative integer) or 'ts' (valid
	 *   14-digit TS_MW timestamp)
	 * @return array<string,int|string>|null Null if the cursor is malformed or of another kind
	 */
	public static function decode( string $cursor, string $kind, array $schema ): ?array {
		if ( $cursor === '' || strlen( $cursor ) > 512 || !preg_match( '/^[A-Za-z0-9_-]+$/', $cursor ) ) {
			return null;
		}
		$json = base64_decode( strtr( $cursor, '-_', '+/' ), true );
		if ( $json === false ) {
			return null;
		}
		$data = json_decode( $json, true );
		if ( !is_array( $data ) || ( $data['k'] ?? null ) !== $kind ) {
			return null;
		}
		$out = [];
		foreach ( $schema as $field => $type ) {
			$value = $data[$field] ?? null;
			if ( $type === 'int' ) {
				if ( !is_int( $value ) || $value < 0 ) {
					return null;
				}
			} elseif ( $type === 'ts' ) {
				if ( !is_string( $value ) || !preg_match( '/^\d{14}$/', $value )
					|| ConvertibleTimestamp::convert( TS_MW, $value ) !== $value
				) {
					return null;
				}
			} else {
				return null;
			}
			$out[$field] = $value;
		}
		return $out;
	}
}
