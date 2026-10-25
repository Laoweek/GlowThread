<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Api;

use DatabaseLogEntry;
use LogPage;
use MediaWiki\Context\RequestContext;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\GlowThread\Model\PostStatus;
use MediaWiki\Extension\GlowThread\Tests\Integration\ReporterTestTrait;
use MediaWiki\MainConfigNames;
use Psr\Log\LoggerInterface;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Api\ApiGlowThreadModerate
 * @covers \MediaWiki\Extension\GlowThread\Service\ModerationService
 * @covers \MediaWiki\Extension\GlowThread\Log\ModerationLogger
 */
class ApiGlowThreadModerateTest extends GlowThreadApiTestCase {
	use ReporterTestTrait;

	private function moderate( $performer, string $do, array $ids, string $reason = '' ): array {
		return $this->gtApi( [
			'action' => 'glowthreadmoderate', 'do' => $do, 'ids' => implode( '|', $ids ), 'reason' => $reason,
		], $performer )['glowthreadmoderate'];
	}

	private function moderator() {
		return $this->getTestSysop()->getUser();
	}

	public function testNeedsModerateRight() {
		$page = $this->gtPage();
		$post = $this->gtPost( $this->gtUser(), $page, 'x' );
		$this->assertSame( 'permissiondenied', $this->gtApiError(
			[ 'action' => 'glowthreadmoderate', 'do' => 'delete', 'ids' => $post->getId() ], $this->gtUser()
		) );
	}

	public function testSitewideBlockedModeratorCannotModerate() {
		$page = $this->gtPage();
		$post = $this->gtPost( $this->gtUser(), $page, 'x' );
		$mod = $this->gtUser( [ 'sysop' ] );
		$this->gtBlockSitewide( $mod );
		$this->assertStringContainsString( 'blocked', $this->gtApiError(
			[ 'action' => 'glowthreadmoderate', 'do' => 'delete', 'ids' => $post->getId() ], $mod
		) );
		$this->assertSame( PostStatus::VISIBLE, $this->gtReload( $post )->getStatus() );
	}

	public function testPartiallyBlockedModeratorCannotModerateAnywhere() {
		$page = $this->gtPage();
		$elsewhere = $this->gtPage( 'GlowThread unrelated page' );
		$post = $this->gtPost( $this->gtUser(), $page, 'x' );
		$mod = $this->gtUser( [ 'sysop' ] );
		$this->gtBlockFromPage( $mod, $elsewhere );
		$this->assertStringContainsString( 'blocked', $this->gtApiError(
			[ 'action' => 'glowthreadmoderate', 'do' => 'delete', 'ids' => $post->getId() ], $mod
		) );
	}

	public function testDeleteRestoreKeepCountersAndLog() {
		$page = $this->gtPage();
		$author = $this->gtUser();
		$top = $this->gtPost( $author, $page, 'top' );
		$reply = $this->gtPost( $this->gtUser(), $page, 'reply', $top );
		$this->gtService( 'ReportService' )->report( $this->gtUser(), $reply->getId(), 'spam', '' );

		$result = $this->moderate( $this->moderator(), 'delete', [ $reply->getId() ], 'Spam' );
		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( PostStatus::DELETED, $result['results'][0]['status'] );
		$this->assertSame( 0, $this->gtReload( $top )->getReplyCount() );
		$this->assertSame( 0, $this->gtReload( $reply )->getOpenReportCount() );
		$outcome = $this->getDb()->newSelectQueryBuilder()->select( 'gtrp_outcome' )->from( 'glowthread_report' )
			->where( [ 'gtrp_post' => $reply->getId() ] )->caller( __METHOD__ )->fetchField();
		$this->assertSame( 'upheld', $outcome );

		$log = $this->gtLogRows( 'glowthread', 'delete' )[0];
		$params = unserialize( $log->log_params );
		$this->assertSame( $reply->getId(), $params['4::comment'] );
		$this->assertSame( $reply->getSeq(), $params['6::seq'] );
		$this->assertArrayHasKey( '5:user-link:author', $params );
		$this->assertSame( $page->getId(), (int)$log->log_page );

		// Deleting again changes nothing and fails for that item
		$again = $this->moderate( $this->moderator(), 'delete', [ $reply->getId() ] );
		$this->assertSame( 'failure', $again['result'] );
		$this->assertSame( 'badstatus', $again['results'][0]['code'] );

		$this->moderate( $this->moderator(), 'restore', [ $reply->getId() ] );
		$this->assertSame( PostStatus::VISIBLE, $this->gtReload( $reply )->getStatus() );
		$this->assertSame( 1, $this->gtReload( $top )->getReplyCount() );
		$this->assertCount( 1, $this->gtLogRows( 'glowthread', 'recover' ) );
	}

