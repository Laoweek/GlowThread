<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Hooks;

use MediaWiki\Cache\HTMLCacheUpdater;
use MediaWiki\Extension\GlowThread\Cdn\CdnPurger;
use MediaWiki\Extension\GlowThread\Hooks\UserMergeHooks;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Model\PostStatus;
use MediaWiki\Extension\GlowThread\Tests\Integration\GlowThreadTestTrait;
use MediaWiki\Extension\GlowThread\Text\TextProcessor;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;

/**
 * Extension:UserMerge support (security review 2026-10-10, I19). UserMerge is not installed in
 * the test wiki, so these tests call GlowThread's handlers themselves (running the hooks would
 * also run Echo's handlers, which need UserMerge's classes), and move the fields the way
 * UserMerge does inside a transaction: one UPDATE per field, before MergeAccountFromTo runs.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Hooks\UserMergeHooks
 */
class UserMergeHooksTest extends MediaWikiIntegrationTestCase {
	use GlowThreadTestTrait;

	/** @var string[] URLs purged */
	private array $purged = [];

	protected function setUp(): void {
		parent::setUp();
		$this->setUpGlowThread();
		$updater = $this->createMock( HTMLCacheUpdater::class );
		$updater->method( 'purgeUrls' )->willReturnCallback( function ( $urls ) {
			$this->purged = array_merge( $this->purged, (array)$urls );
		} );
		$this->setService( 'GlowThread.CdnPurger', static fn ( $services ) => new CdnPurger(
			$services->get( 'GlowThread.Config' ), $services->getUrlUtils(), $updater,
			$services->getMainWANObjectCache()
		) );
	}

	private function handler(): UserMergeHooks {
		return new UserMergeHooks(
			$this->getServiceContainer()->getConnectionProvider(),
			$this->gtService( 'PageLifecycleService' ),
			$this->gtService( 'PostStore' )
		);
	}

	/**
	 * @return array[] The fields GlowThread adds in UserMergeAccountFields
	 */
	private function fields(): array {
		$fields = [];
		$this->handler()->onUserMergeAccountFields( $fields );
		return $fields;
	}

	private function actorId( User $user ): int {
		return $this->getServiceContainer()->getActorNormalization()->findActorId( $user, $this->getDb() );
	}

	/**
	 * Merge $old into $new as UserMerge does: its fields first, then MergeAccountFromTo.
	 */
	private function merge( User $old, User $new ): void {
		foreach ( $this->fields() as $field ) {
			$this->getDb()->newUpdateQueryBuilder()
				->update( $field[0] )
				->set( [ $field['actorId'] => $this->actorId( $new ) ] )
				->where( [ $field['actorId'] => $this->actorId( $old ) ] )
				->options( $field['options'] ?? [] )
				->caller( __METHOD__ )
				->execute();
		}
		$this->handler()->onMergeAccountFromTo( $old, $new );
	}

	/**
	 * @return int[] Actor IDs of the rows of a table that match the conditions
	 */
	private function actors( string $table, string $column, array $conds ): array {
		return array_map( 'intval', $this->getDb()->newSelectQueryBuilder()
			->select( $column )
			->from( $table )
			->where( $conds )
			->caller( __METHOD__ )
			->fetchFieldValues() );
	}

	/**
	 * @return int[]
	 */
	private function likers( PostRecord $post ): array {
		return $this->actors( 'glowthread_reaction', 'gtr_actor', [ 'gtr_post' => $post->getId() ] );
	}

	public function testHandlersAreRegistered() {
		$extension = json_decode( file_get_contents( __DIR__ . '/../../../../extension.json' ), true );
		$this->assertSame( 'usermerge', $extension['Hooks']['UserMergeAccountFields'] );
		$this->assertSame( 'usermerge', $extension['Hooks']['MergeAccountFromTo'] );
		$this->assertSame( UserMergeHooks::class, $extension['HookHandlers']['usermerge']['class'] );
	}

