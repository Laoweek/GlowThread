<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Special;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\GlowThread\Compat\CompatHooks;
use MediaWiki\Extension\GlowThread\Special\SpecialFlowThreadManage;
use MediaWiki\Extension\GlowThread\Special\SpecialGlowThread;
use MediaWiki\Extension\GlowThread\Special\SpecialGlowThreadLink;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Request\FauxRequest;
use MediaWiki\SpecialPage\SpecialPage;
use PermissionsError;
use SpecialPageTestBase;
use Wikimedia\TestingAccessWrapper;

/**
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Special\SpecialGlowThreadLink
 * @covers \MediaWiki\Extension\GlowThread\Special\SpecialGlowThread
 * @covers \MediaWiki\Extension\GlowThread\Special\SpecialFlowThreadManage
 * @covers \MediaWiki\Extension\GlowThread\Compat\CompatHooks
 */
class SpecialPagesTest extends SpecialPageTestBase {
	use GlowThreadTestTrait;

	private string $pageName = 'GlowThreadLink';

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
	}

	protected function newSpecialPage() {
		if ( $this->pageName === 'GlowThread' ) {
			return new SpecialGlowThread( $this->gtService( 'Config' ) );
		}
		if ( $this->pageName === 'FlowThreadManage' ) {
			return new SpecialFlowThreadManage();
		}
		return new SpecialGlowThreadLink(
			$this->gtService( 'PostStore' ),
			$this->gtService( 'CommentQueryService' ),
			$this->getServiceContainer()->getTitleFactory()
		);
	}

	public function testPermalinkByIdSeqAndLegacyId() {
		$page = $this->gtPage( 'GlowThread link page' );
		$post = $this->gtPost( $this->gtUser(), $page, 'link me' );
		$this->getDb()->newUpdateQueryBuilder()->update( 'glowthread_post' )
			->set( [ 'gtp_legacy_id' => '0123456789abcdef012345' ] )->where( [ 'gtp_id' => $post->getId() ] )
			->caller( __METHOD__ )->execute();

		foreach ( [ (string)$post->getId(), 'GlowThread_link_page/1', '0123456789ABCDEF012345' ] as $sub ) {
			[ , $response ] = $this->executeSpecialPage( $sub );
			$this->assertStringContainsString(
				'GlowThread_link_page#gt-' . $post->getId(), $response->getHeader( 'Location' ), $sub
			);
		}
	}

	public function testPermalinkKeepsEchoMarkAsRead() {
		$page = $this->gtPage( 'GlowThread link page' );
		$post = $this->gtPost( $this->gtUser(), $page, 'link me' );
		[ , $response ] = $this->executeSpecialPage(
			(string)$post->getId(),
			new FauxRequest( [ 'markasread' => '7|8', 'markasreadwiki' => 'otherwiki', 'other' => 'x' ] )
		);
		$location = urldecode( $response->getHeader( 'Location' ) );
		$this->assertStringContainsString( 'markasread=7|8', $location );
		$this->assertStringContainsString( 'markasreadwiki=otherwiki', $location );
		$this->assertStringNotContainsString( 'other=x', $location );
		$this->assertStringEndsWith( '#gt-' . $post->getId(), $location );
	}

	public function testOldPermalinkNameIsAnAlias() {
		$factory = $this->getServiceContainer()->getSpecialPageFactory();
		$this->assertSame( [ 'GlowThreadLink', '12' ], $factory->resolveAlias( 'CommentLink/12' ) );
		// While FlowThread is loaded, its own page keeps the name
		$owner = ExtensionRegistry::getInstance()->isLoaded( 'FlowThread' ) ? 'FlowThreadLink' : 'GlowThreadLink';
		$this->assertSame( [ $owner, '12' ], $factory->resolveAlias( 'FlowThreadLink/12' ) );
	}

	public function testHiddenCommentsLookMissing() {
		$page = $this->gtPage( 'GlowThread link page' );
		$post = $this->gtPost( $this->gtUser(), $page, 'link me' );
		$this->gtModerate( 'delete', [ $post ] );
		foreach ( [ (string)$post->getId(), '999999', 'GlowThread_link_page/1', 'nonsense' ] as $sub ) {
			[ $html, $response ] = $this->executeSpecialPage( $sub, null, 'qqx' );
			$this->assertNull( $response->getHeader( 'Location' ), $sub );
			$this->assertStringContainsString( 'glowthread-link-notfound', $html, $sub );
		}
	}

	public function testModerationHubNeedsRight() {
		$this->pageName = 'GlowThread';
		$this->expectException( PermissionsError::class );
		$this->executeSpecialPage( 'queue', new FauxRequest(), null, $this->gtUser() );
	}

	public function testModerationHubShell() {
		$this->pageName = 'GlowThread';
		[ $html ] = $this->executeSpecialPage( 'queue', new FauxRequest(), 'qqx', $this->getTestSysop()->getUser() );
		$this->assertStringContainsString( 'id="ext-glowthread-admin"', $html );
		$this->assertStringContainsString( 'glowthread-special-nojs', $html );
	}

	/**
	 * @dataProvider provideThemes
	 */
	public function testModerationHubTheme( string $theme, ?string $class ) {
		$this->overrideConfigValue( 'GlowThreadTheme', $theme );
		$context = new RequestContext();
		$context->setTitle( SpecialPage::getTitleFor( 'GlowThread', 'queue' ) );
		$context->setUser( $this->getTestSysop()->getUser() );
		$context->setLanguage( 'qqx' );
		$page = new SpecialGlowThread( $this->gtService( 'Config' ) );
		$page->setContext( $context );
		$page->execute( 'queue' );
		$classes = TestingAccessWrapper::newFromObject( $context->getOutput() )->mAdditionalHtmlClasses;
		$this->assertSame( $class === null ? [] : [ $class ], $classes );
	}

	public static function provideThemes() {
		yield 'auto' => [ 'auto', null ];
		yield 'night' => [ 'night', 'ext-glowthread-night' ];
		yield 'day' => [ 'day', 'ext-glowthread-day' ];
		yield 'unknown value counts as auto' => [ 'purple', null ];
	}

	public function testOldModerationPageRedirectsToQueue() {
		$this->pageName = 'FlowThreadManage';
		[ , $response ] = $this->executeSpecialPage( '', null, null, $this->getTestSysop()->getUser() );
		$location = urldecode( $response->getHeader( 'Location' ) );
		$this->assertStringContainsString( 'Special:GlowThread/queue', $location );
	}

	public function testOldModerationPageIsLeftToFlowThread() {
		$this->assertTrue( $this->getServiceContainer()->getSpecialPageFactory()->exists( 'FlowThreadManage' ) );
		$hooks = new CompatHooks( $this->gtService( 'Config' ) );
		$list = [];
		$hooks->onSpecialPage_initList( $list );
		$this->assertSame( SpecialFlowThreadManage::class, $list['FlowThreadManage'] );
		// While FlowThread is loaded, its own page keeps the name
		$list = [ 'FlowThreadManage' => 'FlowThread\\SpecialManage' ];
		$hooks->onSpecialPage_initList( $list );
		$this->assertSame( 'FlowThread\\SpecialManage', $list['FlowThreadManage'] );
	}
}
