<?php

namespace MediaWiki\Extension\GlowThread\Tests\Unit\Filter;

use MediaWiki\Content\TextContent;
use MediaWiki\Extension\GlowThread\Filter\WordFilter;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\PageLookup;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\GlowThread\Filter\WordFilter
 */
class WordFilterTest extends MediaWikiUnitTestCase {

	private function newFilter( string $rules ): WordFilter {
		$revision = $this->createMock( RevisionRecord::class );
		$revision->method( 'getContent' )->willReturn( new TextContent( $rules ) );
		$lookup = $this->createMock( RevisionLookup::class );
		$lookup->method( 'getRevisionByTitle' )->willReturn( $revision );
		$pages = $this->createMock( PageLookup::class );
		$pages->method( 'getPageByName' )->willReturn( $this->createMock( ExistingPageRecord::class ) );
		return new WordFilter( $pages, $lookup );
	}

	public function testCommentsAndBlankLinesAreIgnored() {
		$filter = $this->newFilter( "# a comment\n\n   \n# another" );
		$this->assertSame( [ 'rules' => [], 'invalid' => [] ], $filter->parse() );
		$this->assertFalse( $filter->check( 'anything at all' )['matched'] );
	}

	public function testLiteralMatchesCaseInsensitively() {
		// A phrase rule, not "CheapCoins": a rule is matched as whole words, so "CheapCoins" is one
		// word and does not match the two words "Cheap Coins". The words of a phrase rule match with
		// or without separators between them.
		$filter = $this->newFilter( "cheap coins\n傻瓜" );
		$this->assertTrue( $filter->check( 'buy cheapcoins now' )['matched'] );
		// i18n: a CJK rule matches inside text written without spaces between words
		$this->assertTrue( $filter->check( '你这个傻瓜' )['matched'] );
		$this->assertTrue( $filter->check( 'Cheap Coins!' )['matched'] );
		$this->assertFalse( $filter->check( 'hello' )['matched'] );
	}

	public function testLiteralMatchesAcrossSpacesAndPunctuation() {
		$filter = $this->newFilter( "spam\n傻瓜" );
		$this->assertTrue( $filter->check( 's p a m' )['matched'] );
		$this->assertTrue( $filter->check( 's.p-a_m' )['matched'] );
		// i18n: the same for CJK characters
		$this->assertTrue( $filter->check( '傻 * 瓜' )['matched'] );
	}

