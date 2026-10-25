<?php

namespace MediaWiki\Extension\GlowThread\Cdn;

use MediaWiki\Cache\HTMLCacheUpdater;
use MediaWiki\Extension\GlowThread\GlowThreadConfig;
use MediaWiki\Extension\GlowThread\Query\ListCache;
use MediaWiki\Extension\GlowThread\Store\PageStateStore;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageReference;
use MediaWiki\Utils\UrlUtils;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * Builds the canonical first-page list URLs and purges them from the CDN, together with the
 * page's lists in the wiki's object cache (ListCache) and its cached floor count.
 *
 * Only these exact URLs are cacheable for anonymous readers, so the browser must request
 * exactly them (they are passed to it in mw.config wgGlowThread.listUrls):
 *   {$wgRestPath}/glowthread/v1/page/{pageId}/comments?sort=hot
 *   {$wgRestPath}/glowthread/v1/page/{pageId}/comments?sort=new
 * MediaWiki purges URLs, it never sends Varnish bans, so any other variant would stay stale.
 */
class CdnPurger {

	public const SORTS = [ 'hot', 'new' ];

	/**
	 * @param GlowThreadConfig $config
	 * @param UrlUtils $urlUtils
	 * @param HTMLCacheUpdater $htmlCacheUpdater
	 * @param WANObjectCache $wanCache
	 * @param ListCache|null $listCache Null for the service
	 */
	public function __construct(
		private readonly GlowThreadConfig $config,
		private readonly UrlUtils $urlUtils,
		private readonly HTMLCacheUpdater $htmlCacheUpdater,
		private readonly WANObjectCache $wanCache,
		private ?ListCache $listCache = null
	) {
	}

	/**
	 * Path of the route without query, e.g. /w/rest.php/glowthread/v1/page/42/comments
	 */
	public function getListRoutePath( int $pageId ): string {
		return $this->config->getRestPath() . '/glowthread/v1/page/' . $pageId . '/comments';
	}

	/**
	 * Canonical first-page path for a sort, relative to the server.
	 */
	public function getListPath( int $pageId, string $sort ): string {
		return $this->getListRoutePath( $pageId ) . '?sort=' . $sort;
	}

	/**
	 * @return array<string,string> Sort => canonical relative first-page path
	 */
	public function getListPaths( int $pageId ): array {
		$paths = [];
		foreach ( self::SORTS as $sort ) {
			$paths[$sort] = $this->getListPath( $pageId, $sort );
		}
		return $paths;
	}

	/**
	 * @return string[] Absolute internal URLs of every cacheable list response of the page
	 */
	public function getPurgeUrls( int $pageId ): array {
		$urls = [];
		foreach ( $this->getListPaths( $pageId ) as $path ) {
			$url = $this->urlUtils->expand( $path, PROTO_INTERNAL );
			if ( $url !== null ) {
				$urls[] = $url;
			}
		}
		return $urls;
	}

	/**
	 * The Host header that purges carry: the host (and port, when the URL has one) of
	 * $wgInternalServer, or of $wgServer when no internal server is set. A CDN such as Varnish keys
	 * and purges objects by Host and URL, so only responses to requests with exactly this Host can
	 * be purged.
	 */
	public function getPurgeHost(): ?string {
		$url = $this->urlUtils->expand( '/', PROTO_INTERNAL );
		$parts = $url === null ? null : $this->urlUtils->parse( $url );
		if ( !$parts || !isset( $parts['host'] ) || $parts['host'] === '' ) {
			return null;
		}
		return isset( $parts['port'] ) ? $parts['host'] . ':' . $parts['port'] : $parts['host'];
	}

	/**
	 * Purge the cached list responses of a page, from the CDN and from ListCache, and forget its
	 * cached floor count so the next page view reserves the right height. The CDN purge runs
	 * after the main transaction commits (a PRESEND deferred update). The cache key is deleted at
	 * once: its hold-off keeps a read from before the commit from caching the old count again.
	 *
	 * Like core's purges after edits, it asks for a rebound purge: with $wgCdnReboundPurgeDelay
	 * set, the same URLs are purged again that many seconds later, so a list that a lagging
	 * replica answered in between does not stay cached. Core merges the purges of one request
	 * into one update, which queues one delayed job (none while the delay is 0, the default).
	 */
	public function purgePage( int $pageId ): void {
		$this->wanCache->delete( PageStateStore::makeViewKey( $this->wanCache, $pageId ) );
		$this->listCache ??= MediaWikiServices::getInstance()->getService( 'GlowThread.ListCache' );
		$this->listCache->touchPage( $pageId );
		$this->purgeUrls( $this->getPurgeUrls( $pageId ) );
	}

	/**
	 * Purge several list URLs (from getPurgeUrls()) with one CDN update, and the rebound purge
	 * described at purgePage().
	 *
	 * @param string[] $urls
	 */
	public function purgeUrls( array $urls ): void {
		if ( $urls ) {
			$this->htmlCacheUpdater->purgeUrls( $urls, HTMLCacheUpdater::PURGE_INTENT_TXROUND_REFLECTED );
		}
	}

	/**
	 * Purge the article itself, whose HTML carries the comments-enabled flag in mw.config.
	 */
	public function purgeArticle( PageReference $page ): void {
		$this->htmlCacheUpdater->purgeTitleUrls( [ $page ] );
	}
}
