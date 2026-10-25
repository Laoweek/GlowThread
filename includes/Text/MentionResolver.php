<?php

namespace MediaWiki\Extension\GlowThread\Text;

use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityLookup;

/**
 * Resolves the user IDs of mention tokens to registered, not hidden users, in bulk.
 */
class MentionResolver {

	/** User IDs per query: keeps the IN list short when many texts are resolved together */
	private const BATCH_SIZE = 1000;

	/** @var array<int,UserIdentity|false> User ID => user, or false if none */
	private array $cache = [];

	public function __construct(
		private readonly UserIdentityLookup $userIdentityLookup,
		private readonly TextProcessor $textProcessor
	) {
	}

	/**
	 * @param int[] $ids User IDs
	 * @return array<int,UserIdentity> ID => user, only for registered users who are not hidden, in
	 *   the order of $ids
	 */
	public function resolve( array $ids ): array {
		$missing = array_values( array_diff( array_unique( $ids ), array_keys( $this->cache ) ) );
		if ( $missing ) {
			foreach ( $missing as $id ) {
				$this->cache[$id] = false;
			}
			foreach ( array_chunk( $missing, self::BATCH_SIZE ) as $batch ) {
				$users = $this->userIdentityLookup->newSelectQueryBuilder()
					->whereUserIds( $batch )
					->registered()
					->hidden( false )
					->caller( __METHOD__ )
					->fetchUserIdentities();
				foreach ( $users as $user ) {
					$this->cache[$user->getId()] = $user;
				}
			}
		}
		$found = [];
		foreach ( $ids as $id ) {
			if ( ( $this->cache[$id] ?? false ) !== false ) {
				$found[$id] = $this->cache[$id];
			}
		}
		return $found;
	}

	/**
	 * Mention data for TextProcessor::toSegments(), tokenize(), extractMentions() and
	 * toPlainText(): the users of the mentions in all the texts, looked up together.
	 *
	 * @param string[] $texts Normalised texts
	 * @return array<int,string> User ID => current name, for registered users who are not hidden
	 */
	public function existenceMapForTexts( array $texts ): array {
		$ids = [];
		foreach ( $texts as $text ) {
			array_push( $ids, ...$this->textProcessor->extractMentionIds( $text ) );
		}
		$map = [];
		foreach ( $this->resolve( $ids ) as $id => $user ) {
			$map[$id] = $user->getName();
		}
		return $map;
	}

	/**
	 * Registered, visible users mentioned in the text, each once, in the order mentioned. Only
	 * the mentions that show as mentions count (TextProcessor::MAX_MENTIONS_SHOWN).
	 *
	 * @param string $text Normalised text
	 * @return UserIdentity[]
	 */
	public function getMentionedUsers( string $text ): array {
		return array_values( $this->resolve( $this->textProcessor->extractMentionIds( $text ) ) );
	}

	/**
	 * Texts as readers see them (TextProcessor::toPlainText()), with their mentions looked up
	 * together
	 *
	 * @param string[] $texts Normalised texts
	 * @return string[] Same keys as $texts
	 */
	public function toPlainTexts( array $texts ): array {
		$existing = $this->existenceMapForTexts( $texts );
		return array_map( fn ( $text ) => $this->textProcessor->toPlainText( $text, $existing ), $texts );
	}
}