	public static function provideLiteralRules() {
		// Whole words: a rule does not match inside a longer word
		yield 'class' => [ 'ass', 'class', false ];
		yield 'assume' => [ 'ass', 'assume', false ];
		yield 'passage' => [ 'ass', 'passage', false ];
		yield 'the word' => [ 'ass', 'ass', true ];
		yield 'capitalised, with punctuation' => [ 'ass', 'Ass!', true ];
		// Single letters divided by separators are joined, longer words are not
		yield 'spaced out' => [ 'ass', 'a s s', true ];
		yield 'dotted' => [ 'ass', 'a.s.s', true ];
		yield 'hyphenated' => [ 'ass', 'a-s-s', true ];
		yield 'dots and spaces' => [ 'ass', 'a . s . s', true ];
		yield 'spaced out after a one-letter word' => [ 'ass', 'what a s s', true ];
		yield 'spaced out, capitalised' => [ 'ass', 'A s s', true ];
		yield 'spaced out, mixed case' => [ 'shit', 's H i T', true ];
		// Spaced-out letters do not go on from a lower-case letter to spaced-out capitals
		yield 'one-letter word before capitals' => [ 'ass', 'Got a S S rank today', false ];
		yield 'one-letter word before an abbreviation' => [ 'ass', 'I am a S.S. officer', false ];
		yield 'capitals after another word' => [ 'ass', 'class A S S', true ];
		yield 'apostrophe and a longer word' => [ 'shit', "it's hit", false ];
		yield 'contraction' => [ 'hell', "he'll", false ];
		// i18n: NFKC turns full-width letters into ASCII
		yield 'full-width' => [ 'ass', 'ＡＳＳ', true ];
		yield 'zero-width space' => [ 'ass', "a\u{200B}ss", true ];
		yield 'soft hyphen' => [ 'ass', "a\u{00AD}ss", true ];
		yield 'variation selector before the word' => [ 'ass', "\u{FE0F}ass", true ];
		yield 'variation selector after the word' => [ 'ass', "ass\u{FE0F} you", true ];
		yield 'combining grapheme joiner' => [ 'ass', "a\u{034F}ss", true ];
		yield 'Hangul filler' => [ 'ass', "a\u{3164}ss", true ];
		// A rule without accents ignores accents and other combining marks on Latin letters
		yield 'precomposed accent' => [ 'spam', 'spàm', true ];
		yield 'combining tilde inside' => [ 'spam', "sp\u{0303}am", true ];
		yield 'combining stroke on every letter' => [ 'spam', "s\u{0335}p\u{0335}a\u{0335}m\u{0335}", true ];
		yield 'Cyrillic mark on a Latin letter' => [ 'spam', "s\u{0489}pam", true ];
		// A rule with accents matches only the same accents, still without stray marks
		yield 'accent in the rule' => [ 'spàm', 'SPAM', false ];
		yield 'accent in the rule and the text' => [ 'spàm', 'SPÀM', true ];
		yield 'accent in the rule, stroke in the text' => [ 'spàm', "s\u{0335}pàm", true ];
		// i18n: tones tell Vietnamese words apart
		yield 'Vietnamese tones' => [ 'lồn', 'cái này lớn quá', false ];
		// i18n: also Cyrillic, Greek, Arabic and Hebrew accents and vowel points
		yield 'Cyrillic yo' => [ 'еж', 'ёж', true ];
		yield 'Greek tonos' => [ 'καλα', 'Καλά', true ];
		yield 'Arabic vowel points' => [ 'كلب', 'كَلْب', true ];
		yield 'Hebrew vowel points' => [ 'שלום', 'שָׁלוֹם', true ];
		// i18n: marks that write vowels, tones or voicing stay, so these words stay distinct
		yield 'Devanagari vowel sign' => [ 'काला', 'कला', false ];
		yield 'Thai tone and vowel marks' => [ 'เหี้ย', 'เหยื่อ', false ];
		yield 'kana voicing mark' => [ 'ばか', 'はかる', false ];
		yield 'half-width kana with voicing' => [ 'バカ', 'ﾊﾞｶ', true ];
		yield 'Korean syllables stay whole' => [ '바보', '바봄', false ];
		// i18n: case folding, not only lower case
		yield 'German sharp s' => [ 'straße', 'STRASSE', true ];
		// Phrases: the words in order, with any separators or none between them
		yield 'phrase' => [ 'cheap coins', 'Cheap Coins!', true ];
		yield 'phrase without a space' => [ 'cheap coins', 'cheapcoins', true ];
		yield 'phrase with a hyphen' => [ 'cheap coins', 'cheap-coins', true ];
		yield 'phrase spaced out' => [ 'cheap coins', 'c h e a p c o i n s', true ];
		yield 'phrase, one word' => [ 'cheap coins', 'cheap', false ];
		yield 'phrase, other order' => [ 'cheap coins', 'coins are cheap', false ];
		// i18n: a Latin rule matches next to scripts that put no space before a word
		yield 'next to Chinese' => [ 'spam', '这个spam链接', true ];
		yield 'next to Japanese' => [ 'spam', 'spamです', true ];
		yield 'next to a Korean particle' => [ 'spam', 'spam이야', true ];
		// i18n: a Thai rule matches inside Thai text, which has no spaces between words
		yield 'Thai' => [ 'ควาย', 'แกมันควายจริงๆ', true ];
		// i18n: a CJK rule matches when the text has spaces or symbols between its characters
		yield 'Chinese, spaced in the text' => [ '傻瓜', '傻 * 瓜', true ];
		yield 'Chinese, other word' => [ '傻瓜', '聪明', false ];
		yield 'Chinese, variation selector inside' => [ '傻瓜', "傻\u{FE0F}瓜", true ];
		yield 'Chinese, combining accent inside' => [ '傻瓜', "傻\u{0301}瓜", true ];
		yield 'Chinese, enclosing circle inside' => [ '傻瓜', "傻\u{20DD}瓜", true ];
		yield 'Chinese, ASCII punctuation inside' => [ '傻瓜', '傻.瓜', true ];
		// i18n: but not across ideographic or full-width punctuation that ends a sentence or clause
		yield 'Chinese, across a full stop' => [ '傻瓜', '我很傻。瓜子很好吃', false ];
		yield 'Chinese, across a comma' => [ '傻瓜', '我很傻，瓜子很好吃', false ];
		yield 'Chinese, across a line break' => [ '傻瓜', "我很傻\n瓜子很好吃", false ];
		yield 'Chinese, across a corner bracket' => [ '傻瓜', '他说「我很傻」瓜子很好吃', false ];
		yield 'Chinese, across a curly quote' => [ '傻瓜', '他说“我很傻”瓜子很好吃', false ];
		yield 'Chinese, across a full-width parenthesis' => [ '傻瓜', '（我很傻）瓜子很好吃', false ];
		yield 'Chinese rule with punctuation' => [ '傻，瓜', '你这个傻瓜', true ];
		yield 'Thai, combining accent inside' => [ 'ควาย', "ค\u{0301}วาย", true ];
		// A rule with no letter or digit matches anywhere, a rule of spaces or invisible characters never
		yield 'emoji' => [ '🖕', 'so🖕', true ];
		yield 'keycap emoji' => [ "1\u{FE0F}\u{20E3}", "press 1\u{FE0F}\u{20E3}", true ];
		yield 'keycap emoji, the digit alone' => [ "1\u{FE0F}\u{20E3}", 'I have 1 cat', false ];
		yield 'only a zero-width space' => [ "\u{200B}", 'any text', false ];
		// i18n: an ideographic space, which CJK input methods type, is a space after NFKC
		yield 'only an ideographic space' => [ "\u{3000}", 'any text', false ];
		yield 'only a no-break space' => [ "\u{00A0}", 'any text', false ];
	}

