"""Build dev/seed/content/civil-comments.json from the Civil Comments archive.

The output is the "Kitchen talk" page of the dev wiki: real comment threads about food and
cooking, mapped to the seed content format (see dev/seed/content/README.md). README.md next to
this script describes the source, the filters and the manual review.

    uv run --no-project --with pyarrow==26.0.0 python -I dev/seed/dataset/build.py --cache DIR

The pinned source files (about 1.3 GB) are downloaded into DIR on the first run and checked
against their published checksums on every run. The same inputs always give the same output.
"""

import argparse
import collections
import datetime
import hashlib
import html
import json
import re
import sys
import unicodedata
import urllib.request
from pathlib import Path

PAGE = "Kitchen talk"

# Civil Comments archive (Civil Research Data, figshare, doi:10.6084/m9.figshare.7376747.v1, CC0).
# Name, figshare file id, size, md5 (as published by the figshare API for the article).
BUCKETS = [
    ("bucket1.json", 13639208, 179399009, "dcf196edea684eb3e2c51bd292f44af4"),
    ("bucket2.json", 13639202, 179666304, "1a9a4d8dddf9ee0ff0b95e773f4f7c88"),
    ("bucket3.json", 13639196, 189440200, "74a07fe55cf1e0dd20ef25d79da9ce08"),
    ("bucket4.json", 13639205, 180695600, "7c6ce921b081940888039b520e7f57a7"),
    ("bucket5.json", 13639199, 187283884, "5cd4bfb089cc9eb068891e5d3ea449c0"),
]
FIGSHARE_URL = "https://ndownloader.figshare.com/files/{}"

# Toxicity labels (Jigsaw/Google, Hugging Face dataset google/civil_comments, CC0), pinned to a
# revision. Name, size, sha256 (the Git LFS object id published by Hugging Face).
LABEL_REVISION = "f2970eb3a55777454c94069077cc8d9b5866312d"
LABEL_FILES = [
    ("train-00000-of-00002.parquet", 193527147, "c20f01c3aecbdd942886cacb0ee67e995df33bc712a622fde75927ed3d6ccefe"),
    ("train-00001-of-00002.parquet", 186778090, "a76af69d7b24758bc38b196bc422d4767935c1dd7d3c1465554649c546d99ccb"),
    ("validation-00000-of-00001.parquet", 20955569, "2e0eb65474e7e1290df8689fc93eea783160c898c09ae15382c0a9662367bd03"),
    ("test-00000-of-00001.parquet", 20800265, "410f803ff161e7c4ba005db7de5b444cac413f4861bf056b0fd69d670d75aa08"),
]
LABEL_URL = "https://huggingface.co/datasets/google/civil_comments/resolve/" + LABEL_REVISION + "/data/{}"
LABELS = ["toxicity", "severe_toxicity", "obscene", "threat", "insult", "identity_attack", "sexual_explicit"]

# Article selection: share of approved comments that talk about food, and that talk politics.
ARTICLE_MIN_COMMENTS = 8
ARTICLE_MIN_FOOD = 0.40
ARTICLE_MAX_POLITICS = 0.25
# Thread selection: share of a thread's kept comments with any food word.
THREAD_MIN_FOOD = 0.5
MAX_LENGTH = 1000

# Authors: regular members and anonymous readers from the documentation address ranges.
MEMBERS = ["Alice", "Bob", "Carol", "Dave", "Erin", "Trent"]
ANONYMOUS = (
    [f"ip:198.51.100.{n}" for n in range(110, 120)]
    + [f"ip:203.0.113.{n}" for n in range(110, 120)]
    + [f"ip:2001:db8::{n}" for n in range(110, 120)]
)
MEMBER_SHARE = 6  # out of 10 comments
# Ages: the newest comment is this old, the oldest at most WINDOW old.
NEWEST_MINUTES = 3 * 60
WINDOW_MINUTES = 360 * 24 * 60


def words(pattern):
    return re.compile(r"(?i)\b(?:%s)\b" % pattern)


