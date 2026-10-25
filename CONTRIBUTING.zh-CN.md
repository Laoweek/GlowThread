[English](CONTRIBUTING.md) | 简体中文

# 为 GlowThread 做贡献

感谢你的帮助。本页说明如何搭建开发用维基、代码放在哪里、需要运行哪些检查，以及消息和文档的规则。

## 环境搭建

你需要：

- Docker，用于开发用维基和 PHP 检查。
- Node.js 24 和 pnpm 11，用于浏览器端代码。使用 [fnm](https://github.com/Schniz/fnm)：`fnm install 24 && fnm use 24`。pnpm 的版本固定在 `client/package.json` 的 `packageManager` 字段中。`corepack enable` 会安装它。

然后按照 [dev/README.zh-CN.md](dev/README.zh-CN.md) 操作。它会启动一个装有 GlowThread、Echo、CheckUser 和 Varnish 的本地 MediaWiki，并填入虚构的页面、账号和评论，外加一个页面，上面是取自 CC0 许可的 Civil Comments 存档、经过审核的真实评论。

## 项目结构

| 路径 | 内容 |
|---|---|
| `extension.json` | 注册信息：权限、速率限制、配置、钩子、REST 路由、API 模块、ResourceLoader 模块 |
| `includes/` | PHP：服务、存储、REST 处理程序、API 模块、钩子、导入程序（`Import/`） |
| `maintenance/` | `importFlowThread.php`，即 FlowThread 导入程序 |
| `sql/` | `tables.json`（抽象数据库模式）和按数据库类型生成的 SQL |
| `i18n/` | 消息：`i18n/*.json`（服务器端）、`i18n/api/`（API 帮助）、`i18n/ui/`（浏览器应用） |
| `client/` | 浏览器应用的源代码（Vue、TypeScript、Tailwind CSS），有自己的 [README](client/README.zh-CN.md) |
| `resources/init/` | 预留空间并加载应用的小型加载器 |
| `resources/dist/` | 构建好的代码包。已提交到仓库，见[客户端](#客户端) |
| `tests/phpunit/` | PHP 单元测试和集成测试 |
| `tests/e2e/` | 针对开发用维基的浏览器测试（Playwright） |
| `dev/` | 开发用维基（Docker）、它的种子内容和 Varnish 配置示例 |
| `scripts/php-test.sh` | 在临时的 MediaWiki 容器中运行所有 PHP 检查 |
| `docs/` | 文档，有英文版和 [zh-CN](docs/zh-CN/) 版 |

[docs/zh-CN/architecture.md](docs/zh-CN/architecture.md) 说明了各部分如何协同工作。

## PHP 检查

```sh
scripts/php-test.sh            # MediaWiki 1.43 and 1.46
scripts/php-test.sh 1.45       # another version, by image tag
```

该脚本为每个版本启动一个临时的 `mediawiki:<version>` 容器，并运行 `php -l`、`extension.json` 校验、PHPUnit（单元测试，以及在 SQLite 维基上的集成测试）和使用 MediaWiki 编码规范的 phpcs。它会打印摘要并删除容器。第一次运行时每个版本需要几分钟，主要用于安装 MediaWiki 的开发依赖。

默认加载 Echo 和 CheckUser。`GLOWTHREAD_TEST_EXTENSIONS` 可以选择其他扩展，例如 `GLOWTHREAD_TEST_EXTENSIONS="Echo CheckUser SpamBlacklist"`，或者用 `""` 在不加载任何扩展的情况下测试。脚本的开头部分列出了所有选项。

修改数据库模式时，编辑 `sql/tables.json`，并用 MediaWiki 的 `maintenance/generateSchemaSql.php` 重新生成 SQL 文件。修改已有的表还需要一个补丁文件，并在 `includes/Hooks/SchemaHooks.php` 中添加一项，这样 `update.php` 才会应用它。

## 客户端

评论区和管理中心是 `client/` 中的 Vue 应用。在 `client/` 中运行：

```sh
pnpm install
pnpm typecheck
pnpm test
pnpm build
```

- `pnpm test` 运行单元测试（Vitest），其中包括以下检查：源代码使用的每个消息键都存在，`en`、`qqq`、`zh-hans` 和 `zh-hant` 的键相同，没有消息以空格开头或结尾，也没有消息以冒号结尾。
- `pnpm build` 重新构建 `resources/dist/`，然后运行 `pnpm check`：如果 JavaScript 高于 ES2017，或者 CSS 会被最旧的受支持浏览器丢弃，或者 CSS 在 ResourceLoader 为从右到左的语言翻转时会出错（CSSJanus 会改动的选择器或动画名称），检查就会失败。它还会在每个代码包旁边写入第三方许可证列表。

**提交重新构建的代码包。** 维基安装 GlowThread 时不需要 Node.js，所以 `resources/dist/` 必须与源代码一致。CI 会从头重新构建它，如果已提交的文件与之不同就会失败。每次修改 `client/` 后都要运行 `pnpm build`，并把 `resources/dist/` 一起提交。

浏览器最低版本（Chrome 87、Safari 14、Firefox 78）、从右到左语言的规则、设计令牌，以及如何添加组件或消息，见 [client/README.zh-CN.md](client/README.zh-CN.md)。

## 浏览器测试

`tests/e2e/` 中是针对开发用维基运行的 Playwright 测试：阅读、发表、管理、页面状态、皮肤、浅色和深色模式、桌面和手机尺寸。

1. 用全新的种子数据启动开发用维基（[dev/README.zh-CN.md](dev/README.zh-CN.md)）：`cd dev && ./setup.sh --reset`。测试依赖种子数据中的待审核队列，而且 Newbie 只在每次设置后的 18 小时内处于新账号审核期。
2. 在 `tests/e2e/` 中：

   ```sh
   pnpm install
   pnpm exec playwright install chromium webkit
   pnpm test
   ```

   完整运行一次约需 7 分钟，使用一个 worker。`cdn.spec.ts` 经由 8150 端口上的 Varnish（`GT_CDN`）运行。FlowThread 兼容 API 的测试只有在维基开启该功能时才会运行（在 `dev/` 中执行 `GLOWTHREAD_DEV_LEGACY_COMPAT=1 ./setup.sh`），否则跳过。

`tests/e2e/playwright.config.ts` 的开头部分列出了各个项目和环境变量，例如表示维基地址的 `GT_WIKI`。`tests/e2e/old-chromium/` 在真实的 Chromium 90 中检查评论区，它接近 GlowThread 支持的最旧浏览器引擎。它只能在 Apple 芯片的 macOS 上运行。

## 消息和语言

- 英语是源语言。把每条新消息添加到 `en.json`，并在同一目录的 `qqq.json` 中为它写说明：它出现在哪里，每个参数（`$1`）是什么，以及翻译者必须知道的其他信息。
- 界面提供简体中文（`zh-hans`）和繁体中文（`zh-hant`）。对于 `i18n/ui/`，还要把键添加到这两个文件中，因为 `pnpm test` 要求每个文件的键都相同。如果你无法翻译，请在拉取请求中说明。
- 使用 MediaWiki 的消息语法：参数用 `$1`，需要时使用 `{{PLURAL:$1|…}}` 和 `{{GENDER:…}}`。绝不要用片段拼接句子。
- 把消息中的可变部分作为参数传入，让翻译者控制语序：用 `Held for review: $1`，而不是标签 `Held for review:` 后面跟着来自模板的文字。消息绝不以空格开头或结尾，也绝不以冒号结尾（`pnpm test` 会检查这一点）。消息末尾需要的空格写作 `&#32;`，因为 translatewiki.net 会去掉消息首尾的空格。
- UI 消息以 `glowthread-ui-` 开头。ResourceLoader 会把 `i18n/ui/en.json` 中的键随应用一起发送（评论区不包含 `glowthread-ui-admin-` 键，这些键只有管理中心使用），所以新增的键不需要修改 `extension.json`。

## 代码风格

- PHP 遵循 MediaWiki 编码规范，由 `scripts/php-test.sh` 中的 phpcs 检查。使用制表符缩进。
- TypeScript 和 Vue 遵循周围文件的风格。`pnpm typecheck` 必须通过。
- 代码注释、测试名称和提交消息用英语书写。引用界面标签时，使用它的英文文本或消息键。
- 非英语文本只能出现在消息文件、特殊页面别名、有意检查其他文字（CJK、从右到左的文字、表情符号）的测试中，以及开发用维基的中文演示页面（`番茄炒蛋`，连同它的评论和账号）中。这个页面是为中文文档的截图准备的。
- 测试和开发用维基只使用虚构的数据，`dev/seed/content/civil-comments.json` 中经过审核的 CC0 子集除外（见 `dev/seed/dataset/README.md`）。链接使用 `example.com`、`example.org` 和 `example.net`，地址使用文档专用的 IP 段（`192.0.2.0/24`、`198.51.100.0/24`、`203.0.113.0/24`、`2001:db8::/32`）。
- 保持修改专注。修复错误时要附带一个在没有修复时会失败的测试。

## 文档

- 每份文档都有英文版和简体中文版。根目录文件和各文件夹的 README：`X.md` 和 `X.zh-CN.md`（例如 `dev/README.md` 和 `dev/README.zh-CN.md`）。`docs/` 下：`docs/x.md` 和 `docs/zh-CN/x.md`。每份文档的第一行都链接到它的另一语言版本。
- 在同一个拉取请求中修改两个版本。如果你无法撰写中文版，请说明，并保证英文版准确。
- 为可能把英语作为第二语言的维基管理员写作：短句、常用词、用表格列出设置，写出设置的准确名称和默认值。
- 行为改变时，更新描述它的页面：[配置](docs/zh-CN/configuration.md)、[管理](docs/zh-CN/moderation.md)、[API](docs/zh-CN/api.md) 和 README。
- README 中的截图由 `tests/e2e/scripts/docs-shots.mjs` 生成。如果某项修改改变了截图中显示的内容，请重置开发用维基（`cd dev && ./setup.sh --reset`），在 `tests/e2e/` 中运行 `pnpm docs-shots`，并同时提交 `docs/images/` 和 `docs/zh-CN/images/`。
- 设计文件：[PRODUCT.zh-CN.md](PRODUCT.zh-CN.md) 说明 GlowThread 服务的对象和它的原则，[DESIGN.zh-CN.md](DESIGN.zh-CN.md) 是设计系统的权威来源：颜色、字体、间距、组件以及背后的规则。修改界面之前请先阅读这两份文件。`.impeccable/design.json` 以机器可读的形式为设计工具保存同一套设计系统，PRODUCT.md 中的 `<!-- impeccable:... -->` 注释为这些工具标明文件格式。修改令牌或组件外观时，请先更新 DESIGN.md，再让 design.json 与之一致。

## 拉取请求

说明改了什么、为什么改，以及你是如何测试的：运行了哪些检查，对于界面修改，查看了哪些皮肤和屏幕尺寸。CI 会在每个拉取请求上运行 MediaWiki 1.43 和 1.46 上的 PHP 检查、客户端构建及其测试，以及 Varnish 策略测试。浏览器测试每晚运行，也可以按需运行。

## 许可证

参与贡献即表示你同意，你的贡献与 GlowThread 的其他部分一样，以 [MIT 许可证](LICENSE)发布。