	/**
	 * @dataProvider provideLiteralRules
	 */
	public function testLiteralRules( string $rule, string $text, bool $expected ) {
		$result = $this->newFilter( $rule )->check( $text );
		$this->assertSame( $expected, $result['matched'] );
		$this->assertFalse( $result['error'] );
	}

	public function testMatchReportsLineAndRule() {
		$filter = $this->newFilter( "# rules\nfoo\n/b[a]r/i" );
		$result = $filter->check( 'BAR' );
		$this->assertSame( [ [ 'line' => 3, 'rule' => '/b[a]r/i', 'type' => 'regex' ] ], $result['matches'] );
		$result = $filter->check( 'Foo!' );
		$this->assertSame( [ [ 'line' => 2, 'rule' => 'foo', 'type' => 'literal' ] ], $result['matches'] );
	}

	public function testInvalidLinesAreReportedAndSkipped() {
		$filter = $this->newFilter( "/(unclosed/\n/ok/\n/x/e\nliteral" );
		$parsed = $filter->parse();
		$this->assertCount( 2, $parsed['invalid'] );
		$this->assertSame( 1, $parsed['invalid'][0]['line'] );
		$this->assertSame( 'unsupported-flags', $parsed['invalid'][1]['error'] );
		$this->assertCount( 2, $parsed['rules'], 'the valid lines still apply' );
		$this->assertTrue( $filter->check( 'is it ok' )['matched'] );
		$this->assertTrue( $filter->check( 'a literal here' )['matched'] );
	}

	public function testLiteralTooLongToCompileDoesNotHoldEveryComment() {
		// Beyond PCRE's usual pattern size, so it is reported as invalid instead of failing on
		// every comment (a PCRE built with a larger link size compiles it, which is fine too)
		$filter = $this->newFilter( str_repeat( 'a', 5000 ) . "\nspam" );
		$result = $filter->check( 'hello' );
		$this->assertFalse( $result['error'] );
		$this->assertFalse( $result['matched'] );
		$this->assertTrue( $filter->check( 'spam' )['matched'] );
	}

