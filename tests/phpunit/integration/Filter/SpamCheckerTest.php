<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Filter;

use MediaWiki\Extension\GlowThread\Model\HoldReason;
use MediaWiki\Extension\GlowThread\Model\PostStatus;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Extension\SpamBlacklist\BaseBlacklist;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWikiIntegrationTestCase;
use ReflectionProperty;

/**
 * URLs in comments are checked against the installed SpamBlacklist. Runs only when SpamBlacklist
 * is loaded, for example with GLOWTHREAD_TEST_EXTENSIONS="Echo CheckUser SpamBlacklist"
 * scripts/php-test.sh (the images bundle SpamBlacklist).
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Filter\SpamChecker
 */
class SpamCheckerTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;

	protected function setUp(): void {
		parent::setUp();
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'SpamBlacklist' ) ) {
			$this->markTestSkipped( 'SpamBlacklist is not loaded' );
		}
		$this->setUpGlowThread();
		// Only the list on the wiki, not the default one fetched from meta.wikimedia.org
		$this->overrideConfigValue( 'BlacklistSettings', [ 'spam' => [ 'files' => [] ] ] );
		BaseBlacklist::clearInstanceCache();
		$this->getServiceContainer()->getMessageCache()->enable();
		$this->insertPage( 'MediaWiki:Spam-blacklist', '\bspam\.example\b' );
		// SpamBlacklist keeps the parsed list in its instance: make it read the page just saved
		$instance = BaseBlacklist::getSpamBlacklist();
		( new ReflectionProperty( $instance, 'regexes' ) )->setValue( $instance, false );
	}

	protected function tearDown(): void {
		$this->getServiceContainer()->getMessageCache()->disable();
		parent::tearDown();
	}

	public function testBlacklistedUrlHoldsTheComment() {
		$page = $this->gtPage();

		$held = $this->gtPost( $this->gtUser(), $page, 'Look at https://spam.example/offer now' );
		$this->assertSame( PostStatus::HELD, $held->getStatus() );
		$this->assertContains( HoldReason::SPAM_BLACKLIST, $held->getHoldReasons() );

		$clean = $this->gtPost( $this->gtUser(), $page, 'Look at https://www.mediawiki.org/ now' );
		$this->assertSame( PostStatus::VISIBLE, $clean->getStatus() );
		$this->assertSame( [], $clean->getHoldReasons() );
	}

	/**
	 * URLs with a user part are not linked, but their host is still checked: the one a browser
	 * would open.
	 */
	public function testUrlsLeftAsTextAreCheckedToo() {
		$page = $this->gtPage();
		foreach ( [
			'https://www.mediawiki.org@spam.example/offer',
			'https://spam.example\\@www.mediawiki.org/',
		] as $url ) {
			$post = $this->gtPost( $this->gtUser(), $page, "Look at $url now" );
			$this->assertContains( HoldReason::SPAM_BLACKLIST, $post->getHoldReasons(), $url );
		}
	}
}
