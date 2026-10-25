<?php

namespace MediaWiki\Extension\GlowThread\Import;

use LogEntryBase;
use ManualLogEntry;
use MediaWiki\Extension\GlowThread\Log\LegacyLogType;
use MediaWiki\Extension\GlowThread\Log\ModerationLogger;
use MediaWiki\Extension\GlowThread\Model\HoldReason;
use MediaWiki\Extension\GlowThread\Model\PageState;
use MediaWiki\Extension\GlowThread\Model\PostStatus;
use MediaWiki\Extension\GlowThread\Model\ReportReason;
use MediaWiki\Extension\GlowThread\Query\ListCache;
use MediaWiki\Extension\GlowThread\Store\PageStateStore;
use MediaWiki\Extension\GlowThread\Store\PostStore;
use MediaWiki\Extension\GlowThread\Text\TextProcessor;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\ActorNormalization;
use MediaWiki\User\TempUser\TempUserConfig;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityLookup;
use MediaWiki\User\UserIdentityValue;
use RuntimeException;
use Wikimedia\IPUtils;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Imports FlowThread's comments, likes, reports and page settings into GlowThread.
 *
 * One run plans the complete target state from the FlowThread tables, compares it with what
 * GlowThread holds, and then, depending on the mode:
 * - dry-run: reports what it would write and writes nothing,
 * - apply: writes what is missing (and with ImportOptions::$update rewrites imported rows that
 *   differ), in batches with a wait for replication after each,
 * - verify: writes nothing and records a mismatch for every difference.
 *
 * The FlowThread ID (gtp_legacy_id) is the key, so a second run over the same source changes
 * nothing. Imported likes are the likes with a null timestamp and imported reports have the
 * reason "legacy"; only those are ever removed (with $update). Decisions made in GlowThread
 * since are kept in every mode, $update included: an erased comment, the status, hold reason
 * and text of a comment whose status GlowThread changed, the page it moved a comment to after an
 * undelete, report resolutions made there and page states set there (see keptColumns()).
 * Likes and reports come only with their comment, so none that was taken back in GlowThread is
 * added again (insertAttitudes()). Writes go straight to the tables: no hooks, notifications,
 * CheckUser events, CDN purges or per-comment log entries. The wiki's own cache of the lists
 * (ListCache) is purged after each write batch of an apply run and at its end, also when it
 * fails half-way, so readers see the comments as they are written. The source tables are only
 * read (LegacySource).
 */
class FlowThreadImporter {

	public const MODE_DRY_RUN = 'dry-run';
	public const MODE_APPLY = 'apply';
	public const MODE_VERIFY = 'verify';

	private const POST = PostStore::TABLE;
	private const REACTION = 'glowthread_reaction';
	private const REPORT = 'glowthread_report';
	private const LIKE = 'like';
	private const CHUNK = 500;
	/** Longest gtp_text in bytes: its length in sql/tables.json (a BLOB on MySQL and MariaDB) */
	private const MAX_TEXT_BYTES = 65530;
	/** Ends a text shortened to MAX_TEXT_BYTES */
	private const SHORTENED = '…';
	/**
	 * Columns of glowthread_post the import decides and compares on every run. The floor
	 * (gtp_seq) is not compared: it is handed out once and stays, see checkFloors().
	 */
	private const PLANNED_COLUMNS = [
		'gtp_page', 'gtp_actor', 'gtp_parent', 'gtp_reply_to', 'gtp_thread', 'gtp_depth',
		'gtp_text', 'gtp_created', 'gtp_status', 'gtp_hold_reason',
	];

	/** @var callable Called after each write batch */
	private $waitForReplication;
	/** @var callable Called with a progress message */
	private $progress;

	private string $mode;
	private ImportOptions $options;
	private ImportReport $report;
	private IDatabase $dbw;
	/** @var array<string,array> Legacy hex ID => planned comment, in creation order */
	private array $plan = [];
	/** @var array<string,int|string> Legacy hex ID => gtp_id (or "new:<hex>" in dry-run and verify) */
	private array $ids = [];
	/** @var array<string,?int> Author key => actor ID (null: does not exist yet) */
	private array $actors = [];
	/** @var array<int,array{name:string}> Existing users by ID */
	private array $users = [];
	/** @var array<string,int> Legacy hex ID => floor, stored or assigned in this run */
	private array $floors = [];
	/** @var array<int,string> Page => its comment state once this run is done (pages not listed: open) */
	private array $pageStates = [];
	/** Writes since the last wait for replication, outside the comment batches */
	private int $pendingWrites = 0;
	/** The choices of this run differ from those recorded by the last import */
	private bool $decisionsChanged = false;
	/** @var array<string,true> Legacy hex IDs of the comments this run inserted */
	private array $insertedNow = [];

	public function __construct(
		private readonly IConnectionProvider $dbProvider,
		private readonly LegacyHtmlConverter $converter,
		private readonly ActorNormalization $actorNormalization,
		private readonly PageStateStore $pageStateStore,
		private readonly TempUserConfig $tempUserConfig,
		private readonly UserIdentityLookup $userIdentityLookup,
		private readonly TextProcessor $textProcessor,
		?callable $waitForReplication = null,
		?callable $progress = null,
		private ?ListCache $listCache = null
	) {
		$this->waitForReplication = $waitForReplication ?? static function (): void {
		};
		$this->progress = $progress ?? static function ( string $message ): void {
		};
	}

	/**
	 * @param LegacySource $source
	 * @param ImportOptions $options
	 * @param string $mode One of the MODE_* constants
	 * @param UserIdentity $performer Resolves reports on deleted comments and writes the log entry
	 * @param string $now Current time, TS_MW
	 */
	public function run(
		LegacySource $source, ImportOptions $options, string $mode, UserIdentity $performer, string $now
	): ImportReport {
		if ( $mode !== self::MODE_APPLY ) {
			return $this->runMode( $source, $options, $mode, $performer, $now );
		}
		$this->listCache ??= MediaWikiServices::getInstance()->getService( 'GlowThread.ListCache' );
		$listCache = $this->listCache;
		$wait = $this->waitForReplication;
		$this->waitForReplication = static function () use ( $wait, $listCache ): void {
			$wait();
			$listCache->touchAll();
		};
		try {
			return $this->runMode( $source, $options, $mode, $performer, $now );
		} finally {
			$this->waitForReplication = $wait;
			$listCache->touchAll();
		}
	}

	/**
	 * See run().
	 */
	private function runMode(
		LegacySource $source, ImportOptions $options, string $mode, UserIdentity $performer, string $now
	): ImportReport {
		$this->mode = $mode;
		$this->options = $options;
		$this->report = new ImportReport( $mode );
		$this->dbw = $this->dbProvider->getPrimaryDatabase();
		$this->plan = [];
		$this->ids = [];
		$this->actors = [];
		$this->users = [];
		$this->floors = [];
		$this->pageStates = [];
		$this->pendingWrites = 0;
		$this->decisionsChanged = false;
		$this->insertedNow = [];
		foreach ( $options->toArray() as $key => $value ) {
			$this->report->setInfo( $key, is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : ( $value ?? 'all' ) );
		}
		$this->report->setInfo( 'server', $this->converter->getServer() );
		$this->checkRecordedDecisions();

		$missing = $source->missingTables();
		if ( $missing ) {
			$this->report->mismatch( 'FlowThread tables missing: ' . implode( ', ', $missing ) );
			return $this->report;
		}

		$start = microtime( true );
		$posts = $source->fetchPosts( $options->pageId );
		$attitudes = $source->fetchAttitudes( $options->pageId === null ? null : array_column( $posts, 'id' ) );
		$controls = $source->fetchControls( $options->pageId );
		$this->report->time( 'read FlowThread', microtime( true ) - $start );

		$start = microtime( true );
		$this->planComments( $source, $posts, (int)ConvertibleTimestamp::convert( TS_UNIX, $now ) );
		[ $likes, $reports ] = $this->planAttitudes( $attitudes );
		$this->report->time( 'plan and convert', microtime( true ) - $start );

		$start = microtime( true );
		if ( !$this->canCreateIpActors() ) {
			return $this->report;
		}
		$this->resolveActors( $attitudes );
		$this->planMentions();
		$performerActor = $this->mode === self::MODE_APPLY
			? $this->actorNormalization->acquireActorId( $performer, $this->dbw )
			: $this->actorNormalization->findActorId( $performer, $this->dbw );
		$this->report->time( 'actors', microtime( true ) - $start );

		$start = microtime( true );
		$inserted = $this->syncComments( $likes, $reports, $performerActor, $this->dbw->timestamp( $now ) );
		$this->checkFloors();
		$this->report->time( 'comments', microtime( true ) - $start );

		$start = microtime( true );
		$this->syncLikes( $likes );
		$this->syncReports( $reports, $performerActor, $now );
		$this->report->time( 'likes and reports', microtime( true ) - $start );

		$start = microtime( true );
		$this->syncCounters();
		$this->syncPages( $controls );
		$this->checkVisibility();
		$this->report->time( 'counters and pages', microtime( true ) - $start );

		$this->writeLogEntry( $performer, $inserted );
		return $this->report;
	}

