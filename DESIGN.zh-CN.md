---
name: GlowThread
description: MediaWiki 扩展 GlowThread 的评论区和管理中心，一个人们熟悉的评论区，间距精确，只用一种粉色强调色，夜间模式沉稳。
colors:
  fg: "#171717"
  fg-muted: "#666666"
  fg-subtle: "#737373"
  bg: "transparent"
  surface: "#ffffff"
  surface-subtle: "rgba(0, 0, 0, 0.04)"
  surface-strong: "rgba(0, 0, 0, 0.08)"
  border: "#e8e8e8"
  border-control: "#8a8a8a"
  accent: "#dc6e7e"
  accent-text: "#b83f55"
  accent-bg: "#b83f55"
  accent-bg-hover: "#a5384c"
  on-accent: "#ffffff"
  accent-subtle: "#fbe5e9"
  accent-badge: "#fbe5e9"
  highlight: "#fdf0f2"
  danger: "#c42b2b"
  danger-subtle: "#fdecec"
  warning: "#8a5300"
  warning-subtle: "#fdf3dc"
  success: "#1d7a3e"
  success-subtle: "#e6f4ea"
  overlay-border: "transparent"
  scrim: "rgba(0, 0, 0, 0.32)"
  fg-night: "#ededed"
  fg-muted-night: "#a1a1a1"
  fg-subtle-night: "#8f8f8f"
  surface-night: "#1c1e22"
  surface-subtle-night: "rgba(255, 255, 255, 0.06)"
  surface-strong-night: "rgba(255, 255, 255, 0.1)"
  border-night: "rgba(255, 255, 255, 0.1)"
  border-control-night: "rgba(255, 255, 255, 0.38)"
  accent-night: "#e88a98"
  accent-text-night: "#e88a98"
  accent-bg-night: "#e07a8a"
  accent-bg-hover-night: "#e88a98"
  on-accent-night: "#171717"
  accent-subtle-night: "rgba(232, 138, 152, 0.22)"
  accent-badge-night: "rgba(232, 138, 152, 0.16)"
  highlight-night: "rgba(232, 138, 152, 0.12)"
  danger-night: "#ff8a80"
  danger-subtle-night: "rgba(255, 138, 128, 0.12)"
  warning-night: "#f0b860"
  warning-subtle-night: "rgba(240, 184, 96, 0.12)"
  success-night: "#6fd58f"
  success-subtle-night: "rgba(111, 213, 143, 0.12)"
  overlay-border-night: "rgba(255, 255, 255, 0.12)"
  scrim-night: "rgba(0, 0, 0, 0.56)"
typography:
  title:
    fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, Roboto, "Helvetica Neue", Arial, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", "Noto Sans CJK SC", "Noto Sans SC", "Source Han Sans SC", "WenQuanYi Micro Hei", sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Noto Color Emoji"'
    fontSize: "1rem"
    fontWeight: 600
    lineHeight: 1.4
    letterSpacing: "normal"
  body:
    fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, Roboto, "Helvetica Neue", Arial, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", "Noto Sans CJK SC", "Noto Sans SC", "Source Han Sans SC", "WenQuanYi Micro Hei", sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Noto Color Emoji"'
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.65
    letterSpacing: "normal"
  body-strong:
    fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, Roboto, "Helvetica Neue", Arial, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", "Noto Sans CJK SC", "Noto Sans SC", "Source Han Sans SC", "WenQuanYi Micro Hei", sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Noto Color Emoji"'
    fontSize: "0.875rem"
    fontWeight: 500
    lineHeight: 1.65
    letterSpacing: "normal"
  meta:
    fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, Roboto, "Helvetica Neue", Arial, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", "Noto Sans CJK SC", "Noto Sans SC", "Source Han Sans SC", "WenQuanYi Micro Hei", sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Noto Color Emoji"'
    fontSize: "0.78125rem"
    fontWeight: 400
    lineHeight: 1.45
    letterSpacing: "normal"
    fontFeature: '"tnum"'
  meta-strong:
    fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, Roboto, "Helvetica Neue", Arial, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", "Noto Sans CJK SC", "Noto Sans SC", "Source Han Sans SC", "WenQuanYi Micro Hei", sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Noto Color Emoji"'
    fontSize: "0.78125rem"
    fontWeight: 500
    lineHeight: 1.45
    letterSpacing: "normal"
  badge:
    fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, Roboto, "Helvetica Neue", Arial, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", "Noto Sans CJK SC", "Noto Sans SC", "Source Han Sans SC", "WenQuanYi Micro Hei", sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Noto Color Emoji"'
    fontSize: "0.75rem"
    fontWeight: 500
    lineHeight: 1
    letterSpacing: "normal"
  kbd:
    fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, Roboto, "Helvetica Neue", Arial, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", "Noto Sans CJK SC", "Noto Sans SC", "Source Han Sans SC", "WenQuanYi Micro Hei", sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Noto Color Emoji"'
    fontSize: "0.6875rem"
    fontWeight: 500
    lineHeight: 1
    letterSpacing: "normal"
  code:
    fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", "Noto Sans Mono CJK SC", monospace'
    fontSize: "0.8125rem"
    fontWeight: 400
    lineHeight: 1.6
    letterSpacing: "normal"
rounded:
  badge: "4px"
  control: "6px"
  surface: "8px"
  full: "9999px"
spacing:
  "0.5": "2px"
  "1": "4px"
  "1.5": "6px"
  "2": "8px"
  "2.5": "10px"
  "3": "12px"
  "4": "16px"
  "5": "20px"
  "6": "24px"
  "8": "32px"
  "10": "40px"
  "12": "48px"