	public function testApproveFiresApprovedHookAndDismissesReports() {
		$this->gtSetFilter( 'holdme' );
		$page = $this->gtPage();
		$alice = $this->gtUser();
		$top = $this->gtPost( $alice, $page, 'top' );
		$held = $this->gtPost( $this->gtUser(), $page, 'holdme', $top );
		$this->assertSame( PostStatus::HELD, $held->getStatus() );
		$this->assertSame( 0, $this->gtReload( $top )->getReplyCount() );

		$approved = [];
		$moderated = [];
		$this->setTemporaryHook( 'GlowThreadPostApproved',
			static function ( $post, $p, $author, $mentioned, $replyTo, $replyToAuthor, $mod ) use ( &$approved ) {
				$approved[] = [ $post->getId(), $replyToAuthor->getName(), $mod->getName() ];
			}
		);
		$this->setTemporaryHook( 'GlowThreadPostModerated',
			static function ( $post, $p, $action ) use ( &$moderated ) {
				$moderated[] = [ $post->getId(), $action ];
			}
		);
		$this->moderate( $this->moderator(), 'approve', [ $held->getId() ] );
		$this->assertSame( [ [ $held->getId(), $alice->getName(), $this->moderator()->getName() ] ], $approved );
		$this->assertSame( [ [ $held->getId(), 'approve' ] ], $moderated );
		$this->assertSame( 1, $this->gtReload( $top )->getReplyCount() );
		$this->assertCount( 1, $this->gtLogRows( 'glowthread', 'approve' ) );
	}

	public function testEraseNeedsRightAndADeletedComment() {
		$this->setGroupPermissions( 'commentmod', 'glowthread-moderate', true );
		$page = $this->gtPage();
		$author = $this->gtUser();
		$post = $this->gtPost( $author, $page, 'secret phone number' );
		$modOnly = $this->gtUser( [ 'commentmod' ] );

		$this->assertSame( 'permissiondenied', $this->gtApiError(
			[ 'action' => 'glowthreadmoderate', 'do' => 'erase', 'ids' => $post->getId() ], $modOnly
		) );
		$visible = $this->moderate( $this->moderator(), 'erase', [ $post->getId() ] );
		$this->assertSame( 'badstatus', $visible['results'][0]['code'], 'only deleted comments can be erased' );

		$this->moderate( $modOnly, 'delete', [ $post->getId() ], 'doxxing' );
		$this->moderate( $this->moderator(), 'erase', [ $post->getId() ], 'doxxing' );
		$erased = $this->gtReload( $post );
		$this->assertSame( PostStatus::ERASED, $erased->getStatus() );
		$this->assertSame( '', $erased->getText(), 'text blanked' );
		$this->assertSame( $post->getActorId(), $erased->getActorId(), 'row kept as tombstone' );

		// Erasing is irreversible
		$restore = $this->moderate( $this->moderator(), 'restore', [ $post->getId() ] );
		$this->assertSame( 'badstatus', $restore['results'][0]['code'] );

		$public = unserialize( $this->gtLogRows( 'glowthread', 'erase' )[0]->log_params );
		$this->assertArrayNotHasKey( '5:user-link:author', $public, 'the public entry does not name the author' );
		$private = unserialize( $this->gtLogRows( 'glowthread-private', 'erase' )[0]->log_params );
		$this->assertSame( $author->getName(), $private['5:user-link:author'] );
		$restrictions = $this->getServiceContainer()->getMainConfig()->get( 'LogRestrictions' );
		$this->assertSame( 'glowthread-erase', $restrictions['glowthread-private'] );
	}

