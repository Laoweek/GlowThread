<?php

namespace MediaWiki\Extension\GlowThread\Tests\Unit\Text;

use MediaWiki\Extension\GlowThread\Text\TextProcessor;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\GlowThread\Text\TextProcessor
 */
class TextProcessorTest extends MediaWikiUnitTestCase {

	/** Flag of England: a black flag, the tag letters g b e n g and a cancel tag */
	private const ENGLAND = "🏴\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}";

	/**
	 * ASCII written in tag characters, which show nothing
	 */
	private static function tags( string $ascii ): string {
		$tags = '';
		foreach ( str_split( $ascii ) as $char ) {
			$tags .= mb_chr( 0xE0000 + ord( $char ) );
		}
		return $tags;
	}

	private function newProcessor(): TextProcessor {
		return new TextProcessor();
	}

	public static function provideInertMarkup() {
		yield 'script tag' => [ '<script>alert(1)</script>' ];
		yield 'img onerror' => [ '<img src=x onerror=alert(1)>' ];
		yield 'attribute breakout' => [ '"><svg/onload=alert(1)>' ];
		yield 'javascript URL' => [ 'javascript:alert(document.cookie)' ];
		yield 'data URL' => [ 'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==' ];
		yield 'wikitext' => [ "{{#invoke:Foo|bar}} [[Special:UserLogout]] '''bold'''" ];
		yield 'html entity' => [ '&lt;b&gt;x&#x3C;' ];
	}

	/**
	 * @dataProvider provideInertMarkup
	 */
	public function testMarkupStaysPlainText( string $input ) {
		$tp = $this->newProcessor();
		$text = $tp->normalize( $input );
		$this->assertSame( $input, $text, 'normalize() does not escape or rewrite markup' );
		$this->assertSame( [ [ 'type' => 'text', 'text' => $input ] ], $tp->toSegments( $text, [] ) );
	}

	public static function provideUrls() {
		yield 'plain' => [ 'see https://example.com/a', [ 'https://example.com/a' ] ];
		yield 'trailing period' => [ 'see https://example.com/a.', [ 'https://example.com/a' ] ];
		yield 'trailing comma and question' => [ 'https://example.com/a?, ok', [ 'https://example.com/a' ] ];
		yield 'in parentheses' => [ '(https://example.com/a)', [ 'https://example.com/a' ] ];
		yield 'balanced parentheses kept' => [
			'https://en.wikipedia.org/wiki/Foo_(bar)', [ 'https://en.wikipedia.org/wiki/Foo_(bar)' ]
		];
		// i18n: CJK punctuation and letters of scripts that often follow a URL with no space end it
		yield 'CJK full stop ends' => [ '看https://example.com/x。好', [ 'https://example.com/x' ] ];
		yield 'full-width comma ends' => [ 'https://example.com/x，然后', [ 'https://example.com/x' ] ];
		yield 'Chinese letters end' => [ 'https://example.com/a这是链接', [ 'https://example.com/a' ] ];
		yield 'kana end' => [ 'https://example.com/aです', [ 'https://example.com/a' ] ];
		yield 'Hangul ends' => [ 'https://example.com/a입니다', [ 'https://example.com/a' ] ];
		yield 'Thai letters end' => [ 'https://example.com/aขอบคุณ', [ 'https://example.com/a' ] ];
		yield 'Lao letters end' => [ 'https://example.com/aຂອບໃຈ', [ 'https://example.com/a' ] ];
		yield 'Khmer letters end' => [ 'https://example.com/aអរគុណ', [ 'https://example.com/a' ] ];
		yield 'Myanmar letters end' => [ 'https://example.com/aကျေးဇူး', [ 'https://example.com/a' ] ];
		yield 'CJK closing bracket ends' => [ '（https://example.com/a）', [ 'https://example.com/a' ] ];
		yield 'corner bracket ends' => [ '「https://example.com/a」', [ 'https://example.com/a' ] ];
		yield 'percent-encoded CJK path kept' => [
			'https://example.org/wiki/%E5%88%97 好', [ 'https://example.org/wiki/%E5%88%97' ]
		];
		yield 'upper-case scheme' => [ 'HTTPS://EXAMPLE.COM/A', [ 'HTTPS://EXAMPLE.COM/A' ] ];
		yield 'query and fragment' => [ 'https://a.example/p?q=1&r=2#frag', [ 'https://a.example/p?q=1&r=2#frag' ] ];
		yield 'http' => [ 'http://example.com', [ 'http://example.com' ] ];
		yield 'ftp is not linked' => [ 'ftp://example.com/x', [] ];
		yield 'no host' => [ 'http:// nothing', [] ];
		yield 'protocol-relative is not linked' => [ '//example.com/x', [] ];
		yield 'quote ends' => [ '"https://example.com/x"', [ 'https://example.com/x' ] ];
		yield 'angle bracket ends' => [ 'https://example.com/x<script>', [ 'https://example.com/x' ] ];
		yield 'two links' => [ 'https://a.example https://b.example', [ 'https://a.example', 'https://b.example' ] ];
		yield 'duplicates once' => [ 'https://a.example https://a.example', [ 'https://a.example' ] ];
	}