components:
  button-primary:
    backgroundColor: "{colors.accent-bg}"
    textColor: "{colors.on-accent}"
    typography: "{typography.body-strong}"
    rounded: "{rounded.control}"
    height: "32px"
    padding: "0 12px"
  button-primary-hover:
    backgroundColor: "{colors.accent-bg-hover}"
  button-secondary:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.fg}"
    typography: "{typography.body-strong}"
    rounded: "{rounded.control}"
    height: "32px"
    padding: "0 12px"
  button-secondary-hover:
    backgroundColor: "{colors.surface-subtle}"
  button-ghost:
    backgroundColor: "{colors.bg}"
    textColor: "{colors.fg-muted}"
    typography: "{typography.body-strong}"
    rounded: "{rounded.control}"
    height: "32px"
    padding: "0 12px"
  button-ghost-hover:
    backgroundColor: "{colors.surface-subtle}"
    textColor: "{colors.fg}"
  button-subtle:
    backgroundColor: "{colors.surface-subtle}"
    textColor: "{colors.fg}"
    rounded: "{rounded.control}"
    height: "32px"
    padding: "0 12px"
  button-subtle-hover:
    backgroundColor: "{colors.surface-strong}"
  button-danger:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.danger}"
    typography: "{typography.body-strong}"
    rounded: "{rounded.control}"
    height: "32px"
    padding: "0 12px"
  button-danger-hover:
    backgroundColor: "{colors.danger-subtle}"
  button-link:
    textColor: "{colors.accent-text}"
    typography: "{typography.body-strong}"
  button-sm:
    typography: "{typography.meta-strong}"
    height: "28px"
    padding: "0 10px"
  button-inline:
    typography: "{typography.meta-strong}"
    height: "28px"
    padding: "0 6px"
  like-button:
    textColor: "{colors.fg-muted}"
    typography: "{typography.meta}"
    rounded: "{rounded.control}"
    height: "28px"
    padding: "0 6px"
  like-button-liked:
    textColor: "{colors.accent-text}"
  badge-neutral:
    backgroundColor: "{colors.surface-subtle}"
    textColor: "{colors.fg-muted}"
    typography: "{typography.badge}"
    rounded: "{rounded.badge}"
    height: "20px"
    padding: "0 6px"
  badge-accent:
    backgroundColor: "{colors.accent-badge}"
    textColor: "{colors.accent-text}"
  badge-warning:
    backgroundColor: "{colors.warning-subtle}"
    textColor: "{colors.warning}"
  badge-danger:
    backgroundColor: "{colors.danger-subtle}"
    textColor: "{colors.danger}"
  badge-success:
    backgroundColor: "{colors.success-subtle}"
    textColor: "{colors.success}"
  composer:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.fg}"
    typography: "{typography.body}"
    rounded: "{rounded.surface}"
    padding: "8px 12px"
  input-field:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.fg}"
    typography: "{typography.body}"
    rounded: "{rounded.control}"
    height: "32px"
    padding: "0 10px"
  checkbox-checked:
    backgroundColor: "{colors.accent-bg}"
    textColor: "{colors.on-accent}"
    rounded: "{rounded.badge}"
    size: "18px"
  tab:
    textColor: "{colors.fg-muted}"
    typography: "{typography.body}"
    rounded: "{rounded.control}"
    height: "32px"
    padding: "0 8px"
  tab-active:
    textColor: "{colors.fg}"
    typography: "{typography.body-strong}"
  hub-tab:
    textColor: "{colors.fg-muted}"
    typography: "{typography.body}"
    height: "40px"
    padding: "0 10px"
  nav-item:
    textColor: "{colors.fg-muted}"
    typography: "{typography.body}"
    rounded: "{rounded.control}"
    height: "32px"
    padding: "0 8px"
  nav-item-current:
    backgroundColor: "{colors.surface-strong}"
    textColor: "{colors.fg}"
    typography: "{typography.body-strong}"
  menu:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.fg}"
    typography: "{typography.body}"
    rounded: "{rounded.surface}"
    padding: "4px"
    width: "144px"
  menu-item:
    textColor: "{colors.fg}"
    rounded: "{rounded.control}"
    height: "32px"
    padding: "6px 10px"
  menu-item-highlighted:
    backgroundColor: "{colors.surface-subtle}"
  menu-item-danger:
    textColor: "{colors.danger}"
  menu-item-danger-highlighted:
    backgroundColor: "{colors.danger-subtle}"
  sheet:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.fg}"
    rounded: "{rounded.surface}"
    width: "440px"
  toast:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.fg}"
    typography: "{typography.body}"
    rounded: "{rounded.surface}"
    padding: "6px 6px 6px 16px"
    height: "44px"
    width: "420px"
  tooltip:
    backgroundColor: "{colors.fg}"
    textColor: "{colors.surface}"
    typography: "{typography.meta}"
    rounded: "{rounded.control}"
    padding: "4px 8px"
  notice:
    backgroundColor: "{colors.surface-subtle}"
    textColor: "{colors.fg-muted}"
    typography: "{typography.body}"
    rounded: "{rounded.surface}"
    padding: "8px 12px"
  bulk-bar:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.fg}"
    rounded: "{rounded.surface}"
    padding: "8px 12px"
  avatar:
    typography: "{typography.body-strong}"
    rounded: "{rounded.full}"
    size: "32px"
  avatar-sm:
    typography: "{typography.badge}"
    rounded: "{rounded.full}"
    size: "24px"
  queue-item:
    textColor: "{colors.fg}"
    typography: "{typography.body}"
    padding: "10px 12px"
  queue-item-focused:
    backgroundColor: "{colors.surface-subtle}"
  queue-item-selected:
    backgroundColor: "{colors.highlight}"
---

[English](DESIGN.md) | 简体中文

# 设计系统：GlowThread

## 概览

**创意北极星：“熟悉的评论区，一丝不苟”**

GlowThread 就是读者在大型视频和社区网站上早已熟悉的那种评论区，而且不玩花样：评论数加“热门 · 最新”标签页，一行高的评论输入框，置顶和热门评论，然后是头像、名字、楼层和时间、正文、点赞和“回复”，两级回复，其余回复通过“再显示 N 条回复”就地展开。它的第二个界面是一个管理收件箱，供清理队列的管理员使用。两个界面都不另创外观。它们靠精确的间距、沉稳的层级、即时的反馈，以及在旧版 Vector、Vector 2022、Citizen 和 Minerva 上完全一致的行为取胜。

材质就是维基页面本身。评论区不绘制背景，也没有卡片，所以无论放在 Vector 的白色、Citizen 的冷灰色还是任何夜间背景上，都原样融入。细分隔线和 4 px 的节奏构成它的结构，半透明的黑色或白色叠色提供悬停和按下状态，唯一的粉色标出一切可以操作的东西。只有浮动的元素才会从页面上抬起。动效只用来标示状态变化，开启减弱动态效果后就会关闭。

密度取决于用途。读者端的评论区宽松舒适：1.65 的行高，评论行之间 16 px，手机上 44 px 的触控目标，⋯ 菜单始终可见。管理中心紧凑但沉稳：条目内边距 10 px，28 px 的操作按钮带按键提示，从 1080 px 起侧栏、队列和记录并排显示。两者共用所有设计令牌和组件。刻意不用的有：渐变、毛玻璃和发光，装饰性动效，只在悬停时出现的控件，在内联控件或面板就够用的地方使用模态框，无限滚动，加载时的布局位移，Web 字体和任何外部请求。

**主要特征：**
- 一种强调色，粉色，分两种色调：基础色调用于标记，一种更深、可放心用作文字的色调用于文字和填充按钮。
- 透明的评论区直接放在皮肤自己的页面上，由 1 px 的细分隔线构成结构。
- 悬停、按下、标签和提示框都用半透明的中性叠色，因此在每种皮肤上看起来都一样。
- 先用系统无衬线字体，再用适合读者语言的 CJK 系统字体，共三种文字字号。字重和颜色承担层级，数字使用等宽数字。
- 静止时扁平。浮层在浅色模式下用柔和的阴影抬起，在夜间模式下用 1 px 边框，两者从不同时使用。
- 首字头像，底色是由名字派生的柔和色相，不上传图片，也不发出外部请求。
- 每个控件都能用触摸（44 px）和键盘（2 px 的粉色焦点环）操作。

## 颜色

