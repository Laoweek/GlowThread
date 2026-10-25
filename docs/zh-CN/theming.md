[English](../theming.md) | 简体中文

# GlowThread 主题定制

本页面向希望让 GlowThread 与自己维基风格一致的站点管理员。站点 CSS 通过公开的 CSS 自定义属性（即令牌）设置颜色、文字、密度、形状、头像、图标、动效和布局，浅色和夜间模式都可以设置。令牌覆盖不到的地方，可以选择稳定的部件钩子。所有规则都不需要 `!important`，本页的所有内容都可以写在 `MediaWiki:Common.css` 中。

目录：[快速上手](#快速上手)、[在页面中的结构](#在页面中的结构)、[CSS 的位置](#css-的位置和生效方式)、[作用范围](#作用范围)、[令牌参考](#令牌参考)、[浅色、夜间、仅深色和仅浅色](#浅色夜间仅深色和仅浅色)、[部件钩子](#部件钩子)、[示例主题](#示例主题)、[无障碍](#无障碍)、[放置评论区](#放置评论区)、[不要覆盖的内容](#不要覆盖的内容)、[皮肤说明](#皮肤说明)。

## 快速上手

把下面的代码粘贴到 `MediaWiki:Common.css` 中，就能在浅色和夜间模式下给评论区、管理中心以及它们的菜单、面板和轻提示换上蓝色强调色：

```css
/* MediaWiki:Common.css */
:root {
    /* Light mode */
    --glowthread-color-accent: #3366cc;          /* marks: liked heart, selected tab, focus ring */
    --glowthread-color-accent-text: #2a4b8d;     /* links, mentions and text actions */
    --glowthread-color-accent-bg: #2a4b8d;       /* the primary button */
    --glowthread-color-accent-bg-hover: #233f78;
    --glowthread-color-accent-subtle: #eaf0fb;   /* selection, matched lines in the word filter */
    --glowthread-color-highlight: #f3f6fd;       /* a comment opened by a link */
    /* Night mode */
    --glowthread-night-color-accent: #88a3e8;
    --glowthread-night-color-accent-text: #88a3e8;
    --glowthread-night-color-accent-bg: #6d8fe0;
    --glowthread-night-color-accent-bg-hover: #88a3e8;
    --glowthread-night-color-accent-subtle: rgba(136, 163, 232, 0.2);
    --glowthread-night-color-accent-badge: rgba(136, 163, 232, 0.16);
    --glowthread-night-color-highlight: rgba(136, 163, 232, 0.12);
}
```

浅色取值是 `--glowthread-<name>`，夜间取值是 `--glowthread-night-<name>`。没有设置的令牌保持 GlowThread 的默认值。换成你自己的颜色时，请检查它们的对比度（见[无障碍](#无障碍)）。

## 在页面中的结构

| 部分 | 元素 | 说明 |
|---|---|---|
| 评论区容器 | `<div id="glowthread" class="ext-glowthread">` | 由加载器在 `#bodyContent` 之后创建，在 Minerva、Vector 2022 和 Citizen 上则放在 `#bodyContent` 内部的最后（见[放置评论区](#放置评论区)）。由你用站点 CSS 放置 |
| 应用根元素 | 容器内的 `<div class="gt-scope gt-root ext-glowthread-theme" data-glowthread-app="comments">` | 应用挂载的位置。`gt-scope` 和 `gt-root` 是内部名称。`ext-glowthread-theme` 和 `data-glowthread-app` 是用于令牌的公开钩子（见[作用范围](#作用范围)） |
| 浮层容器 | `<body>` 末尾的 `<div id="glowthread-portal" class="gt-scope gt-portal ext-glowthread-theme" data-glowthread-app="comments">` | 菜单、弹出框、面板、对话框、轻提示和 @提及列表。在 Special:评论管理 上，它带有 `data-glowthread-app="hub"` |
| 无 JavaScript 提示 | 页面内容末尾的 `<noscript><p class="ext-glowthread-nojs">` | JavaScript 关闭时显示。关闭了评论的页面不会添加 |
| 管理中心 | Special:评论管理 上的 `<div id="ext-glowthread-admin" class="ext-glowthread-admin">`，其中是应用根元素 `<div class="gt-scope gt-root ext-glowthread-theme" data-glowthread-app="hub">` | 与评论区使用相同的设计系统和令牌 |

以 `#flowthread` 结尾的旧链接仍然会打开评论。容器本身是 `#glowthread`。如果你的维基用过 FlowThread，请把站点 CSS 中的 `#flowthread` 改为 `#glowthread`（见[从 FlowThread 迁移](migrating-from-flowthread.md)）。

两个应用的每条规则都限定在 `.gt-scope` 范围内。它先做一次重置（`all: revert`），再用比任何只带一个 ID 的皮肤或站点规则更强的选择器建立自己的样式。因此 `#content a { color: red }` 或 `.mw-body h2 { border: 0 }` 影响不到评论，GlowThread 也不会给页面的其他部分设置任何样式。除了在文章下方预留的空间（见[预留空间](#预留空间)）和内部 `--tw-*` 变量的 `@property` 注册，GlowThread 不附带任何全局规则，也不声明任何公开的自定义属性。它只读取你的 CSS 设置的令牌。

## CSS 的位置和生效方式

站点 CSS 可以在以下任一位置设置令牌：

- `MediaWiki:Common.css`，作用于所有皮肤。
- 皮肤的 CSS 页面，例如 `MediaWiki:Vector.css`、`MediaWiki:Vector-2022.css` 或 `MediaWiki:Citizen.css`，只作用于该皮肤。
- 皮肤自己的 ResourceLoader 模块中的样式表，它在 GlowThread 的样式表之前加载。
- 小工具，例如读者在参数设置中开启的主题。

GlowThread 从不声明 `--glowthread-*` 属性。它的样式表只读取这些属性，每个都以 GlowThread 的默认值作为后备值（`var(--glowthread-color-accent, #dc6e7e)`），再在自己的元素上把它解析为一个内部值。因此，无论选择器是什么、规则写在哪里、样式表按什么顺序加载，你的 CSS 声明的值总会胜出，不需要 `!important`。这一点已在浅色和夜间模式的 Vector 2022、浅色模式的 Citizen 和夜间模式的 Minerva 上实测过，测试分别使用了在 GlowThread 之后加载的 CSS（站点样式）和在它之前加载的 CSS（皮肤的模块）。

令牌像所有自定义属性一样继承，所以离元素最近的声明胜出：`.ext-glowthread-theme` 或部件上的值胜过 `:root` 上的值。普通 CSS 属性则不同：GlowThread 自己的规则带两个 ID 的权重，站点规则需要三个 ID 才能覆盖它们（见[普通 CSS 属性](#普通-css-属性)）。

## 作用范围

选择器决定了令牌能到达 GlowThread 的哪些部分：

| 选择器 | 到达 | 说明 |
|---|---|---|
| `:root`、`html` 或 `body` | 全部：评论区、管理中心及其浮层（菜单、弹出框、举报表单、手机上的面板、对话框、轻提示、@提及列表） | 通常的选择 |
| `.ext-glowthread-theme` | 同上：两个应用及其浮层 | 这个类位于评论区的应用根元素、管理中心的应用根元素和 `#glowthread-portal` 上。在这里设置的值胜过 `:root` 上的值 |
| `.ext-glowthread-theme[data-glowthread-app="comments"]` 或 `[data-glowthread-app="hub"]` | 一个应用及其浮层 | 每个页面只运行一个应用，浮层容器使用它的名称 |
| `#glowthread` | 只有评论区，不包括它的浮层 | 菜单、面板和轻提示保持从上层继承的值 |
| `#ext-glowthread-admin` | 只有管理中心，不包括它的浮层 | 管理中心的记录面板和对话框也是如此 |
| 容器部件，例如 `.ext-glowthread-comment[data-glowthread-pinned]` | 该部件及其内部的一切 | 只限[部件表格](#部件和状态属性)中标为“可以”的部件 |

浮层不在评论区内部渲染。菜单、弹出框、面板、对话框、轻提示和 @提及列表渲染在 `<body>` 的子元素 `#glowthread-portal` 中，这样它们才能浮在皮肤的所有层级之上。在 `#glowthread` 或 `#ext-glowthread-admin` 上设置的令牌到达不了它们：深色的评论区会打开浅色的菜单。凡是也应作用于浮层的设置，请使用 `.ext-glowthread-theme` 或 `:root`。

## 令牌参考

下面的表格列出了每个令牌、它的默认值和用途。它们由 [`client/src/design/tokens.json`](../../client/src/design/tokens.json) 生成，这个文件是样式表和这些表格的唯一来源。

有夜间取值的令牌（颜色、`shadow-overlay`、头像颜色和色调）还接受 `--glowthread-night-<name>`（见[夜间取值](#夜间取值)）。在手机上（窄于 640 px），尺寸由你设置的基础值计算得出：三个文字字号大一级（`font-size-body` 的 0.875rem 变为 0.9375rem），除非你设置了对应的 `-phone` 令牌，头像小 0.25rem，轻提示高出 48 px。有些默认值跟随其他令牌，你在部件上设置那些令牌时也是如此：浅色模式下 `color-accent-badge` 等于 `color-accent-subtle`，`color-focus-ring` 等于 `color-accent`，`font-family` 包含 `font-cjk`，`font-family-text` 等于 `font-family`，`reply-indent` 等于 `avatar-size` 加 `avatar-gap`。`font-cjk` 按界面语言有不同的默认值。

<!-- glowthread-tokens:start -->
### 颜色

| 属性 | 浅色 | 夜间 | 用途 |
|---|---|---|---|
| `--glowthread-color-fg` | `#171717` | `#ededed` | 文字和作者名 |
| `--glowthread-color-fg-muted` | `#666666` | `#a1a1a1` | 时间、楼层号、计数、帮助说明、未选中的标签页 |
| `--glowthread-color-fg-subtle` | `#737373` | `#8f8f8f` | 占位文字、排序标签页之间的圆点 |
| `--glowthread-color-bg` | `transparent` | `transparent` | 评论区和管理中心的背景。请保持透明，让皮肤的页面透出来 |
| `--glowthread-comment-bg` | `transparent` | `transparent` | 评论行的底色。在评论部件上设置，可为某些行着色，例如置顶评论。回复是独立的行，会继承这个值，若只想给这条评论着色，请在回复上重设 |
| `--glowthread-color-surface` | `#ffffff` | `#1c1e22` | 输入框、菜单、弹出框、面板、轻提示、次要按钮 |
| `--glowthread-color-surface-subtle` | `rgba(0, 0, 0, 0.04)` | `rgba(255, 255, 255, 0.06)` | 悬停、骨架屏、提示框、中性徽标 |
| `--glowthread-color-surface-strong` | `rgba(0, 0, 0, 0.08)` | `rgba(255, 255, 255, 0.1)` | 按下和当前状态，上面总是 `fg` 文字 |
| `--glowthread-color-border` | `#e8e8e8` | `rgba(255, 255, 255, 0.1)` | 评论之间和标题栏中的细分隔线 |
| `--glowthread-color-border-control` | `#8a8a8a` | `rgba(255, 255, 255, 0.38)` | 输入框、按钮、复选框和单选框的轮廓，滚动条滑块 |
| `--glowthread-color-accent` | `#dc6e7e` | `#e88a98` | 仅用于标记：已点赞的爱心、选中标签页的下划线、获得焦点的输入框边框，未设置 `color-focus-ring` 时也用于焦点环 |
| `--glowthread-color-focus-ring` | 同 `color-accent` | 同 `color-accent` | 键盘焦点环，菜单和列表中获得焦点的条目也在内部画出它。需要 3:1 |
| `--glowthread-color-accent-text` | `#b83f55` | `#e88a98` | 作为文字的强调色：评论中的链接和提及、文字操作、已点赞的计数、文字光标 |
| `--glowthread-color-accent-bg` | `#b83f55` | `#e07a8a` | 主按钮、选中的复选框和单选框 |
| `--glowthread-color-accent-bg-hover` | `#a5384c` | `#e88a98` | 悬停时的主按钮 |
| `--glowthread-color-on-accent` | `#ffffff` | `#171717` | `accent-bg` 上的文字和对勾 |
| `--glowthread-color-accent-subtle` | `#fbe5e9` | `rgba(232, 138, 152, 0.22)` | 文字选区、敏感词规则中匹配的行号、管理中心的“新内容”标签 |
| `--glowthread-color-accent-badge` | 同 `color-accent-subtle` | `rgba(232, 138, 152, 0.16)` | “置顶”徽标的底色 |
| `--glowthread-color-highlight` | `#fdf0f2` | `rgba(232, 138, 152, 0.12)` | 通过链接打开的评论、管理中心中选中的条目 |
| `--glowthread-color-danger` | `#c42b2b` | `#ff8a80` | 删除、隐藏和抹除的文字、错误、举报旗标 |
| `--glowthread-color-danger-subtle` | `#fdecec` | `rgba(255, 138, 128, 0.12)` | 危险徽标和删除确认的底色 |
| `--glowthread-color-warning` | `#8a5300` | `#f0b860` | 待审核的评论、速率限制的等待提示 |
| `--glowthread-color-warning-subtle` | `#fdf3dc` | `rgba(240, 184, 96, 0.12)` | 警告徽标和说明的底色 |
| `--glowthread-color-success` | `#1d7a3e` | `#6fd58f` | 通过敏感词测试 |
| `--glowthread-color-success-subtle` | `#e6f4ea` | `rgba(111, 213, 143, 0.12)` | 成功徽标的底色 |
| `--glowthread-color-overlay-border` | `transparent` | `rgba(255, 255, 255, 0.12)` | 夜间模式下浮层的边缘 |
| `--glowthread-color-scrim` | `rgba(0, 0, 0, 0.32)` | `rgba(0, 0, 0, 0.56)` | 面板后面的变暗遮罩 |
| `--glowthread-color-scheme` | `light` | `dark` | 原生控件和滚动条的 `color-scheme` |

对比度要求：文字颜色（`fg`、`fg-muted`、`fg-subtle`、`accent-text`）在 `surface` 和你的页面背景上需要达到 4.5:1。`accent` 和 `border-control` 需要 3:1。状态颜色在各自的 `-subtle` 底色上需要 4.5:1。`on-accent` 在 `accent-bg` 上需要 4.5:1。请在浅色和夜间模式下都检查你的取值。

### 其他属性

#### 文字

| 属性 | 默认值 | 用途 |
|---|---|---|
| `--glowthread-font-family` | 拉丁文字使用系统界面字体，然后是 `font-cjk`，然后是彩色 emoji | 所有文字。GlowThread 不加载任何 Web 字体 |
| `--glowthread-font-family-text` | 同 `font-family` | 仅用于评论正文和举报说明，阅读字体不会影响控件 |
| `--glowthread-font-family-mono` | `ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", "Noto Sans Mono CJK SC", monospace` | 管理中心的敏感词编辑器 |
| `--glowthread-font-cjk` | 简体中文系统字体（PingFang SC、Microsoft YaHei、Noto Sans CJK SC 等），其他语言使用对应字体 | 字体栈中的 CJK 部分，让汉字按读者语言的字形显示。默认使用简体中文字体，界面语言为日语、韩语或繁体中文（台湾或香港）时使用日文、韩文或繁体中文字体。你设置的值适用于所有语言 |
| `--glowthread-font-size-small` | `0.75rem` | 徽标、小头像上的字符 |
| `--glowthread-font-size-meta` | `0.78125rem`，手机 `0.8125rem` | 时间、计数、小按钮 |
| `--glowthread-font-size-meta-phone` | `font-size-meta` + 0.03125rem（0.8125rem） | 手机上的 `font-size-meta` |
| `--glowthread-font-size-body` | `0.875rem`，手机 `0.9375rem` | 评论文字、输入框、菜单 |
| `--glowthread-font-size-body-phone` | `font-size-body` + 0.0625rem（0.9375rem） | 手机上的 `font-size-body` |
| `--glowthread-font-size-title` | `1rem`，手机 `1.0625rem` | 评论区标题、面板标题。管理中心的设置标题是它的 1.125 倍 |
| `--glowthread-font-size-title-phone` | `font-size-title` + 0.0625rem（1.0625rem） | 手机上的 `font-size-title` |
| `--glowthread-font-size-code` | `0.8125rem` | 管理中心的敏感词编辑器 |
| `--glowthread-line-height-meta` | `1.45` | `font-size-meta` 大小的文字 |
| `--glowthread-line-height-body` | `1.65` | 评论文字和其他 `font-size-body` 大小的文字 |
| `--glowthread-line-height-title` | `1.4` | `font-size-title` 大小的文字 |
| `--glowthread-font-weight-medium` | `500` | 作者名、按钮、选中的标签页 |
| `--glowthread-font-weight-strong` | `600` | 标题、粗体文字、管理中心中的新账户 |

#### 密度

| 属性 | 默认值 | 用途 |
|---|---|---|
| `--glowthread-space` | `0.25rem` | 间距单位：内边距、间隙和控件高度都是它的倍数。触屏上的触控区域保持 44 px |
| `--glowthread-comment-padding-y` | `1rem` | 列表中每条评论上下的间距 |
| `--glowthread-avatar-gap` | `0.75rem` | 评论和输入框中头像与文字之间的间距 |
| `--glowthread-reply-indent` | `calc(avatar-size + avatar-gap)` | 回复的缩进。默认值让串线对齐评论的文字 |

#### 形状和线条

| 属性 | 默认值 | 用途 |
|---|---|---|
| `--glowthread-radius-surface` | `8px` | 菜单、面板、轻提示、提示框、评论输入框的边框 |
| `--glowthread-radius-control` | `6px` | 按钮、输入框、菜单项 |
| `--glowthread-radius-mark` | `4px` | 徽标、复选框、键盘提示、输入框内的小按钮 |
| `--glowthread-radius-pill` | `9999px` | 筛选按钮和管理中心的标签 |
| `--glowthread-border-width` | `1px` | 细分隔线，以及输入框、按钮和复选框的轮廓 |
| `--glowthread-indicator-width` | `0.125rem` | 选中标签页的下划线 |
| `--glowthread-focus-ring-width` | `2px` | 键盘焦点环的宽度 |
| `--glowthread-focus-ring-offset` | `2px` | 控件与焦点环之间的间隙。菜单和列表在条目内部画出焦点环 |
| `--glowthread-shadow-overlay` | `0 4px 12px rgba(0, 0, 0, 0.08), 0 12px 32px rgba(0, 0, 0, 0.12)`，夜间 `0 0 #0000` | 浮层的悬浮效果。浅色模式使用阴影，夜间模式使用 `color-overlay-border` |

#### 头像

| 属性 | 默认值 | 用途 |
|---|---|---|
| `--glowthread-avatar-size` | `2.25rem`，手机 `2rem` | 评论、输入框和管理中心中的头像 |
| `--glowthread-avatar-size-small` | `1.5rem` | 回复、@提及列表和热门评论条中的头像 |
| `--glowthread-avatar-radius` | `9999px` | 头像及其加载占位的圆角。设为 `4px` 得到方形头像 |
| `--glowthread-avatar-font-weight` | `500` | 生成头像上字符的字重 |
| `--glowthread-avatar-bg` | 用户名的色相，加上 `avatar-bg-saturation` 和 `avatar-bg-lightness` | 生成头像的底色（单一颜色）。默认色相取自用户名，色调取自下面四个属性 |
| `--glowthread-avatar-fg` | 用户名的色相，加上 `avatar-fg-saturation` 和 `avatar-fg-lightness` | 生成头像上的字符颜色（单一颜色）。在 `avatar-bg` 上需要 4.5:1 |
| `--glowthread-avatar-bg-saturation` | `58%`，夜间 `26%` | 头像底色的饱和度 |
| `--glowthread-avatar-bg-lightness` | `89%`，夜间 `26%` | 头像底色的亮度 |
| `--glowthread-avatar-fg-saturation` | `52%`，夜间 `70%` | 头像字符的饱和度 |
| `--glowthread-avatar-fg-lightness` | `28%`，夜间 `84%` | 头像字符的亮度。使用默认色调时每个色相都达到 4.5:1 |

#### 图标

| 属性 | 默认值 | 用途 |
|---|---|---|
| `--glowthread-icon-size` | `1rem` | 按钮和菜单中的图标。更小和更大的图标（12、18、20 和 24 px）随它缩放 |
| `--glowthread-icon-size-small` | `0.875rem` | 小号文字旁的图标：箭头、旗标、管理中心的操作 |
| `--glowthread-icon-stroke` | `2px` | 图标的线宽（px），按 24 px 图稿计量，随图标缩放。对勾的线宽是它的 1.5 倍 |

#### 动效

| 属性 | 默认值 | 用途 |
|---|---|---|
| `--glowthread-duration-fast` | `150ms` | 菜单打开、悬停和颜色变化 |
| `--glowthread-duration-base` | `200ms` | 面板滑入、新评论、淡入 |
| `--glowthread-duration-exit` | `150ms` | 面板和淡出的关闭。菜单和弹出框用它的三分之二时间关闭 |
| `--glowthread-ease-out` | `cubic-bezier(0.16, 1, 0.3, 1)` | 所有进入动画 |
| `--glowthread-ease-in` | `ease-in` | 所有退出动画 |

#### 布局

| 属性 | 默认值 | 用途 |
|---|---|---|
| `--glowthread-text-max-width` | `46em` | 评论文字的最大行宽 |
| `--glowthread-sheet-width` | `440px` | 640 px 起侧边面板的宽度（不超过屏幕宽度） |
| `--glowthread-toast-max-width` | `420px` | 轻提示的最大宽度 |
| `--glowthread-toast-offset` | `24px`，手机 `72px` | 轻提示与屏幕底部的距离。手机上再加 48 px，以避开 Citizen 等皮肤的底栏 |

#### 层级

| 属性 | 默认值 | 用途 |
|---|---|---|
| `--glowthread-z-sheet` | `450` | 面板、其遮罩和对话框 |
| `--glowthread-z-popover` | `470` | 菜单、弹出框和@提及列表，也在面板之上 |
| `--glowthread-z-toast` | `480` | 轻提示 |
<!-- glowthread-tokens:end -->

### 字体

要使用其他字体，请设置整个字体栈：

```css
:root { --glowthread-font-family: "Inter", "Noto Sans", sans-serif; }
```

`--glowthread-font-family-text` 只改变评论正文和举报备注，所以阅读用的字体不会影响按钮（见[衬线阅读](#2-衬线阅读)）。

只想更改 CJK 字体时，设置 `--glowthread-font-cjk`。MediaWiki 把界面语言写在 `<html lang>` 上，GlowThread 据此选择日文、韩文或繁体中文字体。你设置的值作用于所有语言。在选择器中加上 `:lang()`，就能只为一种语言设置字体：

```css
:root { --glowthread-font-cjk: "Noto Sans CJK SC", "Microsoft YaHei"; }
:root:lang(ja) { --glowthread-font-cjk: "Noto Sans CJK JP", "Yu Gothic"; }
```

### 手机上的尺寸

你设置的文字字号也会改变手机上的字号，手机上仍然大一级。只有当手机上的字号应该另取别的值时，才需要设置 `-phone` 令牌：

```css
:root {
    --glowthread-font-size-body: 0.9375rem;
    --glowthread-font-size-body-phone: 1rem;
}
```

### 头像和书写方向

头像显示用户名的首个字符，底色的色相取自用户名。在默认色调下，每种色相都满足 4.5:1。如果你更改了这四个色调令牌，请在两种模式下检查几个用户名。`avatar-bg` 和 `avatar-fg` 用一种颜色代替由用户名决定的色相（见[方形头像](#4-方形头像)）。

令牌名称中不含左或右，所以一个令牌在两种书写方向下含义相同。对于从右到左的界面语言（阿拉伯语、希伯来语、波斯语等），MediaWiki 会在 `<html>` 上设置 `dir="rtl"`，ResourceLoader 会以镜像方式提供两个应用的样式表：左右互换。沿阅读方向指向的图标（V 形图标、箭头、回复转角图标、发送图标）也会镜像，菜单和方向键遵循页面方向，侧边面板从左侧打开。评论文字和用户名保持各自的方向（`dir="auto"`），而且评论正文只和它的文字一样宽，所以在从右到左的界面中，一条简短的英文评论仍然紧挨着它的头像。

## 浅色、夜间、仅深色和仅浅色

除非某个夜间开关处于开启状态，GlowThread 都以浅色模式绘制。这些开关都是类：

| 开关 | 由谁设置 | GlowThread 使用 |
|---|---|---|
| `<html>` 上的 `skin-theme-clientpref-night` | Vector 2022、Minerva 和 Citizen，在读者选择深色时 | 夜间取值 |
| `<html>` 上的 `skin-theme-clientpref-os` | 同样这些皮肤，在读者选择自动时 | 系统为深色（`prefers-color-scheme: dark`）时使用夜间取值，否则使用浅色取值 |
| `<html>` 或 `<body>` 上的 `ext-glowthread-night` | `$wgGlowThreadTheme = 'night'`、皮肤、小工具或站点 JavaScript | 夜间取值 |
| `<html>` 或 `<body>` 上的 `ext-glowthread-day` | `$wgGlowThreadTheme = 'day'`、皮肤、小工具或站点 JavaScript | 浅色取值，在以上任何开关下都是如此 |
| 以上都没有 | | 浅色取值 |

`ext-glowthread-night` 和 `ext-glowthread-day` 放在应用的任何祖先元素上都有效，但只有放在 `<html>` 或 `<body>` 上时才会同时作用于浮层。

当维基提供深色模式时，Vector 2022 和 Minerva 会设置这些主题类。Citizen 3 也会设置，它的纯黑选项同样如此，该选项保留 `skin-theme-clientpref-night`。旧版 Vector 没有夜间模式，所以除非设置了切换类，GlowThread 在那里保持浅色。

### 夜间取值

夜间模式读取 `--glowthread-night-<name>`，而不是 `--glowthread-<name>`，每个都以 GlowThread 的夜间默认值作为后备值。只设置浅色取值的主题会让夜间模式保持 GlowThread 自带的样子，夜间取值也可以用同样简单的选择器设置：

```css
:root {
    --glowthread-color-accent: #3366cc;
    --glowthread-night-color-accent: #88a3e8;
}
```

颜色表中“夜间”一栏有取值的令牌都有夜间取值，`shadow-overlay`、`avatar-bg`、`avatar-fg` 和四个头像色调令牌也有。尺寸、字体、动效和布局在两种模式下相同。夜间模式下，`color-focus-ring` 跟随夜间强调色，而 `color-accent-badge` 有自己的夜间默认值：请把 `--glowthread-night-color-accent-badge` 和 `--glowthread-night-color-accent-subtle` 一起设置。对于有夜间取值的令牌，写在夜间选择器（例如 `html.skin-theme-clientpref-night`）下的 `--glowthread-*` 值不会改变夜间模式。没有夜间取值的令牌（例如尺寸或圆角）写在夜间选择器下时，只在夜间模式下生效。

### `$wgGlowThreadTheme`

`LocalSettings.php` 中的 `$wgGlowThreadTheme` 为整个维基固定模式（见[配置](configuration.md#localsettings-变量)）。默认值 `'auto'` 跟随上面的开关。`'night'` 在 `<html>` 上添加 `ext-glowthread-night`，`'day'` 添加 `ext-glowthread-day`，作用于带有评论区的文章浏览页面和 Special:评论管理。其他值按 `'auto'` 处理。

评论区本身没有背景，所以它后面的页面必须是同一色调：只在页面始终为深色时使用 `'night'`，只在页面始终保持浅色（包括皮肤的夜间模式开启时）时使用 `'day'`。否则评论区的文字会落在与它色调相同的页面上。

### 仅深色的站点

始终为深色的站点有三种做法，按从简单到复杂排列：

1. 在 `LocalSettings.php` 中设置 `$wgGlowThreadTheme = 'night';`。
2. 用皮肤、小工具或脚本在 `<html>` 上添加 `ext-glowthread-night`（见[示例 5a](#5a-仅深色使用切换类)）。
3. 只用 CSS：把夜间配色作为浅色取值（见[示例 5b](#5b-仅深色只用-css)）。请优先使用前两种：在管理中心，选中项目的勾选标记是一张图片，只有在夜间开关下才会变深。

### 仅浅色的站点

`$wgGlowThreadTheme = 'day'`，或 `<html>` 上的 `ext-glowthread-day`，会在皮肤的夜间类开启时让 GlowThread 保持浅色。它适合内容区域在所有模式下都保持浅色的站点。

### 其他深色主题

使用 GlowThread 不认识的类的皮肤主题不会切换它：Citizen 4 预览版中的 `skin-theme-clientpref-black`、维基自定义的 Citizen 主题 `skin-theme-clientpref-<value>`，或小工具自己的深色类。请在该主题开启时给 `<html>` 添加 `ext-glowthread-night`，或者把[示例 5b](#5b-仅深色只用-css)的配色放在该主题的选择器下，例如用 `html.skin-theme-clientpref-black` 代替 `:root`。

## 部件钩子

站点 CSS 通过稳定的类名 `ext-glowthread-<part>` 选择 GlowThread 的部件，通过 `data-glowthread-*` 属性选择它们的状态。可以用它们在某个部件上设置令牌，或者设置令牌覆盖不到的普通 CSS 属性。表格来自 [`client/src/design/parts.ts`](../../client/src/design/parts.ts)。

### 部件和状态属性

<!-- glowthread-parts:start -->
每个部件是一种元素上的类。“自定义属性”一栏标为“可以”的部件会重新解析所有自定义属性：在它上面设置的 `--glowthread-*` 属性作用于它的内部。普通的 CSS 属性需要带三个 ID 的选择器才能胜过 GlowThread 自己的规则（它们带两个 ID）：评论区用 `#glowthread#glowthread#glowthread .ext-glowthread-name { font-weight: 600 }`，浮层把 `#glowthread-portal` 写三次，管理中心把 `#ext-glowthread-admin` 写三次。这样的规则在浅色和夜间模式下都生效，所以颜色请优先使用自定义属性。

#### 评论区，位于 `#glowthread` 内

| 类 | 自定义属性 | 是什么 |
|---|---|---|
| `ext-glowthread-section` |  | 评论区 |
| `ext-glowthread-header` |  | 标题和排序标签页所在的一行 |
| `ext-glowthread-heading` |  | 评论区标题（“评论”） |
| `ext-glowthread-count` |  | 标题中的评论数 |
| `ext-glowthread-sort` |  | 排序标签页（热门、最新） |
| `ext-glowthread-modtools` |  | 列表上方的管理工具：待审核和被举报筛选、页面状态、管理模式 |
| `ext-glowthread-composer` | 可以 | 发表框，位于页面上，回复时也出现在评论下方 |
| `ext-glowthread-composer-field` |  | 输入框的边框和底色 |
| `ext-glowthread-composer-toolbar` |  | 输入框下方的一行：表情、@、字数、取消和发送 |
| `ext-glowthread-send` |  | 发送按钮 |
| `ext-glowthread-gate` | 可以 | 读者不能发表时代替发表框的说明（未登录、被封禁、页面已锁定） |
| `ext-glowthread-popular` | 可以 | 热门评论，位于最新列表上方 |
| `ext-glowthread-linked` | 可以 | 列表上方的“对话”框，用于显示不在列表中的链接评论 |
| `ext-glowthread-list` |  | 评论列表：某种排序（置顶评论在前）或管理员筛选的结果 |
| `ext-glowthread-comment` | 可以 | 一条评论及其回复，回复是嵌套在其中的评论 |
| `ext-glowthread-comment-row` |  | 评论本身（不含回复），底色为 `comment-bg` |
| `ext-glowthread-meta` |  | 楼层号和时间 |
| `ext-glowthread-menu-trigger` |  | 评论的 ⋯ 按钮，以及管理工具中的页面状态按钮 |
| `ext-glowthread-actions` |  | 正文下方的一行：赞和回复 |
| `ext-glowthread-like` |  | 赞按钮及赞数 |
| `ext-glowthread-reply` |  | 回复按钮 |
| `ext-glowthread-replies` |  | 评论下方的回复区，及其旁边的竖线 |
| `ext-glowthread-replies-toggle` |  | “再显示 N 条回复”和“收起回复” |
| `ext-glowthread-mod-notes` | 可以 | 评论待审核或被举报的原因，以及管理按钮（仅管理员可见） |

#### 浮层，位于 `#glowthread-portal` 内

| 类 | 自定义属性 | 是什么 |
|---|---|---|
| `ext-glowthread-menu` | 可以 | 菜单：评论的 ⋯ 菜单、页面状态菜单 |
| `ext-glowthread-menu-item` |  | 菜单中的一项 |
| `ext-glowthread-popover` | 可以 | 弹出框：640 px 起的举报表单、表情选择器 |
| `ext-glowthread-emoji` | 可以 | 表情选择器（同时也是弹出框） |
| `ext-glowthread-mentions` | 可以 | @提及建议列表 |
| `ext-glowthread-report` | 可以 | 举报表单，位于弹出框中，手机上位于面板中 |
| `ext-glowthread-sheet` | 可以 | 面板：手机上从底部弹出，640 px 起从侧边弹出（手机上的举报表单、管理中心的记录和快捷键） |
| `ext-glowthread-dialog` |  | 管理中心确认抹除评论的对话框 |
| `ext-glowthread-toast` | 可以 | 轻提示 |

#### 管理中心，位于 `#ext-glowthread-admin` 内

| 类 | 自定义属性 | 是什么 |
|---|---|---|
| `ext-glowthread-hub` |  | 管理中心 |
| `ext-glowthread-hub-nav` | 可以 | 导航：队列、设置、日志、敏感词、快捷键 |
| `ext-glowthread-queue` |  | 队列视图：分类、筛选和条目 |
| `ext-glowthread-queue-tabs` |  | 队列分类标签页 |
| `ext-glowthread-queue-item` | 可以 | 队列中的一条评论 |
| `ext-glowthread-record` | 可以 | 作者或页面记录：位于队列旁边，管理中心窄于 720 px 时位于面板中（在浮层容器里） |
| `ext-glowthread-settings` | 可以 | 设置视图 |
| `ext-glowthread-filter-editor` | 可以 | 设置中的敏感词编辑器 |

#### 两个应用及其浮层

| 类 | 自定义属性 | 是什么 |
|---|---|---|
| `ext-glowthread-avatar` |  | 头像。在它上面设置的 `avatar-bg` 和 `avatar-fg` 对它生效 |
| `ext-glowthread-name` |  | 作者名 |
| `ext-glowthread-body` |  | 评论正文 |
| `ext-glowthread-badge` |  | 状态标签：置顶、待审核、被举报、已隐藏，以及管理中心里的封禁、用户组和频繁举报者 |
| `ext-glowthread-notice` |  | 页面中的提示：评论已关闭、管理中心的提示 |
| `ext-glowthread-button` |  | 按钮：回复、发送、加载更多、通过等 |
| `ext-glowthread-tab` |  | 排序或队列分类中的一个标签页 |
| `ext-glowthread-empty` |  | 还没有评论，或队列为空 |
| `ext-glowthread-loading` |  | 加载时的占位内容 |
| `ext-glowthread-error` |  | 加载失败：带“重试”的错误提示、“加载更多”上方的提示、回复下方的提示 |
| `ext-glowthread-load-more` |  | “加载更多”按钮 |
| `ext-glowthread-bulk-bar` | 可以 | 选中评论时的操作栏（评论区的管理模式、管理中心的多选） |

#### 状态属性

| 属性 | 所在部件 | 取值 | 含义 |
|---|---|---|---|
| `data-glowthread-depth` | `ext-glowthread-comment` | `0`、`1`、`2` | 显示的层级：列表顶层为 0，回复为 1 和 2 |
| `data-glowthread-status` | `ext-glowthread-comment`、`ext-glowthread-queue-item` | `visible`、`held`、`deleted`、`erased` | `held` 为待审核，`deleted` 为被管理员隐藏或被作者删除，`erased` 为正文和作者已被抹除。读者只能看到 `visible` 的评论和自己 `held` 的评论 |
| `data-glowthread-pinned` | `ext-glowthread-comment` | 有或没有 | 在置顶评论上（不在其回复上） |
| `data-glowthread-own` | `ext-glowthread-comment` | 有或没有 | 在读者自己的评论上 |
| `data-glowthread-tone` | `ext-glowthread-badge`、`ext-glowthread-notice` | `neutral`、`accent`、`warning`、`danger`、`success` | 标签或提示的颜色（提示只有 neutral、warning、danger） |
| `data-glowthread-variant` | `ext-glowthread-button` | `primary`、`secondary`、`ghost`、`subtle`、`danger`、`link` | `primary` 是一个视图中唯一的主要操作（发送、提交举报） |
<!-- glowthread-parts:end -->

### 在部件上设置令牌

标为“可以”的部件会重新解析所有令牌，所以在其中一个上设置的令牌作用于它的内部。下面是方形菜单，包括菜单框和其中的项目：

```css
.ext-glowthread-menu {
    --glowthread-radius-surface: 0;
    --glowthread-radius-control: 0;
}
```

在其他部件上设置的令牌不会改变该部件本身，`ext-glowthread-avatar` 上的 `avatar-bg` 和 `avatar-fg` 除外。但它仍会到达其内部标为“可以”的部件，所以 `ext-glowthread-list` 上的 `--glowthread-comment-bg` 会给列表中的每条评论加上底色，评论之间的间隙则不会。回复是嵌套在父评论内的评论，会继承父评论的值：[示例 7](#7-置顶评论加底色) 给置顶评论加上底色，并重置它们的回复。

### 普通 CSS 属性

GlowThread 自己的规则带有两个 ID 和一个或多个类的权重，所以带一个 ID 的站点规则，以及大多数带两个 ID 的站点规则，会有意地输给它们。带三个 ID 的规则不需要 `!important` 就能胜过它们中的每一条。在选择器中重复同一个 ID 是合法的 CSS，每重复一次就增加一次权重。请使用部件所在的容器：浮层，以及在面板中打开时的管理中心记录，都渲染在 `#glowthread-portal` 中。

```css
/* MediaWiki:Common.css */
/* The comment section */
#glowthread#glowthread#glowthread .ext-glowthread-name { font-weight: 600; }
/* Menus, popovers, sheets, toasts and the @mention list (the overlay container at the end of <body>) */
#glowthread-portal#glowthread-portal#glowthread-portal .ext-glowthread-menu { border-radius: 0; }
/* The moderation hub */
#ext-glowthread-admin#ext-glowthread-admin#ext-glowthread-admin .ext-glowthread-queue-item { padding-top: 1rem; padding-bottom: 1rem; }
```

这样的规则还会压过应用在该属性上的悬停、焦点、选中和打开状态，所以请在需要的地方给它加上自己的 `:hover`。它在浅色和夜间模式下都生效。内联样式仍然胜出（Floating UI 为浮层设置的位置），应用中少数几条 `!important` 规则也是如此（标题字体、管理中心的段落外边距、`[hidden]`、减弱动态效果）：字体请设置 `--glowthread-font-family`。有令牌时请优先使用令牌，颜色尤其如此：令牌会到达所有使用它的部件，并且有自己的夜间取值。

### 内部名称

公开的名称包括：令牌、上面的部件类和属性、`ext-glowthread-theme` 和 `data-glowthread-app`、切换类 `ext-glowthread-night` 和 `ext-glowthread-day`，ID `glowthread`、`glowthread-portal` 和 `ext-glowthread-admin`，以及本页提到的页面类：容器上的 `ext-glowthread`、管理中心容器上的 `ext-glowthread-admin`、提示 `ext-glowthread-nojs`，以及预留空间的类 `ext-glowthread-reserve` 和 `ext-glowthread-size-N`。其他一切都是内部名称，任何版本都可能更改：`gt-*` 类（`gt-scope`、`gt-root`、`gt-hit` 等）、`gt:` 工具类、令牌解析成的 `--gt-v-*` 值和其他 `--gt-*` 属性（`--gt-avatar-hue`、`--gt-lang-font-cjk`、`--gt-reserve`、`--gt-init-fill`、`--gt-sticky-top`）、`data-gt-*` 属性、`data-comment` 和 `data-pinned` 属性，以及部件之间的元素。请按类选择部件，而不是按它在元素树中的位置。

## 示例主题

[`client/src/design/example-themes.css`](../../client/src/design/example-themes.css) 中有可以复制到 `MediaWiki:Common.css` 或皮肤 CSS 页面的代码块。GlowThread 不会加载这个文件。每个代码块都可以用简单的选择器，以任意顺序单独使用或与其他代码块一起使用。如果两个代码块设置了同一个令牌（2 和 3 都设置了 `line-height-body`，1 和 5b 都设置了强调色），写在后面的代码块胜出。端到端测试会逐个单独应用这些代码块（`tests/e2e/specs/theming.spec.ts`）。

### 1. 品牌色

即[快速上手](#快速上手)中的代码块：蓝色强调色，浅色和夜间都有。它的对比度按默认值的标准检查过。浅色：`#3366cc` 标记在白色上为 5.37:1，`#2a4b8d` 文字在白色上为 8.44:1，在它的浅底 `#eaf0fb` 上为 7.37:1，`#2a4b8d` 按钮上的白色文字为 8.44:1（悬停时 10.23:1）。夜间：`#88a3e8` 标记和文字在夜间表面上为 6.72:1，在“置顶”徽标上为 5.08:1，在它 20% 的浅底上为 4.64:1，`#6d8fe0` 按钮上的墨色 `#171717` 文字为 5.68:1（悬停时 7.21:1）。换成你自己的颜色时，请检查同样的颜色组合。

### 2. 衬线阅读

评论正文和举报备注使用衬线字体，字号更大，行距更宽松。控件保持界面字体。

```css
:root {
    --glowthread-font-family-text: Georgia, "Noto Serif", "Source Han Serif SC", "Songti SC", serif;
    --glowthread-font-size-body: 0.9375rem;
    --glowthread-line-height-body: 1.75;
}
```

### 3. 紧凑密度

更小的间距单位、更紧凑的行和更小的头像。内边距、间隙和控件高度都是 `--glowthread-space` 的倍数，所以一个令牌就能缩放它们。触摸屏上的触控目标保持 44 px。

```css
:root {
    --glowthread-space: 0.2rem;
    --glowthread-comment-padding-y: 0.625rem;
    --glowthread-avatar-gap: 0.5rem;
    --glowthread-avatar-size: 1.75rem;
    --glowthread-line-height-body: 1.5;
}
```

### 4. 方形头像

方角头像。注释掉的几行让所有头像使用同一种中性色，代替由用户名决定的颜色。两种颜色之间要保持 4.5:1。

```css
:root {
    --glowthread-avatar-radius: 4px;
    /* --glowthread-avatar-bg: #e8e8e8;
       --glowthread-avatar-fg: #404040;
       --glowthread-night-avatar-bg: #33363b;
       --glowthread-night-avatar-fg: #ededed; */
}
```

### 5a. 仅深色：使用切换类

无需复制 CSS：把切换类 `ext-glowthread-night` 放在 `<html>` 或 `<body>` 上，GlowThread 就会使用它的夜间取值，以及站点设置的任何 `--glowthread-night-*`。`$wgGlowThreadTheme = 'night'` 会为整个维基这样做。皮肤用 `OutputPage::addHtmlClasses()` 添加这个类，小工具或 `MediaWiki:Common.js` 则用：

```js
document.documentElement.classList.add( 'ext-glowthread-night' );
```

`ext-glowthread-day` 的作用相反：即使在皮肤的夜间类下也使用浅色取值。

### 5b. 仅深色：只用 CSS

把夜间配色作为浅色取值。要全部复制：这些值只有放在一起才有效（`on-accent` 变深，是因为 `accent-bg` 变浅了）。能用 5a 时请优先使用 5a：管理中心的选择复选框把勾选标记绘制为一张图片，除非夜间模式开启，否则它是白色的，所以在这里它在浅粉色的填充上保持白色。

```css
:root {
    --glowthread-color-scheme: dark;
    --glowthread-color-fg: #ededed;
    --glowthread-color-fg-muted: #a1a1a1;
    --glowthread-color-fg-subtle: #8f8f8f;
    --glowthread-color-surface: #1c1e22;
    --glowthread-color-surface-subtle: rgba(255, 255, 255, 0.06);
    --glowthread-color-surface-strong: rgba(255, 255, 255, 0.1);
    --glowthread-color-border: rgba(255, 255, 255, 0.1);
    --glowthread-color-border-control: rgba(255, 255, 255, 0.38);
    --glowthread-color-accent: #e88a98;
    --glowthread-color-accent-text: #e88a98;
    --glowthread-color-accent-bg: #e07a8a;
    --glowthread-color-accent-bg-hover: #e88a98;
    --glowthread-color-on-accent: #171717;
    --glowthread-color-accent-subtle: rgba(232, 138, 152, 0.22);
    --glowthread-color-accent-badge: rgba(232, 138, 152, 0.16);
    --glowthread-color-highlight: rgba(232, 138, 152, 0.12);
    --glowthread-color-danger: #ff8a80;
    --glowthread-color-danger-subtle: rgba(255, 138, 128, 0.12);
    --glowthread-color-warning: #f0b860;
    --glowthread-color-warning-subtle: rgba(240, 184, 96, 0.12);
    --glowthread-color-success: #6fd58f;
    --glowthread-color-success-subtle: rgba(111, 213, 143, 0.12);
    --glowthread-color-overlay-border: rgba(255, 255, 255, 0.12);
    --glowthread-color-scrim: rgba(0, 0, 0, 0.56);
    --glowthread-shadow-overlay: 0 0 #0000;
    --glowthread-avatar-bg-saturation: 26%;
    --glowthread-avatar-bg-lightness: 26%;
    --glowthread-avatar-fg-saturation: 70%;
    --glowthread-avatar-fg-lightness: 84%;
}
```

### 6. 只针对一个应用

应用根元素和浮层容器带有 `data-glowthread-app`（`comments` 或 `hub`）。这个代码块让管理中心更紧凑，包括它的菜单和面板，评论区保持不变。

```css
.ext-glowthread-theme[data-glowthread-app="hub"] {
    --glowthread-space: 0.2rem;
    --glowthread-font-size-body: 0.8125rem;
}
```

### 7. 置顶评论加底色

在评论上设置的令牌作用于该评论，以及嵌套在其中、继承这个值的回复。第二条规则让回复不带底色。浅色底色上的对比度：`fg` 16.9:1，`fg-muted` 5.4:1，`accent-text` 5.1:1。

```css
.ext-glowthread-comment[data-glowthread-pinned] {
    --glowthread-comment-bg: #f6f8fc;
    --glowthread-night-comment-bg: rgba(255, 255, 255, 0.04);
}
.ext-glowthread-comment[data-glowthread-pinned] .ext-glowthread-comment {
    --glowthread-comment-bg: transparent;
    --glowthread-night-comment-bg: transparent;
}
```

## 无障碍

默认值在浅色和夜间模式下都达到 WCAG 2.2 AA，单元测试会检查默认的颜色组合。你设置的值需要你自己检查。

- **对比度。** 目标值写在颜色表下方：文字颜色在 `surface` 和你的页面背景上为 4.5:1，`accent` 和 `border-control` 为 3:1，状态颜色在各自的 `-subtle` 底色上为 4.5:1，`on-accent` 在 `accent-bg` 上为 4.5:1，头像字母在头像底色上为 4.5:1。请在浅色和夜间模式下、在你提供的每种皮肤上（评论区没有背景，页面会透出来），以及在你用 `comment-bg` 或 `highlight` 添加的底色上检查它们。让 `color-focus-ring` 与页面和各种表面保持 3:1，也不要把 `focus-ring-width` 设为 0。
- **触控目标。** 在触摸屏上，控件响应点按的区域至少为 44 × 44 px，在手机上菜单项和选项行高 44 px。这些尺寸是有意固定的，不跟随 `--glowthread-space`，所以紧凑的主题也会保留它们。
- **文字大小。** 默认字号以 rem 为单位，所以会跟随读者浏览器的文字大小设置。你设置的字号也请使用 rem 或 em。使用默认值时，除了 12 px 的徽标、小头像中 12 px 的字母和 11 px 的按键提示，文字在桌面上不小于 12.5 px，在手机上不小于 13 px。
- **减弱动态效果。** 当读者的系统要求减弱动态效果时，无论时长令牌是多少，GlowThread 都会把所有动画和过渡缩短到 1 ms，通过链接打开的评论会保持静态底色，而不是渐隐。
- **强制颜色模式。** 在强制颜色模式（Windows 的对比度主题）下，浏览器会用读者的系统颜色代替这些颜色，所以颜色令牌在那里不起作用。焦点环是轮廓线，仍然可见，管理中心的复选框会回退为系统控件。有一个例外：用方向键选中的 @提及建议靠内阴影标出，而这种模式会去掉阴影，所以只有屏幕阅读器能知道选中的是哪一项。不要只用颜色传达含义：你为置顶评论或自己的评论添加的底色在这种模式下会消失，而 GlowThread 自己的徽标仍保留文字。

## 放置评论区

容器是唯一一个你可以用站点 CSS 定位、设置尺寸和间距的元素。应用挂载在它的子元素中，所以你对容器的规则在应用加载后仍然有效。

```css
/* A narrower comment column, centred under the article */
#glowthread {
    max-width: 48rem;
    margin-left: auto;
    margin-right: auto;
}
```

加载器放置容器的方式：

1. 它查找皮肤的 `#bodyContent`，皮肤没有时查找 `#mw-content-text`。它会忽略页面正文、页面标题或指示器中带有这些 ID 的元素。
2. 只有当已有的 `#glowthread` 正好位于它本来要放置的位置（紧挨着锚点或在锚点内部）时，它才会复用这个元素。页面正文无法截获或隐藏评论区。
3. 否则，它会紧接在锚点之后创建容器。在 Minerva 上、在 Vector 2022 上，以及锚点的父元素是一个没有为容器留出行的 CSS 网格时（Citizen 的页面网格先是内容行，然后是页脚），它改为把容器放在锚点内部的最后。这时评论区仍然位于文章下方、页脚之上，处在服务器于 `#bodyContent` 内预留的空间中，所以加载后才变长的侧边栏（Vector 2022 固定的工具菜单）不会把它往下推。除 Minerva 外，如果站点 CSS 给 `#glowthread` 指定了网格行（`grid-row` 或 `grid-area`），容器会保留在锚点之后，作为该网格的一项。

站点规则如果给 `#glowthread` 指定了网格区域，它就会保持为锚点父元素的网格项。可以用这种方式把评论区放到你的皮肤或布局的其他网格区域中。

### 预留空间

为了避免评论加载时页脚跳动，服务器会在首次绘制前预留评论区的高度。符合条件的页面浏览会获得样式模块 `ext.glowThread.reserve` 和两个 body 类：`ext-glowthread-reserve` 和 `ext-glowthread-size-N`，其中 N 随页面累计的评论数分档变化（页面关闭了评论时为 `off`）。预留空间是一个以 `#bodyContent::after` 绘制的空块。加载器在一帧内把它替换为同样高度的骨架屏，应用会一直保持这个高度，直到列表绘制完成。没有 JavaScript 的读者不会获得预留空间。

如果你的站点 CSS 大幅改变了评论区（大得多的外边距、侧栏布局），预留的高度就不再匹配，页面可能会移动一次。另外还要注意：

- 在站点 CSS 中给 `#bodyContent::after` 设置样式。这会与预留空间冲突。
- 没有 `#bodyContent` 的布局（某些自定义皮肤）。这时加载器会退回到 `#mw-content-text`，并且不会预留空间，因为预留空间是绘制在 `#bodyContent` 上的。

## 不要覆盖的内容

| 不要 | 原因 |
|---|---|
| 用普通选择器（`#glowthread button`、`.gt-root h2`）给应用内部的元素设置样式 | 它们会输给带两个 ID 权重的限定作用域规则。请使用令牌，或带三个 ID 的部件钩子（见[普通 CSS 属性](#普通-css-属性)） |
| 写宽泛的 `!important` 规则，例如 `h2 { font-family: serif !important }` 或 `.mw-body p { margin: 1em 0 !important }` | `!important` 会压过 GlowThread 的作用域。GlowThread 只防御两种常见情况（两个应用中的标题字体、管理中心中的段落外边距），不防御其他情况 |
| 使用内部名称（`gt-*` 类、`gt:` 工具类、`--gt-*` 属性、`data-gt-*` 属性） | 它们不是公开接口，任何版本都可能更改（见[内部名称](#内部名称)） |
| 隐藏、移动 `#glowthread-portal` 或更改它的样式 | 菜单、面板和轻提示在这里渲染。它必须始终是 `<body>` 的直接子元素。在它上面设置令牌没有问题 |
| 把固定定位或粘性定位的元素放在 `z-index` 450 之上 | 面板位于 450，菜单和弹出框位于 470，轻提示位于 480。层级高于它们的 Cookie 横幅或粘性页头会把它们遮住。确有必要时，可以用 `--glowthread-z-sheet`、`--glowthread-z-popover` 和 `--glowthread-z-toast` 调整 GlowThread 的层级 |
| 给 `--glowthread-color-bg` 设置不透明的颜色 | 评论区设计为直接放在皮肤的页面上。悬停和按下状态的叠色是半透明的，前提是页面能透出来 |
| 在夜间选择器下用 `--glowthread-*` 设置颜色来改变夜间模式 | 对于有夜间取值的令牌，夜间模式只读取 `--glowthread-night-*`，所以这样的规则在夜间模式下不起作用（见[夜间取值](#夜间取值)） |

有几个值是固定的，不能通过主题更改：44 px 的触控目标（见[无障碍](#无障碍)）、管理中心 11 px 的键盘提示、大头像中 15 px 的字母、表情选择器中 20 px 的表情、链接高亮（2.4 s）、加载闪烁（1.6 s）和加载图标（0.8 s）的时长、640 px 断点和管理中心的布局宽度（720 px 和 1080 px）、表情选择器（312 px）、抹除对话框（440 px）和设置视图（760 px）的宽度，手机上粘性栏距底部 72 px 的高度、管理中心选择复选框的勾选标记（无论 `color-on-accent` 是什么，浅色模式下都是白色，夜间模式下都是 `#171717`），以及应用加载之前加载器骨架屏的灰色。管理中心的设置标题是 `--glowthread-font-size-title` 的 1.125 倍，所以会跟随该令牌变化。

## 皮肤说明

GlowThread 在每种皮肤上看起来都一样，而不是模仿各个皮肤。以下是经过测试的皮肤：

| 皮肤 | 评论区的位置 | 说明 |
|---|---|---|
| Vector（旧版） | `#bodyContent` 之后，在内容框内 | 没有夜间模式。深色小工具或站点主题可以用切换类让 GlowThread 变为深色（见[仅深色的站点](#仅深色的站点)） |
| Vector 2022 | `#bodyContent` 内部的最后，位于文章下方，因此固定的工具菜单栏无法把它往下推 | 通过主题类支持夜间模式。管理中心的粘性部分保持在 Vector 2022 粘性页头的下方 |
| Citizen | `#bodyContent` 内部的最后，位于页脚之上，因为 Citizen 的页面网格没有为它留出行。如果站点 CSS 给 `#glowthread` 指定网格行，它会保留在 `#bodyContent` 之后 | 通过主题类支持夜间模式。Citizen 4 预览版的主题需要一个开关（见[其他深色主题](#其他深色主题)）。在手机上，粘性栏（批量操作栏、设置保存栏）会在右侧留出 48 px，给 Citizen 的悬浮页面操作按钮 |
| Minerva（MobileFrontend） | `#bodyContent` 内部的最后，因此与 Minerva 共用两侧留白 | 通过主题类支持夜间模式。MobileFrontend 会折叠文章章节，所以评论往往就在第一屏下方开始 |

其他皮肤只要有 `#bodyContent` 或 `#mw-content-text` 就能工作，但没有经过测试。请检查评论区的位置、夜间模式（如果皮肤有夜间模式，或者皮肤始终为深色时的 `$wgGlowThreadTheme`），并确认皮肤的固定页头或工具栏不会遮住轻提示和面板。

在所有皮肤上，手机（窄于 640 px）使用底部面板、更大的文字和 44 px 的触控目标，轻提示和粘性栏悬浮在视口底部上方 72 px 处，以避开 Citizen 等皮肤的底部栏。从 640 px 起，面板从右侧打开，轻提示位于底部上方 24 px（`--glowthread-toast-offset`），粘性栏位于底部上方 16 px。在管理中心，批量操作栏显示时轻提示会上移 64 px。
