<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Compat;

use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiParamInfo;
use MediaWiki\Api\ApiUsageException;
use MediaWiki\Extension\GlowThread\Compat\ApiFlowThreadCompat;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Extension\GlowThread\Text\TextProcessor;
use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\Authority;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\User\UserIdentityValue;

/**
 * The read-only action=flowthread module.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Compat\ApiFlowThreadCompat
 * @covers \MediaWiki\Extension\GlowThread\Compat\LegacyListService
 * @covers \MediaWiki\Extension\GlowThread\Compat\CompatHooks
 */
class ApiFlowThreadCompatTest extends ApiTestCase {
	use GlowThreadTestTrait;

	private const POST_KEYS = [ 'id', 'userid', 'username', 'text', 'timestamp', 'parentid', 'like', 'myatt' ];

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
		// Off by default, on for wikis that used FlowThread
		$this->overrideConfigValue( 'GlowThreadLegacyCompat', true );
	}

	private function anon(): Authority {
		return $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' );
	}

	private function call( array $params, ?Authority $performer = null, bool $post = false ): array {
		return $this->doApiRequest(
			$params + [ 'action' => 'flowthread' ], null, $post, $performer ?? $this->anon()
		)[0]['flowthread'];
	}

	private function errorCode( array $params, ?Authority $performer = null, bool $post = false ): string {
		try {
			$this->call( $params, $performer, $post );
		} catch ( ApiUsageException $e ) {
			return implode( ',', array_column(
				self::getErrorFormatter()->arrayFromStatus( $e->getStatusValue() ), 'code'
			) );
		}
		$this->fail( 'Expected an error for ' . json_encode( $params ) );
	}

	private function setLegacyId( PostRecord $post, string $hex ): void {
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'glowthread_post' )
			->set( [ 'gtp_legacy_id' => $hex ] )
			->where( [ 'gtp_id' => $post->getId() ] )
			->caller( __METHOD__ )
			->execute();
	}

	public function testListShape() {
		$page = $this->gtPage();
		$alice = $this->gtUser();
		$bob = $this->gtUser();
		$old = $this->gtPost( $alice, $page, 'Old top' );
		$this->setLegacyId( $old, '0605b2a3c4d5e6f7a8b9c0' );
		$reply = $this->gtPost( $bob, $page, 'Reply', $old );
		$nested = $this->gtPost( $alice, $page, 'Nested', $reply );
		$deeper = $this->gtPost( $bob, $page, 'Deeper', $nested );
		$new = $this->gtPost( $bob, $page, 'New top' );
		$gone = $this->gtPost( $bob, $page, 'Gone' );
		$underGone = $this->gtPost( $alice, $page, 'Under gone', $gone );
		$this->gtModerate( 'delete', [ $gone ] );
		$this->gtService( 'ReactionService' )->setLike( $alice, $new->getId(), true );

		$result = $this->call( [ 'type' => 'list', 'pageid' => $page->getId() ], $alice );
		$this->assertSame( 2, $result['count'], 'visible top-level comments' );
		$ids = array_column( $result['posts'], 'id' );
		// Top-level comments newest first, then replies with each parent before its children
		$this->assertSame( [
			(string)$new->getId(),
			'0605b2a3c4d5e6f7a8b9c0',
			(string)$reply->getId(),
			(string)$nested->getId(),
			(string)$deeper->getId(),
		], $ids );
		$this->assertNotContains( (string)$underGone->getId(), $ids );

		$byId = array_column( $result['posts'], null, 'id' );
		foreach ( $result['posts'] as $item ) {
			$this->assertSame( self::POST_KEYS, array_keys( $item ) );
		}
		$this->assertSame( '', $byId['0605b2a3c4d5e6f7a8b9c0']['parentid'] );
		$this->assertSame( '0605b2a3c4d5e6f7a8b9c0', $byId[(string)$reply->getId()]['parentid'] );
		$this->assertSame( (string)$reply->getId(), $byId[(string)$nested->getId()]['parentid'] );
		// Depth 3 is shown under the depth-1 comment, as in GlowThread
		$this->assertSame( (string)$reply->getId(), $byId[(string)$deeper->getId()]['parentid'] );
		$newItem = $byId[(string)$new->getId()];
		$this->assertSame( $bob->getId(), $newItem['userid'] );
		$this->assertSame( $bob->getName(), $newItem['username'] );
		$this->assertSame( 'New top', $newItem['text'] );
		$this->assertSame( 1, $newItem['like'] );
		$this->assertSame( 1, $newItem['myatt'], 'Alice liked it' );
		$this->assertSame( (int)wfTimestamp( TS_UNIX, $new->getCreated() ), $newItem['timestamp'] );
		$this->assertSame( [], $result['popular'], 'one like is below the popular threshold' );
	}

	public function testPagingAndPopular() {
		$this->overrideConfigValue( 'GlowThreadPopularMinLikes', 1 );
		$page = $this->gtPage();
		$posts = [];
		for ( $i = 1; $i <= 3; $i++ ) {
			$posts[] = $this->gtPost( $this->gtUser(), $page, "Comment $i" );
		}
		$this->gtService( 'ReactionService' )->setLike( $this->gtUser(), $posts[0]->getId(), true );

		$first = $this->call( [ 'type' => 'list', 'pageid' => $page->getId(), 'limit' => 2 ] );
		$this->assertSame( [ "Comment 3", "Comment 2" ], array_column( $first['posts'], 'text' ) );
		$this->assertSame( 3, $first['count'] );
		$this->assertSame( [ (string)$posts[0]->getId() ], array_column( $first['popular'], 'id' ) );
		$second = $this->call( [ 'type' => 'list', 'pageid' => $page->getId(), 'limit' => 2, 'offset' => 2 ] );
		$this->assertSame( [ "Comment 1" ], array_column( $second['posts'], 'text' ) );
		$max = $this->call( [ 'type' => 'list', 'pageid' => $page->getId(), 'limit' => 'max' ] );
		$this->assertCount( 3, $max['posts'] );
	}

	public function testTextIsEscapedHtml() {
		$page = $this->gtPage();
		$mentioned = $this->gtUser();
		$this->gtPost( $this->gtUser(), $page,
			"<script>alert(1)</script> <img src=x onerror=alert(2)> & \"q\"\nhttps://example.com/a?b=1&c=<d> "
				. TextProcessor::mentionToken( $mentioned->getId() )
		);
		$text = $this->call( [ 'type' => 'list', 'pageid' => $page->getId() ] )['posts'][0]['text'];
		$this->assertStringNotContainsString( '<script', $text );
		$this->assertStringNotContainsString( '<img', $text );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $text );
		$this->assertStringContainsString( '&amp; &quot;q&quot;<br>', $text );
		$this->assertStringContainsString(
			'<a href="https://example.com/a?b=1&amp;c=" class="external" rel="nofollow ugc noopener" target="_blank">',
			$text
		);
		$this->assertStringContainsString( '>@' . $mentioned->getName() . '</a>', $text );
		// Only links GlowThread made, nothing else is markup
		$this->assertSame( 2, substr_count( $text, '<a ' ) );
		$this->assertSame( 1, substr_count( $text, '<br>' ) );
	}

	public function testHiddenAndHeldAuthors() {
		$page = $this->gtPage();
		$this->gtSetFilter( 'holdword' );
		$author = $this->gtUser();
		$this->gtPost( $author, $page, 'holdword' );
		$result = $this->call( [ 'type' => 'list', 'pageid' => $page->getId() ], $author );
		$this->assertSame( [], $result['posts'], 'held comments are not listed, not even to their author' );

		$ipPost = $this->gtPost( $this->gtUser(), $page, 'By an IP' );
		// Legacy anonymous comments belong to IP actors, which need temporary accounts off
		$tempConfig = $this->getServiceContainer()->getMainConfig()->get( MainConfigNames::AutoCreateTempUser );
		$this->overrideConfigValue(
			MainConfigNames::AutoCreateTempUser, [ 'enabled' => false, 'known' => false ] + $tempConfig
		);
		$ip = new UserIdentityValue( 0, '203.0.113.7' );
		$ipActor = $this->getServiceContainer()->getActorNormalization()->acquireActorId( $ip, $this->getDb() );
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'glowthread_post' )
			->set( [ 'gtp_actor' => $ipActor ] )
			->where( [ 'gtp_id' => $ipPost->getId() ] )
			->caller( __METHOD__ )
			->execute();
		$item = $this->call( [ 'type' => 'list', 'pageid' => $page->getId() ] )['posts'][0];
		$this->assertSame( 0, $item['userid'] );
		$this->assertSame( '203.0.113.7', $item['username'] );
	}

	public function testHiddenUsers() {
		$this->setGroupPermissions( 'sysop', 'hideuser', true );
		$this->setGroupPermissions( 'gtmod', 'glowthread-moderate', true );
		$page = $this->gtPage();
		$hidden = $this->gtUser();
		$post = $this->gtPost( $hidden, $page, 'Soon hidden' );
		$erased = $this->gtPost( $hidden, $page, 'Soon erased' );
		$this->gtModerate( 'delete', [ $erased ] );
		$this->gtModerate( 'erase', [ $erased ] );
		$this->assertStatusGood( $this->getServiceContainer()->getBlockUserFactory()->newBlockUser(
			$hidden, $this->getTestSysop()->getAuthority(), 'infinity', 'hide', [ 'isHideUser' => true ]
		)->placeBlock() );

		$item = $this->call( [ 'type' => 'list', 'pageid' => $page->getId() ] )['posts'][0];
		$this->assertSame( (string)$post->getId(), $item['id'] );
		$this->assertSame( 0, $item['userid'] );
		$this->assertSame( wfMessage( 'rev-deleted-user' )->inLanguage( 'en' )->text(), $item['username'] );
		$this->assertStringNotContainsString( $hidden->getName(), json_encode( $item, JSON_UNESCAPED_UNICODE ) );

		// A moderator without hideuser cannot find the hidden user's comments
		$mod = $this->gtUser( [ 'gtmod' ] );
		$this->assertSame( [], $this->call(
			[ 'type' => 'listall', 'filter' => 'all', 'user' => $hidden->getName() ], $mod
		)['posts'] );
		// With hideuser and glowthread-erase, both comments, the erased one included
		$this->assertSame(
			[ (string)$erased->getId(), (string)$post->getId() ],
			array_column( $this->call(
				[ 'type' => 'listall', 'filter' => 'all', 'user' => $hidden->getName() ],
				$this->getTestSysop()->getUser()
			)['posts'], 'id' )
		);
	}

	public function testErasedCommentsAreNotTiedToTheirAuthor() {
		$this->setGroupPermissions( 'gtmod', 'glowthread-moderate', true );
		$page = $this->gtPage();
		$author = $this->gtUser();
		$kept = $this->gtPost( $author, $page, 'Kept' );
		$erased = $this->gtPost( $author, $page, 'Erased' );
		$this->gtModerate( 'delete', [ $erased ] );
		$this->gtModerate( 'erase', [ $erased ] );

		$params = [ 'type' => 'listall', 'filter' => 'all', 'user' => $author->getName() ];
		$this->assertSame(
			[ (string)$kept->getId() ],
			array_column( $this->call( $params, $this->gtUser( [ 'gtmod' ] ) )['posts'], 'id' ),
			'moderators without glowthread-erase'
		);
		$all = $this->call( [ 'type' => 'listall', 'filter' => 'deleted' ], $this->gtUser( [ 'gtmod' ] ) )['posts'];
		$this->assertSame( [ (string)$erased->getId() ], array_column( $all, 'id' ) );
		$this->assertSame( 0, $all[0]['userid'] );
		$this->assertSame( '', $all[0]['text'] );
	}

	/**
	 * One list page carries at most LegacyListService::MAX_REPLY_TEXT_BYTES of
	 * reply text (and MAX_REPLIES replies), parents before their replies.
	 */
	public function testRepliesAreCappedBySize() {
		$page = $this->gtPage();
		$top = $this->gtPost( $this->gtUser(), $page, 'top' );
		$dbw = $this->getDb();
		$actor = $this->getServiceContainer()->getActorNormalization()->acquireActorId( $this->gtUser(), $dbw );
		// i18n: multibyte text (a CJK character is three bytes) counts by bytes here
		$text = str_repeat( '长', 3000 );
		$count = 100;
		for ( $i = 0; $i < $count; $i++ ) {
			$this->gtService( 'PostStore' )->insert( $dbw, [
				'gtp_page' => $page->getId(), 'gtp_actor' => $actor, 'gtp_parent' => $top->getId(),
				'gtp_thread' => $top->getId(), 'gtp_reply_to' => $top->getId(), 'gtp_depth' => 1,
				'gtp_seq' => 100 + $i, 'gtp_text' => $text,
				'gtp_created' => $dbw->timestamp( 1791000000 + $i ), 'gtp_status' => 'visible',
			] );
		}
		$posts = $this->call( [ 'type' => 'list', 'pageid' => $page->getId() ] )['posts'];
		$replies = count( $posts ) - 1;
		$this->assertGreaterThan( 0, $replies );
		$this->assertLessThan( $count, $replies );
		$this->assertLessThanOrEqual(
			\MediaWiki\Extension\GlowThread\Compat\LegacyListService::MAX_REPLY_TEXT_BYTES,
			$replies * strlen( $text )
		);
	}

	public function testListErrors() {
		$this->assertSame( 'nopageid', $this->errorCode( [ 'type' => 'list' ] ) );
		$this->assertSame( 'nosuchpageid', $this->errorCode( [ 'type' => 'list', 'pageid' => 987654 ] ) );

		$page = $this->gtPage();
		$this->gtPost( $this->gtUser(), $page, 'Secret' );
		$pageId = $page->getId();
		$this->setTemporaryHook( 'getUserPermissionsErrors',
			static function ( $title, $user, $action, &$result ) use ( $pageId ) {
				if ( $action === 'read' && $title->getArticleID() === $pageId ) {
					$result = false;
					return false;
				}
			}
		);
		$this->assertSame(
			'nosuchpageid',
			$this->errorCode( [ 'type' => 'list', 'pageid' => $pageId ] ),
			'unreadable pages are answered like missing ones'
		);
	}

	public function testDisabledPageListsNothing() {
		$page = $this->gtPage();
		$this->gtPost( $this->gtUser(), $page, 'Hidden' );
		$this->gtSetPageState( $page, 'disabled' );
		$result = $this->call( [ 'type' => 'list', 'pageid' => $page->getId() ] );
		$this->assertSame( [ 'posts' => [], 'popular' => [], 'count' => 0 ], $result );
	}

	public static function provideWriteTypes() {
		foreach ( [ 'post', 'like', 'dislike', 'report', 'delete', 'recover', 'markchecked', 'erase' ] as $type ) {
			yield $type => [ $type ];
		}
	}

	/**
	 * @dataProvider provideWriteTypes
	 */
	public function testWriteTypesAreRefused( string $type ) {
		$page = $this->gtPage();
		$post = $this->gtPost( $this->gtUser(), $page, 'Keep me' );
		$params = [ 'type' => $type, 'pageid' => $page->getId(), 'postid' => (string)$post->getId(), 'content' => 'x' ];
		$this->assertSame(
			'glowthread-legacy-readonly',
			$this->errorCode( $params, $this->getTestSysop()->getUser(), true )
		);
		$this->assertSame( 'glowthread-legacy-readonly', $this->errorCode( $params, $this->gtUser() ) );
		$this->assertTrue( $this->gtReload( $post )->isVisible() );
		$this->assertSame( 0, $this->gtReload( $post )->getLikeCount() );
		$this->assertSame( 1, (int)$this->getDb()->newSelectQueryBuilder()
			->select( 'COUNT(*)' )->from( 'glowthread_post' )->caller( __METHOD__ )->fetchField() );
	}

	public function testRefusalNamesGlowThread() {
		try {
			$this->call( [ 'type' => 'post', 'pageid' => 1, 'content' => 'x' ], $this->gtUser(), true );
			$this->fail( 'Expected an error' );
		} catch ( ApiUsageException $e ) {
			$this->assertStringContainsString( 'GlowThread', $e->getMessage() );
		}
	}

	public function testListAllNeedsModerateRight() {
		$this->assertSame( 'permissiondenied', $this->errorCode( [ 'type' => 'listall' ] ) );
		$this->assertSame( 'permissiondenied', $this->errorCode( [ 'type' => 'listall' ], $this->gtUser() ) );
	}

	public function testListAllForModerators() {
		$page = $this->gtPage();
		$other = $this->gtPage( 'GlowThread other page' );
		$author = $this->gtUser();
		$visible = $this->gtPost( $author, $page, 'Visible needle' );
		$deleted = $this->gtPost( $author, $other, 'Deleted' );
		$this->gtModerate( 'delete', [ $deleted ] );
		$this->gtService( 'ReportService' )->report( $this->gtUser(), $visible->getId(), 'spam', '' );
		$mod = $this->getTestSysop()->getUser();

		$all = $this->call( [ 'type' => 'listall', 'filter' => 'all' ], $mod );
		$this->assertFalse( $all['more'] );
		$this->assertSame(
			[ (string)$deleted->getId(), (string)$visible->getId() ],
			array_column( $all['posts'], 'id' )
		);
		$item = $all['posts'][1];
		$this->assertSame(
			array_merge( self::POST_KEYS, [ 'pageid', 'title', 'report', 'status' ] ),
			array_keys( $item )
		);
		$this->assertSame( $page->getId(), $item['pageid'] );
		$this->assertSame( 'GlowThread test page', $item['title'] );
		$this->assertSame( 1, $item['report'] );
		$this->assertSame( 0, $item['status'] );
		$this->assertSame( 1, $all['posts'][0]['status'], 'deleted' );

		$default = $this->call( [ 'type' => 'listall' ], $mod );
		$this->assertSame( [ (string)$visible->getId() ], array_column( $default['posts'], 'id' ) );
		$this->assertSame( [ (string)$deleted->getId() ],
			array_column( $this->call( [ 'type' => 'listall', 'filter' => 'deleted' ], $mod )['posts'], 'id' ) );
		$this->assertSame( [ (string)$visible->getId() ],
			array_column( $this->call( [ 'type' => 'listall', 'filter' => 'reported' ], $mod )['posts'], 'id' ) );
		$this->assertSame( [ (string)$deleted->getId() ], array_column( $this->call(
			[ 'type' => 'listall', 'filter' => 'all', 'title' => 'GlowThread other page' ], $mod
		)['posts'], 'id' ) );
		$this->assertSame( [ (string)$visible->getId() ], array_column( $this->call(
			[ 'type' => 'listall', 'filter' => 'all', 'keyword' => 'needle', 'user' => $author->getName() ], $mod
		)['posts'], 'id' ) );
		$this->assertSame( [], $this->call(
			[ 'type' => 'listall', 'user' => 'No such user anywhere' ], $mod
		)['posts'] );
		$paged = $this->call( [ 'type' => 'listall', 'filter' => 'all', 'limit' => 1, 'dir' => 'newer' ], $mod );
		$this->assertTrue( $paged['more'] );
		$this->assertSame( [ (string)$visible->getId() ], array_column( $paged['posts'], 'id' ) );
	}

	private function moduleClass(): ?string {
		$main = new ApiMain( new FauxRequest( [ 'action' => 'query' ] ) );
		return $main->getModuleManager()->getClassName( 'flowthread' ) ?: null;
	}

	public function testNotAddedWhenAnotherModuleOwnsTheName() {
		$this->assertSame( ApiFlowThreadCompat::class, $this->moduleClass() );
		// FlowThread registers action=flowthread through $wgAPIModules: that one is kept
		$this->overrideConfigValue( MainConfigNames::APIModules, [ 'flowthread' => ApiParamInfo::class ] );
		$this->assertSame( ApiParamInfo::class, $this->moduleClass() );
	}

	public function testSwitchedOff() {
		$this->overrideConfigValue( 'GlowThreadLegacyCompat', false );
		$this->assertSame( 'badvalue', $this->errorCode( [ 'type' => 'list', 'pageid' => 1 ] ) );
	}
}
