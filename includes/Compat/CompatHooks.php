<?php

namespace MediaWiki\Extension\GlowThread\Compat;

use MediaWiki\Api\ApiModuleManager;
use MediaWiki\Extension\GlowThread\GlowThreadConfig;
use MediaWiki\Extension\GlowThread\Special\SpecialFlowThreadManage;
use MediaWiki\Registration\ExtensionRegistry;

/**
 * Adds the read-only action=flowthread module when $wgGlowThreadLegacyCompat is on, unless
 * FlowThread itself is loaded (it owns that module name then).
 *
 * Also registers Special:FlowThreadManage, FlowThread's moderation page, as a redirect to the
 * moderation queue, unless another extension (FlowThread itself) already registered that name.
 */
class CompatHooks {

	public const MODULE = 'flowthread';
	public const MANAGE_PAGE = 'FlowThreadManage';

	public function __construct( private readonly GlowThreadConfig $config ) {
	}

	// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

	/**
	 * @param ApiModuleManager $moduleManager
	 */
	public function onApiMain__moduleManager( $moduleManager ): void {
		if ( !$this->config->isLegacyCompatEnabled()
			|| ExtensionRegistry::getInstance()->isLoaded( 'FlowThread' )
			|| $moduleManager->isDefined( self::MODULE, 'action' )
		) {
			return;
		}
		$moduleManager->addModule( self::MODULE, 'action', [
			'class' => ApiFlowThreadCompat::class,
			'services' => [
				'GlowThread.LegacyListService',
				'GlowThread.CommentQueryService',
			],
		] );
	}

	/**
	 * @param array &$list
	 */
	public function onSpecialPage_initList( &$list ): void {
		$list[self::MANAGE_PAGE] ??= SpecialFlowThreadManage::class;
	}
}
