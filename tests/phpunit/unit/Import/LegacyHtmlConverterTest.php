<?php

namespace MediaWiki\Extension\GlowThread\Tests\Unit\Import;

use DOMDocument;
use MediaWiki\Extension\GlowThread\Import\ConversionResult;
use MediaWiki\Extension\GlowThread\Import\LegacyHtmlConverter;
use MediaWiki\Extension\GlowThread\Text\TextProcessor;
use MediaWikiUnitTestCase;

/**
 * Fixtures are hand-written examples of the HTML that FlowThread stores. No real comment text,
 * user name or IP address is used. Text in Chinese, Japanese or Korean is there only where the
 * case covers that script or the markup of a wiki in that language.
 *
 * @covers \MediaWiki\Extension\GlowThread\Import\LegacyHtmlConverter
 * @covers \MediaWiki\Extension\GlowThread\Import\ConversionResult
 */
class LegacyHtmlConverterTest extends MediaWikiUnitTestCase {

	/** The MediaWiki 1.43 parser wrapper, as stored from that version on */
	private const WRAP = '<div class="mw-content-ltr mw-parser-output" lang="en" dir="ltr">';
	/** The users that the examples link to: name => user ID, as FlowThreadImporter looks them up */
	private const USERS = [
		'Kim' => 5, 'Lee' => 6, '小明' => 7, 'Example' => 8, 'Jane Doe' => 9, 'Tester' => 10, '12345' => 11,
	];

	private function newTextProcessor(): TextProcessor {
		return new TextProcessor();
	}

	private function newConverter(): LegacyHtmlConverter {
		return new LegacyHtmlConverter(
			$this->newTextProcessor(),
			'https://wiki.example',
			'/wiki/$1',
			'/index.php',
			// The User namespace on a Chinese wiki: canonical name, local name and aliases
			[ 'User', '用户', '用戶', '使用者' ]
		);
	}

	private function convert( string $html ): ConversionResult {
		return $this->newConverter()->convert( $html );
	}

	/**
	 * The text as stored, with the users in $users
	 *
	 * @param ConversionResult $result
	 * @param array<string,?int> $users As for LegacyHtmlConverter::withMentions()
	 */
	private function finalText( ConversionResult $result, array $users = self::USERS ): string {
		return $this->newConverter()->withMentions( $result->text, $result->mentions, $users );
	}

	/**
	 * @return string[] "code" or "code:detail" for each warning
	 */
	private static function warningList( ConversionResult $result ): array {
		return array_map(
			static fn ( array $w ) => $w['detail'] === '' ? $w['code'] : $w['code'] . ':' . $w['detail'],
			$result->warnings
		);
	}

