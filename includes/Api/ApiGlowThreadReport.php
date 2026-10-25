<?php

namespace MediaWiki\Extension\GlowThread\Api;

use MediaWiki\Api\ApiMain;
use MediaWiki\Extension\GlowThread\Model\ReportReason;
use MediaWiki\Extension\GlowThread\Service\ReportService;
use MediaWiki\Extension\GlowThread\Settings\SettingsService;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;
use Wikimedia\ParamValidator\TypeDef\StringDef;

/**
 * action=glowthreadreport: report a comment, once per user and comment.
 */
class ApiGlowThreadReport extends ApiGlowThreadBase {

	public function __construct(
		ApiMain $main,
		string $moduleName,
		private readonly ReportService $reportService,
		private readonly SettingsService $settings
	) {
		parent::__construct( $main, $moduleName );
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$status = $this->reportService->report(
			$this->getAuthority(), $params['id'], $params['reason'], $params['note']
		);
		$this->dieUnlessOK( $status );
		$value = $status->getValue();
		$this->getResult()->addValue( null, $this->getModuleName(), [
			'result' => 'success',
			'id' => $params['id'],
			'already' => $value['already'],
			'held' => $value['held'],
		] );
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'id' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
				IntegerDef::PARAM_MIN => 1,
			],
			'reason' => [
				ParamValidator::PARAM_TYPE => ReportReason::USER_REASONS,
				ParamValidator::PARAM_REQUIRED => true,
			],
			'note' => [
				ParamValidator::PARAM_TYPE => 'text',
				ParamValidator::PARAM_DEFAULT => '',
				StringDef::PARAM_MAX_CHARS => ReportService::MAX_NOTE_LENGTH * 2,
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=glowthreadreport&id=7&reason=spam&token=123ABC' => 'apihelp-glowthreadreport-example-spam',
		];
	}
}
