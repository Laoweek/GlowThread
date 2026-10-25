<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Api;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\GlowThread\Model\HoldReason;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Model\PostStatus;
use MediaWiki\Extension\GlowThread\Text\TextProcessor;
use MediaWiki\MainConfigNames;
use MediaWiki\Title\Title;
use MediaWiki\User\Registration\UserRegistrationLookup;
use TestUser;

/**
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Api\ApiGlowThreadPost
 * @covers \MediaWiki\Extension\GlowThread\Service\PostingService
 * @covers \MediaWiki\Extension\GlowThread\Policy\WriteGuard
 */
class ApiGlowThreadPostTest extends GlowThreadApiTestCase {

	/**
	 * Mention syntax for a user: the token with the user's ID.
	 *
	 * @param \MediaWiki\User\UserIdentity $user
	 * @return string
	 */
	private static function mention( $user ): string {
		return TextProcessor::mentionToken( $user->getId() );
	}

	private function post( $performer, int $pageId, string $text, ?int $replyTo = null ): array {
		$params = [ 'action' => 'glowthreadpost', 'pageid' => $pageId, 'text' => $text ];
		if ( $replyTo !== null ) {
			$params['replyto'] = $replyTo;
		}
		return $this->gtApi( $params, $performer )['glowthreadpost'];
	}

	public function testPostAndReplyLevels() {
		$page = $this->gtPage();
		$alice = $this->gtUser();
		$bob = $this->gtUser();

		$top = $this->post( $alice, $page->getId(), 'Top level' );
		$this->assertSame( 'success', $top['result'] );
		$this->assertSame( 'visible', $top['status'] );
		$this->assertSame( 1, $top['seq'] );
		$this->assertSame( 0, $top['comment']['depth'] );
		$this->assertSame( [ [ 'type' => 'text', 'text' => 'Top level' ] ], $top['comment']['segments'] );

		$r1 = $this->post( $bob, $page->getId(), 'Reply', $top['id'] );
		$this->assertSame( 2, $r1['seq'] );
		$this->assertSame( 1, $r1['comment']['depth'] );
		$this->assertSame( $top['id'], $r1['comment']['parentId'] );

		$r2 = $this->post( $alice, $page->getId(), 'Reply to reply', $r1['id'] );
		$this->assertSame( 2, $r2['comment']['depth'] );
		$this->assertSame( $r1['id'], $r2['comment']['parentId'] );
		$this->assertSame( $top['id'], $r2['comment']['threadId'] );

		// A reply to a depth-2 comment is shown at depth 2, under the same depth-1 comment
		$r3 = $this->post( $bob, $page->getId(), 'Deeper', $r2['id'] );
		$this->assertSame( 2, $r3['comment']['depth'] );
		$this->assertSame( $r1['id'], $r3['comment']['parentId'] );
		$this->assertSame( $r2['id'], $r3['comment']['replyTo']['id'] );
		$this->assertSame( $alice->getName(), $r3['comment']['replyTo']['author']['name'] );

		$store = $this->gtService( 'PostStore' );
		$this->assertSame( 1, $store->getById( $top['id'] )->getReplyCount() );
		$this->assertSame( 2, $store->getById( $r1['id'] )->getReplyCount() );
	}

	public function testEmojiAndFourByteCharactersRoundTrip() {
		$page = $this->gtPage();
		// i18n: three- and four-byte characters (CJK, a CJK Extension B ideograph, emoji)
		$text = "Great recipe 你好🍞🎉 𠮷 👨\u{200D}👩\u{200D}👧 🇨🇳";
		$result = $this->post( $this->gtUser(), $page->getId(), $text );
		$this->assertSame( $text, $result['comment']['segments'][0]['text'] );
		$stored = $this->gtService( 'PostStore' )->getById( $result['id'], true );
		$this->assertSame( $text, $stored->getText() );
	}

	public function testUnsafeTextIsStoredAsTyped() {
		$page = $this->gtPage();
		$text = '<script>alert(1)</script> [[Special:UserLogout]] {{SITENAME}}';
		$result = $this->post( $this->gtUser(), $page->getId(), $text );
		$this->assertSame( [ [ 'type' => 'text', 'text' => $text ] ], $result['comment']['segments'] );
	}