	public static function provideCleanConversions() {
		// Rows saved before MediaWiki 1.43: FlowThread stripped the wrapper and the single outer paragraph
		yield 'plain text' => [ 'Have a nice weekend!', 'Have a nice weekend!' ];
		yield 'entities decoded once' => [
			'A&amp;B &lt;3 &quot;quoted&quot; &#39;single&#39; &#124; &#58;', 'A&B <3 "quoted" \'single\' | :'
		];
		yield 'escaped entity typed by the author stays an entity' => [ 'Type &amp;lt;b&amp;gt;', 'Type &lt;b&gt;' ];
		yield 'bare ampersand and unknown entity' => [ 'A & B &foo; C', 'A & B &foo; C' ];
		yield 'author line breaks kept' => [ "Line one\nLine two\nLine three", "Line one\nLine two\nLine three" ];
		yield 'blank lines capped at one' => [ "Above\n\n\n\nBelow", "Above\n\nBelow" ];
		yield 'indents and space runs collapse as in HTML' => [
			"As  follows\n   an indented line  \nLast line", "As follows\nan indented line\nLast line"
		];
		yield 'tab becomes a space' => [ "@Someone \tHello", '@Someone Hello' ];
		yield 'IP reply prefix stays text' => [ '@203.0.113.7 Good point', '@203.0.113.7 Good point' ];
		yield 'URL typed in plain text' => [
			"See\nhttps://example.org/v/?a=1&amp;b=2 \nThanks", "See\nhttps://example.org/v/?a=1&b=2\nThanks"
		];
		yield 'old multi-paragraph' => [
			"<p>First paragraph.\n</p><p>Second paragraph.\n</p>", "First paragraph.\n\nSecond paragraph."
		];
		yield 'old wrapper class' => [ "<div class=\"mw-parser-output\"><p>Old wrapper\n</p></div>", 'Old wrapper' ];

		// Rows saved on MediaWiki 1.43 and later: the full wrapper
		yield 'wrapper with one paragraph' => [ self::WRAP . "<p>Keep it up\n</p></div>", 'Keep it up' ];
		yield 'wrapper with blank lines inside' => [
			self::WRAP . "<p>First sentence\n\n\nSecond sentence\nThird sentence\n</p></div>",
			"First sentence\n\nSecond sentence\nThird sentence"
		];
		yield 'wrapper with paragraphs' => [
			self::WRAP . "<p>One\n</p><p>Two\n</p><p>Three\n</p></div>", "One\n\nTwo\n\nThree"
		];
		yield 'wrapper with br and no newline' => [
			self::WRAP . "<p>First line<br />Next line\n</p></div>", "First line\nNext line"
		];
		yield 'br at the start of a paragraph' => [
			self::WRAP . "<p>Hello there!\n</p><p><br />\nAfter that\n</p></div>", "Hello there!\n\nAfter that"
		];
		yield 'br followed by newline is one break' => [
			"Line one<br />\nLine two<br /><br />\nLine three", "Line one\nLine two\n\nLine three"
		];

		// External links
		yield 'free link' => [
			'<a target="_blank" rel="nofollow noreferrer noopener" class="external free" '
				. 'href="https://example.org/t?id=1">https://example.org/t?id=1</a> for details',
			'https://example.org/t?id=1 for details'
		];
		yield 'free link with entity in href' => [
			'<a class="external free" href="https://example.org/v?a=1&amp;b=2">https://example.org/v?a=1&amp;b=2</a>',
			'https://example.org/v?a=1&b=2'
		];
		// CJK: a Han letter or full-width punctuation ends a URL, so no space is added
		yield 'free link followed by CJK needs no space' => [
			'<a class="external free" href="https://example.org/a">https://example.org/a</a>这里',
			'https://example.org/a这里'
		];
		yield 'free link followed by full-width comma' => [
			'<a class="external free" href="https://example.org/a">https://example.org/a</a>，这里',
			'https://example.org/a，这里'
		];
		yield 'free link followed by period and space' => [
			'see <a class="external free" href="https://example.org/a">https://example.org/a</a>. Next',
			'see https://example.org/a. Next'
		];
		yield 'numbered link becomes its URL' => [
			'see <a class="external autonumber" href="https://example.org/files">[1]</a>. Next',
			'see https://example.org/files. Next'
		];
		yield 'text link keeps text and URL' => [
			'The project is on <a class="external text" href="https://example.org/p/9">example.org</a>.',
			'The project is on example.org (https://example.org/p/9).'
		];
		yield 'broken bracket link around a free link' => [
			'[Name <a class="external free" href="https://example.org/x">https://example.org/x</a>]',
			'[Name https://example.org/x]'
		];
		yield 'protocol-relative external link' => [
			'<a class="external text" href="//other.example/x">Elsewhere</a>', 'Elsewhere (https://other.example/x)'
		];
		// CJK: Han letters and a full-width question mark in a URL are percent-encoded
		yield 'text link whose URL holds full-width punctuation' => [
			'<a class="external text" href="https://example.org/搜索？q=1">Search</a>',
			'Search (https://example.org/%E6%90%9C%E7%B4%A2%EF%BC%9Fq=1)'
		];
		yield 'numbered link whose URL ends in a period' => [
			'<a class="external autonumber" href="https://example.org/a.">[2]</a>', 'https://example.org/a%2E'
		];
		yield 'mailto link' => [
			'<a class="external text" href="mailto:a@example.org">Email</a>', 'Email (mailto:a@example.org)'
		];

		// Wiki links
		yield 'blue link' => [
			'See <a href="/wiki/Test_page" title="Test page">Test page</a> first',
			'See Test page (https://wiki.example/wiki/Test_page) first'
		];
		// CJK: a title with full-width parentheses, percent-encoded in the URL
		yield 'title with full-width parentheses' => [
			'<a href="/wiki/%E8%9B%8B%E7%B3%95%EF%BC%88%E6%96%B0%EF%BC%89" title="蛋糕（新）">蛋糕（新）</a>',
			'蛋糕（新） (https://wiki.example/wiki/%E8%9B%8B%E7%B3%95%EF%BC%88%E6%96%B0%EF%BC%89)'
		];
		yield 'title with ASCII parentheses' => [
			'<a href="/wiki/Pie_(apple)" title="Pie (apple)">Pie (apple)</a>, next sentence',
			'Pie (apple) (https://wiki.example/wiki/Pie_%28apple%29), next sentence'
		];
		yield 'title ending in a period' => [
			'<a href="/wiki/Mr." title="Mr.">Mr.</a>', 'Mr. (https://wiki.example/wiki/Mr%2E)'
		];
		yield 'title with a slash' => [
			'(see <a href="/wiki/Bread/Rye" title="Bread/Rye">Bread/Rye</a>) and',
			'(see Bread/Rye (https://wiki.example/wiki/Bread/Rye)) and'
		];
		yield 'link text differs from title' => [
			'<a href="/wiki/Foo" title="Foo">This</a>', 'This (https://wiki.example/wiki/Foo)'
		];
		// CJK: a percent-encoded section name stays encoded
		yield 'section link' => [
			'<a href="/wiki/Foo#%E9%85%8D%E6%96%99" title="Foo">Foo</a>',
			'Foo (https://wiki.example/wiki/Foo#%E9%85%8D%E6%96%99)'
		];
		yield 'red link to an article' => [
			'<a href="/index.php?title=New_page&amp;action=edit&amp;redlink=1" class="new" '
				. 'title="New page (page does not exist)">New page</a>',
			'New page (https://wiki.example/wiki/New_page)'
		];
		yield 'absolute link to this wiki' => [
			'<a href="https://wiki.example/wiki/Foo">Foo</a>', 'Foo (https://wiki.example/wiki/Foo)'
		];
		yield 'protocol-relative link to this wiki' => [
			'<a href="//wiki.example/wiki/Foo">Foo</a>', 'Foo (https://wiki.example/wiki/Foo)'
		];
		yield 'old revision link keeps its query' => [
			'<a href="/index.php?title=Foo&amp;oldid=123">Old version</a>',
			'Old version (https://wiki.example/index.php?title=Foo&oldid=123)'
		];
		yield 'history link keeps its query' => [
			'<a href="/wiki/Foo?action=history">History</a>', 'History (https://wiki.example/wiki/Foo?action=history)'
		];
		yield 'path ending in colon and digits' => [
			'<a href="/wiki/Recipe:1" title="Recipe:1">Recipe:1</a>', 'Recipe:1 (https://wiki.example/wiki/Recipe:1)'
		];
		yield 'link to a wiki file path' => [
			'<a href="/images/a/ab/X.png">Picture</a>', 'Picture (https://wiki.example/images/a/ab/X.png)'
		];
		yield 'anchor without href' => [ '<a name="x">Anchor</a> text', 'Anchor text' ];
		yield 'fragment-only link' => [ 'See <a href="#cite_note-1">[1]</a>', 'See [1]' ];

		// User links become mention tokens of the user (USERS)
		// Localised User namespace: the simplified Chinese name in hrefs and titles as a Chinese wiki
		// writes them, and a CJK user name in a mention
		yield 'red user link with reply prefix' => [
			'@<a href="/index.php?title=%E7%94%A8%E6%88%B7:%E5%B0%8F%E6%98%8E&amp;action=edit&amp;redlink=1" '
				. 'class="new" title="用户:小明（页面不存在）">小明</a> hello',
			'<@7> hello'
		];
		yield 'blue user link inside the wrapper' => [
			self::WRAP . '<p>@<a href="/wiki/%E7%94%A8%E6%88%B7:Example" title="用户:Example">Example</a>'
				. " OK\n</p></div>",
			'<@8> OK'
		];
		yield 'canonical namespace and space in name' => [
			'@<a href="/wiki/User:Jane_Doe" title="User:Jane Doe">Jane Doe</a> hi', '<@9> hi'
		];
		// Localised User namespace: the traditional Chinese alias
		yield 'traditional Chinese namespace' => [
			'@<a href="/wiki/%E7%94%A8%E6%88%B6:Tester" title="用戶:Tester">Tester</a> morning', '<@10> morning'
		];
		yield 'lower-case namespace' => [ '<a href="/wiki/user:Kim">Kim</a>', '<@5>' ];
		yield 'lower-case user name' => [ '<a href="/wiki/User:kim">kim</a>', '<@5>' ];
		yield 'absolute user link' => [ '<a href="https://wiki.example/wiki/User:Kim">Kim</a> hello', '<@5> hello' ];
		yield 'script path user link' => [ '<a href="/index.php/User:Kim">Kim</a>', '<@5>' ];
		// CJK: a token needs no space on either side, also next to a Han letter
		yield 'user link after a CJK letter' => [ '谢谢<a href="/wiki/User:Kim">Kim</a>的帮助', '谢谢<@5>的帮助' ];
		yield 'mention followed by a letter' => [ '@<a href="/wiki/User:Kim">Kim</a>说得对', '<@5>说得对' ];
		yield 'mention followed by full-width comma' => [ '@<a href="/wiki/User:Kim">Kim</a>，对', '<@5>，对' ];
		yield 'two mentions in a row' => [
			'<a href="/wiki/User:Kim">Kim</a><a href="/wiki/User:Lee">Lee</a>', '<@5><@6>'
		];
		yield 'URL right before a mention' => [
			'<a class="external free" href="https://example.org/a">https://example.org/a</a>'
				. '<a href="/wiki/User:Kim">Kim</a>',
			'https://example.org/a <@5>'
		];
		// A token typed as text was plain text in FlowThread, and is a mention from now on
		yield 'typed token' => [ 'Hi &lt;@5&gt;', 'Hi <@5>' ];
		// CJK: the percent-encoded title in the link text matches the href and stays encoded
		yield 'free link to this wiki' => [
			'<a class="external free" href="https://wiki.example/wiki/%E6%B5%8B">'
				. 'https://wiki.example/wiki/%E6%B5%8B</a>',
			'https://wiki.example/wiki/%E6%B5%8B'
		];
		yield 'title with ampersand and plus' => [
			'<a href="/wiki/A%26B%2BC">A&amp;B+C</a>', 'A&B+C (https://wiki.example/wiki/A%26B%2BC)'
		];
		yield 'numeric user name' => [ '@<a href="/wiki/User:12345" title="User:12345">12345</a> OK', '<@11> OK' ];
		yield 'numeric user name by script path' => [ '<a href="/index.php/User:12345">12345</a>', '<@11>' ];
		yield 'reply prefix with a different label' => [
			'@<a href="/wiki/User:Kim">Kimmy</a> OK', 'Kimmy (<@5>) OK'
		];
		yield 'invisible character between mention and letter' => [
			'<a href="/wiki/User:Kim">Kim</a>&#133;thanks', '<@5>thanks'
		];
		yield 'user link after an ASCII letter' => [ 'abc<a href="/wiki/User:Kim">Kim</a>', 'abc<@5>' ];
		// CJK: raw Han letters in a URL path are percent-encoded
		yield 'free link with CJK path is percent-encoded' => [
			'<a class="external free" href="https://zh.example/wiki/中文">https://zh.example/wiki/中文</a>',
			'https://zh.example/wiki/%E4%B8%AD%E6%96%87'
		];
		yield 'mention at the end' => [ 'Thanks @<a href="/wiki/User:Kim">Kim</a>', 'Thanks <@5>' ];
		yield 'mention label differs' => [ '<a href="/wiki/User:Kim">Kimmy</a> said', 'Kimmy (<@5>) said' ];
		yield 'user subpage is a page link' => [
			'<a href="/wiki/User:Kim/Notes">My notes</a>', 'My notes (https://wiki.example/wiki/User:Kim/Notes)'
		];
		yield 'user talk page is a page link' => [
			'<a href="/wiki/User_talk:Kim">Talk</a>', 'Talk (https://wiki.example/wiki/User_talk:Kim)'
		];
		yield 'other wiki user page is external' => [
			'<a class="external text" href="https://other.example/wiki/User:Kim">Kim</a>',
			'Kim (https://other.example/wiki/User:Kim)'
		];

		// Characters kept exactly
		yield 'kaomoji' => [
			"(〃'▽'〃) ╭(ᵔ_&gt;ᵔ)╮ （╯‵□′）╯︵┴─┴ ( &quot;･ω･ﾞ) &lt;(￣3￣)&gt;",
			"(〃'▽'〃) ╭(ᵔ_>ᵔ)╮ （╯‵□′）╯︵┴─┴ ( \"･ω･ﾞ) <(￣3￣)>"
		];
		yield 'kaomoji with combining marks' => [ "Wish (っ'-')╮=͟͟͞͞ hmm", "Wish (っ'-')╮=͟͟͞͞ hmm" ];
		yield 'emoji and modifiers' => [ 'Yay😀👍🏻☺️🇨🇳👨‍👩‍👧', 'Yay😀👍🏻☺️🇨🇳👨‍👩‍👧' ];
		yield 'symbols and full-width forms' => [ '⒈Done 30％ ☆❤ ①②', '⒈Done 30％ ☆❤ ①②' ];
		// Other scripts: traditional Chinese, kana and hangul pass through unchanged
		yield 'traditional, kana and hangul' => [ '繁體中文 あいう カタカナ 한국어', '繁體中文 あいう カタカナ 한국어' ];
		// CJK: ideographic spaces (U+3000) that indent a paragraph are kept
		yield 'ideographic space kept at a line start' => [ "第一行\n　　缩进一段", "第一行\n　　缩进一段" ];
		yield 'zero-width space, word joiner and bidi embedding removed by TextProcessor' => [
			"✧\u{2060}◝\u{2060}(\u{2060}⁰▿⁰)\u{200B} ok\u{202A}", '✧◝(⁰▿⁰) ok'
		];
		yield 'html comment dropped' => [ 'a<!-- hidden -->b', 'ab' ];
		yield 'bare span is silent' => [ '<span>Text</span>', 'Text' ];
	}

