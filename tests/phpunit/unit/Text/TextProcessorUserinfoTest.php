<?php

namespace MediaWiki\Extension\GlowThread\Tests\Unit\Text;

use MediaWiki\Extension\GlowThread\Text\TextProcessor;
use MediaWikiUnitTestCase;

/**
 * URLs with a user@ part before the host are not linked: they read like one site and go to
 * another. They stay plain text, and SpamBlacklist still checks the host a browser would open.
 *
 * @covers \MediaWiki\Extension\GlowThread\Text\TextProcessor
 */
class TextProcessorUserinfoTest extends MediaWikiUnitTestCase {

	private function newProcessor(): TextProcessor {
		return new TextProcessor();
	}

	public static function provideUserinfo() {
		yield 'user@' => [ 'https://good.example@evil.example/path' ];
		yield 'user:pass@' => [ 'https://user:secret@evil.example/' ];
		yield 'empty password' => [ 'http://good.example:@evil.example' ];
		yield 'empty user' => [ 'https://@evil.example/' ];
		yield 'upper-case scheme' => [ 'HTTPS://GOOD.EXAMPLE@EVIL.EXAMPLE/' ];
		// A browser opens evil.example: the backslash ends the host like a slash
		yield 'backslash before @' => [ 'https://evil.example\\@good.example/', 'https://evil.example/@good.example/' ];
		yield 'backslash as path' => [ 'https://evil.example\\good.example', 'https://evil.example/good.example' ];
		yield 'comma before @' => [ 'https://good.example,@evil.example/' ];
	}

	/**
	 * @dataProvider provideUserinfo
	 */
	public function testUserinfoUrlIsText( string $url, ?string $checked = null ) {
		$tp = $this->newProcessor();
		$text = "see $url now";
		$this->assertSame( [ [ 'type' => 'text', 'text' => $text ] ], $tp->toSegments( $text, [] ) );
		$this->assertSame( [ $checked ?? $url ], $tp->extractUrls( $text ), 'still checked against SpamBlacklist' );
	}

	public static function provideAtAfterHost() {
		yield 'in the path' => [ 'https://medium.example/@alice/post' ];
		yield 'in the query' => [ 'https://a.example/p?mail=a@b.example' ];
		yield 'in the fragment' => [ 'https://a.example/#@x' ];
		yield 'right after the host' => [ 'https://a.example?@b.example' ];
	}

	/**
	 * @dataProvider provideAtAfterHost
	 */
	public function testAtAfterTheHostIsLinked( string $url ) {
		$this->assertSame( [ $url ], $this->newProcessor()->extractUrls( "see $url now" ) );
	}

	/**
	 * A mention token right before or after a URL leaves the URL as it is: the URL ends at <, and
	 * the token is a mention either way
	 */
	public function testMentionNextToUrl() {
		$tp = $this->newProcessor();
		$users = [ 1 => 'Alice' ];
		$alice = [ 'type' => 'mention', 'text' => '@Alice', 'user' => 'Alice', 'id' => 1, 'exists' => true ];
		$text = '<@1>https://good.example@evil.example/';
		$this->assertSame(
			[ $alice, [ 'type' => 'text', 'text' => 'https://good.example@evil.example/' ] ],
			$tp->toSegments( $text, $users )
		);
		$this->assertSame( [ 'https://good.example@evil.example/' ], $tp->extractUrls( $text ) );
		$this->assertSame( [
			[ 'type' => 'link', 'text' => 'https://example.com/?q=', 'href' => 'https://example.com/?q=' ],
			$alice,
		], $tp->toSegments( 'https://example.com/?q=<@1>', $users ) );
	}

	public function testOtherLinksInTheTextStayLinks() {
		$tp = $this->newProcessor();
		$text = 'https://good.example@evil.example/ and https://ok.example/';
		$this->assertSame( [
			[ 'type' => 'text', 'text' => 'https://good.example@evil.example/ and ' ],
			[ 'type' => 'link', 'text' => 'https://ok.example/', 'href' => 'https://ok.example/' ],
		], $tp->toSegments( $text, [] ) );
		// Links first, then the URLs left as text
		$this->assertSame(
			[ 'https://ok.example/', 'https://good.example@evil.example/' ],
			$tp->extractUrls( $text )
		);
	}
}
