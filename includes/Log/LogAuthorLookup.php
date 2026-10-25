<?php

namespace MediaWiki\Extension\GlowThread\Log;

use MapCacheLRU;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Store\PostStore;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\ActorStore;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * The lookups CommentsLogFormatter makes for every entry, remembered across the entries of one
 * list: a log page names the same authors and comments again and again, and core builds a new
 * formatter per entry. Answers are reused for a few seconds only, so long-running scripts (the job
 * runner) see a hideuser block soon after it happens elsewhere. In this process, a hideuser block
 * (forgetName()) and an erase (PostStore, and rememberComment() when the erase is logged) take
 * effect at once.
 *
 * Lists that announce their entries first (preload()) get the authors and comments of all
 * entries in one query each, instead of one per entry.
 */
class LogAuthorLookup {

	/** Seconds an answer is reused */
	private const TTL = 5;

	/** Entries or comments read in one query */
	private const BATCH_SIZE = 500;

	/** Announced entries or comments kept at most (the largest Special:Log page) */
	private const MAX_PENDING = 5000;

	private MapCacheLRU $hiddenNames;

	/** @var MapCacheLRU Log ID => UserIdentity, or false when the entry names no actor */
	private MapCacheLRU $entryAuthors;

	/** @var MapCacheLRU Comment ID => whether it is erased, for comments ModerationLogger logged */
	private MapCacheLRU $loggedComments;

	/** @var MapCacheLRU Author name => actor ID, for comments ModerationLogger logged */
	private MapCacheLRU $loggedAuthors;

	/** @var array<int,true> Log IDs announced by preload() and not read yet, in announcement order */
	private array $pendingEntries = [];

	/** @var array<int,true> Comment IDs announced by preload() and not read yet, in announcement order */
	private array $pendingComments = [];

	public function __construct(
		private readonly UserFactory $userFactory,
		private readonly PostStore $postStore,
		/** The ActorStore service when null */
		private readonly ?ActorStore $actorStore = null
	) {
		$this->hiddenNames = new MapCacheLRU( 1000 );
		$this->entryAuthors = new MapCacheLRU( 1000 );
		$this->loggedComments = new MapCacheLRU( 1000 );
		$this->loggedAuthors = new MapCacheLRU( 1000 );
	}

	/**
	 * Announce the lookups an entry of a list will need, before the list is formatted. Nothing is
	 * read here: a getEntryAuthor() or isErasedComment() call about an announced entry or comment
	 * also reads the ones announced next to it and not read yet, up to BATCH_SIZE in all, on the
	 * side where more are left (pagers format some lists in reverse). Special:Log and
	 * RecentChanges announce every row of a page before they format the first
	 * (CommentsLogFormatter::getPreloadTitles()).
	 *
	 * @param int|null $logId An entry whose author getEntryAuthor() will be asked for
	 * @param int|null $commentId A comment isErasedComment() may be asked about
	 */
	public function preload( ?int $logId, ?int $commentId ): void {
		if ( $logId !== null && count( $this->pendingEntries ) < self::MAX_PENDING ) {
			$this->pendingEntries[$logId] = true;
		}
		if ( $commentId !== null && count( $this->pendingComments ) < self::MAX_PENDING ) {
			$this->pendingComments[$commentId] = true;
		}
	}

	/**
	 * The comment author a glowthread or glowthread-private entry names, found through its
	 * glowthread_author relation (the author's actor ID), under the name the author has now.
	 * The entry's 5:user-link:author keeps the name of the time of the action, which a rename
	 * does not change. Null when the entry has no such relation or its actor is gone.
	 */
	public function getEntryAuthor( int $logId ): ?UserIdentity {
		$author = $this->entryAuthors->get( $logId, self::TTL );
		if ( $author === null ) {
			$logIds = isset( $this->pendingEntries[$logId] )
				? self::takeBatch( $this->pendingEntries, $logId )
				: [ $logId ];
			$db = $this->postStore->getReplica();
			$res = $db->newSelectQueryBuilder()
				->select( [ 'ls_log_id', 'actor_user', 'actor_name' ] )
				->from( 'log_search' )
				// ls_value is a string column: PostgreSQL compares it with an integer only after a cast
				->join( 'actor', null, 'actor_id = ' . $db->buildIntegerCast( 'ls_value' ) )
				->where( [ 'ls_log_id' => $logIds, 'ls_field' => 'glowthread_author' ] )
				->caller( __METHOD__ )
				->fetchResultSet();
			$found = [];
			foreach ( $res as $row ) {
				$found[(int)$row->ls_log_id] = new UserIdentityValue( (int)$row->actor_user, (string)$row->actor_name );
			}
			foreach ( $logIds as $id ) {
				$this->entryAuthors->set( $id, $found[$id] ?? false );
			}
			$author = $found[$logId] ?? false;
		}
		return $author ?: null;
	}

