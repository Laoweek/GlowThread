<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Hooks;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\GlowThread\Hooks\PageDisplayHooks;
use MediaWiki\Extension\GlowThread\Store\PageStateStore;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Output\OutputPage;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\TestingAccessWrapper;

/**
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Hooks\PageDisplayHooks
 */
class PageDisplayHooksTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
	}

	private function render( Title $title ): OutputPage {
		$context = new RequestContext();
		$context->setTitle( $title );
		$context->setUser( $this->gtUser() );
		$out = $context->getOutput();
		$out->setArticleFlag( true );
		$out->setRevisionId( $title->getLatestRevID() );
		$this->getServiceContainer()->getHookContainer()->run( 'BeforePageDisplay', [ $out, $context->getSkin() ] );
		return $out;
	}

	public function testEligiblePageGetsLoaderAndConfig() {
		$page = $this->gtPage( 'GlowThread display page' );
		$out = $this->render( Title::newFromPageIdentity( $page ) );
		$this->assertContains( 'ext.glowThread.init', $out->getModules() );
		$config = $out->getJsConfigVars()['wgGlowThread'];
		$this->assertSame( $page->getId(), $config['pageId'] );
		$this->assertTrue( $config['enabled'] );
		$this->assertSame( 'hot', $config['defaultSort'] );
		$route = '/glowthread/v1/page/' . $page->getId() . '/comments';
		$this->assertStringEndsWith( "$route?sort=hot", $config['listUrls']['hot'] );
		$this->assertStringEndsWith( "$route?sort=new", $config['listUrls']['new'] );
		$this->assertStringNotContainsString( 'TestUser', json_encode( $config ), 'nothing about the reader' );
	}

	public static function provideThemes() {
		yield 'auto' => [ 'auto', [] ];
		yield 'night' => [ 'night', [ 'ext-glowthread-night' ] ];
		yield 'day' => [ 'day', [ 'ext-glowthread-day' ] ];
		yield 'unknown value counts as auto' => [ 'dark', [] ];
	}

	/**
	 * $wgGlowThreadTheme fixes the colour scheme through a class on the html element, only on
	 * pages where the comment section loads.
	 *
	 * @dataProvider provideThemes
	 */
	public function testThemeClass( string $theme, array $classes ) {
		$this->overrideConfigValue( 'GlowThreadTheme', $theme );
		$page = $this->gtPage( 'GlowThread display page' );
		$out = $this->render( Title::newFromPageIdentity( $page ) );
		$this->assertSame( $classes, TestingAccessWrapper::newFromObject( $out )->mAdditionalHtmlClasses );

		$talk = $this->getExistingTestPage( Title::newFromText( 'Talk:GlowThread display page' ) );
		$out = $this->render( Title::newFromPageIdentity( $talk ) );
		$this->assertNotContains( 'ext.glowThread.init', $out->getModules(), 'no comments on talk pages' );
		$this->assertSame( [], TestingAccessWrapper::newFromObject( $out )->mAdditionalHtmlClasses );
	}

	public function testDisabledPageSaysSo() {
		$page = $this->gtPage( 'GlowThread display page' );
		$this->gtSetPageState( $page, 'disabled' );
		$out = $this->render( Title::newFromPageIdentity( $page ) );
		$this->assertFalse( $out->getJsConfigVars()['wgGlowThread']['enabled'] );
		$this->assertStringNotContainsString( 'ext-glowthread-nojs', $out->getHTML() );
	}

	/**
	 * Readers without JavaScript are told why there are no comments, at the
	 * end of the page content.
	 */
	public function testNoJavaScriptNotice() {
		$page = $this->gtPage( 'GlowThread display page' );
		$out = $this->render( Title::newFromPageIdentity( $page ) );
		$this->assertMatchesRegularExpression(
			'#<noscript><p class="ext-glowthread-nojs">[^<]+</p></noscript>#', $out->getHTML()
		);
	}

	/**
	 * ResourceLoader's CSSJanus flips the bundles' styles for right-to-left user languages, and
	 * only for them.
	 */
	public function testAppModulesFlipForRightToLeftLanguages() {
		$rl = $this->getServiceContainer()->getResourceLoader();
		$rtl = new \MediaWiki\ResourceLoader\Context( $rl, new \MediaWiki\Request\FauxRequest( [ 'lang' => 'he' ] ) );
		$ltr = new \MediaWiki\ResourceLoader\Context( $rl, new \MediaWiki\Request\FauxRequest( [ 'lang' => 'en' ] ) );
		foreach ( [ 'ext.glowThread.app', 'ext.glowThread.admin' ] as $name ) {
			$this->assertTrue( $rl->getModule( $name )->getFlip( $rtl ), $name );
			$this->assertFalse( $rl->getModule( $name )->getFlip( $ltr ), $name );
		}
	}

	/**
	 * The section's space is reserved before the first
	 * paint, sized from the page's floor counter, so the footer does not move when the
	 * loader inserts the section.
	 */
	public function testReservesTheSectionSpaceSizedByFloors() {
		$page = $this->gtPage( 'GlowThread display page' );
		$out = $this->render( Title::newFromPageIdentity( $page ) );
		$this->assertContains( 'ext.glowThread.reserve', $out->getModuleStyles() );
		$classes = TestingAccessWrapper::newFromObject( $out )->mAdditionalBodyClasses;
		$this->assertContains( 'ext-glowthread-reserve', $classes );
		$this->assertContains( 'ext-glowthread-size-0', $classes );

		$author = $this->gtUser( [ 'sysop' ] );
		foreach ( [ 'one', 'two', 'three' ] as $text ) {
			$this->gtPost( $author, $page, "Comment $text" );
		}
		// The floor count is cached, and every new comment clears it
		$out = $this->render( Title::newFromPageIdentity( $page ) );
		$classes = TestingAccessWrapper::newFromObject( $out )->mAdditionalBodyClasses;
		$this->assertContains( 'ext-glowthread-size-3', $classes );
	}

	/**
	 * The state and the floor counter come from one cached value, which a change of the state
	 * and a new comment both clear.
	 */
	public function testStateAndFloorsAreCachedTogether() {
		// An hour back: the cache refuses values read (by the real clock) after its own time
		$now = microtime( true ) - 3600;
		$wanCache = new WANObjectCache( [ 'cache' => new HashBagOStuff() ] );
		$wanCache->setMockTime( $now );
		$this->setService( 'MainWANObjectCache', $wanCache );
		$page = $this->gtPage( 'GlowThread display page' );
		$title = Title::newFromPageIdentity( $page );
		$key = PageStateStore::makeViewKey( $wanCache, $page->getId() );
		$this->gtPost( $this->gtUser(), $page, 'One' );
		$now += 60;
		$this->render( $title );
		$this->assertSame( [ 'state' => 'open', 'seq' => 1 ], $wanCache->get( $key ) );

		$now += 60;
		$this->gtSetPageState( $page, 'disabled' );
		$now += 60;
		$out = $this->render( $title );
		$this->assertFalse( $out->getJsConfigVars()['wgGlowThread']['enabled'] );
		$classes = TestingAccessWrapper::newFromObject( $out )->mAdditionalBodyClasses;
		$this->assertContains( 'ext-glowthread-size-off', $classes );
		$this->assertSame( [ 'state' => 'disabled', 'seq' => 1 ], $wanCache->get( $key ) );

		$now += 60;
		$this->gtSetPageState( $page, 'open' );
		$this->gtPost( $this->gtUser(), $page, 'Two' );
		$now += 60;
		$out = $this->render( $title );
		$classes = TestingAccessWrapper::newFromObject( $out )->mAdditionalBodyClasses;
		$this->assertContains( 'ext-glowthread-size-2', $classes );
	}

	public function testDisabledPageReservesTheClosedNote() {
		$page = $this->gtPage( 'GlowThread display page' );
		$this->gtSetPageState( $page, 'disabled' );
		$out = $this->render( Title::newFromPageIdentity( $page ) );
		$this->assertContains( 'ext.glowThread.reserve', $out->getModuleStyles() );
		$classes = TestingAccessWrapper::newFromObject( $out )->mAdditionalBodyClasses;
		$this->assertContains( 'ext-glowthread-size-off', $classes );
	}

	/**
	 * @dataProvider provideSizes
	 */
	public function testSizeClassSteps( int $floors, int $size ) {
		$this->assertSame( $size, PageDisplayHooks::sizeClass( $floors ) );
	}

	public static function provideSizes(): array {
		return [
			[ 0, 0 ], [ 1, 1 ], [ 2, 2 ], [ 4, 3 ], [ 5, 5 ], [ 7, 5 ],
			[ 12, 8 ], [ 13, 13 ], [ 20, 13 ], [ 21, 21 ], [ 900, 21 ],
		];
	}

	public function testIneligiblePagesGetNothing() {
		foreach ( [
			Title::newMainPage(),
			Title::makeTitle( NS_TALK, 'GlowThread display talk' ),
			Title::makeTitle( NS_PROJECT, 'GlowThread display project' ),
		] as $title ) {
			$this->getExistingTestPage( $title );
			$out = $this->render( $title );
			$this->assertNotContains( 'ext.glowThread.init', $out->getModules(), $title->getPrefixedText() );
		}
	}
}
