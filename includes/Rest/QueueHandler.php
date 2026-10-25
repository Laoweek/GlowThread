<?php

namespace MediaWiki\Extension\GlowThread\Rest;

use MediaWiki\Extension\GlowThread\Query\ModerationQueryService;
use MediaWiki\Rest\Response;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

/**
 * GET /glowthread/v1/moderation/queue?tab=&sort=&pageid=&user=&reporter=&q=&cursor=&limit=
 */
class QueueHandler extends GlowThreadHandler {

	public function __construct( private readonly ModerationQueryService $queryService ) {
	}

	public function run(): Response {
		if ( !$this->getAuthority()->isAllowed( 'glowthread-moderate' ) ) {
			return $this->error( 403, 'permission-denied' );
		}
		$params = $this->getValidatedParams();
		$filters = [];
		if ( $params['pageid'] !== null ) {
			$filters['pageId'] = $params['pageid'];
		}
		if ( $params['user'] !== '' ) {
			$filters['user'] = $params['user'];
		}
		if ( $params['reporter'] !== '' ) {
			$filters['reporter'] = $params['reporter'];
		}
		if ( $params['q'] !== '' ) {
			$filters['q'] = $params['q'];
		}
		$viewer = $this->queryService->newViewer( $this->getAuthority() );
		$data = $this->queryService->getQueue(
			$viewer, $params['tab'], $filters, $params['cursor'], $params['limit'], $params['sort']
		);
		return $data === false ? $this->error( 400, 'bad-cursor' ) : $this->privateJson( $data );
	}

	/** @inheritDoc */
	public function getParamSettings() {
		return [
			'tab' => [
				self::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => ModerationQueryService::TABS,
				ParamValidator::PARAM_DEFAULT => 'reported',
			],
			'sort' => [
				self::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => ModerationQueryService::SORTS,
				ParamValidator::PARAM_REQUIRED => false,
			],
			'pageid' => [
				self::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				IntegerDef::PARAM_MIN => 1,
			],
			'user' => [
				self::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => '',
			],
			'reporter' => [
				self::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => '',
			],
			'q' => [
				self::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => '',
			],
			'cursor' => [
				self::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => '',
			],
			'limit' => [
				self::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_DEFAULT => 20,
				IntegerDef::PARAM_MIN => 1,
				IntegerDef::PARAM_MAX => 50,
			],
		];
	}
}