	public function testAuthorOfErasedCommentIsHiddenInOlderLogEntries() {
		$this->setGroupPermissions( 'commentmod', 'glowthread-moderate', true );
		$page = $this->gtPage();
		$author = $this->gtUser();
		$post = $this->gtPost( $author, $page, 'x' );
		$this->moderate( $this->moderator(), 'delete', [ $post->getId() ] );
		$this->moderate( $this->moderator(), 'erase', [ $post->getId() ] );
		$deleteLogId = (int)$this->gtLogRows( 'glowthread', 'delete' )[0]->log_id;

		$entry = \DatabaseLogEntry::newFromId( $deleteLogId, $this->getDb() );
		$formatterFactory = $this->getServiceContainer()->getLogFormatterFactory();

		$context = new RequestContext();
		$context->setUser( $this->gtUser( [ 'commentmod' ] ) );
		$context->setLanguage( 'en' );
		$formatter = $formatterFactory->newFromEntry( $entry );
		$formatter->setContext( $context );
		$this->assertStringNotContainsString( $author->getName(), $formatter->getPlainActionText() );
		$this->assertArrayNotHasKey( 'author', $formatter->formatParametersForApi() );

		$context->setUser( $this->moderator() );
		$formatter = $formatterFactory->newFromEntry( $entry );
		$formatter->setContext( $context );
		$this->assertStringContainsString( $author->getName(), $formatter->getPlainActionText() );
	}

	/**
	 * An author deletes their own comment, then a sysop erases it. The
	 * self-delete entry named the author as performer, publicly. After the erase its performer
	 * and reason are hidden (log_deleted and rc_deleted), in Special:Log, the API and RC.
	 */
	public function testErasingHidesTheAuthorAsPerformerOfEarlierEntries() {
		$page = $this->gtPage();
		$author = $this->gtUser();
		$post = $this->gtPost( $author, $page, 'my phone number is ...' );
		$this->gtApi(
			[ 'action' => 'glowthreaddelete', 'id' => $post->getId(), 'reason' => 'oops, my number' ], $author
		);
		DeferredUpdates::doUpdates();
		$this->moderate( $this->moderator(), 'erase', [ $post->getId() ] );
		DeferredUpdates::doUpdates();
		$deleteLogId = (int)$this->gtLogRows( 'glowthread', 'delete' )[0]->log_id;
		$bits = LogPage::DELETED_USER | LogPage::DELETED_COMMENT;

		$entry = DatabaseLogEntry::newFromId( $deleteLogId, $this->getDb() );
		$this->assertSame( $bits, $entry->getDeleted() & $bits );
		$formatter = $this->getServiceContainer()->getLogFormatterFactory()->newFromEntry( $entry );
		$context = new RequestContext();
		$context->setUser( $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' ) );
		$context->setLanguage( 'en' );
		$formatter->setContext( $context );
		$this->assertStringNotContainsString( $author->getName(), $formatter->getPlainActionText() );

		$anon = $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' );
		$events = $this->doApiRequest( [
			'action' => 'query', 'list' => 'logevents', 'letype' => 'glowthread', 'leaction' => 'glowthread/delete',
			'leprop' => 'user|details|ids|comment',
		], null, false, $anon )[0]['query']['logevents'];
		$row = array_values( array_filter( $events, static fn ( $e ) => $e['logid'] === $deleteLogId ) )[0];
		$this->assertArrayNotHasKey( 'user', $row );
		$this->assertArrayHasKey( 'userhidden', $row );
		$this->assertArrayNotHasKey( 'comment', $row );
		$this->assertStringNotContainsString( $author->getName(), json_encode( $events ) );

		$rcDeleted = $this->getDb()->newSelectQueryBuilder()
			->select( 'rc_deleted' )->from( 'recentchanges' )->where( [ 'rc_logid' => $deleteLogId ] )
			->caller( __METHOD__ )->fetchField();
		$this->assertNotFalse( $rcDeleted, 'the self-delete entry is in RecentChanges' );
		$this->assertSame( $bits, (int)$rcDeleted & $bits );
	}

	/**
	 * A moderator who erases their own comment is not named by the public erase entry either.
	 */
	public function testErasingOwnCommentHidesThePerformer() {
		$page = $this->gtPage();
		$mod = $this->moderator();
		$own = $this->gtPost( $mod, $page, 'mine' );
		$this->moderate( $mod, 'delete', [ $own->getId() ] );
		$this->moderate( $mod, 'erase', [ $own->getId() ] );
		$bits = LogPage::DELETED_USER | LogPage::DELETED_COMMENT;
		foreach ( [ 'delete', 'erase' ] as $action ) {
			$logId = (int)$this->gtLogRows( 'glowthread', $action )[0]->log_id;
			$entry = DatabaseLogEntry::newFromId( $logId, $this->getDb() );
			$this->assertSame( $bits, $entry->getDeleted() & $bits, $action );
		}
	}

	/**
	 * Erasing is irreversible, so each erased comment counts against the
	 * glowthread-erase limit (30 an hour by default), which sysops cannot bypass with
	 * noratelimit. Comments beyond it stay deleted, and hitting it is logged.
	 */
	public function testEraseIsThrottledEvenForSysops() {
		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->atLeastOnce() )->method( 'warning' );
		$this->setLogger( 'GlowThread', $logger );
		$this->overrideConfigValue( MainConfigNames::RateLimits, $this->gtDefaultRateLimits() );
		$page = $this->gtPage();
		$sysop = $this->moderator();
		$ids = [];
		for ( $i = 0; $i < 35; $i++ ) {
			$ids[] = $this->gtPost( $sysop, $page, "comment $i" )->getId();
		}
		$this->assertSame( 'success', $this->moderate( $sysop, 'delete', $ids )['result'] );
		$result = $this->moderate( $sysop, 'erase', $ids );
		$this->assertSame( 'partial', $result['result'] );
		$codes = array_column( $result['results'], 'code' );
		$this->assertSame( array_fill( 0, 30, null ), array_slice( $codes, 0, 30 ) );
		$this->assertSame( array_fill( 0, 5, 'ratelimited' ), array_slice( $codes, 30 ) );
		$last = $this->gtService( 'PostStore' )->getById( $ids[34], true );
		$this->assertSame( PostStatus::DELETED, $last->getStatus() );

		$error = $this->gtApiErrorArray(
			[ 'action' => 'glowthreadmoderate', 'do' => 'erase', 'ids' => (string)$ids[34] ], $sysop
		);
		$this->assertSame( 'ratelimited', $error['code'] );
		$this->assertSame( 3600, $error['data']['retryAfter'] );
		// Other moderation is not affected
		$this->assertSame( 'success', $this->moderate( $sysop, 'restore', [ $ids[34] ] )['result'] );
	}