	/**
	 * Placement, status, text, floor and author of every comment.
	 */
	private function planComments( LegacySource $source, array $posts, int $now ): void {
		$r = $this->report;
		$byHex = [];
		foreach ( $posts as $post ) {
			$r->add( 'comments', 'read' );
			if ( strlen( $post['id'] ) !== LegacyId::BINARY_LENGTH ) {
				$r->add( 'comments', 'skipped: ID is not 11 bytes' );
				$r->addId( 'bad ID', bin2hex( $post['id'] ) );
				continue;
			}
			$post['hex'] = bin2hex( $post['id'] );
			$post['parentHex'] = $post['parent'] !== null && strlen( $post['parent'] ) === LegacyId::BINARY_LENGTH
				? bin2hex( $post['parent'] ) : null;
			if ( $post['parent'] !== null && $post['parentHex'] === null ) {
				$post['parentHex'] = 'bad';
			}
			$byHex[$post['hex']] = $post;
		}
		$outside = [];
		foreach ( $byHex as $post ) {
			if ( $post['parentHex'] !== null && $post['parentHex'] !== 'bad' && !isset( $byHex[$post['parentHex']] ) ) {
				$outside[] = hex2bin( $post['parentHex'] );
			}
		}
		$outsidePages = [];
		foreach ( $outside ? $source->fetchPagesOf( $outside ) : [] as $raw => $page ) {
			$outsidePages[bin2hex( $raw )] = $page;
		}

		$seq = [];
		$first = null;
		$last = null;
		$visiting = [];
		foreach ( $byHex as $hex => $post ) {
			$this->place( $hex, $byHex, $outsidePages, $visiting );
			$id = LegacyId::fromHex( $hex );
			$this->plan[$hex]['created'] = $id->getTimestamp();
			$first ??= $id->getTimestamp();
			$last = $id->getTimestamp();
			$problem = $id->getProblem( $now );
			if ( $problem !== null ) {
				$r->add( 'time', "implausible ID ($problem)" );
				$r->addId( "implausible ID ($problem)", $hex );
			}
			// Floors in creation order
			$seq[$post['page']] = ( $seq[$post['page']] ?? 0 ) + 1;
			$this->plan[$hex]['seq'] = $seq[$post['page']];
		}

		// From here on parents come before their replies (creation order, unless a parent is newer)
		$order = [];
		$visit = function ( string $hex ) use ( &$visit, &$order ): void {
			if ( isset( $order[$hex] ) ) {
				return;
			}
			$order[$hex] = true;
			if ( $this->plan[$hex]['replyTo'] !== null ) {
				unset( $order[$hex] );
				$visit( $this->plan[$hex]['replyTo'] );
				$order[$hex] = true;
			}
		};
		foreach ( array_keys( $this->plan ) as $hex ) {
			$visit( (string)$hex );
		}
		$this->plan = array_replace( $order, $this->plan );

		foreach ( $this->plan as $hex => &$p ) {
			$post = $byHex[$hex];
			$this->planText( $p, $post['text'] );
			$this->planStatus( $p );
			$p['author'] = $post['userId'] > 0 ? 'u:' . $post['userId'] : 'a:' . $post['userName'];
			$p['userId'] = $post['userId'];
			$p['userName'] = $post['userName'];
			$p['sourceLike'] = $post['like'];
			$p['sourceReport'] = $post['report'];
		}
		unset( $p );
		$r->setInfo( 'first comment (UTC)', $first ?? '-' );
		$r->setInfo( 'last comment (UTC)', $last ?? '-' );
		$r->set( 'floors', 'pages with comments', count( $seq ) );
	}

	/**
	 * Placement of one comment: GlowThread shows two reply levels, so deeper comments are shown
	 * under their depth-1 ancestor and keep the comment they answered in gtp_reply_to. Each
	 * comment keeps only its depth, thread and display parent, and the loop guard is shared, so
	 * memory grows linearly with the depth of a reply chain.
	 *
	 * @param string $hex
	 * @param array<string,array> $byHex
	 * @param array<string,int> $outsidePages Pages of parents outside $byHex
	 * @param array<string,true> &$visiting Loop guard: the replies waiting for this comment
	 */
	private function place( string $hex, array $byHex, array $outsidePages, array &$visiting ): void {
		if ( isset( $this->plan[$hex] ) ) {
			return;
		}
		$post = $byHex[$hex];
		$parentHex = $post['parentHex'];
		$orphan = null;
		if ( $parentHex !== null ) {
			if ( $parentHex === 'bad' ) {
				$orphan = 'missing parent';
			} elseif ( isset( $visiting[$parentHex] ) || $parentHex === $hex ) {
				$orphan = 'parent loop';
			} elseif ( !isset( $byHex[$parentHex] ) ) {
				$orphan = isset( $outsidePages[$parentHex] ) ? 'parent on another page' : 'missing parent';
			} elseif ( $byHex[$parentHex]['page'] !== $post['page'] ) {
				$orphan = 'parent on another page';
			} else {
				$visiting[$hex] = true;
				$this->place( $parentHex, $byHex, $outsidePages, $visiting );
				unset( $visiting[$hex] );
				if ( !isset( $this->plan[$parentHex] ) ) {
					$orphan = 'parent loop';
				}
			}
		}
		if ( $parentHex === null || $orphan !== null ) {
			$trueDepth = 0;
			$replyTo = null;
		} else {
			$trueDepth = $this->plan[$parentHex]['trueDepth'] + 1;
			$replyTo = $parentHex;
		}
		if ( $orphan !== null ) {
			$this->report->add( 'threads', "placed at top level: $orphan" );
			$this->report->addId( $orphan, $hex );
		}
		$this->report->add( 'threads', "FlowThread depth $trueDepth" );
		if ( $trueDepth > 2 ) {
			$this->report->add( 'threads', 'shown at depth 2 under their depth-1 ancestor' );
		}
		$parentShown = $replyTo === null ? true : $this->plan[$replyTo]['flowThreadShown'];
		$this->plan[$hex] = [
			'hex' => $hex,
			'page' => $post['page'],
			'trueDepth' => $trueDepth,
			'replyTo' => $replyTo,
			// Deeper than depth 2: the depth-1 ancestor, which is the display parent of the comment answered
			'parent' => $trueDepth <= 2 ? $replyTo : $this->plan[$replyTo]['parent'],
			'thread' => $replyTo === null ? null : ( $this->plan[$replyTo]['thread'] ?? $replyTo ),
			'depth' => min( $trueDepth, 2 ),
			'orphan' => $orphan,
			'sourceStatus' => $post['status'],
			// FlowThread lists a comment only if it and every ancestor are normal
			'flowThreadShown' => $post['status'] === LegacySource::STATUS_NORMAL && $orphan === null && $parentShown,
		];
	}

	private function planText( array &$p, string $html ): void {
		$r = $this->report;
		$result = $this->converter->convert( $html );
		// With a placeholder per user link until planMentions()
		$p['text'] = $result->text;
		$p['warnings'] = $result->getWarningCodes();
		$format = isset( $result->features[LegacyHtmlConverter::FEATURE_WRAPPER] ) ? 'MediaWiki 1.43 wrapper'
			: ( str_contains( $html, '<' ) ? 'inline HTML' : 'plain text' );
		$r->add( 'text', "format: $format" );
		if ( !$result->warnings ) {
			$r->add( 'text', 'converted with no warning' );
		}
		foreach ( $result->getWarningCodes() as $code ) {
			$r->add( 'text', "warning $code: comments" );
		}
		foreach ( $result->warnings as $warning ) {
			$r->add( 'text', "warning {$warning['code']}: occurrences" );
			$r->addId( "warning {$warning['code']}", $p['hex'], $warning['detail'] );
		}
		foreach ( $result->features as $feature => $n ) {
			$r->add( 'text', "feature $feature: comments" );
			$r->add( 'text', "feature $feature: occurrences", $n );
		}
		$r->add( 'mentions', 'user links', count( $result->mentions ) );
		$p['mentions'] = $result->mentions;
	}

