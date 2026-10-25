<?php
// Settings entry point of the GlowThread dev wiki, selected with MW_CONFIG_FILE in
// dev/compose.yaml. It loads the installer output (database credentials and secret keys)
// from the data volume, then the dev settings in LocalSettings.dev.php.

$glowthreadDevBase = '/var/www/data/LocalSettings.php';
if ( !is_file( $glowthreadDevBase ) ) {
	if ( PHP_SAPI !== 'cli' ) {
		http_response_code( 503 );
		header( 'Content-Type: text/plain; charset=utf-8' );
	}
	echo "The dev wiki is not installed yet. Run dev/setup.sh.\n";
	exit( 1 );
}
require $glowthreadDevBase;
require __DIR__ . '/LocalSettings.dev.php';
