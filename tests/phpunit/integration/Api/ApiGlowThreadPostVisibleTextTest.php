<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Api;

/**
 * A comment in which nothing would show is refused like an empty one. Emoji keep the joiners,
 * variation selectors and tags they are made of, and tag characters that hide text are removed.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Api\ApiGlowThreadPost
 * @covers \MediaWiki\Extension\GlowThread\Text\TextProcessor
 */
class ApiGlowThreadPostVisibleTextTest extends GlowThreadApiTestCase {

	public function testNothingVisibleIsRefused() {
		$page = $this->gtPage();
		$user = $this->gtUser();
		// Hangul fillers, Braille blank, joiners, variation selector, CGJ, a tag, a lone combining mark
		$texts = [
			"\u{3164}", "\u{115F}", "\u{FFA0}", "\u{2800}", "\u{200D}", "\u{200C}", "\u{FE0F}", "\u{034F}",
			"\u{E0020}", "\u{0301}", " \u{3164}\n\u{200D}\u{2800} ",
		];
		foreach ( $texts as $text ) {
			$this->assertSame( 'emptycomment', $this->gtApiError(
				[ 'action' => 'glowthreadpost', 'pageid' => $page->getId(), 'text' => $text ], $user
			), json_encode( $text ) );
		}
	}

	public function testEmojiKeepTheirInvisibleParts() {
		$england = "🏴\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}";
		$text = "👨\u{200D}👩\u{200D}👧 ❤\u{FE0F} $england";
		// "hi" in tag characters after the emoji
		$hidden = "\u{E0068}\u{E0069}";
		$result = $this->gtApi(
			[ 'action' => 'glowthreadpost', 'pageid' => $this->gtPage()->getId(), 'text' => $text . $hidden ],
			$this->gtUser()
		)['glowthreadpost'];
		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( [ [ 'type' => 'text', 'text' => $text ] ], $result['comment']['segments'] );
	}
}