严格的中性色阶，凡是直接放在页面上的地方都是半透明的，加上一种粉色强调色和三种以浅底形式出现的柔和状态色。每种颜色都是一个公开令牌，浅色模式下为 `--glowthread-color-*`，夜间模式下为 `--glowthread-night-color-*`。夜间取值作用于 `html.skin-theme-clientpref-night`、系统为深色时的 `html.skin-theme-clientpref-os`，以及切换类 `ext-glowthread-night` 之下。

### 主色
- **强调粉**（Accent Pink，#dc6e7e，夜间 #e88a98，令牌 `accent`）：用于标记，不用于文字。已点赞的实心爱心、选中标签页 2 px 的下划线、2 px 的焦点环、获得焦点的评论输入框或输入框的边框。它在白色上达到 3.19:1，足够用于标记，但在浅色模式下永远不够用于文字。
- **深玫瑰**（Deep Rose，#b83f55，夜间 #e88a98，令牌 `accent-text`）：作为文字的粉色。评论正文中的链接和 @提及，读者端的内联文字操作（“再显示 N 条回复”和“收起回复”、“展开”和“收起”、轻提示中的“撤销”、“查看封禁详情”），已点赞的计数，文字光标，以及“置顶”徽标的文字。在白色上为 5.41:1。
- **玫瑰填充**（Rose Fill，#b83f55，悬停 #a5384c，令牌 `accent-bg` 和 `accent-bg-hover`）配**玫瑰底文字**（On Rose，#ffffff，令牌 `on-accent`）：主按钮，以及选中的复选框和单选框。夜间模式反转这一搭配：较浅的填充（#e07a8a，悬停 #e88a98）配墨色文字（#171717），对比度 6.25:1。
- **浅粉**（Blush，#fbe5e9，夜间为 22% 的粉色，令牌 `accent-subtle`）：文字选区、敏感词规则中匹配的行号、管理中心的“有 N 条新内容 · 刷新”标签。
- **徽标浅粉**（Badge Blush，#fbe5e9，夜间为 16% 的粉色，令牌 `accent-badge`）：仅用作“置顶”徽标的底色。夜间比浅粉更淡，这样深玫瑰文字在每个深色表面上（包括面板）都能保持 4.5:1（在夜板岩上为 5.14:1）。
- **淡粉**（Faint Blush，#fdf0f2，夜间为 12% 的粉色，令牌 `highlight`）：通过永久链接打开的评论（2.4 s 后淡出），以及管理中心队列中选中的条目。

### 中性色
- **墨色**（Ink，#171717，夜间 #ededed，令牌 `fg`）：所有主要文字和作者名。在白色上为 17.9:1。
- **石墨灰**（Graphite，#666666，夜间 #a1a1a1，令牌 `fg-muted`）：时间、楼层号、计数、帮助说明、未选中的标签页和幽灵按钮的文字。在白色上为 5.74:1，在夜间表面上为 6.46:1。
- **锡灰**（Pewter，#737373，夜间 #8f8f8f，令牌 `fg-subtle`）：占位文字和排序标签页之间的 ·。在白色上为 4.74:1。
- **透底**（Page Through，透明，令牌 `bg`）：评论区和管理中心的背景。皮肤的页面会透出来。
- **纸白**（Paper，#ffffff）和**夜板岩**（Night Slate，#1c1e22）（令牌 `surface`）：输入框、菜单、弹出框、面板、轻提示、批量操作栏，以及次要按钮和危险按钮的底面。
- **烟雾叠色**（Smoke Wash，4% 的黑色，夜间为 6% 的白色，令牌 `surface-subtle`）：悬停、骨架屏、提示框、登录提示框、中性徽标、高亮的菜单项、获得焦点的管理中心条目。
- **烟雾按下色**（Smoke Press，8% 的黑色，夜间为 10% 的白色，令牌 `surface-strong`）：按下和当前状态，例如管理中心当前的导航项和分段切换器，上面总是墨色文字。
- **细分隔线**（Hairline，#e8e8e8，夜间为 10% 的白色，令牌 `border`）：评论之间和队列项之间的分隔线、标题栏下的横线、回复旁的竖线、面板标题栏、菜单分隔线。
- **控件边线**（Control Edge，#8a8a8a，夜间为 38% 的白色，令牌 `border-control`）：输入框、次要和危险按钮、复选框和单选框的轮廓，滚动条滑块，以及管理中心链接的下划线。在白色上为 3.45:1，在夜间表面上为 3.56:1。
- **浮层边线**（Overlay Edge，透明，夜间为 12% 的白色，令牌 `overlay-border`）和**遮罩**（Scrim，32% 的黑色，夜间 56%，令牌 `scrim`）：浮层在夜间的层次边框，以及面板后面的变暗遮罩。

### 状态色
- **信号红**（Signal Red，#c42b2b，夜间 #ff8a80）配**红色浅底**（Red Tint，#fdecec，夜间 12%）：删除、隐藏和抹除按钮（作为文字）、被举报徽标、举报旗标、错误、删除确认条。
- **琥珀棕**（Amber Brown，#8a5300，夜间 #f0b860）配**琥珀浅底**（Amber Tint，#fdf3dc，夜间 12%）：待审核徽标和时钟、速率限制的等待提示、管理员看到的页面已关闭说明、条目已过时的说明、频繁举报人旗标，以及举报人筛选标签。
- **叶绿**（Leaf Green，#1d7a3e，夜间 #6fd58f）配**叶绿浅底**（Leaf Tint，#e6f4ea，夜间 12%）：敏感词测试的通过结果。成功徽标的色调已经存在，但目前没有使用。

### 命名规则
**唯一粉色规则（The One Pink Rule）。** 粉色是唯一表示“在这里操作”或“这是当前项”的色相。基础色调绘制标记（爱心、标签页下划线、焦点环、获得焦点的输入框边框），深色调承载文字和填充按钮。填充的粉色按钮是所在视图唯一的主要操作：“发送”、“提交举报”、“登录”，设置中的“检查并保存”或“保存更改”，敏感词界面中的“保存敏感词”。

**状态用浅底规则（The Status-as-Tint Rule）。** 危险、警告和成功只以自身浅底上的文字或小图标标记（旗标、时钟）的形式出现。它们从不填充按钮、行或输入框。破坏性按钮保持描边样式并使用红色文字。

**半透明叠色规则（The Translucent Wash Rule）。** 悬停、按下、标签和提示框的表面是 4% 到 10% 的黑色或白色，绝不使用不透明的灰色，这样它们在 Vector 的白色、Citizen 的冷灰色和每种夜间背景上看起来都一样。

**中性链接规则（The Neutral Link Rule）。** 在管理中心，链接使用正文颜色，带控件边线色的下划线，悬停时下划线变为实色。粉色留给焦点、选中、已勾选的控件、标签页下划线和保存。在读者端，评论正文中的链接和提及保持深玫瑰色。

**4.5 与 3 规则（The 4.5 and 3 Rule）。** 文字令牌在白色和夜间表面上达到 4.5:1，状态文字在自身浅底上达到 4.5:1，控件边线和粉色标记达到 3:1。新颜色在上线前要证明两者都满足。

## 字体排版

