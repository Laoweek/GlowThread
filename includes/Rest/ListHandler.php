<?php

namespace MediaWiki\Extension\GlowThread\Rest;

use Closure;
use MediaWiki\Extension\GlowThread\Cdn\CdnPurger;
use MediaWiki\Extension\GlowThread\GlowThreadConfig;
use MediaWiki\Extension\GlowThread\Policy\PageEligibility;
use MediaWiki\Extension\GlowThread\Query\CommentQueryService;
use MediaWiki\Extension\GlowThread\Query\Cursor;
use MediaWiki\Extension\GlowThread\Query\ListCache;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\StringStream;
use MediaWiki\Title\TitleFormatter;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * GET /glowthread/v1/page/{pageId}/comments?sort=hot|new&cursor=&limit=
 *
 * The first page of each sort, requested by an anonymous reader at exactly the canonical URL
 * (only the sort parameter) under the Host that purges reach, is public and cacheable for
 * $wgGlowThreadCdnMaxAge seconds when $wgUseCdn is on, and is purged on every write to the page
 * that changes what readers see. Everything else is private.
 *
 * The wiki also keeps the anonymous first pages in its object cache (ListCache), for every URL
 * and Host, until the page changes: a cached copy is answered without the comment queries, and
 * without making the query service at all.
 */
class ListHandler extends GlowThreadHandler {

	/** Rights that change an anonymous list (CommentQueryService::getPagePermissions(), Viewer) */
	private const ANON_RIGHTS = [
		'glowthread-post', 'glowthread-react', 'glowthread-report', 'glowthread-erase', 'hideuser',
	];

	private ?CommentQueryService $queryService = null;

	/**
	 * @param Closure $queryServiceProvider Returns the CommentQueryService, made only when needed
	 * @param GlowThreadConfig $config
	 * @param CdnPurger $cdnPurger
	 * @param ListCache $listCache
	 * @param PageEligibility $pageEligibility
	 * @param TitleFormatter $titleFormatter
	 */
	public function __construct(
		private readonly Closure $queryServiceProvider,
		private readonly GlowThreadConfig $config,
		private readonly CdnPurger $cdnPurger,
		private readonly ListCache $listCache,
		private readonly PageEligibility $pageEligibility,
		private readonly TitleFormatter $titleFormatter
	) {
	}

	/**
	 * Factory for the route (extension.json): the query service comes from the service container
	 * when a request needs it.
	 */
	public static function factory(
		GlowThreadConfig $config,
		CdnPurger $cdnPurger,
		ListCache $listCache,
		PageEligibility $pageEligibility,
		TitleFormatter $titleFormatter
	): self {
		return new self(
			static fn () => MediaWikiServices::getInstance()->getService( 'GlowThread.CommentQueryService' ),
			$config, $cdnPurger, $listCache, $pageEligibility, $titleFormatter
		);
	}

	private function getQueryService(): CommentQueryService {
		$this->queryService ??= ( $this->queryServiceProvider )();
		return $this->queryService;
	}

	public function run( int $pageId ): Response {
		$params = $this->getValidatedParams();
		$authority = $this->getAuthority();
		$page = $this->pageEligibility->getPageForRead( $pageId, $authority );
		if ( $page === null ) {
			return $this->error( 404, 'page-not-found' );
		}
		$sort = $params['sort'];
		$cursor = CommentQueryService::decodeListCursor( $sort, $params['cursor'] );
		// An empty cursor names no page: the app leaves the parameter out for the first page. A CDN
		// looks such a request up as the cached first page (docs/caching.md), so one that still
		// arrives here, spelled in a way the CDN does not read as cursor ("+cursor="), would only
		// run the uncached first-page query.
		if ( $cursor === false
			|| ( $params['cursor'] === '' && array_key_exists( 'cursor', $this->getRequest()->getQueryParams() ) )
		) {
			return $this->error( 400, 'bad-cursor' );
		}
		$limit = $params['limit'] ?? $this->config->getPageSize();
		// Not for a client that wrote in the last seconds (ListCache::mayUse())
		$json = $cursor === null && !$authority->isRegistered() && $this->listCache->mayUse()
			? $this->getAnonFirstPage( $page, $sort, $limit )
			: null;
		if ( $json === null ) {
			$queryService = $this->getQueryService();
			$viewer = $queryService->newViewer( $authority );
			$json = $this->getResponseFactory()->encodeJson(
				$queryService->getPageList( $viewer, $page, $sort, $cursor, $limit )
			);
		}
		// The response and headers of ResponseFactory::createJson(), with the body already encoded
		$response = $this->getResponseFactory()->createJson( [] );
		$response->setBody( new StringStream( $json ) );
		$response->setHeader( 'Cache-Control', $this->isCacheable( $page->getId(), $sort, $cursor ) ? sprintf(
			'public, s-maxage=%d, max-age=0', $this->config->getCdnMaxAge()
		) : self::PRIVATE_CACHE );
		return $response;
	}

