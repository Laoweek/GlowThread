<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Echo;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\GlowThread\Echo\EchoSetupHooks;
use MediaWiki\Extension\GlowThread\Store\PostStore;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Tests\Api\ApiTestCase;

/**
 * Rendering a reply notification loads the reply and the comment it answers in one query,
 * since the reply is usually shown under that comment.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Echo\GlowThreadPresentationModel
 * @covers \MediaWiki\Extension\GlowThread\Echo\CommentPresentationModel
 */
class NotificationRenderQueryCountTest extends ApiTestCase {
	use GlowThreadTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'Echo' );
		$this->setUpGlowThread();
		$this->overrideConfigValue( 'EchoUseJobQueue', false );
	}

	public function testReplyNotificationLoadsReplyAndTargetTogether() {
		$page = $this->gtPage();
		$alice = $this->getMutableTestUser()->getUser();
		$bob = $this->getMutableTestUser()->getUser();
		foreach ( [ 'First', 'Second' ] as $text ) {
			$this->gtPost( $bob, $page, "Re: $text", $this->gtPost( $alice, $page, $text ) );
		}
		DeferredUpdates::doUpdates();

		$store = new class( $this->getServiceContainer()->getConnectionProvider() ) extends PostStore {
			public int $batches = 0;

			public function getByIds( array $ids, bool $primary = false ): array {
				$this->batches++;
				return parent::getByIds( $ids, $primary );
			}
		};
		$this->setService( 'GlowThread.PostStore', $store );
		$list = $this->doApiRequest( [
			'action' => 'query',
			'meta' => 'notifications',
			'notformat' => 'model',
			'notprop' => 'list',
			'notlimit' => 50,
			'uselang' => 'en',
		], null, false, $alice )[0]['query']['notifications']['list'];

		$list = array_values( array_filter( $list, static fn ( $n ) => $n['type'] === EchoSetupHooks::TYPE_REPLY ) );
		$this->assertCount( 2, $list );
		foreach ( $list as $item ) {
			// Rendered as visible comments: a permalink to the reply
			$this->assertStringContainsString( 'GlowThreadLink', $item['*']['links']['primary']['url'] );
		}
		$this->assertSame( 2, $store->batches, 'one query per notification' );
	}
}