	/**
	 * @dataProvider provideUrls
	 */
	public function testUrls( string $input, array $expected ) {
		$this->assertSame( $expected, $this->newProcessor()->extractUrls( $input ) );
	}

	public function testUrlSegmentsKeepSurroundingText() {
		$segments = $this->newProcessor()->toSegments( '(see https://example.com/a).', [] );
		$this->assertSame( [
			[ 'type' => 'text', 'text' => '(see ' ],
			[ 'type' => 'link', 'text' => 'https://example.com/a', 'href' => 'https://example.com/a' ],
			[ 'type' => 'text', 'text' => ').' ],
		], $segments );
	}

	public function testOverlongUrlIsText() {
		$url = 'https://example.com/' . str_repeat( 'a', TextProcessor::MAX_URL_LENGTH );
		$this->assertSame( [], $this->newProcessor()->extractUrls( $url ) );
	}

	public static function provideUrlTrimming() {
		yield 'unpaired ) dropped' => [ 'https://a.example/x)', 'https://a.example/x' ];
		yield 'paired ) kept' => [ 'https://a.example/(x)', 'https://a.example/(x)' ];
		yield 'only the unpaired ) dropped' => [ 'https://a.example/(x))', 'https://a.example/(x)' ];
		yield 'unpaired ] dropped' => [ 'https://a.example/[x]]', 'https://a.example/[x]' ];
		yield 'unpaired } dropped' => [ 'https://a.example/{x}}', 'https://a.example/{x}' ];
		yield 'each kind of bracket counted on its own' => [ 'https://a.example/(x]', 'https://a.example/(x' ];
		yield 'brackets counted, not matched by position' => [
			'https://a.example/x)y(z)', 'https://a.example/x)y(z'
		];
		yield 'unpaired opening bracket kept' => [ 'https://a.example/x(', 'https://a.example/x(' ];
		yield 'bracket before the end kept' => [ 'https://a.example/a)b', 'https://a.example/a)b' ];
		yield 'trailing punctuation dropped' => [ 'https://a.example/x.,;:!?*', 'https://a.example/x' ];
		yield 'apostrophe ends' => [ "https://a.example/x'", 'https://a.example/x' ];
		yield 'punctuation before an unpaired bracket' => [ 'https://a.example/x.)', 'https://a.example/x' ];
		yield 'punctuation after an unpaired bracket' => [ 'https://a.example/x).', 'https://a.example/x' ];
		yield 'punctuation inside paired brackets kept' => [ 'https://a.example/(x.)', 'https://a.example/(x.)' ];
		yield 'brackets and punctuation mixed' => [ 'https://a.example/(x)).),', 'https://a.example/(x)' ];
		yield 'kinds of brackets mixed' => [ 'https://a.example/()))).]]]', 'https://a.example/()' ];
		yield 'trailing slash kept' => [ 'https://a.example/x/', 'https://a.example/x/' ];
		yield 'trailing = kept' => [ 'https://a.example/?q=', 'https://a.example/?q=' ];
		yield 'long run of )' => [ 'http://a.example/' . str_repeat( ')', 975 ), 'http://a.example/' ];
		yield 'trimmed before the length cap' => [
			'https://example.com/' . str_repeat( 'a', 100 ) . str_repeat( ')', TextProcessor::MAX_URL_LENGTH ),
			'https://example.com/' . str_repeat( 'a', 100 ),
		];
	}

