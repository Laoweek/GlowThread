<?php

namespace MediaWiki\Extension\GlowThread\Tests\Unit\Import;

use InvalidArgumentException;
use MediaWiki\Extension\GlowThread\Import\ImportOptions;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\GlowThread\Import\ImportOptions
 */
class ImportOptionsTest extends MediaWikiUnitTestCase {

	public function testDefaultsAreTheRecommendations() {
		$options = new ImportOptions();
		$this->assertSame( [
			'pageId' => null, 'update' => false, 'spam' => 'held', 'optedOut' => 'locked',
			'reportsOnDeleted' => 'resolved', 'emptyText' => 'keep', 'hidden' => 'deleted', 'spoilers' => 'keep',
			'images' => 'drop', 'selfAttitudes' => 'keep', 'missingUserPrefix' => 'imported', 'batchSize' => 200,
			'log' => true, 'disabledPages' => 'locked',
		], $options->toArray() );
	}

	public static function provideInvalid() {
		yield 'spam' => [ [ 'spam' => 'erased' ] ];
		yield 'opted out' => [ [ 'optedOut' => 'closed' ] ];
		yield 'disabled pages' => [ [ 'disabledPages' => 'open' ] ];
		yield 'reports' => [ [ 'reportsOnDeleted' => 'dismissed' ] ];
		yield 'empty' => [ [ 'emptyText' => 'drop' ] ];
		yield 'hidden' => [ [ 'hidden' => 'held' ] ];
		yield 'spoilers' => [ [ 'spoilers' => 'drop' ] ];
		yield 'images' => [ [ 'images' => 'keep' ] ];
		yield 'self attitudes' => [ [ 'selfAttitudes' => 'skip' ] ];
		yield 'batch size' => [ [ 'batchSize' => 0 ] ];
		yield 'prefix with >' => [ [ 'missingUserPrefix' => 'a>b' ] ];
		yield 'empty prefix' => [ [ 'missingUserPrefix' => '' ] ];
	}

	/**
	 * @dataProvider provideInvalid
	 */
	public function testRejectsUnknownChoices( array $args ) {
		$this->expectException( InvalidArgumentException::class );
		new ImportOptions( ...$args );
	}
}
