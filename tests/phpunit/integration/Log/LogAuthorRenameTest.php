<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Log;

use DatabaseLogEntry;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\GlowThread\Tests\Integration\Api\GlowThreadApiTestCase;
use MediaWiki\Permissions\Authority;
use MediaWiki\RenameUser\RenameuserSQL;
use MediaWiki\User\UserIdentity;

/**
 * GlowThread's log entries name the comment author through the actor ID (the glowthread_author
 * relation), not the name stored in the entry: after a rename they show the current name, and a
 * hideuser block on the renamed account hides it (security review 2026-10-10, I3).
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Log\CommentsLogFormatter
 * @covers \MediaWiki\Extension\GlowThread\Log\LogAuthorLookup
 */
class LogAuthorRenameTest extends GlowThreadApiTestCase {

	private function anon(): Authority {
		return $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' );
	}

	/**
	 * The comment's glowthread/delete entry as list=logevents gives it to a viewer.
	 */
	private function logEvent( int $commentId, Authority $viewer ): array {
		$events = $this->doApiRequest( [
			'action' => 'query', 'list' => 'logevents', 'letype' => 'glowthread',
			'leaction' => 'glowthread/delete', 'leprop' => 'ids|details', 'lelimit' => 50,
		], null, false, $viewer )[0]['query']['logevents'];
		foreach ( $events as $event ) {
			if ( ( $event['params']['comment'] ?? null ) === $commentId ) {
				return $event;
			}
		}
		$this->fail( "No delete entry for comment $commentId" );
	}

	private function text( int $logId, Authority $viewer ): string {
		$formatter = $this->getServiceContainer()->getLogFormatterFactory()
			->newFromEntry( DatabaseLogEntry::newFromId( $logId, $this->getDb() ) );
		$context = new RequestContext();
		$context->setLanguage( 'en' );
		$context->setAuthority( $viewer );
		$formatter->setContext( $context );
		return $formatter->getPlainActionText();
	}

	private function hide( UserIdentity $user ): void {
		$this->assertStatusGood( $this->getServiceContainer()->getBlockUserFactory()->newBlockUser(
			$user,
			$this->getTestUser( [ 'sysop', 'suppress' ] )->getAuthority(),
			'infinity',
			'',
			[ 'isHideUser' => true, 'isAutoblocking' => false ]
		)->placeBlock() );
	}

	public function testRenamedThenHiddenAuthor() {
		$author = $this->gtUser();
		$oldName = $author->getName();
		$post = $this->gtPost( $author, $this->gtPage( 'GlowThread renamed author' ), 'a comment' );
		$this->gtModerate( 'delete', [ $post ] );

		$newName = 'Vanished user ' . mt_rand( 100000, 999999 );
		$rename = new RenameuserSQL(
			$oldName, $newName, $author->getId(), $this->getTestSysop()->getUser(), [ 'reason' => 'vanish' ]
		);
		// renameUser() replaced rename() in 1.44
		$done = method_exists( $rename, 'renameUser' ) ? $rename->renameUser()->isOK() : $rename->rename();
		$this->assertTrue( $done );
		$this->resetServices();

		// The entry names the account as it is called now
		$event = $this->logEvent( $post->getId(), $this->anon() );
		$this->assertSame( $newName, $event['params']['author'] ?? null );
		$text = $this->text( $event['logid'], $this->anon() );
		$this->assertStringContainsString( $newName, $text );
		$this->assertStringNotContainsString( $oldName, $text );

		$renamed = $this->getServiceContainer()->getUserFactory()->newFromName( $newName );
		$this->hide( $renamed );
		$this->resetServices();

		// Hidden from readers, in the API and in the text
		$event = $this->logEvent( $post->getId(), $this->anon() );
		$this->assertArrayNotHasKey( 'author', $event['params'] );
		$text = $this->text( $event['logid'], $this->anon() );
		$this->assertStringContainsString( '(hidden user)', $text );
		$this->assertStringNotContainsString( $oldName, $text );
		$this->assertStringNotContainsString( $newName, $text );

		// Holders of hideuser see the current name
		$suppressor = $this->getTestUser( [ 'sysop', 'suppress' ] )->getAuthority();
		$this->assertStringContainsString( $newName, $this->text( $event['logid'], $suppressor ) );
	}
}
