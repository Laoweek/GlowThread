<?php

namespace MediaWiki\Extension\GlowThread\Rest;

use MediaWiki\Extension\GlowThread\Query\ModerationQueryService;
use MediaWiki\Rest\Response;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

/**
 * GET /glowthread/v1/moderation/page/{pageId}
 */
class PageHistoryHandler extends GlowThreadHandler {

	public function __construct( private readonly ModerationQueryService $queryService ) {
	}

	public function run( int $pageId ): Response {
		if ( !$this->getAuthority()->isAllowed( 'glowthread-moderate' ) ) {
			return $this->error( 403, 'permission-denied' );
		}
		$viewer = $this->queryService->newViewer( $this->getAuthority() );
		$data = $this->queryService->getPageHistory( $viewer, $pageId );
		// Like the reader routes, a page the viewer cannot read is answered as missing
		return $data === null ? $this->error( 404, 'page-not-found' ) : $this->privateJson( $data );
	}

	/** @inheritDoc */
	public function getParamSettings() {
		return [
			'pageId' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
				IntegerDef::PARAM_MIN => 1,
			],
		];
	}
}
