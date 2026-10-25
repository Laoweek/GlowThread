<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration;

use MediaWiki\Logger\LoggerFactory;
use Psr\Log\AbstractLogger;
use Wikimedia\Rdbms\IDatabase;

/**
 * Counts the queries the test database runs, by the method that ran them (the caller name of
 * the query builder). For MediaWikiIntegrationTestCase subclasses: the wiki's code uses the
 * same connection as getDb() in tests.
 */
trait QueryCounterTrait {

	/**
	 * @param callable $callback
	 * @return array<string,int> Method => number of queries, for the queries $callback ran
	 */
	protected function gtCountQueries( callable $callback ): array {
		$logger = new class extends AbstractLogger {
			/** @var array<string,int> */
			public array $counts = [];

			/** @inheritDoc */
			public function log( $level, $message, array $context = [] ): void {
				if ( ( $context['db_log_category'] ?? null ) === 'query' ) {
					$method = (string)( $context['method'] ?? '' );
					$this->counts[$method] = ( $this->counts[$method] ?? 0 ) + 1;
				}
			}
		};
		$db = $this->getDb();
		$db->setLogger( $logger );
		$db->setFlag( IDatabase::DBO_DEBUG, IDatabase::REMEMBER_PRIOR );
		try {
			$callback();
		} finally {
			$db->restoreFlags();
			// The logger the connection had (MWLBFactory)
			$db->setLogger( LoggerFactory::getInstance( 'rdbms' ) );
		}
		return $logger->counts;
	}

	/**
	 * @param array<string,int> $counts From gtCountQueries()
	 * @param string $method A method name or the end of one (Class::method)
	 * @return int Queries run by methods whose name ends with $method
	 */
	protected function gtQueriesBy( array $counts, string $method ): int {
		$n = 0;
		foreach ( $counts as $name => $count ) {
			if ( str_ends_with( $name, $method ) ) {
				$n += $count;
			}
		}
		return $n;
	}
}
