<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Api;

use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Model\PostStatus;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Restore puts a deleted comment back to the status it had before the deletion: a comment
 * deleted while held is held again, not published. Moderators see that
 * status as mod.deletedFrom.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Service\ModerationService
 * @covers \MediaWiki\Extension\GlowThread\Log\ModerationLogger
 * @covers \MediaWiki\Extension\GlowThread\Query\CommentSerializer
 */
class ApiGlowThreadRestoreTest extends GlowThreadApiTestCase {

	private function moderate( string $do, array $ids, array $extra = [] ): array {
		return $this->gtApi( [
			'action' => 'glowthreadmoderate', 'do' => $do, 'ids' => implode( '|', $ids ),
		] + $extra, $this->getTestSysop()->getUser() )['glowthreadmoderate'];
	}

	/**
	 * mod.deletedFrom of a comment as a moderator gets it
	 */
	private function deletedFrom( PostRecord $post ): ?string {
		$viewer = $this->gtService( 'ViewerFactory' )->newViewer( $this->getTestSysop()->getAuthority() );
		$post = $this->gtReload( $post );
		$data = $this->gtService( 'CommentSerializer' )->serializeAll( [ $post ], $viewer );
		return $data[$post->getId()]['mod']['deletedFrom'];
	}

	public function testCommentDeletedWhileHeldIsHeldAgain() {
		$this->gtSetFilter( 'holdme' );
		$page = $this->gtPage();
		$top = $this->gtPost( $this->gtUser(), $page, 'top' );
		$held = $this->gtPost( $this->gtUser(), $page, 'holdme please', $top );
		$this->assertSame( PostStatus::HELD, $held->getStatus() );

		$this->moderate( 'delete', [ $held->getId() ] );
		$this->assertSame( 'held', $this->deletedFrom( $held ) );

		$restored = $this->moderate( 'restore', [ $held->getId() ] );
		$this->assertSame( 'success', $restored['results'][0]['result'] );
		$this->assertSame( 'held', $restored['results'][0]['status'] );
		$this->assertSame( 'deleted', $restored['results'][0]['old'] );
		$after = $this->gtReload( $held );
		$this->assertSame( PostStatus::HELD, $after->getStatus() );
		$this->assertSame( [ 'filter' ], $after->getHoldReasons() );
		$this->assertSame( 0, $this->gtReload( $top )->getReplyCount(), 'readers still do not see it' );
		$this->assertNull( $this->deletedFrom( $held ), 'only deleted comments have it' );
		// The status the restore set is kept for undo, but not shown in the log API
		$events = $this->gtApi( [
			'action' => 'query', 'list' => 'logevents', 'letype' => 'glowthread', 'leprop' => 'type|details',
		], $this->getTestSysop()->getUser() )['query']['logevents'];
		$this->assertSame( 'recover', $events[0]['action'] );
		$this->assertSame( $held->getId(), $events[0]['params']['comment'] );
		$this->assertArrayNotHasKey( 'new', $events[0]['params'] );

		// Approving publishes it as usual
		$this->moderate( 'approve', [ $held->getId() ] );
		$this->assertSame( PostStatus::VISIBLE, $this->gtReload( $held )->getStatus() );
		$this->assertSame( 1, $this->gtReload( $top )->getReplyCount() );
	}

	public function testEachCommentGoesBackToItsOwnStatus() {
		$this->gtSetFilter( 'holdme' );
		$page = $this->gtPage();
		$visible = $this->gtPost( $this->gtUser(), $page, 'fine' );
		$author = $this->gtUser();
		$held = $this->gtPost( $author, $page, 'holdme' );
		$this->moderate( 'delete', [ $visible->getId() ] );
		// The author deleting their own held comment
		$this->gtApi( [ 'action' => 'glowthreaddelete', 'id' => $held->getId() ], $author );
		$this->assertSame( 'visible', $this->deletedFrom( $visible ) );
		$this->assertSame( 'held', $this->deletedFrom( $held ) );

		$restored = $this->moderate( 'restore', [ $visible->getId(), $held->getId() ] );
		$this->assertSame( [ 'visible', 'held' ], array_column( $restored['results'], 'status' ) );
	}

