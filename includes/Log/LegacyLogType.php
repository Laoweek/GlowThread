<?php

namespace MediaWiki\Extension\GlowThread\Log;

use MediaWiki\Config\Config;
use MediaWiki\Extension\GlowThread\Hooks\LogVisibilityHooks;
use MediaWiki\MainConfigNames;

/**
 * FlowThread's log type, comments. GlowThread writes its own entries to the glowthread and
 * glowthread-private types. On wikis that used FlowThread ($wgGlowThreadLegacyCompat) the
 * comments type is registered with GlowThread's formatter, so FlowThread's old entries keep
 * rendering in Special:Log and in the page history of the moderation hub, to the readers who see
 * GlowThread's own entries (LogVisibilityHooks). With the setting off, those entries are hidden
 * (hideIn()). It is left alone when another extension registered it:
 * FlowThread itself, or Extension:Comments, which uses the same name for its own log.
 */
class LegacyLogType {

	public const TYPE = 'comments';

	/**
	 * The right LogRestrictions asks for to see the comments type while
	 * $wgGlowThreadLegacyCompat is off. It is not an available right and no group has it.
	 */
	public const HIDDEN_RIGHT = 'glowthread-legacy-log';

	/** The settings onRegistration() changes */
	private const SETTINGS = [
		'LogTypes', 'FilterLogTypes', 'ActionFilteredLogs', 'LogActionsHandlers', 'LogRestrictions',
	];

	private const HANDLER = [
		'class' => CommentsLogFormatter::class,
		'services' => [ 'GlowThread.LogAuthorLookup' ],
	];

	/** Special:Log action filters for the actions FlowThread logged */
	private const ACTION_FILTERS = [
		'delete' => [ 'delete' ],
		'recover' => [ 'recover' ],
		'erase' => [ 'erase' ],
		'markchecked' => [ 'markchecked' ],
		'pagestate' => [ 'enable', 'disable' ],
		'import' => [ 'import' ],
	];

	/**
	 * Extension callback. It runs once the configuration of every loaded extension is in place,
	 * so a comments type registered by another extension is already there.
	 */
	public static function onRegistration(): void {
		if ( in_array( self::TYPE, $GLOBALS['wgLogTypes'] ?? [], true ) ) {
			return;
		}
		$settings = [];
		foreach ( self::SETTINGS as $name ) {
			$settings[$name] = $GLOBALS["wg$name"] ?? [];
		}
		$settings = empty( $GLOBALS['wgGlowThreadLegacyCompat'] )
			? self::hideIn( $settings )
			: self::addTo( $settings );
		foreach ( $settings as $name => $value ) {
			$GLOBALS["wg$name"] = $value;
		}
	}

	/**
	 * The given LogTypes, FilterLogTypes, ActionFilteredLogs, LogActionsHandlers and
	 * LogRestrictions settings with the comments type added, and shown to the readers who see the
	 * glowthread type (also where hideIn() hid it).
	 *
	 * @param array<string,array> $settings
	 * @return array<string,array>
	 */
	public static function addTo( array $settings ): array {
		$settings['LogTypes'][] = self::TYPE;
		$settings['FilterLogTypes'][self::TYPE] = true;
		$settings['ActionFilteredLogs'][self::TYPE] = self::ACTION_FILTERS;
		$settings['LogActionsHandlers'][self::TYPE . '/*'] = self::HANDLER;
		$settings['LogRestrictions'][self::TYPE] = LogVisibilityHooks::VIEW_RIGHT;
		return $settings;
	}

	/**
	 * The given settings with FlowThread's old entries hidden, for wikis with
	 * $wgGlowThreadLegacyCompat off. They stay in the database. LogRestrictions keeps them out of
	 * Special:Log and list=logevents. Core still lists those that are in RecentChanges (until
	 * $wgRCMaxAge removes them), so GlowThread's formatter handles them there: it shows neither
	 * their action nor their parameters to readers without HIDDEN_RIGHT.
	 *
	 * @param array<string,array> $settings
	 * @return array<string,array>
	 */
	public static function hideIn( array $settings ): array {
		$settings['LogRestrictions'][self::TYPE] = self::HIDDEN_RIGHT;
		$settings['LogActionsHandlers'][self::TYPE . '/*'] = self::HANDLER;
		return $settings;
	}

	/**
	 * Whether GlowThread registered the comments type (and not another extension), so that its
	 * entries are shown.
	 */
	public static function isRegistered( Config $config ): bool {
		$handler = $config->get( MainConfigNames::LogActionsHandlers )[self::TYPE . '/*'] ?? null;
		return in_array( self::TYPE, $config->get( MainConfigNames::LogTypes ), true )
			&& is_array( $handler ) && ( $handler['class'] ?? null ) === CommentsLogFormatter::class;
	}
}