	private function undo( array $results, string $reason = '' ): array {
		return $this->gtApi( [
			'action' => 'glowthreadmoderate', 'do' => 'undo', 'reason' => $reason,
			'ids' => implode( '|', array_column( $results, 'id' ) ),
			'logids' => implode( '|', array_column( $results, 'logId' ) ),
		], $this->moderator() )['glowthreadmoderate'];
	}

	/**
	 * Results say the status before and the log entry, and do=undo
	 * reverses exactly that entry. Deleting a held comment comes back held, not published.
	 */
	public function testUndoDeleteOfAHeldComment() {
		$this->gtSetFilter( 'holdme' );
		$page = $this->gtPage();
		$held = $this->gtPost( $this->gtUser(), $page, 'holdme please' );
		$deleted = $this->moderate( $this->moderator(), 'delete', [ $held->getId() ] );
		$this->assertSame( 'held', $deleted['results'][0]['old'] );
		$this->assertSame( 'deleted', $deleted['results'][0]['status'] );
		$this->assertSame(
			(int)$this->gtLogRows( 'glowthread', 'delete' )[0]->log_id, $deleted['results'][0]['logId']
		);

		$undone = $this->undo( $deleted['results'], 'oops' );
		$this->assertSame( 'success', $undone['result'] );
		$this->assertSame( 'held', $undone['results'][0]['status'] );
		$this->assertSame( 'deleted', $undone['results'][0]['old'] );
		$after = $this->gtReload( $held );
		$this->assertSame( PostStatus::HELD, $after->getStatus() );
		$this->assertSame( [ 'filter' ], $after->getHoldReasons() );
		$undoRow = $this->gtLogRows( 'glowthread', 'undo' )[0];
		$this->assertSame( (int)$undoRow->log_id, $undone['results'][0]['logId'] );
		$this->assertSame( $deleted['results'][0]['logId'], unserialize( $undoRow->log_params )['7::undone'] );

		// Undoing the same entry again changes nothing
		$this->assertSame( 'nochange', $this->undo( $deleted['results'] )['results'][0]['result'] );
	}