	/**
	 * Trailing punctuation and unpaired closing brackets are left out of the link and stay text
	 *
	 * @dataProvider provideUrlTrimming
	 */
	public function testUrlTrimming( string $text, string $link ) {
		$tp = $this->newProcessor();
		$expected = [ [ 'type' => 'link', 'text' => $link, 'href' => $link ] ];
		if ( $link !== $text ) {
			$expected[] = [ 'type' => 'text', 'text' => substr( $text, strlen( $link ) ) ];
		}
		$this->assertSame( $expected, $tp->toSegments( $text, [] ) );
		$this->assertSame( [ $link ], $tp->extractUrls( $text ) );
	}

	/**
	 * Regression: each closing bracket dropped from a URL counted the brackets of the whole URL
	 * again, so the time grew with the square of the run: about 7 s for this text, 3 ms now.
	 * The bound is generous so that a slow machine does not fail it.
	 */
	public function testLongRunOfClosingBracketsIsFast() {
		$tp = $this->newProcessor();
		$text = 'http://a.example/' . str_repeat( ')', 30000 );
		$start = hrtime( true );
		$segments = $tp->toSegments( $text, [] );
		$urls = $tp->extractUrls( $text );
		$seconds = ( hrtime( true ) - $start ) / 1e9;
		$this->assertSame( [
			[ 'type' => 'link', 'text' => 'http://a.example/', 'href' => 'http://a.example/' ],
			[ 'type' => 'text', 'text' => str_repeat( ')', 30000 ) ],
		], $segments );
		$this->assertSame( [ 'http://a.example/' ], $urls );
		$this->assertLessThan( 1, $seconds, 'seconds for toSegments() and extractUrls()' );
	}

	public static function provideMentionTokens() {
		yield 'one' => [ 'hi <@1>', [ 1 ] ];
		yield 'several, without duplicates, in order' => [ '<@3> <@1> <@3>', [ 3, 1 ] ];
		yield 'no space around' => [ 'hi<@7>,', [ 7 ] ];
		// i18n: CJK text and punctuation right before and after
		yield 'CJK around' => [ '谢谢<@42>！', [ 42 ] ];
		yield 'ten digits' => [ '<@1234567890>', [ 1234567890 ] ];
		yield 'right after a URL, which ends at <' => [ 'https://a.example/x<@5>', [ 5 ] ];
		yield 'typed name' => [ '@Alice hi', [] ];
		yield 'email' => [ 'a@example.com', [] ];
		yield 'zero' => [ '<@0>', [] ];
		yield 'leading zero' => [ '<@01>', [] ];
		yield 'spaces inside' => [ '<@ 1> <@1 >', [] ];
		yield 'sign' => [ '<@-1> <@+1>', [] ];
		yield 'not ASCII digits' => [ "<@abc> <@\u{0661}> <@１>", [] ];
		yield 'eleven digits' => [ '<@12345678901>', [] ];
		yield 'full-width brackets' => [ '＜@1＞', [] ];
	}

	/**
	 * @dataProvider provideMentionTokens
	 */
	public function testExtractMentionIds( string $text, array $ids ) {
		$this->assertSame( $ids, $this->newProcessor()->extractMentionIds( $text ) );
	}

	public function testMentionToken() {
		$this->assertSame( '<@123>', TextProcessor::mentionToken( 123 ) );
		$this->assertSame( [ 123 ], $this->newProcessor()->extractMentionIds( TextProcessor::mentionToken( 123 ) ) );
	}

	public function testMentionSegments() {
		$segments = $this->newProcessor()->toSegments(
			'hi <@1> and <@2>, <@3>!', [ 1 => 'Alice Smith', 3 => '小明' ]
		);
		$this->assertSame( [
			self::text( 'hi ' ),
			[ 'type' => 'mention', 'text' => '@Alice Smith', 'user' => 'Alice Smith', 'id' => 1, 'exists' => true ],
			self::text( ' and ' ),
			self::placeholder(),
			self::text( ', ' ),
			[ 'type' => 'mention', 'text' => '@小明', 'user' => '小明', 'id' => 3, 'exists' => true ],
			self::text( '!' ),
		], $segments );
	}

	/**
	 * A user who does not exist or is hidden is not in the existence data, and never shows a name
	 */
	public function testMentionsWithoutAUserArePlaceholders() {
		$tp = $this->newProcessor();
		$this->assertSame( [ self::placeholder() ], $tp->toSegments( '<@9>', [ 1 => 'Alice' ] ) );
		$this->assertSame(
			[ [ 'type' => 'mention', 'text' => TextProcessor::MENTION_PLACEHOLDER ] ], $tp->tokenize( '<@1>' ),
			'without existence data'
		);
	}