	/**
	 * @dataProvider provideCleanConversions
	 */
	public function testCleanConversion( string $html, string $expected ) {
		$result = $this->convert( $html );
		$this->assertSame( $expected, $this->finalText( $result ) );
		$this->assertSame( [], self::warningList( $result ) );
	}

	public static function provideWarnings() {
		yield 'truncated at an emoji' => [
			self::WRAP . '<p>Have a nice weekend', 'Have a nice weekend', [ 'truncated:unclosed div p' ]
		];
		yield 'truncated after a space' => [
			self::WRAP . '<p>Have a nice weekend ', 'Have a nice weekend', [ 'truncated:unclosed div p' ]
		];
		yield 'truncated to nothing' => [ self::WRAP . '<p>', '', [ 'truncated:unclosed div p', 'empty' ] ];
		yield 'truncated inside a tag' => [
			'<p>See <a href="/wiki/Fo', 'See', [ 'truncated:partial tag, unclosed p' ]
		];
		yield 'truncated inside a tag after inline markup' => [
			'<i>Italic<a hre', 'Italic', [ 'truncated:partial tag, unclosed i', 'formatting-dropped:i' ]
		];
		yield 'truncated inside a link' => [
			'See <a href="/wiki/Foo">Fo', 'See Fo (https://wiki.example/wiki/Foo)', [ 'truncated:unclosed a' ]
		];
		yield 'one emoji as question marks' => [
			'Have a nice weekend????', 'Have a nice weekend????', [ 'question-marks:4' ]
		];
		yield 'three emoji as question marks' => [
			'Me too????????????', 'Me too????????????', [ 'question-marks:12' ]
		];
		yield 'two runs' => [ 'Wow????, so cool????!', 'Wow????, so cool????!', [ 'question-marks:4,4' ] ];
		// i18n: a full-width question mark next to a run is not part of it
		yield 'full-width question mark not counted' => [ 'Really????？', 'Really????？', [ 'question-marks:4' ] ];
		yield 'markup typed as text' => [
			self::WRAP . "<p>First line!&lt;br/&gt;\nSecond line\n</p></div>", "First line!<br/>\nSecond line",
			[ 'literal-markup' ]
		];
		// The heimu spoiler template's markup, with its Chinese tooltip
		yield 'spoiler span' => [
			'The answer is <span title="你知道得太多了&#124;v0" class="heimu">a secret</span>',
			'The answer is a secret',
			[ 'spoiler-revealed' ]
		];
		yield 'bold, italic and strike' => [
			"<b>Bold</b> and <i>italic</i><br />\n<del>deleted</del>",
			"Bold and italic\ndeleted",
			[ 'formatting-dropped:b', 'formatting-dropped:i', 'formatting-dropped:del' ]
		];
		yield 'styled span' => [ '<span style="color:red">Red</span>', 'Red', [ 'formatting-dropped:span' ] ];
		yield 'list' => [
			'<ul><li>One</li><li>Two</li></ul>', "One\nTwo",
			[ 'formatting-dropped:ul', 'formatting-dropped:li' ]
		];
		yield 'external image' => [
			"<p>See the picture:\n</p><p><img src=\"https&#58;//img.example/a.png\" width=\"150px\" />\n</p>",
			'See the picture:',
			[ 'image-dropped:https://img.example/a.png' ]
		];
		yield 'empty image and image with alt' => [
			'Done.<img /><img src="https://img.example/face.png" alt="face.png" /> Next',
			'Done. Next',
			[ 'image-dropped', 'image-dropped:https://img.example/face.png' ]
		];
		yield 'script and style' => [
			'a<script>alert(1)</script>b<style>p{color:red}</style>c',
			'abc',
			[ 'element-dropped:script', 'element-dropped:style' ]
		];
		// CJK: the free link took in the full-width punctuation and Han text after the URL
		yield 'free link that swallowed full-width text' => [
			'<a class="external free" href="https://example.org/r?t=5，后面的话（">https://example.org/r?t=5，后面的话（</a>',
			'https://example.org/r?t=5，后面的话（',
			[ 'link-changed:https://example.org/r?t=5' ]
		];
		yield 'free link ending in an apostrophe' => [
			'<a class="external free" href="https://example.org/it\'">https://example.org/it\'</a>',
			"https://example.org/it'",
			[ 'link-changed:https://example.org/it' ]
		];
		yield 'image inside a link to its file page' => [
			'<a href="/wiki/File:X.png" class="image"><img src="/images/x.png" /></a>',
			'https://wiki.example/wiki/File:X.png',
			[ 'image-dropped:/images/x.png' ]
		];
		yield 'iframe' => [
			'Video<iframe src="https://video.example/1"></iframe>', 'Video', [ 'element-dropped:iframe' ]
		];
		yield 'invalid UTF-8' => [ "abc\xFFdef", "abc\u{FFFD}def", [ 'invalid-utf8' ] ];
		yield 'stray end tag' => [ '<p>a</b>b</p>', 'ab', [ 'unparseable:Unexpected end tag : b' ] ];
		yield 'mis-nested tags' => [
			'<p><b>a</p></b>c', "a\n\nc",
			[
				'unparseable:Opening and ending tag mismatch: p and b',
				'unparseable:Unexpected end tag : b',
				'formatting-dropped:b',
			]
		];
	}

