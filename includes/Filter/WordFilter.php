<?php

namespace MediaWiki\Extension\GlowThread\Filter;

use MediaWiki\Content\TextContent;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageLookup;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use Normalizer;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * Word filter read from the page MediaWiki:GlowThread-filter (editable with the editinterface
 * right, with page history). When the page does not exist there are no rules.
 * Its default text, shown when the page is first edited, is the message glowthread-filter,
 * which only explains the syntax.
 *
 * Each line is one rule:
 * - empty lines and lines starting with # are ignored
 * - /pattern/flags is a PCRE regex (flags from imsx, u is always added), run on the text as stored
 * - anything else is a literal. Literals and the text are compared after fold(): NFKC, case
 *   folding, no invisible characters such as zero-width spaces, no stray combining marks, and no
 *   accents unless the literal has them: rule "spam" matches "spàm", rule "lồn" does not match "lớn".
 *   - A literal with a letter of UNSPACED_SCRIPTS, or with no letter or digit at all (an emoji),
 *     matches anywhere, also in the text with spaces, punctuation and symbols removed, so that
 *     "傻 * 瓜" matches "傻瓜". The text keeps CLAUSE_END, so "我很傻。瓜子" does not.
 *   - Any other literal matches whole words only: rule "ass" matches "Ass!" but not "class". Its
 *     words (runs of letters, digits and marks) match in order with any separators (spaces,
 *     punctuation, symbols) between them, or none, so "cheap coins" also matches "cheapcoins".
 *     The letters of each word may also be written one by one with separators between them
 *     ("a s s", "a.s.s"), but a word is never split elsewhere, so "it's hit" does not match "shit".
 *     Spaced-out letters do not go on from a lower-case letter to spaced-out capitals, so "Got a
 *     S S rank" does not match "ass".
 *
 * Every rule is compiled and run on its own, so one bad line cannot disable the others.
 * Invalid lines are skipped and reported. A regex that fails at run time (for example the
 * backtrack limit) counts as an error, and the caller holds the comment instead of passing it.
 *
 * Every post runs every rule, so at most MAX_RULES rule lines are read, each regex runs under
 * REGEX_MATCH_LIMIT, and the regexes (regex rules and whole-word literals) left when the check has
 * taken REGEX_TIME_BUDGET are not run but count as errors. One regex made of many greedy scans can
 * still take longer: PCRE does not count them.
 */
class WordFilter {

	/** Title (in the MediaWiki namespace) of the rules page */
	public const RULES_PAGE = 'GlowThread-filter';

	/**
	 * Most rule lines read, valid or not. The first line after them is reported as invalid
	 * (too-many-rules), and it and the lines after it are ignored.
	 */
	public const MAX_RULES = 2000;

	/**
	 * Seconds after which check() runs no more regexes and reports each of their rules as an error
	 * (time-limit): the backtrack limit does not stop every slow pattern, such as many (?=.*x).
	 */
	public const REGEX_TIME_BUDGET = 0.25;

	/**
	 * pcre.backtrack_limit (PCRE's match limit) while the rules run, unless the site's is lower. PCRE
	 * counts against it over the whole text, with JIT and without, so a regex that would backtrack
	 * for seconds fails at once and counts as time-limit. Regexes measured on a 10000-character
	 * comment needed at most 20000. PCRE does not count a greedy scan over single characters, such
	 * as \w+ before \s, so a regex with many of them can still be slow.
	 */
	public const REGEX_MATCH_LIMIT = 100000;

	/** preg_last_error() values of a regex stopped by a PCRE limit */
	private const LIMIT_ERRORS = [ PREG_BACKTRACK_LIMIT_ERROR, PREG_RECURSION_LIMIT_ERROR, PREG_JIT_STACKLIMIT_ERROR ];

	/**
	 * Scripts whose words are not divided by spaces (Han, kana, Thai, Lao, Khmer, Myanmar), and
	 * Hangul, whose particles join the word before them. The same list as in TextProcessor.
	 */
	private const UNSPACED_SCRIPTS = '\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}';

