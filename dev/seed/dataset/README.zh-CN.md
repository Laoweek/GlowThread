[English](README.md) | 简体中文

# 开发用维基的 Civil Comments 子集

`dev/seed/content/civil-comments.json` 为开发用维基的页面“Kitchen talk”提供 414 条真实评论（324 个评论串）。它们让一个页面具有真实评论区的数量和形态：长短不一的评论、回复、回复的回复、不均匀的点赞数和集中爆发的活跃期。本文件夹中的 `build.py` 从公开来源生成这个文件，相同的输入总是生成相同的文件。

这些评论是读者在 2015 年到 2017 年间，通过 Civil Comments 平台在美国和加拿大的地方新闻网站上发表的公开评论。这个子集保留关于食物和烹饪的评论串：餐馆、食谱、市场、咖啡、啤酒、烘焙、食品价格和本地美食活动。其中的观点属于原评论者本人。拼写和错别字均保持原样。

署名：

> Contains comments from the Civil Comments archive (Civil Research Data, figshare, doi:10.6084/m9.figshare.7376747, CC0 1.0) with toxicity labels from Jigsaw/Google (CC0 1.0).

中文释义：包含来自 Civil Comments 存档（Civil Research Data，figshare，doi:10.6084/m9.figshare.7376747，CC0 1.0）的评论，以及来自 Jigsaw/Google 的毒性标签（CC0 1.0）。

## 来源和许可证