	/**
	 * The final text of every comment. Each user link becomes the mention token of that user, by
	 * the exact name it links to, looked up for all comments together: registered users who are
	 * not hidden. A hidden user becomes TextProcessor::MENTION_PLACEHOLDER, so that the name is
	 * not stored, and a name that is no user stays "@Name" as plain text. Then text that outgrew
	 * the column (percent-encoded links) is cut at a character boundary, rather than leave MySQL
	 * to cut it inside a character or refuse the row.
	 */
	private function planMentions(): void {
		$r = $this->report;
		$names = [];
		foreach ( $this->plan as $p ) {
			foreach ( $p['mentions'] as $name ) {
				$names[$name] = true;
			}
		}
		// Name => user ID, or null for a hidden user
		$users = [];
		foreach ( array_chunk( array_map( 'strval', array_keys( $names ) ), self::CHUNK ) as $chunk ) {
			foreach ( [ true, false ] as $withHidden ) {
				$query = $this->userIdentityLookup->newSelectQueryBuilder()
					->whereUserNames( $chunk )
					->registered()
					->caller( __METHOD__ );
				if ( !$withHidden ) {
					$query->hidden( false );
				}
				foreach ( $query->fetchUserIdentities() as $user ) {
					$users[$user->getName()] = $withHidden ? null : $user->getId();
				}
			}
		}
		$hidden = count( array_filter( $users, 'is_null' ) );
		$r->set( 'mentions', 'distinct linked names', count( $names ) );
		$r->set( 'mentions', 'names of users, now mentions', count( $users ) - $hidden );
		$r->set( 'mentions', 'names of hidden users, now ' . TextProcessor::MENTION_PLACEHOLDER, $hidden );
		$r->set( 'mentions', 'names that are no user, kept as text', count( $names ) - count( $users ) );
		$ids = [];
		foreach ( $this->plan as &$p ) {
			if ( $p['mentions'] ) {
				$p['text'] = $this->converter->withMentions( $p['text'], $p['mentions'], $users );
			}
			// Tokens the author typed as text in FlowThread count too: they are mentions now
			array_push( $ids, ...$this->textProcessor->extractMentionIds( $p['text'] ) );
			if ( strlen( $p['text'] ) > self::MAX_TEXT_BYTES ) {
				$r->add( 'text', 'shortened to fit the text column' );
				$r->addId( 'shortened to fit the text column', $p['hex'], strlen( $p['text'] ) . ' bytes' );
				$cut = mb_strcut( $p['text'], 0, self::MAX_TEXT_BYTES - strlen( self::SHORTENED ), 'UTF-8' );
				// Not the start of a mention token without its end
				$p['text'] = preg_replace( '/<@[0-9]{0,10}$/', '', $cut ) . self::SHORTENED;
			}
		}
		unset( $p );
		$r->set( 'mentions', 'distinct users mentioned', count( array_unique( $ids ) ) );
	}

	private function planStatus( array &$p ): void {
		$o = $this->options;
		$r = $this->report;
		$hold = null;
		switch ( $p['sourceStatus'] ) {
			case LegacySource::STATUS_NORMAL:
				$status = PostStatus::VISIBLE;
				break;
			case LegacySource::STATUS_DELETED:
				$status = PostStatus::DELETED;
				break;
			case LegacySource::STATUS_SPAM:
				$status = $o->spam === 'held' ? PostStatus::HELD : PostStatus::DELETED;
				$hold = $o->spam === 'held' ? HoldReason::FILTER : null;
				break;
			default:
				$status = PostStatus::DELETED;
				$r->addId( 'unknown FlowThread status', $p['hex'], (string)$p['sourceStatus'] );
		}
		$r->add( 'comments', "FlowThread status {$p['sourceStatus']}" );
		if ( $status === PostStatus::VISIBLE ) {
			if ( $p['text'] === '' && $o->emptyText === 'delete' ) {
				$status = PostStatus::DELETED;
				$r->add( 'comments', 'deleted because empty' );
			} elseif ( in_array( LegacyHtmlConverter::WARN_SPOILER_REVEALED, $p['warnings'], true )
				&& $o->spoilers === 'hold'
			) {
				$status = PostStatus::HELD;
				$hold = HoldReason::FILTER;
				$r->add( 'comments', 'held because of a revealed spoiler' );
			}
		}
		// A comment FlowThread did not list but GlowThread would: GlowThread checks only the
		// display parent and the top-level comment, FlowThread every ancestor and the parent link
		if ( $status === PostStatus::VISIBLE && !$p['flowThreadShown'] ) {
			$shownHere = $p['depth'] === 0 || (
				$this->plan[$p['parent']]['status'] === PostStatus::VISIBLE
				&& $this->plan[$p['thread']]['status'] === PostStatus::VISIBLE
			);
			if ( $shownHere ) {
				$r->add( 'comments', 'hidden in FlowThread, shown by GlowThread\'s rule' );
				$category = $o->hidden === 'deleted' ? 'imported deleted' : 'now visible';
				$r->addId( "hidden in FlowThread, $category", $p['hex'] );
				if ( $o->hidden === 'deleted' ) {
					$status = PostStatus::DELETED;
				}
			} else {
				$r->add( 'comments', 'hidden by a removed ancestor (as in FlowThread)' );
			}
		}
		$p['status'] = $status;
		$p['hold'] = $hold;
		$r->add( 'comments', "imported as $status" );
	}

	/**
	 * Distinct likes and reports per comment, as user IDs.
	 *
	 * @return array{0:array<string,array<int,true>>,1:array<string,array<int,true>>}
	 */
	private function planAttitudes( array $attitudes ): array {
		$r = $this->report;
		$likes = [];
		$reports = [];
		// What FlowThread's counters count: distinct rows per comment, user and type
		$stored = [];
		foreach ( $attitudes as $att ) {
			$stored[bin2hex( $att['id'] )][$att['type']][$att['userId']] = true;
		}
		foreach ( $this->plan as $hex => $p ) {
			if ( count( $stored[$hex][LegacySource::ATTITUDE_LIKE] ?? [] ) !== $p['sourceLike'] ) {
				$r->add( 'likes', 'comments whose FlowThread counter differs from its rows' );
				$r->addId( 'like counter differs in FlowThread', $hex );
			}
			if ( count( $stored[$hex][LegacySource::ATTITUDE_REPORT] ?? [] ) !== $p['sourceReport'] ) {
				$r->add( 'reports', 'comments whose FlowThread counter differs from its rows' );
				$r->addId( 'report counter differs in FlowThread', $hex );
			}
		}
		foreach ( $attitudes as $att ) {
			$section = $att['type'] === LegacySource::ATTITUDE_LIKE ? 'likes'
				: ( $att['type'] === LegacySource::ATTITUDE_REPORT ? 'reports' : 'attitudes' );
			$r->add( $section, 'rows read' );
			if ( $section === 'attitudes' ) {
				$r->add( $section, 'skipped: unknown type' );
				continue;
			}
			$hex = bin2hex( $att['id'] );
			if ( !isset( $this->plan[$hex] ) ) {
				$r->add( $section, 'skipped: comment missing' );
				continue;
			}
			if ( $att['userId'] <= 0 ) {
				$r->add( $section, 'skipped: anonymous' );
				continue;
			}
			if ( $att['userId'] === $this->plan[$hex]['userId'] ) {
				$r->add( $section, 'on own comment' );
				if ( $this->options->selfAttitudes === 'drop' ) {
					$r->add( $section, 'skipped: on own comment' );
					continue;
				}
			}
			if ( $section === 'likes' ) {
				$set = &$likes;
			} else {
				$set = &$reports;
			}
			if ( isset( $set[$hex][$att['userId']] ) ) {
				$r->add( $section, 'skipped: exact duplicate' );
			} else {
				$set[$hex][$att['userId']] = true;
			}
			unset( $set );
		}
		return [ $likes, $reports ];
	}

	/**
	 * MediaWiki refuses IP actors while temporary accounts are enabled. Find that out before
	 * anything is written, and stop with a mismatch if an anonymous author has no actor yet.
	 */
	private function canCreateIpActors(): bool {
		if ( !$this->tempUserConfig->isEnabled() ) {
			return true;
		}
		$missing = 0;
		foreach ( $this->plan as $p ) {
			if ( $p['userId'] !== 0 || !IPUtils::isValid( $p['userName'] ) ) {
				continue;
			}
			$ip = IPUtils::sanitizeIP( $p['userName'] );
			if ( $this->actorNormalization->findActorIdByName( $ip, $this->dbw ) === null ) {
				$missing++;
			}
		}
		if ( $missing === 0 ) {
			return true;
		}
		$this->report->mismatch( 'Temporary accounts are enabled, so MediaWiki cannot create the IP actors ' .
			"for $missing anonymous comments. Import before enabling temporary accounts. Nothing was written." );
		return false;
	}

