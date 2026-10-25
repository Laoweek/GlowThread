<?php

namespace MediaWiki\Extension\GlowThread\Log;

use DatabaseLogEntry;
use LogEntry;
use LogFormatter;
use MediaWiki\Extension\GlowThread\Special\SpecialGlowThreadLink;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use Wikimedia\IPUtils;

/**
 * Formats glowthread and glowthread-private log entries, and FlowThread's comments entries
 * where GlowThread registers that type (LegacyLogType).
 *
 * Entries written by FlowThread (type comments, parameters 4::username, 4::postid, 4::count or
 * none) keep FlowThread's message keys logentry-comments-<action>. Entries written by GlowThread
 * use glowthread-logentry-<action> and glowthread-logentry-private-<action>. The author of a
 * comment that was later erased is shown only to holders of glowthread-erase, and an author
 * hidden by a hideuser block only to holders of hideuser, in the UI and in the API. (Core's
 * user-link parameter type does not check hidden users.)
 *
 * GlowThread's entries show the author under their current name, and check the hideuser block of
 * that account: the entry's glowthread_author relation holds the author's actor ID, while
 * 5:user-link:author keeps the name of the time of the action, which a rename does not change.
 *
 * FlowThread's entries name the author as a plain string: 4::username in delete, recover and
 * markchecked, and 4::postid in erase (a FlowThread bug stored the author's name there). The
 * same rules apply to them: a hideuser-hidden name is hidden, and the author of a FlowThread
 * erase (an erased comment) is shown only to holders of glowthread-erase. They have no actor
 * ID, so the stored name is all there is.
 *
 * The current-name, hidden-user and erased-comment lookups go through LogAuthorLookup, which
 * remembers them across the entries of one list. Special:Log and RecentChanges announce every
 * row before they format any (getPreloadTitles()), so the current names and the erased-comment
 * checks of up to 500 entries go in one query each. The hideuser check reads the blocks of each
 * author once.
 */
class CommentsLogFormatter extends LogFormatter {

	/** Actions whose public entries name the comment author */
	private const AUTHOR_ACTIONS = [
		'delete', 'recover', 'approve', 'markchecked', 'autohold', 'pin', 'unpin', 'post', 'undo',
	];

	/** FlowThread action => parameter holding the comment author's name */
	private const LEGACY_AUTHOR_PARAMS = [
		'delete' => '4::username',
		'recover' => '4::username',
		'markchecked' => '4::username',
		'erase' => '4::postid',
	];

	private ?bool $authorHidden = null;

	/** @var UserIdentity|false|null False when there is none, null before the lookup */
	private $currentAuthor = null;

	public function __construct(
		LogEntry $entry,
		private readonly LogAuthorLookup $authorLookup
	) {
		parent::__construct( $entry );
	}

	private function isGlowThreadEntry(): bool {
		return $this->entry->getType() !== LegacyLogType::TYPE;
	}

	/** @inheritDoc */
	protected function getMessageKey() {
		$subtype = $this->entry->getSubtype();
		if ( $this->entry->getType() === ModerationLogger::PRIVATE_TYPE ) {
			return "glowthread-logentry-private-$subtype";
		}
		if ( $this->isGlowThreadEntry() ) {
			return "glowthread-logentry-$subtype";
		}
		return parent::getMessageKey();
	}

