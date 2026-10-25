<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Hooks;

use Article;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Output\OutputPage;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;

/**
 * The comment section acts in one click, so a logged-in reader's view of it must not be framed
 * by another site (clickjacking). Core allows framing on article views and diffs, and sends
 * X-Frame-Options before BeforePageDisplay, so these tests render the page the way core does.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Hooks\PageDisplayHooks
 */
class PageDisplayFramingTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
		$this->overrideConfigValues( [ 'BreakFrames' => false, 'EditPageFrameOptions' => 'DENY' ] );
	}

	private function context( Title $title, User $user, array $query = [] ): RequestContext {
		$context = new RequestContext();
		$context->setRequest( new FauxRequest( [ 'title' => $title->getPrefixedDBkey() ] + $query ) );
		$context->setTitle( $title );
		$context->setUser( $user );
		$context->setLanguage( 'en' );
		return $context;
	}

	/**
	 * Core's article view, then BeforePageDisplay as OutputPage::output() runs it after the
	 * header is decided.
	 */
	private function view( Title $title, User $user ): OutputPage {
		$context = $this->context( $title, $user );
		Article::newFromTitle( $title, $context )->view();
		return $this->display( $context );
	}

	private function display( RequestContext $context ): OutputPage {
		$out = $context->getOutput();
		$this->getServiceContainer()->getHookContainer()->run( 'BeforePageDisplay', [ $out, $context->getSkin() ] );
		return $out;
	}

	public function testLoggedInViewWithCommentsCannotBeFramed() {
		$title = Title::newFromPageIdentity( $this->gtPage( 'GlowThread framing page' ) );
		$out = $this->view( $title, $this->gtUser() );
		$this->assertContains( 'ext.glowThread.init', $out->getModules() );
		$this->assertSame( 'DENY', $out->getFrameOptions() );
	}

	public function testLoggedOutViewStaysFrameable() {
		$title = Title::newFromPageIdentity( $this->gtPage( 'GlowThread framing page' ) );
		$out = $this->view( $title, $this->getServiceContainer()->getUserFactory()->newAnonymous() );
		$this->assertContains( 'ext.glowThread.init', $out->getModules() );
		$this->assertFalse( $out->getFrameOptions(), 'core leaves article views frameable' );
	}

	public function testPagesWithoutCommentsKeepCoreBehaviour() {
		$title = Title::makeTitle( NS_TALK, 'GlowThread framing talk' );
		$this->getExistingTestPage( $title );
		$out = $this->view( $title, $this->gtUser() );
		$this->assertNotContains( 'ext.glowThread.init', $out->getModules() );
		$this->assertFalse( $out->getFrameOptions() );
	}

	/**
	 * A diff of the current revision shows the article and its comment section below it.
	 */
	public function testDiffOfCurrentRevisionCannotBeFramed() {
		$title = Title::newFromText( 'GlowThread framing diff' );
		$first = $this->editPage( $title, 'First' )->getNewRevision();
		$second = $this->editPage( $title, 'Second' )->getNewRevision();
		$context = $this->context( $title, $this->gtUser(),
			[ 'diff' => $second->getId(), 'oldid' => $first->getId() ] );
		$this->getServiceContainer()->getContentHandlerFactory()
			->getContentHandler( CONTENT_MODEL_WIKITEXT )
			->createDifferenceEngine( $context, $first->getId(), $second->getId() )
			->showDiffPage();
		$out = $this->display( $context );
		$this->assertContains( 'ext.glowThread.init', $out->getModules() );
		$this->assertSame( 'DENY', $out->getFrameOptions() );
	}

	/**
	 * Under a diff, too, an extension can render the current revision itself, without a parser
	 * output.
	 */
	public function testDiffWithCustomRenderedRevisionCannotBeFramed() {
		$this->setTemporaryHook( 'ArticleRevisionViewCustom', static fn () => false );
		$this->clearHook( 'OutputPageParserOutput' );
		$title = Title::newFromText( 'GlowThread framing custom diff' );
		$first = $this->editPage( $title, 'First' )->getNewRevision();
		$second = $this->editPage( $title, 'Second' )->getNewRevision();
		$context = $this->context( $title, $this->gtUser(),
			[ 'diff' => $second->getId(), 'oldid' => $first->getId() ] );
		$this->getServiceContainer()->getContentHandlerFactory()
			->getContentHandler( CONTENT_MODEL_WIKITEXT )
			->createDifferenceEngine( $context, $first->getId(), $second->getId() )
			->showDiffPage();
		$out = $this->display( $context );
		$this->assertContains( 'ext.glowThread.init', $out->getModules() );
		$this->assertSame( 'DENY', $out->getFrameOptions() );
	}

	/**
	 * An extension that renders the page itself adds no parser output, so OutputPageParserOutput
	 * does not run (cleared here, so a parser cache hit cannot stand in). The view footer still
	 * protects the page.
	 */
	public function testCustomRenderedViewCannotBeFramed() {
		$this->setTemporaryHook( 'ArticleRevisionViewCustom', static fn () => false );
		$this->clearHook( 'OutputPageParserOutput' );
		$title = Title::newFromPageIdentity( $this->gtPage( 'GlowThread framing page' ) );
		$out = $this->view( $title, $this->gtUser() );
		$this->assertContains( 'ext.glowThread.init', $out->getModules() );
		$this->assertSame( 'DENY', $out->getFrameOptions() );
	}
}
