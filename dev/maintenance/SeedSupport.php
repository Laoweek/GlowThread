<?php
// Dev only: helpers shared by the seed scripts in this folder.

use MediaWiki\Context\RequestContext;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Request\FauxRequest;
use MediaWiki\User\User;
use Wikimedia\IPUtils;
use Wikimedia\Timestamp\ConvertibleTimestamp;

trait GlowThreadDevSeedSupport {

	/** @var array<string,User> */
	private array $seedUsers = [];

	/**
	 * Read a JSON file of the seed content.
	 */
	private function readJson( string $path ): array {
		if ( !is_file( $path ) ) {
			$this->fatalError( "Missing seed file $path" );
		}
		$data = json_decode( (string)file_get_contents( $path ), true );
		if ( !is_array( $data ) ) {
			$this->fatalError( "$path is not valid JSON: " . json_last_error_msg() );
		}
		return $data;
	}

	/**
	 * Seconds in an age such as "20m", "3h" or "45d".
	 */
	private function parseAgo( string $ago ): int {
		if ( !preg_match( '/^(\d+)([mhd])$/', $ago, $m ) ) {
			$this->fatalError( "Bad age \"$ago\": use a number followed by m, h or d" );
		}
		return (int)$m[1] * [ 'm' => 60, 'h' => 3600, 'd' => 86400 ][$m[2]];
	}

	/**
	 * The user behind an author string of the seed content: an account name, or
	 * "ip:<address>" for an anonymous reader. Accounts must exist (setup.sh creates them).
	 */
	private function seedUser( string $by ): User {
		if ( isset( $this->seedUsers[$by] ) ) {
			return $this->seedUsers[$by];
		}
		$userFactory = $this->getServiceContainer()->getUserFactory();
		if ( str_starts_with( $by, 'ip:' ) ) {
			$ip = substr( $by, 3 );
			if ( !IPUtils::isValid( $ip ) ) {
				$this->fatalError( "Bad IP address in \"$by\"" );
			}
			$user = $userFactory->newAnonymous( IPUtils::sanitizeIP( $ip ) );
		} else {
			$user = $userFactory->newFromName( $by );
			if ( !$user || !$user->isRegistered() ) {
				$this->fatalError( "Unknown account \"$by\" (setup.sh creates the dev accounts)" );
			}
		}
		$this->seedUsers[$by] = $user;
		return $user;
	}

	/**
	 * Run $callback as if the clock showed $unixTime, then run the deferred updates it queued
	 * (notifications, CheckUser rows) at that time too. With $by, the request seems to come
	 * from that author: an anonymous author's own address, or a fixed address per account in
	 * the documentation range 192.0.2.0/24, with a browser user agent (CheckUser records both).
	 *
	 * @return mixed What $callback returned
	 */
	private function atTime( int $unixTime, callable $callback, ?string $by = null ) {
		$context = RequestContext::getMain();
		$previousRequest = $context->getRequest();
		if ( $by !== null ) {
			$request = new FauxRequest( [], true );
			$request->setIP( str_starts_with( $by, 'ip:' )
				? IPUtils::sanitizeIP( substr( $by, 3 ) )
				: '192.0.2.' . ( 10 + crc32( $by ) % 240 ) );
			$request->setHeader( 'User-Agent',
				'Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0' );
			$context->setRequest( $request );
		}
		$previous = ConvertibleTimestamp::setFakeTime( $unixTime );
		try {
			$result = $callback();
			DeferredUpdates::doUpdates();
			return $result;
		} finally {
			ConvertibleTimestamp::setFakeTime( $previous );
			$context->setRequest( $previousRequest );
		}
	}
}
