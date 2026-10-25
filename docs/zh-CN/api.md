[English](../api.md) | 简体中文

# GlowThread API 参考

这是评论区、管理中心、FlowThread 导入工具以及 Echo 和 CheckUser 处理程序所依赖的接口约定。其他工具也可以使用它。读取通过 REST API（`rest.php/glowthread/v1/...`）进行。写入通过 Action API 模块（`api.php`，用 POST 并附带 CSRF 令牌）进行。以 PHP 代码为准。下面的数据格式都取自代码，`tests/phpunit/integration/` 中的集成测试覆盖了每一种。

各部分如何配合见 [architecture.md](architecture.md)。设置和权限见 [configuration.md](configuration.md)，CDN 配置见 [caching.md](caching.md)。

目录：[约定](#约定)、[对象](#对象)、[REST 路由](#rest-路由)、[写入模块](#写入模块)、[错误代码](#错误代码)、[钩子](#供其他扩展使用的钩子)、[日志记录](#日志记录)、[给导入工具的数据模型说明](#给导入工具的数据模型说明)、[界面消息](#界面消息)、[固定链接](#固定链接)、[通知](#通知echo)、[CheckUser 记录](#checkuser-记录)、[兼容 API](#兼容-apiactionflowthread)。

## 约定

- **评论 ID** 是整数（`glowthread_post.gtp_id`）。**楼层号**（`seq`）按页面计，从 1 开始，永不重复使用。显示为 `#42`。
- **时间戳**为 UTC 的 ISO 8601 格式，例如 `2026-10-08T14:23:05Z`。
- **文本从不是 HTML。** 每条评论正文都以[片段](#片段)列表的形式返回。用文本绑定渲染每个片段（Vue 中用 `{{ }}`，DOM 中用 `textContent`）。对来自这些路由的任何内容都不要使用 `v-html` 或 `innerHTML`。
- **长度**按 Unicode 码位计算，相当于 JavaScript 中的 `Array.from( text ).length`，而不是 `text.length`。一个 CJK 字符计为 1，😀 计为 1，一面旗帜计为 2。
- **Action API 调用**必须使用 `formatversion=2`（布尔值是 JSON 布尔值，列表是数组）。用 `new mw.Api().postWithToken( 'csrf', params )` 发送。
- **默认排序**为 `hot`。

### 规范列表 URL

对于匿名读者，列表的第一页是 CDN 唯一可以缓存的响应。必须用以下两个 URL 之一原样请求它：

```
{wgRestPath}/glowthread/v1/page/{pageId}/comments?sort=hot
{wgRestPath}/glowthread/v1/page/{pageId}/comments?sort=new
```

`wgRestPath` 是维基的 `$wgRestPath`，例如 `/w/rest.php`。每次写入改变了读者能看到哪些评论时，GlowThread 都会清除这两个 URL 的缓存。MediaWiki 只清除精确的 URL，从不发送 ban，因此：

- 请求第一页时只带一个查询参数 `sort`，不带其他任何参数（不带 `limit`，不带防缓存参数，也不带空的 `cursor=`）。直接使用 `mw.config.get( 'wgGlowThread' ).listUrls.hot` 或 `.listUrls.new` 中的 URL，不做任何改动。
- 其他任何形式（其他参数、不同的顺序、没有 `sort`）都能正常工作，但响应为 `private`。CDN 可以在查找缓存前把这些变体改写为规范形式。具体做法见 [caching.md](caching.md#cdn-需要做什么)。
- 只有当 `$wgUseCdn` 开启且 `$wgGlowThreadCdnMaxAge` 大于 0 时，响应才是 public，与 MediaWiki 自身的可缓存响应一样。
- 另外，只有当请求的 `Host` 与清除请求所带的主机完全相同时，响应才是 public。这个主机取自 `$wgInternalServer`，未设置内部服务器时取自 `$wgServer`，URL 带端口时还包括端口。在其他主机名下（IP 地址、第二个域名），响应为 `private`，因为清除请求永远到达不了那份副本。
- 已登录读者和带会话 cookie 的匿名读者总是得到 `private` 响应，无论 URL 是什么。
- 点赞不会清除缓存。缓存的匿名列表中的点赞数（以及热门评论区块）最多可能滞后 `$wgGlowThreadCdnMaxAge` 秒（默认 300）。每次改变显示哪些评论的操作（新的可见评论、删除、恢复、审核通过、转入待审核、置顶或取消置顶、撤销、页面状态更改、hideuser 封禁）都会清除缓存。

### 页面配置（`wgGlowThread`）

用 `mw.config.get( 'wgGlowThread' )` 读取。它与模块 `ext.glowThread.init` 一起添加到符合条件的文章浏览中。它是缓存的页面 HTML 的一部分，因此不包含任何关于读者的信息。

```json
{
  "pageId": 42,
  "enabled": true,
  "state": "open",
  "defaultSort": "hot",
  "listUrls": {
    "hot": "/w/rest.php/glowthread/v1/page/42/comments?sort=hot",
    "new": "/w/rest.php/glowthread/v1/page/42/comments?sort=new"
  },
  "restBase": "/w/rest.php/glowthread/v1",
  "features": { "legacyCompat": false }
}
```

当页面的评论被关闭（状态 `disabled`）时，`enabled` 为 false。符合条件是指：页面存在，位于 `$wgGlowThreadNamespaces` 列出的命名空间中，不是讨论页，也不是首页，并且浏览的是当前修订版本的 `action=view`。

Special:评论管理 会添加 `wgGlowThreadAdmin`：

```json
{ "subPage": "queue", "restBase": "/w/rest.php/glowthread/v1", "canErase": true, "canConfigure": true }
```

`subPage` 是 `Special:GlowThread/` 之后的部分（`queue`、`settings`、`user/Name`、`page/Title` 或空）。管理中心据此进行路由。在管理中心替换之前，页面在 `<div id="ext-glowthread-admin">` 中显示消息 `glowthread-special-nojs`。

## 对象

### 评论

```json
{
  "id": 7,
  "seq": 12,
  "pageId": 42,
  "parentId": null,
  "threadId": null,
  "depth": 0,
  "status": "visible",
  "author": { "name": "Alice", "id": 3, "anon": false, "temp": false, "hidden": false },
  "created": "2026-10-08T14:23:05Z",
  "edited": null,
  "segments": [ { "type": "text", "text": "Fed mine twice a day and it doubled 🎉" } ],
  "likes": 3,
  "pinned": false,
  "replyTo": null,
  "viewer": {
    "own": false, "liked": true, "reported": false,
    "canReply": true, "canLike": true, "canReport": true, "canDelete": false
  },
  "replyCount": 2,
  "totalReplies": 5,
  "replies": [ "...comments, see below..." ]
}
```

| 字段 | 含义 |
|---|---|
| `parentId` | 这条评论**显示在其下方**的评论（深度 − 1）。顶层评论为 `null` |
| `threadId` | 对话的顶层评论，顶层评论为 `null` |
| `depth` | `0` 顶层评论，`1` 回复，`2` 回复的回复。对深度 2 评论的回复也显示在深度 2，位于同一条深度 1 评论之下 |
| `replyTo` | 用于回复：作者实际回复的评论。当 `replyTo.id !== parentId` 时显示“回复 @name”。见下文 |
| `status` | `visible`、`held`（等待审核，只有作者和管理员可见）、`deleted`（仅管理员可见）、`erased`（仅管理员可见，文本和作者已移除） |
| `author.name` | 作者被隐藏时为 `null`：hideuser 封禁（读者拥有 `hideuser` 时除外），或被抹除的评论（读者拥有 `glowthread-erase` 时除外）。此时 `hidden` 为 true，`id` 为 0 |
| `author.anon` | 旧的匿名评论（从 FlowThread 导入）：`name` 是 IP 地址 |
| `author.temp` | 临时账号 |
| `author.registration` | 仅管理员可见：账号的创建时间（ISO 8601）。IP 作者、早于 MediaWiki 开始记录注册时间的账号，以及被隐藏的作者为 `null` |
| `author.blocked` | 仅管理员可见：`sitewide`、`partial` 或 `null`。指该账号的有效封禁，对 IP 作者则指对该确切地址的有效封禁（不含范围封禁和自动封禁）。被隐藏的作者为 `null`。`replyTo.author` 和 `mod.statusBy` 不带这两个字段 |
| `segments` | 被抹除的评论为空 |
| `likes` | 点赞数 |
| `pinned` | 置顶在页面顶部（仅限顶层评论） |
| `viewer` | 读者可以对这条评论执行哪些操作。仅作提示：写入模块会再次检查 |
| `replyCount` | 直接显示在这条评论下方、且读者可以看到的评论数（只出现在连同回复一起返回的评论上） |
| `totalReplies` | 列表和对话中的顶层评论：读者可以看到的全部回复，包括两层 |
| `replies` | 显示在这条评论下方的评论：在列表中是最早的前 `replyPreview` 条（默认 3 条），按时间从旧到新，在对话中是全部。列表只加载这些回复，不加载其余回复 |

`replyTo`：

```json
{ "id": 9, "seq": 14, "visible": true, "author": { "name": "Bob", "id": 4, "anon": false, "temp": false, "hidden": false } }
```

当读者看不到目标评论（已删除，或是他人的待审核评论）时，`visible` 为 false，`seq` 和 `author` 为 `null`。

管理员（`glowthread-moderate`）还会得到 `mod`：

```json
"mod": {
  "holdReasons": [ "filter" ],
  "holdMatches": [ { "line": 3, "rule": "free coins", "type": "literal" } ],
  "openReports": 2,
  "reportReasons": [ { "reason": "spam", "count": 2 } ],
  "statusBy": { "name": "Moderator", "id": 1, "anon": false, "temp": false, "hidden": false },
  "statusAt": "2026-10-08T15:00:00Z",
  "deletedFrom": null,
  "pinnedAt": null,
  "legacyId": null
}
```

| 字段 | 含义 |
|---|---|
| `holdReasons` | `filter`（敏感词过滤）、`filter-error`（某条敏感词正则规则在运行时出错）、`spamblacklist`、`newaccount`、`reports`（足够多不同的举报人）、`parentheld`（作者在自己待审核的评论下写的回复，见 [`glowthreadpost`](#actionglowthreadpost)） |
| `holdMatches` | 评论转入待审核时命中的敏感词规则，保存的是当时的内容。`line` 是当时该规则在 MediaWiki:GlowThread-filter 中的行号，`rule` 是该行的原文（最多 255 个字符），`type` 为 `literal` 或 `regex`。最多 20 条。因其他原因转入待审核的评论为空 |
| `statusBy`、`statusAt` | 最后一次更改状态的人和时间。自动转入待审核时 `statusBy` 为 `null` |
| `deletedFrom` | 对于已删除的评论，表示删除前的状态 `visible` 或 `held`，`restore` 会恢复到这个状态。其他状态为 `null`，日志记录中没有记下删除前状态的删除（FlowThread 的删除和导入的已删除评论）也为 `null`。`restore` 会让这些评论变为可见 |
| `legacyId` | 导入评论的 FlowThread ID（22 位十六进制数字） |

谁能看到什么：

| 读者 | 列出的状态 | 已移除评论下的回复 |
|---|---|---|
| 匿名 | `visible` | 隐藏 |
| 已登录 | `visible`，以及自己的 `held` | 隐藏 |
| 管理员 | 全部四种 | 显示 |

只有当一条回复本身、它显示上的父评论和它的顶层评论对某位非管理员读者都可见时，这位读者才能看到它。这是一个产品决定（FlowThread 也是这样做的）：当一条评论被删除、转入待审核或被抹除时，显示在它下方的回复也随之对读者消失，并且不发送墓碑。所有路由的处理一致：列表在 `replies`、`replyCount`、`totalReplies`、`count` 和 `popular` 中都不包含它们，`/comment/{id}`、`/floor/{seq}`、`/thread` 和 `/replies` 对它们返回 404 `comment-not-found`，兼容列表也不包含它们。只有显示上的父评论和顶层评论才算数：如果一条回复的 `replyTo` 目标（它回复的、显示在它旁边而不是上方的评论）被移除，这条回复仍然可见，`replyTo.visible` 为 false。管理员能看到一切，包括被移除的评论及其状态。

### 片段

```json
{ "type": "text", "text": "Hello\nsecond line " }
{ "type": "link", "text": "https://example.com/a", "href": "https://example.com/a" }
{ "type": "mention", "text": "@Alice Smith", "user": "Alice Smith", "id": 3, "exists": true }
{ "type": "mention", "text": "@…", "exists": false }
```

- `text` 保留换行（`\n`）。用 `white-space: pre-wrap`（或 `pre-line`）渲染。给评论正文加上 `dir="auto"`。
- `link.href` 总是 `http:` 或 `https:` URL。渲染为 `<a href rel="nofollow ugc noopener" target="_blank">`，以 `text` 作为链接文本。长 URL 只在视觉上缩短。
- `mention` 来自文本中的 `<@ID>` 标记（见输入规则）。对于已注册且未被隐藏的用户，`text` 是 `@` 加上该用户当前的名称，`user` 是这个名称，`id` 是用户 ID，`exists` 为 true：链接到 `User:<user>`。其他所有标记（不存在的用户、被 hideuser 封禁隐藏的用户、一条评论中前 50 个之后的标记）都是占位符 `@…`，`exists` 为 false，没有 `user` 和 `id`。请把它的 `text` 显示为纯文本：它从不透露任何名称。

输入规则（服务器如何处理输入的文本）：

- 移除不可见字符：除换行外的 C0 和 C1 控制字符、DEL、双向文本的嵌入、覆盖、隔离和标记字符、零宽空格、单词连接符和不可见数学运算符（U+2061 到 U+2064）、BOM、软连字符、蒙古文元音分隔符、行间注释标记和对象替换字符（U+FFF9 到 U+FFFC），以及语言标签（U+E0001）。标签字符（U+E0020 到 U+E007F）也会被移除，但英格兰旗帜等表情符号旗帜中的除外。零宽连接符和零宽非连接符保留（表情符号序列需要它们）。制表符变为空格。文本经过 NFC 规范化，无效的 UTF-8 变为 U+FFFD，连续超过 8 个的组合标记会被截断，连续多个空行合并为一个，并去除首尾空白。不会显示任何内容的文本（只有空格、组合标记、连接符、变体选择符、谚文填充符、盲文空白或其他默认可忽略字符）会变为空。
- URL：`http://` 或 `https://`（不区分大小写），在以下字符处结束：空白、直引号或弯引号、`<>`、CJK 标点（U+3000 到 U+303F）、全角形式（U+FF00 到 U+FFEF），或者某些文字的字母，这些文字的文本常常紧跟在 URL 后面而不加空格：汉字、假名和谚文，以及词与词之间不加空格的泰文、老挝文、高棉文和缅甸文。末尾的 `.,;:!?*'` 和不配对的右括号不属于链接。超过 2048 字节的 URL 保持为文本。如果 URL 的路径中含有这些文字的原始字符，只有第一个这类字符之前的部分会成为链接，因此由软件写入的文本（例如导入工具）必须对 URL 中的非 ASCII 字符进行百分号编码。
- 提及是 `<@ID>` 形式的标记：`<`、`@`、不带前导零的 ASCII 数字用户 ID（1 到 10 位），以及 `>`（`TextProcessor::MENTION_PATTERN`）。其他任何内容都不是提及：手动输入的 `@Name` 保持为纯文本，不会通知任何人。
  1. 用户在自动补全中选择一个名称时，客户端插入标记，并把它显示为 `@Name`。手动输入或通过 API 发送的标记同样会提及该用户：服务器无法区分这两种情况。
  2. 标记两侧都不需要空格（`谢谢<@42>！`），URL 在 `<` 处结束，所以 `https://a.example/x<@5>` 是一个链接加一个提及。格式不正确的标记，例如 `<@0>`、`<@01>` 和 `<@ 1>`，是纯文本。
  3. 长度上限按存储的标记计算（`<@123>` 是 6 个字符），而不是按显示的名称。提及超过 `maxMentions` 个不同已有用户的评论会被拒绝（`toomanymentions`）。读取评论时，每条评论的前 50 个标记会被查询并显示用户当前的名称，其余的显示为 `@…`。

### 列表响应中的 page 和 viewer 块

```json
"page": { "id": 42, "title": "Sourdough starter", "state": "open" },
"viewer": {
  "loggedIn": true,
  "canPost": true,
  "postBlockedReason": null,
  "canReact": true,
  "reactBlockedReason": null,
  "canReport": true,
  "canModerate": false,
  "canErase": false,
  "canConfigure": false
}
```

`canPost` 为 false 时，`postBlockedReason` 为：`login`、`rights`、`blocked`（全站封禁、覆盖本页面的部分封禁，或者读者同时缺少权限时的任何封禁）、`readonly`（维基处于只读模式，仅对已登录读者）、`locked`、`disabled` 或 `ineligible`。显示对应的界面消息。对于匿名读者，这个块只按权限计算，因此缓存的响应对所有人都相同。对被封禁的用户，`canModerate` 和 `canErase` 为 false。当读者的账号仍处于新账号审核中（其评论会转入待审核，其点赞会影响评论排序）而使 `canReact` 为 false 时，`reactBlockedReason` 为 `newaccount`。请说明点赞按钮不可用的原因。其他情况下为 `null`。

对于已登录读者，`canPost`、`canReact` 和 `canReport`（以及由它们生成的每条评论的 `viewer` 标志）取决于权限、封禁（部分封禁只在其覆盖的页面上生效）和只读模式，但不考虑速率限制。刚用完限额的读者仍会看到 `canPost: true`，而写入模块会返回 [`ratelimited` 和 `retryAfter`](#错误代码)。读取列表从不计入速率限制。

## REST 路由

所有路由都是 `GET`，返回 JSON，并需要 `read` 权限。除匿名读者的规范第一页外，每个响应都带有 `Cache-Control: private, must-revalidate, max-age=0, s-maxage=0`。MediaWiki 自身的 REST 错误（参数未通过验证、路径不匹配任何路由、私有 wiki 上的 `rest-read-denied`）不带 `Cache-Control`。

| 路由 | 谁可以访问 |
|---|---|
| [`/glowthread/v1/page/{pageId}/comments`](#get-glowthreadv1pagepageidcomments) | 该页面的读者 |
| [`/glowthread/v1/comment/{id}`](#get-glowthreadv1commentid) | 可以看到该评论的读者 |
| [`/glowthread/v1/page/{pageId}/floor/{seq}`](#get-glowthreadv1pagepageidfloorseq) | 可以看到该评论的读者 |
| [`/glowthread/v1/comment/{id}/thread`](#get-glowthreadv1commentidthread) | 可以看到该评论的读者 |
| [`/glowthread/v1/comment/{id}/replies`](#get-glowthreadv1commentidreplies) | 可以看到该评论的读者 |
| [`/glowthread/v1/moderation/queue`](#get-glowthreadv1moderationqueue) | `glowthread-moderate` |
| [`/glowthread/v1/moderation/user/{name}`](#get-glowthreadv1moderationusername) | `glowthread-moderate` |
| [`/glowthread/v1/moderation/page/{pageId}`](#get-glowthreadv1moderationpagepageid) | `glowthread-moderate` |
| [`/glowthread/v1/settings`](#get-glowthreadv1settings) | `glowthread-moderate` 或 `glowthread-configure` |

### `GET /glowthread/v1/page/{pageId}/comments`

页面的评论列表。

| 参数 | 取值 |
|---|---|
| `sort` | `hot`（默认）或 `new` |
| `cursor` | 取自上一页的 `next`。请求第一页时省略 |
| `limit` | 1 到 50 条顶层评论，默认为 `$wgGlowThreadPageSize`（20）。请求第一页时不要带它，见[规范 URL](#规范列表-url) |

```json
{
  "page": { "id": 42, "title": "Sourdough starter", "state": "open" },
  "sort": "hot",
  "viewer": { "...": "see above" },
  "config": {
    "maxLength": 1000, "maxMentions": 5,
    "reportReasons": [ "spam", "abuse", "offtopic", "spoiler", "misinfo", "other" ],
    "pageSize": 20, "replyPreview": 3
  },
  "count": 57,
  "pinned": [ "...top-level comments with replies..." ],
  "popular": [ "...comments without replies..." ],
  "comments": [ "...top-level comments with replies..." ],
  "next": "eyJrIjoiaG90IiwibiI6MTc5MTAwMDAwMCwibyI6MjB9"
}
```

- `count`：读者在该页面上能看到的评论数，包括回复。
- `pinned`（仅第一页）：置顶的顶层评论，最新置顶的在前，最多 10 条。它们不会在 `comments` 中重复出现，所以最新 10 条之外的置顶评论根本不会列出。
- `popular`（仅第一页）：最多 `$wgGlowThreadPopularCount` 条评论（顶层评论或回复），其点赞数不少于 `$wgGlowThreadPopularMinLikes`（按原始点赞数，而不是衰减后的分数），按衰减后的点赞数排名。当候选评论多于名额时，较早的评论可能排在点赞数相同的较新评论之后。只有点赞最多的 500 条候选评论参与排名：点赞数相同时取最新的，再取 ID 最大的。当它们都已在第一页上时（如热门排序的第一页），为空。热门回复带有 `replyTo` 作为上下文。评论区就地显示热门评论：评论在列表中时在列表中显示，否则通过 `/comment/{id}/thread` 获取。
- `hot`：`(likes + 1) × 0.5^(age in days / $wgGlowThreadHotHalfLife)`，分数最高的在前。游标保留第一页的时间，使各页结果一致。偏移量最多到 1000。两条评论的先后顺序不随时间变化，所以维基可以只计算一次，并保留到页面发生变化（见下文的缓存）。
- `new`：最新的在前，使用键集分页。
- `next`：最后一页为 `null`。
- 在 `disabled` 页面上，非管理员会得到 page、viewer 和 config 块，列表为空，`count` 为 0。

错误：404 `page-not-found`（页面不存在，或读者无法阅读它），400 `bad-cursor`（任何不是由服务器生成的游标，包括空游标，以及时间戳不是真实日期的游标：从不返回 500）。

缓存：在清除请求所针对的主机下、`$wgUseCdn` 开启时，匿名读者的规范第一页为 `Cache-Control: public, s-maxage=<$wgGlowThreadCdnMaxAge>, max-age=0`，否则为 private。没有 `Set-Cookie`，也没有 `Vary: Cookie`。维基还会把匿名读者的第一页，以及其他列表使用的每个页面的摘要，保存在对象缓存中，直到页面发生变化（[caching.md](caching.md#维基的对象缓存)）。返回的结果与没有缓存时相同。

### `GET /glowthread/v1/comment/{id}`

```json
{ "comment": { "...comment...", "replyCount": 0 }, "page": { "id": 42, "title": "Sourdough starter", "state": "open" } }
```

`comment` 没有 `replies`。`replyCount` 是存储的可见直接子评论数。以下情况返回 404 `comment-not-found`：评论不存在，读者看不到它或它显示在其下方的任何评论，它所在的页面已不存在，该页面关闭了评论（对非管理员），或读者无法阅读该页面。

### `GET /glowthread/v1/page/{pageId}/floor/{seq}`

与 `/comment/{id}` 相同，但按楼层号查找。用它解析读者输入的“#42”链接。

### `GET /glowthread/v1/comment/{id}/thread`

`id` 所在的对话：它的顶层评论，以及读者可以看到的所有回复（最多 500 条）。评论区在显示固定链接和热门评论时使用它：补全列表中的对话，或者填充列表上方的“对话”框。

```json
{
  "page": { "id": 42, "title": "Sourdough starter", "state": "open" },
  "focus": 15,
  "thread": { "...top-level comment, replies complete at both levels..." },
  "truncated": false
}
```

滚动到 `focus` 并高亮显示。404 `comment-not-found` 的情况与 `/comment/{id}` 相同。

### `GET /glowthread/v1/comment/{id}/replies`

某条评论下的更多回复，按时间从旧到新。`cursor` 取自 `next`，`limit` 为 1 到 100（默认 20）。评论区的“再显示 N 条回复”每次请求 50 条。

```json
{ "parentId": 7, "replies": [ "...comments. Depth-1 replies carry a preview of their own replies and replyCount..." ], "next": null }
```

错误：404 `comment-not-found`，400 `bad-cursor`。

### `GET /glowthread/v1/moderation/queue`

需要 `glowthread-moderate`（否则返回 403 `permission-denied`）。

| 参数 | 取值 |
|---|---|
| `tab` | `reported`（默认：有未处理举报的可见或待审核评论）、`held`、`deleted`（已删除和已抹除）、`all` |
| `sort` | `reports`（未处理举报最多的在前，其次按最新排列，是 `reported` 的默认排序）、`new`（最新的在前，是其他分类的默认排序）、`old`（最早的在前）。任何分类都可以使用任何排序 |
| `pageid` | 只看这个页面 |
| `user` | 只看这位作者（用户名或 IP） |
| `reporter` | 只看该用户（用户名或 IP）提交了**未处理**举报的评论。未知用户，或对没有 `hideuser` 的读者而言被隐藏的用户，返回空列表。注意 `do=dismiss` 会关闭一条评论的所有未处理举报，包括其他举报人的 |
| `q` | 文本包含此内容，不区分大小写。在使用 MediaWiki 默认二进制连接的 MySQL 上，它比较转为小写的 UTF-8 文本，因此拉丁字母和其他有大小写之分的字母不区分大小写，CJK 不受影响。在其他设置下由数据库的排序规则决定（例如不区分重音）。Postgres 遵循其 `lc_ctype`，SQLite 只忽略 ASCII 字母的大小写。如果 `q` 恰好是某个用户的用户名，还会找到提及该用户的评论（存储为 `<@ID>`），除非该用户被 hideuser 封禁隐藏且读者没有 `hideuser` |
| `cursor`、`limit` | 分页，`limit` 为 1 到 50，默认 20。游标属于其所在的分类和排序：使用另一种 `sort` 的游标会返回 400 `bad-cursor` |

```json
{
  "tab": "reported",
  "sort": "reports",
  "items": [
    {
      "comment": { "...comment with mod..." },
      "page": { "id": 42, "title": "Sourdough starter", "exists": true },
      "context": { "...the comment it replies to, or null..." },
      "reports": [
        { "id": 3, "reason": "spam", "note": "ad link", "created": "2026-10-08T15:00:00Z",
          "reporter": { "name": "Carol", "id": 5, "anon": false, "temp": false, "hidden": false } }
      ]
    }
  ],
  "next": null,
  "counts": { "reported": 1, "held": 4, "deleted": 10 }
}
```

`sort` 是实际使用的排序，请求没有指定排序时也会返回。各筛选条件可以组合（必须全部满足）。`counts` 不受筛选条件影响。`reported` 和 `held`（条目和计数）不包含已删除页面上的评论。`reports` 为每个条目最多列出 10 条未处理举报，最新的在前。该路由最多读取条目数 10 倍的未处理举报，在所有条目中按最新排列，因此一条近期被大量举报的评论可能使其他条目列出的举报变少。导入的举报的理由为 `legacy`，`created` 为 `null`。它们的 `reporter` 被隐藏，`reporter` 筛选也会排除它们。`items` 不包含读者无法阅读的页面上的评论，也不包含以监督方式删除的页面上的评论，除非读者拥有 `viewsuppressed` 或 `suppressrevision`。跳过 500 行之后，一页结果可能变短或为空，并带有 `next` 游标。`counts` 包含所有评论。

### `GET /glowthread/v1/moderation/user/{name}`

需要 `glowthread-moderate`。对未知用户名，以及读者没有 `hideuser` 时被隐藏的用户，返回 404 `user-not-found`。`cursor` 和 `limit`（1 到 50，默认 20）用于对评论分页。服务器未生成的游标返回 400 `bad-cursor`。

```json
{
  "user": {
    "name": "Alice", "id": 3, "actorId": 7, "registered": true,
    "registration": "2024-01-01T00:00:00Z", "editCount": 12, "groups": [ "sysop" ],
    "blocks": [ { "sitewide": true, "expiry": "infinity", "reason": "spam", "by": "Moderator" } ]
  },
  "counts": { "visible": 30, "held": 1, "deleted": 2, "erased": 0 },
  "reports": { "filed": 9, "open": 1, "upheld": 6, "dismissed": 2 },
  "comments": [ "...queue items, newest first..." ],
  "next": null,
  "moderation": [
    { "id": 120, "action": "delete", "timestamp": "2026-10-08T15:00:00Z", "performer": "Moderator",
      "commentId": 7, "seq": 12, "reason": "spam" }
  ]
}
```

`reports` 统计该用户提交的举报及其结果（`upheld`：评论被删除或抹除）。`moderation` 是评论日志（`glowthread`）中关于该用户评论的最近 20 条记录。`blocks` 列出该账号的有效封禁，对 IP 作者则列出对该确切地址的封禁（覆盖它的范围封禁不列出）。自动封禁不列出：它们的目标是 IP 地址，理由中写着被封禁的账号，列出它们会把两者联系起来。被版本删除或监督隐藏的日志字段为 `null`，除非读者有权查看，与 Special:Log 相同。`comments` 和 `moderation` 排除的页面与队列的 `items` 相同。`counts` 和 `reports` 不排除。

只有拥有 `glowthread-erase` 的用户才能看到被抹除评论与其作者的关联。对其他管理员，`counts.erased` 为 `null`，`comments` 中不包含被抹除的评论，`moderation` 中也不包含关于它们的日志记录。队列的 `user` 筛选也以同样方式排除它们，并且除非读者拥有 `hideuser`，对被 hideuser 封禁隐藏的用户什么也找不到。

### `GET /glowthread/v1/moderation/page/{pageId}`

需要 `glowthread-moderate`。对于按与队列 `items` 相同的规则读者不能查看的页面，返回 404 `page-not-found`。

```json
{
  "page": { "id": 42, "title": "Sourdough starter", "exists": true, "state": "locked",
    "stateChangedBy": { "name": "Moderator", "...": "..." }, "stateChangedAt": "2026-10-08T15:00:00Z", "floors": 57 },
  "counts": { "visible": 50, "held": 2, "deleted": 5, "erased": 0, "reported": 3 },
  "moderation": [ "...last 20 log entries for this page, as above..." ]
}
```

`moderation` 列出评论日志（`glowthread`）中该页面的最近 20 条记录。在 GlowThread 注册了 FlowThread 的 `comments` 日志类型的维基上，也会列出 FlowThread 对该页面的记录，见 [FlowThread 的日志记录](#flowthread-的日志记录)。

### `GET /glowthread/v1/settings`

需要 `glowthread-moderate` 或 `glowthread-configure`。

```json
{
  "settings": {
    "maxLength": { "value": 1000, "default": 1000, "editable": true, "overridden": false, "type": "int", "min": 1, "max": 10000 },
    "reportReasons": { "value": [ "spam", "other" ], "default": [ "spam", "abuse", "offtopic", "spoiler", "misinfo", "other" ],
      "editable": true, "overridden": true, "type": "list", "options": [ "..." ], "minItems": 1,
      "changedAt": "2026-10-08T15:00:00Z", "changedByActor": 1 },
    "publicLog": { "value": true, "default": true, "editable": true, "overridden": false, "type": "bool" }
  },
  "canConfigure": true,
  "filter": {
    "page": "MediaWiki:GlowThread-filter",
    "rules": 12,
    "invalid": [ { "line": 4, "rule": "/(broken/", "error": "missing closing parenthesis at offset 7" } ]
  },
  "rateLimits": [
    { "key": "glowthread-post", "limits": { "user": [ 6, 60 ], "newbie": [ 3, 60 ], "ip": [ 3, 60 ], "ip-all": [ 12, 60 ], "subnet-all": [ 24, 60 ] }, "canBypass": true },
    { "key": "glowthread-erase", "limits": { "user": [ 30, 3600 ], "ip-all": [ 60, 3600 ], "subnet-all": [ 120, 3600 ] }, "canBypass": false }
  ]
}
```

设置列表见 [configuration.md](configuration.md)。`editable: false` 表示站点管理员已在 LocalSettings.php 中锁定该设置。`minOn`（仅 `autoHoldReporters` 有）：该设置取 `min` 时为关闭，否则在维基上设置时至少为 `minOn`。所以 `autoHoldReporters` 可取 `0` 或 `2` 到 `100`：不能让一个账号单独使评论转入待审核。LocalSettings.php 仍可以把默认值设为 `1`。

`rateLimits` 按以下顺序列出 GlowThread 各键实际生效的 `$wgRateLimits`，只列出已配置的键：`glowthread-post`、`glowthread-react`、`glowthread-report`、`glowthread-moderate`、`glowthread-erase`、`glowthread-configure`。`limits` 把核心的每种限制类型（`anon`、`user`、`newbie`、`ip`、`subnet`、`ip-all`、`subnet-all`、`user-global` 和用户组名称）映射到 `[ count, seconds ]`。`canBypass: false` 表示 `noratelimit`（管理员和机器人）不能让任何人免于该限制。只读：限制在 LocalSettings.php 中设置。敏感词过滤在其维基页面上编辑，`invalid` 列出被忽略的行。

### REST 错误

```json
{ "errorKey": "comment-not-found", "messageTranslations": { "en": "The comment does not exist or is not visible to you." },
  "httpCode": 404, "httpReason": "Not Found" }
```

在代码中使用 `errorKey`。无效参数（既不是 `hot` 也不是 `new` 的 `sort`、非数字 ID、超出范围的 `limit`）会在 GlowThread 运行之前被 MediaWiki 的 REST 参数验证以 HTTP 400 拒绝。

## 写入模块

所有模块：仅限 POST，POST 正文中必须带 `token`（CSRF），属于写入模式（只读模式下被拒绝），并且每个读者写入都会检查目标页面的阅读权限、用户权限和封禁。使用 `formatversion=2`。结果位于模块名之下，例如 `{ "glowthreadpost": { ... } }`。

| 模块 | 作用 | 权限 |
|---|---|---|
| [`glowthreadpost`](#actionglowthreadpost) | 发表新评论或回复 | `glowthread-post` |
| [`glowthreadreact`](#actionglowthreadreact) | 点赞或取消点赞 | `glowthread-react` |
| [`glowthreadreport`](#actionglowthreadreport) | 举报评论 | `glowthread-report` |
| [`glowthreaddelete`](#actionglowthreaddelete) | 作者删除自己的评论 | `glowthread-post` |
| [`glowthreadmoderate`](#actionglowthreadmoderate) | 审核通过、删除、恢复、抹除、置顶、取消置顶、驳回举报、撤销 | `glowthread-moderate`（`erase` 还需要 `glowthread-erase`） |
| [`glowthreadpage`](#actionglowthreadpage) | 开放、锁定或关闭页面的评论 | `glowthread-moderate` |
| [`glowthreadfiltertest`](#actionglowthreadfiltertest) | 测试敏感词规则，不保存 | `glowthread-configure` |
| [`glowthreadsettings`](#actionglowthreadsettings) | 更改维基上的设置 | `glowthread-configure` |

### `action=glowthreadpost`

| 参数 | |
|---|---|
| `pageid` | 页面 ID（必需） |
| `text` | 评论文本（必需）。规范化之前最多 20000 个字符、44096 字节。实际上限是在规范化之后应用的 `maxLength`。字节数超过 `maxLength` × 4 + 4096 的输入会在规范化之前以 `toolong` 拒绝 |
| `replyto` | 被回复评论的 ID（可选） |

```json
{ "glowthreadpost": {
  "result": "success", "id": 58, "seq": 13, "status": "held", "holdReasons": [ "newaccount" ],
  "comment": { "...comment, as the author sees it..." }
} }
```

`status` 为 `held` 不是错误：向作者显示这条评论，并附上“等待审核”提示。权限：`glowthread-post`。速率限制：`glowthread-post`。`holdReasons` 只列出与作者本人有关的原因：`newaccount`（新账号审核）和 `parentheld`。对于没有 `glowthread-moderate` 的作者，它不包含内容检查（`filter`、`filter-error`、`spamblacklist`），所以被敏感词过滤拦下的评论返回空列表。作者仍能看到评论在待审核，但看不到是哪项检查拦下了它，所以无法利用这个响应针对某项检查调整文本。管理员在这里和 `mod.holdReasons` 中都能得到完整列表。

对作者自己待审核评论的回复（或在其下方的回复）也会转入待审核，原因为 `parentheld`，这样它会与父评论一起审核，并且在管理员审核通过之前不通知任何人。如果在父评论仍待审核时审核通过回复，回复暂时不会通知任何人（读者还看不到它），其通知会在父评论审核通过时发出。

已有 `$wgGlowThreadMaxHeldPerUser` 条评论（默认 3 条）等待审核的作者，再发表一条会转入待审核的评论时，会得到 `toomanyheld`（数据 `max`）。会直接可见的评论不受影响。显示“请等管理员审核后再发表新评论”。

错误：`pagenotfound`、`pageineligible`、`commentsdisabled`、`commentslocked`、`emptycomment`、`toolong`（数据 `max`）、`toomanymentions`（数据 `max`）、`duplicate`、`badparent`、`toomanyheld`（数据 `max`），以及[通用错误](#错误代码)。

### `action=glowthreadreact`

| 参数 | |
|---|---|
| `id` | 评论 ID |
| `value` | `1` 点赞，`0` 取消点赞。幂等，不是切换 |
| `type` | `like`（默认，也是唯一的类型） |

```json
{ "glowthreadreact": { "result": "success", "id": 7, "liked": true, "likes": 4, "changed": true } }
```

在已锁定的页面上可用。错误：`commentnotfound`（评论不存在、对读者不可见，或页面已不存在。它在权限、封禁和页面状态之前检查，所以这个错误不会区分被隐藏的评论和不存在的评论）、`ownlike`、`newaccount`（账号仍处于新账号审核中：不能点赞，但可以取消已有的点赞）、`pageineligible`、`commentsdisabled`。权限和速率限制：`glowthread-react`。点赞不会清除缓存的列表（见[规范列表 URL](#规范列表-url)）。

### `action=glowthreadreport`

| 参数 | |
|---|---|
| `id` | 评论 ID |
| `reason` | `spam`、`abuse`、`offtopic`、`spoiler`、`misinfo`、`other`。只接受 `config.reportReasons` 中的理由 |
| `note` | 可选，规范化后最多 500 个字符（`notetoolong`）。超过 1000 个字符的输入会在此之前被参数验证拒绝 |

```json
{ "glowthreadreport": { "result": "success", "id": 7, "already": false, "held": false } }
```

每个用户对每条评论只能举报一次：重复举报返回 `already: true`，不做任何更改。`held: true` 表示这次举报达到了自动转入待审核的阈值。只有合格的举报人才计入阈值：已注册且不是临时账号，没有被全站封禁或被禁止在该页面操作，并且拥有 `glowthread-trusted`，或者自己能通过新账号审核、账号注册至少 7 天并有一条公开评论。置顶评论，以及拥有 `glowthread-moderate` 或 `glowthread-trusted` 的用户的评论，从不因举报转入待审核。对它们的举报只会进入管理队列。错误：`commentnotfound`（与点赞相同，在页面检查之前）、`ownreport`、`badreason`、`notetoolong`、`pageineligible`、`commentsdisabled`。权限和速率限制：`glowthread-report`（默认每个用户每天 20 次）。

### `action=glowthreaddelete`

作者删除自己的评论。参数为 `id` 和可选的 `reason`。

```json
{ "glowthreaddelete": { "result": "success", "id": 7, "status": "deleted" } }
```

需要账号、`glowthread-post`，并且没有覆盖该页面的封禁（计入 `glowthread-post` 速率限制）。记录为 `glowthread/delete`，执行者为作者（如果评论之后被抹除，作者会再次被隐藏，见[日志记录](#日志记录)）。错误：`commentnotfound`（评论不存在，或是请求者看不到的他人评论：待审核、已删除或已抹除，因此这个错误不会确认评论存在）、`notauthor`（请求者能看到的他人评论。匿名请求总是得到这个错误或 `commentnotfound`）、`badstatus`、`pageineligible`、`commentsdisabled`。

### `action=glowthreadmoderate`

| 参数 | |
|---|---|
| `ids` | 最多 50 个评论 ID，用 `\|` 分隔 |
| `do` | `approve`（held → visible）、`delete`（visible 或 held → deleted）、`restore`（deleted → 删除前的状态：`visible`，或者对于在待审核时被删除的评论，恢复为带原有待审核原因的 `held`，见 `mod.deletedFrom`）、`erase`（deleted → erased，不可逆，需要 `glowthread-erase`）、`pin`（可见的顶层评论）、`unpin`、`dismiss`（关闭未处理的举报）、`undo`（见下文） |
| `logids` | 仅用于 `undo`：每条评论要撤销的日志记录，顺序与 `ids` 相同（数量相同，最多 50 个） |
| `reason` | 可选，最多 500 个字符，显示在 Special:Log 中 |

（参数名为 `do`，因为 `action` 已被 API 本身占用。）

```json
{ "glowthreadmoderate": {
  "result": "partial",
  "do": "delete",
  "results": [
    { "id": 7, "result": "success", "status": "deleted", "old": "held", "logId": 1204, "code": null },
    { "id": 8, "result": "nochange", "status": "deleted", "old": "deleted", "logId": null, "code": null },
    { "id": 9, "result": "error", "status": null, "old": null, "logId": null, "code": "commentnotfound" }
  ]
} }
```

`result` 为 `success`、`partial` 或 `failure`。每个条目：

- `status` 是操作后的状态（对于 `restore`：`visible` 或 `held`）。
- `old` 是服务器在操作前看到的状态。它可能与客户端的副本不同，因为在列表加载之后，举报可能已使评论转入待审核。
- `logId` 是为该评论写入的 `glowthread/*` 日志记录，`nochange` 和错误为 `null`。
- `code` 在出错时设置：`commentnotfound`、`badstatus`（该操作不适用于当前状态）、`cannotpin`（回复不能置顶）、`ratelimited`（仅 `erase`：达到了抹除限制，评论保持已删除状态），以及 `undo` 的 `badlogid` 和 `cannotundo`。

需要 `glowthread-moderate`，并且**没有任何类型的封禁**，包括部分封禁。速率限制：`glowthread-moderate`。副作用：`approve` 驳回未处理的举报，`delete` 和 `erase` 认定举报成立，`erase` 还会隐藏该评论引起的回复和提及通知，以及作者本人之前关于它的日志记录，并清空其举报的备注。

**撤销**（`do=undo`）精确撤销 `logids` 中指定的日志记录，`ids` 中每条评论对应一条（即之前结果中的 `logId`）。同一条评论可以出现多次，以按顺序撤销它的多条记录。各类记录的撤销效果：

| 记录 | 撤销效果 |
|---|---|
| `approve` | 恢复为待审核（带原有的待审核原因）。被这次审核通过驳回的举报重新变为未处理 |
| `delete`（管理员或作者） | 恢复为之前的状态（`visible` 或 `held`，保留待审核原因）。被它认定成立的举报重新变为未处理 |
| `recover`（恢复） | 再次删除 |
| `pin` | 取消置顶 |
| `unpin` | 以原来的置顶时间重新置顶（在页面上的位置不变） |
| `markchecked`（驳回） | 被它驳回的举报重新变为未处理 |

撤销状态更改（`approve`、`delete`、`recover`）还会恢复之前设置状态的人和时间（`mod.statusBy`、`mod.statusAt`）。因此自动转入待审核的评论会重新显示 `statusBy: null`，一条评论的多次状态更改可以从最新的开始依次撤销。只有仍由该操作处理的举报才会重新打开（之后又被处理的举报保持已处理），未处理举报计数随之更新。

每个条目的返回结果：

- `nochange`：该记录已被撤销。
- `badstatus`：评论在该记录之后发生了变化，撤销它无法恢复该记录所改变的内容。对于状态更改：之后又有另一次状态更改（精确到秒）。对于 `pin`：置顶时间不同。对于 `unpin`：评论已不再可见（只有可见评论能被置顶），或者之后又被置顶或取消置顶（而不是撤销）。对于 `markchecked`：评论之后被删除、抹除或状态发生了变化，或者它驳回的举报中已没有一条仍处于被它驳回的状态。
- `badlogid`：记录不存在，既不是 `glowthread` 记录也不是 FlowThread 的 `comments` 记录，涉及的是另一条评论，或者其操作因日志删除而对该管理员隐藏。
- `cannotundo`：该记录无法撤销。包括 `erase`、`undo` 本身、页面状态、设置和导入记录、FlowThread 的记录，以及缺少精确撤销所需参数的记录（见[日志记录](#日志记录)）。

每次撤销都会写入一条 `glowthread/undo` 日志记录（其 `logId` 在结果中），并以操作 `undo` 运行 `GlowThreadPostModerated`。Echo 不会为撤销发送通知，所以已被告知评论被删除的作者，不会在删除被撤销时收到通知，已发送的通知也会保留。审核通过被撤销后再次审核通过一条评论，不会再次发送其回复、提及和用户页通知（一条评论只通知一次），只会再次发送给作者的审核通过通知。当 `logids` 缺失或其数量与 `ids` 不同时，返回错误 `badlogids`。权限、封禁规则和速率限制与其他操作相同（`glowthread-moderate`）。

`erase` 的每条被抹除评论都计入 `glowthread-erase` 限制（默认每个管理员每小时 30 条），`noratelimit` 不能绕过这一限制。超出限制的评论得到条目代码 `ratelimited`，保持已删除状态。如果限额已经用完，整个请求以 `ratelimited` 和 `retryAfter` 失败。达到限制时会记录到日志频道 `GlowThread`。

### `action=glowthreadpage`

参数为 `pageid`、`state`（`open`、`locked`、`disabled`）和可选的 `reason`。

```json
{ "glowthreadpage": { "result": "success", "pageid": 42, "state": "locked", "old": "open", "changed": true } }
```

需要 `glowthread-moderate` 且没有封禁。清除列表和文章的缓存。错误：`pagenotfound`、`pageineligible`。

### `action=glowthreadfiltertest`

参数为 `text` 和可选的 `rules`（用来代替维基页面进行测试的规则草稿）。不保存任何内容。

```json
{ "glowthreadfiltertest": {
  "held": true,
  "matches": [ { "line": 3, "rule": "coins", "type": "literal" } ],
  "errors": [],
  "invalid": [ { "line": 5, "rule": "/(broken/", "error": "missing closing parenthesis at offset 7" } ],
  "rules": 4
} }
```

`errors` 是在这段文本上运行时出错的规则（评论会转入待审核）。需要 `glowthread-configure` 且没有封禁。`text` 最多 10000 个字符（40000 字节），`rules` 最多 65536 字节。每次调用都计入 `glowthread-configure` 限制（默认每分钟 60 次），管理员也无法绕过：返回 `ratelimited` 和 `retryAfter`。

### `action=glowthreadsettings`

`settings`：由设置名称和值组成的 JSON 对象。`null` 把设置重置为配置的默认值。可选参数 `reason`。

```json
{ "glowthreadsettings": {
  "result": "success",
  "changed": [ { "name": "newAccountHours", "old": 24, "new": 48 } ],
  "logId": 812,
  "settings": { "...as GET /settings..." }
} }
```

`logId` 是这次更改的 `glowthread/settings` 日志记录，可用于链接到 `Special:Log?logid=812`。如果每个值都已经是所发送的值（此时 `changed` 为空），则为 `null`。

需要 `glowthread-configure` 且没有封禁。与敏感词测试一样计入 `glowthread-configure` 限制。在保存任何内容之前会验证所有值。错误：`badsettingsjson`、`unknownsetting`、`settinglocked`、`badsettingvalue`、`nochange`（空对象）。

## 错误代码

Action API 错误的格式为 `{ "errors": [ { "code": "...", "text": "..." } ] }`（使用 `errorformat=plaintext` 时）或 `{ "error": { "code": "...", "info": "..." } }`。错误代码是稳定的。消息通过 `uselang` 本地化。

| 代码 | 含义 | 消息键 |
|---|---|---|
| `badtoken`、`missingparam`（token）、`mustbeposted`、`mustpostparams` | POST 正文中没有有效的 CSRF 令牌 | 核心 |
| `permissiondenied` | 用户缺少该权限（包括匿名用户） | 核心 |
| `blocked`、`autoblocked` | 有覆盖该页面的封禁，对于管理操作则是有任何封禁。封禁详情在 `data.blockinfo` 中 | 核心 |
| `ratelimited` | 超出了 `$wgRateLimits` 键的限制。`data.retryAfter`：重试前需要等待的整秒数，取适用限制中最长的时间窗口。它是一个上限：在默认限制下就是该限制的时间窗口，例如发表评论和点赞为 60，举报为 86400，抹除为 3600。使用 `errorformat=plaintext` 时从 `errors[0].data.retryAfter` 读取 | 核心 `apierror-ratelimited` |
| `readonly` | 维基处于只读模式 | 核心 |
| `badvalue`、`toomanyvalues`、`badinteger` ... | 参数验证 | 核心 |
| `pagenotfound` | 页面不存在 | `glowthread-error-page-missing` |
| `pageineligible` | 该页面不提供评论 | `glowthread-error-page-ineligible` |
| `commentsdisabled` | 页面状态为 `disabled` | `glowthread-error-disabled` |
| `commentslocked` | 页面状态为 `locked`（只限制发表和回复） | `glowthread-error-locked` |
| `emptycomment` | 规范化后没有剩下任何可见内容 | `glowthread-error-empty` |
| `toolong` | 超过 `maxLength`，数据 `max` | `glowthread-error-too-long` |
| `toomanymentions` | 提及的不同已有用户超过 `maxMentions`，数据 `max` | `glowthread-error-too-many-mentions` |
| `toomanyheld` | 作者已有 `max` 条评论等待审核，而这一条也会转入待审核，数据 `max` | `glowthread-error-too-many-held` |
| `duplicate` | 同一用户在 `duplicateWindow` 内在同一页面上发表了相同的文本，比较时忽略大小写、空白、全角形式和不可见字符 | `glowthread-error-duplicate` |
| `badparent` | 回复目标不存在、在另一个页面上或不可见（待审核的目标只对其作者可见） | `glowthread-error-parent` |
| `commentnotfound` | 评论不存在或对请求者不可见 | `glowthread-error-comment-missing` |
| `ownlike`、`ownreport` | 自己的评论 | `glowthread-error-own-like`、`glowthread-error-own-report` |
| `newaccount` | 仍处于新账号审核中的账号点赞（取消点赞仍然可以） | `glowthread-error-new-account-like` |
| `badreason` | 举报理由已关闭 | `glowthread-error-report-reason` |
| `notetoolong` | 举报说明超过 500 个字符 | `glowthread-error-note-too-long` |
| `notauthor` | 以作者身份删除他人的评论 | `glowthread-error-not-author` |
| `badstatus` | 操作不适用于该状态 | `glowthread-error-bad-status` |
| `cannotpin` | 置顶一条回复（仅作为条目代码） | |
| `badlogids` | `do=undo` 没有 `logids`，或者日志 ID 与评论 ID 不是一一对应 | `glowthread-error-undo-logids` |
| `badlogid`、`cannotundo` | `do=undo`：日志记录与该评论无关，或无法撤销（仅作为条目代码） | |
| `badsettingsjson`、`unknownsetting`、`settinglocked`、`badsettingvalue`、`nochange` | 设置 | `glowthread-error-settings-json`、`-setting-unknown`、`-setting-locked`、`-setting-range`、`-setting-range-off` 或 `-setting-invalid`、`-settings-empty` |

## 供其他扩展使用的钩子

GlowThread 运行以下钩子（接口位于 `includes/Hook/`，运行器为 `MediaWiki\Extension\GlowThread\HookRunner`）。它们都在**响应发送之后**运行：作为写入操作排队的 POSTSEND 延迟更新，写入事务回滚时取消。它们仍在执行操作的用户的请求中运行，所以 `RequestContext::getMain()->getRequest()` 带有该用户的 IP 和用户代理（即 CheckUser 记录的内容），但用户不必等待它们。所有钩子都不能中止操作。像平常一样在你的 `extension.json` 的 `Hooks` 中注册处理程序。

`PostRecord` 即 `MediaWiki\Extension\GlowThread\Model\PostRecord`。它的只读 getter：`getId()`、`getPageId()`、`getActorId()`、`getParentId()`、`getReplyToId()`、`getThreadId()`、`getRootId()`、`getDepth()`、`getSeq()`、`getText()`、`getCreated()`（TS_MW）、`getStatus()`、`isVisible()`、`isHeld()`、`getHoldReasons()`、`getLikeCount()`、`getReplyCount()`、`getOpenReportCount()`、`isPinned()`、`getLegacyId()`、`getHoldMatches()`（使它转入待审核的敏感词规则）。ID 是整数，所以在 MediaWiki 1.46 上放进 Echo 的 JSON `extra` 是安全的。不要把对象放进 `extra`。

```php
// A new comment or reply was saved, visible or held.
public function onGlowThreadPostSaved(
	PostRecord $post, ProperPageIdentity $page, UserIdentity $author,
	array $mentionedUsers,          // UserIdentity[]: registered, not hidden, author left out, at most maxMentions
	?PostRecord $replyTo,           // the comment answered, null for top-level comments
	?UserIdentity $replyToAuthor
): void;

// An approval let readers see a held comment (see below). Send the notifications PostSaved held back.
public function onGlowThreadPostApproved(
	PostRecord $post, ProperPageIdentity $page, UserIdentity $author,
	array $mentionedUsers, ?PostRecord $replyTo, ?UserIdentity $replyToAuthor,
	UserIdentity $moderator
): void;

// Any moderation action on one comment, and author self-deletion.
public function onGlowThreadPostModerated(
	PostRecord $post,               // after the action
	?ProperPageIdentity $page,      // null if the page no longer exists
	string $action,                 // approve, delete, restore, erase, pin, unpin, dismiss, selfdelete, undo.
	                                // For undo, $post is the comment after the undo and $logId the
	                                // glowthread/undo entry, whose 7::undone names the entry undone.
	                                // After restore $post is visible, or held when it was deleted while held.
	UserIdentity $performer, string $reason,
	UserIdentity $author, int $logId
): void;

// A reader filed a report.
public function onGlowThreadReportFiled(
	PostRecord $post, ProperPageIdentity $page, UserIdentity $reporter,
	string $reason, bool $autoHeld   // true when this report held the comment
): void;
```

通知处理程序的规则：

- `GlowThreadPostSaved` 且 `$post->isHeld()`：什么也不发送。等待 `GlowThreadPostApproved`，它携带相同的数据。审核通过时，提及会根据文本重新计算且不设上限，所以请自行应用 `maxMentions`。页面已不存在时，`GlowThreadPostApproved` 不会运行。它只在读者能看到评论时运行：对被审核通过的评论运行，除非它显示在其下方的评论处于待审核、已删除或已抹除状态。当那条评论被审核通过时，也对其下方之前已待审核并已被审核通过的回复运行。对于读者以前看到过的评论，它可能再次运行（审核通过被撤销后再次审核通过，或审核通过一条因举报而转入待审核的评论），所以每条通知只发送一次。它不检查页面是否关闭了评论。
- 不要就作者自己的评论通知 `$author`，当 `$replyToAuthor` 等于 `$author` 时跳过它。
- 给作者的管理结果通知：带 `delete`、`restore`、`erase` 的 `GlowThreadPostModerated`。`restore` 可能让评论保持待审核（它是在待审核时被删除的），所以在把它当作已发布之前，先检查 `$post->isVisible()`。`approve` 也会在 `GlowThreadPostApproved` 之后运行 `GlowThreadPostModerated`。`selfdelete` 是作者自己的操作。
- 被抹除的评论：永远不要显示其文本，它是空的。隐私：`erase` 之后，`GlowThreadPostModerated` 的 `$author` 是被抹除评论的作者，GlowThread 对所有没有 `glowthread-erase` 的人隐藏此人。`GlowThreadReportFiled` 的 `$reporter` 只向管理员显示。不要公开二者，并删除你复制的被抹除评论的文本和作者。
- 在 MediaWiki 1.43 上，Echo 需要一个 `Title`：`TitleFactory::newFromPageIdentity( $page )`。

关于 CheckUser：发表评论不是日志操作。GlowThread 自己的处理程序（`includes/CheckUser/CheckUserHooks.php`）把每条新评论和每次举报记录为 CheckUser 私有事件，见 [CheckUser 记录](#checkuser-记录)。管理操作发布到最近更改，所以 CheckUser 会自行记录它们。`publicLog` 设置关闭期间，它们不会在最近更改中生成行，GlowThread 会直接把它们传给 `CheckUserInsert` 服务。

## 日志记录

GlowThread 写入两种日志类型：

- `glowthread`，显示在 Special:Log/glowthread（“评论日志”）中。只有拥有 `glowthread-viewlog` 的用户才能查看（`$wgLogRestrictions`）。`publicLog` 设置开启时，`LogVisibilityHooks` 把这项权限给所有人，否则只给拥有 `glowthread-moderate`、`glowthread-erase` 或 `glowthread-configure` 的用户。核心不会把受限类型放进最近更改，所以 `ModerationLogger` 自己插入最近更改中的行，而且只在 `publicLog` 开启时插入。设置关闭期间记录的条目没有对应的行，所以不会发送监视列表邮件或通知，也永远不会出现在最近更改或监视列表中。对于没有这项权限的用户，`LogVisibilityHooks` 还会在 Special:RecentChanges、Special:RecentChangesLinked、Special:Watchlist、`list=recentchanges` 和 `list=watchlist` 中隐藏该类型已有的行。默认不在“主要公开日志”中显示（`$wgFilterLogTypes`）。
- `glowthread-private`，显示在 Special:Log/glowthread-private（“评论抹除日志”）中。只有拥有 `glowthread-erase` 的用户才能查看（`$wgLogRestrictions`），不进入最近更改。

两者都由 `includes/Log/CommentsLogFormatter.php` 格式化，使用消息键 `glowthread-logentry-<action>` 和 `glowthread-logentry-private-<action>`。

| 类型/操作 | 写入者 | 目标 | 参数 |
|---|---|---|---|
| `glowthread/delete` | 管理员删除、作者自行删除 | 页面 | `4::comment`、`5:user-link:author`、`6::seq` |
| `glowthread/recover` | 恢复 | 页面 | 同上 |
| `glowthread/approve` | 审核通过 | 页面 | 同上 |
| `glowthread/markchecked` | 驳回举报 | 页面 | 同上 |
| `glowthread/autohold` | 因举报自动转入待审核（操作者：系统用户“GlowThread”） | 页面 | 同上 |
| `glowthread/pin`、`glowthread/unpin` | 置顶、取消置顶 | 页面 | 同上 |
| `glowthread/erase` | 抹除 | 页面 | `4::comment`、`6::seq`（无作者） |
| `glowthread-private/erase` | 抹除 | 页面 | `4::comment`、`5:user-link:author`、`6::seq` |
| `glowthread/enable`、`glowthread/lock`、`glowthread/disable` | 页面状态改为开放、锁定、关闭 | 页面 | 无 |
| `glowthread/undo` | `glowthreadmoderate` `do=undo` | 页面 | `4::comment`、`5:user-link:author`、`6::seq`、`7::undone`（被撤销的日志 ID） |
| `glowthread/settings` | 设置更改 | Special:评论管理/settings | `4::changes`：`[ name, old, new ]` 列表 |
| `glowthread/import` | FlowThread 导入工具 | Special:评论管理 | `4::count`（到目前为止导入的全部评论数，不只是本次运行导入的），`5::options`（导入选项，JSON）。只写入 `logging` 表，从不发布到最近更改 |
| `glowthread/post` | 仅 CheckUser 处理程序，从不出现在 Special:Log 中 | 页面 | `4::comment`、`6::seq` |
| `glowthread/report` | 仅 CheckUser 处理程序，从不出现在 Special:Log 中 | 页面 | `4::comment`、`6::seq` |

- 页面已不存在时，目标为 `Special:GlowThread/page/<page ID>`。
- 评论记录带有 `log_search` 关系 `glowthread_post`（评论 ID）和 `glowthread_author`（作者的 actor ID）。公开的 `glowthread/erase` 记录没有作者关系。`glowthread/undo` 记录还带有 `glowthread_undo`（被撤销的日志 ID）。
- `5:user-link:author` 保存的是当时的用户名。Special:Log 和 `list=logevents` 通过 `glowthread_author` 显示当前的用户名。
- 有些记录还保存了精确撤销所需的信息。`delete` 带有 `7::old`（之前的状态，`visible` 或 `held`）。`recover` 带有 `7::new`（恢复操作设置的状态）。`unpin` 带有 `7::old`（置顶时间，TS_MW）。`approve`、`delete` 和 `recover` 带有 `9::prev`（之前设置状态的人和时间，格式为 `<actor ID>|<TS_MW>`，任一部分都可以为空）。`approve`、`delete`、`erase` 和 `markchecked` 在处理了举报时带有 `8::reports`（它们处理的举报 ID，逗号分隔）。日志消息、`list=logevents` 和 `list=recentchanges` 都不显示这些参数。机器可读的 RC 推送（使用 JSON 格式化器的 `$wgRCFeeds`）则会显示：它们原样发送 `log_params`，包括这些参数（`9::prev` 含有一个 actor ID），所有日志类型都是如此。只把这类推送提供给可信的接收方。
- 旧的匿名作者（IP 地址）链接到其贡献页面，核心的 `user-link` 类型无法为 IP 做到这一点。
- 格式化器在 Special:Log 和 `list=logevents` 中隐藏作者：评论之后被抹除时，对没有 `glowthread-erase` 的读者隐藏，作者被 hideuser 封禁隐藏时，对没有 `hideuser` 的读者隐藏。核心的 `user-link` 参数类型不检查被隐藏的用户，所以在这里处理。
- 抹除还会在作者是**执行者**的记录中隐藏作者。该评论的公开记录中由作者执行的那些（作者自己的 `glowthread/delete`，或管理员对自己评论的操作，包括管理员抹除自己评论时的 `glowthread/erase` 记录）会把 `log_deleted`（以及 `rc_deleted`）设为 `DELETED_USER | DELETED_COMMENT`，与核心的日志版本删除做法相同。这样，没有 `deletedhistory` 的读者在 Special:Log、`list=logevents`（`userhidden`、`commenthidden`）和最近更改中，既看不到执行者，也看不到理由。

### FlowThread 的日志记录

FlowThread 把记录写入日志类型 `comments`。GlowThread 从不写入该类型。开启 `$wgGlowThreadLegacyCompat` 时，GlowThread 用同一个格式化器注册它，使旧记录继续在 Special:Log/comments（“FlowThread评论日志”）和页面的管理历史（[`/moderation/page/{pageId}`](#get-glowthreadv1moderationpagepageid) 的 `moderation`）中正常显示。这时这些记录的读者与 `glowthread` 类型相同（`$wgLogRestrictions`：`glowthread-viewlog`）。设置关闭时，这些记录会被隐藏（`$wgLogRestrictions`：`glowthread-legacy-log`），最近更改中的对应行也不显示 `logparams`。其他扩展已注册这个名称时，不会注册它：这样的扩展有 FlowThread 本身，以及把 `comments` 用作自身日志的 Extension:Comments（`includes/Log/LegacyLogType.php`）。

- FlowThread 的记录包括带 `4::username` 的 `delete`、`recover` 和 `markchecked`，带 `4::postid` 和 `5::children` 的 `erase`，带 `4::count` 的 `import`，以及 `disable` 和 `enable`。它们保留 FlowThread 的消息键 `logentry-comments-*`。因此在迁移过的维基上，页面的管理历史也会列出 FlowThread 对该页面的记录，其中包括 `enable`、`disable` 和 `import`，它们的 `commentId` 和 `seq` 为 `null`。用户历史不会列出它们，因为它们没有 `glowthread_author` 关系。
- 它们的作者名遵循与 GlowThread 相同的规则：当用户被 hideuser 封禁隐藏时，对没有 `hideuser` 的读者隐藏。在 `erase` 记录中（其 `4::postid` 存的是作者名，这是 FlowThread 的一个 bug），总是对没有 `glowthread-erase` 的读者隐藏，就像 GlowThread 自己的抹除记录不指明任何人一样。此时 API 中会省略该参数（`username` 或 `postid`）。
- 它们无法撤销（`cannotundo`），因为它们没有指向任何 GlowThread 评论。

## 给导入工具的数据模型说明

数据库结构：`sql/tables.json`。状态值和常量位于 `includes/Model/`。FlowThread 导入工具及其映射见 [migration-data.md](migration-data.md)。

- `glowthread_post.gtp_status`：`visible`、`held`、`deleted`、`erased`。FlowThread 状态 0 → `visible`，1（已删除）→ `deleted`，2（垃圾评论）→ `held` 且 `gtp_hold_reason = 'filter'`（或 `deleted`，由站点管理员选择）。
- 位置：`gtp_thread` 是顶层祖先（顶层评论为 null）。`gtp_reply_to` 是被回复的评论（顶层评论为 null）。`gtp_depth` 为 `min( true depth, 2 )`，`gtp_parent` 是显示上的父评论：深度 1 和 2 的评论为真正的父评论，更深的评论为其深度 1 的祖先。因此 `gtp_parent` 的深度总是 `gtp_depth - 1`。
- `gtp_seq`：按创建顺序从 1 开始为每个页面的评论编号，然后调用 `PageStateStore::raiseSeqFloor( $dbw, $pageId, $maxSeq )`（服务 `GlowThread.PageStateStore`），使新评论在其之上继续编号。`(gtp_page, gtp_seq)` 唯一。
- `gtp_legacy_id`：FlowThread ID，22 位小写十六进制数字（唯一）。它可以用任意大小写形式传给 Special:评论链接。
- `gtp_text`：纯文本，用 `TextProcessor::normalize()`（服务 `GlowThread.TextProcessor`）规范化。提及是 `<@ID>` 标记（`TextProcessor::mentionToken()`）。FlowThread 导入工具为每个已注册且未被隐藏的用户写入标记，不是用户的名称写成纯文本 `@Name`，被隐藏的用户写成 `@…`。对 URL 中的非 ASCII 字符进行百分号编码（`https://wiki.example.org/wiki/%E6%97%A5...`），因为原始的 CJK 字符会结束 URL。`TextProcessor::normalize()`、`tokenize()`、`extractUrls()` 和 `extractMentions()` 保持现有签名。后两个返回字符串列表。
- 计数器必须与数据行一致：`gtp_like_count` = 点赞数，`gtp_open_report_count` = `gtrp_resolved_at` 为 null 的举报数，`gtp_reply_count` = `gtp_parent` 为这条评论的**可见**评论数。
- `gtp_created`、`gtp_status_timestamp`、`gtp_pinned` 为 `mwtimestamp`：用 `$dbw->timestamp()` 写入。
- 旧的匿名作者：IP actor（`ActorNormalization::acquireActorId( new UserIdentityValue( 0, $ip ), $dbw )`）。
- `glowthread_reaction`：`gtr_type = 'like'`，导入的点赞的 `gtr_timestamp` 为 null。主键为 `(gtr_post, gtr_actor, gtr_type)`。
- `glowthread_report`：`gtrp_reason = 'legacy'`，`gtrp_created` 为 null，每个 `(post, actor)` 一行。
- `glowthread_page`：`gtpg_state` 为 `open`、`locked` 或 `disabled`（FlowThread 控制状态 2，即管理员关闭了评论 → 默认为 `locked`，或 `disabled`。状态 1，即页面选择不启用评论 → 默认为 `locked`，或 `disabled` 或 `open`。两者都由站点管理员选择，见[导入选项](migration-data.md#导入选项)），`gtpg_seq` 为楼层计数器。
- 导入期间不运行任何管理钩子，也不写入日志记录，除非导入工具自己写入。

## 界面消息

界面字符串位于 `i18n/ui/*.json`。`i18n/ui/en.json` 中的每个键都会自动发送给 `ext.glowThread.app` 和 `ext.glowThread.admin`（`includes/ResourceLoader/UiMessagesModule.php` 读取该文件，评论区不包含 `glowthread-ui-admin-*` 键），所以添加消息无需修改 `extension.json`。把键添加到 `en.json` 和 `qqq.json`（以及你能翻译的其他语言），然后用 `mw.message( key )` 读取。后端和 API 消息仍放在 `i18n/` 和 `i18n/api/` 中。

## 固定链接

| 链接 | 指向 |
|---|---|
| `Special:GlowThreadLink/<id>` | GlowThread 评论 ID |
| `Special:GlowThreadLink/<22 hex digits>` | 导入评论的 FlowThread ID |
| `Special:GlowThreadLink/<page title>/<floor>` | 页面上的某个楼层号，例如 `Special:GlowThreadLink/Sourdough_starter/42` |

每个链接都会重定向到带有锚点 `#gt-<id>` 的页面。评论区给每条评论设置元素 ID `gt-<id>`，并滚动到该评论。如果评论不在列表中，则获取 `/comment/<id>/thread` 并就地显示该评论：它所在的对话在列表中时，在列表中展开，否则显示在列表上方的“对话”框中。读者看不到的评论与不存在的评论得到相同的“未找到”页面（HTTP 404）。复制链接按钮、日志记录和通知都使用 `Special:GlowThreadLink`。

`#glowthread` 会打开评论区。在使用过 FlowThread 的维基上生成的链接仍然有效：`Special:FlowThreadLink/...` 与 `Special:GlowThreadLink/...` 指向同一位置，`Special:FlowThreadManage` 重定向到 `Special:GlowThread/queue`，以 `#flowthread` 结尾的地址会打开评论区。

## 通知（Echo）

只在加载了 Echo 时发送（`includes/Echo/`）。通知类型通过 `BeforeCreateEchoEvent` 钩子注册，Echo 在 MediaWiki 1.43 和 1.46 上都会运行这个钩子。四种类型都属于 `glowthread` 类别（Special:Preferences 中的“评论”）：默认开启网页通知、关闭电子邮件通知，用户可以自行切换两者。Echo 的 `message` 分区显示为“通知”，`alert` 分区显示为“提醒”。

| 类型 | 接收者 | 发送时机 | 分区、图标 |
|---|---|---|---|
| `glowthread-reply` | 被回复评论（`gtp_reply_to`）的作者 | 保存了一条可见回复，或一条待审核回复被审核通过 | `message`、`chat` |
| `glowthread-mention` | 被提及的用户，最多 `maxMentions` 位，按提及顺序 | 保存了一条可见评论，或一条待审核评论被审核通过 | `message`、`mention` |
| `glowthread-userpage` | 评论所在用户页（`User:Name`，不包括其子页面）的所有者，类似 FlowThread 的 `flowthread_userpage` | 保存了一条可见评论，或一条待审核评论被审核通过 | `message`、`edit-user-talk` |
| `glowthread-moderation` | 评论作者 | 管理员删除（附理由）、恢复（仅当恢复使评论可见时）或审核通过一条待审核评论 | `alert`、`revert`（删除）或 `reviewed` |

以下情况不会发送通知：

- 针对自己的评论或操作（回复自己、提及自己、管理员对自己的评论执行操作、自行删除），或者接收者是 IP 作者。
- 待审核的评论（状态为 `held` 的 `GlowThreadPostSaved`）。审核通过时会发送保存时暂缓的通知，提及上限按审核通过时计算。
- 读者看不到的评论：显示在已删除或已抹除评论下方的评论，或者位于关闭了评论的页面上的评论。之后也不会为它补发。作者自己待审核评论下的回复本身也会转入待审核（`parentheld`），并在读者能看到它时发送通知：在它被审核通过时，或者如果那时父评论仍待审核，则在父评论被审核通过时。
- 管理员审核通过一条因举报而转入待审核的评论（它之前是可见的），或一条导入的 FlowThread 评论（FlowThread 当时已发过通知）时，不会再次发送回复、提及或用户页通知。作者仍会收到审核通过通知。
- 已删除的待审核评论被恢复时：`restore` 使它重新变为待审核，所以它的通知要等到审核通过，与任何待审核评论一样。作者也不会收到恢复通知，审核通过时发送审核通过通知。如果删除前的状态未知（`mod.deletedFrom` 为 `null`：FlowThread 的删除、导入的评论），`restore` 会直接公开该评论，只发送恢复通知，不发送回复或提及通知。
- `undo`：撤销管理操作不会发送任何通知。审核通过被撤销后再次审核通过，不会再次发送回复、提及和用户页通知：GlowThread 每条评论只发送一次（它会在 Echo 的表中查找该评论的事件）。作者会再次收到审核通过通知。
- 回复者被禁止在该页面操作时（全站封禁，或覆盖该页面或其命名空间的部分封禁）：不发送回复、提及或用户页通知。
- 接收者无法阅读该页面时。
- 同一作者在一小时内给同一用户发送超过 3 条时：同一作者给同一接收者的回复、提及和用户页通知每小时最多 3 条（`NotificationHooks::MAX_PER_RECIPIENT`，与核心的速率限制一样计入 MediaWiki 的 MicroStash）。其余的会被丢弃，而不是推迟发送。
- 抹除（作者已经收到过删除通知）、置顶、取消置顶或驳回举报。

一位用户对每条评论最多收到一条通知，取回复、提及、用户页中第一个适用的：既被回复又被提及的用户只收到回复通知，在自己用户页上被回复或提及的用户页所有者也只收到那一条。Echo 自身的过滤仍然适用（屏蔽、通知偏好设置）。同一页面上的多条未读回复会合并为一条通知（“您在…上的评论有3条新回复”），同一页面上的多条未读提及也是如此（“…上的评论中有3条新提及提到了您”）。

事件 `extra`（只包含整数、字符串和整数列表，因为 MediaWiki 1.46 上的 Echo 以 JSON 格式存储它）：

| 键 | 出现在 | 值 |
|---|---|---|
| `comment-id` | 全部四种 | GlowThread 评论 ID |
| `reply-to-id` | 回复 | 被回复评论的 ID |
| `action` | 管理 | `delete`、`restore` 或 `approve` |
| `reason` | 管理 | 管理员给出的理由，最多 500 个字符 |
| `recipients` | 全部四种 | 用户 ID，由 Echo 的 `locateFromEventExtra` 读取 |

管理通知不设 agent：它不会指明是哪位管理员（Special:Log/glowthread 会记录）。

每次显示通知时，渲染都会重新检查评论：

- **正文**：当读者能看到该评论时（它以及它显示在其下方的评论都是 `visible`，其所在页面没有关闭评论，并且接收者可以阅读该页面），显示最多 150 个字符的纯文本摘录，其中的提及显示为 `@Name`（不存在或被隐藏的用户显示为 `@…`）。否则显示中性文本 `notification-body-glowthread-unavailable`（“这条评论已无法查看。”）。删除通知改为显示理由，或什么也不显示。
- **主链接**：评论可见时为 `Special:GlowThreadLink/<id>`（会重定向到带有锚点 `#gt-<id>` 的页面），否则为该页面。
- **被抹除的评论**：该评论引起的回复、提及和用户页通知（以及 FlowThread 关于导入评论的通知）会用 Echo 自己的管理机制（`event_deleted`）隐藏，就像 Echo 隐藏关于已删除页面的通知一样。它们不再被列出，Echo 的 API 也不再把作者作为其 `agent` 返回。未读计数会重新计算。恢复页面后它们仍保持隐藏：当恢复操作创建页面时，Echo 会重新显示其恢复的页面 ID 下的所有通知，GlowThread 会紧接着再次隐藏关于被抹除评论的通知，对于之前某次恢复操作已移到另一个页面 ID 的评论也是如此。关于已删除或待审核评论的通知会随页面一起重新显示，并渲染上面的中性文本。仍会渲染的通知（被抹除评论的管理通知）也不会指明作者。
- **作者自己删除的评论**：作者删除自己的评论时（包括管理员删除自己的评论），其回复、提及和用户页通知也会以同样方式隐藏，这样提及不会比评论本身存在得更久。只要评论仍处于删除状态，恢复页面后它们仍保持隐藏。管理员恢复该评论也不会让它们重新出现。管理员删除他人的评论时，这些通知会保留，并渲染中性文本。

### FlowThread 旧通知

在使用过 FlowThread 的维基上，`$wgGlowThreadLegacyCompat` 让 FlowThread 存储的通知继续正常显示。这六种类型用 GlowThread 的展示模型（`LegacyPresentationModel`）重新注册，归入原来的 `flowthread` 类别，这样可以保留用户以前的退订设置，并且该类别在偏好设置中隐藏。不会再创建这些类型的新事件。设置关闭时（默认），不注册这些类型。

| 类型 | 分区、图标 | 标题 |
|---|---|---|
| `flowthread_reply` | `message`、`chat` | “…回复了您在…上发表的评论” |
| `flowthread_userpage` | `message`、`edit-user-talk` | “…在您的用户页上发表了评论” |
| `flowthread_mention` | `message`、`mention` | “…在…上发表的评论中提及了您” |
| `flowthread_delete` | `alert`、`revert` | “您在…上的评论被删除了” |
| `flowthread_recover` | `alert`、`reviewed` | “您在…上的评论被恢复了” |
| `flowthread_spam` | `alert`、`revert` | “您在…上的评论被自动标记为垃圾评论” |

FlowThread 以 11 个原始字节的形式把评论 ID 存在 `extra['postid']` 中（PHP `serialize`，所以 1.46 上的 Echo 仍能读取这些行）。也接受 22 位十六进制值，不区分大小写。展示模型按 `gtp_legacy_id` 查找导入的评论，然后像新类型一样渲染（评论可见时显示摘录和 `Special:GlowThreadLink/<id>` 链接）。如果没有导入的评论具有该 ID，则显示中性文本并链接到页面。页面已被删除的通知不会渲染（Echo 随后会隐藏它，与以前一样）。

## CheckUser 记录

只在加载了 CheckUser 时记录（`includes/CheckUser/CheckUserHooks.php`）。每条新评论，无论可见还是待审核，都会成为 `cu_private_event` 中的一个 CheckUser 私有事件：一条由作者执行、以页面为目标的 `glowthread/post` 日志记录，带有 `4::comment`（ID）和 `6::seq`。它在 `GlowThreadPostSaved` 中（作者的请求内）构建，转换为 `rc_logid` 为 0 的 RecentChange 对象，然后传给 `CheckUserInsert` 服务，由该服务从请求中记录 IP、X-Forwarded-For 和用户代理。不会向 `logging` 或 `recentchanges` 写入任何内容，所以发表评论从不出现在 Special:Log 或 Special:RecentChanges 中。每次举报也以同样方式记录，作为一条由举报者执行的 `glowthread/report` 记录（`4::comment`、`6::seq`，不含举报理由或备注），在 `GlowThreadReportFiled` 中（举报者的请求内）构建。点赞不会被记录。

Special:CheckUser 对某个用户执行 “Get actions” 时，会把它们列为“Alice发表了Page上的评论#4”（消息 `glowthread-logentry-post`，链接到 `Special:GlowThreadLink/<id>`），把举报列为“Bob举报了Page上的评论#4”（`glowthread-logentry-report`），并附带 IP、XFF 和用户代理。管理记录（`glowthread/*`）发布到最近更改，所以 CheckUser 会自己把它们记录到 `cu_log_event` 中。`publicLog` 设置关闭期间，`ModerationLogger` 改为把它们的 RecentChange 对象传给 `CheckUserInsert`，`cu_log_event` 中的结果相同。自动转入待审核（`glowthread/autohold`）记录为由软件执行，IP 为 127.0.0.1，没有 XFF 和用户代理，而不是记录触发它的举报人的 IP。它在最近更改中的 `rc_ip`（如果有这一行）也是 127.0.0.1。受限的 `glowthread-private` 日志从不发布，不会进入最近更改和 CheckUser。

## 兼容 API（`action=flowthread`）

FlowThread API 的只读替身，供从 FlowThread 迁移到 GlowThread 的维基使用（`includes/Compat/`）。仍在调用 FlowThread API 的旧客户端（缓存的页面和脚本、小工具、嵌入维基的应用）在更新之前可以继续显示评论。只有当 `$wgGlowThreadLegacyCompat` 为 true（默认为 `false`）且 FlowThread 本身未加载时，才会添加这个模块。旧客户端不发送令牌，这里也不写入任何内容。见[从 FlowThread 迁移](migrating-from-flowthread.md)。

| 参数 | |
|---|---|
| `type` | `list` 或 `listall`。FlowThread 的写入类型 `post`、`like`、`dislike`、`report`、`delete`、`recover`、`markchecked`、`erase` 无论用 GET 还是 POST，都返回错误 `glowthread-legacy-readonly`（“FlowThread 已被 GlowThread 取代。此接口为只读…”） |
| `pageid` | `list`：必需。`listall`：可选的筛选条件 |
| `offset` | 要跳过的评论数，0 到 10000（默认 0） |
| `limit` | 1 到 100，或 `max`（= 100）。默认 10。`list` 按顶层评论计数 |
| `filter`、`title`、`user`、`keyword`、`dir` | 仅 `listall`。`filter`：`all`、`deleted`（已删除和已抹除）、`spam`（待审核）或 `reported`，默认只看可见评论。`dir`：`older`（默认，最新的在前）或 `newer`。如果 `keyword` 恰好是某个用户的用户名，还会找到提及该用户的评论，与队列中的 `q` 相同 |

`type=list` 需要该页面的阅读权限（读者无法阅读的页面与不存在的页面一样返回 `nosuchpageid`）。响应始终是 private，即使带有 `smaxage` 也一样：这些 URL 的缓存无法清除。

```json
{ "flowthread": {
  "posts": [
    { "id": "0605b2a3c4d5e6f7a8b9c0", "userid": 3, "username": "Alice",
      "text": "Hello <a href=\"https://example.com/\" class=\"external\" rel=\"nofollow ugc noopener\" target=\"_blank\">https://example.com/</a><br>\nline 2",
      "timestamp": 1696000000, "parentid": "", "like": 2, "myatt": 1 },
    { "id": "58", "userid": 0, "username": "203.0.113.7", "text": "…", "timestamp": 1791000000,
      "parentid": "0605b2a3c4d5e6f7a8b9c0", "like": 0, "myatt": 0 }
  ],
  "popular": [ "…same shape…" ],
  "count": 33
} }
```

- `posts`：页面上可见的顶层评论，最新的在前，`offset` 和 `limit` 作用于它们，后面跟着显示在它们下方的可见回复（深度 1 在深度 2 之前，使每个父评论都在其子评论之前，这是 FlowThread 的客户端所需要的）：每个请求最多 300 条回复、256 KiB 回复文本，每个深度内按时间从旧到新排列。读者看不到的评论下方的回复不会列出。待审核的评论不会列出，对其作者也不列出。
- `id`：导入的评论为 FlowThread ID（22 位十六进制数字），新评论为十进制字符串形式的 GlowThread ID。两种 ID 都可以用于 Special:评论链接。`parentid` 使用相同的形式，顶层评论为 `''`。
- `userid`：旧的匿名（IP）作者和被隐藏的作者为 0。被隐藏的作者（hideuser 封禁、被抹除的评论）的 `username` 为 `rev-deleted-user` 的文本。
- `text`：由[片段](#片段)生成的 HTML，因为 FlowThread 的客户端用 `.html()` 插入它。文本会被转义，换行变为 `<br>`，链接是只指向 `http:`/`https:` URL 的 `<a>`，对已有用户的提及会链接到其用户页。除此之外没有任何标记。
- `timestamp`：Unix 秒数。`like`：点赞数。`myatt`：读者点赞过为 1，举报过为 2，否则为 0。
- `popular`：最多 `$wgGlowThreadPopularCount` 条可见评论，点赞数至少为 `$wgGlowThreadPopularMinLikes` 且其祖先都可见，点赞最多的在前。
- `count`：页面上可见的顶层评论数。在关闭了评论的页面上，或在不显示评论的页面上（不在 `$wgGlowThreadNamespaces` 中、讨论页或首页），一切都为空。

`type=listall` 需要 `glowthread-moderate`（否则返回 `permissiondenied`），返回 `{ "more": bool, "posts": [...] }`，这是一个跨页面的扁平列表。每条评论还带有 `pageid`、`title`（已删除页面为 null）、`report`（未处理举报数）和 `status`（FlowThread 的代码：0 可见，1 已删除或已抹除，2 待审核）。与管理队列一样，`user` 筛选对没有 `glowthread-erase` 的读者排除被抹除的评论，并且除非读者拥有 `hideuser`，对被 hideuser 封禁隐藏的用户什么也找不到。它排除的页面与管理队列相同，`offset` 只计算它返回的评论。
