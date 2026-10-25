<?php

namespace MediaWiki\Extension\GlowThread\Rest;

use MediaWiki\Extension\GlowThread\Query\CommentQueryService;
use MediaWiki\Rest\Response;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

/**
 * GET /glowthread/v1/comment/{id}
 */
class CommentHandler extends GlowThreadHandler {

	public function __construct( private readonly CommentQueryService $queryService ) {
	}

	public function run( int $id ): Response {
		$viewer = $this->queryService->newViewer( $this->getAuthority() );
		$data = $this->queryService->getComment( $viewer, $id );
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
		];
	}
}
