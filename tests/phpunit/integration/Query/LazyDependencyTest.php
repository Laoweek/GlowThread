<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Query;

use MediaWiki\Extension\GlowThread\Query\CommentQueryService;
use MediaWiki\Extension\GlowThread\Query\CommentSerializer;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWikiIntegrationTestCase;

/**
 * The read side makes the services that only some readers need when one of them reads: the
 * write checks for the list, the block store and the moderation log for the comments.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Query\CommentQueryService
 * @covers \MediaWiki\Extension\GlowThread\Query\CommentSerializer
 */
class LazyDependencyTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;

	/** @var array<string,int> Service => times made */
	private array $made = [];

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
	}

	private function provider( string $name, callable $make ): \Closure {
		$this->made[$name] = 0;
		return function () use ( $name, $make ) {
			$this->made[$name]++;
			return $make();
		};
	}

	private function newQueryService(): CommentQueryService {
		$services = $this->getServiceContainer();
		$serializer = new CommentSerializer(
			$this->gtService( 'TextProcessor' ),
			$this->gtService( 'MentionResolver' ),
			$this->gtService( 'ActorInfoLookup' ),
			$this->gtService( 'PostStore' ),
			$this->gtService( 'ReactionStore' ),
			$this->gtService( 'ReportStore' ),
			$this->provider( 'blocks', static fn () => $services->getDatabaseBlockStore() ),
			$this->provider( 'log', fn () => $this->gtService( 'ModerationLogger' ) )
		);
		return new CommentQueryService(
			$this->gtService( 'Config' ),
			$this->gtService( 'PostStore' ),
			$this->gtService( 'PageStateStore' ),
			$this->gtService( 'PageEligibility' ),
			$serializer,
			$this->gtService( 'HotRanker' ),
			$this->gtService( 'SettingsService' ),
			$this->gtService( 'ViewerFactory' ),
			$services->getTitleFormatter(),
			$this->provider( 'guard', fn () => $this->gtService( 'WriteGuard' ) ),
			$services->getReadOnlyMode(),
			$this->gtService( 'ListCache' )
		);
	}

	public function testOnlyTheReadersWhoNeedThemMakeThem() {
		$page = $this->gtPage( 'GlowThread lazy services' );
		$post = $this->gtPost( $this->gtUser(), $page, 'A comment' );
		$this->gtModerate( 'delete', [ $this->gtPost( $this->gtUser(), $page, 'Deleted' ) ] );
		$anon = $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' );

		$query = $this->newQueryService();
		$list = $query->getPageList( $query->newViewer( $anon ), $page, 'hot', null, 20 );
		$this->assertSame( [ $post->getId() ], array_column( $list['comments'], 'id' ) );
		$this->assertSame( [ 'blocks' => 0, 'log' => 0, 'guard' => 0 ], $this->made, 'anonymous' );

		$query = $this->newQueryService();
		$query->getPageList( $query->newViewer( $this->gtUser() ), $page, 'hot', null, 20 );
		$this->assertSame( [ 'blocks' => 0, 'log' => 0, 'guard' => 1 ], $this->made, 'logged in' );

		$query = $this->newQueryService();
		$list = $query->getPageList( $query->newViewer( $this->getTestSysop()->getUser() ), $page, 'new', null, 20 );
		$this->assertCount( 2, $list['comments'] );
		$this->assertSame( [ 'blocks' => 1, 'log' => 1, 'guard' => 1 ], $this->made, 'moderator' );
	}
}
