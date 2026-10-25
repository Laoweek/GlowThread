<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Api;

/**
 * Security review W8 (= I18): report notes often quote the comment, so they outlived its erasure.
 * Erasing a comment blanks the notes of its reports now.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Service\ModerationService
 * @covers \MediaWiki\Extension\GlowThread\Store\ReportStore
 */
class ApiGlowThreadEraseNotesTest extends GlowThreadApiTestCase {

	private function note( int $postId ): ?string {
		$note = $this->getDb()->newSelectQueryBuilder()
			->select( 'gtrp_note' )->from( 'glowthread_report' )
			->where( [ 'gtrp_post' => $postId ] )->caller( __METHOD__ )->fetchField();
		return $note === null ? null : (string)$note;
	}

	public function testEraseBlanksReportNotes() {
		$page = $this->gtPage();
		$post = $this->gtPost( $this->gtUser(), $page, 'phone 555-0100' );
		$other = $this->gtPost( $this->gtUser(), $page, 'y' );
		foreach ( [ $post, $other ] as $reported ) {
			$this->gtApi( [
				'action' => 'glowthreadreport', 'id' => $reported->getId(), 'reason' => 'other',
				'note' => 'it says phone 555-0100',
			], $this->gtUser() );
		}
		$this->gtModerate( 'delete', [ $post ] );
		$this->assertSame( 'it says phone 555-0100', $this->note( $post->getId() ), 'deleting keeps the note' );

		$this->gtModerate( 'erase', [ $post ] );
		$this->assertNull( $this->note( $post->getId() ) );
		$this->assertSame( 'it says phone 555-0100', $this->note( $other->getId() ), 'other comments keep theirs' );
	}
}
