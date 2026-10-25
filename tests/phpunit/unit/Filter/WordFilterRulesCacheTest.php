<?php

namespace MediaWiki\Extension\GlowThread\Tests\Unit\Filter;

use MediaWiki\Content\TextContent;
use MediaWiki\Extension\GlowThread\Filter\WordFilter;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\PageLookup;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWikiUnitTestCase;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * The rules page's current revision is looked up for every request, as before, and its text
 * is cached by revision ID, so posting a comment does not load the revision's content every
 * time and an edit of the page is seen at once.
 *
 * @covers \MediaWiki\Extension\GlowThread\Filter\WordFilter
 */
class WordFilterRulesCacheTest extends MediaWikiUnitTestCase {

	private function newFilter( RevisionRecord $current, WANObjectCache $cache ): WordFilter {
		$pages = $this->createMock( PageLookup::class );
		$pages->method( 'getPageByName' )->willReturn( $this->createMock( ExistingPageRecord::class ) );
		$lookup = $this->createMock( RevisionLookup::class );
		$lookup->method( 'getRevisionByTitle' )->willReturn( $current );
		return new WordFilter( $pages, $lookup, $cache );
	}

	/**
	 * A revision whose content may be loaded at most $loads times
	 */
	private function newRevision( int $id, string $text, int $loads ): RevisionRecord {
		$revision = $this->createMock( RevisionRecord::class );
		$revision->method( 'getId' )->willReturn( $id );
		$revision->expects( $this->atMost( $loads ) )->method( 'getContent' )->willReturn( new TextContent( $text ) );
		return $revision;
	}

	public function testRulesTextIsCachedByRevision() {
		$cache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$this->assertTrue( $this->newFilter( $this->newRevision( 5, 'spam', 1 ), $cache )
			->check( 'some spam here' )['matched'] );

		// Another request: the content of revision 5 is not loaded again
		$this->assertSame( 'spam', $this->newFilter( $this->newRevision( 5, 'spam', 0 ), $cache )->getRulesText() );
	}

	public function testEditedRulesAreReadAtOnce() {
		$cache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$this->newFilter( $this->newRevision( 5, 'spam', 1 ), $cache )->getRulesText();

		$filter = $this->newFilter( $this->newRevision( 6, 'eggs', 1 ), $cache );
		$this->assertSame( 'eggs', $filter->getRulesText() );
		$this->assertFalse( $filter->check( 'some spam here' )['matched'] );
	}
}