	/**
	 * Actor IDs for authors, likers and reporters. Registered users are found by user ID, so
	 * renames need nothing. A user ID that no longer exists becomes the external actor
	 * "imported>Name", anonymous authors become IP actors. Actors are created only in apply mode.
	 */
	private function resolveActors( array $attitudes ): void {
		$r = $this->report;
		$userIds = [];
		foreach ( $this->plan as $p ) {
			if ( $p['userId'] > 0 ) {
				$userIds[$p['userId']] = true;
			}
		}
		foreach ( $attitudes as $att ) {
			if ( $att['userId'] > 0 ) {
				$userIds[$att['userId']] = true;
			}
		}
		$actorByUser = [];
		foreach ( array_chunk( array_keys( $userIds ), self::CHUNK ) as $chunk ) {
			$res = $this->dbw->newSelectQueryBuilder()
				->select( [ 'user_id', 'user_name' ] )
				->from( 'user' )
				->where( [ 'user_id' => $chunk ] )
				->caller( __METHOD__ )
				->fetchResultSet();
			foreach ( $res as $row ) {
				$this->users[(int)$row->user_id] = [ 'name' => (string)$row->user_name ];
			}
			$res = $this->dbw->newSelectQueryBuilder()
				->select( [ 'actor_id', 'actor_user' ] )
				->from( 'actor' )
				->where( [ 'actor_user' => $chunk ] )
				->caller( __METHOD__ )
				->fetchResultSet();
			foreach ( $res as $row ) {
				$actorByUser[(int)$row->actor_user] = (int)$row->actor_id;
			}
		}

		$authorUsers = [];
		$authorIps = [];
		foreach ( $this->plan as $hex => $p ) {
			$key = $p['author'];
			if ( array_key_exists( $key, $this->actors ) ) {
				$this->countAuthor( $p );
				continue;
			}
			if ( $p['userId'] > 0 ) {
				$user = $this->users[$p['userId']] ?? null;
				if ( $user !== null ) {
					$authorUsers[$p['userId']] = true;
					$this->actors[$key] = $actorByUser[$p['userId']] ?? $this->acquire(
						new UserIdentityValue( $p['userId'], $user['name'] ), 'actors for existing users created'
					);
					if ( $user['name'] !== $p['userName'] ) {
						$r->add( 'authors', 'users whose stored name differs from the current name' );
					}
				} else {
					$r->add( 'authors', 'users missing, external actor used' );
					$r->addId( 'author user ID missing', $hex );
					$this->actors[$key] = $this->external( $p['userName'] );
				}
			} elseif ( IPUtils::isValid( $p['userName'] ) ) {
				$ip = IPUtils::sanitizeIP( $p['userName'] );
				$authorIps[$ip] = true;
				$this->actors[$key] = $this->actorNormalization->findActorIdByName( $ip, $this->dbw )
					?? $this->acquire( new UserIdentityValue( 0, $ip ), 'IP actors created' );
			} else {
				$r->add( 'authors', 'anonymous names that are not IPs, external actor used' );
				$r->addId( 'anonymous author is not an IP', $hex );
				$this->actors[$key] = $this->external( $p['userName'] );
			}
			$this->countAuthor( $p );
		}
		$r->set( 'authors', 'distinct registered authors', count( $authorUsers ) );
		$r->set( 'authors', 'distinct IP addresses', count( $authorIps ) );

		foreach ( array_keys( $userIds ) as $userId ) {
			$key = 'u:' . $userId;
			if ( !array_key_exists( $key, $this->actors ) && isset( $this->users[$userId] ) ) {
				$this->actors[$key] = $actorByUser[$userId] ?? $this->acquire(
					new UserIdentityValue( $userId, $this->users[$userId]['name'] ), 'actors for existing users created'
				);
			}
		}
	}

	private function countAuthor( array $p ): void {
		$this->report->add(
			'authors', $p['userId'] > 0 ? 'comments by registered users' : 'comments by anonymous authors'
		);
	}

	private function external( string $name ): ?int {
		$external = $this->options->missingUserPrefix . '>' . $name;
		return $this->actorNormalization->findActorIdByName( $external, $this->dbw )
			?? $this->acquire( new UserIdentityValue( 0, $external ), 'external actors created' );
	}

	/**
	 * @return int|null The new actor ID, or null outside apply mode (counted as "would create")
	 */
	private function acquire( UserIdentity $user, string $what ): ?int {
		if ( $this->mode !== self::MODE_APPLY ) {
			$this->report->add( 'authors', $this->mode === self::MODE_VERIFY ? "missing: $what" : "would be $what" );
			if ( $this->mode === self::MODE_VERIFY ) {
				$this->report->mismatch( "actor missing ($what)" );
			}
			return null;
		}
		try {
			$id = $this->actorNormalization->acquireActorId( $user, $this->dbw );
		} catch ( RuntimeException $e ) {
			// For example IP actors while temporary accounts are enabled
			throw new RuntimeException( 'Cannot create an actor: ' . $e->getMessage(), 0, $e );
		}
		$this->report->add( 'authors', $what );
		$this->wrote();
		return $id;
	}

	/**
	 * Count a write outside the comment batches and wait for replication after each batch of them.
	 */
	private function wrote(): void {
		if ( ++$this->pendingWrites >= $this->options->batchSize ) {
			( $this->waitForReplication )();
			$this->pendingWrites = 0;
		}
	}

	/**
	 * Insert missing comments in creation order, each with its likes and reports in the same
	 * transaction, and compare the existing ones.
	 *
	 * @param array<string,array<int,true>> $likes Legacy hex => user ID => true
	 * @param array<string,array<int,true>> $reports Legacy hex => user ID => true
	 * @param int|null $performerActor Resolves reports on deleted comments
	 * @param string $at Time of those resolutions, DB format
	 * @return int Comments inserted
	 */
	private function syncComments( array $likes, array $reports, ?int $performerActor, string $at ): int {
		$r = $this->report;
		$existing = [];
		foreach ( array_chunk( array_keys( $this->plan ), self::CHUNK ) as $chunk ) {
			$res = $this->dbw->newSelectQueryBuilder()
				->select( array_merge(
					[ 'gtp_id', 'gtp_legacy_id', 'gtp_seq', 'gtp_status_actor', 'gtp_status_timestamp' ],
					self::PLANNED_COLUMNS
				) )
				->from( self::POST )
				->where( [ 'gtp_legacy_id' => $chunk ] )
				->caller( __METHOD__ )
				->fetchResultSet();
			foreach ( $res as $row ) {
				$existing[(string)$row->gtp_legacy_id] = $row;
				$this->ids[(string)$row->gtp_legacy_id] = (int)$row->gtp_id;
			}
		}
		$taken = $this->takenFloors();
		$this->countExtraLegacyRows();

		$inserted = 0;
		$batch = 0;
		$done = 0;
		$apply = $this->mode === self::MODE_APPLY;
		foreach ( $this->plan as $hex => $p ) {
			$done++;
			if ( $apply && $batch === 0 ) {
				$this->dbw->startAtomic( __METHOD__ );
			}
			$want = $this->desiredRow( $p );
			$row = $existing[$hex] ?? null;
			if ( $row === null ) {
				if ( isset( $taken[$p['page']][$want['gtp_seq']] ) ) {
					$want['gtp_seq'] = max( array_keys( $taken[$p['page']] ) ) + 1;
					$r->add( 'floors', 'floors out of creation order (planned floor taken)' );
					$r->addId( 'floor taken', $hex );
				}
				$taken[$p['page']][$want['gtp_seq']] = $hex;
				$this->floors[$hex] = $want['gtp_seq'];
				if ( $apply ) {
					$this->ids[$hex] = $this->insertComment( $want, $hex );
					$this->insertAttitudes( $hex, $likes[$hex] ?? [], $reports[$hex] ?? [], $performerActor, $at );
					$this->insertedNow[$hex] = true;
					$inserted++;
					$r->add( 'comments', 'inserted' );
				} else {
					$this->ids[$hex] = "new:$hex";
					$r->add( 'comments', $this->mode === self::MODE_VERIFY ? 'missing in GlowThread' : 'would insert' );
					if ( $this->mode === self::MODE_VERIFY ) {
						$r->mismatch( "comment $hex is not imported" );
					}
				}
			} else {
				$this->floors[$hex] = (int)$row->gtp_seq;
				$this->compareComment( $hex, $row, $want );
			}
			if ( $apply && ++$batch >= $this->options->batchSize ) {
				$this->dbw->endAtomic( __METHOD__ );
				( $this->waitForReplication )();
				( $this->progress )( "comments: $done of " . count( $this->plan ) . " done, $inserted inserted" );
				$batch = 0;
			}
		}
		if ( $apply && $batch > 0 ) {
			$this->dbw->endAtomic( __METHOD__ );
			( $this->waitForReplication )();
		}
		return $inserted;
	}

	/**
	 * @return array<int,array<int,string|int>> Page => floor => legacy hex or gtp_id, for floors in use
	 */
	private function takenFloors(): array {
		$pages = array_unique( array_column( $this->plan, 'page' ) );
		$taken = [];
		foreach ( array_chunk( $pages, self::CHUNK ) as $chunk ) {
			$res = $this->dbw->newSelectQueryBuilder()
				->select( [ 'gtp_id', 'gtp_page', 'gtp_seq', 'gtp_legacy_id' ] )
				->from( self::POST )
				->where( [ 'gtp_page' => $chunk ] )
				->caller( __METHOD__ )
				->fetchResultSet();
			foreach ( $res as $row ) {
				$taken[(int)$row->gtp_page][(int)$row->gtp_seq] = $row->gtp_legacy_id === null
					? (int)$row->gtp_id : (string)$row->gtp_legacy_id;
			}
		}
		return $taken;
	}

