<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Query;

use MediaWiki\Extension\GlowThread\Query\CommentQueryService;
use MediaWiki\Extension\GlowThread\Store\PostStore;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Permissions\Authority;
use MediaWikiIntegrationTestCase;

/**
 * The thread route shows a conversation only to viewers who may see both the opened comment and
 * its top-level comment, with the top-level comment's page.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Query\CommentQueryService
 */
class ThreadContextTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
	}

	private function query(): CommentQueryService {
		return $this->gtService( 'CommentQueryService' );
	}

	private function thread( Authority $authority, int $id ): ?array {
		return $this->query()->getThread( $this->query()->newViewer( $authority ), $id );
	}

	private function anon(): Authority {
		return $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' );
	}

	public function testHeldTopLevelCommentShowsOnlyToItsAuthorAndModerators() {
		$this->gtSetFilter( 'holdme' );
		$page = $this->gtPage();
		$author = $this->gtUser();
		$root = $this->gtPost( $author, $page, 'holdme, a held question' );
		$this->assertTrue( $root->isHeld() );
		$reply = $this->gtPost( $author, $page, 'and my own follow-up', $root );

		foreach ( [ $reply, $root ] as $post ) {
			$thread = $this->thread( $author, $post->getId() );
			$this->assertSame( $root->getId(), $thread['thread']['id'] );
			$this->assertSame( [ $reply->getId() ], array_column( $thread['thread']['replies'], 'id' ) );
			$this->assertNotNull( $this->thread( $this->getTestSysop()->getUser(), $post->getId() ) );
			$this->assertNull( $this->thread( $this->anon(), $post->getId() ) );
			$this->assertNull( $this->thread( $this->gtUser(), $post->getId() ) );
		}
	}

	public function testDeletedTopLevelCommentHidesTheThreadFromReaders() {
		$page = $this->gtPage();
		$root = $this->gtPost( $this->gtUser(), $page, 'top' );
		$reply = $this->gtPost( $this->gtUser(), $page, 'reply', $root );
		$this->gtModerate( 'delete', [ $root ] );

		$this->assertNull( $this->thread( $this->anon(), $reply->getId() ) );
		$thread = $this->thread( $this->getTestSysop()->getUser(), $reply->getId() );
		$this->assertSame( 'deleted', $thread['thread']['status'] );
		$this->assertSame( $reply->getId(), $thread['thread']['replies'][0]['id'] );
	}

	/**
	 * GlowThread keeps a thread on one page. Should the top-level comment be elsewhere anyway,
	 * its page decides.
	 */
	public function testTopLevelCommentOnAnotherPageDecides() {
		$page = $this->gtPage();
		$other = $this->gtPage( 'GlowThread other page' );
		$root = $this->gtPost( $this->gtUser(), $page, 'top' );
		$reply = $this->gtPost( $this->gtUser(), $page, 'reply', $root );
		$this->getDb()->newUpdateQueryBuilder()
			->update( PostStore::TABLE )
			->set( [ 'gtp_page' => $other->getId() ] )
			->where( [ 'gtp_id' => $root->getId() ] )
			->caller( __METHOD__ )
			->execute();

		$this->assertSame( $other->getId(), $this->thread( $this->anon(), $reply->getId() )['page']['id'] );

		$this->gtSetPageState( $other, 'disabled' );
		$this->assertNull( $this->thread( $this->anon(), $reply->getId() ) );
		$this->assertSame(
			$other->getId(),
			$this->thread( $this->getTestSysop()->getUser(), $reply->getId() )['page']['id']
		);
	}
}
