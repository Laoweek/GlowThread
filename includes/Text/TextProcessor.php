<?php

namespace MediaWiki\Extension\GlowThread\Text;

use UtfNormal\Validator;

/**
 * Turns user input into the stored plain-text source, and stored text into segments.
 *
 * Comments are stored as plain text and rendered on read. Segments are the only thing the
 * browser renders: text, links (http and https only) and mentions. No HTML or wikitext is
 * ever produced here, so the client can bind every segment as plain text.
 */
class TextProcessor {

	/** Longest URL that is turned into a link */
	public const MAX_URL_LENGTH = 2048;
	/** Combining marks kept per base character (stops "zalgo" stacks) */
	private const MAX_COMBINING_MARKS = 8;

	/**
	 * Invisible characters that are removed: C0/C1 controls except tab and newline, DEL, bidi
	 * embeddings, overrides, isolates and marks, zero-width space, word joiner, BOM, soft hyphen,
	 * Mongolian vowel separator, interlinear annotation marks, object replacement and the
	 * language tag. Zero-width joiner and non-joiner stay, because emoji and some scripts need them.
	 */
	private const STRIP_PATTERN = '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}'
		. '\x{00AD}\x{061C}\x{180E}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}'
		. '\x{2066}-\x{2069}\x{FEFF}\x{FFF9}-\x{FFFC}\x{E0001}]/u';

	/**
	 * Tag characters, which can spell out ASCII that nobody sees. They are kept only in an emoji
	 * tag sequence: a black flag, a subdivision code in tag letters and digits, and a cancel tag,
	 * as in the flags of England, Scotland and Wales. The others are removed.
	 */
	private const TAG_PATTERN = '/(\x{1F3F4}[\x{E0030}-\x{E0039}\x{E0061}-\x{E007A}]{3,7}\x{E007F})'
		. '|[\x{E0020}-\x{E007F}]/u';

	/**
	 * Characters that show nothing, kept in the text but ignored when deciding whether it is empty
	 * and in comparisonKey(): format characters (zero-width joiner and non-joiner, tags) and the
	 * other default-ignorable code points (variation selectors, the combining grapheme joiner,
	 * Hangul fillers, reserved ones), as in WordFilter, and the Braille blank U+2800. Listed, as
	 * \p{DI} needs PCRE2 10.40.
	 */
	private const IGNORABLE_PATTERN = '/[\p{Cf}\x{034F}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180B}-\x{180F}'
		. '\x{2065}\x{2800}\x{3164}\x{FE00}-\x{FE0F}\x{FFA0}\x{FFF0}-\x{FFF8}\x{E0000}-\x{E0FFF}]+/u';

	/**
	 * A URL is http(s):// followed by characters up to whitespace, quotes, angle brackets, CJK
	 * punctuation (U+3000-U+303F), full-width forms (U+FF00-U+FFEF) or a letter of a script whose
	 * text often follows a URL with no space: Han, kana, Hangul, and Thai, Lao, Khmer and Myanmar,
	 * which put no spaces between words. URLs whose path contains such raw letters are therefore
	 * linked only up to the first one; percent-encoded paths are unaffected.
	 */
	private const URL_PATTERN = '(?i:https?):\/\/[^\s<>"\'\x{3000}-\x{303F}\x{FF00}-\x{FFEF}'
		. '\x{201C}\x{201D}\x{2018}\x{2019}\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}'
		. '\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}]+';

	/**
	 * A mention is a token that names the user by ID: <@123>, the ID in ASCII digits without
	 * leading zeros. Anything else, such as "@Alice", "<@0>", "<@ 1>" or "<@01>", is plain text.
	 * The browser inserts tokens for the users picked from its suggestions, and shows them as
	 * @ and the user's current name.
	 */
	public const MENTION_PATTERN = '/' . self::MENTION_TOKEN . '/';
	private const MENTION_TOKEN = '<@(?<id>[1-9][0-9]{0,9})>';

	/** Mention tokens per text shown as mentions. Later ones show as MENTION_PLACEHOLDER. */
	public const MAX_MENTIONS_SHOWN = 50;

