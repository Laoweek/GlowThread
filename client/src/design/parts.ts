/**
 * Part hooks: the stable names site CSS may select, the one source for the class names in the
 * templates and the part tables in docs/theming.md and docs/zh-CN/theming.md (`pnpm tokens docs`).
 *
 * Each part is the class ext-glowthread-<name> on one kind of element. Templates write the class
 * as a plain string, so it costs nothing at render time and Vue keeps static classes static.
 * tests/parts.test.ts fails when a template uses a part that is missing here, when a part is used
 * nowhere, or when the containers differ from CONTAINER_PARTS in scripts/tokens.mjs.
 *
 * A container part resolves every token again (src/design/tokens.css), so a --glowthread-*
 * property set on it applies inside it. The other parts take plain CSS properties only. Names say
 * what a part is, never where it sits (no "left" or "right": CSSJanus would flip them).
 *
 * The apps do not import this file. It holds the descriptions for the docs, in English and in
 * Simplified Chinese.
 */
export type PartApp = 'comments' | 'overlay' | 'hub' | 'both'

export interface Part {
  name: string
  /** Where it renders: the comment section, the overlay container, the hub, or both apps. */
  app: PartApp
  /** Resolves the tokens again, so a token set on it applies inside it. */
  container: boolean
  desc: string
  zh: string
  /** State attributes it carries (names in ATTRS). */
  attrs?: string[]
}

export interface StateAttr {
  name: string
  /** The values, in the docs' words. An attribute without values is present or absent. */
  values?: string[]
  desc: string
  zh: string
}

export const PART_PREFIX = 'ext-glowthread-'

