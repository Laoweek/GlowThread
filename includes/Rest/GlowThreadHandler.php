<?php

namespace MediaWiki\Extension\GlowThread\Rest;

use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use Wikimedia\Message\MessageValue;

/**
 * Shared behaviour of the GlowThread REST routes: read-only GETs, private by default, JSON
 * errors with a stable errorKey and an explicit Cache-Control header.
 */
abstract class GlowThreadHandler extends SimpleHandler {

	/** Cache-Control for anything personal or not meant for the CDN */
	public const PRIVATE_CACHE = 'private, must-revalidate, max-age=0, s-maxage=0';

	/** @inheritDoc */
	public function needsWriteAccess() {
		return false;
	}

	/**
	 * A JSON response that must not be cached by the CDN.
	 *
	 * @param array $data
	 */
	protected function privateJson( array $data ): Response {
		$response = $this->getResponseFactory()->createJson( $data );
		$response->setHeader( 'Cache-Control', self::PRIVATE_CACHE );
		return $response;
	}

	/**
	 * A localised error. Errors skip the framework's cache-control step, so the header is set here.
	 *
	 * @param int $httpCode
	 * @param string $errorKey Stable code, also the message key suffix (glowthread-rest-<errorKey>)
	 */
	protected function error( int $httpCode, string $errorKey ): Response {
		$response = $this->getResponseFactory()->createLocalizedHttpError(
			$httpCode,
			new MessageValue( 'glowthread-rest-' . $errorKey ),
			[ 'errorKey' => $errorKey ]
		);
		$response->setHeader( 'Cache-Control', self::PRIVATE_CACHE );
		return $response;
	}
}