	/**
	 * @dataProvider provideWarnings
	 */
	public function testConversionWithWarnings( string $html, string $expected, array $warnings ) {
		$result = $this->convert( $html );
		$this->assertSame( $expected, $result->text );
		$this->assertSame( $warnings, self::warningList( $result ) );
		foreach ( $warnings as $warning ) {
			$this->assertTrue( $result->hasWarning( explode( ':', $warning, 2 )[0] ) );
		}
	}

	public static function provideDeepNesting() {
		// libxml stops at 256 nested elements and drops everything deeper
		$deep = static fn ( string $open, string $close, string $inner ): string =>
			str_repeat( $open, 300 ) . $inner . str_repeat( $close, 300 );
		yield 'text and links inside 300 bold elements' => [
			$deep( '<b>', '</b>', 'See <a class="external free" href="https://example.org/a">https://example.org/a</a>'
				. ' and <a href="/wiki/Foo">Foo</a>' ),
			'See https://example.org/a and Foo (https://wiki.example/wiki/Foo)',
			[ 'formatting-dropped:b' ]
		];
		yield 'paragraphs, line breaks, mention and spoiler keep their meaning' => [
			self::WRAP . '<p>@<a href="/wiki/User:Kim">Kim</a> Hello' . "\n</p><p>"
				. $deep( '<span style="color:red">', '</span>',
					'First line<br />' . "\n" . '<span class="heimu">Secret</span>' )
				. "\n</p><p>Bye\n</p></div>",
			"<@5> Hello\n\nFirst line\nSecret\n\nBye",
			[ 'formatting-dropped:span', 'spoiler-revealed' ]
		];
		yield 'element names with a colon (libxml reads o:p as p)' => [
			$deep( '<o:p>', '</o:p>', 'Text <a href="https://example.org/z">z</a>' ),
			'Text z (https://example.org/z)',
			[]
		];
		yield 'end tags that libxml ignores' => [
			str_repeat( '<b><div></b>', 300 ) . 'Still here', 'Still here', [ 'formatting-dropped:b' ]
		];
		yield 'truncated inside the nesting' => [
			str_repeat( '<i>', 300 ) . 'Cut off',
			'Cut off',
			[ 'truncated:unclosed ' . implode( ' ', array_fill( 0, 300, 'i' ) ), 'formatting-dropped:i' ]
		];
	}