	public function testUndoDeleteBringsBackReportsAndCounts() {
		$page = $this->gtPage();
		$top = $this->gtPost( $this->gtUser(), $page, 'top' );
		$reply = $this->gtPost( $this->gtUser(), $page, 'reported reply', $top );
		$this->gtService( 'ReportService' )->report( $this->gtUser(), $reply->getId(), 'spam', '' );
		$deleted = $this->moderate( $this->moderator(), 'delete', [ $reply->getId() ] );
		$this->assertSame( 0, $this->gtReload( $reply )->getOpenReportCount() );
		$this->assertSame( 0, $this->gtReload( $top )->getReplyCount() );

		$this->undo( $deleted['results'] );
		$after = $this->gtReload( $reply );
		$this->assertSame( PostStatus::VISIBLE, $after->getStatus() );
		$this->assertSame( 1, $after->getOpenReportCount(), 'the upheld report is open again' );
		$this->assertSame( 1, $this->gtReload( $top )->getReplyCount() );
	}

	public function testUndoApproveDismissPinAndRestore() {
		$this->overrideConfigValue( 'GlowThreadAutoHoldReporters', 1 );
		$page = $this->gtPage();
		$post = $this->gtPost( $this->gtUser(), $page, 'x' );
		$this->gtService( 'ReportService' )->report( $this->gtEstablishedReporter(), $post->getId(), 'spam', '' );
		$this->assertSame( PostStatus::HELD, $this->gtReload( $post )->getStatus() );

		$approved = $this->moderate( $this->moderator(), 'approve', [ $post->getId() ] );
		$this->assertSame( 'held', $approved['results'][0]['old'] );
		$this->undo( $approved['results'] );
		$this->assertSame( PostStatus::HELD, $this->gtReload( $post )->getStatus() );
		$this->assertSame( 1, $this->gtReload( $post )->getOpenReportCount() );

		$this->moderate( $this->moderator(), 'approve', [ $post->getId() ] );
		$this->overrideConfigValue( 'GlowThreadAutoHoldReporters', 0 );
		$this->gtService( 'ReportService' )->report( $this->gtUser(), $post->getId(), 'abuse', '' );
		$dismissed = $this->moderate( $this->moderator(), 'dismiss', [ $post->getId() ] );
		$this->undo( $dismissed['results'] );
		$this->assertSame( 1, $this->gtReload( $post )->getOpenReportCount() );

		$pinned = $this->moderate( $this->moderator(), 'pin', [ $post->getId() ] );
		$pinnedAt = $this->gtReload( $post )->getPinned();
		$unpinned = $this->moderate( $this->moderator(), 'unpin', [ $post->getId() ] );
		$this->undo( $unpinned['results'] );
		$this->assertSame( $pinnedAt, $this->gtReload( $post )->getPinned(), 'the pin as it was' );
		// The comment is pinned exactly as the pin left it, so the pin can be undone
		$this->assertSame( 'success', $this->undo( $pinned['results'] )['result'] );
		$this->assertFalse( $this->gtReload( $post )->isPinned() );

		$deleted = $this->moderate( $this->moderator(), 'delete', [ $post->getId() ] );
		$restored = $this->moderate( $this->moderator(), 'restore', [ $post->getId() ] );
		$this->assertSame( 'badstatus', $this->undo( $deleted['results'] )['results'][0]['code'],
			'restored since the deletion' );
		$this->undo( $restored['results'] );
		$this->assertSame( PostStatus::DELETED, $this->gtReload( $post )->getStatus() );
	}

