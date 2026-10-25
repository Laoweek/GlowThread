<?php
// Dev only: seed GlowThread comments from dev/seed (see dev/README.md, "Seed content").
//
//   php maintenance/run.php /dev-maintenance/SeedComments.php
//
// Sources: every comments file in dev/seed/content (comments.json, i18n.json and any other
// *.json of that shape, such as civil-comments.json) and dev/seed/site/comments.json: comments
// with replies, likes, reports, pins, held and deleted comments. Plus the load-test page
// "Sandbox/Load test": --load-test-comments comments (default 400) combined from
// content/fragments.json by a seeded random generator, so every run produces the same thread.
// Page states (locked, closed) come from content/pages.json.
//
// Everything goes through GlowThread's own services as the acting user, at the time the content
// gives ("ago", relative to now), so floors, counters, hot scores, log entries and Echo
// notifications come out as if people had written them over the past months. Only for this
// script: rate limits are off, and anonymous authors (old comments from before a wiki turned
// anonymous posting off) may post and report.
//
// A page that already has comments (any status) is skipped, so the script is safe to run again.
// To seed a page again, delete its comments (./setup.sh --reset deletes everything).

use MediaWiki\Extension\GlowThread\Model\PageState;
use MediaWiki\Extension\GlowThread\Model\PostStatus;
use MediaWiki\Extension\GlowThread\Text\TextProcessor;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Title\Title;
use Random\Engine\Mt19937;
use Random\Randomizer;

require_once __DIR__ . '/SeedSupport.php';

class GlowThreadDevSeedComments extends Maintenance {
	use GlowThreadDevSeedSupport;

	/** Page that gets the generated comments (created by SeedPages.php from dev/seed/site) */
	private const LOAD_TEST_PAGE = 'Sandbox/Load test';

	/** Seed of the random generator (timestamps jitter, generated comments) */
	private const RNG_SEED = 20261010;

	/** Authors of generated comments, repeated by weight */
	private const GENERATED_AUTHORS = [
		'Alice', 'Alice', 'Alice', 'Bob', 'Bob', 'Bob', 'Carol', 'Carol', 'Carol',
		'Dave', 'Dave', 'Dave', 'Erin', 'Erin', 'Erin', 'Trent', 'Trent', 'Trent', 'Moderator',
	];

	/** Accounts that like generated comments */
	private const LIKERS = [ 'Alice', 'Bob', 'Carol', 'Dave', 'Erin', 'Trent', 'Moderator', 'Admin' ];

	/** JSON files of the content folder that do not hold GlowThread comments */
	private const NOT_COMMENT_FILES = [ 'pages.json', 'fragments.json', 'flowthread.json' ];

	/** pages.json state => GlowThread page state */
	private const STATES = [ 'open' => PageState::OPEN, 'locked' => PageState::LOCKED, 'closed' => PageState::DISABLED ];