	/**
	 * A character that continues a word: a letter, mark or digit, except of UNSPACED_SCRIPTS, which
	 * need no space before a word ("这个spam链接")
	 */
	private const WORD_CHAR = '(?![' . self::UNSPACED_SCRIPTS . '])[\p{L}\p{M}\p{N}]';

	/** A separator between words: anything but a letter, mark or digit */
	private const SEPARATOR = '[^\p{L}\p{M}\p{N}]';

	/**
	 * Put by fold() between a lower-case letter with the separators after it and spaced-out
	 * capitals ("a S S"), where letters spaced out one by one do not go on. An invisible character,
	 * so the text has no other copy of it after fold().
	 */
	private const CASE_BREAK = "\u{2063}";

	/** A separator between the letters of a spaced-out word: SEPARATOR, but not CASE_BREAK */
	private const LETTER_SEPARATOR = '[^\p{L}\p{M}\p{N}\x{2063}]';

	/**
	 * Letters whose combining marks are accents, which fold() can drop: the same word is also
	 * written without them ("à", "ё", Arabic and Hebrew vowel points). Han has none of its own. In
	 * other scripts, such as Thai, Devanagari and kana, marks write vowels, tones or voicing and stay.
	 */
	private const ACCENTED_SCRIPTS = '\p{Latin}\p{Greek}\p{Cyrillic}\p{Arabic}\p{Hebrew}\p{Han}';

	/**
	 * Combining marks that fold() drops: a run of marks that is not on a letter of a script outside
	 * ACCENTED_SCRIPTS (so also a run on a symbol or nothing) or on a keycap base (0-9, #, *), and
	 * anywhere the generic combining marks (accents, strokes, enclosing circles but the keycap),
	 * which no script needs to spell a word. Listed by block, as \p{Inherited} also has the kana
	 * voicing marks. In decomposed text this drops accents, in composed text only the marks that
	 * compose with nothing.
	 */
	private const ACCENT_PATTERN = '/(?<!\p{M})(?<![0-9#*])(?<!(?![' . self::ACCENTED_SCRIPTS . '])\p{L})\p{M}+'
		. '|[\x{0300}-\x{036F}\x{1AB0}-\x{1AFF}\x{1DC0}-\x{1DFF}\x{20D0}-\x{20E2}\x{20E4}-\x{20FF}'
		. '\x{FE20}-\x{FE2F}]+/u';

	/**
	 * What ends a sentence, clause or quote in text without spaces: line breaks, ideographic
	 * punctuation, CJK brackets (〈〉《》「」『』【】〔〕〖〗〘〙〚〛), curly quotes, and the Myanmar and
	 * Khmer marks. squash() keeps it in the text, so that a rule does not match across it. ASCII
	 * punctuation, which is easy to type between two letters, does not count.
	 */
	private const CLAUSE_END = '\n\x{3001}\x{3002}\x{3008}-\x{3011}\x{3014}-\x{301B}\x{2018}\x{2019}\x{201C}\x{201D}'
		. '\x{104A}\x{104B}\x{17D4}\x{17D5}';

	/**
	 * Full-width ！，．：；？ and brackets （）［］｛｝｟｠, which fold() turns into 。 (CLAUSE_END)
	 * before NFKC turns them into ASCII
	 */
	private const FULL_WIDTH_CLAUSE_END = '/[\x{FF01}\x{FF08}\x{FF09}\x{FF0C}\x{FF0E}\x{FF1A}\x{FF1B}\x{FF1F}'
		. '\x{FF3B}\x{FF3D}\x{FF5B}\x{FF5D}\x{FF5F}\x{FF60}]/u';

	/**
	 * Invisible characters, removed before matching: format characters (zero-width space, soft
	 * hyphen, bidi marks) and the other default-ignorable code points (variation selectors, the
	 * combining grapheme joiner, Hangul fillers, reserved ones). Listed, as \p{DI} needs PCRE2 10.40.
	 */
	private const INVISIBLE_PATTERN = '/[\p{Cf}\x{034F}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180B}-\x{180F}'
		. '\x{2065}\x{3164}\x{FE00}-\x{FE0F}\x{FFA0}\x{FFF0}-\x{FFF8}\x{E0000}-\x{E0FFF}]+/u';

