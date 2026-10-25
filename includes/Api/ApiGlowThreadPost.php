<?php

namespace MediaWiki\Extension\GlowThread\Api;

use MediaWiki\Api\ApiMain;
use MediaWiki\Extension\GlowThread\Model\HoldReason;
use MediaWiki\Extension\GlowThread\Model\PostRecord;
use MediaWiki\Extension\GlowThread\Policy\WriteGuard;
use MediaWiki\Extension\GlowThread\Query\CommentQueryService;
use MediaWiki\Extension\GlowThread\Service\PostingService;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;
use Wikimedia\ParamValidator\TypeDef\StringDef;

/**
 * action=glowthreadpost: post a comment or a reply.
 */
class ApiGlowThreadPost extends ApiGlowThreadBase {

	/** Hard upper bound on input size before normalisation. The configured cap applies after. */
	private const MAX_INPUT_CHARS = 20000;
	/**
	 * Hard upper bound in bytes: 4 bytes per code point of the highest possible maxLength
	 * (10000) plus the slack WriteGuard::checkRawSize() allows. That check then applies the
	 * configured maxLength before the text is normalised.
	 */
	public const MAX_INPUT_BYTES = 4 * 10000 + WriteGuard::RAW_SLACK_BYTES;
	/**
	 * Hold reasons an author without glowthread-moderate is told: about their account or their
	 * held parent. Which content check held the comment (word filter, SpamBlacklist) is left
	 * out, so it cannot be used to tune text against the checks.
	 */
	private const AUTHOR_HOLD_REASONS = [ HoldReason::NEW_ACCOUNT, HoldReason::PARENT_HELD ];

	public function __construct(
		ApiMain $main,
		string $moduleName,
		private readonly PostingService $postingService,
		private readonly CommentQueryService $queryService
	) {
		parent::__construct( $main, $moduleName );
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$status = $this->postingService->post(
			$this->getAuthority(),
			$params['pageid'],
			$params['text'],
			$params['replyto'] ?? null
		);
		$this->dieUnlessOK( $status );
		/** @var PostRecord $post */
		$post = $status->getValue();
		$viewer = $this->queryService->newViewer( $this->getAuthority() );
		$view = $this->queryService->getComment( $viewer, $post->getId() );
		$holdReasons = $post->getHoldReasons();
		if ( !$this->getAuthority()->isAllowed( 'glowthread-moderate' ) ) {
			$holdReasons = array_values( array_intersect( $holdReasons, self::AUTHOR_HOLD_REASONS ) );
		}
		$this->getResult()->addValue( null, $this->getModuleName(), [
			'result' => 'success',
			'id' => $post->getId(),
			'seq' => $post->getSeq(),
			'status' => $post->getStatus(),
			'holdReasons' => $holdReasons,
			'comment' => $view['comment'] ?? null,
		] );
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'pageid' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
				IntegerDef::PARAM_MIN => 1,
			],
			'text' => [
				ParamValidator::PARAM_TYPE => 'text',
				ParamValidator::PARAM_REQUIRED => true,
				StringDef::PARAM_MAX_CHARS => self::MAX_INPUT_CHARS,
				StringDef::PARAM_MAX_BYTES => self::MAX_INPUT_BYTES,
			],
			'replyto' => [
				ParamValidator::PARAM_TYPE => 'integer',
				IntegerDef::PARAM_MIN => 1,
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=glowthreadpost&pageid=42&text=Hello&token=123ABC'
				=> 'apihelp-glowthreadpost-example-post',
			'action=glowthreadpost&pageid=42&replyto=7&text=Thanks&token=123ABC'
				=> 'apihelp-glowthreadpost-example-reply',
		];
	}
}