**正文字体：** 拉丁文字优先使用系统无衬线字体（-apple-system、Segoe UI、system-ui、Roboto 和 Arial），然后是 `--glowthread-font-cjk` 中的 CJK 系统字体，然后是彩色 emoji。CJK 字体跟随 `<html lang>`：日语（Hiragino Sans、Yu Gothic、Meiryo、Noto Sans CJK JP）、韩语（Apple SD Gothic Neo、Malgun Gothic、Noto Sans CJK KR）、繁体中文（PingFang TC、Microsoft JhengHei、Noto Sans CJK TC，香港使用香港字体），其他情况使用简体中文（PingFang SC、Hiragino Sans GB、Microsoft YaHei、Noto Sans CJK SC、Source Han Sans SC），让汉字以读者期望的字形显示。不使用 Web 字体。
**标签/等宽字体：** ui-monospace 加 Noto Sans Mono CJK SC，只用于管理中心的敏感词编辑器。

**风格：** 平台自带的无衬线字体，使用小而精确的字号。层级来自字重（400、500、600）以及墨色与石墨灰的对比，绝不靠大幅度的字号跳跃。没有展示级字号：GlowThread 绘制的最大标题是管理中心的设置标题，比标题字号大一级（管理中心的页面标题由皮肤自己绘制）。

### 层级
- **标题**（Title，600，桌面 16 px，手机 17 px，行高 1.4）：“评论”区块标题、面板标题、设置分区标题、记录名称。队列为空时的标题使用同样的字号，字重为 500。设置页面的标题比它大一级（600，标题字号乘以 1.125，桌面上为 18 px）。
- **正文**（Body，400，桌面 14 px，手机 15 px，行高 1.65）：评论文字（最宽 46em，超过 6 行后折叠，显示“展开”）、输入框、菜单项、提示框、轻提示。“评论”旁边的评论数使用正文字号和石墨灰。
- **正文加强**（Body Strong，500，正文字号）：作者名、选中的标签页、中等按钮的文字。
- **辅助**（Meta，400，桌面 12.5 px，手机 13 px，行高 1.45）：时间、楼层号、计数、帮助说明、举报理由。字重为 500 时用作小按钮和内联文字操作的文字。
- **徽标**（Badge，500，12 px，行高 1）：状态徽标、频繁举报人旗标、小头像中的首字。
- **按键提示**（Key hint，500，11 px，大写，1 px 细分隔线边框，4 px 圆角）：管理中心操作旁边的键盘提示。在触摸屏上隐藏。
- **代码**（Code，400，13 px，行高 1.6，等宽）：敏感词编辑器及其行号。

### 命名规则
**三种字号规则（The Three Sizes Rule）。** 文字只分辅助、正文和标题三种：桌面上为 12.5、14 和 16 px，手机上为 13、15 和 17 px（比例约为 1.125）。12 px 的徽标和 11 px 的按键提示属于标记，不算文字字号。

**等宽数字规则（The Tabular Figures Rule）。** 所有会变化或需要对齐的数字（计数、楼层号、时间、字数计数器、标签页计数、精确时间提示）都使用等宽数字。

## 布局

评论区是一列，宽度与皮肤的内容列相同，位于文章下方 32 px 处，它的标题栏、提示框、评论输入框和列表之间间隔 16 px。间距以 4 px 为基准（Tailwind 的间距单位是 0.25rem），控件内部使用 2 px 的半步：6 px 的图标间距和 10 px 的侧边内边距与 8 px 和 12 px 一样常见。最常用的间距是 8、12、4、6 和 16 px。

**评论区结构。** 标题栏至少 40 px 高，下方留 8 px，底部有一条细分隔线：左边是“评论”和评论数，右边是“热门 · 最新”。评论输入框这一行把查看者的头像放在距输入框 12 px 处。评论行上下各留 16 px，用细分隔线隔开（最后一行没有）。在一行里，头像距文字列 12 px（回复为 8 px）。行头至少 24 px：名字、徽标，然后是推到右边的楼层 · 时间，最后是 ⋯。点赞和“回复”这一行位于文字下方 4 px，两者相距 16 px，整体向左拉 6 px，让爱心与文字对齐。回复在手机上缩进 44 px，从 640 px 起缩进 48 px，位于一条 1 px 的细分隔线之后，与线相距 12 px。第二级回复再缩进 32 px，不会嵌套得更深。“加载更多”居中，至少 160 px 宽。

**按宽度。** 唯一的断点是 640 px。低于它（手机）时，文字大一级，回复折叠在“展开 N 条回复”后面，“回复 @name”说明单独占一行，面板从底部升起（最高为视口的 85%），菜单项高 44 px，轻提示悬浮在底部上方 72 px。从 640 px 起，头像从 32 px 增大到 36 px，面板以 440 px 宽从侧边滑入（从右侧，在从右到左的界面中从左侧），菜单项高 32 px，轻提示位于底部上方 24 px。

**管理中心，按应用自身的宽度**（用 ResizeObserver 测量，因为容器查询需要 Chrome 105）。从 1080 px 起：136 px 的侧栏、队列和 300 px 的记录面板，彼此相距 24 px，侧栏和记录面板粘在皮肤页头下方。从 720 px 起：侧栏变成上方的一行（“队列”和“设置”作为分段切换器），队列和 280 px 的记录面板并排，相距 20 px。低于 720 px：单列，间距 16 px，记录以面板形式打开。队列项的内边距为 10 px × 12 px，下方有一条细分隔线，复选框、头像（从 640 px 起）和内容放在一个间距 12 px 的网格中。操作在文字下方单独占一行，左对齐，间距 6 px。在手机上，页面和作者筛选折叠在“筛选”后面。设置最宽 760 px，各分区之间相距 32 px。

**按皮肤。** 评论区在每种皮肤上看起来都一样，而不是模仿某一种。旧版 Vector 把它放在 `#bodyContent` 之后。Vector 2022 把它放在 `#bodyContent` 内部的最后、文章下方，这样它固定的工具菜单栏就无法把评论区往下推。Citizen 把它放在 `#bodyContent` 内部的最后、页脚之上，因为它的页面网格没有为评论区留出行（站点 CSS 如果给 `#glowthread` 指定网格行，它就保持为网格项），在手机上轻提示和粘性栏会抬高以避开它的底部栏，粘性栏还会在右侧留出 48 px，给它的悬浮页面操作按钮。Minerva 把它作为 `#bodyContent` 的最后一个子元素。在首次绘制之前，服务器会预留评论区的高度（这是一个估计值，随页面的楼层数分档变化，手机有单独的取值，关闭了评论的页面使用一个较矮的值），加载器的骨架屏会正好填满这个高度。

### 命名规则
**页面透出规则（The Page-Through Rule）。** 评论区从不绘制自己的背景、卡片或外边框。皮肤的页面透出来，由细分隔线负责划分结构。

**零位移规则（The No-Shift Rule）。** 评论区的高度在首次绘制前就已预留，骨架屏与之完全吻合，所以评论加载时文章下方的任何内容都不会移动。

**44 规则（The 44 Rule）。** 在触摸屏上，每个控件都在以自身为中心、至少 44 × 44 px 的范围内响应点击，且不会撑大布局（`gt-hit`）。菜单项和选项行在手机上高 44 px。