FOOD_STRONG_WORDS = (
    r"restaurants?|recipes?|cook|cooks|cooking|cooked|chefs?|menus?|dish|dishes|coffee|espresso|lattes?|"
    r"cappuccino|baking|bake|baked|bakery|bakeries|bread|cakes?|pies?|pizzas?|burgers?|hamburgers?|tacos?|"
    r"burritos?|sushi|ramen|pho|noodles?|saimin|dumplings?|curry|curries|soups?|salads?|sandwich\w*|"
    r"desserts?|chocolate|ice cream|gelato|donuts?|doughnuts?|malasadas?|cupcakes?|cookies?|pastr\w+|"
    r"croissants?|bagels?|brunch|breakfast|lunch|dinners?|cafe|cafes|café|diner|diners|bistro|eatery|"
    r"eateries|food trucks?|delicious|tasty|yummy|flavou?rs?|flavou?rful|tastes?|tasted|tasting|foodies?|"
    r"cuisine|kitchen|oven|grill|grilled|barbecue|bbq|smoked|roast\w*|fried|fries|bacon|cheese\w*|butter|"
    r"sauce|spicy|seasoning|vegetables?|veggies?|farmers? market|grocer\w*|bakers?|brewery|breweries|"
    r"brewpub|craft beer|beers?|wines?|cider|cocktails?|teas?|steaks?|poke|musubi|loco moco|plate lunch|"
    r"shave ice|spam musubi|waiters?|waitress\w*|tipping|potluck|snacks?|appetizers?|entrees?|eggs|"
    r"omelett?e?s?|pancakes?|waffles?|fruit|berries|apples?|mangoes?|pineapples?|bananas?|avocados?|"
    r"lemons?|garlic|onions?|tomato(?:es)?|potato(?:es)?|rice|beans|chili|salsa|guacamole|pasta|spaghetti|"
    r"lasagna|meatballs?|chicken|pork|beef|lamb|salmon|halibut|crab|shrimp|oysters?|clams?|seafood|"
    r"turkey|ham|sausages?|hot dogs?"
)
FOOD_WEAK_WORDS = (
    r"food|foods|eat|eats|eating|ate|meal|meals|meat|fish|salt|sugar|sweet|hungry|order|ordered|drink|"
    r"drinks|price|prices|cheap|expensive|portion|portions|fresh|served|serve|serving"
)
FOOD_STRONG = words(FOOD_STRONG_WORDS)
FOOD_ANY = words(FOOD_STRONG_WORDS + "|" + FOOD_WEAK_WORDS)

# Topics that keep a comment out, whatever the context. Broad on purpose: the manual review
# reads what is left, these lists only cut the reading down.
BLOCKED_TOPICS = {
    "politics": words(
        r"trump\w*|clinton\w*|hillary|obama\w*|bernie|sanders|trudeau|harper|wynne|notley|liberals?|"
        r"conservatives?|democrat\w*|republican\w*|gop|dems|leftists?|lefties|lefty|right[- ]wing\w*|"
        r"socialis\w*|communis\w*|capitalis\w*|marxis\w*|fascis\w*|nazi\w*|hitler|vot\w+|elect\w*|ballot|"
        r"senat\w*|congress\w*|legislat\w*|lawmakers?|parliament\w*|putin|russia\w*|govern\w*|governor|"
        r"mayor|council\w*|ndp|tory|tories|racis\w*|racial|politic\w*|president\w*|presidential|tax|taxes|"
        r"taxed|taxpayers?|taxation|unions?|welfare|food stamps?|snap|wic|minimum wage|wages?|regulat\w*|"
        r"subsid\w*|bureaucra\w*|federal|feds|city hall|legislature|campaign\w*|protest\w*|activis\w*|"
        r"feminis\w*|privilege\w*|appropriation|appropriating|colonial\w*|sovereign\w*|immigra\w*|"
        r"refugees?|illegals?|border|deport\w*|aliens?|nationalis\w*|patriot\w*|military|army|war|wars|"
        r"soldiers?|veterans?|terroris\w*|isis|china|chinese government|north korea|iran|israel\w*|"
        r"palestin\w*|mexico wall|brexit|eu|nafta|tariffs?|trade deal|climate|global warming|carbon tax|"
        r"pipelines?|big oil|oil (?:prices?|compan\w+|industry)|pfd|lgbt\w*|gay|gays|lesbian\w*|"
        r"transgender\w*|abortion\w*|pro-?life|pro-?choice|marriage equality|guns?|nra|second amendment|"
        r"constitution\w*|human rights|civil rights|freedom of|free speech|censor\w*|media bias|fake news|"
        r"msm|cbc|fox news|cnn|boycott\w*"
    ),
    "religion": words(
        r"god|gods|jesus|christ|christian\w*|church|churches|catholic\w*|pope|bishop|priest\w*|pastor|"
        r"bible\w*|biblical|scripture\w*|pray\w*|prayer\w*|faith\w*|religio\w*|spiritual\w*|muslim\w*|"
        r"islam\w*|mosque|jew|jews|jewish|judais\w*|kosher|halal|hindu\w*|buddh\w*|sin|sins|sinful|"
        r"heaven\w*|hell|satan\w*|devil|blessed|holy|ramadan|easter|christmas|sabbath|atheis\w*"
    ),
    "crime": words(
        r"crime\w*|criminal\w*|steal\w*|stole|stolen|theft|thie[fv]\w*|robbe\w*|robbed|burglar\w*|"
        r"murder\w*|homicide|arrest\w*|police|cops?|jail\w*|prison\w*|inmates?|convict\w*|felon\w*|drugs?|"
        r"meth|heroin|opioid\w*|fentanyl|cocaine|marijuana|cannabis|pot shops?|weed|stoned|dealer\w*|"
        r"shooting|shooter|stab\w*|assault\w*|rape\w*|abuse\w*|violen\w*|lawsuit\w*|sued|suing|lawyers?|"
        r"attorney\w*|courts?|courtroom|judges?|trial|guilty|fraud\w*|scam\w*|homeless\w*|panhandl\w*|"
        r"vandal\w*|graffiti|gangs?|dui|drunk\w*|intoxicat\w*|alcoholi\w*"
    ),
    "health": words(
        r"health\w*|unhealthy|diet\w*|dieting|diabet\w*|obes\w*|overweight|cancer\w*|carcino\w*|disease\w*|"
        r"gmos?|gmo-free|non-gmo|vaccin\w*|cholesterol|calori\w*|nutrition\w*|nutrient\w*|nutritious|"
        r"allerg\w*|gluten\w*|celiac|lactose|toxic\w*|toxins?|chemicals?|pesticid\w*|herbicid\w*|"
        r"glyphosate|roundup|monsanto|poison\w*|sickness|illness\w*|salmonella|e\.? ?coli|listeria|"
        r"norovirus|food poisoning|hepatitis|doctors?|medical|medicin\w*|hospital\w*|cardio\w*|"
        r"heart attack|stroke|blood pressure|insulin|keto\w*|paleo|vegans?|veganism|vegetarian\w*|carbs?|"
        r"carbohydrat\w*|antioxidant\w*|vitamin\w*|supplement\w*|detox\w*|addict\w*|smoking|smoker\w*|"
        r"cigarett\w*|tobacco|vaping|msg|preservativ\w*|additiv\w*|hormon\w*|antibiotic\w*|weight loss|"
        r"lose weight|obesity|anorex\w*|bulimi\w*|eating disorder\w*|death\w*|dying"
    ),
    # Comments about the news article itself, its author or the advice column's letter writer.
    "context": words(
        r"articles?|authors?|writers?|columnists?|column|reporters?|journalis\w*|editorials?|editors?|"
        r"headlines?|op-?ed|lw\d?|letter writer|this list|the list|this piece|this story|great story|"
        r"the story|adn|g&m|wapo"
    ),
    # Fishery and wildlife management, hunting, sports and gadgets: common next to food words in
    # this archive, but not kitchen talk.
    "offtopic": words(
        r"adf&?g|fisher(?:y|ies)|commercial fish\w*|set ?nets?|gill ?nets?|dip ?net\w*|hatcher(?:y|ies)|"
        r"bycatch|escapement|quotas?|charters?|permits?|licen[cs]es?|hunt\w*|nfl|nba|nhl|mlb|broncos|"
        r"rockies|football|baseball|hockey|playoffs?|quarterback|coach\w*|stadium|iphone|android|"
        r"smartphone\w*|ipad"
    ),
    "nonenglish": words(r"mahalo|aloha|kama.?aina|hanai|da kine"),
}
PERSONAL_DATA = {
    "url": re.compile(r"(?i)https?:|www\.|\b[\w-]+\.(?:com|org|net|ca|gov|edu|us|io|ly|co|info|biz|tv|me|uk|au)\b"),
    "email": re.compile(r"\S+@\S+\.\w+"),
    "handle": re.compile(r"(?<!\w)[@#]\w+"),
    "phone": re.compile(r"\(?\b\d{3}\)?[-.\s]\d{3}[-.\s]\d{4}\b|\b\d{3}[-.]\d{4}\b"),
    "address": re.compile(
        r"\b\d{1,5}\s+(?:[NSEW]\.?\s+)?(?:[A-Z0-9][\w'.-]*\s+){1,4}(?:St|Street|Ave|Avenue|Blvd|Boulevard|Rd|"
        r"Road|Dr|Drive|Way|Ln|Lane|Pl|Place|Hwy|Highway|Pkwy|Parkway|Ct|Court|Loop|Terrace|Circle)\b\.?"
    ),
    "html": re.compile(r"<[a-zA-Z/!][^>]*>"),
}

