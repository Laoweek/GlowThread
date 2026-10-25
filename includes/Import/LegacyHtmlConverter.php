<?php

namespace MediaWiki\Extension\GlowThread\Import;

use DOMDocument;
use DOMElement;
use DOMNode;
use MediaWiki\Extension\GlowThread\Text\TextProcessor;
use UtfNormal\Validator;

/**
 * Turns the rendered HTML that FlowThread stored for a comment into GlowThread's plain-text source.
 *
 * FlowThread kept no source text: it saved the parser output. Rows saved on older MediaWiki
 * versions hold the inner HTML (FlowThread stripped the old wrapper and the single outer
 * paragraph). Rows saved on newer versions keep the whole
 * `<div class="mw-content-ltr mw-parser-output" ...><p>...</p></div>` because FlowThread's wrapper
 * regex no longer matches it. Both forms go through the same DOM walk.
 *
 * Rules, in short:
 * - Paragraphs and other blocks become blank lines, `<br>` a line break. Newlines inside text are
 *   kept: plain-text comments were saved inside <nowiki>, so they are the author's own line breaks.
 *   Runs of spaces collapse to one and lines are trimmed, which is what readers saw in HTML.
 * - Entities are decoded once. Markup the author typed as text (escaped as &lt;...&gt;) stays text.
 * - External links become their URL, or "text (URL)" when the link text says something else.
 * - Links to user pages (any User namespace alias, red links included) become mentions: a
 *   placeholder per link, which withMentions() turns into the user's mention token once the
 *   importer has looked up all names. Other wiki links become "text (URL)" with an absolute,
 *   readable URL to the page.
 * - Images, scripts, styles and embedded objects are dropped and reported. Formatting (bold, strike,
 *   spoiler spans) is reduced to its text and reported.
 * - The result goes through TextProcessor::normalize(), so it is exactly what GlowThread stores.
 *
 * libxml is used with no network access and no entity loading. Its HTML parser reads input as
 * ISO-8859-1 unless the document declares an encoding, so the input is wrapped in a document that
 * declares UTF-8. libxml stops at elements nested more than 256 deep and drops the rest of the
 * document. When it stops early, the comment is parsed again with its elements flattened, so the
 * text and links still come through the same walk.
 *
 * Not reentrant: convert() keeps its working state on the object.
 */
class LegacyHtmlConverter {

	/** The stored HTML stops early, most likely cut at an emoji by the utf8mb3 column */
	public const WARN_TRUNCATED = 'truncated';
	/** A run of 4 or more "?", most likely emoji replaced byte by byte (by a utf8mb3 column) */
	public const WARN_QUESTION_MARKS = 'question-marks';
	/** An image was dropped. Detail: its src */
	public const WARN_IMAGE_DROPPED = 'image-dropped';
	/** A script, style, embed or form element was dropped. Detail: the tag name */
	public const WARN_ELEMENT_DROPPED = 'element-dropped';
	/** Formatting was reduced to its text. Detail: the tag name */
	public const WARN_FORMATTING_DROPPED = 'formatting-dropped';
	/** Text hidden by the heimu (black-bar) spoiler style is now shown as plain text */
	public const WARN_SPOILER_REVEALED = 'spoiler-revealed';
	/**
	 * A bare URL that GlowThread will link differently from the original link, because it stops at
	 * full-width punctuation or trims punctuation at the end. Detail: the part GlowThread links
	 * (not the whole original, which can hold the author's words)
	 */
	public const WARN_LINK_CHANGED = 'link-changed';
	/** The comment text contains something that looks like an HTML tag, typed as text by the author */
	public const WARN_LITERAL_MARKUP = 'literal-markup';
	/** libxml reported mis-nested or invalid markup, so the text may differ from what readers saw. Detail: its message */
	public const WARN_UNPARSEABLE = 'unparseable';
	/** The stored bytes were not valid UTF-8: bad sequences became U+FFFD */
	public const WARN_INVALID_UTF8 = 'invalid-utf8';
	/** Nothing is left after conversion */
	public const WARN_EMPTY = 'empty';