	/**
	 * Regression (A13): typed names are plain text, however many name-like parts they have, and
	 * nothing is looked up for them
	 */
	public function testTypedNamesArePlainText() {
		$tp = $this->newProcessor();
		$text = '@丁' . str_repeat( ',', 85 ) . " @Alice's idea, @Bob @1";
		$this->assertSame( [], $tp->extractMentionIds( $text ) );
		$this->assertSame( [ self::text( $text ) ], $tp->toSegments( $text, [ 1 => 'Alice' ] ) );
		$this->assertSame( [], $tp->extractMentions( $text, [ 1 => 'Alice' ] ) );
	}

	/**
	 * Only the first MAX_MENTIONS_SHOWN tokens of a text show as mentions and are looked up, so
	 * the work per comment is bounded whatever the text says
	 */
	public function testOnlyTheFirstMentionsShow() {
		$tp = $this->newProcessor();
		$max = TextProcessor::MAX_MENTIONS_SHOWN;
		$text = str_repeat( '<@1>', $max ) . '<@2><@1>';
		$users = [ 1 => 'Alice', 2 => 'Bob' ];
		$this->assertSame( [ 1 ], $tp->extractMentionIds( $text ), 'later IDs are not looked up' );
		$segments = $tp->toSegments( $text, $users );
		$this->assertCount( $max + 2, $segments );
		$this->assertSame( array_fill( 0, $max, 'Alice' ), array_column( array_slice( $segments, 0, $max ), 'user' ) );
		$this->assertSame( [ self::placeholder(), self::placeholder() ], array_slice( $segments, $max ) );
		$this->assertSame( [ 'Alice' ], $tp->extractMentions( $text, $users ) );

		$many = '';
		for ( $id = 1; $id <= 1000; $id++ ) {
			$many .= TextProcessor::mentionToken( $id );
		}
		$this->assertSame( range( 1, $max ), $tp->extractMentionIds( $many ) );
	}

	public function testExtractMentions() {
		$tp = $this->newProcessor();
		$users = [ 1 => 'Alice', 2 => '12345' ];
		// A numeric name stays a string
		$this->assertSame( [ '12345', 'Alice' ], $tp->extractMentions( '<@2> <@9> <@1> <@2>', $users ) );
		$this->assertSame( [], $tp->extractMentions( '<@1>' ), 'without existence data' );
	}

	/**
	 * Plain-text renderings (notification excerpts, previews) show names and placeholders
	 */
	public function testPlainText() {
		$this->assertSame(
			'thanks @Alice Smith and ' . TextProcessor::MENTION_PLACEHOLDER . ', see https://a.example/x).',
			$this->newProcessor()->toPlainText(
				'thanks <@1> and <@2>, see https://a.example/x).', [ 1 => 'Alice Smith' ]
			)
		);
	}

	/**
	 * i18n: Chinese text written right after a URL stays text
	 */
	public function testUrlFollowedByChineseKeepsTheText() {
		$this->assertSame( [
			[ 'type' => 'text', 'text' => '看' ],
			[ 'type' => 'link', 'text' => 'https://example.com/a', 'href' => 'https://example.com/a' ],
			[ 'type' => 'text', 'text' => '这是链接。' ],
		], $this->newProcessor()->tokenize( '看https://example.com/a这是链接。' ) );
	}

	private static function text( string $text ): array {
		return [ 'type' => 'text', 'text' => $text ];
	}

	private static function placeholder(): array {
		return [ 'type' => 'mention', 'text' => TextProcessor::MENTION_PLACEHOLDER, 'exists' => false ];
	}

