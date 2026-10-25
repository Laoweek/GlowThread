<?php

namespace MediaWiki\Extension\GlowThread\Settings;

use MediaWiki\Api\ApiMessage;
use MediaWiki\Extension\GlowThread\GlowThreadConfig;
use MediaWiki\Extension\GlowThread\Log\ModerationLogger;
use MediaWiki\Extension\GlowThread\Model\ReportReason;
use MediaWiki\Extension\GlowThread\Policy\RateLimitErrors;
use MediaWiki\Extension\GlowThread\Store\SettingsStore;
use MediaWiki\Message\Message;
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\User\ActorNormalization;
use StatusValue;
use Wikimedia\Rdbms\ReadOnlyMode;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Policy settings that can be tuned on-wiki (Special:GlowThread/settings).
 *
 * Each setting has a LocalSettings variable ($wgGlowThread*), null by default, which then means
 * the built-in 'default'. Setting the variable in LocalSettings locks the setting to that value,
 * and $wgGlowThreadOnWikiSettings[<name>] = false locks it to the variable or its default.
 * Changes need glowthread-configure and are logged as glowthread/settings with the old and new
 * values.
 */
class SettingsService {

	/**
	 * name => definition. type is int, bool or list, and 'default' applies while the LocalSettings
	 * variable is null. Lists are subsets of 'options'. An int with 'minOn' is off at 'min' and
	 * otherwise, when set on the wiki, at least 'minOn'. Its LocalSettings value may be anything
	 * from 'min' to 'max'.
	 */
	public const DEFINITIONS = [
		'maxLength' => [
			'type' => 'int', 'min' => 1, 'max' => 10000, 'default' => 1000, 'config' => 'GlowThreadMaxLength',
		],
		'maxMentions' => [
			'type' => 'int', 'min' => 0, 'max' => 50, 'default' => 5, 'config' => 'GlowThreadMaxMentions',
		],
		'duplicateWindow' => [
			'type' => 'int', 'min' => 0, 'max' => 86400, 'default' => 600, 'config' => 'GlowThreadDuplicateWindow',
		],
		'newAccountHours' => [
			'type' => 'int', 'min' => 0, 'max' => 8760, 'default' => 24, 'config' => 'GlowThreadNewAccountHours',
		],
		'newAccountApproved' => [
			'type' => 'int', 'min' => 0, 'max' => 1000, 'default' => 1,
			'config' => 'GlowThreadNewAccountApproved',
		],
		// On the wiki off, or at least 2: one account alone must not be able to hide a comment
		'autoHoldReporters' => [
			'type' => 'int', 'min' => 0, 'minOn' => 2, 'max' => 100, 'default' => 3,
			'config' => 'GlowThreadAutoHoldReporters',
		],
		'reportReasons' => [
			'type' => 'list', 'options' => ReportReason::USER_REASONS, 'minItems' => 1,
			'default' => ReportReason::USER_REASONS, 'config' => 'GlowThreadReportReasons',
		],
		'publicLog' => [ 'type' => 'bool', 'default' => true, 'config' => 'GlowThreadPublicLog' ],
	];

	/** Seconds the on-wiki rows are reused within one process (long-running scripts) */
	private const PROCESS_TTL = 5;

	/** @var array|null On-wiki rows, cached for this request */
	private ?array $rows = null;
	private float $rowsLoadedAt = 0.0;

	public function __construct(
		private readonly GlowThreadConfig $config,
		private readonly SettingsStore $store,
		private readonly ActorNormalization $actorNormalization,
		private readonly ModerationLogger $logger,
		private readonly ReadOnlyMode $readOnlyMode,
		private readonly RateLimitErrors $rateLimitErrors
	) {
	}

	/**
	 * Effective value of a setting.
	 *
	 * @param string $name A key of DEFINITIONS
	 * @return int|bool|string[]
	 */
	public function get( string $name ) {
		$def = self::DEFINITIONS[$name] ?? null;
		if ( $def === null ) {
			throw new \InvalidArgumentException( "Unknown GlowThread setting $name" );
		}
		if ( $this->isEditable( $name ) ) {
			$row = $this->getRows()[$name] ?? null;
			if ( $row !== null && $this->validateValue( $name, $row['value'] )->isOK() ) {
				return $this->coerce( $name, $row['value'] );
			}
		}
		return $this->getDefault( $name );
	}