	/**
	 * Imported comments whose FlowThread row is gone (erased in FlowThread after an earlier run).
	 * They are kept and listed.
	 */
	private function countExtraLegacyRows(): void {
		$pages = array_unique( array_column( $this->plan, 'page' ) );
		if ( $this->options->pageId !== null ) {
			$pages = [ $this->options->pageId ];
		}
		$query = $this->dbw->newSelectQueryBuilder()
			->select( 'gtp_legacy_id' )
			->from( self::POST )
			->where( $this->dbw->expr( 'gtp_legacy_id', '!=', null ) )
			->caller( __METHOD__ );
		if ( $this->options->pageId !== null ) {
			$query->andWhere( [ 'gtp_page' => $pages ] );
		}
		foreach ( $query->fetchFieldValues() as $hex ) {
			if ( !isset( $this->plan[(string)$hex] ) ) {
				$this->report->add( 'comments', 'imported earlier, no longer in FlowThread (kept)' );
				$this->report->addId( 'no longer in FlowThread', (string)$hex );
				if ( $this->mode === self::MODE_VERIFY ) {
					$this->report->mismatch( "comment $hex is imported but not in FlowThread" );
				}
			}
		}
	}

	/**
	 * Floors are handed out once, in creation order, and never change. Check that the imported
	 * comments of each page still climb in creation order (a floor taken by a GlowThread comment
	 * only moves a comment further up).
	 */
	private function checkFloors(): void {
		$last = [];
		$highest = 0;
		// Hex order is creation order
		$hexes = array_map( 'strval', array_keys( $this->floors ) );
		sort( $hexes, SORT_STRING );
		foreach ( $hexes as $hex ) {
			$floor = $this->floors[$hex];
			$page = $this->plan[$hex]['page'];
			$highest = max( $highest, $floor );
			$previous = $last[$page] ?? 0;
			$last[$page] = $floor;
			if ( $floor <= $previous ) {
				$this->report->add( 'floors', 'imported comments whose floor is not above the previous one' );
				$this->report->addId( 'floor out of order', $hex );
				if ( $this->mode === self::MODE_VERIFY ) {
					$this->report->mismatch( "comment $hex: floor $floor is not above the previous imported floor" );
				}
			}
		}
		$this->report->set( 'floors', 'highest floor', $highest );
	}

	/**
	 * The choices of the last import are in its log entry. A run with other choices would
	 * report every comment they touch as different, so say so first.
	 */
	private function checkRecordedDecisions(): void {
		$row = $this->dbw->newSelectQueryBuilder()
			->select( 'log_params' )
			->from( 'logging' )
			->where( [ 'log_type' => ModerationLogger::TYPE, 'log_action' => 'import' ] )
			->orderBy( 'log_id', 'DESC' )
			->limit( 1 )
			->caller( __METHOD__ )
			->fetchRow();
		if ( !$row ) {
			return;
		}
		$params = LogEntryBase::extractParams( $row->log_params );
		$recorded = json_decode( (string)( $params['5::options'] ?? '' ), true );
		if ( !is_array( $recorded ) ) {
			return;
		}
		$current = $this->decisions();
		$differ = [];
		foreach ( $current as $key => $value ) {
			if ( array_key_exists( $key, $recorded ) && $recorded[$key] !== $value ) {
				$differ[] = "$key (last import: " . json_encode( $recorded[$key] ) . ')';
			}
		}
		if ( !$differ ) {
			return;
		}
		$this->decisionsChanged = true;
		$message = 'Choices differ from the last import: ' . implode( ', ', $differ );
		$this->report->setInfo( 'WARNING', $message );
		if ( $this->mode === self::MODE_VERIFY ) {
			$this->report->mismatch( $message );
		}
	}

	/**
	 * @return array<string,mixed> The choices that shape the imported rows
	 */
	private function decisions(): array {
		$decisions = $this->options->toArray();
		unset( $decisions['pageId'], $decisions['update'], $decisions['batchSize'], $decisions['log'] );
		$decisions['server'] = $this->converter->getServer();
		return $decisions;
	}

	/**
	 * One glowthread/import entry with the number of imported comments and the choices, written
	 * when this run added comments, when an earlier run added them but stopped before its entry,
	 * or when --update applied new choices.
	 */
	private function writeLogEntry( UserIdentity $performer, int $inserted ): void {
		if ( $this->mode !== self::MODE_APPLY || !$this->options->log ) {
			return;
		}
		$total = (int)$this->dbw->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( self::POST )
			->where( $this->dbw->expr( 'gtp_legacy_id', '!=', null ) )
			->caller( __METHOD__ )
			->fetchField();
		$logged = (bool)$this->dbw->newSelectQueryBuilder()
			->select( 'log_id' )
			->from( 'logging' )
			->where( [ 'log_type' => ModerationLogger::TYPE, 'log_action' => 'import' ] )
			->limit( 1 )
			->caller( __METHOD__ )
			->fetchField();
		$newChoices = $this->decisionsChanged && $this->options->update;
		if ( $total === 0 || ( $inserted === 0 && $logged && !$newChoices ) ) {
			return;
		}
		$entry = new ManualLogEntry( ModerationLogger::TYPE, 'import' );
		$entry->setPerformer( $performer );
		$entry->setTarget( SpecialPage::getTitleFor( 'GlowThread' ) );
		$entry->setParameters( [ '4::count' => $total, '5::options' => json_encode( $this->decisions() ) ] );
		$entry->insert( $this->dbw );
		$this->report->add( 'run', 'glowthread/import log entries written' );
	}

	/**
	 * @return array<string,mixed> Column => value for the planned columns
	 */
	private function desiredRow( array $p ): array {
		$idOf = fn ( ?string $hex ) => $hex === null ? null : ( $this->ids[$hex] ?? "new:$hex" );
		return [
			'gtp_page' => $p['page'],
			'gtp_actor' => $this->actors[$p['author']] ?? 'new:' . $p['author'],
			'gtp_parent' => $idOf( $p['parent'] ),
			'gtp_reply_to' => $idOf( $p['replyTo'] ),
			'gtp_thread' => $idOf( $p['thread'] ),
			'gtp_depth' => $p['depth'],
			'gtp_seq' => $p['seq'],
			'gtp_text' => $p['text'],
			'gtp_created' => $p['created'],
			'gtp_status' => $p['status'],
			'gtp_hold_reason' => $p['hold'],
		];
	}

	private function insertComment( array $want, string $hex ): int {
		foreach ( [ 'gtp_actor', 'gtp_parent', 'gtp_reply_to', 'gtp_thread' ] as $column ) {
			if ( is_string( $want[$column] ) ) {
				throw new RuntimeException( "Cannot insert $hex: $column is not resolved" );
			}
		}
		$row = $want;
		$row['gtp_created'] = $this->dbw->timestamp( $want['gtp_created'] );
		$row += [
			'gtp_legacy_id' => $hex,
			'gtp_like_count' => 0,
			'gtp_reply_count' => 0,
			'gtp_open_report_count' => 0,
		];
		$this->dbw->newInsertQueryBuilder()
			->insertInto( self::POST )
			->row( $row )
			->caller( __METHOD__ )
			->execute();
		return $this->dbw->insertId();
	}

	/**
	 * The likes and reports of a comment that this run inserts. They are imported with their
	 * comment only, so a later run never adds back a like or report that was taken back in
	 * GlowThread (a user who removed a like, a user merge), and a run that stopped can continue.
	 *
	 * @param string $hex
	 * @param array<int,true> $likes User ID => true
	 * @param array<int,true> $reports User ID => true
	 * @param int|null $performerActor
	 * @param string $at DB format
	 */
	private function insertAttitudes(
		string $hex, array $likes, array $reports, ?int $performerActor, string $at
	): void {
		$post = $this->ids[$hex];
		$resolve = $this->resolvesReports( $this->plan[$hex]['status'] );
		$rows = [ self::REACTION => [], self::REPORT => [] ];
		// Likes and reports of user IDs that no longer exist are dropped (counted in syncLikes())
		foreach ( array_keys( $likes ) as $userId ) {
			if ( isset( $this->users[$userId] ) ) {
				$rows[self::REACTION][] = [
					'gtr_post' => $post, 'gtr_actor' => $this->actors['u:' . $userId], 'gtr_type' => self::LIKE,
					'gtr_timestamp' => null,
				];
			}
		}
		foreach ( array_keys( $reports ) as $userId ) {
			if ( isset( $this->users[$userId] ) ) {
				$rows[self::REPORT][] = [
					'gtrp_post' => $post,
					'gtrp_actor' => $this->actors['u:' . $userId],
					'gtrp_reason' => ReportReason::LEGACY,
					'gtrp_note' => null,
					'gtrp_created' => null,
				] + self::resolution( $resolve, $performerActor, $at );
			}
		}
		foreach ( [ self::REACTION => 'likes', self::REPORT => 'reports' ] as $table => $section ) {
			if ( $rows[$table] ) {
				$this->dbw->newInsertQueryBuilder()
					->insertInto( $table )
					->ignore()
					->rows( $rows[$table] )
					->caller( __METHOD__ )
					->execute();
				$this->report->add( $section, 'inserted', $this->dbw->affectedRows() );
			}
		}
	}