	public static function provideNormalize() {
		yield 'bidi override removed' => [ "abc\u{202E}def\u{202C}", 'abcdef' ];
		yield 'bidi isolates and marks removed' => [ "\u{2066}a\u{2069}\u{200F}b\u{200E}", 'ab' ];
		yield 'control characters removed' => [ "a\x00b\x07c\x1Bd\x7F", 'abcd' ];
		yield 'C1 controls removed' => [ "a\u{0085}b\u{009B}c", 'abc' ];
		yield 'zero-width space and BOM removed' => [ "sp\u{200B}am\u{FEFF}", 'spam' ];
		yield 'soft hyphen removed' => [ "sp\u{00AD}am", 'spam' ];
		yield 'emoji ZWJ sequence kept' => [ "👨\u{200D}👩\u{200D}👧", "👨\u{200D}👩\u{200D}👧" ];
		yield 'variation selector kept' => [ "❤\u{FE0F}", "❤\u{FE0F}" ];
		// i18n: Persian needs the zero-width non-joiner
		yield 'zero-width non-joiner kept' => [ "می\u{200C}خواهم", "می\u{200C}خواهم" ];
		yield 'flag of England kept' => [ self::ENGLAND, self::ENGLAND ];
		yield 'mention token kept' => [ "hi <@12>\u{200B}!", 'hi <@12>!' ];
		yield 'hidden tag text removed' => [ 'hi' . self::tags( 'secret' ), 'hi' ];
		yield 'tags after a black flag that are no subdivision code removed' => [
			"🏴" . self::tags( 'Ignore this' ) . "\u{E007F}", "🏴"
		];
		yield 'CRLF to LF' => [ "a\r\nb\rc", "a\nb\nc" ];
		yield 'line separators to LF' => [ "a\u{2028}b", "a\nb" ];
		yield 'tab to space' => [ "a\tb", 'a b' ];
		yield 'blank lines collapsed' => [ "a\n\n\n\n\nb", "a\n\nb" ];
		yield 'trailing spaces on lines' => [ "a   \nb", "a\nb" ];
		yield 'trimmed' => [ "  \n hi \n ", 'hi' ];
		// i18n: the ideographic space, and multibyte characters at the edges
		yield 'ideographic space trimmed' => [ "\u{3000}你好\u{3000}", '你好' ];
		// Regression: byte-wise trimming of U+00A0 (C2 A0) and U+3000 (E3 80 80) cut these
		yield 'emoji at the end' => [ 'rude 😠', 'rude 😠' ];
		yield 'CJK ending in byte 80' => [ '吀', '吀' ];
		yield 'katakana starting with byte E3' => [ 'アニメ', 'アニメ' ];
		yield 'no-break and ideographic spaces trimmed' => [ "\u{00A0}\u{3000}x\u{3000}\u{00A0}", 'x' ];
		yield 'NFC' => [ "e\u{0301}", "\u{00E9}" ];
		yield 'invalid UTF-8 replaced' => [ "a\xC3\x28b", "a\u{FFFD}(b" ];
		// NFC first composes a + U+0301 into U+00E1, then 8 marks stay
		yield 'combining mark stack capped' => [
			'a' . str_repeat( "\u{0301}", 30 ), "\u{00E1}" . str_repeat( "\u{0301}", 8 )
		];
	}

	/**
	 * @dataProvider provideNormalize
	 */
	public function testNormalize( string $input, string $expected ) {
		$this->assertSame( $expected, $this->newProcessor()->normalize( $input ) );
	}

	public static function provideNothingVisible() {
		yield 'Hangul filler' => [ "\u{3164}" ];
		yield 'Hangul choseong filler' => [ "\u{115F}" ];
		yield 'half-width Hangul filler' => [ "\u{FFA0}" ];
		yield 'Braille blank' => [ "\u{2800}" ];
		yield 'zero-width joiner' => [ "\u{200D}" ];
		yield 'zero-width non-joiner' => [ "\u{200C}" ];
		yield 'variation selector' => [ "\u{FE0F}" ];
		yield 'combining grapheme joiner' => [ "\u{034F}" ];
		yield 'tag space' => [ "\u{E0020}" ];
		yield 'combining mark' => [ "\u{0301}" ];
		yield 'several, with spaces and lines' => [ "\u{3164} \u{200D}\n\n\u{2800}\u{3000}\u{0301}" ];
	}

	/**
	 * Regression: these were posted as comments that show nothing. Now they are empty, and the
	 * write module refuses them like empty text.
	 *
	 * @dataProvider provideNothingVisible
	 */
	public function testNothingVisibleIsEmpty( string $input ) {
		$this->assertSame( '', $this->newProcessor()->normalize( $input ) );
	}

	public static function provideSomethingVisible() {
		yield 'letter between fillers' => [ "\u{3164}a\u{3164}" ];
		yield 'punctuation' => [ '.' ];
		yield 'digit' => [ '0' ];
		yield 'symbol with a variation selector' => [ "☺\u{FE0F}" ];
		yield 'emoji' => [ '😀' ];
		// Unassigned in the Unicode tables of older PCRE versions
		yield 'Unicode 16 emoji' => [ "\u{1FAE9}" ];
		yield 'private use character' => [ "\u{E000}" ];
		yield 'only a mention' => [ '<@1>' ];
		// i18n
		yield 'CJK' => [ '好' ];
		yield 'Hangul syllable' => [ '한' ];
	}

