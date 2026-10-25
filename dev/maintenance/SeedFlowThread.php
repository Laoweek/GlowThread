<?php
// Dev only, legacy mode: post the made-up comments of dev/seed/content/flowthread.json through
// FlowThread's own API (action=flowthread, POST, as each author), with their replies, likes and
// reports, so the import test has FlowThread data to read.
//
//   php maintenance/run.php /dev-maintenance/SeedFlowThread.php
//
// FlowThread derives a comment's time from its ID, which it generates from the real clock, so
// these comments are dated at seeding time (flowthread.json has no ages). A page that already has
// FlowThread comments is skipped, so the script is safe to run again.

use MediaWiki\Api\ApiMain;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\RequestContext;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

require_once __DIR__ . '/SeedSupport.php';

class GlowThreadDevSeedFlowThread extends Maintenance {
	use GlowThreadDevSeedSupport;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Dev only: seed FlowThread comments in legacy mode' );
		$this->addOption( 'content', 'Seed content folder', false, true );
	}

	public function execute() {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'FlowThread' ) ) {
			$this->fatalError( 'FlowThread is not loaded. Run setup.sh with GLOWTHREAD_DEV_LEGACY=1.' );
		}
		$dir = $this->getOption( 'content', '/dev-seed/content' );
		foreach ( $this->readJson( "$dir/flowthread.json" ) as $titleText => $comments ) {
			$title = Title::newFromText( (string)$titleText );
			if ( !$title || !$title->exists() ) {
				$this->fatalError( "Page \"$titleText\" does not exist (SeedPages.php creates the pages)" );
			}
			$pageId = $title->getArticleID();
			$existing = (int)$this->getPrimaryDB()->newSelectQueryBuilder()
				->select( 'COUNT(*)' )
				->from( 'FlowThread' )
				->where( [ 'flowthread_pageid' => $pageId ] )
				->caller( __METHOD__ )
				->fetchField();
			if ( $existing > 0 ) {
				$this->output( "$titleText: has $existing FlowThread comments, not seeded\n" );
				continue;
			}
			// Every author must exist before the first write, so a typo cannot leave half a page.
			array_walk_recursive( $comments, function ( $value, $key ) {
				if ( $key === 'by' || is_int( $key ) && is_string( $value ) ) {
					$this->seedUser( $value );
				}
			} );
			$counts = [ 'posts' => 0, 'likes' => 0, 'reports' => 0 ];
			foreach ( $comments as $comment ) {
				$this->postThread( $pageId, $comment, null, $counts );
			}
			$this->output( "$titleText: {$counts['posts']} FlowThread comments, {$counts['likes']} likes, " .
				"{$counts['reports']} reports\n" );
		}
	}

	private function postThread( int $pageId, array $c, ?string $parentId, array &$counts ): void {
		$author = $this->seedUser( (string)( $c['by'] ?? '' ) );
		// Wikitext, so the bold, italics and links in the content render as FlowThread renders them
		$params = [ 'type' => 'post', 'pageid' => $pageId, 'content' => (string)( $c['text'] ?? '' ), 'wikitext' => 1 ];
		if ( $parentId !== null ) {
			$params['postid'] = $parentId;
		}
		$this->api( $author, $params );
		$id = $this->newestPostId( $pageId, $author );
		$counts['posts']++;
		foreach ( $c['likes'] ?? [] as $liker ) {
			$this->api( $this->seedUser( $liker ), [ 'type' => 'like', 'postid' => $id ] );
			$counts['likes']++;
		}
		// FlowThread reports carry no reason
		foreach ( $c['reports'] ?? [] as $report ) {
			$this->api( $this->seedUser( $report['by'] ), [ 'type' => 'report', 'postid' => $id ] );
			$counts['reports']++;
		}
		foreach ( $c['replies'] ?? [] as $reply ) {
			$this->postThread( $pageId, $reply, $id, $counts );
		}
	}

	/**
	 * The API answers a post with nothing, so take the newest comment of the author on the page
	 * (FlowThread IDs grow with time).
	 */
	private function newestPostId( int $pageId, User $author ): string {
		$id = $this->getPrimaryDB()->newSelectQueryBuilder()
			->select( 'flowthread_id' )
			->from( 'FlowThread' )
			->where( [ 'flowthread_pageid' => $pageId, 'flowthread_username' => $author->getName() ] )
			->orderBy( 'flowthread_id', 'DESC' )
			->limit( 1 )
			->caller( __METHOD__ )
			->fetchField();
		if ( $id === false ) {
			$this->fatalError( "Posting as {$author->getName()} on page $pageId failed" );
		}
		return str_pad( bin2hex( $id ), 22, '0', STR_PAD_LEFT );
	}

	private function api( User $user, array $params ): void {
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setRequest( new FauxRequest( [ 'action' => 'flowthread', 'format' => 'json' ] + $params, true ) );
		$context->setUser( $user );
		$api = new ApiMain( $context, true );
		$api->execute();
		$data = $api->getResult()->getResultData( null, [ 'Strip' => 'all' ] );
		if ( isset( $data['error'] ) ) {
			$this->fatalError( "FlowThread API error for {$params['type']} as {$user->getName()}: " .
				json_encode( $data['error'] ) );
		}
	}
}

return GlowThreadDevSeedFlowThread::class;
