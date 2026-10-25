<?php

namespace MediaWiki\Extension\GlowThread\Rest;

use MediaWiki\Extension\GlowThread\Query\CommentQueryService;
use MediaWiki\Rest\Response;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

/**
 * GET /glowthread/v1/comment/{id}/replies?cursor=&limit=: more replies under one comment.
 */
class RepliesHandler extends GlowThreadHandler {

	public function __construct( private readonly CommentQueryService $queryService ) {
	}

	public function run( int $id ): Response {
		$params = $this->getValidatedParams();
		$viewer = $this->queryService->newViewer( $this->getAuthority() );
		$data = $this->queryService->getReplies( $viewer, $id, $params['cursor'], $params['limit'] );
		if ( $data === false ) {
			return $this->error( 400, 'bad-cursor' );
		}
		return $data === null ? $this->error( 404, 'comment-not-found' ) : $this->privateJson( $data );
	}

	/** @inheritDoc */
	public function getParamSettings() {
		return [
			'id' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
				IntegerDef::PARAM_MIN => 1,
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
				IntegerDef::PARAM_MAX => 100,
			],
		];
	}
}