	/**
	 * Whether imported reports on a comment with this status are imported resolved. Nobody can
	 * review an erased comment: its reports are closed, as the erase closed them.
	 */
	private function resolvesReports( string $status ): bool {
		return $status === PostStatus::ERASED
			|| ( $status === PostStatus::DELETED && $this->options->reportsOnDeleted === 'resolved' );
	}

	/**
	 * @return array<string,mixed> The resolution columns of an imported report
	 */
	private static function resolution( bool $resolve, ?int $actor, string $at ): array {
		return [
			'gtrp_resolved_actor' => $resolve ? $actor : null,
			'gtrp_resolved_at' => $resolve ? $at : null,
			'gtrp_outcome' => $resolve ? ReportReason::OUTCOME_UPHELD : null,
		];
	}

	/**
	 * Compare an imported comment with the plan, and with $update rewrite what differs. Columns
	 * GlowThread decided since (keptColumns()) are kept in every mode, so the dry run predicts
	 * what a run does and verify does not report what a run keeps on purpose. The plan then
	 * takes the stored page and status, for the floors, pages, reports and visibility checks.
	 */
	private function compareComment( string $hex, \stdClass $row, array $want ): void {
		$r = $this->report;
		$have = [];
		foreach ( self::PLANNED_COLUMNS as $column ) {
			$have[$column] = $row->$column;
		}
		$have['gtp_created'] = ConvertibleTimestamp::convert( TS_MW, $have['gtp_created'] );
		$differs = [];
		foreach ( self::PLANNED_COLUMNS as $column ) {
			$a = $have[$column];
			$b = $want[$column];
			$same = ( $a === null || $b === null ) ? $a === $b : (string)$a === (string)$b;
			if ( !$same ) {
				$differs[] = $column;
			}
		}
		$kept = $this->keptColumns( $row );
		$this->plan[$hex]['page'] = (int)$row->gtp_page;
		if ( in_array( 'gtp_status', $kept, true ) ) {
			$this->plan[$hex]['status'] = (string)$row->gtp_status;
			$this->plan[$hex]['changedInGlowThread'] = true;
		}
		$keptDiffers = array_values( array_intersect( $differs, $kept ) );
		$differs = array_values( array_diff( $differs, $kept ) );
		if ( $keptDiffers ) {
			$r->add( 'comments', 'changed in GlowThread, kept' );
			$r->addId( 'changed in GlowThread, kept', $hex, implode( ' ', $keptDiffers ) );
		}
		if ( !$differs ) {
			if ( !$keptDiffers ) {
				$r->add( 'comments', 'already imported, unchanged' );
			}
			return;
		}
		$r->add( 'comments', 'already imported, differs from FlowThread now' );
		$r->addId( 'differs from FlowThread', $hex, implode( ' ', $differs ) );
		if ( $this->mode === self::MODE_VERIFY ) {
			$r->mismatch( "comment $hex differs: " . implode( ', ', $differs ) );
			return;
		}
		if ( $this->mode !== self::MODE_APPLY || !$this->options->update ) {
			return;
		}
		$set = [];
		foreach ( $differs as $column ) {
			if ( is_string( $want[$column] ) && str_starts_with( $want[$column], 'new:' ) ) {
				continue;
			}
			$set[$column] = $column === 'gtp_created' ? $this->dbw->timestamp( $want[$column] ) : $want[$column];
		}
		if ( $set ) {
			$this->dbw->newUpdateQueryBuilder()
				->update( self::POST )
				->set( $set )
				->where( [ 'gtp_id' => (int)$row->gtp_id ] )
				->caller( __METHOD__ )
				->execute();
			$r->add( 'comments', 'updated' );
		}
	}

	/**
	 * Columns of an imported comment that GlowThread decided after the import and that no run
	 * rewrites: all of an erased comment, and the status, hold reason and text of a comment whose
	 * status GlowThread changed. Every such change sets gtp_status_timestamp (see
	 * PostStore::changeStatus(), automatic holds leave gtp_status_actor null), and an undo puts
	 * back null only together with the imported status. The importer writes neither column.
	 * The page is never rewritten: FlowThread never gives a comment another page ID, GlowThread
	 * does when an undelete gives the page a new one (PostStore::movePage()).
	 *
	 * @return string[]
	 */
	private function keptColumns( \stdClass $row ): array {
		if ( (string)$row->gtp_status === PostStatus::ERASED ) {
			return self::PLANNED_COLUMNS;
		}
		if ( $row->gtp_status_actor !== null || $row->gtp_status_timestamp !== null ) {
			return [ 'gtp_page', 'gtp_status', 'gtp_hold_reason', 'gtp_text' ];
		}
		return [ 'gtp_page' ];
	}

	/**
	 * @param array<string,array<int,true>> $likes Legacy hex => user ID => true
	 */
	private function syncLikes( array $likes ): void {
		$r = $this->report;
		$want = [];
		foreach ( $likes as $hex => $userIds ) {
			foreach ( array_keys( $userIds ) as $userId ) {
				// Likes and reports of user IDs that no longer exist are dropped, whoever they were
				if ( !isset( $this->users[$userId] ) ) {
					$r->add( 'likes', 'skipped: user missing' );
					continue;
				}
				$want[] = [ $hex, $this->actors['u:' . $userId] ?? null ];
			}
		}
		$r->set( 'likes', 'likes to import', count( $want ) );
		$have = $this->existingRows(
			self::REACTION, 'gtr_post', [ 'gtr_post', 'gtr_actor', 'gtr_timestamp' ], [ 'gtr_type' => self::LIKE ]
		);
		foreach ( $want as [ $hex, $actor ] ) {
			$post = $this->ids[$hex];
			$key = "$post:$actor";
			if ( isset( $have[$key] ) ) {
				$have[$key]->wanted = true;
				// Likes this run inserted with their comment are counted there
				if ( !isset( $this->insertedNow[$hex] ) ) {
					$r->add( 'likes', 'already there' );
				}
			} else {
				$this->countMissing( 'likes', 'like', $hex, $post );
			}
		}
		$stale = [];
		foreach ( $have as $row ) {
			if ( empty( $row->wanted ) && $row->gtr_timestamp === null ) {
				$stale[] = [
					'gtr_post' => (int)$row->gtr_post, 'gtr_actor' => (int)$row->gtr_actor, 'gtr_type' => self::LIKE,
				];
			}
		}
		$this->removeStale( self::REACTION, $stale, 'likes' );
	}

	/**
	 * @param array<string,array<int,true>> $reports Legacy hex => user ID => true
	 * @param int|null $performerActor
	 * @param string $now TS_MW
	 */
	private function syncReports( array $reports, ?int $performerActor, string $now ): void {
		$r = $this->report;
		$have = $this->existingRows(
			self::REPORT, 'gtrp_post',
			[ 'gtrp_id', 'gtrp_post', 'gtrp_actor', 'gtrp_reason', 'gtrp_resolved_at', 'gtrp_outcome' ], []
		);
		$at = $this->dbw->timestamp( $now );
		// A moderator's decision is kept: reports on a comment whose status changed in GlowThread,
		// and reports dismissed there (the importer resolves as upheld only)
		$changed = [];
		foreach ( $this->plan as $hex => $p ) {
			if ( !empty( $p['changedInGlowThread'] ) && is_int( $this->ids[$hex] ?? null ) ) {
				$changed[$this->ids[$hex]] = true;
			}
		}
		$decided = static fn ( \stdClass $row ) => isset( $changed[$row->gtrp_post] )
			|| $row->gtrp_outcome === ReportReason::OUTCOME_DISMISSED;
		$wanted = 0;
		foreach ( $reports as $hex => $userIds ) {
			$resolve = $this->resolvesReports( $this->plan[$hex]['status'] );
			foreach ( array_keys( $userIds ) as $userId ) {
				if ( !isset( $this->users[$userId] ) ) {
					$r->add( 'reports', 'skipped: user missing' );
					continue;
				}
				$wanted++;
				$r->add( 'reports', $resolve ? 'to import resolved (comment deleted)' : 'to import open' );
				$actor = $this->actors['u:' . $userId] ?? null;
				$post = $this->ids[$hex];
				$key = "$post:$actor";
				if ( isset( $have[$key] ) ) {
					$row = $have[$key];
					$row->wanted = true;
					if ( $row->gtrp_reason !== ReportReason::LEGACY ) {
						$r->add( 'reports', 'already reported in GlowThread, kept' );
					} elseif ( ( $row->gtrp_resolved_at !== null ) !== $resolve ) {
						if ( $decided( $row ) ) {
							$r->add( 'reports', 'changed in GlowThread, kept' );
							continue;
						}
						$r->add( 'reports', 'imported, resolution differs from the choice now' );
						$r->addId( 'report resolution differs', $hex );
						if ( $this->mode === self::MODE_VERIFY ) {
							$r->mismatch( "report on $hex: resolution differs" );
						} elseif ( $this->mode === self::MODE_APPLY && $this->options->update ) {
							$this->dbw->newUpdateQueryBuilder()
								->update( self::REPORT )
								->set( self::resolution( $resolve, $performerActor, $at ) )
								->where( [ 'gtrp_id' => (int)$row->gtrp_id ] )
								->caller( __METHOD__ )
								->execute();
							$r->add( 'reports', 'resolution updated' );
							$this->wrote();
						}
					} elseif ( !isset( $this->insertedNow[$hex] ) ) {
						// Reports this run inserted with their comment are counted there
						$r->add( 'reports', 'already there' );
					}
					continue;
				}
				$this->countMissing( 'reports', 'report', $hex, $post );
			}
		}
		$r->set( 'reports', 'reports to import', $wanted );
		$stale = [];
		foreach ( $have as $row ) {
			if ( empty( $row->wanted ) && $row->gtrp_reason === ReportReason::LEGACY ) {
				if ( $decided( $row ) ) {
					// Not removed either: its moderation log entry names it
					$r->add( 'reports', 'changed in GlowThread, kept' );
					continue;
				}
				$stale[] = [ 'gtrp_id' => (int)$row->gtrp_id ];
			}
		}
		$this->removeStale( self::REPORT, $stale, 'reports' );
	}

