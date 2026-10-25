<?php

namespace MediaWiki\Extension\GlowThread\Api;

use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiResult;
use MediaWiki\Extension\GlowThread\Service\ModerationService;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * action=glowthreadmoderate: approve, delete, restore, erase, pin, unpin or dismiss reports
 * on up to 50 comments, or undo earlier actions exactly (do=undo with their log IDs).
 */
class ApiGlowThreadModerate extends ApiGlowThreadBase {

	public function __construct(
		ApiMain $main,
		string $moduleName,
		private readonly ModerationService $moderationService
	) {
		parent::__construct( $main, $moduleName );
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$status = $this->moderationService->moderate(
			$this->getAuthority(), $params['do'], $params['ids'], $params['reason'], $params['logids'] ?? []
		);
		$this->dieUnlessOK( $status );
		$results = $status->getValue();
		$errors = count( array_filter( $results, static fn ( $r ) => $r['result'] === 'error' ) );
		if ( $errors === 0 ) {
			$overall = 'success';
		} elseif ( $errors === count( $results ) ) {
			$overall = 'failure';
		} else {
			$overall = 'partial';
		}
		ApiResult::setIndexedTagName( $results, 'comment' );
		$this->getResult()->addValue( null, $this->getModuleName(), [
			'result' => $overall,
			'do' => $params['do'],
			'results' => $results,
		] );
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'ids' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_ISMULTI_LIMIT1 => ModerationService::MAX_IDS,
				ParamValidator::PARAM_ISMULTI_LIMIT2 => ModerationService::MAX_IDS,
				// Kept for undo, which pairs ids with logids. The other actions skip repeats.
				ParamValidator::PARAM_ALLOW_DUPLICATES => true,
			],
			'do' => [
				ParamValidator::PARAM_TYPE => ModerationService::ACTIONS,
				ParamValidator::PARAM_REQUIRED => true,
			],
			'logids' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_ISMULTI_LIMIT1 => ModerationService::MAX_IDS,
				ParamValidator::PARAM_ISMULTI_LIMIT2 => ModerationService::MAX_IDS,
				// Kept for undo, which pairs ids with logids. The other actions skip repeats.
				ParamValidator::PARAM_ALLOW_DUPLICATES => true,
			],
			'reason' => $this->getReasonParam(),
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=glowthreadmoderate&do=delete&ids=7|8&reason=Spam&token=123ABC'
				=> 'apihelp-glowthreadmoderate-example-delete',
			'action=glowthreadmoderate&do=approve&ids=9&token=123ABC'
				=> 'apihelp-glowthreadmoderate-example-approve',
			'action=glowthreadmoderate&do=undo&ids=7|8&logids=120|121&token=123ABC'
				=> 'apihelp-glowthreadmoderate-example-undo',
		];
	}
}