	/**
	 * Every actor column of the schema moves: the columns in a unique key in MergeAccountFromTo,
	 * the others through UserMerge's fields, in its actor format.
	 */
	public function testEveryActorColumnMoves() {
		$unique = [];
		$other = [];
		foreach ( json_decode( file_get_contents( __DIR__ . '/../../../../sql/tables.json' ), true ) as $table ) {
			$uniqueKeys = [ $table['pk'] ?? [] ];
			foreach ( $table['indexes'] ?? [] as $index ) {
				if ( $index['unique'] ) {
					$uniqueKeys[] = $index['columns'];
				}
			}
			foreach ( $table['columns'] as $column ) {
				$name = $column['name'];
				if ( str_ends_with( $name, '_actor' ) ) {
					$inUniqueKey = (bool)array_filter( $uniqueKeys, static fn ( $key ) => in_array( $name, $key ) );
					if ( $inUniqueKey ) {
						$unique[$table['name']] = $name;
					} else {
						$other[] = $table['name'] . '.' . $name;
					}
				}
			}
		}
		$this->assertEqualsCanonicalizing( $unique, UserMergeHooks::UNIQUE_KEY_COLUMNS );
		$listed = [];
		foreach ( $this->fields() as $field ) {
			// A second positional element would be taken for a user ID column
			$this->assertArrayNotHasKey( 1, $field );
			$this->assertSame( SCHEMA_COMPAT_NEW, $field['actorStage'] );
			$this->assertArrayNotHasKey( 'options', $field );
			$listed[] = $field[0] . '.' . $field['actorId'];
		}
		$this->assertEqualsCanonicalizing( $other, $listed );
		$this->assertSame( 7, count( $unique ) + count( $other ) );
	}

	public function testMergeMovesRowsAndLogRelation() {
		$old = $this->gtUser();
		$new = $this->gtUser();
		$page = $this->gtPage( 'GlowThread user merge' );
		$own = $this->gtPost( $old, $page, 'by the old account' );
		$other = $this->gtPost( $this->gtUser(), $page, 'liked by the old account' );
		$this->assertStatusGood( $this->gtService( 'ReactionService' )->setLike( $old, $other->getId(), true ) );
		$this->gtModerate( 'delete', [ $own ] );
		$newActor = $this->actorId( $new );

		$this->purged = [];
		$this->merge( $old, $new );

		$this->assertSame( $newActor, $this->gtReload( $own )->getActorId(), 'the comment moved' );
		$this->assertSame( [ $newActor ], $this->likers( $other ), 'the like moved' );
		$this->assertSame( [ (string)$newActor ], $this->getDb()->newSelectQueryBuilder()
			->select( 'ls_value' )
			->from( 'log_search' )
			->join( 'logging', null, 'log_id = ls_log_id' )
			->where( [ 'ls_field' => 'glowthread_author', 'log_action' => 'delete', 'log_page' => $page->getId() ] )
			->caller( __METHOD__ )
			->fetchFieldValues(), 'the log names the account that stays' );
		$this->assertSame( $this->gtService( 'CdnPurger' )->getPurgeUrls( $page->getId() ), $this->purged,
			'the cached lists showed the old name' );
	}

	/**
	 * Likes and reports of both accounts on the same comment keep one row, and the comment's
	 * counters match the rows that stay. This is what UPDATE IGNORE could not do, and
	 * PostgreSQL has no UPDATE IGNORE.
	 */
	public function testDuplicatesKeepOneRowAndCountsAreCorrected() {
		$old = $this->gtUser();
		$new = $this->gtUser();
		$author = $this->gtUser();
		$page = $this->gtPage( 'GlowThread user merge duplicates' );
		$post = fn ( string $text ): PostRecord => $this->gtPost( $author, $page, $text );
		$likes = $this->gtService( 'ReactionService' );
		$reports = $this->gtService( 'ReportService' );
		$oldActor = $this->actorId( $old );
		$newActor = $this->actorId( $new );

		// Likes: one comment liked by both, one by the old account only
		$likedByBoth = $post( 'liked by both' );
		$likedByOld = $post( 'liked by the old account' );
		foreach ( [ [ $old, $likedByBoth ], [ $new, $likedByBoth ], [ $old, $likedByOld ] ] as [ $user, $comment ] ) {
			$this->assertStatusGood( $likes->setLike( $user, $comment->getId(), true ) );
		}
		$this->assertSame( 2, $this->gtReload( $likedByBoth )->getLikeCount() );

		// Reports: both open, the old account's newer. Old open, new dismissed. Both dismissed.
		$bothOpen = $post( 'reported by both, both open' );
		$oneOpen = $post( 'reported by both, one open' );
		$noneOpen = $post( 'reported by both, none open' );
		$this->assertStatusGood( $reports->report( $new, $bothOpen->getId(), 'spam', 'new account note' ) );
		$this->assertStatusGood( $reports->report( $old, $bothOpen->getId(), 'spam', 'old account note' ) );
		$this->setReportTime( $bothOpen, $oldActor, '20260102000000' );
		$this->setReportTime( $bothOpen, $newActor, '20260101000000' );
		$this->assertStatusGood( $reports->report( $new, $oneOpen->getId(), 'spam', '' ) );
		$this->gtModerate( 'dismiss', [ $oneOpen ] );
		$this->assertStatusGood( $reports->report( $old, $oneOpen->getId(), 'spam', 'still open' ) );
		$this->assertStatusGood( $reports->report( $old, $noneOpen->getId(), 'spam', '' ) );
		$this->assertStatusGood( $reports->report( $new, $noneOpen->getId(), 'spam', '' ) );
		$this->gtModerate( 'dismiss', [ $noneOpen ] );
		$this->assertSame( 2, $this->gtReload( $bothOpen )->getOpenReportCount() );
		$this->assertSame( 1, $this->gtReload( $oneOpen )->getOpenReportCount() );

		$this->merge( $old, $new );

		$this->assertSame( [ $newActor ], $this->likers( $likedByBoth ) );
		$this->assertSame( 1, $this->gtReload( $likedByBoth )->getLikeCount() );
		$this->assertSame( [ $newActor ], $this->likers( $likedByOld ) );
		$this->assertSame( 1, $this->gtReload( $likedByOld )->getLikeCount() );

		$this->assertSame( [ 'old account note' ], $this->reportNotes( $bothOpen ), 'the newer open report stays' );
		$this->assertSame( 1, $this->gtReload( $bothOpen )->getOpenReportCount() );
		$this->assertSame( [ 'still open' ], $this->reportNotes( $oneOpen ), 'the open report stays' );
		$this->assertSame( 1, $this->gtReload( $oneOpen )->getOpenReportCount() );
		$this->assertCount( 1, $this->reportNotes( $noneOpen ) );
		$this->assertSame( 0, $this->gtReload( $noneOpen )->getOpenReportCount() );
		$this->assertSame( [], $this->actors( 'glowthread_report', 'gtrp_actor', [ 'gtrp_actor' => $oldActor ] ) );
	}

