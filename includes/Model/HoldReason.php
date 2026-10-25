<?php

namespace MediaWiki\Extension\GlowThread\Model;

/**
 * Why a comment was held for review (glowthread_post.gtp_hold_reason).
 */
final class HoldReason {
	/** A word-filter rule matched */
	public const FILTER = 'filter';
	/** A word-filter regex failed at run time, so the comment is held rather than passed */
	public const FILTER_ERROR = 'filter-error';
	/** A URL matched SpamBlacklist */
	public const SPAM_BLACKLIST = 'spamblacklist';
	/** The new-account gate applied */
	public const NEW_ACCOUNT = 'newaccount';
	/** Enough distinct users reported the comment */
	public const REPORTS = 'reports';
	/** A reply under the author's own held comment: it waits for review with its parent */
	public const PARENT_HELD = 'parentheld';
}
