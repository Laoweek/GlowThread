<?php

namespace MediaWiki\Extension\GlowThread\Service;

use MediaWiki\Api\ApiMessage;
use MediaWiki\Extension\GlowThread\Cdn\CdnPurger;
use MediaWiki\Extension\GlowThread\Log\ModerationLogger;
use MediaWiki\Extension\GlowThread\Model\PageState;
use MediaWiki\Extension\GlowThread\Policy\PageEligibility;
use MediaWiki\Extension\GlowThread\Policy\WriteGuard;
use MediaWiki\Extension\GlowThread\Store\PageStateStore;
use MediaWiki\Permissions\Authority;
use MediaWiki\User\ActorNormalization;
use StatusValue;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Per-page comment state: open, locked or disabled.
 */
class PageStateService {

	/** Log subtype per state. enable and disable are FlowThread's names. */
	private const LOG_ACTIONS = [
		PageState::OPEN => 'enable',
		PageState::LOCKED => 'lock',
		PageState::DISABLED => 'disable',
	];

	public function __construct(
		private readonly WriteGuard $guard,
		private readonly PageStateStore $pageStateStore,
		private readonly PageEligibility $pageEligibility,
		private readonly ActorNormalization $actorNormalization,
		private readonly ModerationLogger $logger,
		private readonly CdnPurger $cdnPurger,
		private readonly IConnectionProvider $dbProvider
	) {
	}

	/**
	 * @param Authority $authority
	 * @param int $pageId
	 * @param string $state One of PageState::ALL
	 * @param string $reason
	 * @return StatusValue Value: [ 'old' => string, 'state' => string, 'changed' => bool ]
	 */
	public function setState( Authority $authority, int $pageId, string $state, string $reason ): StatusValue {
		if ( !in_array( $state, PageState::ALL, true ) ) {
			throw new \InvalidArgumentException( "Unknown page state $state" );
		}
		$status = $this->guard->checkModerate( $authority, 'glowthread-moderate' );
		if ( !$status->isOK() ) {
			return $status;
		}
		$page = $this->pageEligibility->getPage( $pageId );
		// A page the moderator may not read is answered as missing
		if ( !$page || !$this->guard->canModerateOn( $authority, $pageId ) ) {
			return StatusValue::newFatal( ApiMessage::create( 'glowthread-error-page-missing', 'pagenotfound' ) );
		}
		if ( !$this->pageEligibility->isEligible( $page ) ) {
			return StatusValue::newFatal( ApiMessage::create( 'glowthread-error-page-ineligible', 'pageineligible' ) );
		}
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$performer = $authority->getUser();
		$actorId = $this->actorNormalization->acquireActorId( $performer, $dbw );
		$dbw->startAtomic( __METHOD__ );
		$old = $this->pageStateStore->setState( $dbw, $pageId, $state, $actorId, ConvertibleTimestamp::now( TS_MW ) );
		$changed = $old !== $state;
		if ( $changed ) {
			$this->logger->logPageState( $dbw, self::LOG_ACTIONS[$state], $page, $performer, $reason );
		}
		$dbw->endAtomic( __METHOD__ );
		if ( $changed ) {
			$this->cdnPurger->purgePage( $pageId );
			$this->cdnPurger->purgeArticle( $page );
		}
		return StatusValue::newGood( [ 'old' => $old, 'state' => $state, 'changed' => $changed ] );
	}
}