	/**
	 * @dataProvider provideDeepNesting
	 */
	public function testDeepNestingKeepsTheText( string $html, string $expected, array $otherWarnings ) {
		$result = $this->convert( $html );
		$this->assertSame( $expected, $this->finalText( $result ) );
		$this->assertFalse( $result->hasWarning( LegacyHtmlConverter::WARN_EMPTY ) );
		$this->assertTrue( $result->hasWarning( LegacyHtmlConverter::WARN_UNPARSEABLE ), 'Still listed for review' );
		$this->assertSame(
			$otherWarnings,
			array_values( array_filter(
				self::warningList( $result ), static fn ( string $w ) => !str_starts_with( $w, 'unparseable:' )
			) )
		);
	}

	public function testDeepNestingStaysSafe() {
		$inner = '<a href="javascript:alert(1)">b</a><script>alert(2)</script><style>p{color:red}</style>'
			. '<svg><a href="https://example.org/svg">svg</a><svg></svg>inner</svg>'
			. '<a href="https://example.org/x">x<a href="https://example.org/y">y</a></a>'
			. '<!-- <script> -->&lt;i&gt;&amp;ext;<img src="file:///etc/passwd" />';
		$result = $this->convert( str_repeat( '<div>', 300 ) . $inner . str_repeat( '</div>', 300 ) );
		$this->assertSame(
			'b (javascript:alert(1)) x (https://example.org/x) y (https://example.org/y)<i>&ext;', $result->text
		);
		$this->assertSame( $this->convert( $inner )->text, $result->text, 'Same text as without the nesting' );
		$links = [];
		foreach ( $this->newTextProcessor()->tokenize( $result->text ) as $token ) {
			if ( $token['type'] === 'link' ) {
				$links[] = $token['href'];
			}
		}
		$this->assertSame( [ 'https://example.org/x', 'https://example.org/y' ], $links, 'javascript: stays text' );
		$this->assertSame( [
			'unparseable:Excessive depth in document: 256 use XML_PARSE_HUGE option',
			'element-dropped:script',
			'element-dropped:style',
			'element-dropped:svg',
			'image-dropped:file:///etc/passwd',
			'literal-markup',
		], self::warningList( $result ) );
	}

