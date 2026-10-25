[English](README.md) | 简体中文

# 开发用维基的种子内容

这个文件夹存放用于填充 GlowThread 开发用维基的页面和评论：一个小型的虚构社区食谱。除 `civil-comments.json` 外，所有内容都是为本仓库创作的原创文字。食谱、成员和对话都是虚构的，没有抄自任何网站、书籍或真实的维基。`civil-comments.json` 收录了来自一个 CC0 存档的真实公开评论（见最后一节）。这些内容与仓库的其余部分采用相同的许可证发布。

链接只指向 `example.com`、`example.org` 和 `example.net`，以及用于垃圾信息测试用例的 `spam.example`。匿名作者使用文档专用地址段中的地址（`198.51.100.0/24`、`203.0.113.0/24` 和 `2001:db8::/32`）。

## 文件

| 文件 | 内容 |
|---|---|
| `pages.json` | 页面列表：标题、wikitext 文件和评论状态（`open`、`locked` 或 `closed`）。 |
| `pages/*.wiki` | 用纯核心 wikitext 写成的页面文本：Main Page、九个食谱、`Kitchen talk`、中文食谱 `番茄炒蛋`、`Sandbox` 和 `Sandbox/i18n`。 |
| `comments.json` | 食谱页面上约 200 条评论和回复，以页面标题为键。其中包括提问、更正、小技巧、点赞、举报、一条置顶公告、一条待审核评论、一条已删除评论和垃圾信息。 |
| `civil-comments.json` | 供 `Kitchen talk` 使用的 414 条真实公开评论，来自 CC0 Civil Comments 存档，经人工挑选和审核（见最后一节）。 |
| `fragments.json` | 简短的句子片段（`openers`、`bodies`、`closers`、`replies`），种子代码把它们组合成一个有数百条评论的热闹页面。 |
| `i18n.json` | 仅用于 `Sandbox/i18n` 的测试评论：中文、日文、韩文、阿拉伯文、希伯来文、混合书写方向的文本、表情符号序列、越南语声调符号、天城文组合字符、一个很长的不间断字符串，以及一条达到长度上限的评论。除中文演示页面 `番茄炒蛋` 和 `zh-hans.json` 外，这是种子数据中唯一的非英文文本。 |
| `zh-hans.json` | 供中文演示页面 `番茄炒蛋` 使用的虚构中文评论：回复、点赞、@提及、表情符号、一个链接、一条置顶公告和举报。中文文档的截图使用这个页面。 |
| `flowthread.json` | 供 FlowThread 旧版模式使用的虚构评论，用于导入测试。它们使用少量 wikitext（粗体、斜体、链接和换行）。 |

## 有特殊用途的页面

* `Sourdough starter` 是评论种类最丰富的展示页面。
* `Buttermilk pancakes` 已锁定。它的评论仍然可见，但没有人能添加新评论。
* `Cold brew coffee` 已关闭，所以它的评论被隐藏。
* `Pickled red onions` 没有评论，用来展示空状态。
* `番茄炒蛋` 是一个中文食谱，带有中文讨论，用于中文文档的截图。
* `Sandbox` 供测试脚本使用，初始没有评论。

评论作者是开发用维基的固定测试账号（Admin、Moderator、Alice、Bob、Carol、Dave、Erin、Newbie、Mallory 和 Trent）、只在 `番茄炒蛋` 上发表评论的八个中文演示账号（老陈、麦穗、林小满、阿芳、橘子汽水、豆豆不吃豆、养生小贴士和闲置转让），或匿名地址。`45d` 或 `3h` 这样的时间相对于种子填充的时间，每段文字最多 1000 个 Unicode 码位。

## Civil Comments 子集

`civil-comments.json` 是这里唯一不是原创文字的文件。它收录了 `Kitchen talk` 页面的 414 条真实公开评论，这个页面是一般性的美食讨论，具有真实的评论量和评论串结构。这些评论来自 Civil Comments 存档，并经过人工审核。`dev/seed/dataset/README.md` 说明了挑选方法、许可证以及如何重新构建这个文件。

Contains comments from the Civil Comments archive (Civil Research Data, figshare, doi:10.6084/m9.figshare.7376747, CC0 1.0) with toxicity labels from Jigsaw/Google (CC0 1.0).

中文释义：包含来自 Civil Comments 存档（Civil Research Data，figshare，doi:10.6084/m9.figshare.7376747，CC0 1.0）的评论，以及来自 Jigsaw/Google 的毒性标签（CC0 1.0）。