	/**
	 * LocalSettings value, or the built-in default when it is not set, clamped to the allowed range.
	 *
	 * @param string $name
	 * @return int|bool|string[]
	 */
	public function getDefault( string $name ) {
		$def = self::DEFINITIONS[$name];
		$raw = $this->config->getRaw( $def['config'] ) ?? $def['default'];
		switch ( $def['type'] ) {
			case 'int':
				return max( $def['min'], min( $def['max'], (int)$raw ) );
			case 'bool':
				return (bool)$raw;
			default:
				return array_values( array_intersect( $def['options'], (array)$raw ) );
		}
	}

	public function isEditable( string $name ): bool {
		return !$this->isSetInLocalSettings( $name ) && ( $this->config->getOnWikiSettings()[$name] ?? false );
	}

	private function isSetInLocalSettings( string $name ): bool {
		return $this->config->getRaw( self::DEFINITIONS[$name]['config'] ) !== null;
	}

	/**
	 * Every setting with its value, default and limits, for the settings screen.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function describe(): array {
		$out = [];
		$rows = $this->getRows();
		foreach ( self::DEFINITIONS as $name => $def ) {
			$row = $rows[$name] ?? null;
			$entry = [
				'value' => $this->get( $name ),
				'default' => $this->getDefault( $name ),
				'editable' => $this->isEditable( $name ),
				'overridden' => $row !== null && $this->isEditable( $name ),
				'type' => $def['type'],
			];
			foreach ( [ 'min', 'minOn', 'max', 'options', 'minItems' ] as $key ) {
				if ( isset( $def[$key] ) ) {
					$entry[$key] = $def[$key];
				}
			}
			if ( $row !== null ) {
				$entry['changedAt'] = ConvertibleTimestamp::convert( TS_ISO_8601, $row['timestamp'] );
				$entry['changedByActor'] = $row['actor'];
			}
			$out[$name] = $entry;
		}
		return $out;
	}

	/**
	 * Validate one value without saving it.
	 *
	 * @param string $name
	 * @param mixed $value
	 */
	public function validateValue( string $name, $value ): StatusValue {
		$def = self::DEFINITIONS[$name] ?? null;
		if ( $def === null ) {
			return StatusValue::newFatal( ApiMessage::create(
				[ 'glowthread-error-setting-unknown', $name ], 'unknownsetting'
			) );
		}
		switch ( $def['type'] ) {
			case 'int':
				if ( !is_int( $value ) && !( is_string( $value ) && preg_match( '/^-?\d+$/', $value ) ) ) {
					return $this->invalid( $name );
				}
				$value = (int)$value;
				if ( $value < $def['min'] || $value > $def['max']
					|| ( isset( $def['minOn'] ) && $value !== $def['min'] && $value < $def['minOn'] )
				) {
					return StatusValue::newFatal( ApiMessage::create( isset( $def['minOn'] )
						? [ 'glowthread-error-setting-range-off', $name, Message::numParam( $def['minOn'] ),
							Message::numParam( $def['max'] ), Message::numParam( $def['min'] ) ]
						: [ 'glowthread-error-setting-range', $name, Message::numParam( $def['min'] ),
							Message::numParam( $def['max'] ) ],
						'badsettingvalue'
					) );
				}
				return StatusValue::newGood();
			case 'bool':
				return is_bool( $value ) ? StatusValue::newGood() : $this->invalid( $name );
			default:
				if ( !is_array( $value ) || !array_is_list( $value ) ) {
					return $this->invalid( $name );
				}
				foreach ( $value as $item ) {
					if ( !is_string( $item ) || !in_array( $item, $def['options'], true ) ) {
						return $this->invalid( $name );
					}
				}
				if ( count( array_unique( $value ) ) < ( $def['minItems'] ?? 0 ) ) {
					return $this->invalid( $name );
				}
				return StatusValue::newGood();
		}
	}

