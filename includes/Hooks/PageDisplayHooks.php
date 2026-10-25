<?php

namespace MediaWiki\Extension\GlowThread\Hooks;

use MediaWiki\Diff\Hook\DifferenceEngineShowDiffPageHook;
use MediaWiki\Extension\GlowThread\Cdn\CdnPurger;
use MediaWiki\Extension\GlowThread\GlowThreadConfig;
use MediaWiki\Extension\GlowThread\Model\PageState;
use MediaWiki\Extension\GlowThread\Policy\PageEligibility;
use MediaWiki\Extension\GlowThread\Store\PageStateStore;
use MediaWiki\Html\Html;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\Hook\OutputPageParserOutputHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\Hook\ArticleViewFooterHook;

/**
 * Adds the tiny loader module and page facts to eligible article views.
 *
 * The config is the same for every reader of the page (the HTML is cached by the CDN), so it
 * holds nothing about the current user. Personal state comes from the list route.
 *
 * Readers without JavaScript get a short notice in a <noscript> element at the end of the
 * page content (inside the content area in every skin), because the comments need the app.
 *
 * With $wgGlowThreadTheme set to night or day, the html element gets the class that fixes the
 * colour scheme of the comment section.
 *
 * Readers with JavaScript get the section's space reserved before the first paint: a small
 * styles module (ext.glowThread.reserve) and a body class with a size class, chosen from the
 * page's floor counter (on a closed page, the height of its note). The loader swaps the
 * reservation for the section in one frame, so the footer does not move when the comments
 * arrive.
 *
 * Logged-in readers get frame protection (X-Frame-Options, $wgEditPageFrameOptions) where the
 * section shows, because its buttons act in one click (like, report, approve, hide) and a
 * hostile page could frame the article over a decoy. Article views and diffs allow framing
 * (Article::view() and DifferenceEngine::showDiffPage() turn the protection off), and OutputPage
 * sends the header before BeforePageDisplay runs. So it is set when the page's parser output is
 * added (OutputPageParserOutput: article views and the article under a diff), in the view footer
 * (ArticleViewFooter: article views rendered without a parser output), and on every diff of such
 * a page (DifferenceEngineShowDiffPage: the article under it can be rendered without one too).
 * Logged-out views stay frameable, as core leaves them: those readers cannot act by default, and
 * the CDN shares their HTML.
 */
class PageDisplayHooks implements
	BeforePageDisplayHook,
	OutputPageParserOutputHook,
	ArticleViewFooterHook,
	DifferenceEngineShowDiffPageHook
{

	/** Floor counts where the reserved height steps up (resources/init/reserve.css). */
	private const SIZES = [ 0, 1, 2, 3, 5, 8, 13, 21 ];

	public function __construct(
		private readonly PageEligibility $pageEligibility,
		private readonly PageStateStore $pageStateStore,
		private readonly CdnPurger $cdnPurger,
		private readonly GlowThreadConfig $config
	) {
	}

	/**
	 * @param OutputPage $out
	 * @param \Skin $skin
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		if ( !$this->showsComments( $out ) ) {
			return;
		}
		$pageId = $out->getTitle()->getArticleID();
		// The state and the floor counter, in one cache read (PageStateStore::getViewFacts())
		$facts = $this->pageStateStore->getViewFacts( $pageId );
		$state = $facts['state'];
		$out->addModules( 'ext.glowThread.init' );
		$themeClass = $this->config->getThemeClass();
		if ( $themeClass !== null ) {
			$out->addHtmlClasses( $themeClass );
		}
		$out->addJsConfigVars( 'wgGlowThread', $this->getJsConfig( $pageId, $state ) );
		if ( $state !== PageState::DISABLED ) {
			$out->addHTML( Html::rawElement( 'noscript', [],
				Html::element( 'p', [ 'class' => 'ext-glowthread-nojs' ], $out->msg( 'glowthread-nojs' )->text() )
			) );
		}
		$out->addModuleStyles( 'ext.glowThread.reserve' );
		$out->addBodyClasses( [
			'ext-glowthread-reserve',
			// A closed page shows readers a one-line note.
			'ext-glowthread-size-' . ( $state === PageState::DISABLED
				? 'off' : self::sizeClass( $facts['seq'] ) ),
		] );
	}

	/**
	 * @param OutputPage $outputPage
	 * @param \MediaWiki\Parser\ParserOutput $parserOutput
	 */
	public function onOutputPageParserOutput( $outputPage, $parserOutput ): void {
		$this->preventFraming( $outputPage );
	}

	/**
	 * @param \Article $article
	 * @param bool $patrolFooterShown
	 */
	public function onArticleViewFooter( $article, $patrolFooterShown ) {
		$this->preventFraming( $article->getContext()->getOutput() );
	}

	/**
	 * Runs right after DifferenceEngine::showDiffPage() allowed framing, before it knows whether
	 * the current revision shows below the diff, so every diff of a page with comments counts.
	 *
	 * @param OutputPage $out
	 */
	public function onDifferenceEngineShowDiffPage( $out ) {
		$title = $out->getTitle();
		if ( $title && $out->getUser()->isRegistered() && $this->pageEligibility->isEligible( $title ) ) {
			$out->getMetadata()->setPreventClickjacking( true );
		}
	}

	/**
	 * Frame protection for logged-in readers of a page with the comment section (see the class
	 * comment). It goes on the OutputPage, never on the ParserOutput, which the parser cache shares.
	 */
	private function preventFraming( OutputPage $out ): void {
		if ( $out->getUser()->isRegistered() && $this->showsComments( $out ) ) {
			$out->getMetadata()->setPreventClickjacking( true );
		}
	}

	/**
	 * Whether the output is a view of the current revision of a page that gets the comment section.
	 */
	private function showsComments( OutputPage $out ): bool {
		$title = $out->getTitle();
		return $title
			&& $out->isArticle()
			&& $out->getActionName() === 'view'
			&& $out->isRevisionCurrent()
			&& $this->pageEligibility->isEligible( $title );
	}

	/**
	 * The reservation step for a floor count: the largest of SIZES not above it.
	 */
	public static function sizeClass( int $floors ): int {
		$size = 0;
		foreach ( self::SIZES as $step ) {
			if ( $floors >= $step ) {
				$size = $step;
			}
		}
		return $size;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getJsConfig( int $pageId, string $state ): array {
		return [
			'pageId' => $pageId,
			'enabled' => $state !== PageState::DISABLED,
			'state' => $state,
			'defaultSort' => 'hot',
			'listUrls' => $this->cdnPurger->getListPaths( $pageId ),
			'restBase' => $this->config->getRestPath() . '/glowthread/v1',
			'features' => [
				'legacyCompat' => $this->config->isLegacyCompatEnabled(),
			],
		];
	}
}