# Manual review: every comment that passed the automatic filters was read, and these were taken
# out (with their replies). Reasons: person (names or identifies a real person, including other
# commenters and journalists), politics, religion, crime, health (health or diet claims),
# business (a real business in a negative way), address (a street address or exact location),
# context (only makes sense next to the news article), offtopic (not about food), nonenglish,
# unsuitable (crude, demeaning or otherwise not fit for a public test fixture).
# SHA-256 of the reviewed candidate ids (sorted, one per line). The build stops when the filters
# produce a different candidate set, because new candidates have not been read.
REVIEWED_CANDIDATES = "9e0e03b7784364c2f6be2d2988c10cfdfe26619d25e48351f8f4d36bb2c0a51a"
# BEGIN EXCLUDED
EXCLUDED = {
    "247211": "person", "284493": "person", "284611": "person", "284863": "person",
    "286038": "person", "287388": "person", "298276": "person", "298301": "person",
    "298578": "person", "300468": "person", "301007": "person", "310162": "person",
    "314542": "person", "346640": "person", "367785": "person", "378092": "person",
    "384143": "person", "392944": "person", "427043": "person", "439561": "person",
    "439853": "person", "440905": "person", "441231": "person", "441241": "person",
    "442013": "person", "445799": "person", "445888": "person", "453972": "person",
    "454180": "person", "454265": "person", "454633": "person", "504038": "person",
    "506919": "person", "526778": "person", "528323": "person", "711549": "person",
    "748735": "person", "760098": "person", "773055": "person", "919822": "person",
    "974457": "person", "1046085": "person", "4970168": "person", "4970465": "person",
    "4978951": "person", "5199009": "person", "5203727": "person", "5359906": "person",
    "5362000": "person", "5422036": "person", "5522130": "person", "5522912": "person",
    "5529780": "person", "5652418": "person", "5713343": "person", "5716922": "person",
    "5724282": "person", "5744245": "person", "5778095": "person", "5843199": "person",
    "5924498": "person", "5975430": "person", "6102577": "person", "6160999": "person",
    "6162043": "person", "6178126": "person", "6322386": "person",
    "300693": "politics", "320563": "politics", "420297": "politics", "466112": "politics",
    "466849": "politics", "481714": "politics", "563131": "politics", "723532": "politics",
    "729020": "politics", "853733": "politics", "856264": "politics", "1072571": "politics",
    "5069136": "politics", "5136500": "politics", "5137676": "politics", "5142273": "politics",
    "5208233": "politics", "5208396": "politics", "5272468": "politics", "5295340": "politics",
    "5307058": "politics", "5307503": "politics", "5310012": "politics", "5346344": "politics",
    "5425988": "politics", "5440153": "politics", "5726747": "politics", "5738570": "politics",
    "5819945": "politics", "5827935": "politics", "5836590": "politics", "5866850": "politics",
    "6136733": "politics", "6157357": "politics", "6158814": "politics", "6276660": "politics",
    "6277140": "politics", "6279244": "politics", "6280318": "politics", "6280712": "politics",
    "6281983": "politics", "6314974": "politics",
    "462677": "religion", "5320526": "religion", "6138342": "religion", "6148088": "religion",
    "302804": "crime", "388220": "crime", "5650250": "crime", "5666493": "crime",
    "6158411": "crime", "6282803": "crime",
    "304845": "health", "307672": "health", "314403": "health", "364490": "health",
    "379476": "health", "425195": "health", "440727": "health", "526592": "health",
    "561890": "health", "654516": "health", "712213": "health", "712378": "health",
    "769945": "health", "829424": "health", "830006": "health", "830131": "health",
    "881818": "health", "985262": "health", "989531": "health", "1071097": "health",
    "1071295": "health", "1071602": "health", "1071628": "health", "4970010": "health",
    "4971351": "health", "4977232": "health", "5076050": "health", "5103211": "health",
    "5103235": "health", "5106451": "health", "5128391": "health", "5139104": "health",
    "5139189": "health", "5200169": "health", "5207740": "health", "5207915": "health",
    "5208236": "health", "5286054": "health", "5290565": "health", "5291276": "health",
    "5293022": "health", "5372898": "health", "5374018": "health", "5577766": "health",
    "5599122": "health", "5631996": "health", "5691490": "health", "5736342": "health",
    "5868531": "health", "6002811": "health", "6135746": "health", "6183905": "health",
    "6270936": "health", "6317071": "health",
    "284417": "business", "298093": "business", "298908": "business", "302985": "business",
    "303547": "business", "309604": "business", "326675": "business", "341548": "business",
    "346045": "business", "347418": "business", "347593": "business", "354248": "business",
    "354992": "business", "378556": "business", "391664": "business", "407703": "business",
    "407891": "business", "409022": "business", "409866": "business", "421360": "business",
    "423771": "business", "424304": "business", "425628": "business", "425649": "business",
    "425752": "business", "426619": "business", "426723": "business", "438504": "business",
    "439066": "business", "453451": "business", "454271": "business", "465753": "business",
    "527395": "business", "591127": "business", "592248": "business", "663870": "business",
    "664340": "business", "664727": "business", "664904": "business", "686744": "business",
    "686859": "business", "686916": "business", "687404": "business", "688080": "business",
    "688252": "business", "688689": "business", "700162": "business", "700562": "business",
    "700778": "business", "700839": "business", "701016": "business", "701243": "business",
    "701869": "business", "702177": "business", "702239": "business", "702319": "business",
    "702680": "business", "703150": "business", "712702": "business", "713808": "business",
    "713854": "business", "714084": "business", "714405": "business", "715288": "business",
    "795487": "business", "795956": "business", "799864": "business", "828497": "business",
    "973613": "business", "989124": "business", "989572": "business", "989601": "business",
    "990230": "business", "990827": "business", "990882": "business", "994667": "business",
    "1021203": "business", "1023685": "business", "1023959": "business", "1024199": "business",
    "1024410": "business", "1024950": "business", "1071849": "business", "1072173": "business",
    "4974844": "business", "5001210": "business", "5002168": "business", "5002751": "business",
    "5002873": "business", "5003706": "business", "5003715": "business", "5004161": "business",
    "5004269": "business", "5004384": "business", "5006229": "business", "5008307": "business",
    "5008421": "business", "5008634": "business", "5008870": "business", "5008952": "business",
    "5009211": "business", "5009241": "business", "5009334": "business", "5009853": "business",
    "5011281": "business", "5011423": "business", "5015419": "business", "5015834": "business",
    "5016762": "business", "5017234": "business", "5018365": "business", "5019089": "business",
    "5062842": "business", "5074634": "business", "5075894": "business", "5080046": "business",
    "5080664": "business", "5081345": "business", "5081620": "business", "5081887": "business",
    "5081910": "business", "5082860": "business", "5083716": "business", "5086892": "business",
    "5095750": "business", "5140609": "business", "5203127": "business", "5236326": "business",
    "5237040": "business", "5239441": "business", "5239550": "business", "5241649": "business",
    "5291873": "business", "5299346": "business", "5318642": "business", "5325581": "business",
    "5328800": "business", "5332996": "business", "5333682": "business", "5335185": "business",
    "5339942": "business", "5340381": "business", "5340946": "business", "5343245": "business",
    "5361273": "business", "5361294": "business", "5361875": "business", "5362207": "business",
    "5363555": "business", "5364043": "business", "5365235": "business", "5365761": "business",
    "5422905": "business", "5426226": "business", "5426424": "business", "5426583": "business",
    "5439850": "business", "5441635": "business", "5442190": "business", "5445194": "business",
    "5515334": "business", "5519084": "business", "5519211": "business", "5522520": "business",
    "5522646": "business", "5522717": "business", "5533281": "business", "5573279": "business",
    "5591463": "business", "5598022": "business", "5599297": "business", "5715916": "business",
    "5763126": "business", "5818904": "business", "5913296": "business", "5913818": "business",
    "6003939": "business", "6144683": "business", "6174993": "business", "6175011": "business",
    "6175058": "business", "6175106": "business", "6175945": "business", "6182896": "business",
    "6221933": "business", "6222148": "business", "6229459": "business", "6276562": "business",
    "438769": "address", "531659": "address", "748528": "address",
    "241379": "context", "242369": "context", "245271": "context", "266257": "context",
    "303645": "context", "330964": "context", "341717": "context", "342130": "context",
    "342197": "context", "349068": "context", "360881": "context", "368139": "context",
    "369208": "context", "388551": "context", "388875": "context", "423867": "context",
    "423916": "context", "425018": "context", "425076": "context", "425270": "context",
    "425617": "context", "439555": "context", "439977": "context", "450953": "context",
    "453282": "context", "455093": "context", "529166": "context", "654391": "context",
    "655699": "context", "712785": "context", "729773": "context", "729916": "context",
    "745368": "context", "753310": "context", "768454": "context", "769272": "context",
    "773347": "context", "831538": "context", "945157": "context", "954594": "context",
    "1044049": "context", "1044760": "context", "4974061": "context", "4981004": "context",
    "4983555": "context", "5001259": "context", "5034626": "context", "5035145": "context",
    "5094448": "context", "5131175": "context", "5200031": "context", "5209637": "context",
    "5240285": "context", "5270831": "context", "5301778": "context", "5309245": "context",
    "5313767": "context", "5333335": "context", "5365267": "context", "5402152": "context",
    "5425440": "context", "5466860": "context", "5484784": "context", "5515533": "context",
    "5516303": "context", "5519451": "context", "5526842": "context", "5527054": "context",
    "5527333": "context", "5592627": "context", "5607324": "context", "5660569": "context",
    "5765372": "context", "5766236": "context", "5776381": "context", "5777102": "context",
    "5781958": "context", "5828683": "context", "5835180": "context", "5839756": "context",
    "5939896": "context", "5951929": "context", "5977253": "context", "5982623": "context",
    "6144505": "context", "6172331": "context", "6176633": "context", "6263305": "context",
    "6309680": "context", "6311634": "context", "6311651": "context", "6313432": "context",
    "6313588": "context", "6316978": "context", "6317046": "context",
    "265137": "offtopic", "266103": "offtopic", "270253": "offtopic", "355506": "offtopic",
    "392104": "offtopic", "392607": "offtopic", "393366": "offtopic", "451993": "offtopic",
    "480761": "offtopic", "484379": "offtopic", "768757": "offtopic", "770866": "offtopic",
    "792877": "offtopic", "796429": "offtopic", "797546": "offtopic", "855342": "offtopic",
    "855741": "offtopic", "858571": "offtopic", "948967": "offtopic", "954536": "offtopic",
    "960292": "offtopic", "1036215": "offtopic", "5037283": "offtopic", "5124256": "offtopic",
    "5133499": "offtopic", "5297086": "offtopic", "5298668": "offtopic", "5314017": "offtopic",
    "5314513": "offtopic", "5366560": "offtopic", "5366860": "offtopic", "5367094": "offtopic",
    "5372684": "offtopic", "5374801": "offtopic", "5438263": "offtopic", "5567117": "offtopic",
    "5714521": "offtopic", "5824702": "offtopic", "5834577": "offtopic", "5840673": "offtopic",
    "5935740": "offtopic", "5942769": "offtopic",
    "1073897": "nonenglish", "5069266": "nonenglish", "5074895": "nonenglish",
    "5276404": "nonenglish", "5528639": "nonenglish", "5834729": "nonenglish",
    "309377": "unsuitable", "354960": "unsuitable", "388272": "unsuitable", "423908": "unsuitable",
    "441373": "unsuitable", "452428": "unsuitable", "453800": "unsuitable", "551190": "unsuitable",
    "563702": "unsuitable", "704180": "unsuitable", "708012": "unsuitable", "757103": "unsuitable",
    "773231": "unsuitable", "851560": "unsuitable", "1060735": "unsuitable",
    "1072805": "unsuitable", "5136534": "unsuitable", "5200001": "unsuitable",
    "5203008": "unsuitable", "5312417": "unsuitable", "5320393": "unsuitable",
    "5440157": "unsuitable", "5523318": "unsuitable", "5610723": "unsuitable",
    "5865665": "unsuitable", "5947621": "unsuitable", "6144685": "unsuitable",
    "6157543": "unsuitable", "6172990": "unsuitable", "6218761": "unsuitable",
}
# END EXCLUDED


