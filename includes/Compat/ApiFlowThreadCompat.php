<?php

namespace MediaWiki\Extension\GlowThread\Compat;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiResult;
use MediaWiki\Extension\GlowThread\Query\CommentQueryService;
use MediaWiki\ParamValidator\TypeDef\UserDef;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

/**
 * action=flowthread, read only: FlowThread's list API answered from GlowThread's tables, so
 * cached old scripts, apps and service workers keep showing comments for a while after
 * the switch. type=list for everyone who can read the page, type=listall for moderators.
 * Every write type answers with the error glowthread-legacy-readonly.
 *
 * Answers are never cacheable by a CDN: any extra parameter makes another URL, so they could not
 * be purged when a comment is hidden. GlowThread's REST list is the cached one.
 *
 * Registered by CompatHooks only when $wgGlowThreadLegacyCompat is on and FlowThread is not
 * loaded.
 */
class ApiFlowThreadCompat extends ApiBase {

	public const READ_TYPES = [ 'list', 'listall' ];
	public const WRITE_TYPES = [ 'post', 'like', 'dislike', 'report', 'delete', 'recover', 'markchecked', 'erase' ];

	/** Most top-level comments (list) or comments (listall) per request */
	public const MAX_LIMIT = 100;
	/** Deepest offset */
	public const MAX_OFFSET = 10000;

	public function __construct(
		ApiMain $main,
		string $moduleName,
		private readonly LegacyListService $lister,
		private readonly CommentQueryService $queryService
	) {
		parent::__construct( $main, $moduleName );
	}

	/** @inheritDoc */
	public function execute() {
		$params = $this->extractRequestParams();
		$type = $params['type'];
		if ( in_array( $type, self::WRITE_TYPES, true ) ) {
			$this->dieWithError( 'apierror-glowthread-legacy-readonly', 'glowthread-legacy-readonly' );
		}
		$authority = $this->getAuthority();
		$viewer = $this->queryService->newViewer( $authority );
		$hiddenName = $this->msg( 'rev-deleted-user' )->text();
		$offset = min( self::MAX_OFFSET, max( 0, (int)$params['offset'] ) );
		$limit = min( self::MAX_LIMIT, max( 1, (int)$params['limit'] ) );

		if ( $type === 'list' ) {
			if ( !$params['pageid'] ) {
				$this->dieWithError( [ 'apierror-missingparam', 'pageid' ] );
			}
			$page = $this->queryService->getPageForRead( (int)$params['pageid'], $authority );
			if ( !$page ) {
				// Also for pages the user cannot read, so their existence is not revealed
				$this->dieWithError( [ 'apierror-nosuchpageid', (int)$params['pageid'] ] );
			}
			$result = $this->lister->getPageList( $viewer, $page, $offset, $limit, $hiddenName );
			ApiResult::setIndexedTagName( $result['posts'], 'post' );
			ApiResult::setIndexedTagName( $result['popular'], 'post' );
			// Not cacheable, see the class comment. This also ignores the smaxage parameter.
			$this->getMain()->setCacheMode( 'private' );
		} else {
			$this->checkUserRightsAny( 'glowthread-moderate' );
			$pageId = (int)$params['pageid'];
			if ( !$pageId && $params['title'] !== null ) {
				$title = $this->getTitleFromTitleOrPageId( [ 'title' => $params['title'] ] );
				$pageId = $title->getArticleID();
				if ( !$pageId ) {
					$this->dieWithError( [ 'apierror-missingtitle-byname', wfEscapeWikiText( $params['title'] ) ] );
				}
			}
			$result = $this->lister->getAll(
				$viewer,
				[
					'filter' => $params['filter'],
					'pageId' => $pageId ?: null,
					'user' => $params['user'],
					'keyword' => $params['keyword'],
				],
				$params['dir'] === 'newer',
				$offset,
				$limit,
				$hiddenName
			);
			ApiResult::setIndexedTagName( $result['posts'], 'post' );
		}
		$this->getResult()->addValue( null, $this->getModuleName(), $result );
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'type' => [
				ParamValidator::PARAM_TYPE => array_merge( self::READ_TYPES, self::WRITE_TYPES ),
				ParamValidator::PARAM_REQUIRED => true,
			],
			'pageid' => [
				ParamValidator::PARAM_TYPE => 'integer',
			],
			'offset' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_DEFAULT => 0,
				IntegerDef::PARAM_MIN => 0,
				IntegerDef::PARAM_MAX => self::MAX_OFFSET,
			],
			'limit' => [
				ParamValidator::PARAM_TYPE => 'limit',
				ParamValidator::PARAM_DEFAULT => 10,
				IntegerDef::PARAM_MIN => 1,
				IntegerDef::PARAM_MAX => self::MAX_LIMIT,
				IntegerDef::PARAM_MAX2 => self::MAX_LIMIT,
			],
			'filter' => [
				ParamValidator::PARAM_TYPE => [ 'all', 'deleted', 'spam', 'reported' ],
			],
			'title' => null,
			'user' => [
				ParamValidator::PARAM_TYPE => 'user',
				UserDef::PARAM_ALLOWED_USER_TYPES => [ 'name', 'ip', 'temp' ],
			],
			'keyword' => null,
			'dir' => [
				ParamValidator::PARAM_TYPE => [ 'older', 'newer' ],
				ParamValidator::PARAM_DEFAULT => 'older',
			],
			// Accepted from old clients and ignored: every write type is refused
			'postid' => null,
			'content' => null,
			'wikitext' => false,
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=flowthread&type=list&pageid=1' => 'apihelp-flowthread-example-list',
			'action=flowthread&type=listall&filter=reported' => 'apihelp-flowthread-example-listall',
		];
	}
}
