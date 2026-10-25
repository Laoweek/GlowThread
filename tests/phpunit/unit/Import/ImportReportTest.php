<?php

namespace MediaWiki\Extension\GlowThread\Tests\Unit\Import;

use MediaWiki\Extension\GlowThread\Import\ImportReport;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\GlowThread\Import\ImportReport
 */
class ImportReportTest extends MediaWikiUnitTestCase {

	public function testCountsIdsAndMismatches() {
		$report = new ImportReport( 'verify' );
		$report->add( 'comments', 'read', 3 );
		$report->add( 'comments', 'read' );
		$report->set( 'floors', 'highest floor', 7 );
		for ( $i = 0; $i < 25; $i++ ) {
			$report->addId( 'warning truncated', sprintf( '%022x', $i ), 'unclosed div p' );
		}
		$report->mismatch( 'comment x differs' );
		$report->time( 'comments', 0.5 );
		$report->time( 'comments', 0.25 );

		$this->assertSame( 4, $report->get( 'comments', 'read' ) );
		$this->assertSame( 0, $report->get( 'comments', 'missing key' ) );
		$this->assertCount( 25, $report->getIds( 'warning truncated' ) );
		$this->assertSame( [ 'comment x differs' ], $report->getMismatches() );

		$text = $report->render();
		$this->assertStringContainsString( 'FlowThread import report (verify)', $text );
		$this->assertStringContainsString( 'warning truncated: 25 (5 more in the report file)', $text );
		$this->assertStringNotContainsString( sprintf( '%022x', 24 ), $text, 'Only the first 20 IDs' );
		$this->assertStringContainsString( '0.75 s', $text );
		$this->assertStringContainsString( 'Mismatches: 1', $text );

		$array = $report->toArray();
		$this->assertSame( 'verify', $array['mode'] );
		$this->assertCount( 25, $array['ids']['warning truncated'] );
		$this->assertSame( 0.75, $array['timings']['comments'] );
	}
}
