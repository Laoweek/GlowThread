<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Api;

use DatabaseLogEntry;
use MediaWiki\Block\Restriction\NamespaceRestriction;
use MediaWiki\Context\RequestContext;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\GlowThread\Log\ModerationLogger;
use MediaWiki\Extension\GlowThread\Model\PostStatus;
use MediaWiki\Extension\GlowThread\Model\ReportReason;
use MediaWiki\Extension\GlowThread\Tests\Integration\ReporterTestTrait;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Api\ApiGlowThreadReport
 * @covers \MediaWiki\Extension\GlowThread\Service\ReportService
 * @covers \MediaWiki\Extension\GlowThread\Policy\WriteGuard
 * @covers \MediaWiki\Extension\GlowThread\Log\ModerationLogger
 */
class ApiGlowThreadReportTest extends GlowThreadApiTestCase {
	use ReporterTestTrait;

	private function report( $performer, int $id, string $reason = 'spam', string $note = '' ): array {
		return $this->gtApi(
			[ 'action' => 'glowthreadreport', 'id' => $id, 'reason' => $reason, 'note' => $note ], $performer
		)['glowthreadreport'];
	}

	public function testReportOncePerUser() {
		$page = $this->gtPage();
		$post = $this->gtPost( $this->gtUser(), $page, 'x' );
		$reporter = $this->gtUser();
		$first = $this->report( $reporter, $post->getId(), 'abuse', 'rude 😠' );
		$this->assertFalse( $first['already'] );
		$second = $this->report( $reporter, $post->getId() );
		$this->assertTrue( $second['already'] );
		$this->assertSame( 1, $this->gtReload( $post )->getOpenReportCount() );
		$row = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'gtrp_reason', 'gtrp_note' ] )->from( 'glowthread_report' )
			->where( [ 'gtrp_post' => $post->getId() ] )->caller( __METHOD__ )->fetchRow();
		$this->assertSame( 'abuse', $row->gtrp_reason );
		$this->assertSame( 'rude 😠', $row->gtrp_note );
	}

	public function testOwnCommentCannotBeReported() {
		$page = $this->gtPage();
		$author = $this->gtUser();
		$post = $this->gtPost( $author, $page, 'x' );
		$this->assertSame( 'ownreport', $this->gtApiError(
			[ 'action' => 'glowthreadreport', 'id' => $post->getId(), 'reason' => 'spam' ], $author
		) );
	}

	public function testDisabledReasonIsRejected() {
		$this->overrideConfigValue( 'GlowThreadReportReasons', [ 'spam' ] );
		$page = $this->gtPage();
		$post = $this->gtPost( $this->gtUser(), $page, 'x' );
		$this->assertSame( 'badreason', $this->gtApiError(
			[ 'action' => 'glowthreadreport', 'id' => $post->getId(), 'reason' => 'spoiler' ], $this->gtUser()
		) );
	}

	public function testAutoHoldAfterDistinctReporters() {
		$this->overrideConfigValue( 'GlowThreadAutoHoldReporters', 3 );
		$page = $this->gtPage();
		$top = $this->gtPost( $this->gtUser(), $page, 'top' );
		$reply = $this->gtPost( $this->gtUser(), $page, 'bad reply', $top );
		$this->assertSame( 1, $this->gtReload( $top )->getReplyCount() );

		$this->assertFalse( $this->report( $this->gtEstablishedReporter(), $reply->getId() )['held'] );
		$this->assertFalse( $this->report( $this->gtEstablishedReporter(), $reply->getId() )['held'] );
		$this->assertTrue( $this->report( $this->gtEstablishedReporter(), $reply->getId() )['held'] );

		$after = $this->gtReload( $reply );
		$this->assertSame( PostStatus::HELD, $after->getStatus() );
		$this->assertContains( 'reports', $after->getHoldReasons() );
		$this->assertSame( 0, $this->gtReload( $top )->getReplyCount(), 'held replies leave the visible count' );
	}

	public function testBlockedReportersDoNotCount() {
		$this->overrideConfigValue( 'GlowThreadAutoHoldReporters', 2 );
		$page = $this->gtPage();
		$post = $this->gtPost( $this->gtUser(), $page, 'x' );
		$soonBlocked = $this->gtEstablishedReporter();
		$this->report( $soonBlocked, $post->getId() );
		$this->gtBlockSitewide( $soonBlocked );
		$this->assertFalse( $this->report( $this->gtEstablishedReporter(), $post->getId() )['held'] );
		$this->assertTrue( $this->report( $this->gtEstablishedReporter(), $post->getId() )['held'] );
	}

	/**
	 * A reporter blocked from the page's namespace after reporting no longer counts. Only
	 * sitewide and page blocks used to.
	 */
	public function testNamespaceBlockedReportersDoNotCount() {
		$this->overrideConfigValue( 'GlowThreadAutoHoldReporters', 2 );
		$page = $this->gtPage();
		$post = $this->gtPost( $this->gtUser(), $page, 'x' );
		$soonBlocked = $this->gtEstablishedReporter();
		$this->report( $soonBlocked, $post->getId() );
		$this->assertStatusGood( $this->getServiceContainer()->getBlockUserFactory()->newBlockUser(
			$soonBlocked, $this->getTestSysop()->getAuthority(), 'infinity', 'test block',
			[ 'isAutoblocking' => false ], [ new NamespaceRestriction( 0, NS_MAIN ) ]
		)->placeBlock() );
		$this->assertFalse( $this->report( $this->gtEstablishedReporter(), $post->getId() )['held'] );
		$this->assertTrue( $this->report( $this->gtEstablishedReporter(), $post->getId() )['held'] );
	}

	/**
	 * Three brand-new accounts used to hide a sysop's
	 * pinned announcement by reporting it. Reporters now count only when they would pass the
	 * new-account gate themselves.
	 */
	public function testFreshAccountsDoNotAutoHold() {
		$this->overrideConfigValues( [
			'GlowThreadNewAccountHours' => 24,
			'GlowThreadNewAccountApproved' => 1,
			'GlowThreadAutoHoldReporters' => 3,
		] );
		$page = $this->gtPage();
		$post = $this->gtPost( $this->getTestSysop()->getUser(), $page, 'by an established user' );
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'glowthread_post' )->set( [ 'gtp_actor' => $this->actorOf( $this->gtUser() ) ] )
			->where( [ 'gtp_id' => $post->getId() ] )->caller( __METHOD__ )->execute();
		$socks = [ $this->gtUser(), $this->gtUser(), $this->gtUser() ];
		$this->assertTrue( $this->gtService( 'WriteGuard' )->isGatedNewAccount( $socks[0], null ) );
		foreach ( $socks as $sock ) {
			$this->assertFalse( $this->report( $sock, $post->getId() )['held'] );
		}
		$this->assertSame( PostStatus::VISIBLE, $this->gtReload( $post )->getStatus() );
		$this->assertSame( 3, $this->gtReload( $post )->getOpenReportCount(), 'the reports still reach moderators' );

		// Two days later the same accounts pass the gate. They used to hide the comment then, but
		// they are younger than a week and have no visible comment, so they still do not count.
		ConvertibleTimestamp::setFakeTime( ConvertibleTimestamp::time() + 2 * 86400 );
		try {
			$this->assertFalse( $this->gtService( 'WriteGuard' )->isGatedNewAccount( $socks[0], null ) );
			$this->assertFalse( $this->report( $this->gtUser(), $post->getId() )['held'] );
		} finally {
			ConvertibleTimestamp::setFakeTime( false );
		}
		$this->assertSame( PostStatus::VISIBLE, $this->gtReload( $post )->getStatus() );
	}

	/**
	 * Beyond the new-account gate, a reporter counts only with an account a week old and a
	 * visible comment. glowthread-trusted users count from the start.
	 */
	public function testReportersNeedAWeekAndAVisibleComment() {
		$guard = $this->gtService( 'WriteGuard' );
		$commenter = $this->gtUser();
		$this->gtPost( $commenter, $this->gtPage( 'Another page' ), 'my comment' );
		$idle = $this->gtUser();
		$trusted = $this->gtUser( [ 'bot' ] );
		$this->assertFalse( $guard->isEstablishedReporter( $commenter, $this->actorOf( $commenter ) ),
			'a visible comment, but a new account' );
		$this->assertTrue( $guard->isEstablishedReporter( $trusted, $this->actorOf( $trusted ) ) );
		ConvertibleTimestamp::setFakeTime( ConvertibleTimestamp::time() + 8 * 86400 );
		try {
			$this->assertFalse( $guard->isEstablishedReporter( $idle, $this->actorOf( $idle ) ),
				'a week old, but no visible comment' );
			$this->assertTrue( $guard->isEstablishedReporter( $commenter, $this->actorOf( $commenter ) ) );
		} finally {
			ConvertibleTimestamp::setFakeTime( false );
		}
	}

	/**
	 * An approved comment lets a young account past the new-account gate (its second rule), but
	 * its reports count toward a hold only once the account is a week old.
	 */
	public function testYoungReporterWithApprovedCommentCountsAfterAWeek() {
		$this->overrideConfigValues( [
			'GlowThreadNewAccountHours' => 24,
			'GlowThreadNewAccountApproved' => 1,
			'GlowThreadAutoHoldReporters' => 1,
		] );
		$page = $this->gtPage();
		$post = $this->gtPost( $this->getTestSysop()->getUser(), $page, 'target' );
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'glowthread_post' )->set( [ 'gtp_actor' => $this->actorOf( $this->gtUser() ) ] )
			->where( [ 'gtp_id' => $post->getId() ] )->caller( __METHOD__ )->execute();
		$reporter = $this->gtUser();
		$own = $this->gtPost( $reporter, $page, 'my first comment' );
		$this->assertSame( PostStatus::HELD, $own->getStatus(), 'held by the new-account gate' );
		$this->gtModerate( 'approve', [ $own ] );
		$this->assertFalse(
			$this->gtService( 'WriteGuard' )->isGatedNewAccount( $reporter, $this->actorOf( $reporter ) )
		);
		$this->assertFalse( $this->report( $reporter, $post->getId() )['held'] );
		// A week later the next report (by anyone) finds the reporter eligible
		ConvertibleTimestamp::setFakeTime( ConvertibleTimestamp::time() + 8 * 86400 );
		try {
			$this->assertTrue( $this->report( $this->gtUser(), $post->getId() )['held'] );
		} finally {
			ConvertibleTimestamp::setFakeTime( false );
		}
	}

	/**
	 * Pinned comments and comments by moderators and trusted users are never held by reports;
	 * the reports go to the moderation queue.
	 */
	public function testPinnedAndTrustedCommentsAreNotAutoHeld() {
		$this->overrideConfigValue( 'GlowThreadAutoHoldReporters', 2 );
		$page = $this->gtPage();
		$bySysop = $this->gtPost( $this->getTestSysop()->getUser(), $page, 'announcement' );
		$byBot = $this->gtPost( $this->gtUser( [ 'bot' ] ), $page, 'trusted' );
		$pinned = $this->gtPost( $this->gtUser(), $page, 'pinned by a moderator' );
		$this->gtModerate( 'pin', [ $pinned ] );
		foreach ( [ $bySysop, $byBot, $pinned ] as $post ) {
			for ( $i = 0; $i < 3; $i++ ) {
				$this->assertFalse( $this->report( $this->gtEstablishedReporter(), $post->getId() )['held'] );
			}
			$after = $this->gtReload( $post );
			$this->assertSame( PostStatus::VISIBLE, $after->getStatus() );
			$this->assertSame( 3, $after->getOpenReportCount() );
		}
	}

	/**
	 * Reports used to hide a comment again after every approval. Once a moderator approved it or
	 * dismissed its reports, later reports only reach the queue, until that action is undone.
	 */
	public function testKeptCommentsAreNotHeldAgain() {
		$this->overrideConfigValue( 'GlowThreadAutoHoldReporters', 2 );
		$page = $this->gtPage();
		$approved = $this->gtPost( $this->gtUser(), $page, 'approved after reports' );
		$this->report( $this->gtEstablishedReporter(), $approved->getId() );
		$this->assertTrue( $this->report( $this->gtEstablishedReporter(), $approved->getId() )['held'] );
		$this->gtModerate( 'approve', [ $approved ] );
		$this->report( $this->gtEstablishedReporter(), $approved->getId() );
		$this->assertFalse( $this->report( $this->gtEstablishedReporter(), $approved->getId() )['held'] );
		$after = $this->gtReload( $approved );
		$this->assertSame( PostStatus::VISIBLE, $after->getStatus() );
		$this->assertSame( 2, $after->getOpenReportCount(), 'the reports still reach the queue' );

		$checked = $this->gtPost( $this->gtUser(), $page, 'reports dismissed' );
		$this->report( $this->gtEstablishedReporter(), $checked->getId() );
		$moderation = $this->gtService( 'ModerationService' );
		$sysop = $this->getTestSysop()->getAuthority();
		$dismissed = $moderation->moderate( $sysop, 'dismiss', [ $checked->getId() ], '' )->getValue()[0];
		$this->report( $this->gtEstablishedReporter(), $checked->getId() );
		$this->assertFalse( $this->report( $this->gtEstablishedReporter(), $checked->getId() )['held'] );
		$this->assertSame( PostStatus::VISIBLE, $this->gtReload( $checked )->getStatus() );

		$this->assertStatusGood( $moderation->moderate(
			$sysop, 'undo', [ $checked->getId() ], '', [ $dismissed['logId'] ]
		) );
		$this->assertTrue( $this->report( $this->gtEstablishedReporter(), $checked->getId() )['held'],
			'the dismissal was undone' );

		// A restore keeps the comment too
		$restored = $this->gtPost( $this->gtUser(), $page, 'deleted and restored' );
		$this->gtModerate( 'delete', [ $restored ] );
		$this->gtModerate( 'restore', [ $restored ] );
		$this->report( $this->gtEstablishedReporter(), $restored->getId() );
		$this->assertFalse( $this->report( $this->gtEstablishedReporter(), $restored->getId() )['held'] );
	}

	/**
	 * Reports imported from FlowThread do not count toward the hold: FlowThread never acted on
	 * reports, so they piled up on contested comments. Their reporter can report again, and that
	 * report counts.
	 */
	public function testImportedReportsDoNotCount() {
		$this->overrideConfigValue( 'GlowThreadAutoHoldReporters', 2 );
		$page = $this->gtPage();
		$post = $this->gtPost( $this->gtUser(), $page, 'x' );
		$oldReporter = $this->gtEstablishedReporter();
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'glowthread_report' )
			->row( [
				'gtrp_post' => $post->getId(),
				'gtrp_actor' => $this->actorOf( $oldReporter ),
				'gtrp_reason' => ReportReason::LEGACY,
				'gtrp_created' => null,
			] )
			->caller( __METHOD__ )
			->execute();
		$this->gtService( 'PostStore' )->adjustCounter( $this->getDb(), $post->getId(), 'gtp_open_report_count', 1 );
		$this->assertFalse( $this->report( $this->gtEstablishedReporter(), $post->getId() )['held'] );
		$reportStore = $this->gtService( 'ReportStore' );
		$this->assertSame( 0, $reportStore->getStatsByActor( $this->getDb(), $this->actorOf( $oldReporter ) )['filed'],
			'imported reports are not in the reporter statistics' );

		$again = $this->report( $oldReporter, $post->getId(), 'abuse', 'still rude' );
		$this->assertFalse( $again['already'], 'the imported report becomes this one' );
		$this->assertTrue( $again['held'] );
		$this->assertSame( 2, $this->gtReload( $post )->getOpenReportCount() );
		$row = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'gtrp_reason', 'gtrp_note', 'gtrp_created' ] )->from( 'glowthread_report' )
			->where( [ 'gtrp_post' => $post->getId(), 'gtrp_actor' => $this->actorOf( $oldReporter ) ] )
			->caller( __METHOD__ )->fetchRow();
		$this->assertSame( [ 'abuse', 'still rude' ], [ $row->gtrp_reason, $row->gtrp_note ] );
		$this->assertNotNull( $row->gtrp_created );
		$this->assertSame( 1, $reportStore->getStatsByActor( $this->getDb(), $this->actorOf( $oldReporter ) )['open'] );

		// An imported report that was closed stays closed: one report per user and comment
		$closed = $this->gtPost( $this->gtUser(), $page, 'y' );
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'glowthread_report' )
			->row( [
				'gtrp_post' => $closed->getId(),
				'gtrp_actor' => $this->actorOf( $oldReporter ),
				'gtrp_reason' => ReportReason::LEGACY,
				'gtrp_created' => null,
				'gtrp_resolved_at' => $this->getDb()->timestamp(),
				'gtrp_outcome' => ReportReason::OUTCOME_UPHELD,
			] )
			->caller( __METHOD__ )
			->execute();
		$this->assertTrue( $this->report( $oldReporter, $closed->getId() )['already'] );
		$this->assertSame( 0, $this->gtReload( $closed )->getOpenReportCount() );
	}

	/**
	 * An automatic hold is logged, with the system user as performer, never a reporter.
	 */
	public function testAutoHoldIsLogged() {
		$this->overrideConfigValue( 'GlowThreadAutoHoldReporters', 1 );
		$page = $this->gtPage();
		$author = $this->gtUser();
		$post = $this->gtPost( $author, $page, 'x' );
		$this->assertTrue( $this->report( $this->gtEstablishedReporter(), $post->getId() )['held'] );

		$rows = $this->gtLogRows( 'glowthread', 'autohold' );
		$this->assertCount( 1, $rows );
		$entry = DatabaseLogEntry::newFromId( (int)$rows[0]->log_id, $this->getDb() );
		$this->assertSame( ModerationLogger::SYSTEM_USER, $entry->getPerformerIdentity()->getName() );
		$this->assertSame( $post->getId(), (int)$entry->getParameters()['4::comment'] );
		$formatter = $this->getServiceContainer()->getLogFormatterFactory()->newFromEntry( $entry );
		$context = new RequestContext();
		$context->setLanguage( 'en' );
		$context->setUser( $this->getTestSysop()->getUser() );
		$formatter->setContext( $context );
		$this->assertStringContainsString(
			'held comment #' . $post->getSeq() . ' by ' . $author->getName(), $formatter->getPlainActionText()
		);
	}

	private function actorOf( $user ): int {
		return $this->getServiceContainer()->getActorNormalization()->acquireActorId( $user, $this->getDb() );
	}

	public function testReportFiledHook() {
		$page = $this->gtPage();
		$post = $this->gtPost( $this->gtUser(), $page, 'x' );
		$reporter = $this->gtUser();
		$seen = null;
		$this->setTemporaryHook( 'GlowThreadReportFiled',
			static function ( $p, $pg, $user, $reason, $held ) use ( &$seen ) {
				$seen = [ $p->getId(), $user->getName(), $reason, $held ];
			}
		);
		$this->report( $reporter, $post->getId(), 'misinfo' );
		DeferredUpdates::doUpdates();
		$this->assertSame( [ $post->getId(), $reporter->getName(), 'misinfo', false ], $seen );
	}

	public function testNoteLengthCap() {
		// i18n: the cap counts characters, a CJK character counts one
		$page = $this->gtPage();
		$post = $this->gtPost( $this->gtUser(), $page, 'x' );
		$this->assertSame( 'notetoolong', $this->gtApiError( [
			'action' => 'glowthreadreport', 'id' => $post->getId(), 'reason' => 'other',
			'note' => str_repeat( '长', 501 ),
		], $this->gtUser() ) );
	}
}
