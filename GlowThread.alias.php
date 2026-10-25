<?php
/**
 * Aliases for the special pages of GlowThread.
 *
 * FlowThreadLink, the name of FlowThread's permalink page, is an English alias of GlowThreadLink
 * so links from a former FlowThread install keep working. It applies to every content language.
 * While FlowThread itself is loaded, its page keeps the name (canonical names win over aliases).
 *
 * @file
 * @ingroup Extensions
 */

$specialPageAliases = [];

/** English */
$specialPageAliases['en'] = [
	'GlowThread' => [ 'GlowThread', 'Comments' ],
	'GlowThreadLink' => [ 'GlowThreadLink', 'CommentLink', 'FlowThreadLink' ],
	'FlowThreadManage' => [ 'FlowThreadManage' ],
];

/** Simplified Chinese */
$specialPageAliases['zh-hans'] = [
	'GlowThread' => [ '评论管理' ],
	'GlowThreadLink' => [ '评论链接' ],
];

/** Traditional Chinese */
$specialPageAliases['zh-hant'] = [
	'GlowThread' => [ '評論管理' ],
	'GlowThreadLink' => [ '評論連結' ],
];