	/** The MediaWiki parser wrapper div (class mw-parser-output) was removed */
	public const FEATURE_WRAPPER = 'wrapper';
	/** A paragraph or other block boundary */
	public const FEATURE_BLOCK = 'block';
	/** A <br> line break */
	public const FEATURE_LINE_BREAK = 'line-break';
	/** A link to another site */
	public const FEATURE_EXTERNAL_LINK = 'external-link';
	/** A link to a wiki page other than a user page */
	public const FEATURE_INTERNAL_LINK = 'internal-link';
	/** A link to a user page (turned into a mention) */
	public const FEATURE_USER_LINK = 'user-link';
	/** A red link (page did not exist when the comment was saved) */
	public const FEATURE_RED_LINK = 'red-link';

	/**
	 * libxml errors that lose nothing: a tag its HTML 4 parser does not know (801, HTML5 tags), and
	 * an "&" that starts no entity (68) or an entity without its ";" (23), which stay as typed.
	 */
	private const HARMLESS_LIBXML_ERRORS = [ 801, 68, 23 ];

	private const BLOCK_ELEMENTS = [
		'p', 'div', 'blockquote', 'pre', 'center', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'dl',
		'table', 'hr', 'section', 'article', 'header', 'footer', 'nav', 'aside', 'main', 'figure',
		'figcaption', 'address', 'details', 'summary', 'body', 'html',
	];
	private const LINE_ELEMENTS = [ 'li', 'dt', 'dd', 'tr', 'caption' ];
	private const CELL_ELEMENTS = [ 'td', 'th' ];
	private const DROPPED_ELEMENTS = [
		'script', 'style', 'noscript', 'template', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
		'audio', 'video', 'source', 'track', 'canvas', 'svg', 'math', 'map', 'area', 'form', 'input',
		'button', 'select', 'textarea', 'link', 'meta', 'title', 'head',
	];
	private const IMAGE_ELEMENTS = [ 'img', 'image' ];
	/** Structure that is not worth a warning: blocks are kept as line breaks */
	private const SILENT_ELEMENTS = [ 'p', 'div', 'body', 'html', 'center', 'wbr' ];
	/** Elements that cannot have content, for the truncation check */
	private const VOID_ELEMENTS = [
		'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source',
		'track', 'wbr',
	];

	/**
	 * Marker written right after each URL this class produces, and resolved once the text is
	 * complete: if the next character would continue the URL when TextProcessor reads the text
	 * back, the marker becomes a space, otherwise it goes. Like the mention placeholders, a
	 * Unicode noncharacter, which never occurs in interchanged text and is removed from the input.
	 */
	private const URL_END = "\u{FDD0}";
	/** A user link is MENTION_OPEN, its index in ConversionResult::$mentions, MENTION_CLOSE */
	private const MENTION_OPEN = "\u{FDD1}";
	private const MENTION_CLOSE = "\u{FDD2}";
	/**
	 * What may follow a URL without extending it in TextProcessor::URL_PATTERN: an end character
	 * (whitespace, quotes, angle brackets, CJK punctuation, full-width forms, CJK, Thai, Lao, Khmer
	 * and Myanmar letters), optionally after punctuation and closing brackets that TextProcessor
	 * trims from a URL's end.
	 */
	private const URL_FOLLOW_OK = '/^[.,;:!?*\')\]}]*(?:[\s<>"\'\x{3000}-\x{303F}\x{FF00}-\x{FFEF}'
		. '\x{201C}\x{201D}\x{2018}\x{2019}\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}'
		. '\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}]|$)/u';
	/**
	 * Characters in a bare link that mean MediaWiki's link swallowed the text after the URL:
	 * whitespace, quotes, angle brackets, CJK punctuation and full-width forms.
	 */
	private const SWALLOWED_TEXT = '/[\s<>"\'\x{3000}-\x{303F}\x{FF00}-\x{FFEF}\x{201C}\x{201D}\x{2018}\x{2019}]/u';
	private string $server;
	private string $serverHost;
	/** @var array<string,true> Normalised User namespace names and aliases */
	private array $userNamespaces = [];

	/** Working state of one convert() call */
	private string $out = '';
	private bool $afterBreak = false;
	/** @var array<string,array{code:string,detail:string}> */
	private array $warnings = [];
	/** @var array<string,int> */
	private array $features = [];
	/** @var string[] User names of the user links, by placeholder index */
	private array $mentions = [];