	/**
	 * Rows of a table on the imported comments, keyed "post:actor".
	 *
	 * @return array<string,\stdClass>
	 */
	private function existingRows( string $table, string $postColumn, array $fields, array $where ): array {
		$posts = array_filter( $this->ids, 'is_int' );
		$rows = [];
		foreach ( array_chunk( array_values( $posts ), self::CHUNK ) as $chunk ) {
			$res = $this->dbw->newSelectQueryBuilder()
				->select( $fields )
				->from( $table )
				->where( array_merge( $where, [ $postColumn => $chunk ] ) )
				->caller( __METHOD__ )
				->fetchResultSet();
			$actorColumn = $table === self::REPORT ? 'gtrp_actor' : 'gtr_actor';
			foreach ( $res as $row ) {
				$row->$postColumn = (int)$row->$postColumn;
				if ( isset( $row->gtrp_reason ) ) {
					$row->gtrp_reason = (string)$row->gtrp_reason;
				}
				$rows[$row->$postColumn . ':' . (int)$row->$actorColumn] = $row;
			}
		}
		return $rows;
	}

	/**
	 * A like or report that FlowThread has and GlowThread does not. On a comment still to import it
	 * comes with the comment (insertAttitudes()). On a comment imported earlier it is never added,
	 * in any mode: it may have been taken back in GlowThread, so verify only counts it.
	 *
	 * @param string $section "likes" or "reports"
	 * @param string $what "like" or "report"
	 * @param string $hex
	 * @param int|string $post gtp_id, or "new:<hex>" for a comment not imported yet
	 */
	private function countMissing( string $section, string $what, string $hex, $post ): void {
		if ( is_int( $post ) ) {
			$this->report->add( $section, 'not added: comment imported earlier' );
		} elseif ( $this->mode === self::MODE_VERIFY ) {
			$this->report->add( $section, 'missing in GlowThread' );
			$this->report->mismatch( "$what on $hex missing" );
		} else {
			$this->report->add( $section, 'would insert' );
		}
	}

	/**
	 * Imported likes or reports that FlowThread no longer has: removed with $update, else listed.
	 */
	private function removeStale( string $table, array $conds, string $section ): void {
		if ( !$conds ) {
			return;
		}
		$this->report->add( $section, 'imported earlier, no longer in FlowThread', count( $conds ) );
		if ( $this->mode === self::MODE_VERIFY ) {
			$this->report->mismatch( count( $conds ) . " imported $section are no longer in FlowThread" );
		}
		if ( $this->mode !== self::MODE_APPLY || !$this->options->update ) {
			return;
		}
		foreach ( $conds as $cond ) {
			$this->dbw->newDeleteQueryBuilder()
				->deleteFrom( $table )
				->where( $cond )
				->caller( __METHOD__ )
				->execute();
		}
		$this->report->add( $section, 'removed', count( $conds ) );
		( $this->waitForReplication )();
	}

	/**
	 * Make like, open-report and reply counters match the rows of every imported comment.
	 */
	private function syncCounters(): void {
		$posts = array_values( array_filter( $this->ids, 'is_int' ) );
		$fixed = 0;
		foreach ( array_chunk( $posts, self::CHUNK ) as $chunk ) {
			$counts = [];
			$sources = [
				'gtp_like_count' => $this->dbw->newSelectQueryBuilder()
					->select( [ 'post' => 'gtr_post', 'n' => 'COUNT(*)' ] )
					->from( self::REACTION )
					->where( [ 'gtr_post' => $chunk, 'gtr_type' => self::LIKE ] )
					->groupBy( 'gtr_post' ),
				'gtp_open_report_count' => $this->dbw->newSelectQueryBuilder()
					->select( [ 'post' => 'gtrp_post', 'n' => 'COUNT(*)' ] )
					->from( self::REPORT )
					->where( [ 'gtrp_post' => $chunk, 'gtrp_resolved_at' => null ] )
					->groupBy( 'gtrp_post' ),
				'gtp_reply_count' => $this->dbw->newSelectQueryBuilder()
					->select( [ 'post' => 'gtp_parent', 'n' => 'COUNT(*)' ] )
					->from( self::POST )
					->where( [ 'gtp_parent' => $chunk, 'gtp_status' => PostStatus::VISIBLE ] )
					->groupBy( 'gtp_parent' ),
			];
			foreach ( $sources as $column => $query ) {
				foreach ( $query->caller( __METHOD__ )->fetchResultSet() as $row ) {
					$counts[(int)$row->post][$column] = (int)$row->n;
				}
			}
			$res = $this->dbw->newSelectQueryBuilder()
				->select( [ 'gtp_id', 'gtp_like_count', 'gtp_open_report_count', 'gtp_reply_count' ] )
				->from( self::POST )
				->where( [ 'gtp_id' => $chunk ] )
				->caller( __METHOD__ )
				->fetchResultSet();
			foreach ( $res as $row ) {
				$set = [];
				foreach ( array_keys( $sources ) as $column ) {
					$want = $counts[(int)$row->gtp_id][$column] ?? 0;
					if ( (int)$row->$column !== $want ) {
						$set[$column] = $want;
					}
				}
				if ( !$set ) {
					continue;
				}
				$fixed++;
				if ( $this->mode === self::MODE_APPLY ) {
					$this->dbw->newUpdateQueryBuilder()
						->update( self::POST )
						->set( $set )
						->where( [ 'gtp_id' => (int)$row->gtp_id ] )
						->caller( __METHOD__ )
						->execute();
				} elseif ( $this->mode === self::MODE_VERIFY ) {
					$this->report->mismatch(
						"comment {$row->gtp_id}: counters differ from rows: " . implode( ', ', array_keys( $set ) )
					);
				}
			}
			if ( $this->mode === self::MODE_APPLY ) {
				( $this->waitForReplication )();
			}
		}
		$this->report->set( 'counters', $this->mode === self::MODE_APPLY ? 'comments with counters set'
			: 'comments whose counters differ from their rows', $fixed );
	}

