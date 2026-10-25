<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration;

use MediaWiki\Block\Restriction\PageRestriction;
use MediaWiki\Extension\GlowThread\Log\LegacyLogType;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Tests\Integration\Query\BaseCommentQueryService;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentity;

/**
 * Helpers for GlowThread integration tests (MediaWikiIntegrationTestCase subclasses).
 */
trait GlowThreadTestTrait {

	/**
	 * Common configuration: no new-account gate (test users are minutes old), no rate limits,
	 * comments in the main namespace, a CDN in front of the wiki.
	 */
	protected function setUpGlowThread(): void {
		$this->overrideConfigValues( [
			'GlowThreadNewAccountHours' => 0,
			'GlowThreadNamespaces' => [ 0 => true ],
			'RateLimits' => [],
			'UseCdn' => true,
			'GlowThreadCdnMaxAge' => 300,
		] );
	}

	/**
	 * Registers FlowThread's comments log type the way a wiki with $wgGlowThreadLegacyCompat does
	 * (LegacyLogType runs as an extension callback, before a test can change the setting).
	 */
	protected function gtRegisterLegacyLogType(): void {
		$config = $this->getServiceContainer()->getMainConfig();
		$settings = [];
		$names = [ 'LogTypes', 'FilterLogTypes', 'ActionFilteredLogs', 'LogActionsHandlers', 'LogRestrictions' ];
		foreach ( $names as $name ) {
			$settings[$name] = $config->get( $name );
		}
		$this->overrideConfigValues( LegacyLogType::addTo( $settings ) );
	}

	/**
	 * @param string $name Service name without the GlowThread. prefix
	 * @return mixed
	 */
	protected function gtService( string $name ) {
		return $this->getServiceContainer()->get( 'GlowThread.' . $name );
	}

	/**
	 * The query service as it was before ListCache, to compare lists with (test oracle)
	 */
	protected function gtBaseQueryService(): BaseCommentQueryService {
		$services = $this->getServiceContainer();
		return new BaseCommentQueryService(
			$this->gtService( 'Config' ),
			$this->gtService( 'PostStore' ),
			$this->gtService( 'PageStateStore' ),
			$this->gtService( 'PageEligibility' ),
			$this->gtService( 'CommentSerializer' ),
			$this->gtService( 'HotRanker' ),
			$this->gtService( 'SettingsService' ),
			$this->gtService( 'ViewerFactory' ),
			$services->getTitleFormatter(),
			$this->gtService( 'WriteGuard' ),
			$services->getReadOnlyMode()
		);
	}

	protected function gtPage( string $title = 'GlowThread test page' ): ProperPageIdentity {
		return $this->getExistingTestPage( Title::newFromText( $title ) );
	}

	/**
	 * @param string[] $groups
	 */
	protected function gtUser( array $groups = [] ): User {
		return $this->getMutableTestUser( $groups )->getUser();
	}

	protected function gtPost(
		Authority $author,
		ProperPageIdentity $page,
		string $text,
		?PostRecord $replyTo = null
	): PostRecord {
		$status = $this->gtService( 'PostingService' )->post(
			$author, $page->getId(), $text, $replyTo ? $replyTo->getId() : null
		);
		$this->assertStatusGood( $status );
		return $status->getValue();
	}

	/**
	 * Run a moderation action as the test sysop and check that it went through.
	 *
	 * @param string $action
	 * @param PostRecord[] $posts
	 * @param string $reason
	 */
	protected function gtModerate( string $action, array $posts, string $reason = '' ): void {
		$this->assertStatusGood( $this->gtService( 'ModerationService' )->moderate(
			$this->getTestSysop()->getAuthority(),
			$action,
			array_map( static fn ( PostRecord $p ) => $p->getId(), $posts ),
			$reason
		) );
	}

	protected function gtSetPageState( ProperPageIdentity $page, string $state ): void {
		$this->assertStatusGood( $this->gtService( 'PageStateService' )->setState(
			$this->getTestSysop()->getAuthority(), $page->getId(), $state, ''
		) );
	}

	protected function gtReload( PostRecord $post ): PostRecord {
		return $this->gtService( 'PostStore' )->getById( $post->getId(), true );
	}

	protected function gtBlockSitewide( UserIdentity $user ): void {
		$this->gtBlock( $user, [] );
	}

	protected function gtBlockFromPage( UserIdentity $user, ProperPageIdentity $page ): void {
		$this->gtBlock( $user, [ new PageRestriction( 0, $page->getId() ) ] );
	}

	private function gtBlock( UserIdentity $user, array $restrictions ): void {
		$status = $this->getServiceContainer()->getBlockUserFactory()->newBlockUser(
			$user,
			$this->getTestSysop()->getAuthority(),
			'infinity',
			'test block',
			[ 'isAutoblocking' => false ],
			$restrictions
		)->placeBlock();
		$this->assertStatusGood( $status );
	}

	/**
	 * Set the word filter rules (the MediaWiki:GlowThread-filter page).
	 */
	protected function gtSetFilter( string $rules ): void {
		$this->editPage(
			Title::makeTitle( NS_MEDIAWIKI, 'GlowThread-filter' ),
			$rules,
			'',
			NS_MAIN,
			$this->getTestSysop()->getAuthority()
		);
		// The filter caches the rules for the request; start over with fresh services.
		$this->resetServices();
	}

	/**
	 * Rows of the comments log for one action, newest first.
	 *
	 * @return \stdClass[]
	 */
	protected function gtLogRows( string $type, string $action ): array {
		return iterator_to_array( $this->getDb()->newSelectQueryBuilder()
			->select( [ 'log_id', 'log_type', 'log_action', 'log_params', 'log_page', 'log_namespace', 'log_title' ] )
			->from( 'logging' )
			->where( [ 'log_type' => $type, 'log_action' => $action ] )
			->orderBy( 'log_id', 'DESC' )
			->caller( __METHOD__ )
			->fetchResultSet(), false );
	}
}
