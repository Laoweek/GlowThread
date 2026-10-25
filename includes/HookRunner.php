<?php

namespace MediaWiki\Extension\GlowThread;

use MediaWiki\Extension\GlowThread\Hook\GlowThreadPostApprovedHook;
use MediaWiki\Extension\GlowThread\Hook\GlowThreadPostModeratedHook;
use MediaWiki\Extension\GlowThread\Hook\GlowThreadPostSavedHook;
use MediaWiki\Extension\GlowThread\Hook\GlowThreadReportFiledHook;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\User\UserIdentity;

/**
 * Runs the hooks GlowThread defines for other extensions (Echo and CheckUser handlers).
 */
class HookRunner implements
	GlowThreadPostSavedHook,
	GlowThreadPostApprovedHook,
	GlowThreadPostModeratedHook,
	GlowThreadReportFiledHook
{

	public function __construct( private readonly HookContainer $hookContainer ) {
	}

	/** @inheritDoc */
	public function onGlowThreadPostSaved(
		PostRecord $post,
		ProperPageIdentity $page,
		UserIdentity $author,
		array $mentionedUsers,
		?PostRecord $replyTo,
		?UserIdentity $replyToAuthor
	): void {
		$this->hookContainer->run(
			'GlowThreadPostSaved',
			[ $post, $page, $author, $mentionedUsers, $replyTo, $replyToAuthor ],
			[ 'abortable' => false ]
		);
	}

	/** @inheritDoc */
	public function onGlowThreadPostApproved(
		PostRecord $post,
		ProperPageIdentity $page,
		UserIdentity $author,
		array $mentionedUsers,
		?PostRecord $replyTo,
		?UserIdentity $replyToAuthor,
		UserIdentity $moderator
	): void {
		$this->hookContainer->run(
			'GlowThreadPostApproved',
			[ $post, $page, $author, $mentionedUsers, $replyTo, $replyToAuthor, $moderator ],
			[ 'abortable' => false ]
		);
	}

	/** @inheritDoc */
	public function onGlowThreadPostModerated(
		PostRecord $post,
		?ProperPageIdentity $page,
		string $action,
		UserIdentity $performer,
		string $reason,
		UserIdentity $author,
		int $logId
	): void {
		$this->hookContainer->run(
			'GlowThreadPostModerated',
			[ $post, $page, $action, $performer, $reason, $author, $logId ],
			[ 'abortable' => false ]
		);
	}

	/** @inheritDoc */
	public function onGlowThreadReportFiled(
		PostRecord $post,
		ProperPageIdentity $page,
		UserIdentity $reporter,
		string $reason,
		bool $autoHeld
	): void {
		$this->hookContainer->run(
			'GlowThreadReportFiled',
			[ $post, $page, $reporter, $reason, $autoHeld ],
			[ 'abortable' => false ]
		);
	}
}
