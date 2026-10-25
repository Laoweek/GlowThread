<?php

namespace MediaWiki\Extension\GlowThread\Model;

/**
 * Report reasons stored in glowthread_report.gtrp_reason.
 */
final class ReportReason {
	/** Reasons a reader can choose */
	public const USER_REASONS = [ 'spam', 'abuse', 'offtopic', 'spoiler', 'misinfo', 'other' ];
	/** Imported FlowThread reports carry no reason */
	public const LEGACY = 'legacy';

	/** Report outcome: the comment was deleted, erased or held */
	public const OUTCOME_UPHELD = 'upheld';
	/** Report outcome: a moderator kept the comment */
	public const OUTCOME_DISMISSED = 'dismissed';
}
