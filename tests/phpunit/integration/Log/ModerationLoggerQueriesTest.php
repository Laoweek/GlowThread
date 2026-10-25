<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Log;

use LogEntryBase;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\GlowThread\Log\LogAuthorLookup;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Extension\GlowThread\Tests\Integration\QueryCounterTrait;
use MediaWiki\MediaWikiServices;
use MediaWikiIntegrationTestCase;
use Wikimedia\ScopedCallback;

/**
 * A moderation action on many comments logs and publishes its entries without a query per
 * entry: the log target (the page) is looked up once per page, and publishing formats the
 * entries without reading each comment or looking up each author by name again
 * (ModerationLogger tells LogAuthorLookup about the comments it logs). What the published
 * entries say stays the same.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Log\ModerationLogger
 * @covers \MediaWiki\Extension\GlowThread\Log\LogAuthorLookup
 * @covers \MediaWiki\Extension\GlowThread\Log\CommentsLogFormatter
 */
class ModerationLoggerQueriesTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;
	use QueryCounterTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
	}

	public function testBulkActionLooksUpThePageAndCommentsOnce() {
		$page = $this->gtPage( 'GlowThread bulk log page' );
		$authors = [ $this->gtUser(), $this->gtUser(), $this->gtUser() ];
		$posts = [];
		for ( $i = 0; $i < 30; $i++ ) {
			$posts[] = $this->gtPost( $authors[$i % 3], $page, "comment $i" );
		}
		// Nothing remembered from posting
		$this->resetServices();

		$counts = $this->gtCountQueries( function () use ( $posts ) {
			$this->gtModerate( 'delete', $posts );
			// Publishing the entries (RecentChanges) is a deferred update
			DeferredUpdates::doUpdates();
		} );

		$all = json_encode( $counts );
		$this->assertCount( 30, $this->gtLogRows( 'glowthread', 'delete' ) );
		// The log target, once for the page instead of once per entry
		$this->assertSame( 1, $this->gtQueriesBy( $counts, 'Title::newFromID' ), $all );
		// Publishing checks whether each comment was erased: the logger already had them
		$this->assertSame( 0, $this->gtQueriesBy( $counts, 'PostStore::getByIds' ), $all );
		// Nor is each of the 3 authors looked up by name for the hideuser check: they were read by
		// actor ID. (MediaWiki 1.46 loads one other user by name.)
		$this->assertLessThanOrEqual( 1, $this->gtQueriesBy( $counts, 'User::load' ), $all );
	}

	/**
	 * What the published entries say (RecentChanges and the feeds, as anonymous readers see them)
	 * is the same as when LogAuthorLookup was not told about the logged comments: an author hidden
	 * by a hideuser block stays hidden, other authors are named, and the author of a comment
	 * erased in the same request is hidden wherever MediaWiki formats the entry after the erase
	 * (1.43 to 1.45 format at publication, after the response; 1.46 when the entry is written).
	 */
	public function testPublishedTextsAreUnchanged() {
		$told = $this->publish( 'told' );
		// Built from the services after the reset that setService() does
		$this->setService(
			'GlowThread.LogAuthorLookup',
			static fn ( MediaWikiServices $services ) => new class(
				$services->getUserFactory(), $services->get( 'GlowThread.PostStore' )
			) extends LogAuthorLookup {
				/** @inheritDoc */
				public function rememberComment( PostRecord $post, ?string $authorName = null ): void {
				}
			}
		);
		$this->assertSame( $this->publish( 'not told' ), $told );
		$this->assertSame( [ 'named' => true, 'hidden' => 0 ], $told['plain'] );
		$this->assertSame( [ 'named' => false, 'hidden' => 2 ], $told['hidden'] );
	}

	/**
	 * Delete three comments, then erase one, in one request, and publish the entries.
	 *
	 * @param string $run
	 * @return array<string,array{named:bool,hidden:int}> Author role => whether the texts published
	 *   for the delete entry name the author, and how often they say "(hidden user)"
	 */
	private function publish( string $run ): array {
		$page = $this->gtPage( "GlowThread published log page ($run)" );
		$authors = [];
		$posts = [];
		foreach ( [ 'plain', 'hidden', 'erased' ] as $role ) {
			$authors[$role] = $this->gtUser();
			$posts[$role] = $this->gtPost( $authors[$role], $page, "comment by the $role author" );
		}
		$this->assertStatusGood( $this->getServiceContainer()->getBlockUserFactory()->newBlockUser(
			$authors['hidden'],
			$this->getTestUser( [ 'sysop', 'suppress' ] )->getAuthority(),
			'infinity',
			'',
			[ 'isHideUser' => true, 'isAutoblocking' => false ]
		)->placeBlock() );
		$texts = [];
		$this->setTemporaryHook( 'RecentChange_save', static function ( $rc ) use ( &$texts ) {
			if ( $rc->getAttribute( 'rc_log_type' ) === 'glowthread'
				&& $rc->getAttribute( 'rc_log_action' ) === 'delete'
			) {
				$params = LogEntryBase::extractParams( $rc->getAttribute( 'rc_params' ) );
				$texts[$params['4::comment']] = $rc->mExtra['actionComment'] . "\n" . $rc->mExtra['actionCommentIRC'];
			}
		} );
		$noUpdates = DeferredUpdates::preventOpportunisticUpdates();
		$this->gtModerate( 'delete', array_values( $posts ) );
		$this->gtModerate( 'erase', [ $posts['erased'] ] );
		ScopedCallback::consume( $noUpdates );
		DeferredUpdates::doUpdates();

		$this->assertCount( 3, $texts, $run );
		$out = [];
		foreach ( $posts as $role => $post ) {
			$text = $texts[$post->getId()];
			$out[$role] = [
				'named' => str_contains( $text, $authors[$role]->getName() ),
				'hidden' => substr_count( $text, '(hidden user)' ),
			];
		}
		return $out;
	}
}
