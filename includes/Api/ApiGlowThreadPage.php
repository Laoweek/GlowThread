<?php

namespace MediaWiki\Extension\GlowThread\Api;

use MediaWiki\Api\ApiMain;
use MediaWiki\Extension\GlowThread\Model\PageState;
use MediaWiki\Extension\GlowThread\Service\PageStateService;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

/**
 * action=glowthreadpage: open, lock or disable comments on a page.
 */
class ApiGlowThreadPage extends ApiGlowThreadBase {

	public function __construct(
		ApiMain $main,
		string $moduleName,
		private readonly PageStateService $pageStateService
	) {
		parent::__construct( $main, $moduleName );
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$status = $this->pageStateService->setState(
			$this->getAuthority(), $params['pageid'], $params['state'], $params['reason']
		);
		$this->dieUnlessOK( $status );
		$value = $status->getValue();
		$this->getResult()->addValue( null, $this->getModuleName(), [
			'result' => 'success',
			'pageid' => $params['pageid'],
			'state' => $value['state'],
			'old' => $value['old'],
			'changed' => $value['changed'],
		] );
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'pageid' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
				IntegerDef::PARAM_MIN => 1,
			],
			'state' => [
				ParamValidator::PARAM_TYPE => PageState::ALL,
				ParamValidator::PARAM_REQUIRED => true,
			],
			'reason' => $this->getReasonParam(),
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=glowthreadpage&pageid=42&state=locked&reason=Flame+war&token=123ABC'
				=> 'apihelp-glowthreadpage-example-lock',
		];
	}
}
