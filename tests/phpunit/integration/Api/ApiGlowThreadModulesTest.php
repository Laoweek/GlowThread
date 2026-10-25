<?php

namespace MediaWiki\Extension\GlowThread\Tests\Integration\Api;

use MediaWiki\Api\ApiMain;
use MediaWiki\Context\RequestContext;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Request\WebRequest;
use MediaWiki\Session\CsrfTokenSet;

/**
 * Every GlowThread write module needs a CSRF token, POST and write mode.
 *
 * @group Database
 * @covers \MediaWiki\Extension\GlowThread\Api\ApiGlowThreadBase
 */
class ApiGlowThreadModulesTest extends GlowThreadApiTestCase {

	private const MODULES = [
		'glowthreadpost' => [ 'pageid' => 1, 'text' => 'x' ],
		'glowthreadreact' => [ 'id' => 1, 'value' => 1 ],
		'glowthreadreport' => [ 'id' => 1, 'reason' => 'spam' ],
		'glowthreaddelete' => [ 'id' => 1 ],
		'glowthreadmoderate' => [ 'ids' => 1, 'do' => 'delete' ],
		'glowthreadpage' => [ 'pageid' => 1, 'state' => 'locked' ],
		'glowthreadfiltertest' => [ 'text' => 'x' ],
		'glowthreadsettings' => [ 'settings' => '{"maxLength":500}' ],
	];

	public static function provideModules() {
		foreach ( self::MODULES as $name => $params ) {
			yield $name => [ $name, $params ];
		}
	}

	/**
	 * @dataProvider provideModules
	 */
	public function testModuleFlags( string $name ) {
		$main = new ApiMain( new FauxRequest( [] ) );
		$module = $main->getModuleManager()->getModule( $name );
		$this->assertNotNull( $module, "$name is registered" );
		$this->assertSame( 'csrf', $module->needsToken() );
		$this->assertTrue( $module->mustBePosted() );
		$this->assertTrue( $module->isWriteMode() );
	}

	/**
	 * @dataProvider provideModules
	 */
	public function testTokenRequired( string $name, array $params ) {
		$this->expectApiErrorCode( 'missingparam' );
		$this->doApiRequest( [ 'action' => $name ] + $params, null, false, $this->getTestSysop()->getAuthority() );
	}

	/**
	 * @dataProvider provideModules
	 */
	public function testBadTokenRejected( string $name, array $params ) {
		$this->expectApiErrorCode( 'badtoken' );
		$this->doApiRequest(
			[ 'action' => $name, 'token' => 'bad+\\' ] + $params, null, false, $this->getTestSysop()->getAuthority()
		);
	}

	/**
	 * A cross-site GET (token in the query string) is refused before the module runs. This
	 * goes through ApiMain's external request path, as a real web request would.
	 *
	 * @dataProvider provideModules
	 */
	public function testGetRejected( string $name, array $params ) {
		$user = $this->getTestSysop()->getUser();
		// A token as a session of the user has it, not the logged-out '+\' (ApiMain refuses the
		// token in the query string before it checks the value)
		$tokenRequest = new FauxRequest();
		$tokenRequest->getSession()->setUser( $user );
		$token = ( new CsrfTokenSet( $tokenRequest ) )->getToken()->toString();
		$get = [ 'action' => $name, 'format' => 'json', 'token' => $token ] + $params;
		// A real WebRequest reads the superglobals, and only a real one takes ApiMain's external path
		// phpcs:disable MediaWiki.Usage.SuperGlobalsUsage.SuperGlobals
		$backup = [ $_GET, $_POST, $_SERVER['REQUEST_METHOD'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null ];
		$_GET = $get;
		$_POST = [];
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		try {
			$context = new RequestContext();
			$context->setRequest( new WebRequest() );
			$context->setUser( $user );
			$main = new ApiMain( $context, true );
			ob_start();
			$main->execute();
			$output = ob_get_clean();
		} finally {
			[ $_GET, $_POST, $method, $addr ] = $backup;
			$_SERVER['REQUEST_METHOD'] = $method;
			$_SERVER['REMOTE_ADDR'] = $addr;
		}
		// phpcs:enable
		$this->assertMatchesRegularExpression( '/"code":"(mustpostparams|mustbeposted)"/', $output );
		$this->assertSame( 0, (int)$this->getDb()->newSelectQueryBuilder()
			->select( 'COUNT(*)' )->from( 'glowthread_post' )->caller( __METHOD__ )->fetchField() );
	}
}
