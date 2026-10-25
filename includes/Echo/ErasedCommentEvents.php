<?php

namespace MediaWiki\Extension\GlowThread\Echo;

use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Model\PostStatus;
use MediaWiki\Extension\GlowThread\Store\PostStore;
use MediaWiki\Extension\Notifications\Controller\ModerationController;
use MediaWiki\Extension\Notifications\DbFactory;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\IReadableDatabase;

/**
 * Hides the Echo events that name an erased comment's author as their agent: GlowThread's reply,
 * mention and user page notifications about the comment, and for an imported comment
 * FlowThread's notifications about it. Uses Echo's own moderation (event_deleted), the way Echo
 * hides the notifications of deleted pages, which also recomputes the unread counts. The same
 * applies to a comment its author deleted, so a mention cannot outlive its own deletion.
 *
 * Needs Echo. Used when a comment is erased or its author deletes it, and again after an
 * undeletion, because Echo then shows the notifications of the restored page ID again. Also
 * tells whether a comment's notifications were sent, so an approval does not send them twice.
 */
class ErasedCommentEvents {

	/** GlowThread's notification types about a comment, whose agent is its author */
	private const TYPES = [ EchoSetupHooks::TYPE_REPLY, EchoSetupHooks::TYPE_MENTION, EchoSetupHooks::TYPE_USERPAGE ];

	/**
	 * Whether the notifications a comment caused are hidden: it is erased, or deleted by its own
	 * author (also a moderator deleting their own comment). A moderator who restores the comment
	 * does not bring them back.
	 */
	public static function appliesTo( PostRecord $post ): bool {
		return $post->isErased()
			|| ( $post->isDeleted() && $post->getStatusActorId() === $post->getActorId() );
	}

	/**
	 * Hide the notifications of these comments.
	 *
	 * @param PostRecord[] $posts Comments for which appliesTo() is true
	 * @param int[] $pageIds Pages whose events to search: the pages the comments were on when
	 *  their notifications were sent
	 */
	public static function hide( array $posts, array $pageIds ): void {
		$ids = [];
		$legacyIds = [];
		foreach ( $posts as $post ) {
			$ids[$post->getId()] = true;
			if ( $post->getLegacyId() !== null ) {
				$legacyIds[$post->getLegacyId()] = true;
			}
		}
		if ( !$ids ) {
			return;
		}
		$events = self::load( $pageIds, $legacyIds !== [] );
		ModerationController::moderate( self::match( $events, $ids, $legacyIds ), true );
	}

	/**
	 * Hide the notifications on these pages that are about erased comments, or comments deleted
	 * by their author, wherever those comments are now (an undeletion that merges into a
	 * re-created page moves comments to its page ID, while their notifications keep the old one).
	 *
	 * @param int[] $pageIds
	 * @param IReadableDatabase $db Primary database with GlowThread's tables
	 */
	public static function hideOnPages( array $pageIds, IReadableDatabase $db ): void {
		$events = self::load( $pageIds, true );
		$ids = [];
		$legacyIds = [];
		foreach ( $events as $event ) {
			[ $id, $legacyId ] = self::getCommentRef( $event );
			if ( $id ) {
				$ids[] = $id;
			} elseif ( $legacyId !== null ) {
				$legacyIds[] = $legacyId;
			}
		}
		$hiddenIds = self::selectHidden( $db, 'gtp_id', $ids );
		$hiddenLegacyIds = self::selectHidden( $db, 'gtp_legacy_id', $legacyIds );
		if ( !$hiddenIds && !$hiddenLegacyIds ) {
			return;
		}
		ModerationController::moderate( self::match(
			$events,
			array_fill_keys( array_map( 'intval', $hiddenIds ), true ),
			array_fill_keys( array_map( 'strval', $hiddenLegacyIds ), true )
		), true );
	}

