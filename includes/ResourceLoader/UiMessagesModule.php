<?php

namespace MediaWiki\Extension\GlowThread\ResourceLoader;

use MediaWiki\MediaWikiServices;
use MediaWiki\ResourceLoader\FileModule;
use Wikimedia\ObjectCache\BagOStuff;

/**
 * A file module that ships every message key found in i18n/ui/en.json, so the browser app can
 * add messages without changes to extension.json. Set the file with the module option
 * "uiMessagesFile" (relative to the extension directory). The option "uiMessagesExcludePrefix"
 * leaves out the keys that start with it (the reader app does not ship the moderation hub's
 * glowthread-ui-admin-* messages).
 *
 * ResourceLoader makes the module for every request that lists or loads it, so the keys read
 * from the file are kept in the server's local cache, under a hash of the file's content.
 *
 * The version of the module follows what it sends: FileModule's definition summary has the
 * message blob, made from getMessages() with the text of every key that has one. A key without
 * any text adds nothing to the blob, so it does not change what the module sends either.
 */
class UiMessagesModule extends FileModule {

	/** @var string[] */
	private array $uiMessages = [];

	/**
	 * @param array $options
	 * @param string|null $localBasePath
	 * @param string|null $remoteBasePath
	 */
	public function __construct( array $options = [], ?string $localBasePath = null, ?string $remoteBasePath = null ) {
		parent::__construct( $options, $localBasePath, $remoteBasePath );
		if ( isset( $options['uiMessagesFile'] ) ) {
			$this->uiMessages = self::readKeysCached(
				MediaWikiServices::getInstance()->getLocalServerObjectCache(),
				$this->getLocalPath( $options['uiMessagesFile'] )
			);
		}
		$exclude = (string)( $options['uiMessagesExcludePrefix'] ?? '' );
		if ( $exclude !== '' ) {
			$this->uiMessages = array_values( array_filter(
				$this->uiMessages, static fn ( string $key ) => !str_starts_with( $key, $exclude )
			) );
		}
	}

	/**
	 * readKeys(), remembered by the content of the file: hashing it costs a fraction of
	 * decoding it.
	 *
	 * @param BagOStuff $cache
	 * @param string $path
	 * @return string[]
	 */
	public static function readKeysCached( BagOStuff $cache, string $path ): array {
		$hash = is_readable( $path ) ? hash_file( 'xxh128', $path ) : false;
		if ( $hash === false ) {
			return self::readKeys( $path );
		}
		return $cache->getWithSetCallback(
			$cache->makeKey( 'glowthread-ui-message-keys', $hash ),
			BagOStuff::TTL_DAY,
			static fn () => self::readKeys( $path )
		);
	}

	/**
	 * @param string $path
	 * @return string[] Message keys, without @metadata
	 */
	public static function readKeys( string $path ): array {
		$json = is_readable( $path ) ? file_get_contents( $path ) : false;
		$data = $json === false ? null : json_decode( $json, true );
		if ( !is_array( $data ) ) {
			return [];
		}
		return array_values( array_filter(
			array_keys( $data ),
			static fn ( $key ) => is_string( $key ) && $key !== '@metadata'
		) );
	}

	/** @inheritDoc */
	public function getMessages() {
		return array_values( array_unique( array_merge( parent::getMessages(), $this->uiMessages ) ) );
	}
}
