<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Api;

use MediaWiki\Extension\GlowThread\Model\PostStatus;

/**
 * Security review W7: moderators could delete, approve, pin or erase comments by ID, and close
 * the comment section, on pages they may not read. Such comments and pages are answered as
 * missing now.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Service\ModerationService
 * @covers \MediaWiki\Extension\GlowThread\Service\PageStateService
 */
class ApiGlowThreadModerateReadAccessTest extends GlowThreadApiTestCase {

	public function testUnreadablePagesAreAnsweredAsMissing() {
		$hidden = $this->gtPage( 'Staff only page' );
		$post = $this->gtPost( $this->gtUser(), $hidden, 'x' );
		$this->setTemporaryHook( 'getUserPermissionsErrors',
			static function ( $title, $user, $action, &$result ) use ( $hidden ) {
				if ( $action === 'read' && $title->getArticleID() === $hidden->getId() ) {
					$result = [ 'badaccess-group0' ];
					return false;
				}
				return true;
			}
		);
		$sysop = $this->getTestSysop()->getUser();
		$result = $this->gtApi( [
			'action' => 'glowthreadmoderate', 'do' => 'delete', 'ids' => $post->getId(),
		], $sysop )['glowthreadmoderate']['results'][0];
		$this->assertSame( 'commentnotfound', $result['code'] );
		$this->assertNull( $result['status'], 'the status does not tell that the comment exists' );
		$this->assertSame( PostStatus::VISIBLE, $this->gtReload( $post )->getStatus() );

		$this->assertSame( 'pagenotfound', $this->gtApiError(
			[ 'action' => 'glowthreadpage', 'pageid' => $hidden->getId(), 'state' => 'disabled' ], $sysop
		) );
	}
}