	/**
	 * Whether a user name is hidden by a hideuser block. IP addresses and invalid names are not.
	 */
	public function isHiddenName( string $name ): bool {
		return $this->isHidden( $name, null );
	}

	/**
	 * isHiddenName() for the account getEntryAuthor() gave, which is not looked up by name again.
	 */
	public function isHiddenUser( UserIdentity $user ): bool {
		return $this->isHidden( $user->getName(), $user );
	}

	/**
	 * Whether User::newFromName() accepts the name as it is: a valid user name (not an IP
	 * address) in its canonical form. Reads nothing.
	 */
	public function isCanonicalName( string $name ): bool {
		return $this->userFactory->newFromName( $name )?->getName() === $name;
	}

	/**
	 * Forget whether a name is hidden, after a hideuser block was placed, changed or lifted.
	 */
	public function forgetName( string $name ): void {
		$this->hiddenNames->clear( $name );
	}

	/**
	 * Remember a comment as ModerationLogger logs an action on it, so that publishing the entry
	 * (core formats it for RecentChanges and the feeds) does not read the comment again, nor look
	 * up its author by name. Erasing is always logged with the erased comment, so an erase in this
	 * process counts at once.
	 *
	 * @param PostRecord $post
	 * @param string|null $authorName The name the entry gives the comment's author
	 */
	public function rememberComment( PostRecord $post, ?string $authorName = null ): void {
		$this->loggedComments->set( $post->getId(), $post->isErased() );
		if ( $authorName !== null ) {
			$this->loggedAuthors->set( $authorName, $post->getActorId() );
		}
	}

	public function isErasedComment( int $id ): bool {
		$erased = $this->loggedComments->get( $id, self::TTL );
		if ( $erased !== null ) {
			return $erased;
		}
		$ids = isset( $this->pendingComments[$id] ) ? self::takeBatch( $this->pendingComments, $id ) : [ $id ];
		$post = $this->postStore->getByIdsForDisplay( $ids )[$id] ?? null;
		return $post !== null && $post->isErased();
	}

	private function isHidden( string $name, ?UserIdentity $identity ): bool {
		$hidden = $this->hiddenNames->get( $name, self::TTL );
		if ( $hidden === null ) {
			$identity ??= $this->getLoggedAuthor( $name );
			$user = $this->userFactory->newFromName( $name );
			// The account the name finds: no need to look it up again
			if ( $user && $identity && $identity->isRegistered() && $identity->getName() === $user->getName() ) {
				$user = $this->userFactory->newFromUserIdentity( $identity );
			}
			$hidden = $user !== null && self::hasHiddenNameBlock( $user );
			$this->hiddenNames->set( $name, $hidden );
		}
		return $hidden;
	}

	/**
	 * The account of a comment author ModerationLogger logged under this name. ActorStore has it
	 * already: the logger's callers read it by actor ID.
	 */
	private function getLoggedAuthor( string $name ): ?UserIdentity {
		$actorId = $this->loggedAuthors->get( $name, self::TTL );
		return $actorId
			? ( $this->actorStore ?? MediaWikiServices::getInstance()->getActorStore() )
				->getActorById( $actorId, $this->postStore->getReplica() )
			: null;
	}

	/**
	 * User::isHidden(), without loading the rights of users other than the request's own.
	 * User::getBlock() reads the ipblock-exempt right only to decide whether to check the
	 * request's IP address and block cookie as well, which it does only for the request's user.
	 */
	private static function hasHiddenNameBlock( User $user ): bool {
		$sessionUser = RequestContext::getMain()->getUser();
		if ( !$sessionUser->isSafeToLoad() || $sessionUser->getName() === $user->getName() ) {
			return $user->isHidden();
		}
		return (bool)$user->getBlock( IDBAccessObject::READ_NORMAL, true )?->getHideName();
	}

	/**
	 * $id, a pending ID, and the pending IDs announced right after it, or right before it when
	 * fewer are left after it, up to BATCH_SIZE in all. They are no longer pending.
	 *
	 * @param array<int,true> &$pending
	 * @param int $id
	 * @return int[]
	 */
	private static function takeBatch( array &$pending, int $id ): array {
		$keys = array_keys( $pending );
		$at = array_search( $id, $keys, true );
		$start = count( $keys ) - $at > $at ? $at : max( 0, $at - self::BATCH_SIZE + 1 );
		$ids = array_slice( $keys, $start, self::BATCH_SIZE );
		foreach ( $ids as $taken ) {
			unset( $pending[$taken] );
		}
		return $ids;
	}
}
