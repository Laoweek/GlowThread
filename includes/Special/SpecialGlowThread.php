<?php

namespace MediaWiki\Extension\GlowThread\Special;

use MediaWiki\Extension\GlowThread\GlowThreadConfig;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;
use PermissionsError;

/**
 * Special:GlowThread, the moderation hub. A shell for the ext.glowThread.admin app, which
 * routes the subpages (queue, settings, user/<name>, page/<title>) in the browser. Every
 * action goes through the Action API, which checks rights and blocks again.
 */
class SpecialGlowThread extends SpecialPage {

	private const RIGHT = 'glowthread-moderate';

	public function __construct( private readonly GlowThreadConfig $config ) {
		// The restriction is declared through the overrides below: passing it to the constructor
		// is deprecated in 1.46, and 1.43 reads the property instead of getRestriction().
		parent::__construct( 'GlowThread' );
	}

	/** @inheritDoc */
	public function getRestriction(): string {
		return self::RIGHT;
	}

	/** @inheritDoc */
	public function isRestricted() {
		return true;
	}

	/** @inheritDoc */
	public function userCanExecute( User $user ) {
		return $user->isAllowed( self::RIGHT );
	}

	/** @inheritDoc */
	protected function displayRestrictionError() {
		throw new PermissionsError( self::RIGHT );
	}

	/** @inheritDoc */
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->checkPermissions();
		$out = $this->getOutput();
		$authority = $this->getAuthority();
		$out->addModules( 'ext.glowThread.admin' );
		$themeClass = $this->config->getThemeClass();
		if ( $themeClass !== null ) {
			$out->addHtmlClasses( $themeClass );
		}
		$out->addJsConfigVars( 'wgGlowThreadAdmin', [
			'subPage' => (string)$subPage,
			'restBase' => $this->getConfig()->get( 'RestPath' ) . '/glowthread/v1',
			'canErase' => $authority->isAllowed( 'glowthread-erase' ),
			'canConfigure' => $authority->isAllowed( 'glowthread-configure' ),
		] );
		$out->addHTML( Html::rawElement(
			'div',
			[ 'id' => 'ext-glowthread-admin', 'class' => 'ext-glowthread-admin' ],
			// Wikitext (a link to the comment log), parsed and sanitized like any interface message
			Html::rawElement(
				'p',
				[ 'class' => 'ext-glowthread-admin-nojs' ],
				$this->msg( 'glowthread-special-nojs' )->parse()
			)
		) );
	}

	/** @inheritDoc */
	public function doesWrites() {
		return true;
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'users';
	}
}
