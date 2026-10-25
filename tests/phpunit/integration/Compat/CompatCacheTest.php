<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Compat;

use MediaWiki\Api\ApiMain;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Tests\Api\ApiTestCase;
use Wikimedia\TestingAccessWrapper;

/**
 * Answers of action=flowthread&type=list are never cacheable by a CDN: with any extra parameter
 * the URL changes, so they could not be purged when a moderator hides a comment (security review
 * 2026-10-10, I15).
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Compat\ApiFlowThreadCompat
 */
class CompatCacheTest extends ApiTestCase {
	use GlowThreadTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
		$this->overrideConfigValue( 'GlowThreadLegacyCompat', true );
	}

	/**
	 * An anonymous api.php request, with the cache headers ApiMain sends for it.
	 *
	 * @return array{0:string,1:string} Cache-Control, result as JSON
	 */
	private function anonCompat( array $params ): array {
		$request = new FauxRequest( $params + [ 'action' => 'flowthread', 'format' => 'json' ] );
		$context = new RequestContext();
		$context->setRequest( $request );
		$context->setUser( $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' ) );
		$main = new ApiMain( $context );
		$main->execute();
		TestingAccessWrapper::newFromObject( $main )->sendCacheHeaders( false );
		return [
			(string)$request->response()->getHeader( 'Cache-Control' ),
			json_encode( $main->getResult()->getResultData() ),
		];
	}

	public function testListIsPrivate() {
		$page = $this->gtPage( 'GlowThread compat cache' );
		$this->gtPost( $this->gtUser(), $page, 'COMPAT-CACHE-MARKER' );

		foreach ( [ [], [ 'smaxage' => 600, 'maxage' => 600 ], [ 'limit' => 7, 'cb' => 'x1' ] ] as $extra ) {
			[ $cacheControl, $body ] = $this->anonCompat( [ 'type' => 'list', 'pageid' => $page->getId() ] + $extra );
			$this->assertStringContainsString( 'COMPAT-CACHE-MARKER', $body );
			$this->assertStringStartsWith( 'private', $cacheControl, json_encode( $extra ) );
			$this->assertStringNotContainsString( 's-maxage', $cacheControl, json_encode( $extra ) );
		}
	}
}
