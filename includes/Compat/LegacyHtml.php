<?php

namespace MediaWiki\Extension\GlowThread\Compat;

use MediaWiki\Html\Html;

/**
 * Builds the HTML that FlowThread's list API returned as a comment's `text`, from GlowThread
 * segments. Old clients insert it with jQuery .html(), so every piece of text and every
 * attribute is escaped here, and links are http(s) only.
 */
final class LegacyHtml {

	/**
	 * @param array[] $segments Comment segments (types text, link, mention), see docs/api.md
	 * @param callable(string):string $userUrl Returns the URL of a user page for a user name
	 * @return string HTML
	 */
	public static function fromSegments( array $segments, callable $userUrl ): string {
		$html = '';
		foreach ( $segments as $segment ) {
			$text = (string)( $segment['text'] ?? '' );
			$type = $segment['type'] ?? 'text';
			if ( $type === 'link' && self::isWebUrl( (string)( $segment['href'] ?? '' ) ) ) {
				$html .= Html::element( 'a', [
					'href' => $segment['href'],
					'class' => 'external',
					'rel' => 'nofollow ugc noopener',
					'target' => '_blank',
				], $text );
			} elseif ( $type === 'mention' && !empty( $segment['exists'] ) && isset( $segment['user'] ) ) {
				$html .= Html::element( 'a', [
					'href' => $userUrl( (string)$segment['user'] ),
					'title' => (string)$segment['user'],
				], $text );
			} else {
				$html .= nl2br( htmlspecialchars( $text, ENT_QUOTES ), false );
			}
		}
		return $html;
	}

	private static function isWebUrl( string $url ): bool {
		return (bool)preg_match( '/^https?:\/\//i', $url );
	}
}