	public function testTextStaysWhenFlatteningIsNotEnough() {
		// A raw ">" inside an attribute (MediaWiki escapes it) ends the tag early for the flattening,
		// so the second parse stops too and only the text is kept
		$result = $this->convert( str_repeat( '<span title="a>b">', 300 )
			. 'Text <a href="https://example.org/z">z</a>' . str_repeat( '</span>', 300 ) );
		$this->assertSame( 'Text z', $result->text );
		$this->assertFalse( $result->hasWarning( LegacyHtmlConverter::WARN_EMPTY ) );
		$this->assertContains( 'unparseable:tags stripped, links lost', self::warningList( $result ) );
	}

	public function testQuestionMarksBelowFourAreNotFlagged() {
		$this->assertSame( [], $this->convert( 'Really???' )->warnings );
	}

	public function testNameThatIsNoUserStaysText() {
		$result = $this->convert( '@<a href="/wiki/User:Kim!" title="User:Kim!">Kim!</a> hi' );
		$this->assertSame( '@Kim! hi', $this->finalText( $result ), 'Plain text, not a mention' );
		$this->assertSame( [ 'Kim!' ], $result->mentions );
		$this->assertSame( [], $result->warnings );
	}

	public function testHiddenUserLeavesNoName() {
		$result = $this->convert( '@<a href="/wiki/User:Kim">Kim</a> hi, <a href="/wiki/User:Kim">Kimmy</a>' );
		$this->assertSame( '@… hi, Kimmy (@…)', $this->finalText( $result, [ 'Kim' => null ] ) );
	}