## 层次与深度

GlowThread 静止时是扁平的。评论、队列项、提示框和记录面板都没有阴影：行与行之间用细分隔线隔开，方框则靠底色或细分隔线边框区分。只有浮在内容之上的层才有深度：菜单、弹出框、面板、抹除对话框、轻提示、工具提示、@提及列表和粘性的批量操作栏。浅色模式用柔和的两层阴影把它们抬起，边框是透明的。夜间模式去掉阴影（深色页面上的深色阴影看起来像污渍），改为绘制一条 12% 白色的 1 px 边框。

### 阴影词汇
- **浮层**（Overlay，`box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08), 0 12px 32px rgba(0, 0, 0, 0.12)`，夜间为 `0 0 #0000` 加浮层边线边框）：上面列出的所有浮层。
- **永久链接外扩**（Permalink bleed，淡粉色的 `box-shadow: 0 0 0 6px`）：不表示层次。它把高亮底色延伸到被链接的评论之外 6 px，又不会产生可滚动的溢出，并随高亮一起淡出。
- **键盘选项环**（Keyboard option ring，强调粉的 `box-shadow: inset 0 0 0 2px`）：用方向键选到的 @提及选项，绘制在该行内部。

层叠顺序：皮肤页头位于 200，其下拉菜单位于 350。面板、抹除对话框及其遮罩位于 450。菜单、弹出框、工具提示和 @提及列表位于 470，在打开的面板之上。轻提示位于 480。

### 命名规则
**阴影或边框规则（The Shadow-or-Border Rule）。** 浮层在浅色模式下使用阴影，在夜间模式下使用 12% 白色的边框，两者从不同时使用。

**静止扁平规则（The Flat-at-Rest Rule）。** 留在页面流中的元素都不投射阴影。不浮起的东西就不抬升。

## 形状

小而柔和的圆角，分三级，另加圆形。表面使用 8 px 圆角：评论输入框的边框、菜单、弹出框、轻提示、提示框、登录提示框、批量操作栏、记录面板、抹除对话框，以及底部面板的上方两角。控件使用 6 px 圆角：按钮、输入框、排序下拉框、标签页的点击区域、菜单项、工具提示，以及评论行的高亮。标记使用 4 px 圆角：徽标、复选框、按键提示，以及输入框里的小清除按钮。头像、单选框、胶囊（举报人筛选标签、“有 N 条新内容”标签）和标签页下划线的端头都是正圆。侧边面板贴着视口边缘的一侧保持直角。边框都是 1 px。只有焦点环和标签页下划线是 2 px。面板中的滚动条很细（8 px），滑块为控件边线色。

### 命名规则
**三种圆角规则（The Three Radii Rule）。** 表面 8 px，控件 6 px，标记 4 px，头像和胶囊为正圆。除了圆形，没有任何圆角大于 8 px。

## 组件

### 按钮
安静而精确：一种形状，一套高度，文字使用中等字重。
- **形状：** 柔和的圆角（6 px）。高度为 32 px（md）和 28 px（sm），方形图标按钮为 32 或 28 px，另有用于文字列中操作的内联尺寸（28 px，6 px 内边距，向左拉 6 px）。图标和文字相距 6 px。加载指示器（16 px）放在按钮内部，代替按钮的图标，从不代替内容。
- **主要：** 玫瑰填充配白色文字（夜间：较浅的粉色配墨色文字），悬停时变深（夜间变浅）。只用于视图中唯一的主要操作。
- **次要（默认）：** 纸白底面，1 px 控件边线，墨色文字，悬停时为烟雾叠色。“通过”、“加载更多”、“重试”、“恢复”。
- **幽灵：** 没有底面，石墨灰文字，悬停时为烟雾叠色和墨色。“回复”、“取消”、“置顶”、“查看上下文”。
- **柔和：** 烟雾叠色底面，悬停时为烟雾按下色。
- **危险：** 次要按钮的形状，信号红文字，悬停时为红色浅底。“删除”、“隐藏”、“抹除”。从不填充。
- **链接：** 深玫瑰文字，悬停时加下划线。
- **焦点和禁用：** 全局统一的 2 px 强调粉焦点环，位于边缘外 2 px。禁用的控件降到 50% 不透明度，不再接受指针输入。

### 点赞按钮
- **样式：** 16 px 的描边爱心加一个辅助字号、石墨灰的等宽计数，高 28 px，悬停时为烟雾叠色。
- **已点赞：** 爱心填充为强调粉，计数变为深玫瑰，`aria-pressed` 变为 true。点击时爱心在 150 ms 内从 80% 放大到 112% 再弹回，计数立即变化（乐观更新，以最后一次点击为准）。
- **只读：** 对于不能点赞的查看者，显示同样的爱心和计数，但没有悬停状态。

### 标签
- **徽标：** 高 20 px，4 px 圆角，12 px 中等字重文字，左右内边距 6 px。色调：中性（烟雾叠色、石墨灰：“已隐藏”）、强调（徽标浅粉、深玫瑰：“置顶”）、警告（琥珀色：“待审核”、“仅你可见 · 等待审核”）、危险（红色：“被举报 N”、“已封禁”）、成功。在管理中心，条目从不重复所在标签页已经表明的状态。
- **胶囊标签：** 28 px，全圆角。管理中心的举报人筛选（“举报人：X”，琥珀浅底，带一个 20 px 的清除按钮）和刷新标签（“有 N 条新内容 · 刷新”，浅粉，深玫瑰）。
- **频繁举报人旗标：** 举报人名字后面的一个琥珀色徽标（“本列表举报 N 条”），用于把队列筛选为该举报人的举报。在一行举报信息里只有图标带颜色，它是其中唯一的琥珀色旗标。

### 卡片 / 容器
- **不用卡片。** 评论和队列项是细分隔线之间的行，没有方框、没有填充，也没有阴影。
- **方框只用在方框本身有意义的地方：** 登录提示框和提示框（8 px，烟雾叠色，8 × 12 px 内边距，一个 16 px 图标），删除确认和过时说明（6 px，红色或琥珀浅底），管理中心的记录面板（8 px，细分隔线边框，无填充），永久链接或热门评论所在的对话不在列表中时在列表上方打开的“对话”框（8 px，细分隔线边框，无填充，32 px 的标题栏，标题为辅助字号的石墨灰，另有一个关闭按钮），批量操作栏（8 px，纸白，浮层层次，粘性定位）。