	/**
	 * Change settings. A null value removes the on-wiki override.
	 *
	 * @param array<string,mixed> $changes
	 * @param Authority $performer
	 * @param string $reason
	 * @return StatusValue Value: [ 'changes' => list of [name, old, new] that changed,
	 *   'logId' => ID of the glowthread/settings log entry, null when nothing changed ]
	 */
	public function update( array $changes, Authority $performer, string $reason ): StatusValue {
		$permission = PermissionStatus::newEmpty();
		if ( !$performer->authorizeAction( 'glowthread-configure', $permission ) ) {
			// Only a tripped limit: answer with the wait, like the other write modules
			$onlyRateLimit = $permission->isRateLimitExceeded() && !array_filter(
				$permission->getMessages(), static fn ( $m ) => $m->getKey() !== 'actionthrottledtext'
			);
			return $onlyRateLimit
				? $this->rateLimitErrors->newStatus( 'glowthread-configure', $performer )
				: $permission;
		}
		if ( $this->readOnlyMode->isReadOnly() ) {
			return StatusValue::newFatal( ApiMessage::create( 'apierror-readonly', 'readonly' ) );
		}
		if ( !$changes ) {
			return StatusValue::newFatal( ApiMessage::create( 'glowthread-error-settings-empty', 'nochange' ) );
		}
		$status = StatusValue::newGood();
		foreach ( $changes as $name => $value ) {
			if ( !isset( self::DEFINITIONS[$name] ) ) {
				$status->fatal( ApiMessage::create( [ 'glowthread-error-setting-unknown', $name ], 'unknownsetting' ) );
				continue;
			}
			if ( !$this->isEditable( $name ) ) {
				$status->fatal( ApiMessage::create( [ 'glowthread-error-setting-locked', $name ], 'settinglocked' ) );
				continue;
			}
			if ( $value !== null ) {
				$status->merge( $this->validateValue( $name, $value ) );
			}
		}
		if ( !$status->isOK() ) {
			return $status;
		}

		$dbw = $this->store->getPrimary();
		$actorId = $this->actorNormalization->acquireActorId( $performer->getUser(), $dbw );
		$now = ConvertibleTimestamp::now( TS_MW );
		$applied = [];
		$dbw->startAtomic( __METHOD__ );
		$this->rows = $this->store->getAll( true );
		foreach ( $changes as $name => $value ) {
			$old = $this->get( $name );
			if ( $value === null ) {
				$this->store->delete( $dbw, $name );
				$new = $this->getDefault( $name );
			} else {
				$new = $this->coerce( $name, $value );
				$this->store->set( $dbw, $name, $new, $actorId, $now );
			}
			if ( $old !== $new ) {
				$applied[] = [ $name, $old, $new ];
			}
		}
		$dbw->endAtomic( __METHOD__ );
		// The saved values, read from the primary: in a web request the transaction is still
		// open, and the cached copy would be the old one until it commits
		$this->rows = $this->store->getAll( true );
		$this->rowsLoadedAt = microtime( true );

		$logId = $applied ? $this->logger->logSettings( $performer->getUser(), $applied, $reason ) : null;
		return StatusValue::newGood( [ 'changes' => $applied, 'logId' => $logId ] );
	}

	/**
	 * @param string $name
	 * @param mixed $value Already validated
	 * @return int|bool|string[]
	 */
	private function coerce( string $name, $value ) {
		switch ( self::DEFINITIONS[$name]['type'] ) {
			case 'int':
				return (int)$value;
			case 'bool':
				return (bool)$value;
			default:
				// Keep the canonical order of the options
				return array_values( array_intersect( self::DEFINITIONS[$name]['options'], $value ) );
		}
	}

	private function invalid( string $name ): StatusValue {
		return StatusValue::newFatal( ApiMessage::create(
			[ 'glowthread-error-setting-invalid', $name ], 'badsettingvalue'
		) );
	}

	private function getRows(): array {
		$now = microtime( true );
		if ( $this->rows === null || $now - $this->rowsLoadedAt > self::PROCESS_TTL ) {
			$this->rows = $this->store->getAll();
			$this->rowsLoadedAt = $now;
		}
		return $this->rows;
	}
}
