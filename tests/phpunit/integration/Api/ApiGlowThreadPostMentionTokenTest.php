<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Api;

use MediaWiki\Extension\GlowThread\Text\TextProcessor;
use MediaWiki\User\UserIdentity;

/**
 * Mentions are <@ID> tokens: they show the user's current name, and only their users are
 * notified. Typed names are plain text.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Api\ApiGlowThreadPost
 * @covers \MediaWiki\Extension\GlowThread\Policy\WriteGuard
 * @covers \MediaWiki\Extension\GlowThread\Text\MentionResolver
 * @covers \MediaWiki\Extension\GlowThread\Text\TextProcessor
 */
class ApiGlowThreadPostMentionTokenTest extends GlowThreadApiTestCase {

	/** @var int[]|null IDs of the users GlowThreadPostSaved was given as mentioned */
	private ?array $mentioned = null;

	protected function setUp(): void {
		parent::setUp();
		$this->setTemporaryHook( 'GlowThreadPostSaved', function ( $post, $page, $author, array $mentioned ) {
			$this->mentioned = array_map( static fn ( UserIdentity $user ) => $user->getId(), $mentioned );
		} );
	}

	private function post( UserIdentity $author, string $text ): array {
		return $this->gtApi(
			[ 'action' => 'glowthreadpost', 'pageid' => $this->gtPage()->getId(), 'text' => $text ],
			$this->getServiceContainer()->getUserFactory()->newFromUserIdentity( $author )
		)['glowthreadpost'];
	}

	private static function token( UserIdentity $user ): string {
		return TextProcessor::mentionToken( $user->getId() );
	}

	public function testTokensShowNamesAndNotify() {
		$alice = $this->gtUser();
		$bob = $this->gtUser();
		$author = $this->gtUser();
		$tokens = self::token( $bob ) . self::token( $alice ) . self::token( $author );
		$result = $this->post( $author, 'hi ' . self::token( $alice ) . ', ' . $tokens );
		$this->assertSame( 'success', $result['result'] );
		$mention = static fn ( UserIdentity $user ) => [
			'type' => 'mention', 'text' => '@' . $user->getName(), 'user' => $user->getName(), 'id' => $user->getId(),
			'exists' => true,
		];
		$this->assertSame( [
			[ 'type' => 'text', 'text' => 'hi ' ],
			$mention( $alice ),
			[ 'type' => 'text', 'text' => ', ' ],
			$mention( $bob ),
			$mention( $alice ),
			$mention( $author ),
		], $result['comment']['segments'] );
		$this->assertSame( [ $alice->getId(), $bob->getId() ], $this->mentioned, 'each once, not the author' );
	}

	public function testMissingAndHiddenUsersArePlaceholders() {
		$hidden = $this->gtUser();
		$this->assertStatusGood( $this->getServiceContainer()->getBlockUserFactory()->newBlockUser(
			$hidden, $this->getTestUser( [ 'sysop', 'suppress' ] )->getAuthority(), 'infinity', '',
			[ 'isHideUser' => true, 'isAutoblocking' => false ]
		)->placeBlock() );
		$missing = $hidden->getId() + 1000;
		$result = $this->post( $this->gtUser(), self::token( $hidden ) . " <@$missing>" );
		$placeholder = [ 'type' => 'mention', 'text' => TextProcessor::MENTION_PLACEHOLDER, 'exists' => false ];
		$this->assertSame(
			[ $placeholder, [ 'type' => 'text', 'text' => ' ' ], $placeholder ], $result['comment']['segments']
		);
		$this->assertStringNotContainsString( $hidden->getName(), json_encode( $result ) );
		$this->assertSame( [], $this->mentioned );
	}

	/**
	 * Regression (A13): typed names are never guessed, so they are plain text and notify nobody
	 */
	public function testTypedNamesStayText() {
		$alice = $this->gtUser();
		$text = '@丁' . str_repeat( ',', 85 ) . ' @' . str_replace( ' ', '_', $alice->getName() ) . ' hi';
		$result = $this->post( $this->gtUser(), $text );
		$this->assertSame( [ [ 'type' => 'text', 'text' => $text ] ], $result['comment']['segments'] );
		$this->assertSame( [], $this->mentioned );
	}

	public function testMentionCapCountsDistinctUsersOfTokens() {
		$this->overrideConfigValue( 'GlowThreadMaxMentions', 1 );
		$alice = $this->gtUser();
		$bob = $this->gtUser();
		$author = $this->gtUser();
		$this->assertSame( 'toomanymentions', $this->gtApiError( [
			'action' => 'glowthreadpost', 'pageid' => $this->gtPage()->getId(),
			'text' => self::token( $alice ) . ' ' . self::token( $bob ),
		], $author ) );
		// The same user twice, and the author, count once and not at all
		$result = $this->post( $author, self::token( $alice ) . self::token( $alice ) . self::token( $author ) );
		$this->assertSame( 'success', $result['result'] );
	}
}
