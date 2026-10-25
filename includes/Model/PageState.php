<?php

namespace MediaWiki\Extension\GlowThread\Model;

/**
 * Per-page comment state stored in glowthread_page.gtpg_state.
 */
final class PageState {
	/** Anyone with the rights can comment */
	public const OPEN = 'open';
	/** Existing comments stay readable, no new comments or replies */
	public const LOCKED = 'locked';
	/** The comment section is hidden from readers, no writes except moderation */
	public const DISABLED = 'disabled';

	public const ALL = [ self::OPEN, self::LOCKED, self::DISABLED ];
}