	/**
	 * What a mention of a user who does not exist or is hidden shows instead of a name, and a
	 * mention after the first MAX_MENTIONS_SHOWN
	 */
	public const MENTION_PLACEHOLDER = '@…';

	/**
	 * Normalise raw input into the stored source text: valid UTF-8 in NFC, LF newlines,
	 * no invisible control or bidi characters, no tag characters outside emoji flags, at most
	 * one blank line in a row, trimmed. Text in which nothing would show is empty.
	 */
	public function normalize( string $raw ): string {
		// ASCII controls first: UtfNormal would turn them into U+FFFD. Their bytes never occur
		// inside multi-byte UTF-8 sequences, so a byte-wise pattern is safe on any input.
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $raw ) ?? '';
		$text = Validator::cleanUp( $text );
		$text = str_replace( [ "\r\n", "\r", "\u{2028}", "\u{2029}" ], "\n", $text );
		$text = str_replace( "\t", ' ', $text );
		$text = preg_replace( self::STRIP_PATTERN, '', $text ) ?? '';
		$text = preg_replace( self::TAG_PATTERN, '$1', $text ) ?? '';
		$text = preg_replace(
			'/(\p{M}{' . self::MAX_COMBINING_MARKS . '})\p{M}+/u', '$1', $text
		) ?? '';
		// Trailing spaces on each line, then runs of blank lines
		$text = preg_replace( '/[ \x{00A0}\x{3000}]+$/mu', '', $text ) ?? '';
		$text = preg_replace( "/\n{3,}/", "\n\n", $text ) ?? '';
		// Not trim(): its character list is byte-based and would cut multi-byte characters
		$text = preg_replace( '/^[\p{Z}\n]+|[\p{Z}\n]+$/u', '', $text ) ?? '';
		// Visible: a letter, digit, punctuation, symbol, or a private-use or (to this PCRE) unassigned
		// character such as a newer emoji. Only spaces, marks and IGNORABLE_PATTERN show nothing.
		$visible = preg_replace( self::IGNORABLE_PATTERN, '', $text ) ?? '';
		return preg_match( '/[\p{L}\p{N}\p{P}\p{S}\p{Co}\p{Cn}]/u', $visible ) ? $text : '';
	}

	/**
	 * Form of a text for the duplicate check, in which texts that read the same are equal:
	 * without IGNORABLE_PATTERN, in NFKC (full-width letters and the no-break space become plain
	 * ones), case-folded, each run of white space one space, none at the ends. Only for comparing:
	 * the stored and shown text keeps all of these.
	 *
	 * @param string $text Normalised text
	 * @return string
	 */
	public function comparisonKey( string $text ): string {
		// Invisible characters first, so that they cannot keep a letter and its accent from composing
		$text = preg_replace( self::IGNORABLE_PATTERN, '', $text ) ?? $text;
		$text = mb_convert_case( Validator::toNFKC( $text ), MB_CASE_FOLD, 'UTF-8' );
		// The spaces are ASCII after this, so the byte-based trim() is safe
		return trim( preg_replace( '/[\s\p{Z}]+/u', ' ', $text ) ?? $text, ' ' );
	}

	/**
	 * Length used by the length cap: Unicode code points. A CJK character counts 1, an emoji
	 * counts 1 per code point (a flag counts 2, a family emoji up to 7). The browser must count
	 * the same way: Array.from( text ).length.
	 */
	public function length( string $text ): int {
		return mb_strlen( $text, 'UTF-8' );
	}

	/**
	 * Split stored text into tokens. A mention token (MENTION_PATTERN) of a user that
	 * $existingUsers lists carries the user's ID and current name. A mention of any other user,
	 * and every mention after the first MAX_MENTIONS_SHOWN, is MENTION_PLACEHOLDER with no name
	 * and no ID. Without $existingUsers every mention is the placeholder.
	 *
	 * @param string $text Normalised text
	 * @param array<int,string> $existingUsers User ID => current name, for the users of
	 *   extractMentionIds() who are registered and not hidden (MentionResolver::existenceMapForTexts())
	 * @return array<int,array{type:string,text:string,href?:string,user?:string,id?:int}>
	 */
	public function tokenize( string $text, array $existingUsers = [] ): array {
		$tokens = [];
		$pattern = '/(?<url>' . self::URL_PATTERN . ')|' . self::MENTION_TOKEN . '/u';
		$offset = 0;
		$searchFrom = 0;
		$mentions = 0;
		while ( preg_match( $pattern, $text, $match, PREG_OFFSET_CAPTURE, $searchFrom ) ) {
			[ $whole, $start ] = $match[0];
			$token = null;
			$consumed = $whole;
			$searchFrom = $start + strlen( $whole );
			if ( isset( $match['url'] ) && $match['url'][1] >= 0 && $match['url'][0] !== '' ) {
				$url = $this->trimUrl( $whole );
				if ( $this->isLinkableUrl( $url ) ) {
					$token = [ 'type' => 'link', 'text' => $url, 'href' => $url ];
					$consumed = $url;
				}
			} elseif ( isset( $match['id'] ) && $match['id'][1] >= 0 ) {
				$id = (int)$match['id'][0];
				$name = ++$mentions <= self::MAX_MENTIONS_SHOWN ? $existingUsers[$id] ?? null : null;
				$token = $name === null
					? [ 'type' => 'mention', 'text' => self::MENTION_PLACEHOLDER ]
					: [ 'type' => 'mention', 'text' => '@' . $name, 'user' => $name, 'id' => $id ];
			}
			if ( $token === null ) {
				continue;
			}
			if ( $start > $offset ) {
				$this->appendText( $tokens, substr( $text, $offset, $start - $offset ) );
			}
			$tokens[] = $token;
			$offset = $start + strlen( $consumed );
		}
		if ( $offset < strlen( $text ) ) {
			$this->appendText( $tokens, substr( $text, $offset ) );
		}
		return $tokens;
	}

	/**
	 * Build the segments returned to the browser: the tokens of tokenize(), and on each mention
	 * whether it names a user (false for MENTION_PLACEHOLDER).
	 *
	 * @param string $text Normalised text
	 * @param array<int,string> $existingUsers As for tokenize()
	 * @return array<int,array<string,mixed>>
	 */
	public function toSegments( string $text, array $existingUsers ): array {
		$segments = [];
		foreach ( $this->tokenize( $text, $existingUsers ) as $token ) {
			if ( $token['type'] === 'mention' ) {
				$token['exists'] = isset( $token['user'] );
			}
			$segments[] = $token;
		}
		return $segments;
	}

	/**
	 * URLs to check against SpamBlacklist: the links in the text, in order, then the URLs left as
	 * text because of a user part (see hasUserPart()). Those are checked by the host a browser
	 * would open: a backslash is read as a slash here, and LinkFilter leaves out the user part.
	 *
	 * @return string[] http(s) URLs, without duplicates
	 */
	public function extractUrls( string $text ): array {
		$urls = [];
		foreach ( $this->tokenize( $text ) as $token ) {
			if ( $token['type'] === 'link' && !in_array( $token['href'], $urls, true ) ) {
				$urls[] = $token['href'];
			}
		}
		preg_match_all( '/' . self::URL_PATTERN . '/u', $text, $matches );
		foreach ( $matches[0] as $match ) {
			$url = $this->trimUrl( $match );
			if ( strlen( $url ) <= self::MAX_URL_LENGTH && $this->hasUserPart( $url ) ) {
				$url = str_replace( '\\', '/', $url );
				if ( !in_array( $url, $urls, true ) ) {
					$urls[] = $url;
				}
			}
		}
		return $urls;
	}

	/**
	 * @param string $text Normalised text
	 * @param array<int,string> $existingUsers As for tokenize()
	 * @return string[] Current names of the users the text mentions that $existingUsers lists,
	 *   without duplicates
	 */
	public function extractMentions( string $text, array $existingUsers = [] ): array {
		// A list, not array keys: PHP would turn a numeric name such as "12345" into an int key
		$names = [];
		foreach ( $this->tokenize( $text, $existingUsers ) as $token ) {
			if ( isset( $token['user'] ) && !in_array( $token['user'], $names, true ) ) {
				$names[] = $token['user'];
			}
		}
		return $names;
	}

	/**
	 * User IDs of the mentions that can show as mentions (the first MAX_MENTIONS_SHOWN tokens), to
	 * look up before calling tokenize(), toSegments(), extractMentions() or toPlainText()
	 *
	 * @param string $text Normalised text
	 * @return int[] Without duplicates, in the order mentioned
	 */
	public function extractMentionIds( string $text ): array {
		preg_match_all( self::MENTION_PATTERN, $text, $matches );
		$ids = array_slice( $matches['id'], 0, self::MAX_MENTIONS_SHOWN );
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/**
	 * The text as readers see it, for plain-text renderings such as notification excerpts: each
	 * mention token becomes the text of its tokenize() token.
	 *
	 * @param string $text Normalised text
	 * @param array<int,string> $existingUsers As for tokenize()
	 * @return string
	 */
	public function toPlainText( string $text, array $existingUsers ): string {
		return implode( '', array_column( $this->tokenize( $text, $existingUsers ), 'text' ) );
	}

	/**
	 * The stored form of a mention of a user, for software that writes comments (importers,
	 * seed data, tests)
	 */
	public static function mentionToken( int $userId ): string {
		return "<@$userId>";
	}

	private function appendText( array &$tokens, string $text ): void {
		if ( $text === '' ) {
			return;
		}
		$last = count( $tokens ) - 1;
		if ( $last >= 0 && $tokens[$last]['type'] === 'text' ) {
			$tokens[$last]['text'] .= $text;
		} else {
			$tokens[] = [ 'type' => 'text', 'text' => $text ];
		}
	}

	/**
	 * Drop trailing punctuation that is almost never part of a URL, and a closing bracket
	 * that has no opening partner inside the URL.
	 */
	private function trimUrl( string $url ): string {
		$pairs = [ ')' => '(', ']' => '[', '}' => '{' ];
		// Closing brackets without a partner, counted once and updated as they are dropped:
		// counting again for each dropped character made a long run of ")" cost its length squared
		$unpaired = [];
		foreach ( $pairs as $close => $open ) {
			$unpaired[$close] = substr_count( $url, $close ) - substr_count( $url, $open );
		}
		$end = strlen( $url );
		while ( $end > 0 ) {
			$last = $url[$end - 1];
			if ( strpbrk( $last, '.,;:!?*\'' ) !== false ) {
				$end--;
				continue;
			}
			if ( isset( $pairs[$last] ) && $unpaired[$last] > 0 ) {
				$unpaired[$last]--;
				$end--;
				continue;
			}
			break;
		}
		return substr( $url, 0, $end );
	}

	private function isLinkableUrl( string $url ): bool {
		if ( strlen( $url ) > self::MAX_URL_LENGTH ) {
			return false;
		}
		if ( $this->hasUserPart( $url ) ) {
			return false;
		}
		$parts = parse_url( $url );
		if ( !is_array( $parts ) || !isset( $parts['scheme'] ) || !isset( $parts['host'] ) ) {
			return false;
		}
		$scheme = strtolower( $parts['scheme'] );
		return ( $scheme === 'http' || $scheme === 'https' ) && $parts['host'] !== '';
	}

	/**
	 * Whether the URL has a user@ or user:pass@ part before the host, as in
	 * https://good.example@evil.example/, or a backslash there. Such a URL is not linked: it reads
	 * like one site and goes to another. Browsers read a backslash before the path as a slash and
	 * parse_url() does not, so with one the host a browser opens and the host SpamBlacklist
	 * checks could differ.
	 */
	private function hasUserPart( string $url ): bool {
		return (bool)preg_match( '/^[a-z]+:\/\/[^\/?#]*[@\\\\]/i', $url );
	}
}