	public function testAnonymousCannotPost() {
		$page = $this->gtPage();
		$anon = $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' );
		$code = $this->gtApiError(
			[ 'action' => 'glowthreadpost', 'pageid' => $page->getId(), 'text' => 'x' ], $anon
		);
		$this->assertStringContainsString( 'permissiondenied', $code );
	}

	public function testSitewideBlockedUserCannotPost() {
		$page = $this->gtPage();
		$user = $this->gtUser();
		$this->gtBlockSitewide( $user );
		$code = $this->gtApiError(
			[ 'action' => 'glowthreadpost', 'pageid' => $page->getId(), 'text' => 'x' ], $user
		);
		$this->assertStringContainsString( 'blocked', $code );
	}

	public function testPartialBlockAppliesOnlyToCoveredPage() {
		$blockedPage = $this->gtPage( 'GlowThread blocked page' );
		$otherPage = $this->gtPage( 'GlowThread other page' );
		$user = $this->gtUser();
		$this->gtBlockFromPage( $user, $blockedPage );

		$code = $this->gtApiError(
			[ 'action' => 'glowthreadpost', 'pageid' => $blockedPage->getId(), 'text' => 'x' ], $user
		);
		$this->assertStringContainsString( 'blocked', $code );
		$this->assertSame( 'visible', $this->post( $user, $otherPage->getId(), 'fine here' )['status'] );
	}

	public function testRateLimit() {
		$this->overrideConfigValue( MainConfigNames::RateLimits, [
			'glowthread-post' => [ 'user' => [ 1, 60 ], 'newbie' => [ 1, 60 ] ],
		] );
		$page = $this->gtPage();
		$user = $this->gtUser();
		$this->post( $user, $page->getId(), 'first' );
		$error = $this->gtApiErrorArray(
			[ 'action' => 'glowthreadpost', 'pageid' => $page->getId(), 'text' => 'second' ], $user
		);
		$this->assertSame( 'ratelimited', $error['code'] );
		// How long to wait, in seconds (the window of the limit)
		$this->assertSame( 60, $error['data']['retryAfter'] );
	}

	/**
	 * With the extension's default limits, accounts behind one IP address share
	 * an ip-all ceiling (12 posts a minute), so a farm of fresh accounts cannot multiply the
	 * per-account limit.
	 */
	public function testAccountsBehindOneAddressShareALimit() {
		$this->overrideConfigValue( MainConfigNames::RateLimits, $this->gtDefaultRateLimits() );
		$page = $this->gtPage();
		foreach ( [ $this->gtUser(), $this->gtUser() ] as $n => $user ) {
			for ( $i = 0; $i < 6; $i++ ) {
				$this->assertSame( 'success', $this->post( $user, $page->getId(), "post $n $i" )['result'] );
			}
		}
		$error = $this->gtApiErrorArray(
			[ 'action' => 'glowthreadpost', 'pageid' => $page->getId(), 'text' => 'one more' ], $this->gtUser()
		);
		$this->assertSame( 'ratelimited', $error['code'] );
		$this->assertSame( 60, $error['data']['retryAfter'] );
	}

	/**
	 * Oversized input is refused by its byte length before it is normalised.
	 */
	public function testOversizedRawInputIsRefusedBeforeNormalisation() {
		$this->overrideConfigValue( 'GlowThreadMaxLength', 1000 );
		$page = $this->gtPage();
		// One letter and 9000 zero-width spaces: 27001 bytes that normalise to "a"
		$raw = 'a' . str_repeat( "\u{200B}", 9000 );
		$this->assertSame( 'a', $this->gtService( 'TextProcessor' )->normalize( $raw ) );
		$error = $this->gtApiErrorArray(
			[ 'action' => 'glowthreadpost', 'pageid' => $page->getId(), 'text' => $raw ], $this->gtUser()
		);
		$this->assertSame( 'toolong', $error['code'] );
		$this->assertSame( 1000, $error['data']['max'] );
		// Within 4 bytes per character plus the slack, the normal rules apply
		$this->assertSame( 'success', $this->post( $this->gtUser(), $page->getId(),
			'a' . str_repeat( "\u{200B}", 1000 ) )['result'] );
	}

