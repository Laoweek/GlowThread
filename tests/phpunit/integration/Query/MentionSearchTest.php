<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Query;

use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Extension\GlowThread\Text\TextProcessor;
use MediaWiki\Permissions\Authority;
use MediaWikiIntegrationTestCase;

/**
 * Mentions are stored as <@ID> tokens, so the text search of the moderation queue (q) and of
 * action=flowthread&type=listall (keyword) no longer found a mentioned user's name. A search
 * that is exactly a user's name also finds the comments that mention that user now.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Query\ModerationQueryService
 * @covers \MediaWiki\Extension\GlowThread\Compat\LegacyListService
 */
class MentionSearchTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
		$this->overrideConfigValue( 'GlowThreadLegacyCompat', true );
	}

	/**
	 * @return array{queue:int[],listall:int[]} IDs of the comments each search finds, newest first
	 */
	private function search( Authority $moderator, string $q ): array {
		$viewer = $this->gtService( 'ViewerFactory' )->newViewer( $moderator );
		$queue = $this->gtService( 'ModerationQueryService' )->getQueue( $viewer, 'all', [ 'q' => $q ], '', 20 );
		$listAll = $this->gtService( 'LegacyListService' )->getAll(
			$viewer, [ 'filter' => 'all', 'keyword' => $q ], false, 0, 20, 'hidden'
		);
		return [
			'queue' => array_column( array_column( $queue['items'], 'comment' ), 'id' ),
			'listall' => array_map( 'intval', array_column( $listAll['posts'], 'id' ) ),
		];
	}

	public function testUserNameFindsMentions() {
		$page = $this->gtPage();
		$alice = $this->gtUser();
		$named = $this->gtPost( $this->gtUser(), $page, 'I agree with ' . $alice->getName() );
		$mention = $this->gtPost( $this->gtUser(), $page, 'thanks ' . TextProcessor::mentionToken( $alice->getId() ) );
		$this->gtPost( $this->gtUser(), $page, 'unrelated' );
		$both = [ $mention->getId(), $named->getId() ];
		$this->assertSame(
			[ 'queue' => $both, 'listall' => $both ],
			$this->search( $this->getTestSysop()->getUser(), $alice->getName() )
		);
	}

	public function testHiddenUsersAreFoundOnlyByThoseWhoSeeThem() {
		$page = $this->gtPage();
		$hidden = $this->gtUser();
		$mention = $this->gtPost( $this->gtUser(), $page, 'hi ' . TextProcessor::mentionToken( $hidden->getId() ) );
		$this->assertStatusGood( $this->getServiceContainer()->getBlockUserFactory()->newBlockUser(
			$hidden, $this->getTestUser( [ 'sysop', 'suppress' ] )->getAuthority(), 'infinity', '',
			[ 'isHideUser' => true, 'isAutoblocking' => false ]
		)->placeBlock() );

		$this->assertSame(
			[ 'queue' => [], 'listall' => [] ], $this->search( $this->gtUser( [ 'sysop' ] ), $hidden->getName() )
		);
		$this->assertSame(
			[ 'queue' => [ $mention->getId() ], 'listall' => [ $mention->getId() ] ],
			$this->search( $this->gtUser( [ 'sysop', 'suppress' ] ), $hidden->getName() )
		);
	}
}
