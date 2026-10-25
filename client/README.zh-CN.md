[English](README.md) | 简体中文

# GlowThread 客户端

GlowThread 的浏览器端：文章下方的评论区（`src/comments/`）、Special:评论管理 上的管理中心（`src/admin/`）、共用的设计系统（`src/design/`、`src/ui/`）、辅助代码（`src/lib/`、`src/shared/`），以及把它们构建为 `resources/dist/` 中 ResourceLoader 代码包的构建过程。

Vue 3.5（打包在代码中，因为 MediaWiki 1.43 自带的是 Vue 3.4，而 Reka UI 2.11 需要 3.5），Reka UI 2.11 基础组件加上手工移植的 shadcn-vue 组件，带 `gt:` 前缀的 Tailwind CSS 4，Lucide 图标，Vite 8。

## 命令

在 `client/` 中使用 Node 24 和 pnpm 11 运行（见 package.json 中的 `packageManager`）：

| 命令 | 作用 |
|---|---|
| `pnpm install` | 按 `pnpm-lock.yaml` 安装固定的版本（要求发布满 7 天，见 `pnpm-workspace.yaml`） |
| `pnpm build` | 清空 `resources/dist/`，构建每个应用，然后运行 `pnpm check`。它在每个代码包旁边写入 `<name>.licenses.txt`：代码包含在该代码包或其分块中的每个 npm 包，样式表则还有 Tailwind CSS，并附上版本、许可证和许可证全文（`scripts/vite-plugin-licenses.mjs`）。Reka UI 的 `useId` 按名称导入 Vue 的 `useId`，而不是整个 `vue` 命名空间，因此代码包不含 Vue 中用不到的部分（`scripts/vite-plugin-reka-useid.mjs`，Reka UI 升级改变了那个文件时会让构建失败）。构建输出会提交到仓库，并且必须可复现：全新执行 `pnpm install && pnpm build` 会得到逐字节相同的结果 |
| `pnpm check` | 对代码包做静态检查：ResourceLoader 包装函数内为 ES2017 语法，没有 `eval`，没有最旧的受支持浏览器会丢弃的 CSS，CSS 能为从右到左的语言安全翻转，每个文件都按文件名对应的模块注册在 extension.json 中，并把它所需的模块列为依赖，评论区的第一个模块中没有 Reka UI 浮层，大小不超出预算 |
| `pnpm test` | Vitest 单元测试（`tests/`）：格式化、头像颜色和对比度、文本片段、提及、错误消息、store、消息文件 |
| `pnpm tokens css`、`pnpm tokens docs` | 根据 `src/design/tokens.json` 写入 `src/design/tokens.css`，以及 `docs/theming.md` 和 `docs/zh-CN/theming.md` 中的令牌表格（见[设计令牌](#设计令牌)），并根据 `src/design/parts.ts` 写入这两个文档中的部件表格（见[部件钩子](#部件钩子)） |
| `pnpm typecheck` | `vue-tsc`（请停留在 TypeScript 6：TypeScript 7 会破坏 Vue SFC 编译器） |

针对开发用维基的端到端测试位于 `tests/e2e/`（Playwright，所有皮肤）和 `tests/e2e/old-chromium/`（真实的 Chromium 90）。

## 浏览器下限

Chrome 和 Android WebView 87、Safari 和 iOS 14、Firefox 78：许多手机读者仍在使用基于 Chrome 90 到 110 的应用内 Android WebView。`scripts/targets.mjs` 保存了以下几项的目标：JavaScript 语法级别（ES2017，这也是 ResourceLoader 的压缩器能理解的级别）、Vite 的 CSS 目标，以及最后一轮 Lightning CSS 处理。

对代码的影响：

- CSS 中不能有 `:is()`、`:where()` 或 `:has()`，不能有 `oklch()` 或 `color-mix()`，输出中不能有级联层，不能有范围媒体查询，不能有独立的 `translate`/`scale` 属性。出现其中任何一项，`pnpm check` 都会失败。换成 Tailwind 的说法：避免颜色的不透明度修饰符（`bg-x/50`），以及 `space-*`、`divide-*`、`group-*`、`peer-*`、`has-*`、`*:` 和 `translate-*` 工具类。
- `Array#at` 和 `Object.hasOwn`（Reka UI 内部使用）在 `src/lib/polyfills.ts` 中提供 polyfill。高于下限的新运行时 API 需要 polyfill 或保护判断。`pnpm check` 会列出它已知的这类 API。
- Flex 的 `gap` 需要 Safari 14.1，`:focus-visible` 需要 Safari 15.4。两者都能平稳降级（间距更紧凑、使用浏览器自己的焦点环）。

## CSS 如何限制在应用内部

皮肤和站点 CSS 不能影响应用的样式，应用也不能影响页面的样式（由 `tests/e2e/specs/skins.spec.ts` 在每种皮肤中测量）。

- 每个 Tailwind 类都带有 `gt:` 前缀（`gt:flex`），所以 `.hidden` 或 `.border` 这样的维基类永远不会匹配。
- 源 CSS 用 `.gt-scope` 表示“应用根元素或浮层容器”。`scripts/vite-plugin-mw-scope.mjs` 在构建后改写它：限定范围的重置（`all: revert`）获得两个 ID 加一个类的权重，其余规则获得两个 ID 加两个类的权重，用的是 `:not(#_)`（应用根元素和浮层容器都没有这个 ID），因为 Chrome 87 没有 `:is()`。带一个 ID 的皮肤规则会输，Reka UI 的内联样式仍然胜出，没有任何规则使用 `!important`。
- 应用挂载到 `#glowthread` 的一个子元素（`div.gt-scope.gt-root`）中，所以容器本身保留站点 CSS（例如 `MediaWiki:Vector.css` 或 `MediaWiki:Citizen.css`）给它的任何网格位置。从 FlowThread 迁移过来的维基要在站点 CSS 中把 `#flowthread` 改为 `#glowthread`。
- 根元素设置了所有可继承的文本属性（字体、间距、换行、`text-autospace`、配色方案），因为重置会把它们变成 `inherit`。
- 浮层（菜单、弹出框、面板、工具提示、轻提示、@提及列表）渲染到 `<body>` 末尾的 `#glowthread-portal` 中，它带有同样的作用域类。
- SVG 元素不参与重置，否则重置会去掉 Lucide 的表现属性。
- 带 `!important` 的站点 CSS 胜过任何优先级。有两条这样的规则在站点 CSS 中很常见，并且会影响到应用：`h1 … h6 { font-family: sans-serif !important }` 和 `.vector-body p { margin: 1em 0 !important }`。应用中仅有的几条 `!important` 规则就是为应对它们而写的：标题保持 `--glowthread-font-family`（`src/design/preflight.css`），管理中心位于 `.vector-body` 内，其中的段落保持零外边距（`src/admin/styles.css`）。管理中心把自己的文本块写成 `div`，所以它们上面的外边距工具类仍然有效。`tests/e2e/specs/skins.spec.ts` 和 `admin-skins.spec.ts` 会注入这些规则并进行检查。

## 从右到左的语言

当用户语言是从右到左书写的语言（阿拉伯语、希伯来语、波斯语等）时，MediaWiki 会在 `<html>` 上设置 `dir="rtl"`，ResourceLoader 提供经 CSSJanus 翻转的两份样式表：`margin-left` 变为 `margin-right`，`left` 变为 `right`，`translateX(100%)` 变为 `translateX(-100%)`，等等。从左到右的读者得到未改动的样式表。因此：

- 照常用物理方向的工具类编写布局（`gt:ml-auto`、`gt:pl-3`、`gt:border-l`、`gt:left-0`）。在从右到左的界面中它们会镜像。
- 关键帧名称中绝不能含有 `left` 或 `right`：CSSJanus 会改写 `animation-name`，却不会改写 `@keyframes`。`pnpm check` 会在代码包上运行 cssjanus，一旦有选择器或动画名称会被改变就失败。
- 沿阅读方向指示的图标（表示继续的尖角和箭头、回复的转角图标、发送）带有 `gt-flip-rtl`，它在 `:root[dir="rtl"]` 下将图标镜像。CSSJanus 无法处理 SVG 内部。
- Reka UI 通过 `ConfigProvider`（`src/lib/media.ts` 中的 `pageDir()`）从 `<html>` 获取方向，所以标签页、单选组和菜单中的方向键会遵循它。Floating UI 读取浮层容器的方向，把 `start` 对齐放在右侧。自己编写的横向移动按键处理（表情符号网格）也使用 `pageDir()`。
- 评论正文、名称和备注保持 `dir="auto"`，所以每一项都采用自己文本的方向。评论正文的宽度只与其文本相同（`gt:w-fit`），所以在从右到左的界面中，一条简短的英文评论仍然紧挨着它的头像。链接文字为 `dir="ltr"`，所以 URL 在从右到左的文本中保持原有顺序。

## 设计令牌

公开的自定义属性是 `--glowthread-<name>`。`src/design/tokens.json` 是唯一的来源：其中每个令牌都带有所属分组、浅色和夜间默认值、手机上的增量，以及英文和简体中文说明。GlowThread 从不声明公开令牌。它只读取这些令牌，每个都以自己的默认值作为后备值，所以站点 CSS 可以用任意选择器、按任意顺序设置其中任何一个，不需要 `!important`：

```css
/* MediaWiki:Common.css */
:root {
  --glowthread-color-accent: #3366cc;       /* light mode */
  --glowthread-night-color-accent: #88a3e8; /* night mode */
}
.ext-glowthread-comment[data-glowthread-pinned] { --glowthread-comment-bg: #f6f8fc; }
```

样式表如何使用它们（`src/design/tokens.css`，由 `pnpm tokens css` 生成，位于 `tokens` 层，构建时不对这一层做作用域改写）：

- 每个令牌都被解析为 `.gt-scope`（应用根元素和浮层容器）上的内部值 `--gt-v-<name>`，并在容器部件上再解析一次，即 `scripts/tokens.mjs` 的 `CONTAINER_PARTS` 中每个名称对应的 `.ext-glowthread-<part>`。所以在某个部件（一条评论、一个队列项目、一个菜单）上设置的令牌作用于它的内部。这个列表有意保持简短：列表中的每个元素都会重新解析所有令牌，这会增加样式重新计算的开销。这些部件还会重新设置文字颜色和字体，所以继承它们的文字（作者名）也会跟随在部件上设置的令牌。应用只读取 `--gt-v-*`。每个应用的样式表只保留其 JavaScript 写出的部件的规则。
- 颜色、浮层阴影、头像色调、`color-scheme` 和 `comment-bg` 有夜间取值。夜间模式改为读取 `--glowthread-night-<name>`，并以 GlowThread 的夜间默认值作为后备值，适用于 `html.skin-theme-clientpref-night` 下、系统为深色时的 `html.skin-theme-clientpref-os` 下，以及 `<html>` 或 `<body>` 上的切换类 `ext-glowthread-night` 下（由皮肤、小工具或站点 JavaScript 添加）。祖先元素上的 `ext-glowthread-day` 强制使用浅色模式，皮肤的夜间类存在时也是如此。只设置浅色取值的站点主题不会改变夜间模式。
- 有些默认值跟随同一元素上的其他令牌：浅色模式下 `color-accent-badge` 等于 `color-accent-subtle`，`color-focus-ring` 等于 `color-accent`，`font-family` 包含 `font-cjk`，`font-family-text` 等于 `font-family`，`reply-indent` 等于 `avatar-size` 加 `avatar-gap`。
- `font-cjk` 按界面语言有不同的默认值（`:root:lang()`，即 `<html>` 的语言：日文、韩文、台湾和香港的繁体中文）。站点设置的值作用于所有语言。
- 低于 640 px 时，字号等于基础字号加一级增量（`font-size-body` 的 0.875rem 变为 0.9375rem），除非站点设置了 `-phone` 令牌。头像小 0.25rem（低于 40rem，即 `gt:sm:` 开始的宽度），轻提示高出 48 px。
- 头像颜色（`avatar-bg`、`avatar-fg`）在头像元素本身上解析，因为由名称得出的色相（`--gt-avatar-hue`）在那里。
- 应用根元素和浮层容器带有类 `ext-glowthread-theme` 和 `data-glowthread-app`（`comments` 或 `hub`，由 `mountApp` 设置）。`.ext-glowthread-theme[data-glowthread-app="hub"]` 为管理中心及其菜单和面板设置主题，而 `#glowthread` 会漏掉浮层。

| 分组 | 令牌（`--glowthread-…`） |
|---|---|
| 颜色 | `color-fg`、`-fg-muted`、`-fg-subtle`、`-bg`、`-surface`、`-surface-subtle`、`-surface-strong`、`-border`、`-border-control`、`-accent`、`-accent-text`、`-accent-bg`、`-accent-bg-hover`、`-on-accent`、`-accent-subtle`、`-accent-badge`、`-highlight`、`-danger`、`-warning`、`-success`（各带 `-subtle`）、`-overlay-border`、`-scrim`、`-focus-ring`、`color-scheme`、`comment-bg` |
| 文字 | `font-family`、`font-family-text`、`font-family-mono`、`font-cjk`、`font-size-small`、`-meta`、`-body`、`-title`（各带 `-phone`）、`-code`、`line-height-meta`、`-body`、`-title`、`font-weight-medium`、`-strong` |
| 密度 | `space`（间距单位）、`comment-padding-y`、`avatar-gap`、`reply-indent` |
| 形状和线条 | `radius-surface`、`-control`、`-mark`、`-pill`、`border-width`、`indicator-width`、`focus-ring-width`、`focus-ring-offset`、`shadow-overlay` |
| 头像 | `avatar-size`、`-size-small`、`-radius`、`-font-weight`、`-bg`、`-fg`、`avatar-{bg,fg}-{saturation,lightness}` |
| 图标 | `icon-size`、`icon-size-small`、`icon-stroke` |
| 动效 | `duration-fast`、`-base`、`-exit`、`ease-out`、`ease-in` |
| 布局 | `text-max-width`、`sheet-width`、`toast-max-width`、`toast-offset` |
| 层级 | `z-sheet`、`z-popover`、`z-toast` |

每个令牌的默认值和用途见 [docs/zh-CN/theming.md](../docs/zh-CN/theming.md)，其中的表格由 `pnpm tokens docs` 根据注册表写入（也写入 `docs/theming.md`）。`src/design/example-themes.css` 中有供站点 CSS 使用的示例主题（品牌色、衬线阅读、紧凑密度、方形头像、仅深色的站点、只针对一个应用、置顶评论）。它不属于代码包。

添加或修改令牌：编辑 `tokens.json`，运行 `pnpm tokens css` 和 `pnpm tokens docs`，并以 `var(--gt-v-<name>)` 的形式读取它。以下情况下 `tests/tokens.test.ts` 会失败：tokens.css 或文档表格与注册表不一致，源代码读取了注册表中没有的令牌，注册表中有任何地方都没有读取的令牌，某对默认颜色在浅色或夜间模式下达不到 WCAG AA，或者名称中含有 “left” 或 “right”（CSSJanus 会改写它）。代码包声明了公开令牌，或读取公开令牌时没有后备值，`pnpm check` 就会失败。

在应用内部，这些令牌通过 `src/design/index.css` 中的 `@theme inline` 变成 Tailwind 工具类：`gt:text-fg`、`gt:bg-surface-subtle`、`gt:border-border-control`、`gt:text-accent-text`、`gt:rounded-control`、`gt:rounded-mark`、`gt:rounded-pill`、`gt:rounded-avatar`、`gt:text-meta`、`gt:text-small`、`gt:font-medium`、`gt:font-semibold`（即 strong 字重）、`gt:font-text`、`gt:shadow-overlay`、`gt:size-icon`（还有 `icon-sm`，以及随 `icon-size` 缩放的 `icon-xs`、`icon-lg`、`icon-xl`、`icon-2xl`）、`gt:size-avatar`、`gt:z-sheet`、`gt:z-popover`、`gt:z-toast`。`gt:border` 的宽度为 `border-width`。间距工具类是 `--glowthread-space` 的倍数（默认 0.25rem，所以 `gt:p-3` 是 12 px）。触控目标写作 `gt:min-h-[2.75rem]`（44 px），不在网格上，所以更密的间距单位也会让它们保持 44 px。没有对应工具类的令牌以 `gt:max-w-(--gt-v-text-max-width)` 的形式读取。唯一的断点是 `gt:sm:`（640 px 及以上）。不带它的样式用于手机。

辅助类（纯 CSS，位于 `src/design/base.css` 和 `motion.css`）：`gt-hit`（在触摸屏上提供 44 px 的触控目标，不改变布局）、`gt-flip-rtl`（在从右到左的界面中镜像图标）、`gt-tip` 加 `data-tip`（悬停或键盘聚焦时在元素上方显示文字提示）、`gt-num`（等宽数字）、`gt-scroll`（跟随主题的细滚动条）、`gt-sr-only`、`gt-fold`（6 行折叠）、`gt-sticky-bar`（固定在底部的栏：在 Citizen 手机版上会避开浮动的页面操作按钮）、`gt-avatar`、`gt-comment-row`（不含回复的评论本身，底色为 `comment-bg`），以及动效类 `gt-anim-pop`、`gt-anim-fade`、`gt-anim-sheet`、`gt-anim-enter`、`gt-anim-like`、`gt-highlight`、`gt-pulse`、`gt-spin`。开启“减少动态效果”时，所有动画都会关闭。

层级（`z-sheet`、`z-popover`、`z-toast`）：面板为 450，菜单、弹出框、工具提示和 @提及列表为 470（也在面板之上），轻提示为 480。皮肤的页头位于 200，其下拉菜单位于 350。不属于 Reka 层、但属于某个打开的面板的浮动部分（@提及列表、轻提示）带有 `data-gt-floating`，所以触碰它们不会关闭面板。

键盘：菜单和列表在获得焦点的项目内侧绘制焦点环（`color-focus-ring`、`focus-ring-width`）。F8 把焦点移到最新的轻提示上（以便及时使用“撤销”）。

## 部件钩子

站点 CSS 通过稳定的类名 `ext-glowthread-<part>` 和状态属性 `data-glowthread-*` 选择应用的部件。`src/design/parts.ts` 是注册表：其中每个部件都带有它渲染的位置（评论区、浮层容器、管理中心或两个应用）、它是否是容器，以及英文和简体中文说明。`pnpm tokens docs` 根据它写入 `docs/theming.md` 和 `docs/zh-CN/theming.md` 中的部件表格。应用不导入这个文件。

- 模板把类写成紧挨着工具类的普通字符串（`class="ext-glowthread-meta gt-num gt:ml-auto ..."`），或写在共用组件中（`Avatar`、`Badge`、`buttonVariants`、`DropdownMenuContent`、`SheetContent` 等），所以钩子在渲染时没有任何开销。以下情况下 `tests/parts.test.ts` 会失败：源代码、`example-themes.css` 或英文版 README 使用了注册表中没有的钩子，某个部件在任何地方都没有用到，容器与 `CONTAINER_PARTS` 不一致，或文档表格不一致。`tests/parts-render.test.ts` 渲染一条评论并检查它的钩子。
- 容器部件（评论、发表框、菜单、面板、轻提示、队列项目，以及注册表中标出的其他部件）会重新解析所有令牌，所以在其中一个上设置的令牌作用于它的内部。其他部件只接受普通 CSS 属性。
- 状态：评论带有 `data-glowthread-depth`（0 到 2，按显示的层级）、`data-glowthread-status`（`visible`、`held`、`deleted`、`erased`）、`data-glowthread-pinned` 和 `data-glowthread-own`。队列项目也带有状态，徽章和提示带有 `data-glowthread-tone`，按钮带有 `data-glowthread-variant`。内部的 `data-comment` 和 `data-pinned` 保持不变：应用和端到端测试会用到它们。
- 名称说明部件是什么，而不说明它在哪里：不含 “left” 或 “right”（CSSJanus 会在从右到左的界面中翻转它们）。其他 `ext-glowthread-` 名称不是部件：应用根元素上的 `ext-glowthread-theme`、切换类 `-night` 和 `-day`、加载器的 `-reserve` 和 `-size-N`、提示 `-nojs` 和 `-admin-nojs`，以及管理中心的容器 ID `ext-glowthread-admin`。
- 添加部件：把它加入 `parts.ts`（如果它应该接受令牌，还要加入 `scripts/tokens.mjs` 中的 `CONTAINER_PARTS`，然后运行 `pnpm tokens css`），在模板中完整写出这个类（构建会在每个应用的 JavaScript 中查找它，发现部件的类由片段拼接而成时会停止），再运行 `pnpm tokens docs`。

普通属性需要权重。应用的每条规则都带有两个 ID 和一个或多个类的权重（`.gt-scope.gt-scope:not(#_):not(#_) .gt\:text-fg`，见[CSS 如何限制在应用内部](#css-如何限制在应用内部)），所以带一个或两个 ID 的站点规则会有意地输给它。带三个 ID 的规则不需要 `!important` 就能胜过应用的每条规则。在选择器中重复同一个 ID 是合法的 CSS，每重复一次就增加一次权重。请使用部件所在的容器：浮层，以及在面板中打开时的管理中心记录，都渲染在 `#glowthread-portal` 中。

```css
/* MediaWiki:Common.css */
/* The comment section */
#glowthread#glowthread#glowthread .ext-glowthread-name { font-weight: 600; }
/* Menus, popovers, sheets, toasts and the @mention list (the overlay container at the end of <body>) */
#glowthread-portal#glowthread-portal#glowthread-portal .ext-glowthread-menu { border-radius: 0; }
/* The moderation hub */
#ext-glowthread-admin#ext-glowthread-admin#ext-glowthread-admin .ext-glowthread-queue-item { padding-top: 1rem; padding-bottom: 1rem; }
```

这样的规则还会压过应用在该属性上的悬停、焦点、选中和打开状态，所以请在需要的地方给它加上自己的 `:hover`，而且它在浅色和夜间模式下都生效。内联样式仍然胜出（Floating UI 为浮层设置的位置），应用中少数几条 `!important` 规则也是如此（标题字体、管理中心的段落外边距、`[hidden]`、减少动态效果）：字体请设置 `--glowthread-font-family`。有令牌时请优先使用令牌，颜色尤其如此：令牌会到达所有使用它的部件，并且有自己的夜间取值。

## 组件（`src/ui/`）

从 shadcn-vue 手工移植到 Reka UI 上（shadcn-vue 的 CLI 会破坏带前缀的类），并用设计令牌重新设置样式。从文件夹导入：`import { Button } from '@/ui/button'`。

| 组件 | 基于 | 说明 |
|---|---|---|
| `Button` | `cva`（只有 `asChild` 时使用 Reka `Primitive`） | `variant`：primary（一个视图中唯一的主要操作）、secondary、ghost、subtle、danger、link。`size`：sm、md、icon、icon-sm、inline。链接用 `as="a"` |
| `DropdownMenu`、`…Trigger`、`…Content`、`…Item`（`variant="danger"`）、`…Separator`、`…Label`、`…Group` | Reka DropdownMenu | 内容渲染到浮层容器中，默认与末端对齐 |
| `Popover`、`…Trigger`、`…Anchor`、`…Content`、`…Close` | Reka Popover | `PopoverAnchor :reference` 可以锚定到任意元素（举报表单用到了它） |
| `Sheet`、`SheetContent`（`title`、`description`、`closeLabel`） | Reka Dialog | 手机上为底部面板，从 640 px 起为侧边面板 |
| `Tabs`、`TabsList`、`TabsTrigger`、`TabsContent` | Reka Tabs | 选中的标签页：2 px 强调色下划线，标签文字为中性色 |
| `Textarea` | 原生 | 从 `rows` 行增高到 `maxRows` 行 |
| `Avatar`（`name`、`generic`、`size`） | | 在由名称决定色相的背景上显示首字母，或显示人像图标 |
| `Tooltip`（`content`）+ `TooltipProvider` | Reka Tooltip | 悬停和键盘聚焦时打开。触发元素必须可获得焦点。纯文本提示请优先使用纯 CSS 的 `gt-tip`（JavaScript 少约 2 kB），评论区显示确切时间时就是这样做的 |
| `Badge`（`tone`：neutral、accent、warning、danger、success） | | 低调的状态标签 |
| `Checkbox`、`RadioGroup` + `RadioGroupItem` | Reka | 选中时用强调色填充 |
| `Toaster` + `toast()` | 自行实现 | 实时区域在任何轻提示出现之前就已存在。错误以 assertive 方式播报。带操作（“撤销”）的轻提示保留 6 秒。悬停和聚焦时暂停 |
| `Skeleton`、`Spinner` | | Spinner 只用在控件内部，绝不用来代替内容 |

共用的领域部件（`src/shared/`）：`CommentBody`（把文本片段渲染为文字、链接和提及，绝不使用 `v-html`。链接文字总是显示真实的主机名，不带任何 `user@` 部分，过长的路径从中间省略）、`UserName`、`RelativeTime`、`types.ts`（API 数据结构）、`undo.ts`（如何撤销一个操作：使用服务器提供的、带日志 ID 的精确撤销，或者根据服务器返回的 `old` 状态决定的反向操作，绝不依据页面上的副本）。辅助代码（`src/lib/`）：`api.ts`（REST 请求，带 CSRF 令牌的 Action API 写入）、`errors.ts`（把错误码转为消息）、`i18n.ts`（用 `t('key')` 获取 `glowthread-ui-key`）、`time.ts`、`avatar.ts`、`segments.ts`、`wiki.ts`（指向维基内部的链接）、`media.ts`、`clipboard.ts`、`mount.ts`。

### 添加组件

1. 创建 `src/ui/<name>/<Name>.vue` 和 `index.ts`。封装 Reka 基础组件，`class` 只用于补充布局（没有 tailwind-merge，所以绝不要传入与组件自身工具类冲突的工具类），使用令牌工具类，并保留每种状态：悬停、焦点（全局焦点环）、禁用、打开和关闭（浮动内容使用 `gt-anim-pop`）。
2. 渲染到浮层容器中的内容需要 `gt:z-popover`（模态界面用 `gt:z-sheet`）以及 `gt:bg-surface gt:border gt:border-overlay-border gt:shadow-overlay`。
3. 手机上的可交互部分要加 `gt-hit`，或者本身就有 44 px。
4. 运行 `pnpm build`：`pnpm check` 会拒绝最旧的受支持浏览器会丢弃的选择器和颜色函数。

### 添加文字

UI 消息位于 `i18n/ui/{en,zh-hans,zh-hant,qqq}.json`，前缀为 `glowthread-ui-`。ResourceLoader 会把 `en.json` 的每个键随应用模块一起发送（无需修改 extension.json）。使用 `t('key', ...params)`，配合 MediaWiki 的 `$1` 和 `{{PLURAL:$1|…}}`。消息绝不以冒号或空格结尾、再由模板补上其余部分：其余部分要作为参数传入。如果模板需要为参数设置样式或添加链接，`tParts('key', n)` 会在参数处把消息拆开（`Held for review: $1`）。消息末尾需要的空格写作 `&#32;`。完整的句子用 `sentences()` 连接，它使用该语言的单词分隔符。如果源代码中用到的键缺失，或各文件之间不一致，`pnpm test` 就会失败。

## 添加应用

1. 创建 `src/<name>/main.ts`（复制 `src/admin/main.ts`：先是 polyfill，然后是样式表，最后是 `src/lib/mount.ts` 中的 `mountApp`）。
2. 创建 `src/<name>/styles.css`：`@import "../design/index.css";`，带 `source(none)` 的工具类导入，以及针对 `../ui`、`../shared` 和 `./` 的 `@source` 行。
3. `pnpm build` 会找到每个 `src/<name>/main.ts`，并写入 `resources/dist/<name>.js`、`.css` 和 `.licenses.txt`（评论区是例外，因为它的模块名，输出为 `app.js`）。`scripts/` 和 `vite.config.ts` 中都不需要修改。在 `scripts/check-dist.mjs` 中为它添加大小预算。应用中的 `import()` 会生成一个分块 `<name>-<chunk>.js`，它作为模块 `ext.glowThread.<name>.<chunk>` 提供（`scripts/vite-plugin-mw-chunks.mjs`）：把它添加到 extension.json，并把它所需的模块列为依赖，再为它添加大小预算。

## 评论区（`src/comments/`）

- 服务器在首次绘制前为评论区预留空间：`PageDisplayHooks` 添加样式模块 `ext.glowThread.reserve`（`resources/init/reserve.css`，约 0.2 kB）和 body 类 `ext-glowthread-reserve ext-glowthread-size-N`，其中 N 随页面的楼层计数器分档（0、1、2、3、5、8、13、21），已关闭的页面则为 `off`（即它那一行提示的高度）。CSS 给 `#bodyContent::after` 设置该档位实测第一页评论区高度的中位数（分手机和桌面），仅在有 `.client-js` 时生效。
- `resources/init/index.js`（手写的 ES2017，不经过构建）在 `#bodyContent` 之后创建 `#glowthread.ext-glowthread`（旧版 Vector），在 Minerva、Vector 2022 和其他没有位置容纳它的网格布局中，则创建为 `#bodyContent` 内的最后一个元素（Citizen 的网格行依次是“content”和“footer”），除非站点 CSS 给 `#glowthread` 指定了网格区域。在 `#bodyContent` 内部，它取代服务器在那里预留的空间，所以短文章旁边加载后才变长的一栏（Vector 2022 固定显示的工具菜单）不会推动它。它会忽略页面文本内带有皮肤 ID 的元素（解析器输出、`h1` 中的 DISPLAYTITLE、指示器），只在自己本来会放置 `#glowthread` 的位置才沿用已有的 `#glowthread`：页面文本无法劫持或隐藏评论区。浮层容器 `#glowthread-portal` 只有作为 `<body>` 的子元素时才会被复用，评论区标题也只在应用内部查找。它用一个与预留空间等高的静态骨架屏填充容器，并在同一帧内移除预留类，所以页面不会移动。遇到 `#gt-<id>`、`#glowthread` 和 `#flowthread`（维基从 FlowThread 迁移之前生成的链接）时，它立即加载 `ext.glowThread.app`。评论区在屏幕上时，它在页面的 `load` 事件之后加载。其他情况下，只有在读者滚动（包括加载器运行之前的滚动）、评论区进入视口下方一屏范围内之后才加载。在手机上，MobileFrontend 会折叠文章，所以在没人看评论的浏览中，评论区常常就在第一屏下方。如果模块加载失败，它会移除骨架屏。加载模块的同时，它用与应用自己的请求相同的请求（相同的 URL、请求头和凭据，因此 Varnish 返回同一份缓存）请求列表的第一页（`listUrls`），并把这个请求交给 `mount()`：应用的第一次 `restGet` 对这个 URL 直接使用它（`src/lib/api.ts` 中的 `useEarlyResponse`），失败时与以前一样显示错误状态和“重试”。对已登录读者，它还会单独发出一个请求加载 `ext.glowThread.app.extras`。
- 应用由三个模块组成（`lazy.ts`）：`ext.glowThread.app`、`ext.glowThread.app.menu`（`menu.ts`，⋯ 菜单的菜单项，在 ⋯ 按钮被悬停、聚焦或点按，或者评论区内发生触摸时加载）和 `ext.glowThread.app.extras`（`extras.ts`，评论输入框、举报表单和管理工具，为已登录或可以发表评论的读者加载，他们的列表会等待它，列表到达后最多 5 秒）。只能通过 `lazy.ts` 使用它们的组件：第一个模块导入它们的任何文件时，`tests/split.test.ts` 会失败。
- 在列表绘制完成之前，应用保持与预留空间完全相同的高度（`data-gt-reserve`）。永久链接（`#gt-<id>`、`#glowthread`、`#flowthread`）只在应用根元素内查找。
- 每条评论都会绘制的图标（⋯、心形、回复、回复区的箭头、人像头像）是直接写在模板里的 Lucide 标记，比每个图标一个组件更省：与 Lucide 渲染的结果不一致时，`tests/icons.test.ts` 会失败，并显示应当粘贴的标记。
- `store.ts` 保存状态和操作，传输层通过注入提供（有单元测试）：规范的列表 URL、游标分页、乐观且幂等的点赞（以最后一次点击为准）、发表和回复、删除自己的评论、可撤销的管理操作、页面状态。
- `App.vue` 组合以下部分：头部（评论数、“热门 · 最新”）、管理工具、评论输入框或无法发表的原因、列表、链接打开的对话（`LinkedThread.vue`）、举报表单和轻提示容器。
- 永久链接 `#gt-<id>` 和热门评论区块会滚动到该评论并高亮显示。评论不在列表中时，它所在的对话就地显示：对话在列表中时在列表中展开（`showComment` 通过 `/comment/{id}/thread` 补全），否则显示在列表上方的“对话”框中，读者可以关闭它。其他页面的评论在那里只被提及，并附有指向它的链接。
- 对话从不在面板中打开。在评论下方，“再显示 N 条回复”就地加载接下来的回复（`/comment/{id}/replies`，每次 50 条，按时间从旧到新），两级回复都是如此，直到全部显示，“收起回复”则把读者展开的对话重新折叠。手机上回复折叠在“展开 N 条回复”后面。读者自己新发的回复保持可见。“复制链接”给出 `Special:GlowThreadLink/<id>`，它会找到评论所在的页面，所以页面移动后链接仍然有效。

## 管理中心（`src/admin/`）

`ext.glowThread.admin` 只由 Special:评论管理 加载（读者永远不会加载它），连同自带的 Vue 在 gzip 后约 88 kB（预算 120 kB）。`main.ts` 把应用挂载到 `#ext-glowthread-admin`，替换服务器输出的无 JavaScript 提示。

- 队列排序（`sort`：“举报最多”、“最新”、“最早”）和举报人筛选（`reporter`，从频繁举报人徽章和用户记录进入）是地址的一部分。每条评论都附带作者的注册信息和封禁情况。因敏感词进入待审核的评论会显示记录下来的命中行（`mod.holdMatches`），没有记录时退而使用敏感词测试。
- 布局取决于应用自身的宽度（使用 `ResizeObserver`，因为容器查询需要 Chrome 105）：从 1080 px 起，侧边菜单（“队列”、“设置”、日志和敏感词链接）、队列和记录面板并排显示。从 720 px 起，侧边菜单变为上方的一行（“队列”和“设置”显示为分段切换按钮，以免看起来像队列的分类标签），记录面板仍在队列旁边。更窄时，记录在面板中打开（手机上为底部面板）。
- 每个队列项目依次是：作者、账号时长（新账号用粗体，不用颜色）、页面、不拆开的 `#seq · time`、状态标签（当前分类已经表明的那个除外）、评论文字、原因（只有图标带颜色，“本列表举报 N 条”徽章是唯一的琥珀色标记），然后是单独一行、左对齐的操作按钮：先是“通过”和“删除”或“恢复”，接着是偶尔使用的操作，最后是“查看上下文”（手机上为图标）。只有使用精确指针设备时，获得焦点的项目才会带底色。管理中心的链接使用 `gt-link`（正文颜色，跟随主题的下划线）：强调色只留给焦点、选中状态和标签页下划线。
- `router.ts`：子页面 `queue?tab=&page=&user=&q=`、`user/<name>`、`page/<title>` 和 `settings`，位于特殊页面自身的本地化标题之下（例如中文维基上的 `特殊:评论管理`）。导航使用 `history.pushState`，每个历史条目都带有自己的路由以支持后退和前进，`useskin` 和 `uselang` 会保留，文档标题随视图变化。
- `queue.ts`：队列的 store，传输层通过注入提供。游标分页，每次请求 50 项，当 j 越过末尾或剩余不足 9 项时加载更多，可见的后备方式是“加载更多”。操作是乐观的：项目离开当前分类（在“全部”中则原地更新），计数随之变化，焦点移到下一项，失败时项目回到原来的位置（会记住服务器的排序）。`moderation.ts` 保存纯规则：哪个操作适用于哪条评论（“通过”会公开待审核的评论，并关闭公开评论的举报）、操作的作用、评论属于哪个分类、如何撤销、举报分组，以及频繁举报人标记。
- `keyboard.ts`：j、k、x、a、d、r、/、?、Esc。以下情况下快捷键不起作用：在输入框中打字时、输入法组字过程中、按住修饰键时、操作键的按键重复、在对话框或菜单内，或焦点位于管理中心之外时。a、d 和 r 作用于持有键盘焦点的项目（按 Tab 或“查看上下文”之后），如果该项目在所选内容中，或焦点不在任何项目上，则作用于所选内容（`actionTargets`）。
- 性能：列表项目经过记忆化（`v-memo`），并从 DOM 获取自己在列表中的位置，所以一个操作只会重新渲染它改动的项目，即使已加载数百项也是如此。
- `history.ts`：作者或页面的记录，跟随获得焦点的项目（150 ms 防抖，缓存一分钟）。`settings.ts`：设置草稿、与当前设置的差异、附带理由保存，以及敏感词（用编辑 API 编辑，用 `glowthreadfiltertest` 检查和测试）。`wikiApi.ts`：核心 Action API 读取（管理员的权限和封禁、作者信息、标题和用户名建议、敏感词页面、`paraminfo`）。`errors.ts`：管理中心自己的错误码，优先于共用的错误码。
- 撤销：服务器提供 `do=undo` 时为精确撤销（见 `docs/api.md`，通过 `paraminfo` 检测），否则在反向操作只起撤销作用的情况下使用反向操作。
- 测试：`tests/admin-*.test.ts`（路由、键盘、规则、store、设置、错误），`tests/e2e/specs/admin.spec.ts` 和 `admin-skins.spec.ts`。开发用维基的种子数据（`dev/seed/`）会在队列中放入待审核、被举报和已删除的评论。