def fail(message):
    sys.exit(f"build.py: {message}")


def digest(path, algorithm):
    h = hashlib.new(algorithm)
    with open(path, "rb") as f:
        for block in iter(lambda: f.read(1 << 20), b""):
            h.update(block)
    return h.hexdigest()


def fetch(cache, name, url, size, algorithm, expected):
    """Download url into cache/name unless it is there, and check its size and checksum."""
    path = cache / name
    if not path.exists():
        part = path.with_suffix(path.suffix + ".part")
        print(f"downloading {url}", file=sys.stderr)
        with urllib.request.urlopen(url) as response, open(part, "wb") as out:
            while block := response.read(1 << 20):
                out.write(block)
        part.rename(path)
    if path.stat().st_size != size:
        fail(f"{path} has {path.stat().st_size} bytes, expected {size}. Delete it to download again.")
    actual = digest(path, algorithm)
    if actual != expected:
        fail(f"{path} {algorithm} is {actual}, expected {expected}. Delete it to download again.")
    return path


def clean_text(text):
    text = html.unescape(text)
    text = text.replace("\r\n", "\n").replace("\r", "\n")
    text = unicodedata.normalize("NFC", text)
    text = "\n".join(line.rstrip() for line in text.split("\n"))
    text = re.sub(r"\n{3,}", "\n\n", text)
    return text.strip()