### 输入框 / 字段
- **评论输入框：** 8 px 圆角的框体，纸白底上 1 px 控件边线。获得焦点前只有一行，获得焦点后变成三行，下方有一条细分隔线和工具栏：表情、@、接近上限时显示的等宽字数、回复时的“取消”，以及“发送”。获得焦点时框体边框变为强调粉（文本框本身不绘制焦点环）。最多增高到 12 行。按 Ctrl 或 ⌘ + Enter 发送。超出上限时，计数和一条消息变为信号红。受速率限制时，一行琥珀色文字说明需要等待多久。
- **文本输入框**（管理中心的筛选、理由、设置中的数字）：32 px，6 px 圆角，控件边线，纸白，左侧有一个内缩 10 px 的 16 px 石墨灰图标。获得焦点时边框变粉，并加上全局焦点环。有值的筛选框使用墨色边框。无效的输入框使用信号红边框。禁用的输入框使用烟雾叠色和石墨灰文字。
- **下拉选择框**（队列排序）：28 px，控件边线，辅助字号文字，带自己的 14 px V 形图标，因为重置样式去掉了原生箭头。
- **复选框和单选框：** 18 px，纸白底上的控件边线。选中的复选框填充玫瑰填充色，上面是白色对勾（夜间为墨色）。选中的单选框使用玫瑰填充色的边框和一个 10 px 的圆点。单选选项行在手机上高 44 px，从 640 px 起高 32 px。在强制颜色模式下，管理中心的复选框回退为系统方框。
- **文字相关样式：** 占位文字为锡灰，光标为深玫瑰，选区为浅粉。

### 导航
- **排序标签页（读者端）：** “热门 · 最新”，正文字号，高 32 px，左右内边距 8 px，石墨灰，悬停时变为墨色。选中的标签页为墨色、中等字重，下方是一条 2 px 的强调粉下划线，左右各内缩 8 px，端头为圆形。两者之间用一个锡灰色的 · 分隔。
- **队列标签页（管理中心）：** 高 40 px，带一个石墨灰的等宽计数。下划线落在标签栏的细分隔线上。窄屏上标签栏可以横向滚动。
- **管理中心侧栏（从 1080 px 起）：** 32 px 的条目，带 16 px 图标、文字和计数。当前条目使用烟雾按下色和墨色中等字重文字。细分隔线之后是 28 px、辅助字号的日志、敏感词页面和快捷键链接。
- **分段切换器：** 低于 1080 px 时位于队列上方的“队列”和“设置”，以及记录面板中的“作者”和“页面”。当前分段使用烟雾按下色。它从不使用下划线，因此不会被误认为队列标签页。
- **管理中心链接：** 正文颜色，文字下方 3 px 处有一条 1 px 的控件边线色下划线，悬停时变为实色。

### 菜单、面板、轻提示和提示
- **⋯ 菜单：** 触发按钮为 28 px（触摸屏上 44 px），始终可见。菜单为纸白，8 px 圆角，4 px 内边距，至少 144 px 宽，与触发按钮的末端对齐，相距 4 px，使用浮层层次。菜单项为 6 px 圆角，高 32 px（手机上 44 px），左右内边距 10 px，图标间距 10 px。高亮的菜单项使用烟雾叠色。键盘焦点在菜单项内部绘制粉色焦点环。危险菜单项为红色浅底上的信号红。分隔线是细分隔线，分组标签为辅助字号的石墨灰文字。
- **举报表单：** 从 640 px 起是锚定在评论上的弹出框，在手机上是底部面板。理由以单选行列出，有一个可选的补充说明，“提交举报”是唯一的主按钮。
- **面板：** 用于管理中心的记录和手机上的举报表单。对话从不在面板中打开。在手机上是底部面板，上方两角为 8 px 圆角，顶边有一条浮层边线。从 640 px 起是 440 px 宽的侧边面板，位于右侧，左边有一条浮层边线（在从右到左的界面中镜像）。它的标题栏高 52 px，包含标题和一个 36 px 的关闭按钮，面板覆盖在遮罩之上。
- **轻提示：** 最宽 420 px，至少 44 px 高，纸白，8 px 圆角，浮层层次。包含一条消息、一个可选的深玫瑰色操作（“撤销”）和一个关闭按钮。错误为红色文字，放在以 assertive 方式播报的实时区域中。带操作的轻提示停留 6 s，悬停或获得焦点时暂停计时。按 F8 会把焦点移到最新一条轻提示的操作上。管理中心显示批量操作栏时，轻提示的位置会升高。
- **精确时间提示和工具提示：** 反色（墨色底面，纸白文字），6 px 圆角，4 × 8 px 内边距，辅助字号，等宽数字，浮层层次。纯 CSS 实现的时间提示在悬停 300 ms 后出现，通过键盘获得焦点时立即出现。

### 头像
- **样式：** 名字的第一个字符，放在由名字派生的柔和色相上。色相各不相同，色调由令牌固定：背景为 58% 饱和度和 89% 亮度，文字为 52% 和 28%（夜间：26% 和 26% 的背景上是 70% 和 84% 的文字）。每种色相都满足 4.5:1，由单元测试检查。
- **尺寸：** 评论和评论输入框为 32 px（从 640 px 起 36 px），回复和列表为 24 px，正圆，中等字重。
- **通用头像：** 已隐藏的用户、旧数据中以 IP 署名的作者和未登录的读者显示一个人形图标，石墨灰，底色为烟雾叠色。

### 评论行
读者端的标志性组件。头像，然后是一列：正文加强字样的名字（链接到用户页）、徽标、回复的是讨论串中更深处某人时显示的“回复 @name”说明，然后是推到右边的楼层和时间（`#42 · 2 hours ago`，辅助字号，等宽数字，石墨灰），最后是 ⋯。接着是正文字样的文字，然后是点赞和“回复”这一行。管理员会在文字和点赞行之间看到待审核和被举报的原因，并可一键做出决定。新评论会滑入到位。通过永久链接打开的评论使用淡粉色，在 2.4 s 内淡出，并获得焦点但不显示焦点环。被隐藏（删除）的文字只有管理员和作者本人能看到，显示为 60% 的不透明度。被抹除的评论留下一行石墨灰的墓碑文字。

### 队列项
管理中心的标志性组件。复选框、头像，然后是标题行：作者（一个按钮，打开其记录）、账号年龄（新账号用粗体墨色，从不用颜色）、页面、保持在一起的 `#seq · time`，以及推到右边的状态徽标。回复会用一行石墨灰文字显示它回复的评论。接着是举报理由，每个理由一行，带一个信号红的旗标图标、中等字重墨色的理由、一个等宽的 ×N 和举报人。待审核的条目会多一行琥珀色的时钟说明，写明命中了什么。操作行在最后：先是“通过”和“删除”（或“恢复”），让它们在每个条目中位置相同，然后是不常用的操作，然后是“查看上下文”（手机上为图标），每个都带按键提示。键盘焦点所在的条目在其边缘内侧绘制粉色焦点环，并且只在使用精确指针设备时使用烟雾叠色。选中的条目使用淡粉色。在支持 `contain-intrinsic-size: auto` 的浏览器中，屏幕外的条目会跳过布局和绘制。

### 加载、空状态和错误状态
- **加载：** 与内容形状相同的烟雾叠色块（一个 36 px 的圆、一个 40 px 的输入框、几行文字），每 1.6 s 在 100% 和 55% 不透明度之间脉动。在应用加载之前，加载器自己的骨架屏使用 14% 的灰色（rgba(128, 128, 128, 0.14)），这样不依赖令牌也能在浅色和深色页面上看清。
- **空状态：** 居中，上下内边距 48 px，一个 24 px 的石墨灰图标，一行正文加强文字和一行辅助字号的邀请语。
- **错误：** 居中，一个 24 px 的 CloudOff 图标，石墨灰的错误消息（最宽 28em），以及一个次要样式的“重试”按钮。

