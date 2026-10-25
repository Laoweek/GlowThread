[English](README.md) | 简体中文

# GlowThread 开发用维基

用于开发和测试 GlowThread 的本地 MediaWiki 1.43。它是一个小型的英文社区食谱维基（“GlowThread Dev”），页面、账号和评论串都是虚构的，并且像前面有缓存的维基一样通过 Varnish 提供服务。其中没有任何内容来自真实的维基。其中一个页面 `Kitchen talk` 收录了来自 CC0 Civil Comments 存档的真实公开评论（新闻网站上的评论，经人工挑选和审核），以提供真实的评论量和评论串结构。

```
browser ── 127.0.0.1:8150 ──> varnish (9.0, varnish/default.vcl)
                                  │
browser ── 127.0.0.1:8151 ──> mediawiki (official mediawiki:1.43 image, Apache + PHP 8.3) ──> db (MariaDB 10.11)
                              jobrunner (same image, runs queued jobs)                    ──┘
```

## 环境要求

- 带 Compose v2 的 Docker：Docker Desktop、[Colima](https://github.com/abiosoft/colima)（`colima start`）或 Linux 上的 Docker Engine。
- git 和 bash。
- 镜像约占 3 GB 磁盘空间（mediawiki 1.5 GB、varnish 0.5 GB、mariadb 0.4 GB、composer 0.3 GB），数据库另需几百 MB。

你的机器上不需要安装 PHP、Composer 和 Node。所有东西都在容器中运行。

## 快速开始

```sh
cd dev
./setup.sh            # first run: image downloads, then about 30 seconds
open http://localhost:8150/wiki/Sourdough_starter
```

`setup.sh` 可以随时安全地重新运行，没有需要做的事时，一次运行只需几秒。每次运行会：

1. 获取固定版本的第三方代码（`fetch.sh`），并在 `.env.local` 中生成缺少的密码，
2. 启动或重新创建容器（Compose 项目 `glowthread`），
3. 首次运行时安装 MediaWiki，然后运行 `update.php`，让数据库模式的更改生效，
4. 保存种子页面，创建账号，设置它们的注册日期并施加封禁，
5. 在每个还没有评论的页面上填充种子评论，
6. 运行队列中的作业并清空 Varnish 缓存。

| 命令 | 作用 |
|---|---|
| `./setup.sh --reset` | 删除维基（数据库、安装程序输出、日志），并从种子数据重新搭建。保留 `.env.local` 和已获取的代码。 |
| `docker compose stop` | 停止容器。用 `./setup.sh` 或 `docker compose start` 再次启动。 |
| `docker compose down --volumes` | 删除容器和维基。 |
| `varnish/test.sh` | 测试缓存策略（不需要运行中的维基）。 |
| `./import-test.sh` | FlowThread 导入测试（见[旧版模式](#旧版模式和导入测试)）。 |

## 服务和端口

| URL 或服务 | 说明 |
|---|---|
| http://localhost:8150/ | 经过 Varnish 的维基。这是 `$wgServer`：链接和清除缓存的请求都使用它。 |
| http://localhost:8151/ | 直接访问 Apache，绕过 Varnish。它页面上的链接仍然指向 8150。 |
| `db` | MariaDB，不对外发布端口。`docker compose exec db mariadb -uwikiuser -p glowthread`（密码为 `.env.local` 中的 `GLOWTHREAD_DEV_DB_PASSWORD`）。表名以 `gt_` 开头。 |
| `jobrunner` | 作业一进入队列就运行（`$wgJobRunRate = 0`，所以页面浏览不运行任何作业）。 |

所有端口只监听 127.0.0.1。请使用 `localhost`，不要用 `127.0.0.1`：Varnish 只为 MediaWiki 清除缓存时使用的 Host（`localhost:8150`）缓存评论列表。

如果你的 shell 设置了 `http_proxy`，请确保本地请求绕过代理（`NO_PROXY=localhost`，或 `curl --noproxy localhost`）。

通过环境变量设置：

| 变量 | 默认值 | 作用 |
|---|---|---|
| `GLOWTHREAD_DEV_PORT` | `8150` | Varnish 端口，也是 `$wgServer` 中的端口 |
| `GLOWTHREAD_DEV_DIRECT_PORT` | `8151` | Apache 端口 |
| `GLOWTHREAD_SRC` | `..` | 要挂载的 GlowThread 检出（见[其他检出](#其他检出)） |
| `GLOWTHREAD_DEV_LEGACY` | `0` | `1` 加载 FlowThread 而不是 GlowThread |
| `GLOWTHREAD_DEV_LEGACY_COMPAT` | `0` | `1` 开启 `$wgGlowThreadLegacyCompat`。不能与 `GLOWTHREAD_DEV_LEGACY=1` 同时使用 |
| `GLOWTHREAD_DEV_DB` | `mariadb` | `mysql` 改为运行 MySQL 8.0（见 [MySQL](#mysql)） |
| `GLOWTHREAD_DEV_MW_IMAGE` | `mediawiki:1.43` | 使用其他 MediaWiki 镜像，例如更新的 1.43 补丁版本 |
| `GLOWTHREAD_DEV_LOAD_TEST_COMMENTS` | `400` | 在 `Sandbox/Load test` 上生成的评论数（仅在该页面还没有评论时生成，所以要与 `--reset` 一起使用） |

每次运行 `setup.sh` 都要传入这些变量，例如 `GLOWTHREAD_DEV_PORT=8250 GLOWTHREAD_DEV_DIRECT_PORT=8251 ./setup.sh`。Compose 本身不读取 `.env.local`，所以请用 `./setup.sh` 启动整套服务。首次运行之后，直接运行 `docker compose up -d` 也可以（使用默认设置），因为数据库会保留创建时的密码。

## 账号

密码生成在 `dev/.env.local` 中（权限 600，已被 gitignore 忽略），每个账号一个变量，例如 `GLOWTHREAD_DEV_PASSWORD_ALICE`。`setup.sh` 从不打印密码。

| 账号 | 用户组 | 注册时间 | 角色 |
|---|---|---|---|
| Admin | sysop、bureaucrat、checkuser | 5 年前 | 安装维基、保存种子页面、施加封禁 |
| Moderator | sysop | 4 年前 | 在种子数据中置顶公告、删除评论、锁定和关闭页面 |
| Alice、Bob、Carol、Dave、Erin | | 2 到 3 年前 | 普通成员 |
| Trent | trusted | 6 年前 | 老成员。开发用的用户组 `trusted` 拥有 `glowthread-trusted`：不受新账号审核限制，从不仅因举报而进入待审核 |
| Mallory | | 21 个月前 | 扮演发垃圾信息和捣乱的用户 |
| Newbie | | 最近一次运行 `setup.sh` 的 6 小时前 | 处于 GlowThread 的新账号审核期内（24 小时），所以它的评论会进入待审核 |
| Blocked | | 2 年前 | 被全站封禁 |
| PartialBlocked | | 2 年前 | 仅在页面“Banana bread”上被封禁 |
| BlockedMod | sysop | 3 年前 | 被全站封禁的管理员（被封禁的管理员不能执行管理操作） |
| 老陈、麦穗、林小满、阿芳、橘子汽水、豆豆不吃豆、养生小贴士、闲置转让 | | 12 天到 5.5 年前 | 演示账号：只在中文演示页面 `番茄炒蛋` 上发表评论的成员。它们没有密码（`.env.local` 中也没有对应的变量），所以没有人能以它们的身份登录 |

这些封禁没有到期时间，并且关闭了自动封禁：所有开发请求都来自同样几个 Docker 地址，自动封禁会把其他账号也一起封禁。每次运行都会重新设置与 `.env.local` 不一致的账号密码。Newbie 的注册日期随每次运行而变化，所以如果需要 Newbie 处于新账号审核期内，而上次运行已超过 18 小时，请再运行一次 `./setup.sh`。这样重新运行后，Newbie 看起来是在它自己的种子评论之后才注册的，这没有影响。

数据库卷还在时删除 `.env.local`，`setup.sh` 会在做出任何更改之前停止，因为数据库保留着最初的密码。这时请运行 `./setup.sh --reset`。

在脚本中用 Action API 登录（先 `action=query&meta=tokens&type=login`，再 `action=clientlogin`）。会话 Cookie 是 `glowthread_gt__session`。

MediaWiki 信任来自 Docker 网络的 `X-Forwarded-For`，所以测试可以发送 `X-Forwarded-For: 203.0.113.7`（两个端口均可）来冒充另一个客户端。GlowThread 的写入限制包括按 IP 计算的键（`ip-all`、`subnet-all`），在这里所有账号原本会共用它们，所以大量写入的脚本应为每个账号发送各自的地址。

## 种子内容

维基的种子数据来自 `seed/`：

| 文件夹或文件 | 内容 |
|---|---|
| `seed/content/` | 食谱内容：`pages.json` 和 `pages/`（Main Page、九个食谱、`Kitchen talk`、中文食谱 `番茄炒蛋`、`Sandbox`、`Sandbox/i18n`），`comments.json` 中的评论串，`zh-hans.json`（`番茄炒蛋` 的中文评论），`civil-comments.json`（CC0，见 `seed/dataset/README.md`）以及任何同样结构的其他评论文件，`fragments.json`（用于负载测试页面的句子片段），`i18n.json`（`Sandbox/i18n` 上使用其他文字和书写方向的评论）和 `flowthread.json`（旧版模式）。它的 `README.md` 说明了格式。 |
| `seed/site/` | 开发环境额外添加的页面：`MediaWiki:Spam-blacklist`（屏蔽指向 `spam.example` 的链接），`User:Alice` 和 `User:Trent`（`User:Alice` 上带有评论），以及 `Sandbox/Load test`。 |

有特定用途的页面：

| 页面 | 状态 | 用来测试什么 |
|---|---|---|
| Sourdough starter | 开放 | 展示页面：47 条手写评论，包括一条置顶公告、热门评论、一条 Newbie 发表的待审核评论、一条被 Moderator 删除的评论，以及未处理的举报 |
| Banana bread | 开放 | Mallory 的垃圾链接，被垃圾链接黑名单拦下进入待审核。PartialBlocked 在这里被封禁。 |
| Buttermilk pancakes | 锁定 | 评论保留，没有人能添加新评论 |
| Cold brew coffee | 关闭 | 评论被隐藏（GlowThread 状态 `disabled`） |
| Pickled red onions | 开放 | 没有评论：空状态 |
| Kitchen talk | 开放 | 来自 CC0 Civil Comments 存档的 414 条真实公开评论，分属 324 个评论串：真实的长度、回复结构和点赞数（`seed/dataset/README.md`） |
| 番茄炒蛋 | 开放 | 中文演示页面：一个中文食谱，带有 20 条中文评论，作者是八个演示账号和 Moderator。其中有一条置顶公告、点赞、@提及、表情符号、一个链接，以及两条评论上未处理的举报。文档的中文截图取自这个页面（`tests/e2e/scripts/docs-shots.mjs`），英文截图取自 Sourdough starter |
| Sandbox | 开放 | 初始为空，供测试脚本使用 |
| Sandbox/Load test | 开放 | 负载和分页测试：400 条评论（`GLOWTHREAD_DEV_LOAD_TEST_COMMENTS`），时间跨度 17 个月，带有回复和点赞，由带种子的随机数生成器根据 `fragments.json` 生成，所以每次运行都产生相同的评论串 |
| Sandbox/i18n | 开放 | 中文、日文、韩文、阿拉伯文、希伯来文、混合书写方向、表情符号序列、组合字符、一个很长的不间断字符串 |
| User:Alice | 开放 | 用户页上的评论会通知页面所有者 |

种子填充的工作方式（`maintenance/SeedComments.php`）：每条评论、回复、点赞、举报、置顶、删除和页面状态，都以执行操作的用户身份，通过 GlowThread 自己的服务完成，时间为内容中给出的时间（`"ago": "45d"`，相对于本次运行，带有少量确定性的随机偏移）。时钟通过 `ConvertibleTimestamp::setFakeTime()` 调整，延迟更新也在这个时间内运行，所以楼层号、计数器、热度分、日志条目、Echo 通知和 CheckUser 记录看起来都像是人们在过去几个月里写下的。每位作者从自己的地址写入：匿名作者使用各自的地址，账号使用 192.0.2.0/24 中的一个固定地址。CheckUser 只保留最近 90 天的数据，所以更早的评论没有 CheckUser 记录。仅在种子填充期间，速率限制关闭，并且匿名作者可以发表评论和举报（就像曾经允许匿名评论的维基上留下的旧匿名评论）。

种子填充会跳过所有已有评论的页面，所以你自己的测试评论在重新运行后仍然保留。种子中标记为待审核的评论，必须是被 GlowThread 自己的规则（新账号审核或垃圾链接黑名单）拦下的。待审核评论上的点赞和举报会被跳过，因为读者看不到这条评论。要从种子数据重新开始，请运行 `./setup.sh --reset`（约 30 秒）。

## 维基设置

`config/LocalSettings.php` 先从数据卷加载安装程序的输出（数据库凭据、密钥），然后加载 `config/LocalSettings.dev.php`：

- 英语、UTC、短 URL（`/wiki/$1`），不发送邮件，不允许上传。
- 数据库：安装程序的默认设置（二进制表选项），表前缀为 `gt_`，以便持续测试对前缀的处理。
- 缓存：不使用 Redis。对象缓存、会话缓存和解析器缓存都存放在数据库中，Web 服务器、作业执行进程和维护脚本共用这个数据库，所以种子填充或导入会让 Web 服务器看到的缓存失效。APCu 用作单服务器缓存。
- 皮肤：Vector 2022（默认）、旧版 Vector（`?useskin=vector`）、Citizen（`?useskin=citizen`）。手机通过 MobileFrontend 使用 Minerva。
- 扩展：Echo、Thanks 和 SpamBlacklist（镜像自带），CheckUser 和 MobileFrontend（另行获取），GlowThread（挂载），FlowThread（另行获取，仅用于旧版模式）。
- GlowThread：使用默认设置，另外允许在用户页上评论（`$wgGlowThreadNamespaces` 为主命名空间和 User）。`$wgGlowThreadLegacyCompat` 保持关闭（默认值），导入测试除外。
- CDN：`$wgUseCdn`，向 Varnish 发送 PURGE，信任来自 Docker 网络的 `X-Forwarded-For`。

第三方代码（`fetch.sh`，固定到确切的提交，已被 gitignore 忽略）：来自 Wikimedia Gerrit 上 REL1_43 分支的 CheckUser 和 MobileFrontend，Citizen 3.23.0，以及来自 https://github.com/nbdd0121/MW-FlowThread 的 FlowThread 1.3.3（它的最新发布版本，也是最后一个能在 MediaWiki 1.43 上运行的版本：之后 master 上的提交使用了 1.43 中没有的类名）。CheckUser 唯一的运行时依赖（firebase/php-jwt）由一个临时的 `composer:2.10.3` 容器安装。要更新固定的版本，请修改 `fetch.sh` 中的提交并删除对应的文件夹。

为什么这样选择：

- **官方镜像，无需构建。** `mediawiki:1.43` 已包含 Apache、短 URL、APCu、intl 和自带的扩展。开发设置（`config/php.ini`、`config/apache-access.conf`）以挂载方式提供，所以没有需要构建或重新构建的东西。
- **MariaDB 10.11。** 这是 MediaWiki 安装程序最常搭配使用的 LTS 版本，使用安装程序默认的二进制表。改用 MySQL 8.0 只需设置一个变量。
- **不使用 Redis。** 没有任何东西需要它。数据库缓存由所有进程共用，而只用 APCu 的缓存对维护脚本不可见。
- **Varnish 9.0**，使用基于 MediaWiki 手册的 VCL，这样缓存方面的错误（设置了 Cookie 的响应、从不被清除的列表）会先在这里暴露，而不是等到出现在带缓存的维基上。

## 旧版模式和导入测试

`GLOWTHREAD_DEV_LEGACY=1 ./setup.sh` 加载 FlowThread 而不是 GlowThread（两者都占用 `action=flowthread`，所以不能同时加载），并在还没有 FlowThread 评论的页面上，通过 FlowThread 自己的 API 发表 `seed/content/flowthread.json` 中的评论。FlowThread 根据评论的 ID 确定其日期，所以这些评论带有那次运行的时间。运行 `./setup.sh` 即可切换回来。两个扩展各自使用自己的数据表，所以切换不会丢失数据。

`./import-test.sh` 在开发数据上运行整个迁移过程：

1. 旧版模式，为 FlowThread 填充种子数据，
2. 切换回 GlowThread，并开启 `$wgGlowThreadLegacyCompat`（与从 FlowThread 迁移过来的维基的运行方式相同），
3. 运行 `maintenance/run.php GlowThread:importFlowThread`，先加 `--dry-run`，然后正式运行，再加 `--verify`（发现任何不一致时以 1 退出），
4. 检查每条 FlowThread 评论都有 GlowThread 副本，并且只读的 `action=flowthread` 路由能够响应，

然后回到正常模式，即使某一步失败也是如此（加 `--keep` 则停留在结束时的状态）。它可以安全地重新运行：导入程序以 FlowThread 的 ID 为键，只添加缺少的内容。

## Varnish 和缓存

`varnish/default.vcl` 遵循 MediaWiki 手册（缓存匿名页面浏览，放行已登录用户和 API 请求，接受来自 MediaWiki 的 PURGE），并为 GlowThread 的读取路由添加了例外规则。文件中的注释解释了每条规则，所以它可以作为生产环境 VCL 的示例。简而言之：

- 规范 Host 上对 `/rest.php/glowthread/v1/page/{id}/comments` 的匿名 GET 和 HEAD 请求会被缓存，每个页面和排序方式（`?sort=hot` 或 `?sort=new`）对应一个 URL。无用的参数会被丢弃，带非空 `cursor`（后续页）的请求直接放行（空的 `cursor` 视为第一页），已登录读者和 GlowThread 的其他所有路由也一样放行。
- 只存储明确带有 `s-maxage` 的响应。GlowThread 对读取第一页的匿名读者发送 `Cache-Control: public, s-maxage=300`，并在列表变化时清除两个 URL 的缓存。Varnish 把每个列表响应以 `private` 交给客户端，因为清除请求到达不了它前面的任何缓存。
- PURGE 通过存储在每个缓存对象上的标头，对确切的 Host 和 URL 发出 ban（桌面版和移动版副本一起），这样 Varnish 的 ban lurker 可以在后台清理 ban 列表。仅限开发环境的 `BAN /rest.php/glowthread/v1/page/{id}` 会清除一个页面的所有缓存 URL。
- 只接受来自 Docker 默认网络（172.16.0.0/12 和 192.168.0.0/16）的 PURGE 和 BAN。如果 Docker 引擎配置了其他地址池，需要把相应的地址段加入 `purge` ACL。

用于调试的标头：`X-Cache`（`HIT` 或 `MISS`），以及仅限开发环境的 `X-Cache-Detail`，它能区分 `PASS`（没有查找缓存）和 `MISS`。

匿名页面的 HTML 会保留 5 小时（`$wgCdnMaxAge`）。修改服务器端代码后，请登录查看页面、使用 8151 端口，或者清空缓存：

```sh
docker compose exec varnish varnishadm ban req.url '~' .
```

`varnish/test.sh` 在一个临时的 Varnish 容器中，用 `varnishtest` 针对一个假后端检查整个策略，包括来自 ACL 之外的 PURGE（通过 PROXY 协议监听器）以及没有配置规范 Host 的 Varnish。每次修改 VCL 后都要运行它。CI 也会运行它。

为了让例外规则生效，GlowThread 必须一直做到以下几点：回答匿名列表读取时使用 `public, s-maxage=N` 且不带 `Set-Cookie`，在 `$wgServer` 的 Host 上清除逐字节相同的规范 URL，从不为匿名读者设置名称中含有 `session` 或 `Token` 的 Cookie，并让可缓存的响应只取决于 URL（不取决于客户端地址、用户代理、语言或 Cookie）。

## 调试

```sh
docker compose logs -f mediawiki                                         # Apache access and errors
docker compose exec mediawiki sh -c 'tail -n 50 /var/www/data/logs/*.log'   # exceptions, PHP warnings, GlowThread's log
docker compose exec -u www-data mediawiki php maintenance/run.php eval      # PHP prompt with MediaWiki loaded
docker compose exec -u www-data mediawiki php maintenance/run.php sql      # SQL prompt
```

- 异常会在页面上显示详细信息（`$wgShowExceptionDetails`），弃用警告会写入日志。
- PHP 的修改在下一个请求时生效。扩展的任何 PHP 或 JSON 文件发生变化时，解析器缓存都会失效。
- ResourceLoader 调试模式：在单个页面上加 `?debug=1`，或运行 `touch config/rl-debug.on` 对所有请求开启（删除该文件即可关闭）。
- 损坏的 `extension.json`（无法解析的）会被跳过，所以你编辑它时维基仍能正常工作。

## 其他检出

项目根目录以只读方式挂载到 `extensions/GlowThread`。要测试其他检出，例如一个 git worktree：

```sh
GLOWTHREAD_SRC=/path/to/checkout ./setup.sh
```

这个路径必须是 Docker 能挂载的（在 macOS 上使用 Colima 或 Docker Desktop 时，是主目录下的文件夹）。该文件夹位于 Apache 的网站根目录内，所以 `config/apache-access.conf` 只提供其中的静态资源（`client/`、`dev/`、`docs/`、`tests/` 和其他非运行时文件夹以外的 CSS、JS、图片和字体），不直接运行其中的任何 PHP 文件，也不提供任何扩展或皮肤的隐藏文件或文件夹。

容器中的 PHP 无法读取检出中的 `dev/` 文件夹：它上面挂载了一个空的只读文件夹，所以 `dev/.env.local` 无法被读取。要用同样的方式隐藏其他本地文件夹（例如 `private/`），请把它们列在 `dev/compose.override.yaml`（已被 git 忽略）中，在 `dev/` 中运行的 `docker compose`（以及 `setup.sh`）会把它和 `compose.yaml` 一起加载。其中列出的每个文件夹都必须存在于挂载的检出中，否则容器无法启动。

## MySQL

`GLOWTHREAD_DEV_DB=mysql ./setup.sh` 运行 MySQL 8.0 而不是 MariaDB，并使用自己的卷（`glowthread_db_mysql`、`glowthread_data_mysql`），所以它是用同样方式填充种子数据的第二个维基。`./setup.sh` 会回到 MariaDB 维基，后者一直保留。`GLOWTHREAD_DEV_DB=mysql ./setup.sh --reset` 只删除 MySQL 维基。

## 文件

| 文件 | 用途 |
|---|---|
| `setup.sh`、`fetch.sh`、`import-test.sh` | 搭建整套服务、获取固定版本的第三方代码、运行导入测试 |
| `compose.yaml` | 各项服务 |
| `compose.override.yaml` | 可选，仅限本地：要对容器隐藏的本检出中的文件夹 |
| `config/LocalSettings.php`、`config/LocalSettings.dev.php` | 设置入口文件和开发设置 |
| `config/php.ini`、`config/apache-access.conf` | 挂载到镜像中的 PHP 和 Apache 设置 |
| `maintenance/Seed*.php` | 种子脚本：页面、账号、GlowThread 评论、FlowThread 评论 |
| `seed/content/`、`seed/site/` | 种子内容 |
| `seed/dataset/` | 如何从 CC0 Civil Comments 存档构建 `civil-comments.json`（`build.py`，许可证和署名见其中的 `README.md`） |
| `varnish/default.vcl`、`varnish/policy.vtc`、`varnish/test.sh` | 缓存策略及其测试 |
