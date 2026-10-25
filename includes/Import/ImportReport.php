<?php

namespace MediaWiki\Extension\GlowThread\Import;

/**
 * Counts, legacy IDs for review, mismatches and timings of one import run.
 *
 * Holds no comment text, and no user names or IP addresses: IDs are FlowThread comment IDs
 * (22 hex digits), and details are warning details such as an image URL or a missing tag.
 */
class ImportReport {

	/** IDs listed per category when the report is rendered as text */
	private const SHOWN_IDS = 20;

	/** @var array<string,array<string,int>> Section => key => count */
	private array $counts = [];
	/** @var array<string,string[]> Category => legacy IDs (with an optional ": detail") */
	private array $ids = [];
	/** @var string[] */
	private array $mismatches = [];
	/** @var array<string,float> Phase => seconds */
	private array $timings = [];
	/** @var array<string,mixed> */
	private array $info = [];

	public function __construct( public readonly string $mode ) {
	}

	public function add( string $section, string $key, int $n = 1 ): void {
		$this->counts[$section][$key] = ( $this->counts[$section][$key] ?? 0 ) + $n;
	}

	public function set( string $section, string $key, int $n ): void {
		$this->counts[$section][$key] = $n;
	}

	public function get( string $section, string $key ): int {
		return $this->counts[$section][$key] ?? 0;
	}

	public function addId( string $category, string $legacyId, string $detail = '' ): void {
		$this->ids[$category][] = $detail === '' ? $legacyId : "$legacyId: $detail";
	}

	/**
	 * @return string[]
	 */
	public function getIds( string $category ): array {
		return $this->ids[$category] ?? [];
	}

	public function mismatch( string $message ): void {
		$this->mismatches[] = $message;
	}

	/**
	 * @return string[]
	 */
	public function getMismatches(): array {
		return $this->mismatches;
	}

	/**
	 * @param string $key
	 * @param mixed $value Shown in the report header
	 */
	public function setInfo( string $key, $value ): void {
		$this->info[$key] = $value;
	}

	public function time( string $phase, float $seconds ): void {
		$this->timings[$phase] = ( $this->timings[$phase] ?? 0.0 ) + $seconds;
	}

	/**
	 * @return array<string,mixed> Everything, for a JSON report file
	 */
	public function toArray(): array {
		return [
			'mode' => $this->mode,
			'info' => $this->info,
			'counts' => $this->counts,
			'ids' => $this->ids,
			'mismatches' => $this->mismatches,
			'timings' => array_map( static fn ( float $s ) => round( $s, 3 ), $this->timings ),
		];
	}

	/**
	 * @return string Plain-text report for the console
	 */
	public function render(): string {
		$lines = [ "FlowThread import report ({$this->mode})" ];
		foreach ( $this->info as $key => $value ) {
			$lines[] = sprintf( '  %-24s %s', $key, is_scalar( $value ) ? (string)$value : json_encode( $value ) );
		}
		foreach ( $this->counts as $section => $counts ) {
			if ( $section === 'text' ) {
				ksort( $counts );
			}
			$lines[] = '';
			$lines[] = $section;
			foreach ( $counts as $key => $n ) {
				$lines[] = sprintf( '  %-40s %9d', $key, $n );
			}
		}
		if ( $this->ids ) {
			$lines[] = '';
			$lines[] = 'For review (FlowThread IDs)';
			foreach ( $this->ids as $category => $ids ) {
				$more = count( $ids ) > self::SHOWN_IDS
					? ' (' . ( count( $ids ) - self::SHOWN_IDS ) . ' more in the report file)' : '';
				$lines[] = "  $category: " . count( $ids ) . $more;
				foreach ( array_slice( $ids, 0, self::SHOWN_IDS ) as $id ) {
					$lines[] = "    $id";
				}
			}
		}
		$lines[] = '';
		$lines[] = 'Timings';
		foreach ( $this->timings as $phase => $seconds ) {
			$lines[] = sprintf( '  %-24s %8.2f s', $phase, $seconds );
		}
		$lines[] = '';
		if ( $this->mismatches ) {
			$lines[] = 'Mismatches: ' . count( $this->mismatches );
			foreach ( array_slice( $this->mismatches, 0, 50 ) as $message ) {
				$lines[] = "  $message";
			}
		} else {
			$lines[] = 'Mismatches: 0';
		}
		return implode( "\n", $lines ) . "\n";
	}
}
