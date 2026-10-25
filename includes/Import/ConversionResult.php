<?php

namespace MediaWiki\Extension\GlowThread\Import;

/**
 * What LegacyHtmlConverter made of one stored FlowThread comment.
 */
final class ConversionResult {

	/**
	 * @param string $text Plain-text source in the stored GlowThread form (normalised, never HTML),
	 *  except for a placeholder per user link: LegacyHtmlConverter::withMentions() gives the text
	 *  to store
	 * @param array<int,array{code:string,detail:string}> $warnings Problems for the import report,
	 *  codes are the LegacyHtmlConverter::WARN_* constants
	 * @param array<string,int> $features How often each LegacyHtmlConverter::FEATURE_* occurred
	 * @param string[] $mentions The user name each user link names, one per placeholder in $text,
	 *  in order
	 */
	public function __construct(
		public readonly string $text,
		public readonly array $warnings,
		public readonly array $features,
		public readonly array $mentions
	) {
	}

	/**
	 * @param string $code A LegacyHtmlConverter::WARN_* constant
	 */
	public function hasWarning( string $code ): bool {
		foreach ( $this->warnings as $warning ) {
			if ( $warning['code'] === $code ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return string[] Distinct warning codes, in the order they first occurred
	 */
	public function getWarningCodes(): array {
		return array_values( array_unique( array_column( $this->warnings, 'code' ) ) );
	}
}
