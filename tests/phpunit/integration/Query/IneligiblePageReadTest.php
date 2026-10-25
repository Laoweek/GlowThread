<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Query;

use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWikiIntegrationTestCase;

/**
 * Comments of a page where comments no longer appear (its namespace left
 * $wgGlowThreadNamespaces, or it moved out of them) used to stay readable through every reader
 * route although the article no longer showed them. Readers now get what a disabled page gives:
 * an empty list and no single comments. Moderators still see them, to clean up.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Query\CommentQueryService
 */
class IneligiblePageReadTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
	}

	public function testReaderRoutesHideCommentsOfIneligiblePages() {
		$page = $this->gtPage();
		$author = $this->gtUser();
		$top = $this->gtPost( $author, $page, 'top' );
		$reply = $this->gtPost( $this->gtUser(), $page, 'reply', $top );
		$query = $this->gtService( 'CommentQueryService' );
		$anon = $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' );
		$this->assertCount( 1, $query->getPageList( $query->newViewer( $anon ), $page, 'new', null, 20 )['comments'] );

		$this->overrideConfigValue( 'GlowThreadNamespaces', [ NS_MAIN => false, NS_USER => true ] );
		$query = $this->gtService( 'CommentQueryService' );
		foreach ( [ 'anonymous' => $anon, 'author' => $author ] as $who => $reader ) {
			$this->assertSame( [], $this->readAll( $reader, $page, $top->getId(), $reply->getId() ), $who );
			$list = $query->getPageList( $query->newViewer( $reader ), $page, 'hot', null, 20 );
			$this->assertSame( 0, $list['count'], $who );
			$this->assertSame( 'ineligible', $list['viewer']['postBlockedReason'], $who );
		}

		$moderator = $this->getTestSysop()->getUser();
		$this->assertSame(
			[ 'list', 'comment', 'floor', 'thread', 'replies', 'context' ],
			$this->readAll( $moderator, $page, $top->getId(), $reply->getId() )
		);
	}

	/**
	 * @return string[] The reader routes that returned the comments
	 */
	private function readAll( Authority $reader, ProperPageIdentity $page, int $topId, int $replyId ): array {
		$query = $this->gtService( 'CommentQueryService' );
		$viewer = $query->newViewer( $reader );
		$served = [];
		if ( $query->getPageList( $viewer, $page, 'new', null, 20 )['comments'] ) {
			$served[] = 'list';
		}
		if ( $query->getComment( $viewer, $replyId ) ) {
			$served[] = 'comment';
		}
		if ( $query->getCommentByFloor( $viewer, $page->getId(), 1 ) ) {
			$served[] = 'floor';
		}
		if ( $query->getThread( $viewer, $replyId ) ) {
			$served[] = 'thread';
		}
		if ( $query->getReplies( $viewer, $topId, '', 10 ) ) {
			$served[] = 'replies';
		}
		// Special:GlowThreadLink, the permalink, goes through getVisibleContext()
		$post = $this->gtService( 'PostStore' )->getById( $replyId );
		if ( $query->getVisibleContext( $viewer, $post ) ) {
			$served[] = 'context';
		}
		return $served;
	}
}
