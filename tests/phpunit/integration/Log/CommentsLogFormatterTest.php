<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Log;

use ManualLogEntry;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Log\CommentsLogFormatter
 */
class CommentsLogFormatterTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
		// FlowThread's entries render where GlowThread registers their type
		$this->gtRegisterLegacyLogType();
	}

	private function format( ManualLogEntry $entry, $viewer = null ): string {
		return $this->newFormatter( $entry, $viewer )->getPlainActionText();
	}

	private function newFormatter( ManualLogEntry $entry, $viewer = null ) {
		$formatter = $this->getServiceContainer()->getLogFormatterFactory()->newFromEntry( $entry );
		$context = new RequestContext();
		$context->setLanguage( 'en' );
		$context->setUser( $viewer ?? $this->getTestSysop()->getUser() );
		$formatter->setContext( $context );
		return $formatter;
	}

	private function anon() {
		return $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' );
	}

	private function legacyEntry( string $action, array $params ): ManualLogEntry {
		$entry = new ManualLogEntry( 'comments', $action );
		$entry->setPerformer( $this->getTestSysop()->getUser() );
		$entry->setTarget( Title::newFromText( 'Some page' ) );
		$entry->setParameters( $params );
		return $entry;
	}

	/**
	 * FlowThread's entries name the author in
	 * 4::username, which core never hides. A hideuser-hidden author is hidden there too, in the
	 * text and in the API.
	 */
	public function testLegacyEntriesHideHiddenUsers() {
		$hidden = $this->gtUser();
		$this->assertStatusGood( $this->getServiceContainer()->getBlockUserFactory()->newBlockUser(
			$hidden,
			$this->getTestUser( [ 'sysop', 'suppress' ] )->getAuthority(),
			'infinity',
			'',
			[ 'isHideUser' => true, 'isAutoblocking' => false ]
		)->placeBlock() );
		foreach ( [ 'delete', 'recover', 'markchecked' ] as $action ) {
			$entry = $this->legacyEntry( $action, [ '4::username' => $hidden->getName() ] );
			$text = $this->format( $entry, $this->anon() );
			$this->assertStringNotContainsString( $hidden->getName(), $text, $action );
			$this->assertStringContainsString( '(hidden user)', $text, $action );
			$api = $this->newFormatter( $entry, $this->anon() )->formatParametersForApi();
			$this->assertStringNotContainsString( $hidden->getName(), json_encode( $api ), $action );
			// Holders of hideuser still see the name
			$this->assertStringContainsString( $hidden->getName(),
				$this->format( $entry, $this->getTestUser( [ 'sysop', 'suppress' ] )->getUser() ), $action );
		}
		// Other names stay
		$this->assertStringContainsString( 'Old user',
			$this->format( $this->legacyEntry( 'delete', [ '4::username' => 'Old user' ] ), $this->anon() ) );
	}

	/**
	 * A FlowThread erase entry names the author of a comment that is gone for good (its
	 * 4::postid holds the name). Like GlowThread's own erase entries, it names nobody to readers
	 * without glowthread-erase.
	 */
	public function testLegacyEraseHidesTheAuthor() {
		$entry = $this->legacyEntry( 'erase', [ '4::postid' => 'Old user', '5::children' => 2 ] );
		$text = $this->format( $entry, $this->anon() );
		$this->assertStringNotContainsString( 'Old user', $text );
		$this->assertStringContainsString( 'along with 2 replies', $text );
		$this->assertStringNotContainsString( 'Old user',
			json_encode( $this->newFormatter( $entry, $this->anon() )->formatParametersForApi() ) );
		$this->assertStringContainsString( 'Old user', $this->format( $entry ) );
	}

	public static function provideLegacyEntries() {
		yield 'delete' => [ 'delete', [ '4::username' => 'Old user' ], 'deleted a comment by Old user on' ];
		yield 'recover' => [ 'recover', [ '4::username' => 'Old user' ], 'restored a comment by Old user on' ];
		yield 'erase' => [ 'erase', [ '4::postid' => 'Old user', '5::children' => 2 ], 'along with 2 replies' ];
		yield 'import' => [ 'import', [ '4::count' => 12 ], 'imported 12 comments to' ];
		yield 'markchecked' => [ 'markchecked', [ '4::username' => 'Old user' ], 'as checked' ];
		yield 'disable' => [ 'disable', [], 'turned off comments on' ];
		yield 'enable' => [ 'enable', [], 'opened comments on' ];
	}

	/**
	 * Entries written by FlowThread keep rendering.
	 *
	 * @dataProvider provideLegacyEntries
	 */
	public function testLegacyEntries( string $action, array $params, string $expected ) {
		$entry = new ManualLogEntry( 'comments', $action );
		$entry->setPerformer( $this->getTestSysop()->getUser() );
		$entry->setTarget( Title::newFromText( 'Some page' ) );
		$entry->setParameters( $params );
		$this->assertStringContainsString( $expected, $this->format( $entry ) );
	}

	public function testNewEntry() {
		$entry = new ManualLogEntry( 'glowthread', 'delete' );
		$entry->setPerformer( $this->getTestSysop()->getUser() );
		$entry->setTarget( Title::newFromText( 'Some page' ) );
		$entry->setParameters( [ '4::comment' => 77, '5:user-link:author' => 'Alice', '6::seq' => 12 ] );
		$text = $this->format( $entry );
		$this->assertStringContainsString( 'deleted comment #12 by Alice on [[Some page]]', $text );
		// The comment number links to its permalink
		$html = $this->newFormatter( $entry )->getActionText();
		$this->assertStringContainsString( 'Special:GlowThreadLink/77', $html );
	}

	public function testImportEntry() {
		$entry = new ManualLogEntry( 'glowthread', 'import' );
		$entry->setPerformer( $this->getTestSysop()->getUser() );
		$entry->setTarget( Title::newFromText( 'Special:GlowThread' ) );
		$entry->setParameters( [ '4::count' => 12, '5::options' => '{}' ] );
		$this->assertStringContainsString( 'imported 12 comments from FlowThread', $this->format( $entry ) );
	}

	public function testSettingsEntry() {
		$entry = new ManualLogEntry( 'glowthread', 'settings' );
		$entry->setPerformer( $this->getTestSysop()->getUser() );
		$entry->setTarget( Title::newFromText( 'Special:GlowThread/settings' ) );
		$entry->setParameters( [ '4::changes' => [ [ 'maxLength', 1000, 500 ], [ 'publicLog', true, false ] ] ] );
		$text = $this->format( $entry );
		$this->assertStringContainsString( 'maxLength: 1000 → 500', $text );
		$this->assertStringContainsString( 'publicLog: true → false', $text );
	}

	public function testHiddenAuthorIsNotShown() {
		$author = $this->gtUser();
		$this->assertStatusGood( $this->getServiceContainer()->getBlockUserFactory()->newBlockUser(
			$author,
			$this->getTestUser( [ 'sysop', 'suppress' ] )->getAuthority(),
			'infinity',
			'',
			[ 'isHideUser' => true, 'isAutoblocking' => false ]
		)->placeBlock() );
		$entry = new ManualLogEntry( 'glowthread', 'delete' );
		$entry->setPerformer( $this->getTestSysop()->getUser() );
		$entry->setTarget( Title::newFromText( 'Some page' ) );
		$entry->setParameters( [ '4::comment' => 77, '5:user-link:author' => $author->getName(), '6::seq' => 12 ] );

		$text = $this->format( $entry );
		$this->assertStringNotContainsString( $author->getName(), $text );
		$this->assertStringContainsString( '(hidden user)', $text );

		$formatter = $this->getServiceContainer()->getLogFormatterFactory()->newFromEntry( $entry );
		$context = new RequestContext();
		$context->setUser( $this->getTestUser( [ 'sysop', 'suppress' ] )->getUser() );
		$context->setLanguage( 'en' );
		$formatter->setContext( $context );
		$this->assertStringContainsString( $author->getName(), $formatter->getPlainActionText() );
	}

	public function testLegacyAnonymousAuthorShowsTheAddress() {
		$entry = new ManualLogEntry( 'glowthread', 'delete' );
		$entry->setPerformer( $this->getTestSysop()->getUser() );
		$entry->setTarget( Title::newFromText( 'Some page' ) );
		$entry->setParameters( [ '4::comment' => 5, '5:user-link:author' => '203.0.113.7', '6::seq' => 3 ] );
		$this->assertStringContainsString( 'comment #3 by 203.0.113.7 on', $this->format( $entry ) );
	}
}