	public function testUndoSeveralEntriesOfOneComment() {
		$page = $this->gtPage();
		$post = $this->gtPost( $this->gtUser(), $page, 'x' );
		$pinned = $this->moderate( $this->moderator(), 'pin', [ $post->getId() ] );
		$deleted = $this->moderate( $this->moderator(), 'delete', [ $post->getId() ] );
		$undone = $this->undo( array_merge( $deleted['results'], $pinned['results'] ) );
		$this->assertSame( [ 'success', 'success' ], array_column( $undone['results'], 'result' ) );
		$after = $this->gtReload( $post );
		$this->assertSame( PostStatus::VISIBLE, $after->getStatus() );
		$this->assertFalse( $after->isPinned() );
		// Repeated IDs still count once for the other actions
		$twice = $this->moderate( $this->moderator(), 'delete', [ $post->getId(), $post->getId() ] );
		$this->assertCount( 1, $twice['results'] );
	}

	/**
	 * Steps in different seconds, so the check that a comment is unchanged since an entry is
	 * exercised for real: an undo puts back who set the status and when.
	 */
	public function testUndoAChainOfStatusChangesNewestFirst() {
		$this->gtSetFilter( 'holdme' );
		$page = $this->gtPage();
		$post = $this->gtPost( $this->gtUser(), $page, 'holdme' );
		ConvertibleTimestamp::setFakeTime( '20261009100100' );
		$approved = $this->moderate( $this->moderator(), 'approve', [ $post->getId() ] );
		ConvertibleTimestamp::setFakeTime( '20261009100200' );
		$deleted = $this->moderate( $this->moderator(), 'delete', [ $post->getId() ] );
		ConvertibleTimestamp::setFakeTime( '20261009100300' );
		$undone = $this->undo( array_merge( $deleted['results'], $approved['results'] ) );
		ConvertibleTimestamp::setFakeTime( false );
		$this->assertSame( [ 'success', 'success' ], array_column( $undone['results'], 'result' ) );
		$after = $this->gtReload( $post );
		$this->assertSame( PostStatus::HELD, $after->getStatus() );
		$this->assertNull( $after->getStatusActorId(), 'an automatic hold again' );
		$this->assertNull( $after->getStatusTimestamp() );
	}

	public function testUndoRefusesWhenTheCommentChangedSince() {
		$page = $this->gtPage();
		$post = $this->gtPost( $this->gtUser(), $page, 'x' );
		$this->gtService( 'ReportService' )->report( $this->gtUser(), $post->getId(), 'spam', '' );
		ConvertibleTimestamp::setFakeTime( '20261009110000' );
		$dismissed = $this->moderate( $this->moderator(), 'dismiss', [ $post->getId() ] );
		$pinned = $this->moderate( $this->moderator(), 'pin', [ $post->getId() ] );
		ConvertibleTimestamp::setFakeTime( '20261009110100' );
		$unpinned = $this->moderate( $this->moderator(), 'unpin', [ $post->getId() ] );
		ConvertibleTimestamp::setFakeTime( '20261009110200' );
		$this->moderate( $this->moderator(), 'pin', [ $post->getId() ] );
		ConvertibleTimestamp::setFakeTime( '20261009110300' );
		$unpinnedAgain = $this->moderate( $this->moderator(), 'unpin', [ $post->getId() ] );
		ConvertibleTimestamp::setFakeTime( '20261009110400' );
		$this->moderate( $this->moderator(), 'delete', [ $post->getId() ] );
		ConvertibleTimestamp::setFakeTime( '20261009110500' );

		$this->assertSame( 'badstatus', $this->undo( $dismissed['results'] )['results'][0]['code'],
			'reports of a comment deleted since stay closed' );
		$this->assertSame( 0, $this->gtReload( $post )->getOpenReportCount() );
		$this->assertSame( 'badstatus', $this->undo( $unpinned['results'] )['results'][0]['code'],
			'pinned and unpinned again since' );
		$this->assertSame( 'badstatus', $this->undo( $pinned['results'] )['results'][0]['code'] );
		// Not even the newest unpin is undone on a deleted comment: only visible comments are pinned
		$this->assertSame( 'badstatus', $this->undo( $unpinnedAgain['results'] )['results'][0]['code'] );
		$this->assertNull( $this->gtReload( $post )->getPinned() );
		ConvertibleTimestamp::setFakeTime( false );
	}

