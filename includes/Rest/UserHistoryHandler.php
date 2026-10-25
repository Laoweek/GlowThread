<?php

namespace MediaWiki\Extension\GlowThread\Rest;

use MediaWiki\Extension\GlowThread\Query\ModerationQueryService;
use MediaWiki\Rest\Response;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

/**
 * GET /glowthread/v1/moderation/user/{name}?cursor=&limit=
 */
class UserHistoryHandler extends GlowThreadHandler {

	public function __construct( private readonly ModerationQueryService $queryService ) {
	}

	public function run( string $name ): Response {
		if ( !$this->getAuthority()->isAllowed( 'glowthread-moderate' ) ) {
			return $this->error( 403, 'permission-denied' );
		}
		$params = $this->getValidatedParams();
		$viewer = $this->queryService->newViewer( $this->getAuthority() );
		$data = $this->queryService->getUserHistory( $viewer, $name, $params['cursor'], $params['limit'] );
		if ( $data === false ) {
			return $this->error( 400, 'bad-cursor' );
		}
		return $data === null ? $this->error( 404, 'user-not-found' ) : $this->privateJson( $data );
	}

	/** @inheritDoc */
	public function getParamSettings() {
		return [
			'name' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
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