def labels_ok(labels):
    return (
        labels is not None
        and labels["toxicity"] < 0.1
        and labels["insult"] < 0.1
        and labels["identity_attack"] < 0.05
        and labels["obscene"] < 0.05
        and labels["threat"] < 0.05
        and labels["sexual_explicit"] < 0.05
    )


def filter_reason(row, labels):
    """Why the automatic filters drop a comment, or None."""
    if row["rating"] != "approved":
        return "rating"
    if not labels_ok(labels.get(row["text"])):
        return "toxicity labels" if row["text"] in labels else "no labels"
    text = clean_text(row["text"])
    if not text:
        return "empty"
    if len(text) > MAX_LENGTH:
        return "length"
    for name, pattern in PERSONAL_DATA.items():
        if pattern.search(text):
            return name
    for name, pattern in BLOCKED_TOPICS.items():
        if pattern.search(text):
            return name
    return None


def read_buckets(paths):
    for path in paths:
        with open(path, encoding="utf-8") as f:
            for line in f:
                if line.strip():
                    yield json.loads(line)


def select_articles(paths):
    approved = collections.Counter()
    food = collections.Counter()
    politics = collections.Counter()
    for row in read_buckets(paths):
        if row["rating"] == "approved":
            article = row["article_id"]
            approved[article] += 1
            food[article] += bool(FOOD_STRONG.search(row["text"]))
            politics[article] += bool(BLOCKED_TOPICS["politics"].search(row["text"]))
    return {
        a for a, n in approved.items()
        if n >= ARTICLE_MIN_COMMENTS and food[a] / n >= ARTICLE_MIN_FOOD and politics[a] / n <= ARTICLE_MAX_POLITICS
    }