	/**
	 * Whether GlowThread sent notifications about this comment (hidden ones included): a reply,
	 * mention or user page notification by its author on its page.
	 *
	 * @param PostRecord $post
	 * @param UserIdentity $author The comment's author, the agent of its notifications
	 */
	public static function wereSent( PostRecord $post, UserIdentity $author ): bool {
		if ( !$author->isRegistered() ) {
			return false;
		}
		$res = DbFactory::newFromDefault()->getEchoDb( DB_PRIMARY )->newSelectQueryBuilder()
			->select( Event::selectFields() )
			->from( 'echo_event' )
			->where( [
				'event_page_id' => $post->getPageId(),
				'event_type' => self::TYPES,
				'event_agent_id' => $author->getId(),
			] )
			->caller( __METHOD__ )
			->fetchResultSet();
		foreach ( $res as $row ) {
			if ( self::getCommentRef( Event::newFromRow( $row ) )[0] === $post->getId() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Of these comments, the ones whose notifications stay hidden (see appliesTo()).
	 *
	 * @param IReadableDatabase $db
	 * @param string $field gtp_id or gtp_legacy_id
	 * @param array $values Values of that field
	 * @return array The values of that field for those comments
	 */
	private static function selectHidden( IReadableDatabase $db, string $field, array $values ): array {
		if ( !$values ) {
			return [];
		}
		$res = $db->newSelectQueryBuilder()
			->select( PostStore::FIELDS )
			->from( PostStore::TABLE )
			->where( [
				$field => array_values( array_unique( $values ) ),
				'gtp_status' => [ PostStatus::ERASED, PostStatus::DELETED ],
			] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$hidden = [];
		foreach ( $res as $row ) {
			if ( self::appliesTo( PostRecord::newFromRow( $row ) ) ) {
				$hidden[] = $row->$field;
			}
		}
		return $hidden;
	}

	/**
	 * GlowThread's comment events (and FlowThread's when $withLegacy) on these pages that are
	 * not hidden yet.
	 *
	 * @param int[] $pageIds
	 * @param bool $withLegacy
	 * @return Event[]
	 */
	private static function load( array $pageIds, bool $withLegacy ): array {
		$pageIds = array_values( array_unique( array_filter( $pageIds, static fn ( $id ) => $id > 0 ) ) );
		if ( !$pageIds ) {
			return [];
		}
		$types = self::TYPES;
		if ( $withLegacy ) {
			$types = array_merge( $types, array_keys( EchoSetupHooks::LEGACY_TYPES ) );
		}
		$res = DbFactory::newFromDefault()->getEchoDb( DB_PRIMARY )->newSelectQueryBuilder()
			->select( Event::selectFields() )
			->from( 'echo_event' )
			->where( [
				'event_page_id' => $pageIds,
				'event_type' => $types,
				'event_deleted' => 0,
			] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$events = [];
		foreach ( $res as $row ) {
			$events[] = Event::newFromRow( $row );
		}
		return $events;
	}

	/**
	 * The comment an event is about: GlowThread's comment ID, or for FlowThread's events the
	 * legacy ID (22 lowercase hex digits).
	 *
	 * @return array{0:int,1:?string}
	 */
	private static function getCommentRef( Event $event ): array {
		if ( isset( EchoSetupHooks::LEGACY_TYPES[$event->getType()] ) ) {
			return [ 0, LegacyPresentationModel::toLegacyHex( $event->getExtraParam( 'postid' ) ) ];
		}
		return [ (int)$event->getExtraParam( 'comment-id' ), null ];
	}

	/**
	 * @param Event[] $events
	 * @param array<int,true> $ids Comment IDs
	 * @param array<string,true> $legacyIds Legacy IDs
	 * @return int[] IDs of the events about these comments
	 */
	private static function match( array $events, array $ids, array $legacyIds ): array {
		$eventIds = [];
		foreach ( $events as $event ) {
			[ $id, $legacyId ] = self::getCommentRef( $event );
			if ( isset( $ids[$id] ) || ( $legacyId !== null && isset( $legacyIds[$legacyId] ) ) ) {
				$eventIds[] = (int)$event->getId();
			}
		}
		return $eventIds;
	}
}
