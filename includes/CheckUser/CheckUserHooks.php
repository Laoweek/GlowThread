<?php

namespace MediaWiki\Extension\GlowThread\CheckUser;

use ManualLogEntry;
use MediaWiki\Extension\GlowThread\Hook\GlowThreadPostSavedHook;
use MediaWiki\Extension\GlowThread\Hook\GlowThreadReportFiledHook;
use MediaWiki\Extension\GlowThread\Log\ModerationLogger;
use MediaWiki\Extension\GlowThread\Log\SoftwareLogEntry;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\UserIdentity;

/**
 * Records every new comment (visible or held) and every report as a CheckUser private event, so
 * checkusers see the IP address, X-Forwarded-For and user agent of comment authors and reporters
 * in Special:CheckUser.
 *
 * The event is a glowthread/post or glowthread/report log entry that is never saved to the
 * logging table or RecentChanges: its RecentChange object (rc_logid 0) goes straight to
 * CheckUser's CheckUserInsert::updateCheckUserData(), which files it in cu_private_event.
 * CheckUser reads IP, XFF and user agent from the main request, and GlowThreadPostSaved and
 * GlowThreadReportFiled run in the request of the author or reporter. Moderation entries are
 * published to RecentChanges, which CheckUser records on its own, or handed to CheckUser by
 * ModerationLogger while the publicLog setting keeps them out of RecentChanges.
 *
 * Automatic holds (glowthread/autohold) are the exception. They are logged as the system user in
 * the request of the report that caused the hold, so CheckUser would file that reporter's IP
 * address, XFF and user agent under the system user, and a check of it would list every such
 * reporter. Reporters already have their own glowthread/report records, so the hold is recorded
 * as done by the software instead (IP 127.0.0.1, no XFF and no user agent), as AbuseFilter does
 * for its system user. CheckUser's hook interfaces are not implemented, because CheckUser may
 * not be installed and their namespace moved between its branches.
 *
 * Does nothing when CheckUser is not loaded. The service is fetched by name because its class
 * moved between CheckUser branches.
 */
class CheckUserHooks implements GlowThreadPostSavedHook, GlowThreadReportFiledHook {

	public const LOG_ACTION = 'post';
	public const REPORT_LOG_ACTION = 'report';

	/** @inheritDoc */
	public function onGlowThreadPostSaved(
		PostRecord $post,
		ProperPageIdentity $page,
		UserIdentity $author,
		array $mentionedUsers,
		?PostRecord $replyTo,
		?UserIdentity $replyToAuthor
	): void {
		$this->record( self::LOG_ACTION, $post, $page, $author, $post->getCreated() );
	}

	/** @inheritDoc */
	public function onGlowThreadReportFiled(
		PostRecord $post,
		ProperPageIdentity $page,
		UserIdentity $reporter,
		string $reason,
		bool $autoHeld
	): void {
		// The note is left out: it is free text about someone else's comment
		$this->record( self::REPORT_LOG_ACTION, $post, $page, $reporter, null );
	}

	/**
	 * CheckUserInsertLogEventRow: a log entry published to RecentChanges.
	 *
	 * @param string &$ip
	 * @param string|false &$xff
	 * @param array &$row
	 * @param UserIdentity $user
	 * @param int $id Log ID
	 * @param \RecentChange|null $rc
	 */
	public function onCheckUserInsertLogEventRow( &$ip, &$xff, array &$row, $user, $id, $rc ): void {
		if ( self::isAutoHold( $rc ) ) {
			$ip = SoftwareLogEntry::IP;
			$xff = false;
			$row['cule_agent'] = '';
		}
	}

	/**
	 * CheckUserInsertPrivateEventRow: CheckUser files a log entry as a private event when it
	 * cannot find its logging row.
	 *
	 * @param string &$ip
	 * @param string|false &$xff
	 * @param array &$row
	 * @param UserIdentity $user
	 * @param \RecentChange|null $rc
	 */
	public function onCheckUserInsertPrivateEventRow( &$ip, &$xff, array &$row, $user, $rc ): void {
		if ( self::isAutoHold( $rc ) ) {
			$ip = SoftwareLogEntry::IP;
			$xff = false;
			$row['cupe_agent'] = '';
		}
	}

	/**
	 * @param \RecentChange|null $rc
	 */
	private static function isAutoHold( $rc ): bool {
		return $rc
			&& $rc->getAttribute( 'rc_log_type' ) === ModerationLogger::TYPE
			&& $rc->getAttribute( 'rc_log_action' ) === 'autohold';
	}

	/**
	 * @param string $action Log subtype
	 * @param PostRecord $post
	 * @param ProperPageIdentity $page
	 * @param UserIdentity $performer
	 * @param string|null $timestamp TS_MW, null for now
	 */
	private function record(
		string $action,
		PostRecord $post,
		ProperPageIdentity $page,
		UserIdentity $performer,
		?string $timestamp
	): void {
		$services = MediaWikiServices::getInstance();
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'CheckUser' )
			|| !$services->hasService( 'CheckUserInsert' )
		) {
			return;
		}
		$entry = new ManualLogEntry( ModerationLogger::TYPE, $action );
		$entry->setPerformer( $performer );
		$entry->setTarget( $page );
		if ( $timestamp !== null ) {
			$entry->setTimestamp( $timestamp );
		}
		$entry->setParameters( [
			'4::comment' => $post->getId(),
			'6::seq' => $post->getSeq(),
		] );
		$services->getService( 'CheckUserInsert' )->updateCheckUserData( $entry->getRecentChange( 0 ) );
	}
}
