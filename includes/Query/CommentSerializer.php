<?php

namespace MediaWiki\Extension\GlowThread\Query;

use Closure;
use MediaWiki\Block\DatabaseBlockStore;
use MediaWiki\Extension\GlowThread\Log\ModerationLogger;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Model\PostStatus;
use MediaWiki\Extension\GlowThread\Store\PostStore;
use MediaWiki\Extension\GlowThread\Store\ReactionStore;
use MediaWiki\Extension\GlowThread\Store\ReportStore;
use MediaWiki\Extension\GlowThread\Text\MentionResolver;
use MediaWiki\Extension\GlowThread\Text\TextProcessor;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Turns comments into the JSON shape documented in docs/api.md, with everything a viewer
 * may see and nothing more. All lookups are done in bulk for the whole response.
 */
class CommentSerializer {

	private ?DatabaseBlockStore $blockStore = null;
	private ?ModerationLogger $moderationLogger = null;
	/** @var array What the last serialization looked up: actor IDs, actors, texts, mention map */
	private array $lastLookups = [ [], [], [], [] ];

	/**
	 * @param TextProcessor $textProcessor
	 * @param MentionResolver $mentionResolver
	 * @param ActorInfoLookup $actorInfoLookup
	 * @param PostStore $postStore
	 * @param ReactionStore $reactionStore
	 * @param ReportStore $reportStore
	 * @param Closure|null $blockStoreProvider Returns the DatabaseBlockStore. Only moderators
	 *   need it, so it is not made for other readers. Null: no block states.
	 * @param Closure|null $moderationLoggerProvider Returns the ModerationLogger, for moderators
	 *   too. Null: no statuses before deletion.
	 */
	public function __construct(
		private readonly TextProcessor $textProcessor,
		private readonly MentionResolver $mentionResolver,
		private readonly ActorInfoLookup $actorInfoLookup,
		private readonly PostStore $postStore,
		private readonly ReactionStore $reactionStore,
		private readonly ReportStore $reportStore,
		private readonly ?Closure $blockStoreProvider = null,
		private readonly ?Closure $moderationLoggerProvider = null
	) {
	}

	private function getBlockStore(): ?DatabaseBlockStore {
		if ( $this->blockStoreProvider ) {
			$this->blockStore ??= ( $this->blockStoreProvider )();
		}
		return $this->blockStore;
	}

	private function getModerationLogger(): ?ModerationLogger {
		if ( $this->moderationLoggerProvider ) {
			$this->moderationLogger ??= ( $this->moderationLoggerProvider )();
		}
		return $this->moderationLogger;
	}

	/**
	 * Serialize comments without nesting.
	 *
	 * @param PostRecord[] $posts
	 * @param Viewer $viewer
	 * @param array<int,array{canPost:bool,canReact:bool,canReport:bool}> $pageFlags Page ID => what the
	 *   viewer may do on that page (missing pages: nothing)
	 * @return array<int,array<string,mixed>> Keyed by comment ID
	 */
	public function serializeAll( array $posts, Viewer $viewer, array $pageFlags = [] ): array {
		return $this->serializeAllWithLoaded( $posts, $viewer, $pageFlags, [] );
	}