	/**
	 * @param TextProcessor $textProcessor Normalises the result and checks how URLs parse back
	 * @param string $server The wiki's canonical server ($wgCanonicalServer), for absolute page URLs
	 *  and for recognising absolute links to this wiki
	 * @param string $articlePath $wgArticlePath, such as "/wiki/$1"
	 * @param string $script $wgScript, such as "/index.php" (red links point there)
	 * @param string[] $userNamespaceNames Every name and alias of the User namespace, in any case,
	 *  with spaces or underscores: the canonical "User" plus the Language::getNamespaceIds() keys
	 *  that map to NS_USER (the content language's names, such as "Benutzer" on a German wiki)
	 * @param bool $imageLinks Replace each image by its URL instead of dropping it (still reported)
	 */
	public function __construct(
		private readonly TextProcessor $textProcessor,
		string $server,
		private readonly string $articlePath,
		private readonly string $script,
		array $userNamespaceNames,
		private readonly bool $imageLinks = false
	) {
		$server = rtrim( $server, '/' );
		$this->server = str_starts_with( $server, '//' ) ? 'https:' . $server : $server;
		$this->serverHost = strtolower( (string)parse_url( $this->server, PHP_URL_HOST ) );
		foreach ( $userNamespaceNames as $name ) {
			$this->userNamespaces[$this->normalizeNamespace( $name )] = true;
		}
	}

	/**
	 * @return string The server used for absolute page URLs
	 */
	public function getServer(): string {
		return $this->server;
	}