	/**
	 * Mentions name users by ID (<@ID>). Those of the old account are rewritten, and nothing
	 * else in the text changes. Erased comments are left alone.
	 */
	public function testMentionsOfTheOldAccountAreRewritten() {
		$old = $this->gtUser();
		$new = $this->gtUser();
		$page = $this->gtPage( 'GlowThread user merge mentions' );
		$oldToken = TextProcessor::mentionToken( $old->getId() );
		$newToken = TextProcessor::mentionToken( $new->getId() );
		$texts = [
			"Hi $oldToken and $oldToken!" => "Hi $newToken and $newToken!",
			"<@{$old->getId()}0> <@1{$old->getId()}> @{$old->getName()}"
				=> "<@{$old->getId()}0> <@1{$old->getId()}> @{$old->getName()}",
			"{$oldToken}{$oldToken}." => "{$newToken}{$newToken}.",
		];
		$posts = [];
		foreach ( $texts as $text => $expected ) {
			$posts[] = [ $this->setText( $this->gtPost( $this->gtUser(), $page, 'x' ), $text ), $expected ];
		}
		$erased = $this->setText( $this->gtPost( $this->gtUser(), $page, 'x' ), "erased $oldToken" );
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'glowthread_post' )
			->set( [ 'gtp_status' => PostStatus::ERASED ] )
			->where( [ 'gtp_id' => $erased->getId() ] )
			->caller( __METHOD__ )
			->execute();

		$this->merge( $old, $new );

		foreach ( $posts as [ $post, $expected ] ) {
			$this->assertSame( $expected, $this->gtReload( $post )->getText() );
		}
		$this->assertSame( "erased $oldToken", $this->gtReload( $erased )->getText() );
	}

	private function setText( PostRecord $post, string $text ): PostRecord {
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'glowthread_post' )
			->set( [ 'gtp_text' => $text ] )
			->where( [ 'gtp_id' => $post->getId() ] )
			->caller( __METHOD__ )
			->execute();
		return $post;
	}

	private function setReportTime( PostRecord $post, int $actorId, string $timestamp ): void {
		$db = $this->getDb();
		$db->newUpdateQueryBuilder()
			->update( 'glowthread_report' )
			->set( [ 'gtrp_created' => $db->timestamp( $timestamp ) ] )
			->where( [ 'gtrp_post' => $post->getId(), 'gtrp_actor' => $actorId ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @return string[] Notes of the reports on a comment
	 */
	private function reportNotes( PostRecord $post ): array {
		return array_map( 'strval', $this->getDb()->newSelectQueryBuilder()
			->select( 'gtrp_note' )
			->from( 'glowthread_report' )
			->where( [ 'gtrp_post' => $post->getId() ] )
			->caller( __METHOD__ )
			->fetchFieldValues() );
	}
}