	public function testUndoOfASelfDeletion() {
		$page = $this->gtPage();
		$author = $this->gtUser();
		$post = $this->gtPost( $author, $page, 'mine' );
		$this->gtApi( [ 'action' => 'glowthreaddelete', 'id' => $post->getId() ], $author );
		$logId = (int)$this->gtLogRows( 'glowthread', 'delete' )[0]->log_id;
		$undone = $this->undo( [ [ 'id' => $post->getId(), 'logId' => $logId ] ] );
		$this->assertSame( 'success', $undone['result'] );
		$after = $this->gtReload( $post );
		$this->assertSame( PostStatus::VISIBLE, $after->getStatus() );
		$this->assertNull( $after->getStatusActorId() );
	}

	public function testEntriesWithoutUndoDataOrHiddenCannotBeUndone() {
		$page = $this->gtPage();
		$post = $this->gtPost( $this->gtUser(), $page, 'x' );
		$deleted = $this->moderate( $this->moderator(), 'delete', [ $post->getId() ] );
		$logId = $deleted['results'][0]['logId'];
		$dbw = $this->getDb();
		// An entry without the status it replaced (no 9::prev)
		$params = unserialize( $dbw->newSelectQueryBuilder()->select( 'log_params' )->from( 'logging' )
			->where( [ 'log_id' => $logId ] )->caller( __METHOD__ )->fetchField() );
		$dbw->newUpdateQueryBuilder()->update( 'logging' )
			->set( [ 'log_params' => serialize( array_diff_key( $params, [ '9::prev' => true ] ) ) ] )
			->where( [ 'log_id' => $logId ] )->caller( __METHOD__ )->execute();
		$this->assertSame( 'cannotundo', $this->undo( $deleted['results'] )['results'][0]['code'] );
		// A FlowThread entry names no GlowThread comment
		$legacy = new \ManualLogEntry( 'comments', 'delete' );
		$legacy->setPerformer( $this->moderator() );
		$legacy->setTarget( \MediaWiki\Title\Title::newFromPageIdentity( $page ) );
		$legacy->setParameters( [ '4::username' => 'Somebody' ] );
		$legacyId = $legacy->insert();
		$this->assertSame( 'cannotundo',
			$this->undo( [ [ 'id' => $post->getId(), 'logId' => $legacyId ] ] )['results'][0]['code'] );
		// An entry whose action is suppressed: a moderator without suppression rights cannot name it
		$restored = $this->moderate( $this->moderator(), 'restore', [ $post->getId() ] );
		$dbw->newUpdateQueryBuilder()->update( 'logging' )
			->set( [ 'log_deleted' => LogPage::DELETED_ACTION | LogPage::DELETED_RESTRICTED ] )
			->where( [ 'log_id' => $restored['results'][0]['logId'] ] )->caller( __METHOD__ )->execute();
		$this->assertSame( 'badlogid', $this->undo( $restored['results'] )['results'][0]['code'] );
	}

	public function testUndoDataIsNotInTheLogApi() {
		$page = $this->gtPage();
		$post = $this->gtPost( $this->gtUser(), $page, 'x' );
		$this->gtService( 'ReportService' )->report( $this->gtUser(), $post->getId(), 'spam', '' );
		$this->moderate( $this->moderator(), 'delete', [ $post->getId() ] );
		$events = $this->gtApi( [
			'action' => 'query', 'list' => 'logevents', 'letype' => 'glowthread', 'leprop' => 'details',
		], $this->moderator() )['query']['logevents'];
		$this->assertSame( $post->getId(), $events[0]['params']['comment'] );
		$this->assertArrayNotHasKey( 'old', $events[0]['params'] );
		$this->assertArrayNotHasKey( 'reports', $events[0]['params'] );
		$this->assertArrayNotHasKey( 'prev', $events[0]['params'] );
	}