	/**
	 * @param string $html The stored flowthread_text value
	 */
	public function convert( string $html ): ConversionResult {
		$this->out = '';
		$this->afterBreak = false;
		$this->warnings = [];
		$this->features = [];
		$this->mentions = [];

		if ( !mb_check_encoding( $html, 'UTF-8' ) ) {
			$this->warn( self::WARN_INVALID_UTF8 );
			$html = Validator::cleanUp( $html );
		}
		$html = str_replace( [ self::URL_END, self::MENTION_OPEN, self::MENTION_CLOSE ], '', $html );
		[ $html, $truncation ] = $this->repairTruncation( $html );
		if ( $truncation !== null ) {
			$this->warn( self::WARN_TRUNCATED, $truncation );
		}

		$body = $this->parse( $html ) ?? $this->parse( $this->flatten( $html ) );
		if ( $body !== null ) {
			$this->walk( $body );
			$text = $this->out;
		} else {
			$this->warn( self::WARN_UNPARSEABLE, 'tags stripped, links lost' );
			$text = html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		// Normalise first: it removes invisible characters that would otherwise sit between a
		// URL and the next letter when the end markers are resolved
		$text = $this->resolveEnds( $this->textProcessor->normalize( $this->tidy( $text ) ) );

		if ( preg_match_all( '/\?{4,}/', $text, $runs ) ) {
			$this->warn( self::WARN_QUESTION_MARKS, implode( ',', array_map( 'strlen', $runs[0] ) ) );
		}
		if ( preg_match( '/<\/?[a-zA-Z][a-zA-Z0-9]*(?:\s[^<>]*)?\/?>/', $text ) ) {
			$this->warn( self::WARN_LITERAL_MARKUP );
		}
		if ( $text === '' ) {
			$this->warn( self::WARN_EMPTY );
		}
		return new ConversionResult( $text, array_values( $this->warnings ), $this->features, $this->mentions );
	}

	/**
	 * The final text of a conversion: each user link placeholder becomes the mention token of
	 * that user (TextProcessor::mentionToken()), TextProcessor::MENTION_PLACEHOLDER for a hidden
	 * user, so that the name is not stored, or "@Name" as plain text for a name that is no user.
	 *
	 * @param string $text ConversionResult::$text
	 * @param string[] $mentions ConversionResult::$mentions
	 * @param array<string,?int> $users User name => ID of a registered user who is not hidden, or
	 *   null for a hidden one. Names that are no user are left out.
	 * @return string Normalised text
	 */
	public function withMentions( string $text, array $mentions, array $users ): string {
		$text = preg_replace_callback(
			'/' . self::MENTION_OPEN . '(\d+)' . self::MENTION_CLOSE . '/u',
			static function ( array $m ) use ( $mentions, $users ): string {
				$name = (string)( $mentions[(int)$m[1]] ?? '' );
				if ( !array_key_exists( $name, $users ) ) {
					return '@' . $name;
				}
				return $users[$name] === null
					? TextProcessor::MENTION_PLACEHOLDER : TextProcessor::mentionToken( $users[$name] );
			},
			$text
		) ?? $text;
		return $this->textProcessor->normalize( $text );
	}

	/**
	 * Find signs that the stored value was cut off: a tag that never ends, or elements that are
	 * opened and never closed. MediaWiki's parser always closes what it opens, so either one means
	 * the column dropped the rest of the value. A partial tag is removed and open elements are
	 * closed, so libxml sees a complete fragment.
	 *
	 * @return array{0:string,1:?string} The repaired HTML, and what was missing (null if nothing)
	 */
	private function repairTruncation( string $html ): array {
		$missing = [];
		$lastOpen = strrpos( $html, '<' );
		if ( $lastOpen !== false && strpos( $html, '>', $lastOpen ) === false ) {
			$html = substr( $html, 0, $lastOpen );
			$missing[] = 'partial tag';
		}
		preg_match_all( '/<(\/?)([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>/', $html, $tags, PREG_SET_ORDER );
		$open = [];
		// Where each name is open in $open, innermost last, so that an end tag is found in one step
		$openAt = [];
		foreach ( $tags as [ $whole, $closing, $name ] ) {
			$name = strtolower( $name );
			if ( in_array( $name, self::VOID_ELEMENTS, true ) || str_ends_with( $whole, '/>' ) ) {
				continue;
			}
			if ( $closing === '' ) {
				$openAt[$name][] = count( $open );
				$open[] = $name;
				continue;
			}
			if ( $openAt[$name] ?? [] ) {
				// Closing an outer element closes everything opened inside it
				$at = end( $openAt[$name] );
				while ( count( $open ) > $at ) {
					$closed = array_pop( $open );
					array_pop( $openAt[$closed] );
				}
			}
		}
		if ( $open ) {
			$html .= implode( '', array_map( static fn ( string $tag ): string => "</$tag>", array_reverse( $open ) ) );
			$missing[] = 'unclosed ' . implode( ' ', $open );
		}
		return [ $html, $missing ? implode( ', ', $missing ) : null ];
	}

	/**
	 * @return DOMNode|null The body holding the parsed comment, or null if libxml gave up or
	 *  stopped early (a fatal error, such as elements nested deeper than its limit)
	 */
	private function parse( string $html ): ?DOMNode {
		$doc = new DOMDocument( '1.0', 'UTF-8' );
		$useInternal = libxml_use_internal_errors( true );
		// Errors the caller already collected stay, only the ones from this parse are read
		$pending = count( libxml_get_errors() );
		try {
			$loaded = $doc->loadHTML(
				'<!DOCTYPE html><html><head>'
					. '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'
					. '</head><body>' . $html . '</body></html>',
				LIBXML_NONET
			);
			$errors = array_slice( libxml_get_errors(), $pending );
		} finally {
			if ( !$useInternal ) {
				libxml_clear_errors();
			}
			libxml_use_internal_errors( $useInternal );
		}
		$stopped = false;
		foreach ( $errors as $error ) {
			if ( !in_array( $error->code, self::HARMLESS_LIBXML_ERRORS, true ) ) {
				$this->warn( self::WARN_UNPARSEABLE, trim( $error->message ) );
			}
			$stopped = $stopped || $error->level === LIBXML_ERR_FATAL;
		}
		$body = $loaded ? $doc->getElementsByTagName( 'body' )->item( 0 ) : null;
		if ( $body === null ) {
			$this->warn( self::WARN_UNPARSEABLE, 'no document' );
		}
		// After a fatal error the document holds only what came before it
		return $stopped ? null : $body;
	}

	/**
	 * The comment without nesting, for a second parse when libxml stopped early. Each element
	 * becomes an empty copy of itself followed by its content, so the walk still reports it, and
	 * its end tag becomes the break or space that the walk would add at the end of a block, line
	 * or cell. Void and self-closed elements stay as they are. Links keep their content (one link
	 * cannot hold another), and dropped elements lose it. Comments stay, so markup inside them
	 * stays inert.
	 */
	private function flatten( string $html ): string {
		// Tag names as libxml reads them. A comment that never closes runs to the end, as in libxml
		$parts = preg_split(
			'/(<!--.*?(?:-->|\z)|<\/?[a-zA-Z][a-zA-Z0-9:._-]*+[^>]*+>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE
		);
		if ( $parts === false ) {
			return $html;
		}
		$flat = '';
		$inLink = false;
		/** @var array{0:string,1:int}|null $dropping The dropped element being skipped, how deep */
		$dropping = null;
		foreach ( $parts as $i => $part ) {
			// Even parts are text, odd parts the tags and comments between them
			if ( $i % 2 === 0 || !preg_match( '/^<(\/?)([a-zA-Z][a-zA-Z0-9:._-]*)/', $part, $m ) ) {
				$flat .= $dropping === null ? $part : '';
				continue;
			}
			$name = strtolower( $m[2] );
			$end = $m[1] === '/';
			$empty = !$end && ( in_array( $name, self::VOID_ELEMENTS, true ) || str_ends_with( $part, '/>' ) );
			if ( $dropping !== null ) {
				if ( $name === $dropping[0] && $end ) {
					$dropping[1]--;
				} elseif ( $name === $dropping[0] && !$empty && !in_array( $name, [ 'script', 'style' ], true ) ) {
					// Script and style hold raw text, which cannot open another element
					$dropping[1]++;
				}
				$dropping = $dropping[1] > 0 ? $dropping : null;
			} elseif ( $name === 'a' ) {
				$flat .= ( $inLink ? '</a>' : '' ) . ( $end ? '' : $part );
				$inLink = !$end;
			} elseif ( $end ) {
				$flat .= in_array( $name, self::BLOCK_ELEMENTS, true ) ? "\n\n"
					: ( in_array( $name, self::LINE_ELEMENTS, true ) ? "\n"
					: ( in_array( $name, self::CELL_ELEMENTS, true ) ? ' ' : '' ) );
			} elseif ( $empty ) {
				$flat .= $part;
			} else {
				$flat .= $part . "</$name>";
				if ( in_array( $name, self::DROPPED_ELEMENTS, true ) ) {
					$dropping = [ $name, 1 ];
				}
			}
		}
		return $flat;
	}

	private function walk( DOMNode $parent ): void {
		foreach ( iterator_to_array( $parent->childNodes ) as $node ) {
			if ( $node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE ) {
				$this->appendText( $node->nodeValue ?? '' );
			} elseif ( $node instanceof DOMElement ) {
				$this->element( $node );
			}
			// Comments and processing instructions carry nothing for readers
		}
	}

	private function element( DOMElement $el ): void {
		$tag = strtolower( $el->tagName );
		if ( $tag === 'br' ) {
			$this->feature( self::FEATURE_LINE_BREAK );
			$this->out .= "\n";
			$this->afterBreak = true;
		} elseif ( $tag === 'a' ) {
			$this->link( $el );
		} elseif ( in_array( $tag, self::IMAGE_ELEMENTS, true ) ) {
			$src = trim( $el->getAttribute( 'src' ) );
			$this->warn( self::WARN_IMAGE_DROPPED, $src );
			if ( $this->imageLinks && preg_match( '#^(?:https?:)?//|^/#i', $src ) ) {
				$url = str_starts_with( $src, '//' ) ? 'https:' . $src
					: ( str_starts_with( $src, '/' ) ? $this->server . $src : $src );
				if ( $this->out !== '' && !preg_match( '/\s$/u', $this->out ) ) {
					$this->out .= ' ';
				}
				$this->appendLabelled( '', $this->linkable( $url ) );
			}
		} elseif ( in_array( $tag, self::DROPPED_ELEMENTS, true ) ) {
			$this->warn( self::WARN_ELEMENT_DROPPED, $tag );
		} elseif ( in_array( $tag, self::BLOCK_ELEMENTS, true ) ) {
			if ( $tag === 'div' && preg_match( '/(^|\s)mw-parser-output(\s|$)/', $el->getAttribute( 'class' ) ) ) {
				$this->feature( self::FEATURE_WRAPPER );
			} elseif ( $tag !== 'body' && $tag !== 'html' ) {
				$this->feature( self::FEATURE_BLOCK );
			}
			$this->formatting( $tag );
			$this->boundary( "\n\n" );
			$this->walk( $el );
			$this->boundary( "\n\n" );
		} elseif ( in_array( $tag, self::LINE_ELEMENTS, true ) ) {
			$this->formatting( $tag );
			$this->boundary( "\n" );
			$this->walk( $el );
			$this->boundary( "\n" );
		} elseif ( in_array( $tag, self::CELL_ELEMENTS, true ) ) {
			$this->formatting( $tag );
			$this->walk( $el );
			$this->appendText( ' ' );
		} else {
			if ( $tag === 'span' && preg_match( '/(^|\s)heimu(\s|$)/', $el->getAttribute( 'class' ) ) ) {
				$this->warn( self::WARN_SPOILER_REVEALED );
			} elseif ( $tag !== 'span' || $el->hasAttribute( 'class' ) || $el->hasAttribute( 'style' ) ) {
				$this->formatting( $tag );
			}
			$this->walk( $el );
		}
	}

	/**
	 * Report a structural or formatting element whose meaning is lost in plain text.
	 */
	private function formatting( string $tag ): void {
		if ( !in_array( $tag, self::SILENT_ELEMENTS, true ) ) {
			$this->warn( self::WARN_FORMATTING_DROPPED, $tag );
		}
	}

	/**
	 * Append text from the document. Whitespace is tidied later, except that MediaWiki writes a
	 * newline after each <br>, which must not double the break.
	 */
	private function appendText( string $text ): void {
		$text = str_replace( [ "\r\n", "\r" ], "\n", $text );
		if ( $this->afterBreak ) {
			$text = preg_replace( '/^[ \t]*\n/', '', $text, 1 ) ?? $text;
			if ( trim( $text, " \t" ) !== '' ) {
				$this->afterBreak = false;
			}
		}
		$this->out .= $text;
	}

	/**
	 * End the current block: trailing whitespace goes, then the separator, unless nothing came yet.
	 */
	private function boundary( string $separator ): void {
		$this->out = rtrim( $this->out, " \t\n" );
		if ( $this->out !== '' ) {
			$this->out .= $separator;
		}
		$this->afterBreak = false;
	}

	private function link( DOMElement $a ): void {
		$href = trim( $a->getAttribute( 'href' ) );
		$label = $this->innerText( $a );
		if ( $href === '' || str_starts_with( $href, '#' ) ) {
			$this->appendText( $label );
			return;
		}
		$target = $this->resolveInternal( $href );
		if ( $target === null ) {
			$this->feature( self::FEATURE_EXTERNAL_LINK );
			$url = str_starts_with( $href, '//' ) ? 'https:' . $href : $href;
			$same = rawurldecode( $label ) === rawurldecode( $href ) || rawurldecode( $label ) === rawurldecode( $url );
			if ( $same ) {
				if ( preg_match( self::SWALLOWED_TEXT, $url ) ) {
					// The author's words after the URL: keep them readable, GlowThread links less
					$tokens = $this->textProcessor->tokenize( $url );
					if ( !$this->linksAs( $url ) ) {
						$this->warn(
							self::WARN_LINK_CHANGED, ( $tokens[0]['type'] ?? '' ) === 'link' ? $tokens[0]['href'] : ''
						);
					}
					$this->appendLabelled( '', $url );
				} else {
					$this->appendLabelled( '', $this->linkable( $url ) );
				}
				return;
			}
			// "[1]" style links carry nothing beyond the URL itself
			$numbered = preg_match( '/(^|\s)autonumber(\s|$)/', $a->getAttribute( 'class' ) )
				|| preg_match( '/^\[\d+\]$/', $label );
			$this->appendLabelled( $numbered ? '' : $label, $this->linkable( $url ) );
			return;
		}
		if ( $target['red'] ) {
			$this->feature( self::FEATURE_RED_LINK );
		}
		$user = $target['title'] === null ? null : $this->userName( $target['title'] );
		if ( $user !== null ) {
			$this->feature( self::FEATURE_USER_LINK );
			$this->mention( $user, $label );
			return;
		}
		$this->feature( self::FEATURE_INTERNAL_LINK );
		$same = rawurldecode( $label ) === rawurldecode( $href )
			|| rawurldecode( $label ) === rawurldecode( $target['url'] );
		$this->appendLabelled( $same ? '' : $label, $target['url'] );
	}

	/**
	 * "label (URL)", or just the URL when the label adds nothing.
	 */
	private function appendLabelled( string $label, string $url ): void {
		if ( $label === '' || $label === $url ) {
			$this->appendText( $url . self::URL_END );
		} else {
			$this->appendText( $label . ' (' . $url . ')' . self::URL_END );
		}
	}

	/**
	 * The text of an element on one line, without changing the surrounding state.
	 */
	private function innerText( DOMElement $el ): string {
		$saved = [ $this->out, $this->afterBreak ];
		$this->out = '';
		$this->afterBreak = false;
		$this->walk( $el );
		$text = str_replace( self::URL_END, '', $this->out );
		[ $this->out, $this->afterBreak ] = $saved;
		return trim( preg_replace( '/[ \t\n\r\f\x{00A0}]+/u', ' ', $text ) ?? $text );
	}

	/**
	 * Write a mention of $name: a placeholder for withMentions(). FlowThread's reply prefix is "@"
	 * followed by the user link, so an "@" right before the link is part of the mention.
	 */
	private function mention( string $name, string $label ): void {
		// MediaWiki always capitalises the first letter of a user name
		$canonical = mb_strtoupper( mb_substr( $name, 0, 1 ) ) . mb_substr( $name, 1 );
		$placeholder = self::MENTION_OPEN . count( $this->mentions ) . self::MENTION_CLOSE;
		$this->mentions[] = $canonical;
		if ( str_ends_with( $this->out, '@' ) ) {
			$this->out = substr( $this->out, 0, -1 );
		}
		$shown = ltrim( $label, '@' );
		if ( $shown !== '' && !in_array( $shown, [ $name, $canonical, str_replace( ' ', '_', $canonical ) ], true ) ) {
			// The link said something else: keep those words and add who they meant
			$this->appendText( $shown . ' (' . $placeholder . ')' );
			return;
		}
		$this->appendText( $placeholder );
	}

	/**
	 * Recognise links to this wiki: relative paths, and absolute or protocol-relative URLs on the
	 * same host. Page links use the article path ("/wiki/Title") or the script ("/index.php?title=
	 * Title&action=edit&redlink=1" for red links, "/index.php/Title"). Links that carry more than a
	 * title, such as an old revision or a diff, keep their full URL.
	 *
	 * @return array{title:?string,url:string,red:bool}|null Null for links to other sites
	 */
	private function resolveInternal( string $href ): ?array {
		if ( str_starts_with( $href, '//' ) ) {
			$absolute = 'https:' . $href;
		} elseif ( str_starts_with( $href, '/' ) ) {
			// parse_url() rejects some relative paths, such as "/wiki/User:12345"
			$absolute = $this->server . $href;
		} else {
			$absolute = $href;
		}
		$parts = parse_url( $absolute );
		if ( $parts === false || strtolower( $parts['host'] ?? '' ) !== $this->serverHost ) {
			return null;
		}
		$path = $parts['path'] ?? '';
		parse_str( $parts['query'] ?? '', $query );
		$pageOnly = !array_diff( array_keys( $query ), [ 'title', 'action', 'redlink' ] )
			&& in_array( $query['action'] ?? 'edit', [ 'edit', 'view' ], true );
		$title = null;
		[ $before, $after ] = array_pad( explode( '$1', $this->articlePath, 2 ), 2, '' );
		if ( $path === $this->script || $path === $this->script . '/' ) {
			$title = is_string( $query['title'] ?? null ) ? $query['title'] : null;
		} elseif ( str_starts_with( $path, $this->script . '/' ) ) {
			$title = rawurldecode( substr( $path, strlen( $this->script ) + 1 ) );
		} elseif ( $before !== '' && str_starts_with( $path, $before ) && strlen( $path ) > strlen( $before )
			&& ( $after === '' || str_ends_with( $path, $after ) )
		) {
			$title = rawurldecode(
				substr( $path, strlen( $before ), strlen( $path ) - strlen( $before ) - strlen( $after ) )
			);
		}
		if ( $title !== null ) {
			$title = trim( str_replace( '_', ' ', $title ) );
		}
		if ( $title === null || $title === '' || !$pageOnly ) {
			$url = $this->server . preg_replace( '/^[^\/]*\/\/[^\/]*/', '', $absolute );
			return [ 'title' => null, 'url' => $this->linkable( $url ), 'red' => false ];
		}
		$url = $this->pageUrl( $title );
		if ( ( $parts['fragment'] ?? '' ) !== '' ) {
			$url .= '#' . $this->encodeUrlPart( rawurldecode( $parts['fragment'] ) );
		}
		return [ 'title' => $title, 'url' => $url, 'red' => ( $query['redlink'] ?? '' ) === '1' ];
	}

	/**
	 * @return string|null The user name if $title is a user page (not a subpage), else null
	 */
	private function userName( string $title ): ?string {
		$colon = strpos( $title, ':' );
		if ( $colon === false ) {
			return null;
		}
		$name = trim( substr( $title, $colon + 1 ) );
		if ( !isset( $this->userNamespaces[$this->normalizeNamespace( substr( $title, 0, $colon ) )] )
			|| $name === '' || str_contains( $name, '/' )
		) {
			return null;
		}
		return $name;
	}

	/**
	 * An absolute URL for a page that TextProcessor autolinks as one piece (docs/api.md asks
	 * software to percent-encode non-ASCII characters in URLs).
	 */
	private function pageUrl( string $title ): string {
		$path = $this->encodeUrlPart( str_replace( ' ', '_', $title ) );
		return $this->server . str_replace( '$1', $path, $this->articlePath );
	}

	/**
	 * Percent-encode a title or fragment: everything except ASCII letters, digits, "-_.~", ":" and
	 * "/" (non-ASCII characters end a URL in TextProcessor, the rest would end, trim or change it),
	 * and "." or ":" at the very end, which TextProcessor trims.
	 */
	private function encodeUrlPart( string $part ): string {
		$part = str_replace( [ '%3A', '%2F' ], [ ':', '/' ], rawurlencode( $part ) );
		return preg_replace_callback(
			'/[.:]+$/',
			static fn ( array $m ): string => implode( '', array_map(
				static fn ( string $c ): string => sprintf( '%%%02X', ord( $c ) ), str_split( $m[0] )
			) ),
			$part
		) ?? '';
	}

	/**
	 * Percent-encode what would end or trim $url when TextProcessor autolinks it: every non-ASCII
	 * character, whitespace and control, quotes and angle brackets anywhere, and punctuation or an
	 * unbalanced closing bracket at the end. The link target stays the same.
	 */
	private function linkable( string $url ): string {
		$url = preg_replace_callback(
			'/[^\x21-\x7E]|["\'<>]/u',
			static fn ( array $m ): string => rawurlencode( $m[0] ),
			$url
		) ?? '';
		$pairs = [ ')' => '(', ']' => '[', '}' => '{' ];
		$tail = '';
		while ( $url !== '' ) {
			$last = substr( $url, -1 );
			$trimmed = strpbrk( $last, '.,;:!?*' ) !== false || ( isset( $pairs[$last] )
				&& substr_count( $url, $pairs[$last] ) < substr_count( $url, $last ) );
			if ( !$trimmed ) {
				break;
			}
			$tail = sprintf( '%%%02X', ord( $last ) ) . $tail;
			$url = substr( $url, 0, -1 );
		}
		return $url . $tail;
	}

	/**
	 * Whether TextProcessor turns $url, on its own, into exactly this link.
	 */
	private function linksAs( string $url ): bool {
		$tokens = $this->textProcessor->tokenize( $url );
		return count( $tokens ) === 1 && $tokens[0]['type'] === 'link' && $tokens[0]['href'] === $url;
	}

	/**
	 * Replace each end marker with a space where the next character would otherwise run on into
	 * the URL before it, and drop it everywhere else. A mention right after a URL is set apart
	 * too: as "@Name" text it would continue the URL.
	 */
	private function resolveEnds( string $text ): string {
		$parts = explode( self::URL_END, $text );
		$result = array_shift( $parts );
		foreach ( $parts as $part ) {
			if ( $part !== '' && !preg_match( self::URL_FOLLOW_OK, $part ) ) {
				$result .= ' ';
			}
			$result .= $part;
		}
		return $result;
	}

	/**
	 * Collapse spaces and tabs, trim every line, and allow at most one blank line in a row.
	 */
	private function tidy( string $text ): string {
		$text = str_replace( [ "\r\n", "\r" ], "\n", $text );
		$text = str_replace( [ "\t", "\f", "\v", "\u{00A0}" ], ' ', $text );
		$text = preg_replace( '/ {2,}/', ' ', $text ) ?? $text;
		$text = preg_replace( '/^ +| +$/m', '', $text ) ?? $text;
		$text = preg_replace( "/\n{3,}/", "\n\n", $text ) ?? $text;
		return trim( $text, "\n" );
	}

	private function normalizeNamespace( string $name ): string {
		return mb_strtolower( trim( str_replace( '_', ' ', $name ) ) );
	}

	private function warn( string $code, string $detail = '' ): void {
		$this->warnings[$code . "\0" . $detail] = [ 'code' => $code, 'detail' => $detail ];
	}

	private function feature( string $name ): void {
		$this->features[$name] = ( $this->features[$name] ?? 0 ) + 1;
	}
}
