<?php

namespace MediaWiki\Extension\GlowThread\Hook;

use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\User\UserIdentity;

/**
 * @stable to implement
 */
interface GlowThreadReportFiledHook {
	/**
	 * A reader reported a comment. Runs after commit, in the reporter's request (so request IP
	 * and user agent are the reporter's).
	 *
	 * Privacy: GlowThread shows who reported a comment only to moderators. Do not publish
	 * $reporter.
	 *
	 * @param PostRecord $post The comment after the report (held if $autoHeld)
	 * @param ProperPageIdentity $page
	 * @param UserIdentity $reporter
	 * @param string $reason One of spam, abuse, offtopic, spoiler, misinfo, other
	 * @param bool $autoHeld Whether this report made the comment reach the auto-hold threshold
	 * @return void This hook must not abort, it must return no value
	 */
	public function onGlowThreadReportFiled(
		PostRecord $post,
		ProperPageIdentity $page,
		UserIdentity $reporter,
		string $reason,
		bool $autoHeld
	): void;
}