	/** @inheritDoc */
	protected function getMessageParameters() {
		if ( isset( $this->parsedParameters ) ) {
			return $this->parsedParameters;
		}
		$entryParams = $this->entry->getParameters();
		$params = parent::getMessageParameters();
		if ( isset( $entryParams['4::changes'] ) ) {
			// The parent passes the array through unformatted, and arrays cannot be message parameters
			$params[3] = $this->formatChanges( $entryParams['4::changes'] );
		}
		if ( array_key_exists( '4::comment', $entryParams ) ) {
			$params[3] = $this->makeCommentLink(
				(int)$entryParams['4::comment'],
				(int)( $entryParams['6::seq'] ?? 0 )
			);
			$author = $entryParams['5:user-link:author'] ?? null;
			if ( $this->isAuthorHidden() ) {
				$params[4] = $this->msg( 'glowthread-log-author-hidden' )->text();
			} elseif ( is_string( $author ) && IPUtils::isIPAddress( $author ) ) {
				// Legacy anonymous authors: core's user-link type cannot make a link for an IP
				$params[4] = Message::rawParam( $this->makeUserLink( new UserIdentityValue( 0, $author ) ) );
			}
		} elseif ( $this->getLegacyAuthorParam() !== null && $this->isAuthorHidden() ) {
			$params[3] = $this->msg( 'glowthread-log-author-hidden' )->text();
		}
		$this->parsedParameters = $params;
		return $params;
	}

	/** @inheritDoc */
	protected function formatParameterValue( $type, $value ) {
		if ( $type === 'user-link' ) {
			$author = $this->getCurrentAuthor();
			$value = $author?->getName() ?? $value;
			// The parent's link, without looking up again by name the account the relation gave.
			// Any other name is left to the parent (an invalid one shows empty-username).
			if ( $author && $this->authorLookup->isCanonicalName( $value ) ) {
				$saveLinkFlood = $this->linkFlood;
				$this->setShowUserToolLinks( false );
				$value = Message::rawParam( $this->makeUserLink( $author ) );
				$this->setShowUserToolLinks( $saveLinkFlood );
				return $value;
			}
		}
		return parent::formatParameterValue( $type, $value );
	}

	/**
	 * Announces the entry's lookups to LogAuthorLookup (see the class comment), and preloads the
	 * user page of the author as the entry stored their name (the current name differs only after
	 * a rename).
	 *
	 * @inheritDoc
	 */
	public function getPreloadTitles() {
		$titles = parent::getPreloadTitles();
		if ( !$this->isGlowThreadEntry() || !$this->entry instanceof DatabaseLogEntry ) {
			return $titles;
		}
		$params = $this->entry->getParameters();
		$author = $params['5:user-link:author'] ?? null;
		$this->authorLookup->preload(
			is_string( $author ) ? (int)$this->entry->getId() : null,
			$this->namesErasableComment() ? (int)$params['4::comment'] : null
		);
		if ( is_string( $author ) && !IPUtils::isIPAddress( $author ) ) {
			$userPage = Title::makeTitleSafe( NS_USER, $author );
			if ( $userPage ) {
				$titles[] = $userPage;
			}
		}
		return $titles;
	}

	/** @inheritDoc */
	protected function getParametersForApi() {
		if ( !$this->canViewLogType() ) {
			// As for the action text. (list=recentchanges asks for the parameters of every row.)
			return [];
		}
		$params = parent::getParametersForApi();
		// What an exact undo needs (the status before or after, resolved report IDs) is not for readers
		unset( $params['7::old'], $params['7::new'], $params['8::reports'], $params['9::prev'] );
		$author = $this->getCurrentAuthor();
		if ( $author && isset( $params['5:user-link:author'] ) ) {
			$params['5:user-link:author'] = $author->getName();
		}
		if ( $this->isAuthorHidden() ) {
			unset( $params['5:user-link:author'] );
			$legacy = $this->getLegacyAuthorParam();
			if ( $legacy !== null ) {
				unset( $params[$legacy] );
			}
		}
		return $params;
	}

	/**
	 * For an entry written by FlowThread, the parameter that holds the comment author's name.
	 */
	private function getLegacyAuthorParam(): ?string {
		if ( $this->isGlowThreadEntry() ) {
			return null;
		}
		$key = self::LEGACY_AUTHOR_PARAMS[$this->entry->getSubtype()] ?? null;
		return $key !== null && is_string( $this->entry->getParameters()[$key] ?? null ) ? $key : null;
	}

