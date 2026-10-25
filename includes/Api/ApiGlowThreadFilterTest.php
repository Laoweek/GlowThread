<?php

namespace MediaWiki\Extension\GlowThread\Api;

use MediaWiki\Api\ApiMain;
use MediaWiki\Extension\GlowThread\Filter\WordFilter;
use MediaWiki\Extension\GlowThread\Policy\WriteGuard;
use MediaWiki\Extension\GlowThread\Text\TextProcessor;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\StringDef;

/**
 * action=glowthreadfiltertest: run the word filter (or draft rules) on sample text without
 * saving anything.
 *
 * Bounded: the sample text is at most a comment's size (10000 code points, the highest
 * maxLength) and draft rules at most 64 KiB, of which the filter reads WordFilter::MAX_RULES
 * rules and runs regexes for WordFilter::REGEX_TIME_BUDGET (as when posting). Each call counts
 * against the glowthread-configure rate limit, which sysops cannot bypass.
 */
class ApiGlowThreadFilterTest extends ApiGlowThreadBase {

	/** Longest sample text, in code points: the highest possible maxLength */
	public const MAX_TEXT_CHARS = 10000;
	/** Longest draft rules, in bytes */
	public const MAX_RULES_BYTES = 65536;

	public function __construct(
		ApiMain $main,
		string $moduleName,
		private readonly WordFilter $wordFilter,
		private readonly TextProcessor $textProcessor,
		private readonly WriteGuard $guard
	) {
		parent::__construct( $main, $moduleName );
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$this->dieUnlessOK( $this->guard->checkModerate( $this->getAuthority(), 'glowthread-configure' ) );
		$rules = $params['rules'] ?? null;
		$text = $this->textProcessor->normalize( $params['text'] );
		$result = $this->wordFilter->check( $text, $rules );
		$parsed = $this->wordFilter->parse( $rules );
		$this->getResult()->addValue( null, $this->getModuleName(), [
			'held' => $result['matched'] || $result['error'],
			'matches' => $result['matches'],
			'errors' => $result['errors'],
			'invalid' => $parsed['invalid'],
			'rules' => count( $parsed['rules'] ),
		] );
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'text' => [
				ParamValidator::PARAM_TYPE => 'text',
				ParamValidator::PARAM_REQUIRED => true,
				StringDef::PARAM_MAX_CHARS => self::MAX_TEXT_CHARS,
				StringDef::PARAM_MAX_BYTES => 4 * self::MAX_TEXT_CHARS,
			],
			'rules' => [
				ParamValidator::PARAM_TYPE => 'text',
				StringDef::PARAM_MAX_BYTES => self::MAX_RULES_BYTES,
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=glowthreadfiltertest&text=Buy+cheap+coins&token=123ABC'
				=> 'apihelp-glowthreadfiltertest-example-current',
			'action=glowthreadfiltertest&text=Buy+cheap+coins&rules=/cheap\s+coins/i&token=123ABC'
				=> 'apihelp-glowthreadfiltertest-example-draft',
		];
	}
}
