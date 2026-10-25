<?php
// Dev settings of the GlowThread test wiki, loaded after the installer output.
// A plain English wiki behind Varnish, with the extensions a comment system talks to:
// Echo, Thanks, SpamBlacklist, CheckUser and MobileFrontend. dev/README.md explains the choices.

// GLOWTHREAD_DEV_LEGACY=1 loads FlowThread instead of GlowThread (import tests).
$glowthreadDevLegacy = getenv( 'GLOWTHREAD_DEV_LEGACY' ) === '1';

// Site. The canonical URL goes through Varnish, so MediaWiki builds links and purges for it.
$wgServer = getenv( 'GLOWTHREAD_DEV_SERVER' ) ?: 'http://localhost:8150';
$wgCanonicalServer = $wgServer;
$wgArticlePath = '/wiki/$1';
$wgSitename = 'GlowThread Dev';
$wgLanguageCode = 'en';
$wgLocaltimezone = 'UTC';
$wgEnableEmail = false;
$wgEnableUserEmail = false;
$wgEnableUploads = false;

// Caches. No Redis: the object, session and parser caches live in the database, which the web
// server, the job runner and maintenance scripts share, so a seed or import run invalidates
// what the web server sees. APCu stays the per-server cache (MediaWiki picks it on its own).
$wgMainCacheType = CACHE_DB;
$wgSessionCacheType = CACHE_DB;

// CDN. MediaWiki sends PURGE requests to Varnish when pages and comment lists change.
$wgUseCdn = true;
$wgCdnServers = [ 'varnish:80' ];
// Trust X-Forwarded-For from the Docker networks (Varnish, and the host through the published
// ports), so a test can pose as another client IP by sending X-Forwarded-For. GlowThread's rate
// limits include per-IP keys that every account would otherwise share in this stack.
$wgCdnServersNoPurge = [ '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16' ];

// Jobs. The jobrunner container runs them as soon as they are queued.
$wgJobRunRate = 0;

// Skins. Vector 2022 on desktop. Legacy Vector (key "vector"), Minerva and Citizen can be
// picked in the preferences or with ?useskin=.
$wgDefaultSkin = 'vector-2022';
if ( is_file( "$IP/skins/Citizen/skin.json" ) ) {
	wfLoadSkin( 'Citizen' );
}

// Mobile. MobileFrontend serves Minerva to phones on the same URLs. Varnish sets the
// X-Subdomain header for phone user agents and keeps a separate cached copy for them.
if ( is_file( "$IP/extensions/MobileFrontend/extension.json" ) ) {
	wfLoadExtension( 'MobileFrontend' );
	$wgMFAutodetectMobileView = true;
	$wgDefaultMobileSkin = 'minerva';
}

// Bundled extensions a comment system talks to.
wfLoadExtensions( [ 'Echo', 'Thanks', 'SpamBlacklist' ] );
// No remote blacklist: the local MediaWiki:Spam-blacklist page (seeded by setup.sh) blocks
// links to spam.example.
$wgBlacklistSettings = [ 'spam' => [ 'files' => [] ] ];

if ( is_file( "$IP/extensions/CheckUser/extension.json" ) ) {
	wfLoadExtension( 'CheckUser' );
}

// Comments: FlowThread in legacy mode, otherwise GlowThread. Never both, they both claim
// action=flowthread. update.php leaves the tables of the unloaded one alone.
$glowthreadDevLoaded = null;
if ( $glowthreadDevLegacy ) {
	wfLoadExtension( 'FlowThread' );
	// Refuse writes by GET, as a careful FlowThread wiki would.
	$wgFlowThreadEnforcePost = true;
	// A local placeholder avatar instead of FlowThread's default, an external image.
	$wgFlowThreadConfig['Avatar'] = $wgFlowThreadConfig['AnonymousAvatar'] = 'data:image/svg+xml,' . rawurlencode(
		'<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><circle cx="20" cy="20" r="20" fill="#c8ccd1"/></svg>'
	);
	$glowthreadDevLoaded = 'FlowThread';
} else {
	// An extension.json that does not parse is skipped instead of breaking the wiki while
	// it is being edited.
	$glowthreadDevJson = "$IP/extensions/GlowThread/extension.json";
	if ( is_file( $glowthreadDevJson ) && json_decode( (string)file_get_contents( $glowthreadDevJson ) ) !== null ) {
		wfLoadExtension( 'GlowThread' );
		$glowthreadDevLoaded = 'GlowThread';
	}
}

// A dev group for long-time members whose comments skip the new-account gate and are never
// held by reports alone (Trent is in it).
$wgGroupPermissions['trusted']['glowthread-trusted'] = true;

if ( $glowthreadDevLoaded === 'GlowThread' ) {
	// Defaults, plus comments on user pages so user-page notifications can be tested.
	$wgGlowThreadNamespaces = [ NS_MAIN => true, NS_USER => true ];
	// Off by default. import-test.sh turns it on, as a wiki coming from FlowThread would.
	$wgGlowThreadLegacyCompat = getenv( 'GLOWTHREAD_DEV_LEGACY_COMPAT' ) === '1';

	// Keep the parser cache on, but invalidate it whenever a PHP or JSON file of the extension
	// changes, so server-side changes show on the next uncached view. Hidden folders and
	// folders that are not part of the extension are skipped.
	$glowthreadDevNewest = 0;
	$glowthreadDevFiles = new RecursiveIteratorIterator( new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( "$IP/extensions/GlowThread", FilesystemIterator::SKIP_DOTS ),
		static function ( SplFileInfo $file ): bool {
			$name = $file->getFilename();
			return $name[0] !== '.' && !in_array( $name,
				[ 'client', 'dev', 'docs', 'node_modules', 'private', 'tests', 'vendor' ], true );
		}
	) );
	foreach ( $glowthreadDevFiles as $glowthreadDevFile ) {
		if ( preg_match( '/\.(php|json)$/', $glowthreadDevFile->getFilename() ) ) {
			$glowthreadDevNewest = max( $glowthreadDevNewest, $glowthreadDevFile->getMTime() );
		}
	}
	$wgCacheEpoch = max( $wgCacheEpoch, gmdate( 'YmdHis', $glowthreadDevNewest ) );
}

// Debugging. Exceptions, PHP warnings and deprecations go to log files in the data volume:
// `docker compose exec mediawiki sh -c 'tail -n 50 /var/www/data/logs/*.log'`.
$wgShowExceptionDetails = true;
$wgDevelopmentWarnings = true;
error_reporting( E_ALL );
$wgDebugLogGroups['exception'] = '/var/www/data/logs/exception.log';
$wgDebugLogGroups['error'] = '/var/www/data/logs/error.log';
$wgDebugLogGroups['fatal'] = '/var/www/data/logs/fatal.log';
$wgDebugLogGroups['GlowThread'] = '/var/www/data/logs/glowthread.log';
ini_set( 'error_log', '/var/www/data/logs/php.log' );

// ResourceLoader debug mode: off by default. `touch dev/config/rl-debug.on` turns it on for
// every request (remove the file to turn it off), `?debug=1` for one page.
$wgResourceLoaderDebug = is_file( __DIR__ . '/rl-debug.on' );
// Do not let browsers keep the startup module for 5 minutes after a client change.
$wgResourceLoaderMaxage = [ 'unversioned' => 1 ];
