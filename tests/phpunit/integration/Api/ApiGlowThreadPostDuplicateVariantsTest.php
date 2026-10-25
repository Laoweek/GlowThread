<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Api;

/**
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Policy\WriteGuard
 * @covers \MediaWiki\Extension\GlowThread\Text\TextProcessor
 */
class ApiGlowThreadPostDuplicateVariantsTest extends GlowThreadApiTestCase {

	/**
	 * Regression: case, spacing, a no-break space, full-width letters and invisible characters
	 * got a repeated text past the duplicate check
	 */
	public function testVariantsOfARecentTextAreDuplicates() {
		$params = [ 'action' => 'glowthreadpost', 'pageid' => $this->gtPage()->getId() ];
		$user = $this->gtUser();
		$result = $this->gtApi( $params + [ 'text' => 'buy my café now' ], $user )['glowthreadpost'];
		$this->assertSame( 'success', $result['result'] );
		$variants = [
			'Buy my CAFÉ now', 'buy  my café now', "buy my\ncafé now", "buy\u{200D} my café now",
			"buy\u{200C} my café now", "buy\u{FE0F} my café now", "buy\u{034F} my café now",
			"buy\u{E0020} my café now", "buy\u{00A0}my café now", 'ｂuy my café now',
		];
		foreach ( $variants as $text ) {
			$this->assertSame(
				'duplicate', $this->gtApiError( $params + [ 'text' => $text ], $user ), json_encode( $text )
			);
		}
		// A different word is not a duplicate
		$result = $this->gtApi( $params + [ 'text' => 'buy my cafe now' ], $user )['glowthreadpost'];
		$this->assertSame( 'success', $result['result'] );
	}
}