### 动效
动效只标示状态变化，别无他用。令牌：150 ms（`duration-fast`）、200 ms（`duration-base`）和指数缓出曲线（`cubic-bezier(0.16, 1, 0.3, 1)`）。退出动画更短，并使用缓入。
- **菜单、弹出框、工具提示：** 在 150 ms 内进入，从 98% 的缩放向下移动 2 px 落定。退出时在 100 ms 内淡出。
- **遮罩：** 200 ms 淡入，150 ms 淡出。
- **面板：** 在手机上从底部向上滑出，从 640 px 起从侧边滑入（右侧，在从右到左的界面中为左侧），用时 200 ms，退出用时 150 ms。
- **新评论和轻提示：** 向下滑动 6 px 到位，并在 200 ms 内淡入。
- **点赞：** 爱心在 150 ms 内弹跳一下。
- **永久链接高亮：** 在 2.4 s 的前 60% 保持不变，然后淡出。
- **骨架屏：** 每 1.6 s 脉动一次。**加载指示器：** 每 0.8 s 旋转一圈。
- **颜色变化**（悬停、焦点边框）：150 ms，使用缓出曲线。
- **减弱动态效果：** 每个动画和过渡都降到 1 ms，只运行一次，永久链接高亮变为静态底色。

## 推荐与禁忌

### 推荐：
- **要**从解析后的令牌（`var(--gt-v-*)`，或读取它们的 `gt:` 工具类）获取所有颜色、字号、圆角和时长，绝不直接读取 `--glowthread-*` 属性，这样站点 CSS 就能为两个应用及其浮层更换主题。
- **要**只把填充的粉色留给视图中唯一的主要操作，把基础粉色（#dc6e7e）用于标记：已点赞的爱心、标签页下划线、焦点环和获得焦点的输入框边框。
- **要**用 1 px 的细分隔线分隔各行，并保持扁平。只在方框本身表达含义的地方使用方框（登录提示、提示框、确认）。
- **要**用半透明叠色（4% 和 8% 的黑色，夜间模式下 6% 和 10% 的白色）作为悬停、按下和标签的表面。
- **要**在触摸屏上给每个控件 44 px 的触控目标（`gt-hit` 或 44 px 的方框），并让 ⋯ 菜单始终可见。
- **要**用等宽数字显示计数、楼层号、时间和计数器。
- **要**在浅色模式下用浮层阴影、在夜间模式下用 12% 白色边框抬起浮层，并把它们渲染到浮层容器（portal）中的 450、470 或 480 层级。
- **要**只为状态变化添加动画，时长在 150 到 200 ms 之间，使用指数缓出曲线，并让减弱动态效果可以关闭它。
- **要**用粗体标出新账号，用以文字说明状态的浅底徽标表示状态。
- **要**检查新颜色作为文字是否达到 4.5:1、作为标记是否达到 3:1，在浅色模式和每个夜间开关下都要检查。

### 禁忌：
- **不要**使用渐变、毛玻璃、模糊或发光。
- **不要**添加装饰性动效，或只在悬停时出现的控件。
- **不要**在内联控件或面板就够用的地方打开模态框。只有不可逆的抹除操作会用对话框询问。
- **不要**用无限滚动加载更多评论。保留明确的“加载更多”。
- **不要**给评论区加背景、卡片或外边框。让页面透出来。
- **不要**在悬停或标签上使用不透明的灰色。
- **不要**用红色、琥珀色或绿色填充按钮。破坏性操作使用描边样式和红色文字。
- **不要**把管理中心的链接设为粉色。
- **不要**在触摸屏上给获得焦点的队列项加底色，因为加了底色的第一项看起来像被按下了。
- **不要**加载 Web 字体，也不要从 CDN 或外部服务加载任何东西，包括头像。
- **不要**在发布的代码中使用 `:is()`、`:where()`、`:has()`、`oklch()`、`color-mix()`、层叠层（cascade layers）、范围媒体查询，或单独的 `translate` 和 `scale` 属性，并避免会生成它们的 Tailwind 工具类（`bg-x/50`、`space-*`、`divide-*`、`group-*`、`peer-*`、`has-*`、`translate-*`）。
- **不要**添加 `!important`，那两条用来抵御以 `!important` 编写的站点 CSS 的规则除外（两个应用中的标题字体、管理中心中的段落外边距）。

## 主题与平台

### 主题 API
- GlowThread 从不声明它的公开令牌。由 `client/src/design/tokens.json` 生成的 `client/src/design/tokens.css` 读取每个 `--glowthread-<name>`（浅色）和 `--glowthread-night-<name>`（夜间），以 GlowThread 的默认值作为后备值，把它解析为应用根元素、浮层容器和容器部件上的内部值 `--gt-v-<name>`。因此站点 CSS（MediaWiki:Common.css、皮肤的 CSS 页面、皮肤模块或小工具）可以用任意选择器、按任意顺序设置任何令牌，不需要 `!important`，例如 `:root { --glowthread-color-accent: #3366cc }`，夜间取值则写在 `--glowthread-night-color-accent` 中。[docs/zh-CN/theming.md](docs/zh-CN/theming.md) 是面向站点管理员的指南，包含所有令牌、部件钩子和示例主题。
- 夜间模式在 `html.skin-theme-clientpref-night`（Vector 2022、Citizen、Minerva）下、系统为深色时的 `html.skin-theme-clientpref-os` 下，以及切换类 `ext-glowthread-night` 下切换颜色、浮层层次和头像色调，`$wgGlowThreadTheme = 'night'` 会把这个类添加到 `<html>` 上。`ext-glowthread-day` 强制使用浅色模式。旧版 Vector 自身没有夜间模式。`--glowthread-color-scheme` 设置原生控件和滚动条的配色方案。
- 低于 640 px 时文字字号放大一级：手机上的字号由基础令牌计算得出，除非站点设置了对应的 `-phone` 令牌。在手机上，头像小 0.25rem，轻提示高出 48 px。
- `--glowthread-font-family` 把 `--glowthread-font-cjk` 的 CJK 字体放在拉丁字体之后。它们的默认值跟随 `<html lang>`（`ja`、`ko`、`zh-Hant` 和 `zh-TW`、`zh-Hant-HK` 和 `zh-HK`），其他所有语言使用简体中文字体。站点为 `--glowthread-font-cjk` 设置的值作用于所有语言，站点为 `--glowthread-font-family` 设置的值会替换整个字体栈。
- 在应用内部，令牌通过 `@theme inline` 变成带 `gt:` 前缀的 Tailwind 工具类（`gt:text-fg`、`gt:bg-surface-subtle`、`gt:rounded-control`、`gt:shadow-overlay`），它们读取 `--gt-v-*` 值。间距是 `--glowthread-space`（默认 4 px）的倍数，触控目标不在这个网格上，保持 44 px，`gt:sm:`（640 px）是唯一的断点。
- 每条规则都在 `all: revert` 重置之后限定在 `.gt-scope`（应用根元素和浮层容器 `#glowthread-portal`）范围内，带有两个 ID 的优先级，任何只带一个 ID 的皮肤规则都无法超过。站点 CSS 通过稳定的钩子 `ext-glowthread-<part>` 和 `data-glowthread-*` 到达各个部件：在容器部件上设置令牌，用带三个 ID 的选择器设置普通属性。评论区容器是带有 `ext-glowthread` 类的 `#glowthread`。站点 CSS 可以放置它，应用挂载在它的一个子元素中。