	public function testRulesAfterTheLimitAreReportedAndIgnored() {
		$rules = "# comments and empty lines do not count\n\n";
		for ( $i = 1; $i <= WordFilter::MAX_RULES + 5; $i++ ) {
			$rules .= "word$i\n";
		}
		$filter = $this->newFilter( $rules );
		$parsed = $filter->parse();
		$this->assertCount( WordFilter::MAX_RULES, $parsed['rules'] );
		$this->assertSame( [ [
			'line' => WordFilter::MAX_RULES + 3,
			'rule' => 'word' . ( WordFilter::MAX_RULES + 1 ),
			'error' => 'too-many-rules',
		] ], $parsed['invalid'] );
		$this->assertTrue( $filter->check( 'word' . WordFilter::MAX_RULES )['matched'] );
		$this->assertFalse( $filter->check( 'word' . ( WordFilter::MAX_RULES + 1 ) )['matched'] );
	}

	public function testRegexesAfterTheTimeBudgetAreErrors() {
		// Each regex is slow on long text (a .* lookahead at every position) without reaching the
		// backtrack limit. Rules that ran before the budget still match.
		$filter = $this->newFilter(
			"lorem\n" . str_repeat( "/(?=.*cheap)(?=.*pills)/is\n", WordFilter::MAX_RULES - 2 ) . 'ipsum'
		);
		$result = $filter->check( str_repeat( 'lorem ipsum dolor sit amet ', 370 ) );
		$this->assertSame( [ 1 ], array_column( $result['matches'], 'line' ) );
		$this->assertTrue( $result['error'] );
		$this->assertSame(
			[ 'line' => WordFilter::MAX_RULES, 'rule' => 'ipsum', 'error' => 'time-limit' ],
			end( $result['errors'] )
		);
	}

	public function testSlashesInsideRegexNeedNoEscaping() {
		$filter = $this->newFilter( '/https?://bad\.example/i' );
		$this->assertTrue( $filter->check( 'go to HTTP://BAD.EXAMPLE/x' )['matched'] );
	}

	public function testRegexRuntimeErrorIsReportedNotPassed() {
		$jit = ini_get( 'pcre.jit' );
		$limit = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.jit', '0' );
		ini_set( 'pcre.backtrack_limit', '100' );
		try {
			$filter = $this->newFilter( "/(a+)+\$/\nnever" );
			$result = $filter->check( str_repeat( 'a', 40 ) . 'b' );
		} finally {
			ini_set( 'pcre.jit', $jit );
			ini_set( 'pcre.backtrack_limit', $limit );
		}
		$this->assertTrue( $result['error'] );
		$this->assertFalse( $result['matched'] );
		$this->assertSame( 1, $result['errors'][0]['line'] );
		$this->assertSame( 'time-limit', $result['errors'][0]['error'] );
	}

	public function testSlowRegexIsStoppedOnItsOwn() {
		// Each optional lookahead scans the rest of the text at every position: about 9 seconds
		// under PCRE's default limit, stopped by REGEX_MATCH_LIMIT
		$limit = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.backtrack_limit', '1000000' );
		try {
			$filter = $this->newFilter( '/' . str_repeat( '(?:(?=.*\bspam1\b))?', 40 ) . '(?=.*\bcheap\b)/is' );
			$start = microtime( true );
			$result = $filter->check( str_repeat( 'lorem ipsum dolor sit amet ', 370 ) );
			$seconds = microtime( true ) - $start;
			$this->assertSame( '1000000', ini_get( 'pcre.backtrack_limit' ), 'the limit is restored' );
		} finally {
			ini_set( 'pcre.backtrack_limit', $limit );
		}
		$this->assertSame( [ 1 ], array_column( $result['errors'], 'line' ) );
		$this->assertSame( 'time-limit', $result['errors'][0]['error'] );
		$this->assertLessThan( WordFilter::REGEX_TIME_BUDGET, $seconds );
	}

	public function testNoPageMeansNoRules() {
		$pages = $this->createMock( PageLookup::class );
		$pages->method( 'getPageByName' )->willReturn( null );
		$filter = new WordFilter( $pages, $this->createMock( RevisionLookup::class ) );
		$this->assertSame( '', $filter->getRulesText() );
		$this->assertFalse( $filter->check( 'anything' )['matched'] );
	}

	public function testDraftRulesDoNotReplaceCachedRules() {
		$filter = $this->newFilter( 'live' );
		$this->assertTrue( $filter->check( 'draft word', 'draft' )['matched'] );
		$this->assertFalse( $filter->check( 'draft word' )['matched'] );
		$this->assertTrue( $filter->check( 'live word' )['matched'] );
	}
}