	public function testNumericMentionIsAString() {
		$this->assertSame( [ '12345' ], $this->convert( '<a href="/wiki/User:12345">12345</a>' )->mentions );
	}

	public function testTypedNamesStayText() {
		$result = $this->convert( '@Bob hello, <a href="/wiki/User:Kim">Kim</a>' );
		$this->assertSame( '@Bob hello, <@5>', $this->finalText( $result ) );
		$this->assertSame( [ 'Kim' ], $result->mentions );
	}

	public function testFeaturesAndMentions() {
		// A red link in the localised User namespace (用户), with a CJK user name
		$result = $this->convert(
			self::WRAP . '<p>@<a class="new" '
				. 'href="/index.php?title=%E7%94%A8%E6%88%B7:%E5%B0%8F%E6%98%8E&amp;action=edit&amp;redlink=1">小明</a>'
				. ' and <a href="/wiki/User:kim">kim</a>, see <a href="/wiki/Foo">Foo</a>'
				. '<br />and <a class="external free" href="https://example.org/">https://example.org/</a>'
				. "\n</p><p>Bye\n</p></div>"
		);
		$this->assertSame(
			"<@7> and <@5>, see Foo (https://wiki.example/wiki/Foo)\nand https://example.org/\n\nBye",
			$this->finalText( $result )
		);
		$this->assertSame( [ '小明', 'Kim' ], $result->mentions );
		$this->assertSame( [
			LegacyHtmlConverter::FEATURE_WRAPPER => 1,
			LegacyHtmlConverter::FEATURE_BLOCK => 2,
			LegacyHtmlConverter::FEATURE_RED_LINK => 1,
			LegacyHtmlConverter::FEATURE_USER_LINK => 2,
			LegacyHtmlConverter::FEATURE_INTERNAL_LINK => 1,
			LegacyHtmlConverter::FEATURE_LINE_BREAK => 1,
			LegacyHtmlConverter::FEATURE_EXTERNAL_LINK => 1,
		], $result->features );
		$this->assertSame( [], $result->warnings );
	}

	public function testOutputParsesBackIntoTheSameLinksAndMentions() {
		$tp = $this->newTextProcessor();
		// CJK: Han letters and full-width punctuation right before and after each link and mention
		$result = $this->convert(
			'回复<a href="/wiki/User:Kim">Kim</a>：是<a href="/wiki/%E6%B5%8B%E8%AF%95">测试</a>吗？'
				. '<a href="/wiki/Pie_(apple)">派</a>，<a class="external free" href="https://example.org/a">'
				. 'https://example.org/a</a>好的 @<a href="/wiki/User:Lee">Lee</a>'
		);
		$links = [];
		$mentions = [];
		foreach ( $tp->tokenize( $this->finalText( $result ), array_flip( self::USERS ) ) as $token ) {
			if ( $token['type'] === 'link' ) {
				$links[] = $token['href'];
			} elseif ( $token['type'] === 'mention' ) {
				$mentions[] = $token['user'];
			}
		}
		$this->assertSame( [
			'https://wiki.example/wiki/%E6%B5%8B%E8%AF%95',
			'https://wiki.example/wiki/Pie_%28apple%29',
			'https://example.org/a',
		], $links );
		$this->assertSame( [ 'Kim', 'Lee' ], $mentions );
	}

