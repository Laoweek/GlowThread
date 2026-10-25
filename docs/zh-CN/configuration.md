[English](../configuration.md) | 简体中文

# 配置

本页列出站点管理员可以在 GlowThread 中配置的全部内容：`LocalSettings.php` 中的变量、权限和用户组、速率限制、可以在维基上修改的设置、敏感词过滤、日志、通知、CheckUser 记录、FlowThread 兼容 API、页面被删除或移动时的处理，以及特殊页面。

相关页面：

- [README](../../README.zh-CN.md)：安装和入门
- [管理指南](moderation.md)：管理员如何使用这些设置
- [部署在 CDN 之后](caching.md)：Varnish 和其他缓存
- [API 参考](api.md)：REST 路由、API 模块、钩子和日志记录

## 开始之前

加载扩展并创建它的数据表：

```php
wfLoadExtension( 'GlowThread' );
```

```sh
php maintenance/run.php update
```

`update` 会创建五张表：`glowthread_post`、`glowthread_reaction`、`glowthread_report`、`glowthread_page` 和 `glowthread_setting`。表结构文件位于 `sql/<database type>/tables-generated.sql`。它还会创建记录[自动转入待审核](#举报自动转入待审核)的系统用户“GlowThread”。

**数据库。** 评论列表使用窗口函数（`ROW_NUMBER() OVER`），只加载需要显示的回复。这需要 MariaDB 10.2 或更高版本、MySQL 8.0 或更高版本、SQLite 3.25 或更高版本，或者 PostgreSQL。开发用维基运行在 MariaDB 10.11 或 MySQL 8.0 上，PHP 测试在 SQLite 上运行。PostgreSQL 支持这些 SQL，但 GlowThread 没有在它上面测试过。

所有文本列都是二进制类型（`BLOB` 或 `VARBINARY`）。即使 `$wgDBTableOptions` 使用 3 字节的 `utf8` 字符集，Emoji 和其他 4 字节字符也能完整保存。

**可选扩展。** 这些扩展已加载时 GlowThread 会使用它们，没有它们也能正常工作：

| 扩展 | GlowThread 用它做什么 |
|---|---|
| [Echo](https://www.mediawiki.org/wiki/Extension:Echo) | 针对回复、提及、用户页上的评论和管理操作发送[通知](#通知echo) |
| [CheckUser](https://www.mediawiki.org/wiki/Extension:CheckUser) | 为每条新评论和每次举报写入一条 [CheckUser 记录](#checkuser) |
| [SpamBlacklist](https://www.mediawiki.org/wiki/Extension:SpamBlacklist) | 检查评论中的链接。含有黑名单链接的评论会进入待审核 |

**推荐安装 [AntiSpoof](https://www.mediawiki.org/wiki/Extension:AntiSpoof)。** 没有它，任何人都可以注册一个看起来与他人完全相同的用户名，例如带有一个不可见软连字符的“Moderator”。GlowThread 只对管理员显示出这类字符，并且不会在 @提及建议中列出这些用户名。

**不要同时加载 FlowThread。** 两个扩展会各自添加一个评论区，而且 FlowThread 会在页面被删除时彻底删除该页面的评论。要从 FlowThread 迁移，请按照[从 FlowThread 迁移](migrating-from-flowthread.md)操作。

## LocalSettings 变量

所有变量都是可选的。请把它们放在 `wfLoadExtension( 'GlowThread' );` 之后。

| 变量 | 默认值 | 含义 |
|---|---|---|
| `$wgGlowThreadNamespaces` | `[ 0 => true ]` | 显示评论的命名空间，格式为命名空间 ID => `true` 或 `false`。你的值会与默认值合并，所以 `[ 0 => false, 2 => true ]` 会关闭主命名空间并开启用户页。讨论命名空间、特殊页面、不存在的页面和首页永远不会有评论 |
| `$wgGlowThreadMaxLength` | `1000` | 评论的最大长度，按 Unicode 码位计算。大多数 Emoji 算一个，旗帜或家庭等 Emoji 序列算多个。维基内设置 `maxLength` |
| `$wgGlowThreadMaxMentions` | `5` | 一条评论最多可以提及多少位不同的现有用户。维基内设置 `maxMentions` |
| `$wgGlowThreadPageSize` | `20` | 列表每页的顶层评论数，范围 1 到 50 |
| `$wgGlowThreadReplyPreview` | `3` | 每条评论下显示的回复数，范围 0 到 20。读者通过“再显示 N 条回复”就地加载其余回复 |
| `$wgGlowThreadPopularCount` | `3` | 列表上方热门评论区块的大小，范围 0 到 10。`0` 关闭该区块 |
| `$wgGlowThreadPopularMinLikes` | `2` | 评论进入热门评论区块前至少需要的点赞数 |
| `$wgGlowThreadHotHalfLife` | `90` | 热门排序和热门评论区块所用点赞分数的半衰期，单位为天。半衰期越短，新评论越快胜出。见[热门排序](#热门排序) |
| `$wgGlowThreadCdnMaxAge` | `300` | CDN 可以为未登录读者保留第一页评论的秒数（`s-maxage`）。只在 `$wgUseCdn` 开启时生效。`0` 让所有列表响应都变为私有。见[部署在 CDN 之后](caching.md) |
| `$wgGlowThreadDuplicateWindow` | `600` | 同一用户在同一页面上不能再次发表相同文字的时长，单位为秒。只在大小写、空格、全角形式或不可见字符上不同的文字算作相同。维基内设置 `duplicateWindow` |
| `$wgGlowThreadNewAccountHours` | `24` | 新账号审核：账号注册时长，单位为小时，见[下文](#新账号审核)。`0` 关闭新账号审核。维基内设置 `newAccountHours` |
| `$wgGlowThreadNewAccountApproved` | `1` | 新账号审核：新账号有多少条公开评论后可以免于审核。维基内设置 `newAccountApproved` |
| `$wgGlowThreadAutoHoldReporters` | `3` | 一条公开评论上有这么多位不同的符合条件的用户提交了未处理的举报时，把它转入待审核，见[下文](#举报自动转入待审核)。`0` 关闭此功能。在维基上只能设为 `0` 或 `2` 到 `100`，所以单个账号无法独自让评论进入待审核。`LocalSettings.php` 中仍然可以设为 `1`。维基内设置 `autoHoldReporters` |
| `$wgGlowThreadMaxHeldPerUser` | `3` | 一位作者最多可以有多少条评论等待审核。超出后，本应进入待审核的新评论会被拒绝，并返回错误 `toomanyheld`。会立即公开的评论不受影响。`0` 表示不限。只能在 LocalSettings 中设置 |
| `$wgGlowThreadReportReasons` | 全部六个 | 向读者提供的举报理由：`spam`、`abuse`、`offtopic`、`spoiler`、`misinfo`、`other`。设置这个变量会替换整个列表。维基内设置 `reportReasons` |
| `$wgGlowThreadPublicLog` | `true` | 在 Special:Log、Special:RecentChanges、监视列表和 API 中向所有人显示 GlowThread 的日志条目（管理操作和设置更改，以及 `$wgGlowThreadLegacyCompat` 开启时 FlowThread 的 `comments` 条目）。设为 `false` 时，只有拥有 `glowthread-moderate`、`glowthread-erase` 或 `glowthread-configure` 的用户能看到，并且这期间记录的条目永远不会进入最近更改和监视列表，见[日志](#日志)。CheckUser 在两种情况下都会记录它们。维基内设置 `publicLog` |
| `$wgGlowThreadTheme` | `'auto'` | 评论区和管理中心的配色方案。`'auto'` 跟随皮肤的浅色或夜间模式，在皮肤跟随系统设置时也跟随读者的系统设置。`'night'` 和 `'day'` 通过在 `<html>` 上添加类 `ext-glowthread-night` 或 `ext-glowthread-day`，让它们始终保持深色或浅色，作用于带有评论区的文章浏览页面和 Special:评论管理。只在评论后面的页面始终为深色时使用 `'night'`，只在页面始终保持浅色时使用 `'day'`。其他值按 `'auto'` 处理。只能在 LocalSettings 中设置。见[主题定制](theming.md#wgglowthreadtheme) |
| `$wgGlowThreadLegacyCompat` | `false` | FlowThread 兼容：以只读方式响应 FlowThread 的 API（`api.php?action=flowthread`），并显示 FlowThread 已保存的通知和日志记录。从 FlowThread 迁移过来的维基要把它设为 `true` 并保持开启。见 [FlowThread 兼容](#flowthread-兼容) |
| `$wgGlowThreadOnWikiSettings` | 见[维基内设置](#维基内设置) | 拥有 `glowthread-configure` 的用户可以在维基上修改哪些设置 |

超出上述范围的值会被调整为最接近的允许值。

`maxLength` 只需提高到维基需要的程度。每次读取都会重新把每条评论的文字转换为链接，所以较长的评论会让每次页面浏览的开销更大，最坏情况下开销增长得比长度更快。

### 新账号审核

如果作者的账号注册时间短于 `newAccountHours`，**并且**公开评论少于 `newAccountApproved` 条，评论会**进入待审核**，而不会被拒绝。拥有 `glowthread-trusted` 的用户不受新账号审核限制。没有注册日期的很老的账号视为老账号。被新账号审核拦下的账号在通过审核之前不能点赞（错误 `newaccount`），但仍然可以取消点赞。

按默认值，新账号在注册后的前 24 小时内发表的评论都要等待审核，直到管理员通过其中一条。审核应该多严格，取决于你的社区，也取决于维基会收到多少垃圾信息。新账号审核看的是账号时长和已通过的评论，而不是编辑次数，因为许多读者只评论，从不编辑。把 `newAccountHours` 设为 `0` 可以关闭新账号审核。

### 举报自动转入待审核

一条公开评论上有 `autoHoldReporters`（默认 3）位不同的**符合条件**的用户提交了未处理的举报时，这条评论会转入待审核。举报人满足以下条件才算符合条件：

- 已注册（临时账号不算），并且不是作者本人，
- 没有被禁止在那里举报：既没有被全站封禁，也没有被针对该页面或其命名空间的部分封禁，
- 拥有 `glowthread-trusted`，或者同时满足以下所有条件：自己能通过新账号审核（账号注册时长至少为 `newAccountHours`，或者有 `newAccountApproved` 条公开评论），账号注册至少 7 天，并且至少有一条公开评论。7 天和一条评论是固定的，不是设置项。

所以提前注册、从未使用过的账号无法把一条评论藏起来。每条举报仍然会进入管理队列。

置顶评论、拥有 `glowthread-moderate` 或 `glowthread-trusted` 的用户发表的评论，以及管理员已经保留的评论（已通过、已恢复，或其举报已被忽略），永远不会因举报而进入待审核。对它们的举报只会进入队列。撤销这次通过、恢复或忽略后，这一保护随之结束。从 FlowThread 导入的举报不计入，除非同一用户再次举报这条评论。每次自动转入待审核都会记录为 `glowthread/autohold`，执行者是系统用户“GlowThread”。这个用户名已被保留，任何人都无法注册，`update` 会创建这个用户。如果在安装 GlowThread 之前已有账号使用这个名称，该账号不会被接管，自动转入待审核会改为记录为由“Maintenance script”执行。与所有使用保留名称的账号一样，该账号在重命名之前无法再登录。

关闭新账号审核（`newAccountHours` 设为 `0`）时，举报人仍然需要注册满 7 天的账号和一条公开评论。

### 热门排序

读者可以按“热门”（热门排序，`sort=hot`，默认）或“最新”对评论排序。热门排序和“热门评论”区块按点赞分数排名，这个分数每 `$wgGlowThreadHotHalfLife` 天减半：

- 热门排序：(点赞数 + 1) × 0.5^(发表天数 / 半衰期)
- 热门评论：点赞数 × 0.5^(发表天数 / 半衰期)，只在点赞数至少为 `$wgGlowThreadPopularMinLikes` 的评论中排名

候选评论多于名额时，点赞很多但较早的评论可能进不了热门评论区块。热门排序只对页面中最近发表的 5,000 条顶层评论排名，并且只能翻页浏览其中的前 1,000 条。评论更多的页面上，较早的评论只会出现在“最新”排序中。

## 维基内设置

拥有 `glowthread-configure` 的用户（默认为管理员）在 **Special:评论管理/settings** 上修改这些设置。[管理指南](moderation.md#设置)介绍了这个界面。保存时需要填写理由，每次更改都会连同旧值和新值记录在 Special:Log/glowthread 中。

| 设置 | 类型 | 允许的值 | LocalSettings 变量 |
|---|---|---|---|
| `maxLength` | 整数 | 1 到 10000 | `$wgGlowThreadMaxLength` |
| `maxMentions` | 整数 | 0 到 50 | `$wgGlowThreadMaxMentions` |
| `duplicateWindow` | 秒 | 0 到 86400 | `$wgGlowThreadDuplicateWindow` |
| `newAccountHours` | 小时 | 0 到 8760 | `$wgGlowThreadNewAccountHours` |
| `newAccountApproved` | 整数 | 0 到 1000 | `$wgGlowThreadNewAccountApproved` |
| `autoHoldReporters` | 整数 | 0（关闭），或 2 到 100 | `$wgGlowThreadAutoHoldReporters` |
| `reportReasons` | 列表 | 六个理由中至少一个 | `$wgGlowThreadReportReasons` |
| `publicLog` | 开或关 | | `$wgGlowThreadPublicLog` |

在 `LocalSettings.php` 中设置这些变量之一，会把对应设置锁定为你的值。设置界面会把它显示为灰色，并标注“已在 LocalSettings 中锁定”，之前在维基上保存的值不再生效。删除这一行后，这项设置又可以在维基上修改。没有设置变量时，设置从[上表](#localsettings-变量)中的默认值开始。

`$wgGlowThreadOnWikiSettings` 可以锁定设置而不指定值，这时设置保持默认值。默认值是：

```php
$wgGlowThreadOnWikiSettings = [
	'maxLength' => true,
	'maxMentions' => true,
	'duplicateWindow' => true,
	'newAccountHours' => true,
	'newAccountApproved' => true,
	'autoHoldReporters' => true,
	'reportReasons' => true,
	'publicLog' => true,
];
```

你的值会与默认值合并。例如，下面的配置把举报理由锁定为默认值，其余设置保持不变：

```php
$wgGlowThreadOnWikiSettings = [ 'reportReasons' => false ];
```

设置会缓存在 MediaWiki 的 WAN 对象缓存中，并在每个 PHP 进程中缓存几秒，直到设置发生变化。更改设置会清除这个缓存。CDN 为未登录读者缓存的列表会在 `$wgGlowThreadCdnMaxAge` 秒内反映更改。

## 敏感词过滤

规则保存在维基页面 **MediaWiki:GlowThread-filter** 上。与其他界面页面一样，编辑它需要 `editinterface` 权限，它有历史记录，也可以被保护。页面不存在时就没有规则。打开编辑时，起始文字是一段简短的语法说明。这个页面与所有界面页面一样是公开的：任何人都可以读到规则，发垃圾信息的人也一样。在 Special:评论管理/settings 上，管理员可以查看规则，拥有 `glowthread-configure` 的用户可以测试规则，拥有 `editinterface` 的用户可以编辑规则。

- 每行一条规则。空行和以 `#` 开头的行（注释）会被忽略。
- 普通规则和评论在比较之前都会经过规范化：NFKC（全角和其他兼容形式变为普通形式）、大小写折叠，以及去除零宽空格等不可见字符。拉丁、希腊、西里尔、阿拉伯和希伯来字母上的重音符号和其他组合符号会被去除，除非这一行本身带有重音符号：这时评论也保留它的重音符号。泰文、印度系文字和假名中表示元音、声调或浊音的符号会保留。
- 在词与词之间用空格分隔的文字（拉丁、西里尔、希腊、阿拉伯等大多数文字）中，普通规则按**完整单词**匹配。规则 `ass` 匹配“Ass!”，但不匹配“class”或“pass”。
  - 多个单词的规则按顺序匹配这些单词，单词之间可以有任意空格、标点或符号，也可以没有：`cheap coins` 也匹配“cheap-coins”和“cheapcoins”。
  - 单词的字母之间可以隔开：`spam` 也匹配“s p a m”，`ass` 匹配“a.s.s”。单词不会在其他位置被拆开，所以“it's hit”不匹配 `shit`。小写字母后面跟着隔开的大写字母时，不会从这里开始一个单词，所以“Got a S S rank”不匹配 `ass`。
  - 中文、日文、韩文、泰文、老挝文、高棉文和缅甸文字符视为单词边界，因为这些文字常常直接连着拉丁单词，中间没有空格。`spam` 能在“这个spam链接”中匹配。
- 含有中文、日文、韩文、泰文、老挝文、高棉文或缅甸文字符的普通规则，以及不含任何字母或数字的普通规则（例如一个 Emoji），会在评论的**任意位置**匹配。只要规则中至少有两个不是空格、标点或符号的字符，即使这些字符之间插入了空格、符号或 ASCII 标点也能匹配。所以这一点不适用于 Emoji 规则。它永远不会跨越换行、全角的句子和分句标点（`。、，！？：；`）、中日韩括号和引号（`「」『』【】《》〈〉`，以及全角的 `（）［］`）或弯引号（`“”‘’`）进行匹配。
- 形如 `/pattern/flags` 的行是 PCRE 正则表达式，作用于按原样保存的评论。标志可以是 `i`、`m`、`s` 和 `x`。`u`（Unicode）始终开启。加上 `i` 可以忽略大小写。表达式中的斜杠不需要转义。
- 每条规则单独编译和运行，所以一行出错不会让其他规则失效。出错的行会被忽略，并在设置界面中列出。
- 命中规则会让评论**进入待审核**。评论永远不会被拒绝。如果正则表达式在运行时出错（例如达到 PCRE 回溯上限），评论也会进入待审核。
- 限制：最多读取 2000 条规则（`WordFilter::MAX_RULES`）。每个正则表达式运行时，`pcre.backtrack_limit` 会降到 100000（`WordFilter::REGEX_MATCH_LIMIT`）。检查满 0.25 秒（`WordFilter::REGEX_TIME_BUDGET`）后，剩下的正则表达式不再运行。以任一方式被中止的正则表达式会让评论进入待审核（“time-limit”）。PCRE 并不计算所有类型的工作量，所以由许多贪婪扫描组成的长表达式（例如重复多次的 `(\w+)\s+\1`）在 10000 个字符的评论上，每一份仍然可能耗时约 80 毫秒。
- 敏感词过滤对所有作者生效，包括管理员和拥有 `glowthread-trusted` 的用户。

示例：

```text
# Advertising: whole words, also "cheap-pills" or "c h e a p pills"
cheap pills
# A regular expression for variants
/\bfree\s+(?:coins|gems)\b/i
```

要在不保存的情况下检查规则，请使用设置界面上的“测试一段评论”。它调用 API 模块 `action=glowthreadfiltertest`，这个模块需要 `glowthread-configure`。测试与发表评论运行同样的代码，使用编辑框中的规则，无论是否已保存。

## 权限

| 权限 | 默认用户组 | 允许 |
|---|---|---|
| `glowthread-post` | `user` | 发表评论和回复、删除自己的评论 |
| `glowthread-react` | `user` | 点赞评论 |
| `glowthread-report` | `user` | 举报评论 |
| `glowthread-trusted` | `sysop`、`bot` | 不受新账号审核限制。这些用户的评论永远不会因举报而进入待审核 |
| `glowthread-moderate` | `sysop` | 使用 Special:评论管理 和评论区中的管理工具，更改页面的评论状态，查看待审核和已删除的评论，以及作者和页面的记录 |
| `glowthread-erase` | `sysop` | 永久抹除已删除的评论（需同时拥有 `glowthread-moderate`），以及查看 Special:Log/glowthread-private |
| `glowthread-configure` | `sysop` | 修改维基内设置和测试敏感词过滤。设置界面是 Special:评论管理 的一部分，而 Special:评论管理 需要 `glowthread-moderate` |
| `glowthread-viewlog` | `publicLog` 开启时为所有人，否则为拥有 `glowthread-moderate`、`glowthread-erase` 或 `glowthread-configure` 的用户 | 查看 Special:Log/glowthread，以及最近更改和监视列表中的相应记录。见[日志](#日志) |

页面保护不涵盖评论：拥有 `glowthread-post` 的用户可以在受保护的页面上发表评论。只有 `$wgNamespaceProtection` 会生效。要阻止某个页面上出现新评论，请把它的评论设为“锁定”。

不支持匿名发表评论，也没有测试过。不要把 `glowthread-post`、`glowthread-react` 和 `glowthread-report` 授予 `*` 用户组。

像往常一样把权限授予其他用户组。例如，设立一个评论管理员用户组，并只让监督员抹除：

```php
$wgGroupPermissions['commentmod']['glowthread-moderate'] = true;

$wgGroupPermissions['sysop']['glowthread-erase'] = false;
$wgGroupPermissions['suppress']['glowthread-erase'] = true;
```

抹除还需要 `glowthread-moderate`。不是管理员的监督员也需要这项权限。

要让可信的老用户不受新账号审核限制，可以把 `glowthread-trusted` 授予他们所在的用户组，例如 `autoconfirmed`，前提是你的维基的自动确认规则足够严格。

### 封禁

- 读者操作（发表、点赞、举报、删除自己的评论）会检查评论所在页面上的封禁。部分封禁只对它覆盖的页面和命名空间生效。
- 在任何封禁下，包括部分封禁，管理操作和设置更改都会被拒绝。
- 没有“只禁止评论”的封禁。可以禁止该用户编辑，或对相关页面或命名空间使用部分封禁。

### 机器人密码和 OAuth

授权 `editpage` 包含发表、点赞和举报。`delete` 包含管理。`oversight` 包含抹除，抹除无法撤销，所以抹除评论的工具需要同时具有 `delete` 和 `oversight`。`editsiteconfig` 包含配置。每个机器人密码和 OAuth 应用都有的 `basic` 包含 `glowthread-viewlog`，所以只要其用户能查看评论日志，工具也能查看。

## 速率限制

GlowThread 在 `$wgRateLimits` 中加入以下限制。每个键都是一项 GlowThread 权限的名称。

| 键 | `user` | `newbie` | `ip` | `ip-all` | `subnet-all` |
|---|---|---|---|---|---|
| `glowthread-post`（发表、回复、删除自己的评论） | 每分钟 6 次 | 每分钟 3 次 | 每分钟 3 次 | 每分钟 12 次 | 每分钟 24 次 |
| `glowthread-react` | 每分钟 60 次 | 每分钟 30 次 | 每分钟 30 次 | 每分钟 120 次 | 每分钟 240 次 |
| `glowthread-report` | 每天 20 次 | 每天 5 次 | 每天 5 次 | 每天 30 次 | 每天 60 次 |
| `glowthread-moderate` | 每分钟 120 次 | | | 每分钟 240 次 | 每分钟 480 次 |
| `glowthread-erase`（每抹除一条评论计一次），不可绕过 | 每小时 30 次 | | | 每小时 60 次 | 每小时 120 次 |
| `glowthread-configure`（设置更改、敏感词测试），不可绕过 | 每分钟 60 次 | | | 每分钟 120 次 | 每分钟 240 次 |

各列的含义：

- `user`：每个账号。
- `newbie`：每个未自动确认的账号。对这些账号，它取代 `user`。
- `ip`：每个 IP 地址，只针对未登录用户和新手账号。
- `ip-all`：同一 IP 地址后面的所有人，包括已登录的账号。
- `subnet-all`：同一子网后面的所有人，包括已登录的账号。子网指 IPv4 /24 或 IPv6 /64。

修改之前需要了解的规则：

- 拥有 `noratelimit` 的用户（默认为管理员和行政员）不受限制，但标为“不可绕过”的两个键除外。抹除无法撤销，所以一个被盗用的管理员账号不能一次清空大量评论。这两个键把 `&can-bypass` 设为 `false`。
- 你的值会按条件与这些默认值合并。`$wgRateLimits['glowthread-post']['user'] = [ 10, 60 ];` 只替换这一个条件。
- 只有当 `ip-all` 和 `subnet-all` 的速率高于账号自身的速率时，MediaWiki 才会对账号应用它们（“用户限制更宽松”时会忽略它们）。所以默认值高于 `user` 速率：大多数键是 2 倍和 4 倍，举报是 1.5 倍和 3 倍。如果你提高了某个 `user` 限制，也要一起提高 `ip-all` 和 `subnet-all`，否则它们会在不知不觉中不再对账号生效。只有同时设置了 `ip-all`，才会检查 `subnet-all`。
- `ip-all` 和 `subnet-all` 防止同一地址上的一批新账号成倍放大单个账号的限制。它们设得远高于正常流量，所以同一个运营商级 NAT 地址后面的大量读者不会受影响。
- 一个拥有整个 IPv6 /48 的客户端（很多主机商和运营商都会分配 /48）拥有 65,536 个 /64 子网，所以 `ip-all` 和 `subnet-all` 限制不了它：只有按账号的限制会生效。这是 MediaWiki 速率限制器本身的行为。如果这对你的 wiki 很重要，请在前端代理上按 /48 限制请求，或使用 `PingLimiter` 钩子。
- 触发限制时，API 返回 `ratelimited` 和 `data.retryAfter`，其值是所适用条件中最长的时间窗口。
- 如果一次抹除请求的评论数超过本小时剩余的额度，会抹除到上限为止，其余评论返回 `ratelimited`，并保持已删除状态。

**MediaWiki 必须看到每个读者的真实 IP 地址。** 在 MediaWiki 不信任的代理或 CDN 后面，所有请求看起来都来自代理。这时 `ip-all` 就变成整个维基共用的一个限制：所有人合计每分钟 12 条评论。检查方法：在未登录状态下打开 `api.php?action=query&meta=userinfo&format=json`。`query.userinfo.name` 就是 MediaWiki 看到的地址，它必须是你自己的公网地址。修复方法见[部署在 CDN 之后](caching.md#客户端-ip-地址)。

**计数器保存在 `$wgMicroStashType` 中。** 所有应用服务器必须共享它（例如 Redis 或 Memcached）。如果这个存储丢失了数据（没有持久化时重启，或在内存压力下被驱逐），所有限制都会从零开始重新计数。每位作者待审核评论的数量上限（`$wgGlowThreadMaxHeldPerUser`）在数据库中计数，仍然有效。请让这个存储保持在其内存上限以下。

## 日志

| 日志 | 内容 | 谁可以查看 |
|---|---|---|
| Special:Log/glowthread（“评论日志”） | 每一次管理操作、页面状态更改和设置更改，作者删除自己的评论，以及 FlowThread 导入（`glowthread/import`） | 拥有 `glowthread-viewlog` 的用户（`$wgLogRestrictions`）：`publicLog` 开启时为所有人。默认不在“主要公开日志”中显示（`$wgFilterLogTypes`） |
| Special:Log/glowthread-private（“评论抹除日志”） | 每条被抹除评论的作者 | 拥有 `glowthread-erase` 的用户（`$wgLogRestrictions`） |
| Special:Log/comments（“FlowThread评论日志”） | 从 FlowThread 迁移过来的维基上，FlowThread 的旧记录 | 与 Special:Log/glowthread 相同的读者，前提是 `$wgGlowThreadLegacyCompat` 开启，并且没有其他扩展使用日志类型 `comments`。默认不在“主要公开日志”中显示 |

- **谁能看到日志。** `$wgLogRestrictions` 把 `glowthread` 类型，以及 GlowThread 显示 `comments` 时的该类型，限制给 `glowthread-viewlog` 权限。`publicLog` 设置开启时，GlowThread 把这项权限给所有人，否则只给拥有 `glowthread-moderate`、`glowthread-erase` 或 `glowthread-configure` 的用户。要让其他用户组在设置关闭时也能查看日志，请授予它 `glowthread-viewlog`。没有这项权限的用户在 Special:Log、Special:RecentChanges、Special:RecentChangesLinked、监视列表和 API 中都看不到这些记录。
- `publicLog` 开启时，`glowthread` 日志记录会作为日志操作发布到 Special:RecentChanges，所以 CheckUser 会像对待其他日志记录一样记录它们，监视该页面的用户也会在监视列表中看到它们。导入记录（`glowthread/import`）是例外：它只写入日志。新评论和举报不会写入日志。加载了 CheckUser 时，每一条都会改为生成一条 [CheckUser 记录](#checkuser)。
- **`publicLog` 关闭期间记录的条目不会在最近更改中生成行。** 它们不会发送监视列表邮件或 Echo 监视列表通知，也不会留下监视列表标记。管理员可以在 Special:Log/glowthread 和 Special:评论管理 中找到它们，但在最近更改和监视列表中找不到，即使之后重新开启这个设置也一样。加载了 CheckUser 时，GlowThread 会把它们直接交给 CheckUser。
- GlowThread 只写入 `glowthread` 和 `glowthread-private` 两种日志类型。`comments` 是 FlowThread 的日志类型。只有在 `$wgGlowThreadLegacyCompat` 开启，并且没有其他扩展已经使用这个名称（FlowThread 本身，或者日志同名的 Extension:Comments）时，GlowThread 才会注册它。这样 FlowThread 的旧记录会继续显示在 Special:Log/comments 和 Special:评论管理 的页面历史中。关闭这个设置时，`$wgLogRestrictions` 把 `comments` 限制给 `glowthread-legacy-log` 权限，而没有任何用户组拥有这项权限。在重新开启之前，这些记录不会出现在 Special:Log 和 API 中。
- 评论被抹除后，其作者针对这条评论的公开日志记录（自己删除它，或作为管理员处理它）的执行者和理由会通过 `log_deleted` 隐藏，与核心的日志修订版本删除功能相同。没有 `deletedhistory` 的读者两者都看不到。如果你把 `glowthread-erase` 移到 `suppress` 用户组，管理员仍然拥有 `deletedhistory`，仍然可以看到这些执行者。
- GlowThread 的日志记录显示作者当前的名称，该账号被 `hideuser` 隐藏时则隐藏名称。
- FlowThread 的旧记录以纯文本写着评论作者的名字。日志格式化器会隐藏被 `hideuser` 封禁隐藏的名字，并对所有没有 `glowthread-erase` 的人隐藏 FlowThread `erase` 记录中提到的作者。FlowThread 的 `delete`、`recover` 和 `markchecked` 记录只保存作者的名字，不保存评论，所以即使评论后来被抹除，这些记录仍会显示作者。如果这一点很重要，请用核心的日志修订版本删除功能隐藏这类记录。
- 调试日志：把 `GlowThread` 频道写入文件，可以记录管理员何时达到抹除上限，例如 `$wgDebugLogGroups['GlowThread'] = '/var/log/mediawiki/glowthread.log';`。MediaWiki 自己的 `ratelimit` 频道会记录每一次触发的限制。

日志记录的格式见 [api.md](api.md#日志记录)。

## 通知（Echo）

加载 Echo 后，在以下情况下 GlowThread 会通知相关用户：

- 有人回复了他们的评论，
- 有人在评论中提及他们（`@Name`），
- 有人在他们的用户页（`User:Name`，不含子页面）上发表评论，前提是用户命名空间已开启评论（`$wgGlowThreadNamespaces[NS_USER] = true;`）。如果这条评论同时回复或提及了 Name，Name 会改为收到回复或提及的通知，
- 管理员删除、恢复或通过了他们的评论。

无需任何配置。通知类型会通过 Echo 的钩子自行注册。

- 用户在 Special:Preferences 的“通知”中，在“评论”类别（`glowthread`）下进行选择。默认开启网页通知，关闭电子邮件通知。
- 评论被抹除后，它引起的通知（回复、提及、用户页）会通过 Echo 的管理功能隐藏，Echo 不再显示其作者。
- 撤销管理操作不会发送任何通知。
- 一条评论只通知一次。撤销后再次通过它，不会发送新的回复或提及通知。
- 同一位作者每小时最多向同一位用户发送 3 条评论通知，其余的会被丢弃。
- 同一页面上的提及会像回复一样合并为一条通知。
- 作者删除自己的评论时，它引起的通知会被隐藏，与被抹除的评论相同。
- **推荐：** 开启 Echo 的按用户屏蔽功能。没有它，通过提及或回复受到骚扰的读者只能关闭所有评论通知，无法只屏蔽某一个人。

  ```php
  $wgEchoPerUserBlacklist = true;
  ```

- 在从 FlowThread 迁移过来的维基上，只要 `$wgGlowThreadLegacyCompat` 开启，FlowThread 已保存的通知就会继续显示（见 [FlowThread 兼容](#flowthread-兼容)）。偏好设置中不提供它们的类别。[从 FlowThread 迁移](migrating-from-flowthread.md#9-迁移通知偏好设置)介绍了如何把用户在 FlowThread 中的通知选择复制到 GlowThread。

各类通知的详细信息见 [api.md](api.md#通知echo)。

## CheckUser

加载 CheckUser 后，每条新评论，无论公开还是待审核，都会作为一条 CheckUser 私有事件保存，包括作者的 IP 地址、`X-Forwarded-For` 和用户代理。Special:CheckUser 会在该用户的操作中把它列为在某个页面上发表的评论。每次举报也以同样的方式保存，包括举报人的 IP 地址、`X-Forwarded-For` 和用户代理，并列为举报了一条评论。点赞不会被记录。这些记录遵循 CheckUser 自身的保留期限（`$wgCUDMaxAge`，默认 90 天）。它们不会写入 Special:Log 或 Special:RecentChanges。

管理操作是普通的 `glowthread` 日志记录，所以 CheckUser 会像对待其他日志记录一样通过最近更改记录它们，在 `publicLog` 关闭期间则由 GlowThread 直接交给 CheckUser。其中的内容与 Special:Log/glowthread 已经显示的内容相同。被抹除评论的作者，以及被 `hideuser` 封禁隐藏的用户，在那里仍然是隐藏的。受限的 `glowthread-private` 日志永远不会进入最近更改或 CheckUser。自动转入待审核（`glowthread/autohold`）记录为由软件执行，IP 地址为 127.0.0.1，没有 `X-Forwarded-For` 和用户代理，它在最近更改中的行（如果有）也是 127.0.0.1。触发它的举报人有自己的举报记录。

从 FlowThread 导入的评论没有 CheckUser 记录。

## FlowThread 兼容

只适用于从 FlowThread 迁移过来的维基。设置 `$wgGlowThreadLegacyCompat = true;` 并保持开启。它做三件事。

**FlowThread 已保存的通知。** GlowThread 注册 FlowThread 的 Echo 通知类型，所以用户旧的 FlowThread 通知会继续显示。不会产生新的此类通知。

**FlowThread 的日志记录。** GlowThread 注册 FlowThread 的 `comments` 日志类型，所以它的旧记录会继续显示在 Special:Log/comments 和 Special:评论管理 的页面历史中。如果其他扩展已经使用了这个名称（FlowThread 本身或 Extension:Comments），则跳过这一步。见[日志](#日志)。

关闭这个设置时，这些通知和日志记录只是被隐藏，不会被删除：重新开启后它们会再次显示。

**兼容 API。** `api.php?action=flowthread` 会继续用 GlowThread 的数据响应 FlowThread 的读取类型：

- `type=list`，面向读者，
- `type=listall`，面向拥有 `glowthread-moderate` 的用户。

所有写入类型都会被拒绝，错误信息中会提到 GlowThread。这样读取 FlowThread API 的外部工具、应用和脚本可以继续工作。

- 默认关闭，因为新维基没有 FlowThread 数据。[从 FlowThread 迁移](migrating-from-flowthread.md)会开启它。关闭时，`action=flowthread` 是未知操作。
- FlowThread 本身已加载时，永远不会添加这个模块。
- 每次请求的限制：最多 100 条顶层评论，偏移量最多 10000，每页列表最多 300 条回复和 256 KiB 回复文字。
- 响应总是 `private`，所以任何缓存都不会保留它们。

响应格式见 [api.md](api.md#兼容-apiactionflowthread)。

## CDN

GlowThread 不需要 CDN 也能工作。在 MediaWiki 前面部署 Varnish 或其他 CDN 后，未登录读者可以从缓存获取第一页评论，评论变化时 MediaWiki 会清除缓存。CDN 需要为 `/rest.php/glowthread/v1/` 设置例外规则，并且 MediaWiki 必须看到客户端的真实 IP 地址。`$wgServer` 必须是固定的 URL，而不是根据每个请求计算出来的，除非设置了 `$wgInternalServer`（[主机名](caching.md#主机名)）。全部细节见[部署在 CDN 之后](caching.md)。

## 页面生命周期

- **删除页面**会隐藏它的评论，因为评论所在的页面已经不存在。不会抹除任何内容。
- **恢复已删除的页面**时，只有这次恢复所还原的修订对应的页面 ID，其评论才会恢复。在同一标题下恢复较晚页面的修订，不会带回此前在该标题下被删除的较早页面的评论。如果旧页面 ID 已被重新创建的页面接管，评论会移到合并后的页面，楼层号会排在该页面已用楼层之后。合并后的页面保留较严格的评论状态，并由一条 `lock` 或 `disable` 日志记录写明执行恢复的用户。
- **被监督的删除。** 如果页面最后一次删除是监督删除（`suppress/delete`），其评论只有在同时解除监督的恢复操作中才会回来，这需要 `suppressrevision`。否则评论会和被监督的修订一样，保持脱离页面的状态。
- **移动页面**不需要任何处理。评论跟随页面 ID。该页面已缓存的列表会被清除。
- **隐藏用户。** 通过 Special:Block 或 API 施加、修改或解除带 `hideuser` 的封禁时，会清除该用户最近评论过的最多 500 个页面的评论列表缓存。其他页面的列表（例如有人提及该用户的页面）会在 `$wgGlowThreadCdnMaxAge` 秒内更新。CentralAuth 的全域监督不会清除任何缓存。
- **合并账号。** 使用 Extension:UserMerge 合并账号时，该账号的评论、点赞、举报、记录下来的管理操作和日志中的执行者身份都会移到保留下来的账号，提及它的地方也会指向那个账号。如果两个账号点赞或举报过同一条评论，只保留一行（对于举报，保留未处理的那条，否则保留较新的那条），并修正评论的计数。

## 特殊页面

| 页面 | 谁可以使用 | 用途 |
|---|---|---|
| Special:评论管理（Special:GlowThread，别名 Special:Comments） | `glowthread-moderate` | 管理中心。子页面：`queue`（默认）、`settings`、`user/<name>`（某位作者的全部评论）、`page/<title>`（某个页面上的全部评论）。见[管理指南](moderation.md) |
| Special:评论链接（Special:GlowThreadLink） | 所有人 | 评论的固定链接，不在特殊页面列表中列出。`Special:GlowThreadLink/<id>` 在评论所在页面上打开该 ID 的评论。`Special:GlowThreadLink/<page title>/<n>` 打开页面上编号为 `n`（即楼层号）的评论。`Special:GlowThreadLink/<22 hex digits>` 按 FlowThread ID 打开从 FlowThread 导入的评论。读者看不到的评论会显示“未找到” |
| Special:FlowThreadLink | 所有人 | FlowThread 的固定链接页面名称，是 Special:评论链接 的别名，让以前安装 FlowThread 时的链接继续有效。不在列表中列出 |
| Special:FlowThreadManage | 所有人（管理中心本身需要 `glowthread-moderate`） | FlowThread 的管理页面名称。重定向到 Special:评论管理/queue。不在列表中列出。FlowThread 本身已加载时不会添加 |

固定链接会打开带有片段 `#gt-<id>` 的页面，评论区会展开并定位到这条评论。片段 `#glowthread` 会打开评论区本身。