export const PARTS: Part[] = [
  // Comment section, inside #glowthread
  { name: 'section', app: 'comments', container: false, desc: 'The comment section', zh: '评论区' },
  { name: 'header', app: 'comments', container: false, desc: 'The row with the heading and the sort tabs', zh: '标题和排序标签页所在的一行' },
  { name: 'heading', app: 'comments', container: false, desc: 'The section heading (Comments)', zh: '评论区标题（“评论”）' },
  { name: 'count', app: 'comments', container: false, desc: 'The number of comments in the heading', zh: '标题中的评论数' },
  { name: 'sort', app: 'comments', container: false, desc: 'The sort tabs (Top, Newest)', zh: '排序标签页（热门、最新）' },
  { name: 'modtools', app: 'comments', container: false, desc: 'Moderator tools above the list: the Awaiting review and Reported filters, the page state, Select', zh: '列表上方的管理工具：待审核和被举报筛选、页面状态、管理模式' },
  { name: 'composer', app: 'comments', container: true, desc: 'The composer, on the page and under a comment while replying', zh: '发表框，位于页面上，回复时也出现在评论下方' },
  { name: 'composer-field', app: 'comments', container: false, desc: 'The frame of the text field (border and background)', zh: '输入框的边框和底色' },
  { name: 'composer-toolbar', app: 'comments', container: false, desc: 'The row under the text field: emoji, @, the character count, Cancel and Send', zh: '输入框下方的一行：表情、@、字数、取消和发送' },
  { name: 'send', app: 'comments', container: false, desc: 'The Send button', zh: '发送按钮' },
  { name: 'gate', app: 'comments', container: true, desc: 'In place of the composer when the reader cannot post (logged out, blocked, page locked)', zh: '读者不能发表时代替发表框的说明（未登录、被封禁、页面已锁定）' },
  { name: 'popular', app: 'comments', container: true, desc: 'Top comments, above the Newest list', zh: '热门评论，位于最新列表上方' },
  { name: 'linked', app: 'comments', container: true, desc: 'The Conversation box above the list, for a linked comment that is not in the list', zh: '列表上方的“对话”框，用于显示不在列表中的链接评论' },
  { name: 'list', app: 'comments', container: false, desc: 'A list of comments: one sort order (pinned comments first) or a moderator filter', zh: '评论列表：某种排序（置顶评论在前）或管理员筛选的结果' },
  { name: 'comment', app: 'comments', container: true, desc: 'One comment with its replies, which are comments inside it', zh: '一条评论及其回复，回复是嵌套在其中的评论', attrs: ['data-glowthread-depth', 'data-glowthread-status', 'data-glowthread-pinned', 'data-glowthread-own'] },
  { name: 'comment-row', app: 'comments', container: false, desc: 'The comment itself, without its replies, drawn on `comment-bg`', zh: '评论本身（不含回复），底色为 `comment-bg`' },
  { name: 'meta', app: 'comments', container: false, desc: 'Floor number and time', zh: '楼层号和时间' },
  { name: 'menu-trigger', app: 'comments', container: false, desc: 'The ⋯ button of a comment, and the page state button of the moderator tools', zh: '评论的 ⋯ 按钮，以及管理工具中的页面状态按钮' },
  { name: 'actions', app: 'comments', container: false, desc: 'The row under the text: like and Reply', zh: '正文下方的一行：赞和回复' },
  { name: 'like', app: 'comments', container: false, desc: 'The like button with its count', zh: '赞按钮及赞数' },
  { name: 'reply', app: 'comments', container: false, desc: 'The Reply button', zh: '回复按钮' },
  { name: 'replies', app: 'comments', container: false, desc: 'The replies under a comment, with the line beside them', zh: '评论下方的回复区，及其旁边的竖线' },
  { name: 'replies-toggle', app: 'comments', container: false, desc: 'Show more replies and Hide replies', zh: '“再显示 N 条回复”和“收起回复”' },
  { name: 'mod-notes', app: 'comments', container: true, desc: 'Why a comment is held or reported, with the moderation buttons (moderators only)', zh: '评论待审核或被举报的原因，以及管理按钮（仅管理员可见）' },

  // Overlays, inside #glowthread-portal
  { name: 'menu', app: 'overlay', container: true, desc: 'A menu: the ⋯ menu of a comment, the page state menu', zh: '菜单：评论的 ⋯ 菜单、页面状态菜单' },
  { name: 'menu-item', app: 'overlay', container: false, desc: 'One item of a menu', zh: '菜单中的一项' },
  { name: 'popover', app: 'overlay', container: true, desc: 'A popover: the report form from 640 px, the emoji picker', zh: '弹出框：640 px 起的举报表单、表情选择器' },
  { name: 'emoji', app: 'overlay', container: true, desc: 'The emoji picker (also a popover)', zh: '表情选择器（同时也是弹出框）' },
  { name: 'mentions', app: 'overlay', container: true, desc: 'The @mention suggestions', zh: '@提及建议列表' },
  { name: 'report', app: 'overlay', container: true, desc: 'The report form, in a popover or, on phones, in a sheet', zh: '举报表单，位于弹出框中，手机上位于面板中' },
  { name: 'sheet', app: 'overlay', container: true, desc: 'A sheet: from the bottom on phones, from the side from 640 px (the report form on phones, the hub\'s record and shortcuts)', zh: '面板：手机上从底部弹出，640 px 起从侧边弹出（手机上的举报表单、管理中心的记录和快捷键）' },
  { name: 'dialog', app: 'overlay', container: false, desc: 'The hub\'s dialog that confirms erasing a comment', zh: '管理中心确认抹除评论的对话框' },
  { name: 'toast', app: 'overlay', container: true, desc: 'A toast message', zh: '轻提示' },

  // Moderation hub, inside #ext-glowthread-admin
  { name: 'hub', app: 'hub', container: false, desc: 'The moderation hub', zh: '管理中心' },
  { name: 'hub-nav', app: 'hub', container: true, desc: 'Navigation: Queue, Settings, the log, the word filter, shortcuts', zh: '导航：队列、设置、日志、敏感词、快捷键' },
  { name: 'queue', app: 'hub', container: false, desc: 'The queue view: tabs, filters and items', zh: '队列视图：分类、筛选和条目' },
  { name: 'queue-tabs', app: 'hub', container: false, desc: 'The queue tabs', zh: '队列分类标签页' },
  { name: 'queue-item', app: 'hub', container: true, desc: 'One comment in the queue', zh: '队列中的一条评论', attrs: ['data-glowthread-status'] },
  { name: 'record', app: 'hub', container: true, desc: 'The author or page record: beside the queue, or in a sheet (in the overlay container) when the hub is narrower than 720 px', zh: '作者或页面记录：位于队列旁边，管理中心窄于 720 px 时位于面板中（在浮层容器里）' },
  { name: 'settings', app: 'hub', container: true, desc: 'The settings view', zh: '设置视图' },
  { name: 'filter-editor', app: 'hub', container: true, desc: 'The word filter in the settings', zh: '设置中的敏感词编辑器' },

  // Both apps (and their overlays)
  { name: 'avatar', app: 'both', container: false, desc: 'An avatar. `avatar-bg` and `avatar-fg` set on it apply to it', zh: '头像。在它上面设置的 `avatar-bg` 和 `avatar-fg` 对它生效' },
  { name: 'name', app: 'both', container: false, desc: 'An author\'s name', zh: '作者名' },
  { name: 'body', app: 'both', container: false, desc: 'The text of a comment', zh: '评论正文' },
  { name: 'badge', app: 'both', container: false, desc: 'A status label: Pinned, Awaiting review, Reported, Hidden, and in the hub blocks, user groups and frequent reporters', zh: '状态标签：置顶、待审核、被举报、已隐藏，以及管理中心里的封禁、用户组和频繁举报者', attrs: ['data-glowthread-tone'] },
  { name: 'notice', app: 'both', container: false, desc: 'A message in the page: comments closed or turned off, the hub\'s notices', zh: '页面中的提示：评论已关闭、管理中心的提示', attrs: ['data-glowthread-tone'] },
  { name: 'button', app: 'both', container: false, desc: 'A button: Reply, Send, Load more, Approve and the like', zh: '按钮：回复、发送、加载更多、通过等', attrs: ['data-glowthread-variant'] },
  { name: 'tab', app: 'both', container: false, desc: 'One tab of the sort tabs or the queue tabs', zh: '排序或队列分类中的一个标签页' },
  { name: 'empty', app: 'both', container: false, desc: 'No comments yet, or an empty queue', zh: '还没有评论，或队列为空' },
  { name: 'loading', app: 'both', container: false, desc: 'Placeholders while loading', zh: '加载时的占位内容' },
  { name: 'error', app: 'both', container: false, desc: 'A failed load: the error state with Try again, the line above Load more, the line under replies', zh: '加载失败：带“重试”的错误提示、“加载更多”上方的提示、回复下方的提示' },
  { name: 'load-more', app: 'both', container: false, desc: 'The Load more button', zh: '“加载更多”按钮' },
  { name: 'bulk-bar', app: 'both', container: true, desc: 'The bar for selected comments (Select in the section, the selection in the hub)', zh: '选中评论时的操作栏（评论区的管理模式、管理中心的多选）' },
]

