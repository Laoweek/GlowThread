<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Api;

use MediaWiki\Extension\GlowThread\Filter\WordFilter;

/**
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Api\ApiGlowThreadFilterTest
 */
class ApiGlowThreadFilterTestTest extends GlowThreadApiTestCase {

	public function testCurrentAndDraftRules() {
		$this->gtSetFilter( "coins\n/(broken/" );
		$sysop = $this->getTestSysop()->getUser();
		$current = $this->gtApi( [ 'action' => 'glowthreadfiltertest', 'text' => 'cheap C O I N S' ], $sysop );
		$current = $current['glowthreadfiltertest'];
		$this->assertTrue( $current['held'] );
		$this->assertSame( 2, $current['invalid'][0]['line'] );
		$this->assertSame( 1, $current['rules'] );

		$draft = $this->gtApi( [
			'action' => 'glowthreadfiltertest', 'text' => 'cheap coins', 'rules' => "/expensive/i",
		], $sysop )['glowthreadfiltertest'];
		$this->assertFalse( $draft['held'] );
		$this->assertSame( [], $draft['invalid'] );
	}

	/**
	 * The test gives the same answer as posting the text: both normalise it the same way and run
	 * the same check, which matches whole words.
	 */
	public function testMatchesLikePosting() {
		$this->gtSetFilter( "ass\ncheap coins\n傻瓜" );
		$sysop = $this->getTestSysop()->getUser();
		$page = $this->gtPage();
		$texts = [
			'a class on the passage' => false,
			"it's hit or miss" => false,
			'A.S.S!' => true,
			// A zero-width joiner survives the stored-text normalisation, the filter ignores it
			"a\u{200D}ss" => true,
			// i18n: full-width letters match through NFKC
			'ＣＨＥＡＰ-coins' => true,
			// i18n: a CJK rule matches inside text without spaces between words
			'你这个傻瓜' => true,
			// Accents and strokes are ignored, a capital after a lower-case letter ends spaced-out letters
			"A\u{0335}S\u{0335}S\u{0335}" => true,
			'Got a S S rank today' => false,
			// i18n: not across the end of a sentence or a line
			'我很傻。瓜子很好吃' => false,
			"我很傻\n瓜子很好吃" => false,
		];
		foreach ( $texts as $text => $held ) {
			$test = $this->gtApi( [ 'action' => 'glowthreadfiltertest', 'text' => $text ], $sysop );
			$this->assertSame( $held, $test['glowthreadfiltertest']['held'], "filter test: $text" );
			// Posted by a new sysop each time: a moderator is told every hold reason (an author is not
			// told about the filter), and the filter applies to sysops too
			$post = $this->gtApi( [
				'action' => 'glowthreadpost', 'pageid' => $page->getId(), 'text' => $text,
			], $this->gtUser( [ 'sysop' ] ) )['glowthreadpost'];
			$this->assertSame( $held ? 'held' : 'visible', $post['status'], "post: $text" );
			$this->assertSame( $held ? [ 'filter' ] : [], $post['holdReasons'], "post: $text" );
		}
	}

	/**
	 * The filter test is bounded (sample text and draft rules) and counts
	 * against the glowthread-configure limit, which sysops cannot bypass.
	 */
	public function testBoundsAndThrottle() {
		$sysop = $this->getTestSysop()->getUser();
		$this->assertSame( 'maxchars', $this->gtApiError(
			[ 'action' => 'glowthreadfiltertest', 'text' => str_repeat( 'a', 10001 ) ], $sysop
		) );
		$this->assertSame( 'maxbytes', $this->gtApiError(
			[ 'action' => 'glowthreadfiltertest', 'text' => 'x', 'rules' => str_repeat( "a\n", 32769 ) ], $sysop
		) );

		$limits = $this->gtDefaultRateLimits();
		$this->assertFalse( $limits['glowthread-configure']['&can-bypass'] );
		$this->overrideConfigValue( 'RateLimits', [
			'glowthread-configure' => [ 'user' => [ 2, 60 ], '&can-bypass' => false ],
		] );
		for ( $i = 0; $i < 2; $i++ ) {
			$this->gtApi( [ 'action' => 'glowthreadfiltertest', 'text' => "try $i" ], $sysop );
		}
		$error = $this->gtApiErrorArray( [ 'action' => 'glowthreadfiltertest', 'text' => 'third' ], $sysop );
		$this->assertSame( 'ratelimited', $error['code'] );
		$this->assertSame( 60, $error['data']['retryAfter'] );
	}

	/**
	 * Rules after WordFilter::MAX_RULES are not dropped silently: the first is listed as invalid.
	 */
	public function testTooManyRulesAreReported() {
		$sysop = $this->getTestSysop()->getUser();
		$rules = '';
		for ( $i = 1; $i <= WordFilter::MAX_RULES + 1; $i++ ) {
			$rules .= "w$i\n";
		}
		$test = $this->gtApi( [
			'action' => 'glowthreadfiltertest', 'text' => 'w' . ( WordFilter::MAX_RULES + 1 ), 'rules' => $rules,
		], $sysop )['glowthreadfiltertest'];
		$this->assertFalse( $test['held'] );
		$this->assertSame( WordFilter::MAX_RULES, $test['rules'] );
		$this->assertSame( [ [
			'line' => WordFilter::MAX_RULES + 1,
			'rule' => 'w' . ( WordFilter::MAX_RULES + 1 ),
			'error' => 'too-many-rules',
		] ], $test['invalid'] );
	}

	public function testNeedsConfigureRight() {
		$this->assertSame( 'permissiondenied', $this->gtApiError(
			[ 'action' => 'glowthreadfiltertest', 'text' => 'x' ], $this->gtUser()
		) );
	}
}