	/**
	 * Floor counters and page states.
	 *
	 * @param array<int,int> $controls Page => FlowThread control status
	 */
	private function syncPages( array $controls ): void {
		$r = $this->report;
		$maxSeq = [];
		foreach ( $this->plan as $p ) {
			$maxSeq[$p['page']] = max( $maxSeq[$p['page']] ?? 0, $p['seq'] );
		}
		// Pages GlowThread has closed: FlowThread may have reopened them since an earlier run
		$closed = $this->dbw->newSelectQueryBuilder()
			->select( 'gtpg_page' )
			->from( PageStateStore::TABLE )
			->where( $this->dbw->expr( 'gtpg_state', '!=', PageState::OPEN ) )
			->caller( __METHOD__ );
		if ( $this->options->pageId !== null ) {
			$closed->andWhere( [ 'gtpg_page' => $this->options->pageId ] );
		}
		$pages = array_values( array_unique( array_merge(
			array_keys( $maxSeq ), array_keys( $controls ), array_map( 'intval', $closed->fetchFieldValues() )
		) ) );
		$this->countPageKinds( array_keys( $maxSeq ) );
		foreach ( array_chunk( $pages, self::CHUNK ) as $chunk ) {
			$stored = $this->dbw->newSelectQueryBuilder()
				->select( [ 'page' => 'gtp_page', 'n' => 'MAX(gtp_seq)' ] )
				->from( self::POST )
				->where( [ 'gtp_page' => $chunk ] )
				->groupBy( 'gtp_page' )
				->caller( __METHOD__ )
				->fetchResultSet();
			foreach ( $stored as $row ) {
				$maxSeq[(int)$row->page] = max( $maxSeq[(int)$row->page] ?? 0, (int)$row->n );
			}
		}
		foreach ( $pages as $page ) {
			$state = $this->pageStateStore->get( $page, true );
			$seq = $maxSeq[$page] ?? 0;
			if ( $state['seq'] < $seq ) {
				if ( $this->mode === self::MODE_APPLY ) {
					$this->pageStateStore->raiseSeqFloor( $this->dbw, $page, $seq );
					$r->add( 'pages', 'floor counters raised' );
					$this->wrote();
				} elseif ( $this->mode === self::MODE_VERIFY ) {
					$r->mismatch( "page $page: floor counter {$state['seq']} is below floor $seq" );
				} else {
					$r->add( 'pages', 'floor counters to raise' );
				}
			}
			$this->pageStates[$page] = $state['state'];
			$control = $controls[$page] ?? 0;
			if ( $control === 0 && $state['state'] === PageState::OPEN ) {
				continue;
			}
			$r->add( 'pages', "FlowThread control status $control" );
			$want = $this->wantedState( $control );
			if ( $want === null ) {
				$r->addId( 'unknown control status', (string)$page, (string)$control );
				continue;
			}
			if ( $state['state'] === $want ) {
				$r->add( 'pages', "already $want" );
				continue;
			}
			// A state someone set in GlowThread is kept, in every mode and with --update too, so the
			// dry run predicts what a run does and verify does not report what a run keeps on purpose
			if ( $this->isStateChangedInGlowThread( $page ) ) {
				$r->add( 'pages', 'state changed in GlowThread, kept' );
				continue;
			}
			$this->pageStates[$page] = $want;
			if ( $this->mode === self::MODE_VERIFY ) {
				$r->mismatch( "page $page: state {$state['state']}, FlowThread says $want" );
			} elseif ( $this->mode === self::MODE_DRY_RUN ) {
				$r->add( 'pages', "would set $want" );
			} else {
				$this->setPageState( $page, $want );
				$r->add( 'pages', "set $want" );
				$this->wrote();
			}
		}
		( $this->waitForReplication )();
	}

	/**
	 * Comments on pages that no longer exist or that are now redirects.
	 *
	 * @param int[] $pages
	 */
	private function countPageKinds( array $pages ): void {
		$found = [];
		foreach ( array_chunk( $pages, self::CHUNK ) as $chunk ) {
			$res = $this->dbw->newSelectQueryBuilder()
				->select( [ 'page_id', 'page_is_redirect' ] )
				->from( 'page' )
				->where( [ 'page_id' => $chunk ] )
				->caller( __METHOD__ )
				->fetchResultSet();
			foreach ( $res as $row ) {
				$found[(int)$row->page_id] = (bool)$row->page_is_redirect;
			}
		}
		foreach ( $this->plan as $p ) {
			if ( !isset( $found[$p['page']] ) ) {
				$this->report->add( 'pages', 'comments on pages that do not exist' );
				$this->report->addId( 'on a page that does not exist', $p['hex'] );
			} elseif ( $found[$p['page']] ) {
				$this->report->add( 'pages', 'comments on redirects' );
			}
		}
	}

	/**
	 * The GlowThread page state for a FlowThread control status, or null for an unknown status.
	 * FlowThread kept showing the existing comments on pages it closed, it only refused new ones,
	 * so both kinds of closed page are locked unless the options say otherwise.
	 */
	private function wantedState( int $control ): ?string {
		return match ( $control ) {
			0 => PageState::OPEN,
			LegacySource::CONTROL_DISABLED => $this->options->disabledPages,
			LegacySource::CONTROL_OPTED_OUT => $this->options->optedOut,
			default => null,
		};
	}

	/**
	 * Whether a moderator changed the page's comment state in GlowThread: a glowthread enable,
	 * lock or disable entry exists for the page. The importer writes no such entry, it attributes
	 * the states it sets to FlowThread's own entries (see setPageState()).
	 */
	private function isStateChangedInGlowThread( int $page ): bool {
		return (bool)$this->dbw->newSelectQueryBuilder()
			->select( '1' )
			->from( 'logging' )
			->where( [
				'log_type' => ModerationLogger::TYPE,
				'log_action' => [ 'enable', 'lock', 'disable' ],
				'log_page' => $page,
			] )
			->limit( 1 )
			->caller( __METHOD__ )
			->fetchField();
	}

	/**
	 * Set a page state, attributed to the latest FlowThread "disable" (or "enable") log entry of
	 * that page if there is one.
	 */
	private function setPageState( int $page, string $state ): void {
		$action = $state === PageState::OPEN ? 'enable' : 'disable';
		$log = $this->dbw->newSelectQueryBuilder()
			->select( [ 'log_actor', 'log_timestamp' ] )
			->from( 'logging' )
			->where( [ 'log_type' => LegacyLogType::TYPE, 'log_action' => $action, 'log_page' => $page ] )
			->orderBy( 'log_timestamp', 'DESC' )
			->limit( 1 )
			->caller( __METHOD__ )
			->fetchRow();
		$this->pageStateStore->raiseSeqFloor( $this->dbw, $page, 0 );
		$this->dbw->newUpdateQueryBuilder()
			->update( PageStateStore::TABLE )
			->set( [
				'gtpg_state' => $state,
				'gtpg_actor' => $log ? (int)$log->log_actor : null,
				'gtpg_timestamp' => $log ? $this->dbw->timestamp( $log->log_timestamp ) : null,
			] )
			->where( [ 'gtpg_page' => $page ] )
			->caller( __METHOD__ )
			->execute();
		// The state is cached (PageStateStore), for example from a run before a rollback
		$this->pageStateStore->invalidate( $this->dbw, $page );
	}

	/**
	 * Comments readers see, per the planned statuses and page states and per FlowThread (which
	 * showed the existing comments on every page, closed ones too). Equal unless an option
	 * changes it, for example closed pages imported as disabled. In verify mode the stored rows
	 * and page states must give the planned result too.
	 */
	private function checkVisibility(): void {
		$r = $this->report;
		$flowThread = 0;
		$planned = 0;
		foreach ( $this->plan as $p ) {
			$flowThread += (int)$p['flowThreadShown'];
			$shown = $p['status'] === PostStatus::VISIBLE
				&& ( $this->pageStates[$p['page']] ?? PageState::OPEN ) !== PageState::DISABLED
				&& ( $p['depth'] === 0 || (
					$this->plan[$p['parent']]['status'] === PostStatus::VISIBLE
					&& $this->plan[$p['thread']]['status'] === PostStatus::VISIBLE
				) );
			$planned += (int)$shown;
		}
		$r->set( 'visibility', 'comments readers see in FlowThread', $flowThread );
		$r->set( 'visibility', 'comments readers see in GlowThread (planned)', $planned );
		if ( $this->mode === self::MODE_DRY_RUN ) {
			return;
		}
		$status = [];
		$place = [];
		$pageOf = [];
		$posts = array_values( array_filter( $this->ids, 'is_int' ) );
		foreach ( array_chunk( $posts, self::CHUNK ) as $chunk ) {
			$res = $this->dbw->newSelectQueryBuilder()
				->select( [ 'gtp_id', 'gtp_status', 'gtp_parent', 'gtp_thread', 'gtp_page' ] )
				->from( self::POST )
				->where( [ 'gtp_id' => $chunk ] )
				->caller( __METHOD__ )
				->fetchResultSet();
			foreach ( $res as $row ) {
				$status[(int)$row->gtp_id] = (string)$row->gtp_status;
				$place[(int)$row->gtp_id] = [ $row->gtp_parent, $row->gtp_thread ];
				$pageOf[(int)$row->gtp_id] = (int)$row->gtp_page;
			}
		}
		$disabled = [];
		foreach ( array_chunk( array_values( array_unique( $pageOf ) ), self::CHUNK ) as $chunk ) {
			$res = $this->dbw->newSelectQueryBuilder()
				->select( 'gtpg_page' )
				->from( PageStateStore::TABLE )
				->where( [ 'gtpg_page' => $chunk, 'gtpg_state' => PageState::DISABLED ] )
				->caller( __METHOD__ )
				->fetchFieldValues();
			foreach ( $res as $page ) {
				$disabled[(int)$page] = true;
			}
		}
		$stored = 0;
		foreach ( $place as $id => [ $parent, $thread ] ) {
			$stored += (int)( $status[$id] === PostStatus::VISIBLE && !isset( $disabled[$pageOf[$id]] )
				&& ( $parent === null || (
					( $status[(int)$parent] ?? null ) === PostStatus::VISIBLE
					&& ( $status[(int)$thread] ?? null ) === PostStatus::VISIBLE
				) ) );
		}
		$r->set( 'visibility', 'comments readers see in GlowThread (rows)', $stored );
		if ( $this->mode === self::MODE_VERIFY && $stored !== $planned ) {
			$r->mismatch( "readers would see $stored imported comments, the plan says $planned" );
		}
	}
}