	/** @var array[] Parsed rules by rules text (the on-wiki page and a draft), cached for this request */
	private array $parsed = [];

	/** @var string|null Rules text of the wiki page, cached for this request */
	private ?string $rulesText = null;

	public function __construct(
		private readonly PageLookup $pageLookup,
		private readonly RevisionLookup $revisionLookup,
		/** Core's main WAN object cache when null */
		private readonly ?WANObjectCache $wanCache = null
	) {
	}

	/**
	 * Raw rules text of the current revision of the rules page, empty if there is none.
	 *
	 * The current revision is looked up as always, and its text is cached by revision ID (the
	 * text of a revision never changes, and that of the current one cannot be hidden), which
	 * saves loading its content on every comment.
	 */
	public function getRulesText(): string {
		if ( $this->rulesText === null ) {
			$page = $this->pageLookup->getPageByName( NS_MEDIAWIKI, self::RULES_PAGE );
			$revision = $page ? $this->revisionLookup->getRevisionByTitle( $page ) : null;
			if ( !$revision ) {
				$this->rulesText = '';
			} elseif ( $revision->getId() ) {
				$wanCache = $this->wanCache ?? MediaWikiServices::getInstance()->getMainWANObjectCache();
				$this->rulesText = $wanCache->getWithSetCallback(
					$wanCache->makeKey( 'glowthread-filter-rules', $revision->getId() ),
					WANObjectCache::TTL_DAY,
					static fn () => self::getText( $revision )
				);
			} else {
				$this->rulesText = self::getText( $revision );
			}
		}
		return $this->rulesText;
	}

	private static function getText( RevisionRecord $revision ): string {
		$content = $revision->getContent( SlotRecord::MAIN );
		return $content instanceof TextContent ? $content->getText() : '';
	}

