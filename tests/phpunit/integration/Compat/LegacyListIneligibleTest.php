<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Compat;

use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Permissions\Authority;
use MediaWiki\Tests\Api\ApiTestCase;

/**
 * Security review R3: action=flowthread&type=list served the comments of a page where comments
 * no longer appear (its namespace left $wgGlowThreadNamespaces), although the article and
 * GlowThread's own list no longer show them. It answers as if the page had no comments now, as
 * on a page whose comments are turned off.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Compat\LegacyListService
 */
class LegacyListIneligibleTest extends ApiTestCase {
	use GlowThreadTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
		$this->overrideConfigValue( 'GlowThreadLegacyCompat', true );
		$this->overrideConfigValue( 'GlowThreadPopularMinLikes', 1 );
	}

	private function list( int $pageId, Authority $performer ): array {
		return $this->doApiRequest(
			[ 'action' => 'flowthread', 'type' => 'list', 'pageid' => $pageId ], null, false, $performer
		)[0]['flowthread'];
	}

	public function testListIsEmptyOnIneligiblePages() {
		$page = $this->gtPage();
		$top = $this->gtPost( $this->gtUser(), $page, 'top' );
		$this->gtPost( $this->gtUser(), $page, 'reply', $top );
		$this->gtService( 'ReactionService' )->setLike( $this->gtUser(), $top->getId(), true );
		$anon = $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' );
		$before = $this->list( $page->getId(), $anon );
		$this->assertSame( 1, $before['count'] );
		$this->assertCount( 2, $before['posts'] );
		$this->assertCount( 1, $before['popular'] );

		$this->overrideConfigValue( 'GlowThreadNamespaces', [ NS_MAIN => false, NS_USER => true ] );
		foreach ( [ 'anonymous' => $anon, 'sysop' => $this->getTestSysop()->getUser() ] as $who => $reader ) {
			$this->assertSame(
				[ 'posts' => [], 'popular' => [], 'count' => 0 ], $this->list( $page->getId(), $reader ), $who
			);
		}
	}
}
