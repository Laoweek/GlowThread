<?php

namespace MediaWiki\Extension\GlowThread\Echo;

use MediaWiki\Extension\GlowThread\GlowThreadConfig;

/**
 * Registers GlowThread's notification types with Echo, and FlowThread's old types so the
 * notifications FlowThread stored keep rendering on wikis that used FlowThread (while
 * $wgGlowThreadLegacyCompat is on).
 *
 * Echo runs these hooks itself, so nothing here runs when Echo is not loaded. Types are
 * registered with the BeforeCreateEchoEvent hook because Echo on MediaWiki 1.43 ignores the
 * extension.json attributes. The Echo hook interfaces are not implemented on purpose: they
 * exist only when Echo is installed.
 */
class EchoSetupHooks {

	public const CATEGORY = 'glowthread';
	public const TYPE_REPLY = 'glowthread-reply';
	public const TYPE_MENTION = 'glowthread-mention';
	public const TYPE_USERPAGE = 'glowthread-userpage';
	public const TYPE_MODERATION = 'glowthread-moderation';

	/** FlowThread's category and types, kept so stored notifications render */
	public const LEGACY_CATEGORY = 'flowthread';
	public const LEGACY_TYPES = [
		'flowthread_reply' => [ 'section' => 'message', 'group' => 'interactive' ],
		'flowthread_userpage' => [ 'section' => 'message', 'group' => 'interactive' ],
		'flowthread_mention' => [ 'section' => 'message', 'group' => 'interactive' ],
		'flowthread_delete' => [ 'section' => 'alert', 'group' => 'negative' ],
		'flowthread_recover' => [ 'section' => 'alert', 'group' => 'positive' ],
		'flowthread_spam' => [ 'section' => 'alert', 'group' => 'negative' ],
	];

	/** Echo's locator for user IDs listed in the event's extra data */
	private const LOCATOR = 'MediaWiki\\Extension\\Notifications\\UserLocator::locateFromEventExtra';

	public function __construct( private readonly GlowThreadConfig $config ) {
	}

	/**
	 * @param array &$notifications
	 * @param array &$notificationCategories
	 * @param array &$icons
	 */
	public function onBeforeCreateEchoEvent(
		array &$notifications,
		array &$notificationCategories,
		array &$icons
	): void {
		// Messages: echo-category-title-glowthread, echo-pref-tooltip-glowthread. Web on and
		// email off by default (Echo's defaults for a category without presets).
		$notificationCategories[self::CATEGORY] = [
			'priority' => 4,
			'tooltip' => 'echo-pref-tooltip-glowthread',
		];
		$locators = [ [ self::LOCATOR, [ NotificationHooks::RECIPIENTS ] ] ];
		$notifications[self::TYPE_REPLY] = [
			'category' => self::CATEGORY,
			'group' => 'interactive',
			'section' => 'message',
			'presentation-model' => CommentPresentationModel::class,
			'bundle' => [ 'web' => true, 'email' => true, 'expandable' => true ],
			'user-locators' => $locators,
		];
		$notifications[self::TYPE_MENTION] = [
			'category' => self::CATEGORY,
			'group' => 'interactive',
			'section' => 'message',
			'presentation-model' => CommentPresentationModel::class,
			'bundle' => [ 'web' => true, 'email' => true, 'expandable' => true ],
			'user-locators' => $locators,
		];
		$notifications[self::TYPE_USERPAGE] = [
			'category' => self::CATEGORY,
			'group' => 'interactive',
			'section' => 'message',
			'presentation-model' => CommentPresentationModel::class,
			'user-locators' => $locators,
		];
		$notifications[self::TYPE_MODERATION] = [
			'category' => self::CATEGORY,
			'group' => 'neutral',
			'section' => 'alert',
			'presentation-model' => ModerationPresentationModel::class,
			'user-locators' => $locators,
		];

		// FlowThread's types never get new events. They are registered only when
		// $wgGlowThreadLegacyCompat is on (wikis that used FlowThread): elsewhere they have nothing
		// to render and would only be listed on Special:DisplayNotificationsConfiguration. While
		// FlowThread itself is loaded, its own registration is kept (when its hook runs after this
		// one, it replaces these entries anyway).
		if ( !$this->config->isLegacyCompatEnabled()
			|| isset( $notificationCategories[self::LEGACY_CATEGORY] )
		) {
			return;
		}
		// Their category keeps users' old opt-outs and is left out of the preferences
		// ('no-dismiss' all), so only GlowThread's category is offered.
		$notificationCategories[self::LEGACY_CATEGORY] = [
			'priority' => 4,
			'tooltip' => 'echo-pref-tooltip-flowthread',
			'no-dismiss' => [ 'all' ],
		];
		foreach ( self::LEGACY_TYPES as $type => $info ) {
			$notifications[$type] = [
				'category' => self::LEGACY_CATEGORY,
				'group' => $info['group'],
				'section' => $info['section'],
				'presentation-model' => LegacyPresentationModel::class,
			];
		}
	}

	/**
	 * Several unread replies on the same page are shown as one bundled notification, and so are
	 * several unread mentions on the same page.
	 *
	 * @param \MediaWiki\Extension\Notifications\Model\Event $event
	 * @param string &$bundleKey
	 */
	public function onEchoGetBundleRules( $event, string &$bundleKey ): void {
		$type = $event->getType();
		if ( ( $type === self::TYPE_REPLY || $type === self::TYPE_MENTION ) && $event->getPageId() ) {
			$bundleKey = $type . '-' . $event->getPageId();
		}
	}
}