### 从右到左和混合文字
- 从右到左的界面语言（`<html>` 上的 `dir="rtl"`）会镜像两个应用：ResourceLoader 用 CSSJanus 翻转它们的样式表，所以源 CSS 用物理方向的 left 和 right 编写，从不需要第二份副本。关键帧名称从不包含“left”或“right”，如果翻转会改变某个选择器或动画名称，`pnpm check` 就会失败。
- 沿阅读方向指向的图标（V 形图标、箭头、回复转角图标、发送图标）带有 `gt-flip-rtl`，它会在 `:root[dir="rtl"]` 下镜像这些图标，因为 CSSJanus 无法作用到 SVG 内部。
- 菜单、标签页、单选组和表情网格从 `<html dir>` 获取方向，所以方向键的移动方向与读者的阅读方向一致。
- 评论正文、名字和说明都是 `dir="auto"`，采用其自身文字的方向。评论正文只和它的文字一样宽，所以在从右到左的界面中，一条简短的从左到右书写的评论仍然紧挨着它的头像。评论文字中的链接是 `dir="ltr"`，所以 URL 在从右到左的文字中保持原有顺序。
- 超过一周的日期显示为用户语言的短日期，使用公历，并使用 MediaWiki 为该语言设定的数字。

### 无障碍底线
- 在每个支持的皮肤上，浅色和夜间模式都达到 WCAG 2.2 AA，对比度数值见“颜色”一节。
- 所有控件使用同一种焦点环：2 px 实线强调粉，位于外侧 2 px（菜单项、队列项和列表选项则在内侧）。用鼠标或触摸获得焦点的按钮不绘制焦点环。应用为了引导定位而聚焦的元素（删除后的标题、被链接的评论）不显示焦点环。
- 44 px 的触控目标，⋯ 菜单始终可见，每个操作都是可以用触摸和键盘操作的真正按钮。
- 管理中心的键盘快捷键（j、k、x、a、d、r、/、?、Esc）不会在输入框中、输入法组字时、按着修饰键时、对话框和菜单中触发。按 Ctrl 或 ⌘ + Enter 发送评论。按 F8 跳到最新的轻提示。
- 轻提示通过在第一条轻提示出现之前就已存在的实时区域播报，错误以 assertive 方式播报。相对时间附带精确时间的文本，供屏幕阅读器读取。
- 处处遵循减弱动态效果设置。在强制颜色模式下，管理中心的复选框回退为系统控件。
- 使用默认令牌时，除了 12 px 的徽标、小头像中 12 px 的字母和 11 px 的按键提示，文字在桌面上从不小于 12.5 px，在手机上从不小于 13 px，以保证 CJK 字符清晰可读。

### 浏览器底线
- Chrome 和 Android WebView 87，Safari 和 iOS 14，Firefox 78。
- 发布的 CSS 避免使用 `:is()`、`:where()`、`:has()`、`oklch()`、`color-mix()`、层叠层、范围媒体查询和单独的变换属性。只要出现其中任何一个，`pnpm check` 就会失败。源码中的颜色写成十六进制或 `rgba()`，在打包产物中写成 8 位十六进制。
- 优雅降级，不用 polyfill：不支持 flex `gap`（Safari 14.1）时间距变紧，不支持 `:focus-visible`（Safari 15.4）时回退为浏览器自带的焦点环，`text-autospace`（Chrome 140）只在支持的浏览器中添加中西文之间的间距，`content-visibility` 只在支持 `contain-intrinsic-size: auto` 时才应用。
- 新版 CSS 能完成的布局和尺寸计算改由脚本完成：管理中心的宽度断点用 ResizeObserver（容器查询需要 Chrome 105），自动增高的文本框通过测量实现（`field-sizing` 需要 Chrome 123）。

## 设计依据

上述规则隐含但没有解释的决定：
- **粉色用在哪里。** 粉色标出可以操作的东西或当前项。这不只包括主按钮：深玫瑰文字（评论正文中的链接和提及、读者端的内联文字操作、“置顶”徽标、光标）、选区、选中的复选框和单选框、获得焦点的输入框边框、管理中心的新内容标签及其选中条目的底色。管理中心的链接保持中性（中性链接规则），所以管理中心中的粉色表示焦点、选中、已勾选的控件、标签页下划线和保存。读者端在评论正文的链接上保留粉色。
- **描边样式的队列操作。** 队列同时显示许多条目，所以没有哪一个“通过”按钮是视图中唯一的主要操作。“通过”、“删除”和“恢复”是描边的次要按钮和危险按钮。在管理中心，填充的粉色只在保存设置或敏感词时出现。
- **自有字体栈。** 两个应用在应用根元素上设置自己的系统字体栈，并强制用于标题，所以每种皮肤显示的字体都一样。拉丁字体在前，所以拉丁字母和数字保持系统界面的字形。CJK 字体跟随读者的语言，所以同一个汉字会按读者期望的日文、韩文或中文写法绘制。
- **作用域。** 每条规则都带 `gt:` 前缀，限定在 `.gt-scope` 下。`ext-glowthread` 只是容器的类。应用挂载在容器的一个子元素中，所以限定作用域的重置从不影响容器，用来放置容器的站点 CSS 会一直有效。
- **固定值。** 间距在控件内部增加了间距单位的半步（默认为 6 和 10 px）。颜色、字号、圆角、界面的时长和缓动以及层级都是令牌，包括 12 px 的徽标文字（`font-size-small`）、13 px 的代码字体（`font-size-code`）和 4 px 的标记圆角（`radius-mark`）。固定的有：44 px 的触控目标（无障碍底线）、11 px 的按键提示、大头像中 15 px 的字母、表情选择器中 20 px 的表情、圆形的单选按钮、确切时间提示焦点区域 3 px 的圆角、8 px 的滚动条滑块，永久链接渐隐（2.4 s）、骨架屏闪烁（1.6 s）、加载图标（0.8 s）和提示延迟（300 ms）的时长，640、720 和 1080 px 的断点，表情选择器、抹除对话框和设置视图的宽度，手机上粘性栏距底部 72 px 的高度、管理中心选择复选框白色或墨色的勾选标记，以及加载器骨架屏的灰色。设置标题由标题令牌派生，因此会跟随它变化。
- **夜间的徽标底色。** “置顶”徽标使用 `accent-badge`（夜间为 16% 的粉色），而不是 `accent-subtle`，这样它的文字在页面和面板上都能达到 4.5:1。一项 axe 无障碍测试会在每种皮肤中检查页面上的一条置顶评论，以确保这一点。
