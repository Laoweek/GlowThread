<?php

namespace MediaWiki\Extension\GlowThread\Api;

use MediaWiki\Api\ApiBase;
use StatusValue;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\StringDef;

/**
 * Shared behaviour of the GlowThread write modules: CSRF token, POST only, write mode, and
 * errors from StatusValues with stable codes.
 */
abstract class ApiGlowThreadBase extends ApiBase {

	/** Longest moderation or deletion reason, in code points */
	protected const MAX_REASON_CHARS = 500;

	/** @inheritDoc */
	public function needsToken() {
		return 'csrf';
	}

	/** @inheritDoc */
	public function mustBePosted() {
		return true;
	}

	/** @inheritDoc */
	public function isWriteMode() {
		return true;
	}

	/**
	 * Die with the status errors unless it is OK.
	 */
	protected function dieUnlessOK( StatusValue $status ): void {
		if ( !$status->isOK() ) {
			$this->dieStatus( $status );
		}
	}

	/**
	 * @return array ParamValidator settings of an optional reason parameter
	 */
	protected function getReasonParam(): array {
		return [
			ParamValidator::PARAM_TYPE => 'string',
			ParamValidator::PARAM_DEFAULT => '',
			StringDef::PARAM_MAX_CHARS => self::MAX_REASON_CHARS,
		];
	}
}