export const ATTRS: StateAttr[] = [
  { name: 'data-glowthread-depth', values: ['0', '1', '2'], desc: 'Level as shown: 0 at the top of a list, 1 and 2 for replies', zh: '显示的层级：列表顶层为 0，回复为 1 和 2' },
  { name: 'data-glowthread-status', values: ['visible', 'held', 'deleted', 'erased'], desc: '`held` is awaiting review, `deleted` is hidden by a moderator or deleted by its author, `erased` has lost its text and author. Readers see only `visible` comments and their own `held` ones', zh: '`held` 为待审核，`deleted` 为被管理员隐藏或被作者删除，`erased` 为正文和作者已被抹除。读者只能看到 `visible` 的评论和自己 `held` 的评论' },
  { name: 'data-glowthread-pinned', desc: 'On a pinned comment (not on its replies)', zh: '在置顶评论上（不在其回复上）' },
  { name: 'data-glowthread-own', desc: 'On the reader\'s own comments', zh: '在读者自己的评论上' },
  { name: 'data-glowthread-tone', values: ['neutral', 'accent', 'warning', 'danger', 'success'], desc: 'The colour of a badge or notice (notices: neutral, warning, danger)', zh: '标签或提示的颜色（提示只有 neutral、warning、danger）' },
  { name: 'data-glowthread-variant', values: ['primary', 'secondary', 'ghost', 'subtle', 'danger', 'link'], desc: '`primary` is the one main action of a view (Send, Send report)', zh: '`primary` 是一个视图中唯一的主要操作（发送、提交举报）' },
]
