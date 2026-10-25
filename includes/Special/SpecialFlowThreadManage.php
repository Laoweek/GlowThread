<?php

namespace MediaWiki\Extension\GlowThread\Special;

use MediaWiki\SpecialPage\SpecialRedirectToSpecial;

/**
 * Special:FlowThreadManage, FlowThread's moderation page, now sends moderators to the queue.
 */
class SpecialFlowThreadManage extends SpecialRedirectToSpecial {

	public function __construct() {
		parent::__construct( 'FlowThreadManage', 'GlowThread', 'queue' );
	}
}