	private int $now;
	private Randomizer $rng;
	/** @var array[] Events of the page being seeded */
	private array $events = [];
	/** @var array<string,string> Comment key => wanted status */
	private array $wanted = [];
	private int $keySerial = 0;
	/** Time of the newest comment of the page being seeded */
	private int $lastPostTime = 0;
	/** Steps that failed, over all pages */
	private int $failures = 0;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Dev only: seed GlowThread comments' );
		$this->addOption( 'content', 'Seed content folder', false, true );
		$this->addOption( 'site', 'Site seed folder', false, true );
		$this->addOption( 'load-test-comments', 'Comments generated on ' . self::LOAD_TEST_PAGE .
			' (default 400)', false, true );
	}

	public function execute() {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'GlowThread' ) ) {
			$this->fatalError( 'GlowThread is not loaded (legacy mode, or no valid extension.json).' );
		}
		$contentDir = $this->getOption( 'content', '/dev-seed/content' );
		$siteDir = $this->getOption( 'site', '/dev-seed/site' );
		$this->relaxPolicyForSeeding();
		$this->now = time();

		$states = [];
		foreach ( $this->readJson( "$contentDir/pages.json" ) as $entry ) {
			$states[$entry['title']] = $entry['state'] ?? 'open';
		}
		// Every comments file of the content folder (comments.json, i18n.json and any other file
		// of that shape), then the site folder's.
		$files = array_filter(
			glob( "$contentDir/*.json" ) ?: [],
			static fn ( $file ) => !in_array( basename( $file ), self::NOT_COMMENT_FILES, true )
		);
		$files[] = "$siteDir/comments.json";
		$threads = [];
		foreach ( $files as $file ) {
			foreach ( $this->readJson( $file ) as $title => $comments ) {
				$threads[$title] = array_merge( $threads[$title] ?? [], $comments );
			}
		}
		$count = $this->getOption( 'load-test-comments', '400' );
		if ( !ctype_digit( (string)$count ) ) {
			$this->fatalError( '--load-test-comments needs a number' );
		}
		$generated = $this->generate( $this->readJson( "$contentDir/fragments.json" ), (int)$count );
		$threads[self::LOAD_TEST_PAGE] = array_merge( $threads[self::LOAD_TEST_PAGE] ?? [], $generated );

		foreach ( array_unique( array_merge( array_keys( $states ), array_keys( $threads ) ) ) as $title ) {
			$this->seedPage( (string)$title, $threads[$title] ?? [], $states[$title] ?? 'open' );
		}
		if ( $this->failures ) {
			$this->fatalError( "{$this->failures} seed steps failed (see above). Fix the content or the code, " .
				'then run ./setup.sh --reset: pages that already have comments are not seeded again.' );
		}
	}

	/**
	 * For this process only: no rate limits, and anonymous authors may post and report.
	 */
	private function relaxPolicyForSeeding(): void {
		$hooks = $this->getServiceContainer()->getHookContainer();
		$hooks->register( 'PingLimiter', static function ( $user, $action, &$result ) {
			$result = false;
			return false;
		} );
		$hooks->register( 'UserGetRights', static function ( $user, &$rights ) {
			if ( !$user->isRegistered() ) {
				$rights = array_merge( $rights, [ 'glowthread-post', 'glowthread-report' ] );
			}
		} );
	}

	private function seedPage( string $titleText, array $comments, string $state ): void {
		$title = Title::newFromText( $titleText );
		if ( !$title || !$title->exists() ) {
			$this->fatalError( "Page \"$titleText\" does not exist (SeedPages.php creates the pages)" );
		}
		if ( !isset( self::STATES[$state] ) ) {
			$this->fatalError( "$titleText: unknown state \"$state\"" );
		}
		$pageId = $title->getArticleID();
		$existing = (int)$this->getPrimaryDB()->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'glowthread_post' )
			->where( [ 'gtp_page' => $pageId ] )
			->caller( __METHOD__ )
			->fetchField();
		if ( $existing > 0 ) {
			$this->output( "$titleText: has $existing comments, not seeded\n" );
			return;
		}
		if ( !$comments && $state === 'open' ) {
			return;
		}

		$this->rng = new Randomizer( new Mt19937( self::RNG_SEED ^ crc32( $titleText ) ) );
		$this->events = [];
		$this->wanted = [];
		$this->lastPostTime = 0;
		$lastTime = 0;
		foreach ( $comments as $comment ) {
			$lastTime = max( $lastTime, $this->plan( $titleText, $comment, null, null, 0 ) );
		}
		usort( $this->events, static fn ( $a, $b ) => [ $a['t'], $a['n'] ] <=> [ $b['t'], $b['n'] ] );
		// Every author must exist before the first write, so a typo cannot leave half a page.
		foreach ( $this->events as $event ) {
			$this->seedUser( $event['by'] );
		}

		$ids = [];
		$counts = [ 'post' => 0, 'like' => 0, 'report' => 0, 'delete' => 0, 'pin' => 0 ];
		foreach ( $this->events as $event ) {
			$ok = $this->atTime( $event['t'], function () use ( $titleText, $pageId, $event, &$ids ) {
				return $this->runEvent( $titleText, $pageId, $event, $ids );
			}, $event['by'] );
			if ( $ok ) {
				$counts[$event['type']]++;
			}
		}
		$this->checkStatuses( $titleText, $ids );

		$wantedState = self::STATES[$state];
		if ( $wantedState !== PageState::OPEN ) {
			$moderator = $this->seedUser( 'Moderator' );
			$reason = $wantedState === PageState::LOCKED
				? 'Locked: the recipe was retired, old answers stay readable'
				: 'Comments closed on this page';
			// A locked page still takes likes and reports, so it is locked an hour after its newest
			// comment (often the lock notice). A closed page takes nothing, so after everything.
			$after = $wantedState === PageState::LOCKED ? $this->lastPostTime : $lastTime;
			$at = $after ? min( $this->now - 300, $after + 3600 ) : $this->now - 7 * 86400;
			$status = $this->atTime( $at, fn () => $this->getServiceContainer()->get( 'GlowThread.PageStateService' )
				->setState( $moderator, $pageId, $wantedState, $reason ), 'Moderator' );
			if ( !$status->isOK() ) {
				$this->fail( "$titleText: could not set state $wantedState: " . $this->keys( $status ) );
			}
		}
		$this->output( sprintf( "%s: %d comments, %d likes, %d reports, %d deleted, %d pinned, state %s\n",
			$titleText, $counts['post'], $counts['like'], $counts['report'], $counts['delete'], $counts['pin'],
			$wantedState ) );
	}

	/**
	 * Turn one comment and its replies into timed events.
	 *
	 * @return int Time of the last event of this comment and its replies
	 */
	private function plan( string $page, array $c, ?string $parentKey, ?int $parentTime, int $depth ): int {
		foreach ( [ 'by', 'text' ] as $field ) {
			if ( !isset( $c[$field] ) || !is_string( $c[$field] ) ) {
				$this->fatalError( "$page: a comment has no \"$field\": " . json_encode( $c ) );
			}
		}
		if ( $depth > 2 ) {
			$this->fatalError( "$page: replies nest at most two levels below a top-level comment" );
		}
		$key = $c['ref'] ?? ( '#' . ++$this->keySerial );
		$age = $c['agoSeconds'] ?? $this->parseAgo( (string)( $c['ago'] ?? '1d' ) );
		$jitter = $age >= 86400 ? 6 * 3600 : ( $age >= 3600 ? 900 : 60 );
		$time = $this->now - $age + $this->rng->getInt( -$jitter, $jitter );
		if ( $parentTime !== null ) {
			$time = max( $time, $parentTime + $this->rng->getInt( 120, 900 ) );
		}
		$time = min( $time, $this->now - 120 );
		$this->lastPostTime = max( $this->lastPostTime, $time );
		$this->addEvent( 'post', $time, [
			'key' => $key, 'parent' => $parentKey, 'by' => $c['by'], 'text' => $c['text'],
		] );
		$status = $c['status'] ?? 'visible';
		if ( !in_array( $status, [ 'visible', 'held', 'deleted' ], true ) ) {
			$this->fatalError( "$page: unknown status \"$status\"" );
		}
		$this->wanted[$key] = [ $status === 'deleted' ? PostStatus::DELETED : $status, isset( $c['status'] ) ];

		$last = $time;
		if ( !empty( $c['pinned'] ) ) {
			$last = max( $last, $this->later( $time, 60, 900 ) );
			$this->addEvent( 'pin', $last, [ 'key' => $key, 'by' => 'Moderator' ] );
		}
		foreach ( $c['likes'] ?? [] as $liker ) {
			$at = $this->later( $time, 60, 3 * 86400 );
			$this->addEvent( 'like', $at, [ 'key' => $key, 'by' => $liker ] );
			$last = max( $last, $at );
		}
		foreach ( $c['reports'] ?? [] as $report ) {
			$at = $this->later( $time, 300, 2 * 86400 );
			$this->addEvent( 'report', $at, [
				'key' => $key, 'by' => $report['by'], 'reason' => $report['reason'] ?? 'other',
				'note' => $report['note'] ?? '',
			] );
			$last = max( $last, $at );
		}
		foreach ( $c['replies'] ?? [] as $reply ) {
			$last = max( $last, $this->plan( $page, $reply, $key, $time, $depth + 1 ) );
		}
		// A moderator removes the comment after everything above happened
		if ( $status === 'deleted' ) {
			$last = $this->later( $last, 1800, 12 * 3600 );
			$this->addEvent( 'delete', $last, [
				'key' => $key, 'by' => 'Moderator',
				'reason' => !empty( $c['reports'] ) ? 'Removed after reports' : 'Removed by a moderator',
			] );
		}
		return $last;
	}

	/**
	 * A random time between $min and $max seconds after $time, and before now.
	 */
	private function later( int $time, int $min, int $max ): int {
		$room = max( 1, $this->now - 30 - $time );
		return $time + $this->rng->getInt( min( $min, $room ), max( min( $min, $room ), min( $max, $room ) ) );
	}

	private function addEvent( string $type, int $time, array $data ): void {
		$this->events[] = [ 'type' => $type, 't' => $time, 'n' => count( $this->events ) ] + $data;
	}

	/**
	 * @return bool Whether it worked
	 */
	private function runEvent( string $page, int $pageId, array $event, array &$ids ): bool {
		$services = $this->getServiceContainer();
		$user = $this->seedUser( $event['by'] );
		$label = "$page: {$event['type']} by {$event['by']}";
		if ( $event['type'] === 'post' ) {
			$parentId = null;
			if ( $event['parent'] !== null ) {
				$parentId = $ids[$event['parent']] ?? null;
				if ( $parentId === null ) {
					$this->fail( "$label skipped: its parent was not posted" );
					return false;
				}
			}
			$status = $services->get( 'GlowThread.PostingService' )
				->post( $user, $pageId, $this->mentionTokens( $event['text'] ), $parentId );
			if ( !$status->isOK() ) {
				$this->fail( "$label failed: " . $this->keys( $status ) . ' (' . mb_substr( $event['text'], 0, 60 ) . ')' );
				return false;
			}
			$ids[$event['key']] = $status->getValue()->getId();
			return true;
		}
		$id = $ids[$event['key']] ?? null;
		if ( $id === null ) {
			return false;
		}
		$post = $services->get( 'GlowThread.PostStore' )->getById( $id, true );
		if ( in_array( $event['type'], [ 'like', 'report' ], true ) && $post && $post->isHeld() ) {
			// Readers never see a held comment, so they cannot like or report it. The content's
			// spam comment is held by the spam blacklist when it is posted, before its reports.
			$this->output( "$label skipped: comment $id is held for review ("
				. implode( ', ', $post->getHoldReasons() ) . ")\n" );
			return false;
		}
		if ( $event['type'] === 'like' ) {
			$status = $services->get( 'GlowThread.ReactionService' )->setLike( $user, $id, true );
		} elseif ( $event['type'] === 'report' ) {
			$status = $services->get( 'GlowThread.ReportService' )
				->report( $user, $id, $event['reason'], $event['note'] );
		} else {
			$status = $services->get( 'GlowThread.ModerationService' )->moderate(
				$user, $event['type'], [ $id ], $event['reason'] ?? ''
			);
			if ( $status->isOK() && ( $status->getValue()[0]['result'] ?? '' ) !== 'success' ) {
				$this->fail( "$label on comment $id: " . ( $status->getValue()[0]['code'] ?? 'no change' ) );
				return false;
			}
		}
		if ( !$status->isOK() ) {
			$this->fail( "$label on comment $id failed: " . $this->keys( $status ) );
			return false;
		}
		return true;
	}

	/**
	 * The content writes a mention as "@Name". GlowThread stores a mention as a token with the
	 * user's ID (TextProcessor::MENTION_PATTERN), as the browser does for a name picked from its
	 * suggestions, so each "@Name" of an account becomes that account's token. The name runs up
	 * to the next space or punctuation and must be the account name exactly. As in the browser,
	 * an @ right after an ASCII letter or digit, "_", ".", "/" or "@" starts no mention. Anything
	 * else, such as "@ the coast", "a@b.example" or "https://x.example/@Bob", stays plain text.
	 */
	private function mentionTokens( string $text ): string {
		$lookup = $this->getServiceContainer()->getUserIdentityLookup();
		return preg_replace_callback( '/(?<![A-Za-z0-9_.\/@])@([\p{L}\p{M}\p{N}]+)/u', static function ( $m ) use ( $lookup ) {
			$user = $lookup->getUserIdentityByName( $m[1] );
			return $user && $user->isRegistered() && $user->getName() === $m[1]
				? TextProcessor::mentionToken( $user->getId() )
				: $m[0];
		}, $text );
	}

	/**
	 * Report comments whose status differs from the content. A comment without a status that
	 * GlowThread held (spam blacklist, word filter, reports) is expected and only noted. A
	 * comment whose explicit status was not reached is a warning.
	 */
	private function checkStatuses( string $page, array $ids ): void {
		$store = $this->getServiceContainer()->get( 'GlowThread.PostStore' );
		foreach ( $this->wanted as $key => [ $wanted, $explicit ] ) {
			$post = isset( $ids[$key] ) ? $store->getById( $ids[$key], true ) : null;
			if ( !$post || $post->getStatus() === $wanted ) {
				continue;
			}
			if ( !$explicit && $post->isHeld() ) {
				$this->output( "$page: comment $key ({$post->getId()}) was held by GlowThread ("
					. implode( ', ', $post->getHoldReasons() ) . ")\n" );
			} else {
				$this->fail( "$page: comment $key is {$post->getStatus()}, the content says $wanted" );
			}
		}
	}

	private function fail( string $message ): void {
		$this->failures++;
		$this->error( $message );
	}

	private function keys( StatusValue $status ): string {
		return implode( ', ', array_map( static fn ( $m ) => $m->getKey(), $status->getMessages() ) );
	}

	/**
	 * The comments of the load-test page, $total in all: top-level comments spread over about
	 * 17 months (more of them recent), a third with replies, a few liked a lot.
	 *
	 * @return array[] Comments in the content format, with "agoSeconds" instead of "ago"
	 */
	private function generate( array $fragments, int $total ): array {
		foreach ( [ 'openers', 'bodies', 'closers', 'replies' ] as $part ) {
			if ( empty( $fragments[$part] ) ) {
				$this->fatalError( "fragments.json has no \"$part\"" );
			}
		}
		$rng = new Randomizer( new Mt19937( self::RNG_SEED ) );
		$pick = static fn ( array $list ) => $list[$rng->getInt( 0, count( $list ) - 1 )];
		$chance = static fn ( float $p ) => $rng->getFloat( 0, 1 ) < $p;
		$used = [];
		// One author never posts the same text twice on the page (the duplicate check)
		$unique = static function ( string $by, string $text ) use ( &$used, $pick, $fragments ) {
			while ( isset( $used["$by\n$text"] ) ) {
				$text .= ' ' . $pick( $fragments['closers'] );
			}
			$used["$by\n$text"] = true;
			return $text;
		};
		$author = static function () use ( $rng, $pick ) {
			if ( $rng->getInt( 1, 100 ) <= 12 ) {
				return 'ip:' . $pick( [ '198.51.100.', '203.0.113.' ] ) . $rng->getInt( 100, 199 );
			}
			return $pick( self::GENERATED_AUTHORS );
		};
		$likes = static function ( string $by ) use ( $rng ) {
			$count = (int)floor( $rng->getFloat( 0, 1 ) ** 3 * 8 );
			$likers = array_values( array_diff( self::LIKERS, [ $by ] ) );
			return array_slice( $rng->shuffleArray( $likers ), 0, $count );
		};
		$reply = static function ( string $parentBy, int $parentAge ) use ( $rng, $pick, $chance, $unique, $author, $fragments ) {
			do {
				$by = $author();
			} while ( $by === $parentBy );
			$text = $pick( $fragments['replies'] );
			if ( $chance( 0.3 ) ) {
				$text .= ' ' . $pick( $fragments['bodies'] );
			}
			return [
				'by' => $by,
				'text' => $unique( $by, $text ),
				'agoSeconds' => max( 180, $parentAge - $rng->getInt( 600, 3 * 86400 ) ),
			];
		};

		$comments = [];
		$left = $total;
		while ( $left > 0 ) {
			$left--;
			$by = $author();
			$parts = [];
			if ( $chance( 0.7 ) ) {
				$parts[] = $pick( $fragments['openers'] );
			}
			$parts[] = $pick( $fragments['bodies'] );
			if ( $chance( 0.6 ) ) {
				$parts[] = $pick( $fragments['closers'] );
			}
			$age = 600 + (int)( 520 * 86400 * ( $rng->getFloat( 0, 1 ) ** 1.7 ) );
			$comment = [
				'by' => $by,
				'text' => $unique( $by, implode( ' ', $parts ) ),
				'agoSeconds' => $age,
				'likes' => $likes( $by ),
				'replies' => [],
			];
			if ( $chance( 0.35 ) ) {
				for ( $r = $rng->getInt( 1, 3 ); $r > 0 && $left > 0; $r-- ) {
					$child = $reply( $by, $age );
					$left--;
					if ( $left > 0 && $chance( 0.25 ) ) {
						$child['replies'] = [ $reply( $child['by'], $child['agoSeconds'] ) ];
						$left--;
					}
					$comment['replies'][] = $child;
				}
			}
			$comments[] = $comment;
		}
		return $comments;
	}
}

return GlowThreadDevSeedComments::class;
