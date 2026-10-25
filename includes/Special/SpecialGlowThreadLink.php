<?php

namespace MediaWiki\Extension\GlowThread\Special;

use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Query\CommentQueryService;
use MediaWiki\Extension\GlowThread\Store\PostStore;
use MediaWiki\SpecialPage\UnlistedSpecialPage;
use MediaWiki\Title\TitleFactory;

/**
 * Permalinks to comments. Redirects to the page with the fragment #gt-<id>.
 *
 * Accepted forms:
 * - Special:GlowThreadLink/<id>          GlowThread comment ID
 * - Special:GlowThreadLink/<22 hex>      FlowThread ID of an imported comment
 * - Special:GlowThreadLink/<Page>/<n>    floor number n on a page
 *
 * Comments the reader cannot see get the same "not found" answer as missing ones.
 * Special:FlowThreadLink, FlowThread's permalink page, is an alias of this page (see
 * GlowThread.alias.php), so links from a former FlowThread install keep working. While
 * FlowThread itself is loaded, its own page keeps that name.
 */
class SpecialGlowThreadLink extends UnlistedSpecialPage {

	public const NAME = 'GlowThreadLink';

	public function __construct(
		private readonly PostStore $postStore,
		private readonly CommentQueryService $queryService,
		private readonly TitleFactory $titleFactory
	) {
		parent::__construct( self::NAME );
	}

	/** @inheritDoc */
	public function execute( $subPage ) {
		$this->setHeaders();
		$post = $this->findPost( trim( (string)$subPage ) );
		$context = $post ? $this->queryService->getVisibleContext(
			$this->queryService->newViewer( $this->getAuthority() ), $post
		) : null;
		if ( !$post || !$context ) {
			$out = $this->getOutput();
			$out->setStatusCode( 404 );
			$out->addWikiMsg( 'glowthread-link-notfound' );
			return;
		}
		$title = $this->titleFactory->newFromPageIdentity( $context[0] );
		$this->getOutput()->redirect( $title->createFragmentTarget( 'gt-' . $post->getId() )
			->getFullURL( $this->getMarkAsReadQuery(), false, PROTO_CURRENT ) );
	}

	/**
	 * Echo's notification links carry markasread (and markasreadwiki). Echo acts on them when a
	 * page is rendered, which a redirect skips, so they are passed on to the comment's page.
	 *
	 * @return array<string,string>
	 */
	private function getMarkAsReadQuery(): array {
		$query = [];
		foreach ( [ 'markasread', 'markasreadwiki' ] as $name ) {
			$value = $this->getRequest()->getText( $name );
			if ( $value !== '' ) {
				$query[$name] = $value;
			}
		}
		return $query;
	}

	private function findPost( string $subPage ): ?PostRecord {
		if ( $subPage === '' ) {
			return null;
		}
		if ( preg_match( '/^\d{1,10}$/', $subPage ) ) {
			return $this->postStore->getById( (int)$subPage );
		}
		if ( preg_match( '/^[0-9a-fA-F]{22}$/', $subPage ) ) {
			return $this->postStore->getByLegacyId( $subPage );
		}
		if ( preg_match( '/^(.+)\/(\d{1,10})$/', $subPage, $m ) ) {
			$title = $this->titleFactory->newFromText( $m[1] );
			if ( $title && $title->getArticleID() ) {
				return $this->postStore->getByPageAndSeq( $title->getArticleID(), (int)$m[2] );
			}
		}
		return null;
	}
}