	/**
	 * serializeAll(), told which other comments the caller has loaded in this request, such as
	 * the parent of replies: replies to them need no lookup of their target.
	 *
	 * @param PostRecord[] $posts
	 * @param Viewer $viewer
	 * @param array<int,array{canPost:bool,canReact:bool,canReport:bool}> $pageFlags As in serializeAll()
	 * @param PostRecord[] $loaded
	 * @return array<int,array<string,mixed>> Keyed by comment ID
	 */
	public function serializeAllWithLoaded( array $posts, Viewer $viewer, array $pageFlags, array $loaded ): array {
		$this->lastLookups = [ [], [], [], [] ];
		if ( !$posts ) {
			return [];
		}
		$ids = [];
		$actorIds = [];
		$texts = [];
		$replyToIds = [];
		foreach ( $posts as $post ) {
			$ids[] = $post->getId();
			$actorIds[] = $post->getActorId();
			if ( $post->getReplyToId() ) {
				$replyToIds[] = $post->getReplyToId();
			}
			if ( $viewer->isModerator && $post->getStatusActorId() ) {
				$actorIds[] = $post->getStatusActorId();
			}
			if ( !$post->isErased() ) {
				$texts[] = $post->getText();
			}
		}
		$byId = [];
		foreach ( $posts as $post ) {
			$byId[$post->getId()] = $post;
		}
		$replyTos = $byId;
		foreach ( $loaded as $record ) {
			$replyTos[$record->getId()] ??= $record;
		}
		$replyTos += $this->postStore->getByIds( array_diff( $replyToIds, array_keys( $replyTos ) ) );
		foreach ( $replyToIds as $rid ) {
			if ( isset( $replyTos[$rid] ) ) {
				$actorIds[] = $replyTos[$rid]->getActorId();
			}
		}
		$actors = $this->actorInfoLookup->getInfo( $actorIds );
		$existing = $this->mentionResolver->existenceMapForTexts( $texts );
		$this->lastLookups = [ $actorIds, $actors, $texts, $existing ];

		$db = $this->postStore->getReplica();
		$liked = [];
		$reported = [];
		if ( $viewer->actorId ) {
			$liked = array_flip(
				$this->reactionStore->getReactedPostIds( $db, $viewer->actorId, $ids, ReactionStore::TYPE_LIKE )
			);
			$reported = array_flip( $this->reportStore->getReportedPostIds( $db, $viewer->actorId, $ids ) );
		}
		$reasonCounts = [];
		$blocked = [];
		$deletedFrom = [];
		if ( $viewer->isModerator ) {
			$blocked = $this->getBlockStates( array_map( static fn ( $p ) => $p->getActorId(), $posts ), $actors );
			$reportedIds = [];
			$deletedAt = [];
			foreach ( $posts as $post ) {
				if ( $post->getOpenReportCount() > 0 ) {
					$reportedIds[] = $post->getId();
				}
				if ( $post->isDeleted() && $post->getStatusTimestamp() !== null ) {
					$deletedAt[$post->getId()] = $post->getStatusTimestamp();
				}
			}
			$reasonCounts = $this->reportStore->getOpenReasonCounts( $db, $reportedIds );
			$deletedFrom = $this->getModerationLogger()?->getStatusesBeforeDeletion( $db, $deletedAt ) ?? [];
		}

		$out = [];
		foreach ( $posts as $post ) {
			$id = $post->getId();
			$flags = $pageFlags[$post->getPageId()]
				?? [ 'canPost' => false, 'canReact' => false, 'canReport' => false ];
			$own = $viewer->isOwn( $post );
			$visible = $post->isVisible();
			$author = $this->formatAuthor(
				$actors[$post->getActorId()] ?? null,
				$viewer,
				$post->isErased() && !$viewer->canErase
			);
			if ( $viewer->isModerator ) {
				// Facts that help judge the author, for moderators: account age and blocks
				$info = $author['hidden'] ? null : ( $actors[$post->getActorId()] ?? null );
				$author['registration'] = $info ? self::iso( $info['registration'] ) : null;
				$author['blocked'] = $info ? ( $blocked[$post->getActorId()] ?? null ) : null;
			}
			$data = [
				'id' => $id,
				'seq' => $post->getSeq(),
				'pageId' => $post->getPageId(),
				'parentId' => $post->getParentId(),
				'threadId' => $post->getThreadId(),
				'depth' => $post->getDepth(),
				'status' => $post->getStatus(),
				'author' => $author,
				'created' => self::iso( $post->getCreated() ),
				'edited' => self::iso( $post->getEdited() ),
				'segments' => $post->isErased()
					? []
					: $this->textProcessor->toSegments( $post->getText(), $existing ),
				'likes' => $post->getLikeCount(),
				'pinned' => $post->isPinned(),
				'replyTo' => null,
				'viewer' => [
					'own' => $own,
					'liked' => isset( $liked[$id] ),
					'reported' => isset( $reported[$id] ),
					'canReply' => $flags['canPost'] && ( $visible || ( $post->isHeld() && $own ) ),
					'canLike' => $flags['canReact'] && $visible && !$own,
					'canReport' => $flags['canReport'] && $visible && !$own && !isset( $reported[$id] ),
					'canDelete' => $own && !$viewer->isAnon()
						&& in_array( $post->getStatus(), [ PostStatus::VISIBLE, PostStatus::HELD ], true ),
				],
			];
			$replyToId = $post->getReplyToId();
			if ( $replyToId !== null ) {
				$target = $replyTos[$replyToId] ?? null;
				$canSee = $target !== null && $viewer->canSeeStatus( $target );
				$data['replyTo'] = [
					'id' => $replyToId,
					'seq' => $canSee ? $target->getSeq() : null,
					'visible' => $canSee,
					'author' => $canSee
						? $this->formatAuthor(
							$actors[$target->getActorId()] ?? null,
							$viewer,
							$target->isErased() && !$viewer->canErase
						)
						: null,
				];
			}
			if ( $viewer->isModerator ) {
				$reasons = [];
				foreach ( $reasonCounts[$id] ?? [] as $reason => $count ) {
					$reasons[] = [ 'reason' => $reason, 'count' => $count ];
				}
				$data['mod'] = [
					'holdReasons' => $post->getHoldReasons(),
					'holdMatches' => $post->getHoldMatches(),
					'openReports' => $post->getOpenReportCount(),
					'reportReasons' => $reasons,
					'statusBy' => $post->getStatusActorId()
						? $this->formatAuthor( $actors[$post->getStatusActorId()] ?? null, $viewer, false )
						: null,
					'statusAt' => self::iso( $post->getStatusTimestamp() ),
					// Deleted comments: the status before the deletion, which restore goes back to
					'deletedFrom' => $deletedFrom[$id] ?? null,
					'pinnedAt' => self::iso( $post->getPinned() ),
					'legacyId' => $post->getLegacyId(),
				];
			}
			$out[$id] = $data;
		}
		return $out;
	}

