<?php

namespace MediaWiki\Extension\GlowThread\Api;

use MediaWiki\Api\ApiMain;
use MediaWiki\Extension\GlowThread\Service\ReactionService;
use MediaWiki\Extension\GlowThread\Store\ReactionStore;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

/**
 * action=glowthreadreact: set or clear a like. Idempotent, not a toggle.
 */
class ApiGlowThreadReact extends ApiGlowThreadBase {

	public function __construct(
		ApiMain $main,
		string $moduleName,
		private readonly ReactionService $reactionService
	) {
		parent::__construct( $main, $moduleName );
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$status = $this->reactionService->setLike( $this->getAuthority(), $params['id'], $params['value'] === '1' );
		$this->dieUnlessOK( $status );
		$value = $status->getValue();
		$this->getResult()->addValue( null, $this->getModuleName(), [
			'result' => 'success',
			'id' => $params['id'],
			'liked' => $value['liked'],
			'likes' => $value['likes'],
			'changed' => $value['changed'],
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
			'value' => [
				ParamValidator::PARAM_TYPE => [ '0', '1' ],
				ParamValidator::PARAM_REQUIRED => true,
			],
			'type' => [
				ParamValidator::PARAM_TYPE => [ ReactionStore::TYPE_LIKE ],
				ParamValidator::PARAM_DEFAULT => ReactionStore::TYPE_LIKE,
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=glowthreadreact&id=7&value=1&token=123ABC' => 'apihelp-glowthreadreact-example-like',
			'action=glowthreadreact&id=7&value=0&token=123ABC' => 'apihelp-glowthreadreact-example-unlike',
		];
	}
}
