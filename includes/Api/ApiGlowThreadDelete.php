<?php

namespace MediaWiki\Extension\GlowThread\Api;

use MediaWiki\Api\ApiMain;
use MediaWiki\Extension\GlowThread\Service\ModerationService;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

/**
 * action=glowthreaddelete: the author deletes their own comment.
 */
class ApiGlowThreadDelete extends ApiGlowThreadBase {

	public function __construct(
		ApiMain $main,
		string $moduleName,
		private readonly ModerationService $moderationService
	) {
		parent::__construct( $main, $moduleName );
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$status = $this->moderationService->selfDelete( $this->getAuthority(), $params['id'], $params['reason'] );
		$this->dieUnlessOK( $status );
		$this->getResult()->addValue( null, $this->getModuleName(), [
			'result' => 'success',
			'id' => $params['id'],
			'status' => $status->getValue()->getStatus(),
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
			'reason' => $this->getReasonParam(),
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=glowthreaddelete&id=7&token=123ABC' => 'apihelp-glowthreaddelete-example-delete',
		];
	}
}
