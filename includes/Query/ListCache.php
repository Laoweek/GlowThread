<?php

namespace MediaWiki\Extension\GlowThread\Query;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\GlowThread\GlowThreadConfig;
use MediaWiki\Extension\GlowThread\Store\CacheSetOptions;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\ChronologyProtector;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Object cache of the comment lists, in the WAN object cache:
 * - the anonymous first page of each sort, as the JSON body the list route sends;
 * - a summary of each page that does not depend on the viewer (CommentQueryService), which
 *   logged-in readers and later pages use.
 *
 * Both depend on check keys: one per page, touched by every write that changes what readers see
 * on it (CdnPurger::purgePage(), and likes), and one for all pages, touched by
 * changes that can reach any page (names hidden or renamed, merged accounts, settings, imports).
 * A touch takes effect when the main transaction commits. A copy computed from the data before
 * the commit is stored within MAX_LAG seconds of it or not at all, so inside the hold-off of the
 * touch, where it counts as out of date. Everything expires within an hour, which bounds changes
 * made around the hooks (direct database edits).
 *
 * Every answer from the cache checks the users the response depends on, in one query: those it
 * names still have those names and are not hidden, the hidden authors are still hidden, and the
 * user IDs of mentions that named nobody still name nobody. So a block that core writes without
 * a hook (CentralAuth's global suppression), its removal, or an account created for a mentioned
 * ID, shows at once.
 *
 * Nothing waits for a lock: a reader never gets a copy from before a purge, so a CDN that
 * fetches the list again after its own purge always stores the new one. A client that wrote in
 * the last seconds is not answered from the cache at all (mayUse()).
 *
 * A miss costs no more than reading the list from the database, and a list that the cache
 * cannot answer for a while costs no cache round trips at all after the first one. Each web
 * server keeps notes in its own cache (APCu when installed), each for a bounded time, that only
 * ever send a request to the database as before:
 * - the hold-off of a purge it saw (WANObjectCache::HOLDOFF_TTL): nothing in the cache is up to
 *   date then, so it reads nothing and writes nothing;
 * - a page whose summary readers who cannot make one (anonymous ones) found missing;
 * - a page whose summary could not be stored (replica lag): nobody reads or makes it;
 * - an anonymous first page that cannot be stored (its order holds for that time only).
 * An anonymous miss reads the summary only past the hold-off, and the summary is made after the
 * response is sent, for logged-in readers only.
 */
class ListCache {

	/** Format of the cached values. Bump it when a change alters list responses or summaries. */
	public const VERSION = 3;

	/** Seconds a cached response or summary lives at most */
	public const TTL = WANObjectCache::TTL_HOUR;

	/**
	 * Seconds that the data of a stored value may be behind the primary database, as replication
	 * lag plus the age of the read (WANObjectCache's MAX_READ_LAG). Kept below
	 * WANObjectCache::HOLDOFF_TTL: a value stored after the hold-off of a purge then has the
	 * write that the purge was for.
	 */
	private const MAX_LAG = 7;

	/** Seconds a web server's notes last at most (besides the hold-off), see the class comment */
	private const NOTE_TTL = 60;

	/**
	 * @var array{0:int,1:?array}|null While an anonymous response is computed: its page and the
	 *   summary found up to date with it (getSummary() then returns that, without reading or
	 *   making one)
	 */
	private ?array $anonymous = null;

	/** @var array<int,true> Pages whose summary this request makes after the response */
	private array $building = [];

	/**
	 * @param WANObjectCache $wanCache
	 * @param IConnectionProvider $dbProvider
	 * @param GlowThreadConfig|null $config Null when only touching keys
	 * @param ChronologyProtector|null $chronologyProtector
	 * @param ILoadBalancer|null $loadBalancer The main load balancer, for $chronologyProtector
	 * @param BagOStuff|null $srvCache The web server's own cache, which remembers the hold-off of
	 *   the purges it saw
	 */
	public function __construct(
		private readonly WANObjectCache $wanCache,
		private readonly IConnectionProvider $dbProvider,
		private readonly ?GlowThreadConfig $config = null,
		private readonly ?ChronologyProtector $chronologyProtector = null,
		private readonly ?ILoadBalancer $loadBalancer = null,
		private readonly ?BagOStuff $srvCache = null
	) {
	}

	/**
	 * Key of the summary of a page, with the configuration it depends on
	 */
	private function getSummaryKey( int $pageId ): string {
		return $this->wanCache->makeKey( 'glowthread-list-summary', self::VERSION, $pageId, md5( serialize( [
			$this->config->getHotHalfLife(),
			$this->config->getPopularCount(),
			$this->config->getPopularMinLikes(),
			$this->config->getRaw( 'CacheEpoch' ),
		] ) ) );
	}

	/**
	 * Read values of a page in one cache read, with its check keys. Nothing during the hold-off
	 * of a purge that this web server saw: no value is up to date then.
	 *
	 * @param string[] $keys
	 * @param int $pageId
	 * @return array{0:array<string,mixed>,1:bool,2:array<string,bool>} The values that are up to
	 *   date, by key; whether a value stored now would be up to date: no purge of the page in the
	 *   hold-off (this only saves useless reads and writes: the cache checks every value read
	 *   against the purges anyway); and the keys without any value, up to date or not
	 */
	private function read( array $keys, int $pageId ): array {
		$now = ConvertibleTimestamp::time();
		$holdOffKey = $this->noteKey( 'holdoff', $pageId );
		if ( $this->noted( $holdOffKey ) ) {
			return [ [], false, [] ];
		}
		$info = WANObjectCache::PASS_BY_REF;
		$values = $this->wanCache->getMulti(
			$keys, $curTTLs, [ $this->getPageKey( $pageId ), $this->getGlobalKey() ], $info
		);
		$fresh = [];
		$absent = [];
		$lastPurge = 0;
		foreach ( $keys as $key ) {
			if ( isset( $values[$key] ) && ( $curTTLs[$key] ?? 0 ) > 0 ) {
				$fresh[$key] = $values[$key];
			}
			if ( !isset( $values[$key] ) ) {
				$absent[$key] = true;
			}
			$lastPurge = max( $lastPurge, $info[$key][WANObjectCache::KEY_CHECK_AS_OF] ?? 0 );
		}
		// A check key that this read made (there was none) counts as purged now too: nothing is
		// stored for the first seconds after that, as after a purge
		$holdOffEnd = $lastPurge + WANObjectCache::HOLDOFF_TTL;
		if ( $now <= $holdOffEnd ) {
			// No longer than a hold-off from now, whatever the clock of the server that purged
			$this->note( $holdOffKey, min( $holdOffEnd, $now + WANObjectCache::HOLDOFF_TTL ) );
		}
		return [ $fresh, $now > $holdOffEnd, $absent ];
	}

	/**
	 * Key of a note of this web server (see the class comment), null without a server cache
	 *
	 * @param string $kind
	 * @param int|string $id
	 * @return string|null
	 */
	private function noteKey( string $kind, $id ): ?string {
		return $this->srvCache ? $this->srvCache->makeKey( 'glowthread-list-note', $kind, $id ) : null;
	}

	/**
	 * Whether a note says that reading is useless until a later time
	 */
	private function noted( ?string $key ): bool {
		return $key !== null && ConvertibleTimestamp::time() < (float)$this->srvCache->get( $key );
	}

	/**
	 * Note that reading is useless until $until, a Unix time
	 */
	private function note( ?string $key, float $until ): void {
		$ttl = (int)ceil( $until - ConvertibleTimestamp::time() );
		if ( $key !== null && $ttl > 0 ) {
			$this->srvCache->set( $key, $until, $ttl + 1 );
		}
	}

	/**
	 * Store a value after the response is sent. Its set options come from before its reads, so
	 * the cache knows how old the data may be. Not when it may be older than MAX_LAG, which the
	 * cache would store for a short time instead: then it is not even made, and $failNote notes
	 * that reading it is useless for a while.
	 *
	 * @param string $key
	 * @param callable $make Returns the value, after the response
	 * @param int $ttl
	 * @param array|null $setOpts From CacheSetOptions, null for reads that $make makes
	 * @param bool $creating Whether the cache had no value at all: added in one write, without
	 *   reading the old one as replacing it takes
	 * @param string|null $failNote
	 * @param string|null $clearNote A note that the stored value ends
	 */
	private function store(
		string $key, callable $make, int $ttl, ?array $setOpts, bool $creating, ?string $failNote,
		?string $clearNote = null
	): void {
		DeferredUpdates::addCallableUpdate(
			function () use ( $key, $make, $ttl, $setOpts, $creating, $failNote, $clearNote ) {
				$setOpts ??= CacheSetOptions::forRead( $this->dbProvider->getReplicaDatabase() );
				$recent = static function () use ( $setOpts ): bool {
					$lag = $setOpts['lag'] ?? 0;
					$age = microtime( true ) - ( $setOpts['since'] ?? microtime( true ) );
					return $lag !== false && $lag + $age <= self::MAX_LAG;
				};
				if ( !$recent() ) {
					$this->note( $failNote, ConvertibleTimestamp::time() + self::NOTE_TTL );
					return;
				}
				$value = $make();
				if ( !$recent() ) {
					$this->note( $failNote, ConvertibleTimestamp::time() + self::NOTE_TTL );
					return;
				}
				$this->wanCache->set( $key, $value, $ttl, [ 'creating' => $creating ] + $setOpts );
				if ( $clearNote !== null ) {
					$this->srvCache->delete( $clearNote );
				}
			},
			DeferredUpdates::POSTSEND
		);
	}

	private function getPageKey( int $pageId ): string {
		return $this->wanCache->makeKey( 'glowthread-list-epoch', $pageId );
	}

	private function getGlobalKey(): string {
		return $this->wanCache->makeKey( 'glowthread-list-epoch-all' );
	}

	/**
	 * The lists of a page changed.
	 */
	public function touchPage( int $pageId ): void {
		$this->touch( $this->getPageKey( $pageId ) );
	}

	/**
	 * Something that any page may show changed.
	 */
	public function touchAll(): void {
		$this->touch( $this->getGlobalKey() );
	}

	/**
	 * Touch a check key once the main transaction commits. A copy of the data from before the
	 * commit is stored within MAX_LAG seconds of the commit or not at all (store()), so within the
	 * hold-off of the touch, where it counts as out of date.
	 */
	private function touch( string $key ): void {
		$wanCache = $this->wanCache;
		$this->dbProvider->getPrimaryDatabase()->onTransactionCommitOrIdle(
			static function () use ( $wanCache, $key ) {
				$wanCache->touchCheckKey( $key );
			},
			__METHOD__
		);
	}

	/**
	 * Whether the cache may answer this request: not when this client wrote in the last seconds
	 * (ChronologyProtector has its replication positions). Such a request reads a replica that
	 * waited for those writes, while a copy in the cache may come from a replica that had not got
	 * them, so the list is read from the database as it always was.
	 */
	public function mayUse(): bool {
		if ( !$this->chronologyProtector || !$this->loadBalancer ) {
			return true;
		}
		// MediaWiki 1.43 takes the load balancer, later versions take no argument
		return !$this->chronologyProtector->getTouched( $this->loadBalancer );
	}

	/**
	 * The anonymous first page of a list, computed by $compute when not cached.
	 *
	 * @param int $pageId
	 * @param string $sort
	 * @param array $variant Everything else the response depends on (limit, rights, title,
	 *   configuration...), hashed into the key
	 * @param callable $compute Called with whether the list may be stored: returns [ response
	 *   data, facts ], CommentQueryService::getPageList() and the facts it reported, which it
	 *   needs to collect only for a list that may be stored (else null)
	 * @param callable $encode Turns the data into the JSON body
	 * @param bool $showsHiddenUsers Whether these readers see the names of hidden users
	 *   (hideuser)
	 * @return array{body:string,next:?string,cached:bool} next: the hot cursor in the body;
	 *   cached: whether the body comes from the cache, then computed for an earlier time, whose
	 *   cursor ListHandler gives the time of the request
	 */
	public function getAnonResponse(
		int $pageId,
		string $sort,
		array $variant,
		callable $compute,
		callable $encode,
		bool $showsHiddenUsers
	): array {
		$key = $this->wanCache->makeKey(
			'glowthread-list', self::VERSION, $pageId, $sort, md5( serialize( $variant ) )
		);
		$unstorable = $this->noteKey( 'unstorable', md5( $key ) );
		$writable = false;
		$absent = [];
		if ( !$this->noted( $unstorable ) ) {
			[ $fresh, $writable, $absent ] = $this->read( [ $key ], $pageId );
			if ( isset( $fresh[$key] ) && $this->usersHold( $fresh[$key]['users'], $showsHiddenUsers ) ) {
				return [ 'cached' => true ] + $fresh[$key];
			}
		}
		// Computed from the database as before, or from a summary already up to date: making one
		// here would cost more than the list. During the hold-off nothing is up to date, so the
		// summary, large on a large page, is not even read then. The set options only for a list
		// that will be stored: they may cost a replication lag query.
		$summary = $writable ? $this->readSummary( $pageId, false, null ) : null;
		$setOpts = $writable ? CacheSetOptions::forRead( $this->dbProvider->getReplicaDatabase() ) : null;
		$this->anonymous = [ $pageId, $summary ];
		try {
			[ $data, $facts ] = $compute( $writable );
		} finally {
			$this->anonymous = null;
		}
		$value = [
			'body' => $encode( $data ),
			'next' => $sort === CommentQueryService::SORT_HOT ? $data['next'] : null,
		];
		if ( $writable ) {
			// Kept only while its order comes out the same at any later time (HotRanker): not while
			// a comment is dated after now, not past the time when the score of the oldest comment
			// ranked loses precision, and not with a tie in it, which holds for this time only
			$now = ConvertibleTimestamp::time();
			if ( $facts['from'] <= $now && $facts['until'] > $now ) {
				$stored = $value + [ 'users' => [
					'named' => self::getNamedUsers( $data ),
					'hidden' => $facts['hidden'],
					'missing' => $facts['missing'],
				] ];
				$ttl = min( self::TTL, $facts['until'] - $now );
				$this->store( $key, static fn () => $stored, $ttl, $setOpts, isset( $absent[$key] ), $unstorable );
			} else {
				$this->note( $unstorable, min(
					$now + self::NOTE_TTL,
					$facts['from'] > $now ? $facts['from'] : PHP_INT_MAX
				) );
			}
		}
		return [ 'cached' => false ] + $value;
	}

	/**
	 * The registered users a list response names, as authors, as authors replied to and in
	 * mentions.
	 *
	 * @param array $data From CommentQueryService::getPageList()
	 * @return array<int,string> User ID => name shown
	 */
	private static function getNamedUsers( array $data ): array {
		$users = [];
		$visit = static function ( array $comment ) use ( &$users, &$visit ): void {
			foreach ( [ $comment['author'] ?? null, $comment['replyTo']['author'] ?? null ] as $author ) {
				if ( $author && !$author['hidden'] && $author['id'] ) {
					$users[$author['id']] = $author['name'];
				}
			}
			foreach ( $comment['segments'] ?? [] as $segment ) {
				if ( $segment['type'] === 'mention' && isset( $segment['id'] ) ) {
					$users[$segment['id']] = $segment['user'];
				}
			}
			foreach ( $comment['replies'] ?? [] as $reply ) {
				$visit( $reply );
			}
		};
		foreach ( [ 'pinned', 'comments', 'popular' ] as $list ) {
			foreach ( $data[$list] ?? [] as $comment ) {
				$visit( $comment );
			}
		}
		return $users;
	}

	/**
	 * Whether the users a cached response depends on are as they were, in one query, read as
	 * the response would read them now:
	 * - named (user ID => name): still there under that name, and not hidden (unless these
	 *   readers see hidden users);
	 * - hidden (user IDs of authors shown as hidden): still hidden;
	 * - missing (user IDs of mentions that named nobody): still no account, or a hidden one.
	 *
	 * @param array{named:array<int,string>,hidden?:int[],missing?:int[]} $users
	 * @param bool $showsHiddenUsers
	 */
	private function usersHold( array $users, bool $showsHiddenUsers ): bool {
		$named = $users['named'];
		$hidden = $showsHiddenUsers ? [] : array_flip( $users['hidden'] ?? [] );
		$missing = array_flip( $users['missing'] ?? [] );
		$ids = array_keys( $named + $hidden + $missing );
		if ( !$ids ) {
			return true;
		}
		$db = $this->dbProvider->getReplicaDatabase();
		// As HideUserUtils: a block with bl_deleted on the account. With LIMIT 1, as later versions
		// of MediaWiki have it: an account can have more than one such block.
		$hiddenSql = $db->newSelectQueryBuilder()
			->select( '1' )
			->from( 'block_target', 'gt_hu_target' )
			->join( 'block', 'gt_hu_block', 'gt_hu_block.bl_target=gt_hu_target.bt_id' )
			->where( [ 'gt_hu_target.bt_user=user_id', 'gt_hu_block.bl_deleted' => 1 ] )
			->limit( 1 )
			->caller( __METHOD__ )
			->getSQL();
		$rows = $db->newSelectQueryBuilder()
			->select( [ 'user_id', 'user_name', 'gt_hidden' => "CASE WHEN ($hiddenSql) IS NULL THEN 0 ELSE 1 END" ] )
			->from( 'user' )
			->where( [ 'user_id' => $ids ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$found = [];
		foreach ( $rows as $row ) {
			$found[(int)$row->user_id] = [ (string)$row->user_name, (bool)(int)$row->gt_hidden ];
		}
		foreach ( $named as $id => $name ) {
			if ( !isset( $found[$id] ) || $found[$id][0] !== $name || ( $found[$id][1] && !$showsHiddenUsers ) ) {
				return false;
			}
		}
		foreach ( $hidden as $id => $unused ) {
			if ( !isset( $found[$id] ) || !$found[$id][1] ) {
				return false;
			}
		}
		foreach ( $missing as $id => $unused ) {
			if ( isset( $found[$id] ) && !$found[$id][1] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The viewer-independent summary of a page (CommentQueryService) when the stored one is up
	 * to date, else null: the caller reads the database as before. Then, when the caller may make
	 * one and a stored copy would be up to date, $build makes it after the response is sent, once
	 * a request, and it is stored. Not locked against stampedes: logged-in readers, among them the
	 * author of a new comment, must see a write as soon as it is committed.
	 *
	 * While an anonymous response is computed, only a summary that was up to date when it was
	 * read is used, and none is made.
	 *
	 * @param int $pageId
	 * @param callable $build Returns the summary, from its own reads
	 * @param bool $mayBuild Whether this request may make one (not for anonymous readers)
	 * @return array|null
	 */
	public function getSummary( int $pageId, callable $build, bool $mayBuild ): ?array {
		if ( $this->anonymous && $this->anonymous[0] === $pageId ) {
			return $this->anonymous[1];
		}
		return $this->readSummary( $pageId, $mayBuild, $build );
	}

	/**
	 * See getSummary(). Not read after this web server noted that it could not be stored, nor,
	 * by readers who cannot make one, after one of them found none.
	 *
	 * @param int $pageId
	 * @param bool $mayBuild
	 * @param callable|null $build
	 * @return array|null
	 */
	private function readSummary( int $pageId, bool $mayBuild, ?callable $build ): ?array {
		$missing = $this->noteKey( 'nosummary', $pageId );
		$lagging = $this->noteKey( 'lagging', $pageId );
		if ( $this->noted( $lagging ) || ( !$mayBuild && $this->noted( $missing ) ) ) {
			return null;
		}
		$key = $this->getSummaryKey( $pageId );
		[ $fresh, $writable, $absent ] = $this->read( [ $key ], $pageId );
		if ( isset( $fresh[$key] ) ) {
			return $fresh[$key];
		}
		if ( $writable && $mayBuild && $build && !isset( $this->building[$pageId] ) ) {
			$this->building[$pageId] = true;
			$this->store( $key, $build, self::TTL, null, isset( $absent[$key] ), $lagging, $missing );
		} elseif ( $writable && !$mayBuild ) {
			$this->note( $missing, ConvertibleTimestamp::time() + self::NOTE_TTL );
		}
		return null;
	}

	/**
	 * A summary that no longer fits the comments: they changed without a purge (an edit around
	 * the hooks). Purge the page's lists now, so that the summary is made again after the
	 * hold-off.
	 */
	public function purgeMissed( int $pageId ): void {
		$this->wanCache->touchCheckKey( $this->getPageKey( $pageId ) );
	}
}