	/**
	 * The JSON body of the anonymous first page from ListCache, null for readers whose rights
	 * make it differ from page to page (glowthread-moderate: moderators see every status).
	 *
	 * The key holds everything else the answer depends on. The hot cursor holds the time of the
	 * request (the order does not change with it, see HotRanker), so a cached copy gets the time
	 * of this request, as the query service would give it.
	 */
	private function getAnonFirstPage( ProperPageIdentity $page, string $sort, int $limit ): ?string {
		$authority = $this->getAuthority();
		if ( $authority->isAllowed( 'glowthread-moderate' ) ) {
			return null;
		}
		$rights = [];
		foreach ( self::ANON_RIGHTS as $right ) {
			$rights[] = $authority->isAllowed( $right );
		}
		$variant = [
			$limit,
			$this->pageEligibility->isEligible( $page ),
			$rights,
			$this->titleFormatter->getPrefixedText( $page ),
			$this->config->getOutputConfig(),
		];
		$value = $this->listCache->getAnonResponse(
			$page->getId(),
			$sort,
			$variant,
			function ( bool $storable ) use ( $page, $sort, $limit ) {
				$queryService = $this->getQueryService();
				$viewer = $queryService->newViewer( $this->getAuthority() );
				// The facts only for a list that may be stored: they cost another look at every text
				return $storable
					? [ $queryService->getPageList( $viewer, $page, $sort, null, $limit, $facts ), $facts ]
					: [ $queryService->getPageList( $viewer, $page, $sort, null, $limit ), null ];
			},
			fn ( array $data ) => $this->getResponseFactory()->encodeJson( $data ),
			$authority->isAllowed( 'hideuser' )
		);
		$json = $value['body'];
		// A body computed now has the cursor of its own ranking already
		if ( $value['cached'] && $value['next'] !== null ) {
			$now = Cursor::encode( 'hot', [ 'n' => ConvertibleTimestamp::time(), 'o' => $limit ] );
			$json = str_replace( '"next":"' . $value['next'] . '"', '"next":"' . $now . '"', $json );
		}
		return $json;
	}

	/**
	 * Cacheable only for anonymous readers without a session, on the first page, at exactly the
	 * canonical URL that CdnPurger purges: byte for byte the same path and query, so that
	 * variants such as /page/042/ or ?%73ort=hot are never cached unpurged. The Host header must
	 * be exactly the one purges carry, or a copy cached under another host name (an IP address,
	 * a second domain) would outlive every purge.
	 */
	private function isCacheable( int $pageId, string $sort, ?array $cursor ): bool {
		if ( $cursor !== null
			|| $this->config->getCdnMaxAge() <= 0
			|| $this->getAuthority()->isRegistered()
			|| $this->getSession()->isPersistent()
		) {
			return false;
		}
		$purgeHost = $this->cdnPurger->getPurgeHost();
		if ( $purgeHost === null || $this->getRequest()->getHeaderLine( 'Host' ) !== $purgeHost ) {
			return false;
		}
		$uri = $this->getRequest()->getUri();
		return $uri->getPath() . '?' . $uri->getQuery() === $this->cdnPurger->getListPath( $pageId, $sort );
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
			'sort' => [
				self::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => CommentQueryService::SORTS,
				ParamValidator::PARAM_DEFAULT => CommentQueryService::SORT_HOT,
			],
			'cursor' => [
				self::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => '',
			],
			'limit' => [
				self::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				IntegerDef::PARAM_MIN => 1,
				IntegerDef::PARAM_MAX => 50,
			],
		];
	}
}