	public function testUnknownStatusBeforeTheDeletionRestoresVisible() {
		$this->gtSetFilter( 'holdme' );
		$held = $this->gtPost( $this->gtUser(), $this->gtPage(), 'holdme' );
		// Deleted in FlowThread and imported: no glowthread/delete entry says what it was
		$this->getDb()->newUpdateQueryBuilder()->update( 'glowthread_post' )
			->set( [ 'gtp_status' => PostStatus::DELETED, 'gtp_status_timestamp' => $this->getDb()->timestamp() ] )
			->where( [ 'gtp_id' => $held->getId() ] )->caller( __METHOD__ )->execute();

		$this->assertNull( $this->deletedFrom( $held ) );
		$this->assertSame( 'visible', $this->moderate( 'restore', [ $held->getId() ] )['results'][0]['status'] );
	}

	/**
	 * Steps in different seconds, so the deletion entry is found by the comment's status time
	 */
	public function testUndoOfARestoreToHeld() {
		$this->gtSetFilter( 'holdme' );
		$page = $this->gtPage();
		$top = $this->gtPost( $this->gtUser(), $page, 'top' );
		$held = $this->gtPost( $this->gtUser(), $page, 'holdme', $top );
		ConvertibleTimestamp::setFakeTime( '20261009120000' );
		$this->moderate( 'delete', [ $held->getId() ] );
		ConvertibleTimestamp::setFakeTime( '20261009120100' );
		$restored = $this->moderate( 'restore', [ $held->getId() ] );
		ConvertibleTimestamp::setFakeTime( '20261009120200' );
		$undone = $this->moderate( 'undo', [ $held->getId() ], [ 'logids' => $restored['results'][0]['logId'] ] );
		$this->assertSame( 'success', $undone['results'][0]['result'] );
		$this->assertSame( 'deleted', $undone['results'][0]['status'] );
		$this->assertSame( 0, $this->gtReload( $top )->getReplyCount() );
		// Deleted as by the first deletion again, so another restore goes back to held too
		$this->assertSame( 'held', $this->deletedFrom( $held ) );
		ConvertibleTimestamp::setFakeTime( '20261009120300' );
		$this->assertSame( 'held', $this->moderate( 'restore', [ $held->getId() ] )['results'][0]['status'] );
		ConvertibleTimestamp::setFakeTime( false );
	}

	/**
	 * All in one second: a later deletion that was undone has the same time as the current one,
	 * and must not decide where the comment goes back to.
	 */
	public function testAnUndoneDeletionOfTheSameSecondDoesNotCount() {
		$this->gtSetFilter( 'holdme' );
		$page = $this->gtPage();
		$post = $this->gtPost( $this->gtUser(), $page, 'holdme' );
		$id = $post->getId();
		ConvertibleTimestamp::setFakeTime( '20261009130000' );
		$this->moderate( 'delete', [ $id ] );
		$steps = [];
		foreach ( [ 'restore', 'approve', 'delete' ] as $do ) {
			$steps[] = $this->moderate( $do, [ $id ] )['results'][0]['logId'];
		}
		foreach ( array_reverse( $steps ) as $logId ) {
			$undone = $this->moderate( 'undo', [ $id ], [ 'logids' => $logId ] );
			$this->assertSame( 'success', $undone['results'][0]['result'] );
		}
		$this->assertSame( PostStatus::DELETED, $this->gtReload( $post )->getStatus() );
		$this->assertSame( 'held', $this->deletedFrom( $post ) );
		$this->assertSame( 'held', $this->moderate( 'restore', [ $id ] )['results'][0]['status'] );
		ConvertibleTimestamp::setFakeTime( false );
	}

	/**
	 * A restore is undone only while the comment has the status that restore set: here its
	 * author deletes it again in the same second. (Reports no longer hold a restored comment.)
	 */
	public function testUndoOfARestoreRefusesWhenTheAuthorDeletedItSince() {
		$page = $this->gtPage();
		$author = $this->gtUser();
		$post = $this->gtPost( $author, $page, 'x' );
		ConvertibleTimestamp::setFakeTime( '20261009140000' );
		$this->moderate( 'delete', [ $post->getId() ] );
		$restored = $this->moderate( 'restore', [ $post->getId() ] );
		$this->assertSame( 'visible', $restored['results'][0]['status'] );
		$this->assertStatusGood( $this->gtService( 'ModerationService' )->selfDelete( $author, $post->getId(), '' ) );
		$this->assertSame( PostStatus::DELETED, $this->gtReload( $post )->getStatus() );

		$undone = $this->moderate( 'undo', [ $post->getId() ], [ 'logids' => $restored['results'][0]['logId'] ] );
		$this->assertSame( 'badstatus', $undone['results'][0]['code'] );
		$this->assertSame( PostStatus::DELETED, $this->gtReload( $post )->getStatus() );
		ConvertibleTimestamp::setFakeTime( false );
	}
}
