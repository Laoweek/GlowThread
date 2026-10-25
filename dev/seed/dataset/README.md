English | [简体中文](README.zh-CN.md)

# Civil Comments subset for the dev wiki

`dev/seed/content/civil-comments.json` holds 414 real comments (324 threads) for the dev wiki page "Kitchen talk". They give one page the volume and shape of a real comment section: long and short comments, replies, replies to replies, uneven likes and bursts of activity. `build.py` in this folder makes the file from public sources, and the same inputs always give the same file.

The comments are public comments that readers posted on regional news sites in the United States and Canada between 2015 and 2017, through the Civil Comments platform. This subset keeps threads about food and cooking: restaurants, recipes, markets, coffee, beer, baking, food prices and local food events. The opinions are the original commenters' own. Typos and spelling are kept as written.

Attribution:

> Contains comments from the Civil Comments archive (Civil Research Data, figshare, doi:10.6084/m9.figshare.7376747, CC0 1.0) with toxicity labels from Jigsaw/Google (CC0 1.0).

## Sources and license

| Source | What it gives | License |
|---|---|---|
| Civil Comments archive, [doi:10.6084/m9.figshare.7376747.v1](https://doi.org/10.6084/m9.figshare.7376747.v1) by Civil Research Data | Comment text, comment and parent ids, article ids, times, moderation rating, like counts. No user ids. | CC0 1.0, as stated in the article metadata at https://api.figshare.com/v2/articles/7376747 |
| Hugging Face dataset [google/civil_comments](https://huggingface.co/datasets/google/civil_comments/tree/f2970eb3a55777454c94069077cc8d9b5866312d), revision `f2970eb3a55777454c94069077cc8d9b5866312d` | Toxicity scores from Jigsaw/Google for the same comments, keyed by comment text | CC0 1.0, as stated in the dataset card |

CC0 1.0: https://creativecommons.org/publicdomain/zero/1.0/

`build.py` downloads these files and checks each one against its published size and checksum (figshare publishes MD5, Hugging Face publishes SHA-256):

| File | URL | Bytes | Checksum |
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

## How the subset is chosen

`build.py` has two food word lists. The strong list names dishes, drinks and places to eat (restaurant, recipe, coffee, bread, soup, pizza and so on). The broad list adds everyday words such as food, eat, meal, price, drink and fresh.

1. **Articles.** An article qualifies when it has at least 8 approved comments, at least 40% of them contain a strong food word and at most 25% contain a politics word.
2. **Comments.** A comment on such an article stays only if all of these hold:
   * its moderation rating is `approved`,
   * its toxicity labels are known and low: toxicity and insult below 0.1, identity attack, obscene, threat and sexual explicit below 0.05 (labels join by exact text, and when a text has several labels the worst one counts),
   * after decoding HTML entities and normalising line ends, it has at most 1000 characters,
   * it has no URL, email address, `@` or `#` handle, phone number, street address or HTML tag,
   * it has no word from the block lists in `build.py`: politics, religion, crime, health and diet, words about the news article itself (article, author, columnist, "LW" and so on), off-topic subjects that sit next to food in this archive (fisheries, hunting, sports, phones), and a few Hawaiian and pidgin words.
3. **Threads.** A thread is a top-level comment with a strong food word and its replies. A reply stays only when every comment above it stays. At least half of a thread's comments must contain a word from the broad food list.
4. **Manual review.** Every candidate comment was read. The `EXCLUDED` list in `build.py` removes, by comment id, anything that names or identifies a real person (other commenters, journalists, owners, relatives), discusses politics, religion, crime, or health and diet claims, speaks about a specific real business in a negative way, gives a street address, only makes sense next to the news article, is off topic, uses another language, or is otherwise not fit for a public test fixture. Replies to a removed comment go too. A rerun applies the same list, so it reproduces the reviewed output exactly.
5. **Repeats.** A comment whose text repeats an earlier one is dropped (none in the current build).

| Step | Comments |
|---|---:|
| Whole archive | 1,806,317 |
| On the 248 qualifying articles | 5,141 |
| Candidates after the filters, in 823 threads | 1,152 |
| Kept after the manual review, in 324 threads from 118 articles | 414 |

On the qualifying articles the filters dropped: rating 206, toxicity labels 920, no labels 4, URL 90, handle 17, HTML 4, street address 1, politics 261, religion 73, crime 145, health 391, article context 202, off topic 121, other language 9. Another 1,545 comments passed the filters but were not in a food thread.

The manual review removed 537 comments by id: business 188, context 95, person 67, health 54, off topic 42, politics 42, unsuitable 30, crime 6, other language 6, religion 4, street address 3. Their 201 replies went with them.

## Mapping to the seed format

* **Authors.** The archive has no user ids, so each comment gets an author from a hash of its id: about 6 in 10 go to the regular members (Alice, Bob, Carol, Dave, Erin, Trent), the rest to anonymous readers at `ip:198.51.100.110-119`, `ip:203.0.113.110-119` and `ip:2001:db8::110-119`. A reply never has the same author as its parent. Admin, Moderator, Newbie, Mallory and the blocked accounts never appear.
* **Nesting.** Top-level comments, replies and replies to replies keep their place. A deeper reply moves up under its second-level ancestor and starts with `@Name`, the author of the comment it answered (always a member, so the mention resolves).
* **Likes.** The Civil Comments like count, capped at the number of members other than the author, filled from the members in an order that depends on the comment id.
* **Ages.** The original times keep their order and relative gaps, scaled into the last 360 days, with the newest comment 3 hours old. Ages from two days up are rounded to whole hours (the seeder adds hours of jitter anyway), then lowered by a minute where that keeps the original order or keeps a reply younger than its parent.
* **References.** Each comment has `"ref": "cc-<Civil Comments id>"`, so it can be traced back to the archive and to the review list.
* No pins, statuses or reports.

`build.py` checks the result before writing it (authors, lengths, URLs, mentions, likes, ages, nesting) and stops on any problem.

## Rebuild

```sh
uv run --no-project --with pyarrow==26.0.0 python -I dev/seed/dataset/build.py --cache ~/.cache/glowthread-civil-comments
```

The first run downloads about 1.3 GB into the cache folder. A run takes about four minutes after that. The output is `dev/seed/content/civil-comments.json`. The current file has SHA-256 `35482195d6ea4b719e0928385b2a6f2e70579171fda4d780dfac306f4de89c7c`, and a rebuild gives the same bytes (tested with Python 3.14 and pyarrow 26.0.0).

`--candidates FILE` also writes every candidate thread with its review decision, for checking or extending the review. If the filters change, the candidates change and some of them have not been read. The build then stops, because `REVIEWED_CANDIDATES` (a hash of the reviewed candidate ids) no longer matches. Read the new candidates, extend `EXCLUDED`, then update the hash. The build also stops when an excluded id is no longer a candidate.