	/**
	 * @dataProvider provideSomethingVisible
	 */
	public function testSomethingVisibleIsKept( string $input ) {
		$this->assertSame( $input, $this->newProcessor()->normalize( $input ) );
	}

	public static function provideSameComparisonKey() {
		$base = 'buy my café now';
		yield 'same' => [ $base, $base ];
		yield 'trailing spaces' => [ $base, "$base   " ];
		yield 'NFD' => [ $base, "buy my cafe\u{0301} now" ];
		yield 'case' => [ $base, 'Buy My CAFÉ now' ];
		yield 'double space' => [ $base, 'buy  my café now' ];
		yield 'line break' => [ $base, "buy my\ncafé now" ];
		yield 'zero-width space' => [ $base, "buy\u{200B} my café now" ];
		yield 'zero-width joiner' => [ $base, "buy\u{200D} my café now" ];
		yield 'zero-width non-joiner' => [ $base, "buy\u{200C} my café now" ];
		yield 'variation selector' => [ $base, "buy\u{FE0F} my café now" ];
		yield 'combining grapheme joiner' => [ $base, "buy\u{034F} my café now" ];
		yield 'tag character' => [ $base, "buy\u{E0020} my café now" ];
		yield 'Hangul filler' => [ $base, "buy\u{3164} my café now" ];
		yield 'full-width letter' => [ $base, 'ｂuy my café now' ];
		yield 'no-break space' => [ $base, "buy\u{00A0}my café now" ];
		yield 'emoji with and without a variation selector' => [ '❤ thanks', "❤\u{FE0F} thanks" ];
		// i18n
		yield 'ideographic space' => [ '你好 世界', "你好\u{3000}世界" ];
		yield 'full-width comma' => [ '你好,世界', '你好，世界' ];
		yield 'sharp s' => [ 'STRASSE', 'straße' ];
	}

	/**
	 * Regression: each of these second texts got past the duplicate check
	 *
	 * @dataProvider provideSameComparisonKey
	 */
	public function testSameComparisonKey( string $first, string $second ) {
		$tp = $this->newProcessor();
		$this->assertSame(
			$tp->comparisonKey( $tp->normalize( $first ) ), $tp->comparisonKey( $tp->normalize( $second ) )
		);
	}

	public static function provideDifferentComparisonKey() {
		yield 'accent' => [ 'buy my café now', 'buy my cafe now' ];
		yield 'punctuation' => [ 'buy my café now', 'buy my café now!' ];
		yield 'no space between words' => [ 'buy my café now', 'buymy café now' ];
		yield 'other emoji' => [ '👍', '👎' ];
		// i18n
		yield 'hiragana and katakana' => [ 'ありがとう', 'アリガトウ' ];
		yield 'simplified and traditional' => [ '汉字', '漢字' ];
	}

	/**
	 * @dataProvider provideDifferentComparisonKey
	 */
	public function testDifferentComparisonKey( string $first, string $second ) {
		$tp = $this->newProcessor();
		$this->assertNotSame(
			$tp->comparisonKey( $tp->normalize( $first ) ), $tp->comparisonKey( $tp->normalize( $second ) )
		);
	}

	public function testComparisonKey() {
		$this->assertSame(
			'buy my café now', $this->newProcessor()->comparisonKey( " Ｂuy\u{200D}  MY\u{00A0}café\nnow " )
		);
	}

	public static function provideLength() {
		yield 'ASCII' => [ 'hello', 5 ];
		// i18n: a CJK character counts one
		yield 'CJK' => [ '你好世界', 4 ];
		yield 'emoji is one code point' => [ '😀', 1 ];
		yield 'flag is two' => [ '🇨🇳', 2 ];
		yield 'family is five' => [ "👨\u{200D}👩\u{200D}👧", 5 ];
		yield 'mixed' => [ 'Hi 你好🎂!', 7 ];
	}

	/**
	 * @dataProvider provideLength
	 */
	public function testLength( string $text, int $expected ) {
		$this->assertSame( $expected, $this->newProcessor()->length( $text ) );
	}

	/**
	 * i18n: characters outside the Basic Multilingual Plane (a CJK Extension B ideograph, emoji,
	 * a mathematical letter)
	 */
	public function testFourByteCharactersSurviveNormalize() {
		$text = '𠮷 😀🎉 𝒳';
		$this->assertSame( $text, $this->newProcessor()->normalize( $text ) );
	}
}