	/**
	 * An author with GlowThreadMaxHeldPerUser comments waiting for review
	 * cannot add another held one. Comments that would be visible are not affected.
	 */
	public function testHeldCommentsPerAuthorAreCapped() {
		$this->overrideConfigValue( 'GlowThreadMaxHeldPerUser', 3 );
		$this->gtSetFilter( 'holdme' );
		$page = $this->gtPage();
		$author = $this->gtUser();
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertSame( 'held', $this->post( $author, $page->getId(), "holdme $i" )['status'] );
		}
		$error = $this->gtApiErrorArray(
			[ 'action' => 'glowthreadpost', 'pageid' => $page->getId(), 'text' => 'holdme 4' ], $author
		);
		$this->assertSame( 'toomanyheld', $error['code'] );
		$this->assertSame( 3, $error['data']['max'] );
		$this->assertSame( 'visible', $this->post( $author, $page->getId(), 'fine' )['status'] );
		$this->assertSame( 'held', $this->post( $this->gtUser(), $page->getId(), 'holdme other' )['status'],
			'the cap is per author' );
	}

	public function testNoRateLimitRightIsHonoured() {
		$this->overrideConfigValue( MainConfigNames::RateLimits, [
			'glowthread-post' => [ 'user' => [ 1, 60 ] ],
		] );
		$page = $this->gtPage();
		$sysop = $this->getTestSysop()->getUser();
		$this->post( $sysop, $page->getId(), 'first' );
		$this->assertSame( 'success', $this->post( $sysop, $page->getId(), 'second' )['result'] );
	}

	public function testCrossPageParentIsRejected() {
		$pageA = $this->gtPage( 'GlowThread page A' );
		$pageB = $this->gtPage( 'GlowThread page B' );
		$user = $this->gtUser();
		$parent = $this->post( $user, $pageA->getId(), 'on A' );
		$code = $this->gtApiError( [
			'action' => 'glowthreadpost', 'pageid' => $pageB->getId(), 'text' => 'sneaky', 'replyto' => $parent['id'],
		], $this->gtUser() );
		$this->assertSame( 'badparent', $code );
	}

	public function testReplyToDeletedOrMissingParentIsRejected() {
		$page = $this->gtPage();
		$user = $this->gtUser();
		$parent = $this->post( $user, $page->getId(), 'soon gone' );
		$this->gtService( 'ModerationService' )->moderate(
			$this->getTestSysop()->getAuthority(), 'delete', [ $parent['id'] ], ''
		);
		$params = [ 'action' => 'glowthreadpost', 'pageid' => $page->getId(), 'text' => 'reply' ];
		$this->assertSame(
			'badparent', $this->gtApiError( $params + [ 'replyto' => $parent['id'] ], $this->gtUser() )
		);
		$this->assertSame( 'badparent', $this->gtApiError( $params + [ 'replyto' => 999999 ], $this->gtUser() ) );
	}

	public function testReplyToOwnHeldCommentIsAllowedButNotToOthers() {
		$this->gtSetFilter( 'holdme' );
		$page = $this->gtPage();
		$author = $this->gtUser();
		$held = $this->post( $author, $page->getId(), 'holdme please' );
		$this->assertSame( 'held', $held['status'] );
		// The reply waits for review with its parent, so it cannot notify before it is approved
		$reply = $this->post( $author, $page->getId(), 'adding context', $held['id'] );
		$this->assertSame( 'held', $reply['status'] );
		$this->assertSame( [ HoldReason::PARENT_HELD ], $reply['holdReasons'] );
		$replyToReply = $this->post( $author, $page->getId(), 'and more', $reply['id'] );
		$this->assertSame( [ HoldReason::PARENT_HELD ], $replyToReply['holdReasons'] );
		$this->assertSame( 'badparent', $this->gtApiError( [
			'action' => 'glowthreadpost', 'pageid' => $page->getId(), 'text' => 'hi', 'replyto' => $held['id'],
		], $this->gtUser() ) );
	}

	public function testWordFilterHoldsInsteadOfRejecting() {
		$this->gtSetFilter( "# rules\nbadword\n/f+o+o/i" );
		$page = $this->gtPage();
		$result = $this->post( $this->gtUser(), $page->getId(), 'this has a B A D W O R D in it' );
		$this->assertSame( 'held', $result['status'] );
		$this->assertSame( [], $result['holdReasons'], 'the author is not told which check held it' );
		$this->assertSame( 'visible', $this->post( $this->gtUser(), $page->getId(), 'clean text' )['status'] );
		$this->assertSame( 'held', $this->post( $this->gtUser(), $page->getId(), 'FFOOO' )['status'] );
	}

	public function testHeldCommentKeepsTheRulesThatMatched() {
		$this->gtSetFilter( "# rules\nbadword\n/f+o+o/i\nunused" );
		$page = $this->gtPage();
		$held = $this->post( $this->gtUser(), $page->getId(), 'badword and FFOOO' );
		$this->assertSame( 'held', $held['status'] );
		// Later rule changes do not change what is shown for this hold
		$this->gtSetFilter( 'something else' );
		$post = $this->gtService( 'PostStore' )->getById( $held['id'] );
		$this->assertSame( [
			[ 'line' => 2, 'rule' => 'badword', 'type' => 'literal' ],
			[ 'line' => 3, 'rule' => '/f+o+o/i', 'type' => 'regex' ],
		], $post->getHoldMatches() );
		$visible = $this->post( $this->gtUser(), $page->getId(), 'badword is fine now' );
		$this->assertSame( 'visible', $visible['status'] );
		$this->assertSame( [], $this->gtService( 'PostStore' )->getById( $visible['id'] )->getHoldMatches() );
	}

	public function testNewAccountGate() {
		$this->overrideConfigValues( [
			'GlowThreadNewAccountHours' => 24,
			'GlowThreadNewAccountApproved' => 1,
		] );
		$page = $this->gtPage();
		$newbie = $this->gtUser();
		$result = $this->post( $newbie, $page->getId(), 'first comment' );
		$this->assertSame( 'held', $result['status'] );
		$this->assertSame( [ 'newaccount' ], $result['holdReasons'] );

		// Trusted users skip the gate
		$sysop = $this->getTestSysop()->getUser();
		$this->assertSame( 'visible', $this->post( $sysop, $page->getId(), 'sysop' )['status'] );

		// Once a moderator approved one comment, the young account posts directly
		$this->gtService( 'ModerationService' )->moderate(
			$this->getTestSysop()->getAuthority(), 'approve', [ $result['id'] ], ''
		);
		$this->assertSame( 'visible', $this->post( $newbie, $page->getId(), 'second comment' )['status'] );
	}

	/**
	 * The gate reads the account's age from UserRegistrationLookup (User::getRegistration() is
	 * deprecated since 1.45). On 1.43 that method does not ask the lookup, so this fails there
	 * when the gate calls it.
	 */
	public function testNewAccountGateUsesUserRegistrationLookup() {
		$this->overrideConfigValues( [
			'GlowThreadNewAccountHours' => 24,
			'GlowThreadNewAccountApproved' => 1,
		] );
		$lookup = $this->createMock( UserRegistrationLookup::class );
		$lookup->method( 'getRegistration' )->willReturn( '20010115000000' );
		$this->setService( 'UserRegistrationLookup', $lookup );
		$page = $this->gtPage();
		$this->assertSame( 'visible', $this->post( $this->gtUser(), $page->getId(), 'old account' )['status'] );
	}

	public function testLengthCapCountsCodePoints() {
		$this->overrideConfigValue( 'GlowThreadMaxLength', 5 );
		$page = $this->gtPage();
		$user = $this->gtUser();
		// i18n: a CJK character and an emoji count one code point each
		$this->assertSame( 'success', $this->post( $user, $page->getId(), '你好世界🍞' )['result'] );
		$error = $this->gtApiError(
			[ 'action' => 'glowthreadpost', 'pageid' => $page->getId(), 'text' => '你好世界🍞!' ], $user
		);
		$this->assertSame( 'toolong', $error );
	}

	public function testEmptyAfterNormalisationIsRejected() {
		$page = $this->gtPage();
		$this->assertSame( 'emptycomment', $this->gtApiError(
			[ 'action' => 'glowthreadpost', 'pageid' => $page->getId(), 'text' => " \u{200B}\u{202E} \n" ],
			$this->gtUser()
		) );
	}

	public function testMentionCap() {
		$this->overrideConfigValue( 'GlowThreadMaxMentions', 1 );
		$page = $this->gtPage();
		$a = $this->gtUser();
		$b = $this->gtUser();
		$user = $this->gtUser();
		$missing = $b->getId() + 1000;
		$ok = $this->post( $user, $page->getId(), self::mention( $a ) . " <@$missing>" );
		$this->assertSame( 'success', $ok['result'] );
		$error = $this->gtApiError( [
			'action' => 'glowthreadpost', 'pageid' => $page->getId(),
			'text' => self::mention( $a ) . ' ' . self::mention( $b ),
		], $user );
		$this->assertSame( 'toomanymentions', $error );
	}

	/**
	 * The cap counts a user whose name has an apostrophe once however often the comment
	 * mentions them, and "'s" after the mention stays text. Typed without picking, the name is
	 * plain text and does not count.
	 */
	public function testMentionCapCountsNamesWithApostrophes() {
		$this->overrideConfigValue( 'GlowThreadMaxMentions', 1 );
		$page = $this->gtPage();
		$obrien = ( new TestUser( "O'Brien" ) )->getUser();
		$user = $this->gtUser();
		$ok = $this->post( $user, $page->getId(),
			self::mention( $obrien ) . "'s idea, thanks " . self::mention( $obrien ) . '!' );
		$this->assertSame( 'success', $ok['result'] );
		$mention = [
			'type' => 'mention', 'text' => "@O'Brien", 'user' => "O'Brien", 'id' => $obrien->getId(), 'exists' => true,
		];
		$this->assertSame( [
			$mention, [ 'type' => 'text', 'text' => "'s idea, thanks " ], $mention, [ 'type' => 'text', 'text' => '!' ],
		], $ok['comment']['segments'] );
		$error = $this->gtApiError( [
			'action' => 'glowthreadpost', 'pageid' => $page->getId(),
			'text' => self::mention( $this->gtUser() ) . ' and ' . self::mention( $obrien ),
		], $user );
		$this->assertSame( 'toomanymentions', $error );
		$typed = $this->post( $user, $page->getId(), self::mention( $this->gtUser() ) . " and @O'Brien" );
		$this->assertSame( 'success', $typed['result'] );
		$this->assertSame( [ 'type' => 'text', 'text' => " and @O'Brien" ], $typed['comment']['segments'][1] );
	}

	public function testDuplicateTextIsRejected() {
		$page = $this->gtPage();
		$user = $this->gtUser();
		$this->post( $user, $page->getId(), 'Thanks for the tip!' );
		$this->assertSame( 'duplicate', $this->gtApiError(
			[ 'action' => 'glowthreadpost', 'pageid' => $page->getId(), 'text' => 'Thanks for the tip!  ' ], $user
		) );
		// Another user, or another page, is fine
		$this->assertSame( 'success', $this->post( $this->gtUser(), $page->getId(), 'Thanks for the tip!' )['result'] );
		$other = $this->gtPage( 'GlowThread another page' );
		$this->assertSame( 'success', $this->post( $user, $other->getId(), 'Thanks for the tip!' )['result'] );
	}

	public function testLockedAndDisabledPages() {
		$page = $this->gtPage();
		$sysop = $this->getTestSysop()->getAuthority();
		$pageState = $this->gtService( 'PageStateService' );
		$params = [ 'action' => 'glowthreadpost', 'pageid' => $page->getId(), 'text' => 'x' ];

		$this->assertStatusGood( $pageState->setState( $sysop, $page->getId(), 'locked', '' ) );
		$this->assertSame( 'commentslocked', $this->gtApiError( $params, $this->gtUser() ) );

		$this->assertStatusGood( $pageState->setState( $sysop, $page->getId(), 'disabled', '' ) );
		$this->assertSame( 'commentsdisabled', $this->gtApiError( $params, $this->gtUser() ) );

		$this->assertStatusGood( $pageState->setState( $sysop, $page->getId(), 'open', '' ) );
		$this->assertSame( 'success', $this->post( $this->gtUser(), $page->getId(), 'x' )['result'] );
	}

	public function testIneligiblePages() {
		$user = $this->gtUser();
		$talk = $this->getExistingTestPage( Title::makeTitle( NS_TALK, 'GlowThread talk' ) );
		$this->assertSame( 'pageineligible', $this->gtApiError(
			[ 'action' => 'glowthreadpost', 'pageid' => $talk->getId(), 'text' => 'x' ], $user
		) );
		$main = $this->getExistingTestPage( Title::newMainPage() );
		$this->assertSame( 'pageineligible', $this->gtApiError(
			[ 'action' => 'glowthreadpost', 'pageid' => $main->getId(), 'text' => 'x' ], $user
		) );
		$this->assertSame( 'pagenotfound', $this->gtApiError(
			[ 'action' => 'glowthreadpost', 'pageid' => 987654, 'text' => 'x' ], $user
		) );
	}

	public function testReadOnlyMode() {
		$page = $this->gtPage();
		$this->overrideConfigValue( MainConfigNames::ReadOnly, 'maintenance' );
		$this->assertSame( 'readonly', $this->gtApiError(
			[ 'action' => 'glowthreadpost', 'pageid' => $page->getId(), 'text' => 'x' ], $this->gtUser()
		) );
	}

	public function testPostSavedHookCarriesStatusMentionsAndReplyTarget() {
		$this->gtSetFilter( 'holdme' );
		$page = $this->gtPage();
		$alice = $this->gtUser();
		$bob = $this->gtUser();
		$calls = [];
		$this->setTemporaryHook( 'GlowThreadPostSaved',
			static function (
				PostRecord $post, $p, $author, array $mentioned, $replyTo, $replyToAuthor
			) use ( &$calls ) {
				$calls[] = [
					'status' => $post->getStatus(),
					'author' => $author->getName(),
					'mentioned' => array_map( static fn ( $u ) => $u->getName(), $mentioned ),
					'replyTo' => $replyTo ? $replyTo->getId() : null,
					'replyToAuthor' => $replyToAuthor ? $replyToAuthor->getName() : null,
				];
			}
		);
		$top = $this->post( $alice, $page->getId(), 'Hi ' . self::mention( $bob ) . ' and ' . self::mention( $alice ) );
		$this->post( $bob, $page->getId(), 'holdme reply', $top['id'] );

		DeferredUpdates::doUpdates();
		$this->assertCount( 2, $calls );
		$this->assertSame( PostStatus::VISIBLE, $calls[0]['status'] );
		$this->assertSame( [ $bob->getName() ], $calls[0]['mentioned'], 'self-mentions are left out' );
		$this->assertNull( $calls[0]['replyTo'] );
		$this->assertSame( PostStatus::HELD, $calls[1]['status'] );
		$this->assertSame( $top['id'], $calls[1]['replyTo'] );
		$this->assertSame( $alice->getName(), $calls[1]['replyToAuthor'] );
	}

	public function testFloorNumbersAreNeverReused() {
		$page = $this->gtPage();
		$user = $this->gtUser();
		$first = $this->post( $user, $page->getId(), 'one' );
		$this->gtService( 'ModerationService' )->selfDelete( $user, $first['id'], '' );
		$second = $this->post( $user, $page->getId(), 'two' );
		$this->assertSame( 2, $second['seq'] );
	}

	public function testWritesNeedReadAccessToThePage() {
		$page = $this->gtPage( 'GlowThread secret page' );
		$post = $this->gtPost( $this->gtUser(), $page, 'x' );
		$pageId = $page->getId();
		$this->setTemporaryHook( 'getUserPermissionsErrors',
			static function ( $title, $user, $action, &$result ) use ( $pageId ) {
				if ( $action === 'read' && $title->getArticleID() === $pageId ) {
					$result = 'badaccess-group0';
					return false;
				}
				return true;
			}
		);
		$user = $this->gtUser();
		foreach ( [
			[ 'action' => 'glowthreadpost', 'pageid' => $pageId, 'text' => 'hi' ],
			[ 'action' => 'glowthreadreact', 'id' => $post->getId(), 'value' => 1 ],
			[ 'action' => 'glowthreadreport', 'id' => $post->getId(), 'reason' => 'spam' ],
		] as $params ) {
			$this->assertSame( 'permissiondenied', $this->gtApiError( $params, $user ), $params['action'] );
		}
		$this->assertSame( 0, $this->gtReload( $post )->getLikeCount() );
	}
}