	/**
	 * The users the last serialization depends on without naming them, for ListCache to check:
	 * 'hidden', the user IDs of authors hidden by a hideuser block, and 'missing', the user IDs of
	 * mentions that name nobody (no account, or a hidden one). From what it looked up already.
	 *
	 * @return array{hidden:int[],missing:int[]}
	 */
	public function getLastUsers(): array {
		[ $actorIds, $actors, $texts, $existing ] = $this->lastLookups;
		$users = [ 'hidden' => [], 'missing' => [] ];
		foreach ( $actorIds as $actorId ) {
			$info = $actors[$actorId] ?? null;
			if ( $info && $info['hidden'] && $info['userId'] ) {
				$users['hidden'][] = $info['userId'];
			}
		}
		foreach ( $texts as $text ) {
			foreach ( $this->textProcessor->extractMentionIds( $text ) as $mentionId ) {
				if ( !isset( $existing[$mentionId] ) ) {
					$users['missing'][] = $mentionId;
				}
			}
		}
		return array_map( static fn ( $ids ) => array_values( array_unique( $ids ) ), $users );
	}

	/**
	 * Active blocks of the comment authors, in two queries at most (accounts, IP addresses).
	 *
	 * @param int[] $actorIds
	 * @param array $actors From ActorInfoLookup
	 * @return array<int,string> Actor ID => 'sitewide' or 'partial', for blocked authors only
	 */
	private function getBlockStates( array $actorIds, array $actors ): array {
		$blockStore = $this->getBlockStore();
		if ( !$blockStore ) {
			return [];
		}
		$byUserId = [];
		$byAddress = [];
		foreach ( array_unique( $actorIds ) as $actorId ) {
			$info = $actors[$actorId] ?? null;
			if ( !$info ) {
				continue;
			} elseif ( $info['userId'] ) {
				$byUserId[$info['userId']] = $actorId;
			} else {
				$byAddress[$info['name']] = $actorId;
			}
		}
		$blocks = [];
		if ( $byUserId ) {
			$blocks = $blockStore->newListFromConds( [ 'bt_user' => array_keys( $byUserId ) ] );
		}
		if ( $byAddress ) {
			$blocks = array_merge(
				$blocks,
				// Not autoblocks: they would tell which address a blocked account used
				$blockStore->newListFromConds( [
					'bt_address' => array_map( 'strval', array_keys( $byAddress ) ),
					'bt_auto' => 0,
				] )
			);
		}
		$states = [];
		foreach ( $blocks as $block ) {
			$target = $block->getTargetUserIdentity();
			$actorId = $target && $target->getId()
				? ( $byUserId[$target->getId()] ?? null )
				: ( $byAddress[$block->getTargetName()] ?? null );
			if ( $actorId !== null && ( $states[$actorId] ?? null ) !== 'sitewide' ) {
				$states[$actorId] = $block->isSitewide() ? 'sitewide' : 'partial';
			}
		}
		return $states;
	}

	/**
	 * @param array|null $info From ActorInfoLookup
	 * @param Viewer $viewer
	 * @param bool $forceHidden Hide even from viewers who could otherwise see the name
	 * @return array{name:?string,id:int,anon:bool,temp:bool,hidden:bool}
	 */
	public function formatAuthor( ?array $info, Viewer $viewer, bool $forceHidden ): array {
		if ( $info === null || $forceHidden || ( $info['hidden'] && !$viewer->canSeeHiddenUsers ) ) {
			return [ 'name' => null, 'id' => 0, 'anon' => false, 'temp' => false, 'hidden' => true ];
		}
		return [
			'name' => $info['name'],
			'id' => $info['userId'],
			'anon' => $info['anon'],
			'temp' => $info['temp'],
			'hidden' => false,
		];
	}

	public static function iso( ?string $timestamp ): ?string {
		if ( $timestamp === null ) {
			return null;
		}
		// The common case, a TS_MW time from a PostRecord, without ConvertibleTimestamp's parsing
		if ( PostRecord::isPlainTimestamp( $timestamp ) ) {
			return substr( $timestamp, 0, 4 ) . '-' . substr( $timestamp, 4, 2 ) . '-'
				. substr( $timestamp, 6, 2 ) . 'T' . substr( $timestamp, 8, 2 ) . ':'
				. substr( $timestamp, 10, 2 ) . ':' . substr( $timestamp, 12, 2 ) . 'Z';
		}
		return ConvertibleTimestamp::convert( TS_ISO_8601, $timestamp );
	}
}