	public function testUndoRefusals() {
		$page = $this->gtPage();
		$a = $this->gtPost( $this->gtUser(), $page, 'a' );
		$b = $this->gtPost( $this->gtUser(), $page, 'b' );
		$deleted = $this->moderate( $this->moderator(), 'delete', [ $a->getId(), $b->getId() ] );
		$erased = $this->moderate( $this->moderator(), 'erase', [ $a->getId() ] );
		$this->assertSame( 'cannotundo', $this->undo( $erased['results'] )['results'][0]['code'] );
		// A log ID of another comment
		$swapped = [ [ 'id' => $b->getId(), 'logId' => $deleted['results'][0]['logId'] ] ];
		$this->assertSame( 'badlogid', $this->undo( $swapped )['results'][0]['code'] );
		$this->assertSame( 'badlogids', $this->gtApiError( [
			'action' => 'glowthreadmoderate', 'do' => 'undo', 'ids' => $b->getId(),
		], $this->moderator() ) );
		$this->assertSame( 'permissiondenied', $this->gtApiError( [
			'action' => 'glowthreadmoderate', 'do' => 'undo', 'ids' => $b->getId(),
			'logids' => $deleted['results'][1]['logId'],
		], $this->gtUser() ) );
	}

	public function testPinOnlyTopLevelVisible() {
		$page = $this->gtPage();
		$top = $this->gtPost( $this->gtUser(), $page, 'top' );
		$reply = $this->gtPost( $this->gtUser(), $page, 'reply', $top );
		$pinReply = $this->moderate( $this->moderator(), 'pin', [ $reply->getId() ] );
		$this->assertSame( 'cannotpin', $pinReply['results'][0]['code'] );
		$this->assertSame( 'success', $this->moderate( $this->moderator(), 'pin', [ $top->getId() ] )['result'] );
		$this->assertTrue( $this->gtReload( $top )->isPinned() );
		$pinAgain = $this->moderate( $this->moderator(), 'pin', [ $top->getId() ] );
		$this->assertSame( 'nochange', $pinAgain['results'][0]['result'] );
		$this->moderate( $this->moderator(), 'unpin', [ $top->getId() ] );
		$this->assertFalse( $this->gtReload( $top )->isPinned() );
		$this->assertCount( 1, $this->gtLogRows( 'glowthread', 'pin' ) );
		$this->assertCount( 1, $this->gtLogRows( 'glowthread', 'unpin' ) );
	}

	public function testDismissReports() {
		$page = $this->gtPage();
		$post = $this->gtPost( $this->gtUser(), $page, 'x' );
		$this->gtService( 'ReportService' )->report( $this->gtUser(), $post->getId(), 'spam', '' );
		$this->gtService( 'ReportService' )->report( $this->gtUser(), $post->getId(), 'other', '' );
		$this->assertSame( 2, $this->gtReload( $post )->getOpenReportCount() );
		$this->moderate( $this->moderator(), 'dismiss', [ $post->getId() ] );
		$this->assertSame( 0, $this->gtReload( $post )->getOpenReportCount() );
		$this->assertSame( PostStatus::VISIBLE, $this->gtReload( $post )->getStatus() );
		$this->assertCount( 1, $this->gtLogRows( 'glowthread', 'markchecked' ) );
		$dismissAgain = $this->moderate( $this->moderator(), 'dismiss', [ $post->getId() ] );
		$this->assertSame( 'nochange', $dismissAgain['results'][0]['result'] );
	}

	public function testBulkWithPartialFailure() {
		$page = $this->gtPage();
		$a = $this->gtPost( $this->gtUser(), $page, 'a' );
		$b = $this->gtPost( $this->gtUser(), $page, 'b' );
		$result = $this->moderate( $this->moderator(), 'delete', [ $a->getId(), $b->getId(), 999999 ] );
		$this->assertSame( 'partial', $result['result'] );
		$this->assertSame( [ 'success', 'success', 'error' ], array_column( $result['results'], 'result' ) );
		$this->assertSame( 'commentnotfound', $result['results'][2]['code'] );
	}

	public function testModeratorActingOnOwnCommentIsLogged() {
		$page = $this->gtPage();
		$mod = $this->moderator();
		$own = $this->gtPost( $mod, $page, 'my own' );
		$this->moderate( $mod, 'delete', [ $own->getId() ], 'oops' );
		$params = unserialize( $this->gtLogRows( 'glowthread', 'delete' )[0]->log_params );
		$this->assertSame( $mod->getName(), $params['5:user-link:author'] );
	}

	public function testTooManyIds() {
		$this->assertSame( 'toomanyvalues', $this->gtApiError( [
			'action' => 'glowthreadmoderate', 'do' => 'delete', 'ids' => implode( '|', range( 1, 51 ) ),
		], $this->moderator() ) );
	}
}