def load_labels(paths, texts):
    import pyarrow.parquet as pq

    labels = {}
    for path in paths:
        for batch in pq.ParquetFile(path).iter_batches(columns=["text"] + LABELS, batch_size=50000):
            columns = batch.to_pydict()
            for i, text in enumerate(columns["text"]):
                if text in texts:
                    row = {name: columns[name][i] for name in LABELS}
                    if text in labels:  # the same text labelled twice: keep the worse scores
                        row = {name: max(row[name], labels[text][name]) for name in LABELS}
                    labels[text] = row
    return labels


def parse_time(value):
    return datetime.datetime.strptime(value.replace(" UTC", "").split(".")[0], "%Y-%m-%d %H:%M:%S")


def stable(*parts):
    return int.from_bytes(hashlib.sha256(":".join(map(str, parts)).encode()).digest()[:8], "big")


def format_age(minutes):
    if minutes % 1440 == 0:
        return f"{minutes // 1440}d"
    if minutes % 60 == 0:
        return f"{minutes // 60}h"
    return f"{minutes}m"


def main():
    here = Path(__file__).resolve().parent
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--cache", type=Path, required=True, help="folder for the downloaded source files")
    parser.add_argument("--out", type=Path, default=here.parent / "content" / "civil-comments.json")
    parser.add_argument("--candidates", type=Path, help="also write every candidate thread here, for review")
    args = parser.parse_args()
    args.cache.mkdir(parents=True, exist_ok=True)

    buckets = [fetch(args.cache, n, FIGSHARE_URL.format(i), s, "md5", h) for n, i, s, h in BUCKETS]
    label_files = [fetch(args.cache, n, LABEL_URL.format(n), s, "sha256", h) for n, s, h in LABEL_FILES]
    counts = collections.Counter()

    articles = select_articles(buckets)
    counts["qualifying articles"] = len(articles)
    rows = {}
    for row in read_buckets(buckets):
        counts["comments in the archive"] += 1
        if row["article_id"] in articles:
            rows[row["id"]] = row
    counts["comments on food articles"] = len(rows)
    labels = load_labels(label_files, {r["text"] for r in rows.values() if r["rating"] == "approved"})

    reasons = {i: filter_reason(r, labels) for i, r in rows.items()}
    children = collections.defaultdict(list)
    for row in rows.values():
        if row.get("parent_id"):
            children[row["parent_id"]].append(row)
    for siblings in children.values():
        siblings.sort(key=lambda r: (r["created_at"], int(r["id"])))

    def kept_subtree(row):
        """The comment and its replies that pass the filters (a reply needs all its ancestors)."""
        out = [row]
        for child in children.get(row["id"], []):
            if reasons[child["id"]] is None:
                out += kept_subtree(child)
        return out

    for reason in reasons.values():
        if reason is not None:
            counts[f"dropped by filter: {reason}"] += 1
    threads = []
    for row in sorted(rows.values(), key=lambda r: (r["created_at"], int(r["id"]))):
        if row.get("parent_id") or reasons[row["id"]] is not None or not FOOD_STRONG.search(row["text"]):
            continue
        subtree = kept_subtree(row)
        if sum(bool(FOOD_ANY.search(r["text"])) for r in subtree) / len(subtree) >= THREAD_MIN_FOOD:
            threads.append(subtree)
    candidates = [r for t in threads for r in t]
    counts["candidate threads"] = len(threads)
    counts["candidate comments"] = len(candidates)
    counts["kept but not in a food thread"] = sum(r is None for r in reasons.values()) - len(candidates)

    if args.candidates:
        write_candidates(args.candidates, threads, rows)
    reviewed = hashlib.sha256("\n".join(sorted((r["id"] for r in candidates), key=int)).encode()).hexdigest()
    if reviewed != REVIEWED_CANDIDATES:
        fail(f"the candidates changed (ids hash {reviewed}). Review them (--candidates), update EXCLUDED and REVIEWED_CANDIDATES.")

    # Manual review, then drop repeated texts (the dev wiki refuses duplicates).
    keep = set()
    seen_texts = set()
    for row in candidates:
        parent = row.get("parent_id")
        if row["id"] in EXCLUDED:
            counts[f"removed in review: {EXCLUDED[row['id']]}"] += 1
        elif parent and parent not in keep:
            counts["removed in review: reply to a removed comment"] += 1
        elif clean_text(row["text"]) in seen_texts:
            counts["removed: repeated text"] += 1
        else:
            keep.add(row["id"])
            seen_texts.add(clean_text(row["text"]))
    unknown = set(EXCLUDED) - {r["id"] for r in candidates}
    if unknown:
        fail(f"excluded ids that are not candidates (filters changed?): {sorted(unknown, key=int)}")

    page = to_page([r for r in candidates if r["id"] in keep], rows)
    check_page(page)
    args.out.write_text(json.dumps({PAGE: page}, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    counts["output comments"] = len(keep)
    counts["output threads"] = len(page)
    counts["output articles"] = len({rows[i]["article_id"] for i in keep})
    for key in sorted(counts):
        print(f"{counts[key]:>9}  {key}", file=sys.stderr)
    print(f"wrote {args.out}", file=sys.stderr)


def write_candidates(path, threads, rows):
    """Candidate threads as indented text, marked with the review decision."""
    with open(path, "w", encoding="utf-8") as out:
        for thread in threads:
            depth = {}
            out.write(f"\n## article {thread[0]['article_id']}\n")
            for row in thread:
                depth[row["id"]] = depth.get(row.get("parent_id"), -1) + 1
                mark = EXCLUDED.get(row["id"], "keep")
                text = clean_text(row["text"]).replace("\n", " / ")
                out.write(f"{'  ' * depth[row['id']]}[{row['id']}] {mark} likes={row['likes']} | {text}\n")


def to_page(kept, rows):
    """Map kept comments to the seed content format."""
    by_parent = collections.defaultdict(list)
    for row in kept:
        by_parent[row.get("parent_id")].append(row)
    depth = {}
    for row in kept:  # parents come before their replies
        depth[row["id"]] = depth[row["parent_id"]] + 1 if row.get("parent_id") else 0

    # Authors. A comment whose replies get flattened (and so mention it) is by a member.
    author = {}
    for row in kept:
        h = stable("author", row["id"])
        mentioned = depth[row["id"]] >= 2 and by_parent.get(row["id"])
        if h % 10 < MEMBER_SHARE or mentioned:
            choices = MEMBERS
        else:
            choices = ANONYMOUS
        start = (h // 10) % len(choices)
        parent_author = author.get(row.get("parent_id"))
        for k in range(len(choices)):
            name = choices[(start + k) % len(choices)]
            if name != parent_author:
                break
        author[row["id"]] = name

    # Ages: same order and relative gaps as the original, squeezed into the last year.
    times = {r["id"]: parse_time(r["created_at"]) for r in kept}
    newest, oldest = max(times.values()), min(times.values())
    span = max((newest - oldest).total_seconds(), 1)
    age = {}
    previous = WINDOW_MINUTES
    for row in sorted(kept, key=lambda r: (times[r["id"]], int(r["id"]))):
        minutes = NEWEST_MINUTES + round((newest - times[row["id"]]).total_seconds() / span * (WINDOW_MINUTES - NEWEST_MINUTES))
        if minutes >= 2 * 1440:
            minutes = round(minutes / 60) * 60  # whole hours from two days up: the seeder jitters by hours anyway
        minutes = min(minutes, previous)  # never older than a comment written before it
        if row.get("parent_id"):
            if row["parent_id"] not in age:
                fail(f"{row['id']} is older than the comment it replies to")
            minutes = min(minutes, age[row["parent_id"]] - 1)  # replies are younger than their parent
        age[row["id"]] = previous = minutes

    def convert(row):
        text = clean_text(row["text"])
        if depth[row["id"]] >= 3:
            text = f"@{author[row['parent_id']]} {text}"
        comment = {"ref": f"cc-{row['id']}", "by": author[row["id"]], "text": text, "ago": format_age(age[row["id"]])}
        likers = sorted((m for m in MEMBERS if m != author[row["id"]]), key=lambda m: stable("like", row["id"], m))
        likes = likers[: min(int(row["likes"] or 0), len(likers))]
        if likes:
            comment["likes"] = likes
        return comment

    def flattened(row):
        """Replies below the second level, oldest first, as replies of the second level."""
        out = []
        for child in by_parent.get(row["id"], []):
            out += [child] + flattened(child)
        return out

    page = []
    for top in by_parent[None]:
        comment = convert(top)
        replies = []
        for reply in by_parent.get(top["id"], []):
            converted = convert(reply)
            below = sorted(flattened(reply), key=lambda r: (times[r["id"]], int(r["id"])))
            if below:
                converted["replies"] = [convert(r) for r in below]
            replies.append(converted)
        if replies:
            comment["replies"] = replies
        page.append(comment)
    return page


def check_page(page):
    """Fail unless the page follows the seed content contract and the rules of this subset."""
    ages = {"m": 1, "h": 60, "d": 1440}
    authors = set()
    refs = set()
    pairs = set()
    mentions = []

    def check(comment, depth, parent_age):
        where = comment.get("ref", "?")
        if set(comment) - {"ref", "by", "text", "ago", "likes", "replies"}:
            fail(f"{where}: unexpected fields {sorted(set(comment))}")
        if comment["ref"] in refs:
            fail(f"{where}: repeated ref")
        refs.add(comment["ref"])
        by, text = comment["by"], comment["text"]
        if by not in MEMBERS and by not in ANONYMOUS:
            fail(f"{where}: author {by} is not a regular member or a documentation address")
        authors.add(by)
        if (by, text) in pairs:
            fail(f"{where}: {by} posts the same text twice")
        pairs.add((by, text))
        if not text or len(text) > MAX_LENGTH:
            fail(f"{where}: text length {len(text)}")
        body = re.sub(r"^@\w+ ", "", text)
        mentions.extend(re.findall(r"^@(\w+) ", text))
        for name, pattern in PERSONAL_DATA.items():
            if pattern.search(body):
                fail(f"{where}: {name} in the text")
        match = re.fullmatch(r"(\d+)([mhd])", comment["ago"])
        if not match:
            fail(f"{where}: bad age {comment['ago']}")
        age = int(match.group(1)) * ages[match.group(2)]
        if parent_age is not None and age >= parent_age:
            fail(f"{where}: a reply is not younger than its parent")
        likes = comment.get("likes", [])
        if len(set(likes)) != len(likes) or by in likes or not set(likes) <= set(MEMBERS):
            fail(f"{where}: bad likes {likes}")
        replies = comment.get("replies", [])
        if replies and depth >= 2:
            fail(f"{where}: replies nest more than two levels deep")
        for reply in replies:
            check(reply, depth + 1, age)
        return age

    previous = None
    for comment in page:
        age = check(comment, 0, None)
        if previous is not None and age > previous:
            fail(f"{comment['ref']}: top-level comments are not in time order")
        previous = age
    for name in mentions:
        if name not in authors:
            fail(f"a comment mentions {name}, who does not write on the page")


if __name__ == "__main__":
    main()