	/**
	 * For a GlowThread entry that names the comment author, the author under their current name
	 * (LogAuthorLookup::getEntryAuthor()). Null for FlowThread's entries, for entries without
	 * an author, and for entries not read from the database (no log ID yet), which keep the
	 * stored name.
	 */
	private function getCurrentAuthor(): ?UserIdentity {
		if ( $this->currentAuthor === null ) {
			$this->currentAuthor = false;
			if ( $this->isGlowThreadEntry()
				&& is_string( $this->entry->getParameters()['5:user-link:author'] ?? null )
				&& $this->entry instanceof DatabaseLogEntry
			) {
				$this->currentAuthor = $this->authorLookup->getEntryAuthor( (int)$this->entry->getId() ) ?? false;
			}
		}
		return $this->currentAuthor ?: null;
	}

	/**
	 * Whether the author must be hidden from the current viewer: the author is hidden by a
	 * hideuser block and the viewer lacks hideuser, or the comment was erased and the viewer
	 * cannot erase.
	 */
	private function isAuthorHidden(): bool {
		if ( $this->authorHidden !== null ) {
			return $this->authorHidden;
		}
		$params = $this->entry->getParameters();
		$authority = $this->context->getAuthority();
		$legacyParam = $this->getLegacyAuthorParam();
		$author = $legacyParam !== null ? $params[$legacyParam] : ( $params['5:user-link:author'] ?? null );
		$current = $this->getCurrentAuthor();
		if ( $current ) {
			$author = $current->getName();
		}
		$hidden = false;
		if ( is_string( $author ) && !$authority->isAllowed( 'hideuser' ) ) {
			$hidden = $current
				? $this->authorLookup->isHiddenUser( $current )
				: $this->authorLookup->isHiddenName( $author );
		}
		if ( !$hidden && !$authority->isAllowed( 'glowthread-erase' ) ) {
			if ( $legacyParam !== null ) {
				// A FlowThread erase entry names the author of a comment that is gone for good
				$hidden = $this->entry->getSubtype() === 'erase';
			} elseif ( $this->namesErasableComment() ) {
				$hidden = $this->authorLookup->isErasedComment( (int)$params['4::comment'] );
			}
		}
		$this->authorHidden = $hidden;
		return $hidden;
	}

	/**
	 * Whether the entry is a public GlowThread entry about a comment that names its author, which
	 * readers without glowthread-erase do not see once the comment is erased.
	 */
	private function namesErasableComment(): bool {
		return $this->entry->getType() === ModerationLogger::TYPE
			&& in_array( $this->entry->getSubtype(), self::AUTHOR_ACTIONS, true )
			&& isset( $this->entry->getParameters()['4::comment'] );
	}

	/**
	 * @return string|array Plain text for IRC and history, otherwise a raw link parameter
	 */
	private function makeCommentLink( int $id, int $seq ) {
		$text = $this->msg( 'glowthread-log-comment', $seq, $id )->text();
		if ( $this->plaintext ) {
			return $text;
		}
		return Message::rawParam( $this->getLinkRenderer()->makeKnownLink(
			SpecialPage::getTitleFor( SpecialGlowThreadLink::NAME, (string)$id ),
			$text
		) );
	}

	/**
	 * @param mixed $changes List of [name, old, new]
	 */
	private function formatChanges( $changes ): string {
		$lang = $this->context->getLanguage();
		$items = [];
		foreach ( is_array( $changes ) ? $changes : [] as $change ) {
			if ( !is_array( $change ) || count( $change ) !== 3 ) {
				continue;
			}
			[ $name, $old, $new ] = $change;
			$items[] = $this->msg(
				'glowthread-log-setting-change',
				(string)$name,
				$this->formatValue( $old ),
				$this->formatValue( $new )
			)->text();
		}
		return $lang->commaList( $items );
	}

	/**
	 * @param mixed $value
	 */
	private function formatValue( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}
		return (string)$value;
	}
}