	public function testNeverOutputsMarkupFromTheDocument() {
		$result = $this->convert(
			self::WRAP . '<p><b onclick="x()">a</b><a href="javascript:alert(1)">b</a>'
				. '<img src=x onerror=alert(1)><svg><script>alert(2)</script></svg>' . "\n</p></div>"
		);
		$this->assertSame( 'ab (javascript:alert(1))', $result->text );
		$this->assertStringNotContainsString( '<', $result->text );
	}

	public function testDoesNotExpandEntitiesOrFetchAnything() {
		$result = $this->convert(
			'<!DOCTYPE x [<!ENTITY ext SYSTEM "file:///etc/passwd">]><p>&ext;<img src="file:///etc/passwd"></p>'
		);
		$this->assertStringNotContainsString( 'root:', $result->text );
		$this->assertStringContainsString( '&ext;', $result->text, 'The entity stays literal text' );
	}

	public function testUtf8SurvivesLibxml() {
		// Multibyte UTF-8: emoji, Han, kana and hangul
		$text = 'Have a nice weekend!😀 繁體 あ 한 ☺️';
		$this->assertSame( $text, $this->convert( $text )->text );
		$this->assertSame( $text, $this->convert( self::WRAP . "<p>$text\n</p></div>" )->text );

		// Without a declared encoding libxml reads the bytes as ISO-8859-1 and this test would fail
		$naive = new DOMDocument();
		$internal = libxml_use_internal_errors( true );
		$naive->loadHTML( "<p>$text</p>" );
		libxml_clear_errors();
		libxml_use_internal_errors( $internal );
		$this->assertNotSame( $text, $naive->textContent, 'libxml mangles undeclared UTF-8' );
	}

	public function testLibxmlStateIsRestored() {
		$before = libxml_use_internal_errors( false );
		try {
			$this->convert( '<p>a</b></p>' );
			$this->assertFalse( libxml_use_internal_errors( false ) );
			$this->assertSame( [], libxml_get_errors() );

			// A caller that collects its own errors keeps them
			libxml_use_internal_errors( true );
			( new DOMDocument() )->loadHTML( '<p>x</i></p>' );
			$pending = libxml_get_errors();
			$this->assertNotSame( [], $pending );
			$result = $this->convert( '<p>a</b></p>' );
			$this->assertTrue( libxml_use_internal_errors( true ) );
			$this->assertEquals( $pending, array_slice( libxml_get_errors(), 0, count( $pending ) ) );
			$this->assertSame(
				[ 'unparseable:Unexpected end tag : b' ], self::warningList( $result ), 'Only its own errors count'
			);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $before );
		}
	}

	public function testMarkersInInputDoNotLeak() {
		$result = $this->convert( "a\u{FDD0}b\u{FDD1}0\u{FDD2}c" );
		$this->assertSame( 'ab0c', $result->text );
		$this->assertSame( 'ab0c', $this->finalText( $result ) );
	}

	public function testImagesCanBecomeLinks() {
		$converter = new LegacyHtmlConverter(
			$this->newTextProcessor(), 'https://wiki.example', '/wiki/$1', '/index.php', [ 'User' ], true
		);
		// CJK: a Han letter in the file name is percent-encoded
		$result = $converter->convert(
			'Picture<img src="https&#58;//img.example/图.png" /> and<img src="/images/a.png" /><img />'
		);
		$this->assertSame(
			'Picture https://img.example/%E5%9B%BE.png and https://wiki.example/images/a.png', $result->text
		);
		$this->assertSame(
			[ LegacyHtmlConverter::WARN_IMAGE_DROPPED ], $result->getWarningCodes(), 'Still reported'
		);
	}

	public function testConverterIsReusable() {
		$converter = $this->newConverter();
		$first = $converter->convert( self::WRAP . '<p>@<a href="/wiki/User:Kim">Kim</a> one' );
		$second = $converter->convert( 'two' );
		$this->assertSame( '<@5> one', $this->finalText( $first ) );
		$this->assertSame( 'two', $second->text );
		$this->assertSame( [], $second->warnings );
		$this->assertSame( [], $second->features );
		$this->assertSame( [], $second->mentions );
	}
}
