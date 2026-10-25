<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Rest;

use GuzzleHttp\Psr7\Uri;
use MediaWiki\Extension\GlowThread\Rest\ListHandler;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Rest\RequestData;
use MediaWiki\Rest\ResponseInterface;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWikiIntegrationTestCase;

/**
 * The first page is asked for without a cursor. A CDN serves a request with an empty cursor from
 * the cached first page, so one that reaches MediaWiki anyway is refused instead of running the
 * uncached list query.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Rest\ListHandler
 */
class ListHandlerEmptyCursorTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;
	use HandlerTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
	}

	private function get( array $query ): ResponseInterface {
		$page = $this->gtPage();
		$this->gtPost( $this->gtUser(), $page, 'x' );
		$purger = $this->gtService( 'CdnPurger' );
		return $this->executeHandler(
			ListHandler::factory(
				$this->gtService( 'Config' ),
				$purger,
				$this->gtService( 'ListCache' ),
				$this->gtService( 'PageEligibility' ),
				$this->getServiceContainer()->getTitleFormatter()
			),
			new RequestData( [
				'uri' => new Uri( $purger->getListRoutePath( $page->getId() ) . '?' . http_build_query( $query ) ),
				'pathParams' => [ 'pageId' => $page->getId() ],
				'queryParams' => $query,
			] ),
			[], [], [], [],
			$this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' )
		);
	}

	public function testEmptyCursorIsRefused() {
		$response = $this->get( [ 'sort' => 'hot', 'cursor' => '', 'limit' => 50 ] );
		$this->assertSame( 400, $response->getStatusCode() );
		$this->assertSame( 'bad-cursor', json_decode( $response->getBody()->getContents(), true )['errorKey'] );
	}

	public function testFirstPageNeedsNoCursor() {
		$response = $this->get( [ 'sort' => 'hot', 'limit' => 50 ] );
		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertCount( 1, json_decode( $response->getBody()->getContents(), true )['comments'] );
	}
}