	/**
	 * @param string|null $rulesText Rules to use instead of the wiki page (for testing a draft)
	 * @return array{rules:array[],invalid:array[]} Rules (line, rule, type, pattern) and invalid lines
	 *   (line, rule, error)
	 */
	public function parse( ?string $rulesText = null ): array {
		$rulesText ??= $this->getRulesText();
		if ( isset( $this->parsed[$rulesText] ) ) {
			return $this->parsed[$rulesText];
		}
		$rules = [];
		$invalid = [];
		$lines = preg_split( '/\r\n|\r|\n/', $rulesText ) ?: [];
		foreach ( $lines as $index => $line ) {
			$lineNo = $index + 1;
			$line = trim( $line );
			if ( $line === '' || str_starts_with( $line, '#' ) ) {
				continue;
			}
			if ( count( $rules ) + count( $invalid ) >= self::MAX_RULES ) {
				$invalid[] = [ 'line' => $lineNo, 'rule' => $line, 'error' => 'too-many-rules' ];
				break;
			}
			if ( preg_match( '/^\/(.+)\/([a-zA-Z]*)$/s', $line, $m ) ) {
				$error = null;
				$pattern = $this->compileRegex( $m[1], $m[2], $error );
				if ( $pattern === null ) {
					$invalid[] = [ 'line' => $lineNo, 'rule' => $line, 'error' => $error ?? 'invalid' ];
					continue;
				}
				$rules[] = [ 'line' => $lineNo, 'rule' => $line, 'type' => 'regex', 'pattern' => $pattern ];
			} else {
				// A literal written with accents matches only text with the same accents
				$literal = self::fold( $line, true );
				$accents = $literal !== self::fold( $line );
				$rule = [ 'line' => $lineNo, 'rule' => $line, 'type' => 'literal', 'accents' => $accents ];
				if ( preg_match( '/[\p{L}\p{N}]/u', $literal )
					&& !preg_match( '/(?=\p{L})[' . self::UNSPACED_SCRIPTS . ']/u', $literal )
				) {
					// A very long rule can exceed the size of a pattern PCRE compiles
					$error = null;
					$pattern = $this->compileRegex( self::wordsRegex( $literal ), '', $error );
					if ( $pattern === null ) {
						$invalid[] = [ 'line' => $lineNo, 'rule' => $line, 'error' => $error ?? 'invalid' ];
						continue;
					}
					$rule += [ 'words' => true, 'pattern' => $pattern ];
				} else {
					// trim() keeps no-break and ideographic spaces, which NFKC turns into plain spaces: a line
					// of them would match every comment with a space
					$literal = preg_replace( '/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $literal ) ?? $literal;
					$rule += [ 'words' => false, 'pattern' => $literal, 'squashed' => self::squash( $literal, false ) ];
				}
				$rules[] = $rule;
			}
		}
		$this->parsed[$rulesText] = [ 'rules' => $rules, 'invalid' => $invalid ];
		return $this->parsed[$rulesText];
	}

	/**
	 * Run the filter.
	 *
	 * @param string $text Comment text normalised by TextProcessor::normalize(), as posting and the
	 *   filter test both do
	 * @param string|null $rulesText Rules to use instead of the wiki page
	 * @return array{matched:bool,error:bool,matches:array[],errors:array[]} Matches (line, rule, type)
	 *   and run-time errors (line, rule, error)
	 */
	public function check( string $text, ?string $rulesText = null ): array {
		$parsed = $this->parse( $rulesText );
		// The text without accents, and with them for the literals that have accents
		$folded = [ self::fold( $text ), self::fold( $text, true ) ];
		$squashed = [ self::squash( $folded[0], true ), self::squash( $folded[1], true ) ];
		$matches = [];
		$errors = [];
		$deadline = hrtime( true ) + (int)( self::REGEX_TIME_BUDGET * 1e9 );
		// Restored afterwards, so that the rest of MediaWiki keeps the site's limit
		$siteLimit = ini_get( 'pcre.backtrack_limit' );
		$lowerLimit = (int)$siteLimit > self::REGEX_MATCH_LIMIT;
		if ( $lowerLimit ) {
			ini_set( 'pcre.backtrack_limit', (string)self::REGEX_MATCH_LIMIT );
		}
		try {
			foreach ( $parsed['rules'] as $rule ) {
				$accents = (int)( $rule['accents'] ?? false );
				if ( $rule['type'] === 'literal' && !$rule['words'] ) {
					// An empty pattern (a rule of only spaces and invisible characters) matches nothing
					$hit = ( $rule['pattern'] !== '' && str_contains( $folded[$accents], $rule['pattern'] ) )
						|| ( mb_strlen( $rule['squashed'] ) >= 2
							&& str_contains( $squashed[$accents], $rule['squashed'] ) );
				} elseif ( hrtime( true ) > $deadline ) {
					$errors[] = [ 'line' => $rule['line'], 'rule' => $rule['rule'], 'error' => 'time-limit' ];
					continue;
				} else {
					$result = preg_match( $rule['pattern'], $rule['type'] === 'literal' ? $folded[$accents] : $text );
					if ( $result === false ) {
						// A PCRE limit stops a regex that would run too long, as the time budget does
						$error = in_array( preg_last_error(), self::LIMIT_ERRORS, true )
							? 'time-limit'
							: preg_last_error_msg();
						$errors[] = [ 'line' => $rule['line'], 'rule' => $rule['rule'], 'error' => $error ];
						continue;
					}
					$hit = $result === 1;
				}
				if ( $hit ) {
					$matches[] = [ 'line' => $rule['line'], 'rule' => $rule['rule'], 'type' => $rule['type'] ];
				}
			}
		} finally {
			if ( $lowerLimit ) {
				ini_set( 'pcre.backtrack_limit', $siteLimit );
			}
		}
		return [
			'matched' => (bool)$matches,
			'error' => (bool)$errors,
			'matches' => $matches,
			'errors' => $errors,
		];
	}

	/**
	 * Normalise text or a literal rule for matching: no invisible characters (INVISIBLE_PATTERN),
	 * full-width clause punctuation as 。, NFKC (full-width and other compatibility forms),
	 * CASE_BREAK, case folding, and no stray marks or (unless $keepAccents) accents (ACCENT_PATTERN).
	 *
	 * @param string $text
	 * @param bool $keepAccents Keep the accents of letters that have a precomposed form ("à", "ồ")
	 * @return string
	 */
	private static function fold( string $text, bool $keepAccents = false ): string {
		// Invisible characters first, so that they cannot come between a letter and its marks
		$text = preg_replace( self::INVISIBLE_PATTERN, '', $text ) ?? $text;
		$text = preg_replace( self::FULL_WIDTH_CLAUSE_END, "\u{3002}", $text ) ?? $text;
		$normal = Normalizer::normalize( $text, $keepAccents ? Normalizer::FORM_KC : Normalizer::FORM_KD );
		$text = $normal === false ? $text : $normal;
		$text = preg_replace(
			'/(\p{Ll}\p{M}*' . self::SEPARATOR . '++)(?=\p{Lu}\p{M}*' . self::SEPARATOR . '++\p{Lu})/u',
			'$1' . self::CASE_BREAK,
			$text
		) ?? $text;
		$text = mb_convert_case( $text, MB_CASE_FOLD, 'UTF-8' );
		$text = preg_replace( self::ACCENT_PATTERN, '', $text ) ?? $text;
		$normal = Normalizer::normalize( $text, Normalizer::FORM_C );
		return $normal === false ? $text : $normal;
	}

	/**
	 * Regex body (for compileRegex()) of a whole-word literal: its words in order with any
	 * separators or none between them. A word of several letters also matches with separators
	 * between each of its letters.
	 *
	 * @param string $literal Folded rule with at least one letter or digit
	 * @return string
	 */
	private static function wordsRegex( string $literal ): string {
		$words = [];
		foreach ( preg_split( '/' . self::SEPARATOR . '+/u', $literal, -1, PREG_SPLIT_NO_EMPTY ) ?: [] as $word ) {
			preg_match_all( '/[\p{L}\p{N}]\p{M}*/u', $word, $m );
			$letters = array_map( 'preg_quote', $m[0] );
			$words[] = count( $letters ) > 1
				? '(?:' . implode( '', $letters ) . '|' . implode( self::LETTER_SEPARATOR . '++', $letters ) . ')'
				: $letters[0];
		}
		// Possessive separators: they never overlap the letters that follow
		return '(?<!' . self::WORD_CHAR . ')' . implode( self::SEPARATOR . '*+', $words )
			. '(?!' . self::WORD_CHAR . ')';
	}

	/**
	 * Remove whitespace, punctuation, symbols and format characters.
	 *
	 * @param string $text
	 * @param bool $keepClauseEnd Keep CLAUSE_END, as in the text (a rule matches without it)
	 * @return string
	 */
	private static function squash( string $text, bool $keepClauseEnd ): string {
		$pattern = $keepClauseEnd
			? '/(?:(?![' . self::CLAUSE_END . '])[\s\p{P}\p{S}\p{Cf}])+/u'
			: '/[\s\p{P}\p{S}\p{Cf}]+/u';
		return preg_replace( $pattern, '', $text ) ?? $text;
	}

	/**
	 * @param string $body Pattern between the slashes
	 * @param string $flags
	 * @param string|null &$error Set when the pattern is rejected
	 * @return string|null Compiled pattern, or null if invalid
	 */
	private function compileRegex( string $body, string $flags, ?string &$error ): ?string {
		if ( preg_match( '/[^imsx]/', $flags ) ) {
			$error = 'unsupported-flags';
			return null;
		}
		// \x01 as delimiter, so slashes inside the body need no escaping. A body containing \x01
		// fails to compile and is reported as invalid.
		$pattern = "\x01" . $body . "\x01" . count_chars( $flags . 'u', 3 );
		$message = null;
		set_error_handler( static function ( $errno, $errstr ) use ( &$message ) {
			$message = $errstr;
			return true;
		} );
		try {
			$ok = preg_match( $pattern, '' );
		} finally {
			restore_error_handler();
		}
		if ( $ok === false ) {
			$error = $message !== null
				? preg_replace( '/^preg_match\(\): /', '', $message )
				: preg_last_error_msg();
			return null;
		}
		return $pattern;
	}
}
