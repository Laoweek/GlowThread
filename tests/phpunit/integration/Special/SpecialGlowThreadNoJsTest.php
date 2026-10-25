<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Special;

use MediaWiki\Extension\GlowThread\Special\SpecialGlowThread;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Request\FauxRequest;
use SpecialPageTestBase;

/**
 * The hub's notice for readers without JavaScript is wikitext: it links to the comment log.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Special\SpecialGlowThread
 */
class SpecialGlowThreadNoJsTest extends SpecialPageTestBase {
	use GlowThreadTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
	}

	protected function newSpecialPage() {
		return new SpecialGlowThread( $this->gtService( 'Config' ) );
	}

	private function notice(): string {
		[ $html ] = $this->executeSpecialPage( 'queue', new FauxRequest(), 'en', $this->getTestSysop()->getUser() );
		$this->assertSame( 1, preg_match( '#<p class="ext-glowthread-admin-nojs">(.*?)</p>#s', $html, $m ), $html );
		return $m[1];
	}

	public function testNoticeLinksToTheCommentLog() {
		$notice = $this->notice();
		$this->assertStringNotContainsString( '[[', $notice );
		$this->assertMatchesRegularExpression(
			'#<a href="[^"]*Special:Log/glowthread"[^>]*>the comment log</a>#', $notice
		);
	}

	/**
	 * The message is sysop-editable wikitext, so it goes through the parser and its sanitizer.
	 */
	public function testEditedNoticeCannotAddMarkup() {
		$this->overrideConfigValue( 'UseDatabaseMessages', true );
		$this->setTemporaryHook( 'MessagesPreLoad', static function ( $title, &$message ) {
			if ( $title === 'Glowthread-special-nojs' ) {
				$message = '<script>alert(1)</script><img src=x onerror=alert(1)> [javascript:alert(1) x]';
			}
		} );
		$notice = $this->notice();
		$this->assertStringNotContainsString( '<script', $notice );
		$this->assertStringNotContainsString( '<img', $notice );
		$this->assertStringNotContainsString( 'href="javascript:', $notice );
		$this->assertStringContainsString( '&lt;script', $notice, 'shown as text' );
	}
}
