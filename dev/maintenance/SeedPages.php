<?php
// Dev only: save the wiki pages listed in a seed folder's pages.json.
//
//   php maintenance/run.php /dev-maintenance/SeedPages.php --dir=/dev-seed/content
//
// Each entry is { "title", "file" } (file relative to <dir>/pages/), with an optional "by"
// (the account that saves it, default Admin). A missing page is created with a backdated first
// revision (years before the seeded comments). A page whose text differs from its file gets a
// new revision now. A page that matches its file is left alone, so the script is safe to run
// again. Other keys (such as "state") are for SeedComments.php.

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

require_once __DIR__ . '/SeedSupport.php';

class GlowThreadDevSeedPages extends Maintenance {
	use GlowThreadDevSeedSupport;

	/** First revisions are backdated this far, one hour apart in file order */
	private const CREATED_AGO = 3 * 365 * 86400;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Dev only: save the seed pages' );
		$this->addOption( 'dir', 'Seed folder with pages.json and pages/', true, true );
	}

	public function execute() {
		$dir = $this->getOption( 'dir' );
		foreach ( array_values( $this->readJson( "$dir/pages.json" ) ) as $index => $entry ) {
			$title = Title::newFromText( (string)( $entry['title'] ?? '' ) );
			if ( !$title || !isset( $entry['file'] ) ) {
				$this->fatalError( "$dir/pages.json: bad entry " . json_encode( $entry ) );
			}
			$author = $this->seedUser( (string)( $entry['by'] ?? 'Admin' ) );
			$this->savePage( $title, "$dir/pages/{$entry['file']}", $author, $index );
		}
	}

	private function savePage( Title $title, string $file, User $author, int $index ): void {
		if ( !is_file( $file ) ) {
			$this->fatalError( "Missing page file $file" );
		}
		$text = rtrim( (string)file_get_contents( $file ) ) . "\n";
		$services = $this->getServiceContainer();
		$page = $services->getWikiPageFactory()->newFromTitle( $title );
		$current = $page->getContent();
		if ( $current && rtrim( $current->serialize() ) === rtrim( $text ) ) {
			return;
		}
		$content = ContentHandler::makeContent( $text, $title );
		$save = static function () use ( $page, $author, $content, $current ) {
			$updater = $page->newPageUpdater( $author );
			$updater->setContent( SlotRecord::MAIN, $content );
			$updater->saveRevision(
				CommentStoreComment::newUnsavedComment( $current ? 'Update from dev/seed' : 'Seed page' ),
				$current ? EDIT_UPDATE : EDIT_NEW
			);
			return $updater->getStatus();
		};
		$status = $current
			? $this->atTime( time(), $save )
			: $this->atTime( time() - self::CREATED_AGO + $index * 3600, $save );
		if ( !$status->isOK() ) {
			$this->fatalError( "Could not save {$title->getPrefixedText()}: " . $status->getWikiText( false, false, 'en' ) );
		}
		$this->output( ( $current ? 'updated ' : 'created ' ) . $title->getPrefixedText() . "\n" );
	}
}

return GlowThreadDevSeedPages::class;
