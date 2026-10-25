<?php

namespace MediaWiki\Extension\GlowThread\Api;

use MediaWiki\Api\ApiMain;
use MediaWiki\Extension\GlowThread\Settings\SettingsService;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\StringDef;

/**
 * action=glowthreadsettings: change on-wiki GlowThread settings.
 */
class ApiGlowThreadSettings extends ApiGlowThreadBase {

	public function __construct(
		ApiMain $main,
		string $moduleName,
		private readonly SettingsService $settings
	) {
		parent::__construct( $main, $moduleName );
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$changes = json_decode( $params['settings'], true );
		// A JSON object: {} decodes to [], which SettingsService answers with "nochange"
		if ( !is_array( $changes ) || ( $changes !== [] && array_is_list( $changes ) ) ) {
			$this->dieWithError( 'glowthread-error-settings-json', 'badsettingsjson' );
		}
		$status = $this->settings->update( $changes, $this->getAuthority(), $params['reason'] );
		$this->dieUnlessOK( $status );
		$changed = [];
		foreach ( $status->getValue()['changes'] as [ $name, $old, $new ] ) {
			$changed[] = [ 'name' => $name, 'old' => $old, 'new' => $new ];
		}
		$this->getResult()->addValue( null, $this->getModuleName(), [
			'result' => 'success',
			'changed' => $changed,
			// The glowthread/settings log entry of this change, null when nothing changed
			'logId' => $status->getValue()['logId'],
			'settings' => $this->settings->describe(),
		] );
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'settings' => [
				ParamValidator::PARAM_TYPE => 'text',
				ParamValidator::PARAM_REQUIRED => true,
				StringDef::PARAM_MAX_BYTES => 10000,
			],
			'reason' => $this->getReasonParam(),
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=glowthreadsettings&settings={"newAccountHours":48}&reason=Spam+wave&token=123ABC'
				=> 'apihelp-glowthreadsettings-example-set',
		];
	}
}
