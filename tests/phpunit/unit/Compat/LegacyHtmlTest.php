<?php

namespace MediaWiki\Extension\GlowThread\Tests\Unit\Compat;

use MediaWiki\Extension\GlowThread\Compat\LegacyHtml;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\GlowThread\Compat\LegacyHtml
 */
class LegacyHtmlTest extends MediaWikiUnitTestCase {

	private function render( array $segments ): string {
		return LegacyHtml::fromSegments(
			$segments,
			static fn ( string $name ) => '/wiki/User:' . rawurlencode( $name )
		);
	}

	public function testTextIsEscapedAndKeepsLineBreaks() {
		$this->assertSame(
			'&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &#039;q&#039;<br>' . "\n" . 'next',
			$this->render( [ [ 'type' => 'text', 'text' => "<script>alert(\"x\")</script> & 'q'\nnext" ] ] )
		);
	}

	public function testLink() {
		$html = $this->render( [ [
			'type' => 'link',
			'text' => 'https://example.com/?a=1&b="2"',
			'href' => 'https://example.com/?a=1&b="2"',
		] ] );
		$this->assertSame(
			'<a href="https://example.com/?a=1&amp;b=&quot;2&quot;" class="external" '
				. 'rel="nofollow ugc noopener" target="_blank">https://example.com/?a=1&amp;b="2"</a>',
			$html
		);
	}

	public function testNonWebLinkBecomesText() {
		$this->assertSame(
			'javascript:alert(1)',
			$this->render( [ [ 'type' => 'link', 'text' => 'javascript:alert(1)', 'href' => 'javascript:alert(1)' ] ] )
		);
	}

	public function testMentions() {
		$this->assertSame(
			'<a href="/wiki/User:A%3Cb%3E" title="A&lt;b&gt;">@A&lt;b></a> @Ghost',
			$this->render( [
				[ 'type' => 'mention', 'text' => '@A<b>', 'user' => 'A<b>', 'exists' => true ],
				[ 'type' => 'text', 'text' => ' ' ],
				[ 'type' => 'mention', 'text' => '@Ghost', 'user' => 'Ghost', 'exists' => false ],
			] )
		);
	}

	public function testEmpty() {
		$this->assertSame( '', $this->render( [] ) );
	}
}
