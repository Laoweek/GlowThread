<?php

namespace MediaWiki\Extension\GlowThread\Policy;

use MediaWiki\Extension\GlowThread\GlowThreadConfig;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageLookup;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Title\TitleFactory;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * Decides on which pages comments may appear.
 *
 * A page is eligible when it exists, is in one of $wgGlowThreadNamespaces, is not a talk page,
 * not a special page and not the main page.
 *
 * The main page is recognised by its page ID, cached for an hour: Title::isMainPage() resolves
 * the mainpage message, which costs localisation cache reads on every list request.
 */
class PageEligibility {

	public const OK = null;
	public const REASON_MISSING = 'pagenotfound';
	public const REASON_NAMESPACE = 'namespace';
	public const REASON_TALK = 'talk';
	public const REASON_MAIN_PAGE = 'mainpage';

	private ?int $mainPageId = null;

	public function __construct(
		private readonly GlowThreadConfig $config,
		private readonly NamespaceInfo $namespaceInfo,
		private readonly TitleFactory $titleFactory,
		private readonly PageLookup $pageLookup,
		private readonly WANObjectCache $wanCache
	) {
	}

	/**
	 * @return string|null Null when eligible, otherwise one of the REASON_ constants
	 */
	public function getIneligibleReason( PageIdentity $page ): ?string {
		if ( !$page->canExist() || !$page->exists() ) {
			return self::REASON_MISSING;
		}
		$ns = $page->getNamespace();
		if ( $this->namespaceInfo->isTalk( $ns ) ) {
			return self::REASON_TALK;
		}
		if ( !in_array( $ns, $this->config->getNamespaces(), true ) ) {
			return self::REASON_NAMESPACE;
		}
		if ( $page->getId() === $this->getMainPageId() ) {
			return self::REASON_MAIN_PAGE;
		}
		return self::OK;
	}

	/**
	 * Page ID of the main page, 0 if it does not exist. A change of the mainpage message or a
	 * move of the main page is picked up within an hour.
	 */
	private function getMainPageId(): int {
		$this->mainPageId ??= (int)$this->wanCache->getWithSetCallback(
			$this->wanCache->makeKey( 'glowthread-mainpage-id' ),
			WANObjectCache::TTL_HOUR,
			fn () => $this->titleFactory->newMainPage()->getArticleID()
		);
		return $this->mainPageId;
	}

	public function isEligible( PageIdentity $page ): bool {
		return $this->getIneligibleReason( $page ) === self::OK;
	}

	/**
	 * An existing page by ID, if the reader may read it.
	 */
	public function getPageForRead( int $pageId, Authority $authority ): ?ProperPageIdentity {
		$page = $this->getPage( $pageId );
		if ( !$page || !$authority->authorizeRead( 'read', $page ) ) {
			return null;
		}
		return $page;
	}

	/**
	 * Look up an existing page by ID.
	 */
	public function getPage( int $pageId ): ?ProperPageIdentity {
		if ( $pageId <= 0 ) {
			return null;
		}
		$page = $this->pageLookup->getPageById( $pageId );
		return $page && $page->exists() ? $page : null;
	}
}
