<?php
/**
 * Import FlowThread's comments, likes, reports and page settings into GlowThread.
 *
 * Usage:
 *   php maintenance/run.php GlowThread:importFlowThread --dry-run
 *   php maintenance/run.php GlowThread:importFlowThread
 *   php maintenance/run.php GlowThread:importFlowThread --verify
 *
 * See docs/migration-data.md for the mapping.
 */

namespace MediaWiki\Extension\GlowThread\Maintenance;

use InvalidArgumentException;
use MediaWiki\Extension\GlowThread\Import\FlowThreadImporter;
use MediaWiki\Extension\GlowThread\Import\ImportOptions;
use MediaWiki\Extension\GlowThread\Import\LegacyHtmlConverter;
use MediaWiki\Extension\GlowThread\Import\LegacySource;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\User\User;
use Wikimedia\Timestamp\ConvertibleTimestamp;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

class ImportFlowThread extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'GlowThread' );
		$this->addDescription(
			'Import FlowThread comments, likes, reports and page settings into GlowThread. Safe to re-run: ' .
			'the FlowThread ID is the key. Never writes to the FlowThread tables.'
		);
		$this->addOption( 'dry-run', 'Plan and report, write nothing' );
		$this->addOption( 'verify', 'Write nothing, compare GlowThread with FlowThread, exit 1 on any mismatch' );
		$this->addOption( 'page', 'Only this page ID', false, true );
		$this->addOption( 'update', 'Also rewrite imported comments, likes, reports and page states ' .
			'that differ from FlowThread now (default: only add what is missing). What was decided in ' .
			'GlowThread is kept: erased comments, statuses, report resolutions and page states set there, ' .
			'and comments moved to a new page ID after an undelete' );
		$this->addOption( 'server', 'Server for absolute links to wiki pages in converted text ' .
			'(default $wgCanonicalServer)', false, true );
		$this->addOption( 'spam', 'FlowThread spam (status 2): held (default) or deleted', false, true );
		$this->addOption( 'disabled-pages', 'Pages where an admin turned FlowThread off (control status 2), ' .
			'which kept showing their comments: locked (default) or disabled (comments hidden)', false, true );
		$this->addOption( 'opted-out', 'Pages opted out of FlowThread (control status 1): ' .
			'locked (default), disabled or open', false, true );
		$this->addOption( 'reports-on-deleted', 'Reports on deleted comments: resolved (default) or open',
			false, true );
		$this->addOption( 'empty', 'Comments empty after conversion: keep (default) or delete', false, true );
		$this->addOption( 'hidden', 'Normal comments FlowThread did not show but GlowThread would: ' .
			'deleted (default) or visible', false, true );
		$this->addOption( 'spoilers', 'Comments with a revealed spoiler: keep (default) or hold', false, true );
		$this->addOption( 'images', 'Images in comments: drop (default) or link (replace by their URL)', false, true );
		$this->addOption( 'self-attitudes', 'Likes and reports on one\'s own comment: keep (default) or drop',
			false, true );
		$this->addOption( 'missing-user-prefix', 'Actor prefix for authors whose user ID is gone ' .
			'(default "imported", giving "imported>Name")', false, true );
		$this->addOption( 'user', 'Performer of the log entry and of resolved reports ' .
			'(default "Maintenance script")', false, true );
		$this->addOption( 'no-log', 'Do not write the glowthread/import log entry' );
		$this->addOption( 'report-file', 'Also write the full report (all IDs) as JSON to this file', false, true );
		$this->setBatchSize( 200 );
	}

	public function execute() {
		$dryRun = $this->hasOption( 'dry-run' );
		$verify = $this->hasOption( 'verify' );
		if ( $dryRun && $verify ) {
			$this->fatalError( 'Use either --dry-run or --verify.' );
		}
		$mode = $verify ? FlowThreadImporter::MODE_VERIFY
			: ( $dryRun ? FlowThreadImporter::MODE_DRY_RUN : FlowThreadImporter::MODE_APPLY );
		try {
			$page = $this->getOption( 'page' );
			if ( $page !== null && !ctype_digit( (string)$page ) ) {
				throw new InvalidArgumentException( '--page needs a page ID (a positive number)' );
			}
			$options = new ImportOptions(
				$page === null ? null : (int)$page,
				$this->hasOption( 'update' ),
				$this->getOption( 'spam', 'held' ),
				$this->getOption( 'opted-out', 'locked' ),
				$this->getOption( 'reports-on-deleted', 'resolved' ),
				$this->getOption( 'empty', 'keep' ),
				$this->getOption( 'hidden', 'deleted' ),
				$this->getOption( 'spoilers', 'keep' ),
				$this->getOption( 'images', 'drop' ),
				$this->getOption( 'self-attitudes', 'keep' ),
				$this->getOption( 'missing-user-prefix', 'imported' ),
				(int)$this->getBatchSize(),
				!$this->hasOption( 'no-log' ),
				$this->getOption( 'disabled-pages', 'locked' )
			);
		} catch ( InvalidArgumentException $e ) {
			$this->fatalError( $e->getMessage() );
		}

		$services = $this->getServiceContainer();
		$config = $services->getMainConfig();
		$userNamespaces = [ $services->getNamespaceInfo()->getCanonicalName( NS_USER ) ];
		foreach ( $services->getContentLanguage()->getNamespaceIds() as $name => $ns ) {
			if ( $ns === NS_USER ) {
				$userNamespaces[] = (string)$name;
			}
		}
		$converter = new LegacyHtmlConverter(
			$services->get( 'GlowThread.TextProcessor' ),
			$this->getOption( 'server', $config->get( MainConfigNames::CanonicalServer ) ),
			$config->get( MainConfigNames::ArticlePath ),
			$config->get( MainConfigNames::Script ),
			$userNamespaces,
			$options->images === 'link'
		);

		// Like core's deleteBatch.php: only the reserved maintenance user may be taken over
		$userName = $this->getOption( 'user' );
		if ( $userName === null ) {
			$performer = $mode === FlowThreadImporter::MODE_APPLY
				? User::newSystemUser( User::MAINTENANCE_SCRIPT_USER, [ 'steal' => true ] )
				: $services->getUserFactory()->newFromName( User::MAINTENANCE_SCRIPT_USER, 'valid' );
		} else {
			$performer = $services->getUserFactory()->newFromName( $userName );
			if ( $performer && !$performer->isRegistered() ) {
				$this->fatalError( "No such user \"$userName\"." );
			}
		}
		if ( !$performer ) {
			$this->fatalError( 'Invalid user name.' );
		}

		$importer = new FlowThreadImporter(
			$services->getConnectionProvider(),
			$converter,
			$services->getActorNormalization(),
			$services->get( 'GlowThread.PageStateStore' ),
			$services->getTempUserConfig(),
			$services->getUserIdentityLookup(),
			$services->get( 'GlowThread.TextProcessor' ),
			function (): void {
				$this->waitForReplication();
			},
			function ( string $message ): void {
				$this->output( "  $message\n" );
			},
			$services->get( 'GlowThread.ListCache' )
		);
		$this->output( "FlowThread import, mode $mode\n" );
		$start = microtime( true );
		$report = $importer->run(
			new LegacySource( $this->getReplicaDB() ),
			$options,
			$mode,
			$performer,
			ConvertibleTimestamp::now( TS_MW )
		);
		$report->time( 'total', microtime( true ) - $start );
		$this->output( $report->render() );

		$file = $this->getOption( 'report-file' );
		if ( $file !== null ) {
			$json = json_encode(
				$report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);
			if ( file_put_contents( $file, $json . "\n" ) === false ) {
				$this->error( "Could not write $file" );
			}
		}
		if ( $report->getMismatches() ) {
			$this->fatalError( 'Mismatches found: ' . count( $report->getMismatches() ) );
		}
	}
}

// @codeCoverageIgnoreStart
$maintClass = ImportFlowThread::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