| 来源 | 提供的内容 | 许可证 |
|---|---|---|
| Civil Comments 存档，[doi:10.6084/m9.figshare.7376747.v1](https://doi.org/10.6084/m9.figshare.7376747.v1)，由 Civil Research Data 发布 | 评论文本、评论 ID 和父评论 ID、文章 ID、时间、审核评级、点赞数。不含用户 ID。 | CC0 1.0，依据 https://api.figshare.com/v2/articles/7376747 中文章元数据的声明 |
| Hugging Face 数据集 [google/civil_comments](https://huggingface.co/datasets/google/civil_comments/tree/f2970eb3a55777454c94069077cc8d9b5866312d)，修订版本 `f2970eb3a55777454c94069077cc8d9b5866312d` | Jigsaw/Google 为同一批评论给出的毒性分数，以评论文本为键 | CC0 1.0，依据数据集卡片中的声明 |

CC0 1.0: https://creativecommons.org/publicdomain/zero/1.0/

`build.py` 下载这些文件，并根据公布的大小和校验和逐一检查（figshare 公布 MD5，Hugging Face 公布 SHA-256）：

| 文件 | URL | 字节数 | 校验和 |
|---|---|---:|---|
| `bucket1.json` | https://ndownloader.figshare.com/files/13639208 | 179399009 | MD5 `dcf196edea684eb3e2c51bd292f44af4` |
| `bucket2.json` | https://ndownloader.figshare.com/files/13639202 | 179666304 | MD5 `1a9a4d8dddf9ee0ff0b95e773f4f7c88` |
| `bucket3.json` | https://ndownloader.figshare.com/files/13639196 | 189440200 | MD5 `74a07fe55cf1e0dd20ef25d79da9ce08` |
| `bucket4.json` | https://ndownloader.figshare.com/files/13639205 | 180695600 | MD5 `7c6ce921b081940888039b520e7f57a7` |
| `bucket5.json` | https://ndownloader.figshare.com/files/13639199 | 187283884 | MD5 `5cd4bfb089cc9eb068891e5d3ea449c0` |
| `train-00000-of-00002.parquet` | https://huggingface.co/datasets/google/civil_comments/resolve/f2970eb3a55777454c94069077cc8d9b5866312d/data/train-00000-of-00002.parquet | 193527147 | SHA-256 `c20f01c3aecbdd942886cacb0ee67e995df33bc712a622fde75927ed3d6ccefe` |
| `train-00001-of-00002.parquet` | https://huggingface.co/datasets/google/civil_comments/resolve/f2970eb3a55777454c94069077cc8d9b5866312d/data/train-00001-of-00002.parquet | 186778090 | SHA-256 `a76af69d7b24758bc38b196bc422d4767935c1dd7d3c1465554649c546d99ccb` |
| `validation-00000-of-00001.parquet` | https://huggingface.co/datasets/google/civil_comments/resolve/f2970eb3a55777454c94069077cc8d9b5866312d/data/validation-00000-of-00001.parquet | 20955569 | SHA-256 `2e0eb65474e7e1290df8689fc93eea783160c898c09ae15382c0a9662367bd03` |
| `test-00000-of-00001.parquet` | https://huggingface.co/datasets/google/civil_comments/resolve/f2970eb3a55777454c94069077cc8d9b5866312d/data/test-00000-of-00001.parquet | 20800265 | SHA-256 `410f803ff161e7c4ba005db7de5b444cac413f4861bf056b0fd69d670d75aa08` |

## 如何挑选子集

`build.py` 有两个食物词表。强词表列出菜肴、饮品和就餐场所（restaurant、recipe、coffee、bread、soup、pizza 等）。宽词表另外加入 food、eat、meal、price、drink 和 fresh 这类日常词。

1. **文章。** 一篇文章入选的条件是：至少有 8 条已通过审核的评论，其中至少 40% 含有强词表中的食物词，至多 25% 含有政治词。
2. **评论。** 这类文章下的评论只有满足以下所有条件才会保留：
   * 审核评级为 `approved`，
   * 毒性标签已知且较低：toxicity（毒性）和 insult（侮辱）低于 0.1，identity attack（身份攻击）、obscene（淫秽）、threat（威胁）和 sexual explicit（露骨性内容）低于 0.05（标签按完全相同的文本关联，一段文本有多组标签时以最差的一组为准），
   * 解码 HTML 实体并统一换行符之后，长度不超过 1000 个字符，
   * 不含 URL、电子邮件地址、`@` 或 `#` 账号名、电话号码、街道地址或 HTML 标签，
   * 不含 `build.py` 中屏蔽词表里的词：政治、宗教、犯罪、健康和饮食，关于新闻文章本身的词（article、author、columnist、“LW”等），在这个存档中常与食物话题相邻的离题内容（渔业、狩猎、体育、手机），以及少量夏威夷语和皮钦语词汇。
3. **评论串。** 一个评论串由一条含有强词表食物词的顶层评论及其回复组成。只有当上方的每条评论都保留时，回复才会保留。一个评论串中至少一半的评论必须含有宽词表中的词。
4. **人工审核。** 每条候选评论都经过阅读。`build.py` 中的 `EXCLUDED` 列表按评论 ID 移除以下内容：点名或能识别出真实人物（其他评论者、记者、店主、亲属）的，讨论政治、宗教、犯罪或健康和饮食说法的，以负面方式谈论某家具体真实商家的，给出街道地址的，只有对照新闻文章才能读懂的，离题的，使用其他语言的，或者因其他原因不适合作为公开测试数据的。被移除评论的回复也一并移除。重新运行会应用同一个列表，所以能准确重现审核后的输出。
5. **重复。** 文本与之前某条评论重复的评论会被丢弃（当前构建中没有）。

| 步骤 | 评论数 |
|---|---:|
| 整个存档 | 1,806,317 |
| 248 篇入选文章上的评论 | 5,141 |
| 经过筛选后的候选评论，分属 823 个评论串 | 1,152 |
| 人工审核后保留的评论，分属 118 篇文章的 324 个评论串 | 414 |

在入选文章上，各项筛选丢弃的评论数：审核评级 206，毒性标签 920，无标签 4，URL 90，账号名 17，HTML 4，街道地址 1，政治 261，宗教 73，犯罪 145，健康 391，依赖文章上下文 202，离题 121，其他语言 9。另有 1,545 条评论通过了筛选，但不在食物评论串中。

人工审核按 ID 移除了 537 条评论：商家 188，上下文 95，人物 67，健康 54，离题 42，政治 42，不合适 30，犯罪 6，其他语言 6，宗教 4，街道地址 3。它们的 201 条回复也一并移除。

## 映射到种子格式

* **作者。** 存档中没有用户 ID，所以每条评论根据其 ID 的哈希值分配作者：大约十分之六分给普通成员（Alice、Bob、Carol、Dave、Erin、Trent），其余分给位于 `ip:198.51.100.110-119`、`ip:203.0.113.110-119` 和 `ip:2001:db8::110-119` 的匿名读者。回复的作者永远不会与其父评论相同。Admin、Moderator、Newbie、Mallory 和被封禁的账号从不出现。
* **嵌套。** 顶层评论、回复和回复的回复保持原来的位置。更深层的回复会上移到它的第二层祖先之下，并以 `@Name` 开头，即它所回复评论的作者（总是成员，所以提及能够解析）。
* **点赞。** 使用 Civil Comments 的点赞数，上限为作者以外的成员人数，按取决于评论 ID 的顺序由成员依次填充。
* **时间。** 原始时间保持顺序和相对间隔，缩放到最近 360 天内，最新的评论为 3 小时前。两天及以上的时间取整到整小时（反正种子程序会加上几小时的随机偏移），然后在需要保持原始顺序、或需要让回复晚于父评论时减去一分钟。
* **引用。** 每条评论都带有 `"ref": "cc-<Civil Comments id>"`，所以可以追溯到存档和审核列表。
* 没有置顶、状态或举报。

`build.py` 在写入之前检查结果（作者、长度、URL、提及、点赞、时间、嵌套），发现任何问题就停止。

## 重新构建

```sh
uv run --no-project --with pyarrow==26.0.0 python -I dev/seed/dataset/build.py --cache ~/.cache/glowthread-civil-comments
```

第一次运行会向缓存文件夹下载约 1.3 GB。之后每次运行约需四分钟。输出为 `dev/seed/content/civil-comments.json`。当前文件的 SHA-256 为 `35482195d6ea4b719e0928385b2a6f2e70579171fda4d780dfac306f4de89c7c`，重新构建会得到相同的字节（已用 Python 3.14 和 pyarrow 26.0.0 测试）。

`--candidates FILE` 还会写出每个候选评论串及其审核决定，用于检查或扩展审核。如果筛选条件改变，候选评论也会改变，其中一些还没有人读过。这时构建会停止，因为 `REVIEWED_CANDIDATES`（已审核候选 ID 的哈希值）不再匹配。请阅读新的候选评论，扩展 `EXCLUDED`，然后更新哈希值。如果某个被排除的 ID 不再是候选评论，构建也会停止。
