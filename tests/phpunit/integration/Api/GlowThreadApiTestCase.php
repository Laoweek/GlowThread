<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Api;

use MediaWiki\Api\ApiUsageException;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Permissions\Authority;
use MediaWiki\Tests\Api\ApiTestCase;

/**
 * Base class for the write module tests.
 */
abstract class GlowThreadApiTestCase extends ApiTestCase {
	use GlowThreadTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
	}

	/**
	 * Call a module as a user with a valid CSRF token.
	 *
	 * @param array $params
	 * @param Authority $performer
	 * @return array Result data
	 */
	protected function gtApi( array $params, Authority $performer ): array {
		return $this->doApiRequestWithToken( $params, null, $performer )[0];
	}

	/**
	 * Call a module and return the first error it fails with, as the API formats it (code, data).
	 */
	protected function gtApiErrorArray( array $params, Authority $performer ): array {
		try {
			$this->gtApi( $params, $performer );
		} catch ( ApiUsageException $e ) {
			return self::getErrorFormatter()->arrayFromStatus( $e->getStatusValue() )[0];
		}
		$this->fail( 'Expected an API error for ' . json_encode( $params ) );
	}

	/**
	 * The extension's own default rate limits (tests otherwise run without any).
	 */
	protected function gtDefaultRateLimits(): array {
		return json_decode( file_get_contents( __DIR__ . '/../../../../extension.json' ), true )['RateLimits'];
	}

	/**
	 * Call a module and return the error code it fails with.
	 */
	protected function gtApiError( array $params, Authority $performer ): string {
		try {
			$this->gtApi( $params, $performer );
		} catch ( ApiUsageException $e ) {
			$codes = array_column( self::getErrorFormatter()->arrayFromStatus( $e->getStatusValue() ), 'code' );
			return implode( ',', array_unique( $codes ) );
		}
		$this->fail( 'Expected an API error for ' . json_encode( $params ) );
	}
}
