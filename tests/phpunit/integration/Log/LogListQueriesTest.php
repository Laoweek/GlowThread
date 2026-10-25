<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Log;

use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Extension\GlowThread\Tests\Integration\QueryCounterTrait;
use MediaWiki\Permissions\Authority;
use MediaWiki\RenameUser\RenameuserSQL;
use MediaWiki\Request\FauxRequest;
use SpecialPageTestBase;

/**
 * Special:Log formats GlowThread's entries with a fixed number of queries, however many there
 * are: the authors and the erased-comment checks of all entries are read in one query each
 * (LogAuthorLookup::preload()), and the author's account is not looked up again by name for
 * its link (performance review PERF-5, 2026-10-09).
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Log\CommentsLogFormatter
 * @covers \MediaWiki\Extension\GlowThread\Log\LogAuthorLookup
 */
class LogListQueriesTest extends SpecialPageTestBase {
	use GlowThreadTestTrait;
	use QueryCounterTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
	}

	protected function newSpecialPage() {
		return $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'Log' );
	}

	private function reader(): Authority {
		return $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' );
	}

	public function testEntriesAreLookedUpTogether() {
		$author = $this->gtUser();
		$page = $this->gtPage( 'GlowThread log list page' );
		$posts = [];
		for ( $i = 0; $i < 6; $i++ ) {
			$posts[] = $this->gtPost( $author, $page, "comment $i" );
		}
		$this->gtModerate( 'delete', $posts );
		// Nothing remembered from the writes
		$this->resetServices();

		$html = '';
		$reader = $this->reader();
		$counts = $this->gtCountQueries( function () use ( &$html, $reader ) {
			[ $html ] = $this->executeSpecialPage( 'glowthread', new FauxRequest( [ 'limit' => 50 ] ), 'qqx', $reader );
		} );

		$this->assertSame(
			6, substr_count( $html, '<bdi>' . $author->getName() . '</bdi>' ), 'every entry names the author'
		);
		$all = json_encode( $counts );
		$this->assertSame( 1, $this->gtQueriesBy( $counts, 'LogAuthorLookup::getEntryAuthor' ), $all );
		// Readers without glowthread-erase: whether each comment was erased since
		$this->assertSame( 1, $this->gtQueriesBy( $counts, 'PostStore::getByIds' ), $all );
		// At most once for the author's hideuser check, never once per entry
		$this->assertLessThanOrEqual( 1, $this->gtQueriesBy( $counts, 'User::load' ), $all );
	}

	/**
	 * Read together, every entry still shows its own author: a hideuser-hidden author and the
	 * author of a comment erased since are hidden from readers, and a renamed author shows under
	 * the current name.
	 */
	public function testBatchedEntriesKeepTheirOwnAuthors() {
		$page = $this->gtPage( 'GlowThread log mapping page' );
		$authors = [];
		$posts = [];
		foreach ( [ 'plain', 'hidden', 'erased', 'renamed' ] as $role ) {
			$authors[$role] = $this->gtUser();
			$posts[$role] = $this->gtPost( $authors[$role], $page, "comment by the $role author" );
		}
		$this->gtModerate( 'delete', array_values( $posts ) );
		$this->gtModerate( 'erase', [ $posts['erased'] ] );
		$this->assertStatusGood( $this->getServiceContainer()->getBlockUserFactory()->newBlockUser(
			$authors['hidden'],
			$this->getTestUser( [ 'sysop', 'suppress' ] )->getAuthority(),
			'infinity',
			'',
			[ 'isHideUser' => true, 'isAutoblocking' => false ]
		)->placeBlock() );
		$oldName = $authors['renamed']->getName();
		$newName = 'Renamed log author ' . mt_rand( 100000, 999999 );
		$rename = new RenameuserSQL(
			$oldName, $newName, $authors['renamed']->getId(), $this->getTestSysop()->getUser(), [ 'reason' => 'test' ]
		);
		// renameUser() replaced rename() in 1.44
		$this->assertTrue( method_exists( $rename, 'renameUser' ) ? $rename->renameUser()->isOK() : $rename->rename() );
		$this->resetServices();

		[ $html ] = $this->executeSpecialPage(
			'glowthread', new FauxRequest( [ 'limit' => 50 ] ), 'qqx', $this->reader()
		);
		$line = static function ( int $commentId ) use ( $html ): string {
			foreach ( explode( "\n", $html ) as $line ) {
				if ( str_contains( $line, 'glowthread/delete' )
					&& str_contains( $line, "GlowThreadLink/$commentId\"" )
				) {
					return $line;
				}
			}
			return '';
		};
		$hiddenText = '(glowthread-log-author-hidden)';
		$this->assertStringContainsString(
			'<bdi>' . $authors['plain']->getName() . '</bdi>', $line( $posts['plain']->getId() )
		);
		foreach ( [ 'hidden', 'erased' ] as $role ) {
			$text = $line( $posts[$role]->getId() );
			$this->assertStringContainsString( $hiddenText, $text, $role );
			$this->assertStringNotContainsString( $authors[$role]->getName(), $text, $role );
		}
		$text = $line( $posts['renamed']->getId() );
		$this->assertStringContainsString( '<bdi>' . $newName . '</bdi>', $text );
		$this->assertStringNotContainsString( $oldName, $text );
	}

	/**
	 * Long lists are read BATCH_SIZE (500) entries at a time, also when the pager formats them in
	 * reverse (dir=prev).
	 */
	public function testLongListsAreReadInBatches() {
		$ids = range( 900001, 901200 );
		foreach ( [ 'forward' => $ids, 'reverse' => array_reverse( $ids ) ] as $order => $formatted ) {
			$this->resetServices();
			$lookup = $this->gtService( 'LogAuthorLookup' );
			foreach ( $ids as $id ) {
				$lookup->preload( $id, $id );
			}
			$counts = $this->gtCountQueries( static function () use ( $lookup, $formatted ) {
				foreach ( $formatted as $id ) {
					$lookup->getEntryAuthor( $id );
					$lookup->isErasedComment( $id );
				}
			} );
			$this->assertSame( 3, $this->gtQueriesBy( $counts, 'LogAuthorLookup::getEntryAuthor' ), $order );
			$this->assertSame( 3, $this->gtQueriesBy( $counts, 'PostStore::getByIds' ), $order );
		}
	}
}
