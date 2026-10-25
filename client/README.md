English | [简体中文](README.zh-CN.md)

# GlowThread client

The browser side of GlowThread: the comment section under articles (`src/comments/`), the moderation hub on Special:GlowThread (`src/admin/`), the shared design system (`src/design/`, `src/ui/`), helpers (`src/lib/`, `src/shared/`), and the build that turns them into ResourceLoader bundles in `resources/dist/`.

Vue 3.5 (bundled, because MediaWiki 1.43 ships Vue 3.4 and Reka UI 2.11 needs 3.5), Reka UI 2.11 primitives with hand-ported shadcn-vue components, Tailwind CSS 4 with the `gt:` prefix, Lucide icons, Vite 8.

## Commands

Run in `client/` with Node 24 and pnpm 11 (`packageManager` in package.json):

| Command | Does |
|---|---|
| `pnpm install` | Installs the pinned versions from `pnpm-lock.yaml` (7-day release age, see `pnpm-workspace.yaml`) |
| `pnpm build` | Cleans `resources/dist/`, builds every app, then runs `pnpm check`. Next to each bundle it writes `<name>.licenses.txt`: every npm package whose code is in the bundle or its chunks, and Tailwind CSS for the stylesheet, with its version, license and license text (`scripts/vite-plugin-licenses.mjs`). Reka UI's `useId` gets Vue's `useId` by name instead of the whole `vue` namespace, so the bundles leave out the parts of Vue they do not use (`scripts/vite-plugin-reka-useid.mjs`, which stops the build when a Reka UI upgrade changes that file). The output is committed and must be reproducible: a fresh `pnpm install && pnpm build` gives identical bytes |
| `pnpm check` | Static checks of the bundles: ES2017 syntax inside ResourceLoader's wrapper, no `eval`, no CSS the browser floor drops, CSS that flips safely for right-to-left languages, each file registered in extension.json as the module its name implies with the modules it requires as dependencies, no Reka UI overlays in the first comment module, sizes against the budget |
| `pnpm test` | Vitest unit tests (`tests/`): formatting, avatar colours and contrast, segments, mentions, error messages, the store, message files |
| `pnpm tokens css`, `pnpm tokens docs` | Writes `src/design/tokens.css` and the token tables of `docs/theming.md` and `docs/zh-CN/theming.md` from `src/design/tokens.json` ([design tokens](#design-tokens)), and their part tables from `src/design/parts.ts` ([part hooks](#part-hooks)) |
| `pnpm typecheck` | `vue-tsc` (stay on TypeScript 6: TypeScript 7 breaks the Vue SFC compiler) |

End-to-end tests against the dev wiki live in `tests/e2e/` (Playwright, all skins) and `tests/e2e/old-chromium/` (a real Chromium 90).

## Browser floor

Chrome and Android WebView 87, Safari and iOS 14, Firefox 78: many readers on phones still use in-app Android WebViews on Chrome 90 to 110. `scripts/targets.mjs` holds the targets for the JavaScript syntax level (ES2017, which is also what ResourceLoader's minifier understands), Vite's CSS target and the final Lightning CSS pass.

Consequences for code:

- No `:is()`, `:where()` or `:has()` in CSS, no `oklch()` or `color-mix()`, no cascade layers in the output, no range media queries, no individual `translate`/`scale` properties. `pnpm check` fails on any of them. In Tailwind terms: avoid opacity modifiers on colours (`bg-x/50`), `space-*`, `divide-*`, `group-*`, `peer-*`, `has-*`, `*:` and `translate-*` utilities.
- `Array#at` and `Object.hasOwn` (used inside Reka UI) are polyfilled in `src/lib/polyfills.ts`. A new runtime API above the floor needs a polyfill or a guard; `pnpm check` lists the ones it knows.
- Flex `gap` needs Safari 14.1 and `:focus-visible` Safari 15.4. Both degrade gracefully (tighter spacing, the browser's own focus ring).

## How the CSS stays inside the app

Skins and site CSS must not style the app, and the app must not style the page (measured by `tests/e2e/specs/skins.spec.ts` in every skin).

- Every Tailwind class carries the `gt:` prefix (`gt:flex`), so wiki classes such as `.hidden` or `.border` never match.
- Source CSS writes `.gt-scope` for "the app root or the portal container". `scripts/vite-plugin-mw-scope.mjs` rewrites it after the build: the scoped reset (`all: revert`) gets the weight of two IDs and one class, everything else two IDs and two classes, using `:not(#_)` (neither the app root nor the portal container has that ID) because Chrome 87 has no `:is()`. A skin rule with one ID loses, inline styles from Reka UI still win, nothing is `!important`.
- The app mounts into a child of `#glowthread` (`div.gt-scope.gt-root`), so the container itself keeps any grid placement that site CSS (for example `MediaWiki:Vector.css` or `MediaWiki:Citizen.css`) gives it. A wiki that comes from FlowThread renames `#flowthread` to `#glowthread` in its site CSS.
- The root sets every inherited text property (font, spacing, wrapping, `text-autospace`, colour scheme), because the reset turns them into `inherit`.
- Overlays (menus, popovers, sheets, tooltips, toasts, the @mention list) render into `#glowthread-portal` at the end of `<body>`, which carries the same scope class.
- SVG elements skip the reset, which would remove Lucide's presentation attributes.
- Site CSS with `!important` beats any specificity. Two such rules are common in site CSS and would reach the apps: `h1 … h6 { font-family: sans-serif !important }` and `.vector-body p { margin: 1em 0 !important }`. The only `!important` rules of the apps answer them: headings keep `--glowthread-font-family` (`src/design/preflight.css`), and paragraphs inside the hub, which sits in `.vector-body`, keep zero margins (`src/admin/styles.css`). The hub writes its own text blocks as `div`, so margin utilities on them still work. `tests/e2e/specs/skins.spec.ts` and `admin-skins.spec.ts` inject those rules and check.

## Right-to-left languages

For a right-to-left user language (Arabic, Hebrew, Persian and others) MediaWiki sets `dir="rtl"` on `<html>` and ResourceLoader serves both stylesheets flipped by CSSJanus: `margin-left` becomes `margin-right`, `left` becomes `right`, `translateX(100%)` becomes `translateX(-100%)`, and so on. Left-to-right readers get the stylesheets unchanged. So:

- Write layout with the physical utilities as usual (`gt:ml-auto`, `gt:pl-3`, `gt:border-l`, `gt:left-0`). They mirror in a right-to-left interface.
- Keyframe names never contain `left` or `right`: CSSJanus would rename the `animation-name` but not the `@keyframes`. `pnpm check` runs cssjanus on the bundles and fails when a selector or an animation name would change.
- Icons that point along the reading direction (chevrons and arrows to go on, the reply corner, send) carry `gt-flip-rtl`, which mirrors them under `:root[dir="rtl"]`. CSSJanus cannot reach inside an SVG.
- Reka UI gets the direction from `<html>` through `ConfigProvider` (`pageDir()` in `src/lib/media.ts`), so arrow keys in tabs, radio groups and menus follow it. Floating UI reads the direction of the portal and puts `start` alignment on the right. Own key handling that moves sideways (the emoji grid) uses `pageDir()` too.
- Comment bodies, names and notes keep `dir="auto"`, so each one takes the direction of its own text. A comment body is only as wide as its text (`gt:w-fit`), so a short English comment in a right-to-left interface still sits next to its avatar. Link labels are `dir="ltr"`, so a URL keeps its order inside right-to-left text.

## Design tokens

The public custom properties are `--glowthread-<name>`. `src/design/tokens.json` is the one source: each token with its group, light and night defaults, phone step and descriptions (English and Simplified Chinese). GlowThread never declares a public token. It only reads them, each with its own default as the fallback, so site CSS sets any of them with any selector, in any order and without `!important`:

```css
/* MediaWiki:Common.css */
:root {
  --glowthread-color-accent: #3366cc;       /* light mode */
  --glowthread-night-color-accent: #88a3e8; /* night mode */
}
.ext-glowthread-comment[data-glowthread-pinned] { --glowthread-comment-bg: #f6f8fc; }
```

How the stylesheet uses them (`src/design/tokens.css`, generated by `pnpm tokens css`, layer `tokens`, which the build leaves unscoped):

- Each token is resolved into an internal `--gt-v-<name>` on `.gt-scope` (the app roots and the overlay container) and again on the container parts, `.ext-glowthread-<part>` for each name in `CONTAINER_PARTS` in `scripts/tokens.mjs`. So a token set on a part (a comment, a queue item, a menu) applies inside it. The list is short on purpose: every element on it resolves every token again, which costs style recalculation. The parts also set the text colour and face again, so text that inherits them (an author name) follows a token set on the part. The apps read only `--gt-v-*`. Each app's stylesheet keeps the rules of only the parts its JavaScript writes.
- Colours, the overlay shadow, the avatar tones, `color-scheme` and `comment-bg` have a night value. Night mode reads `--glowthread-night-<name>` instead, with GlowThread's night default, under `html.skin-theme-clientpref-night`, under `html.skin-theme-clientpref-os` while the system is dark, and under the switch class `ext-glowthread-night` on `<html>` or `<body>` (a skin, a gadget or site JavaScript adds it). `ext-glowthread-day` on an ancestor forces light mode, also over the skin's night classes. A site theme that sets only light values leaves night mode as it is.
- Some defaults follow other tokens on the same element: `color-accent-badge` is `color-accent-subtle` in light mode, `color-focus-ring` is `color-accent`, `font-family` contains `font-cjk`, `font-family-text` is `font-family`, `reply-indent` is `avatar-size` plus `avatar-gap`.
- `font-cjk` has a default per interface language (`:root:lang()`, the language of `<html>`: Japanese, Korean, Traditional Chinese for Taiwan and for Hong Kong). A value the site sets applies to every language.
- Below 640 px the font sizes are the base size plus a step (`font-size-body` 0.875rem becomes 0.9375rem), unless the site sets the `-phone` token. The avatars are 0.25rem smaller (below 40rem, where `gt:sm:` starts) and the toasts 48 px higher.
- The avatar colours (`avatar-bg`, `avatar-fg`) resolve on the avatar itself, where the hue from the name is (`--gt-avatar-hue`).
- The app roots and the overlay container carry the class `ext-glowthread-theme` and `data-glowthread-app` (`comments` or `hub`, set by `mountApp`). `.ext-glowthread-theme[data-glowthread-app="hub"]` themes the hub with its menus and sheets, `#glowthread` would miss the overlays.

| Group | Tokens (`--glowthread-…`) |
|---|---|
| Colours | `color-fg`, `-fg-muted`, `-fg-subtle`, `-bg`, `-surface`, `-surface-subtle`, `-surface-strong`, `-border`, `-border-control`, `-accent`, `-accent-text`, `-accent-bg`, `-accent-bg-hover`, `-on-accent`, `-accent-subtle`, `-accent-badge`, `-highlight`, `-danger`, `-warning`, `-success` (each with `-subtle`), `-overlay-border`, `-scrim`, `-focus-ring`, `color-scheme`, `comment-bg` |
| Type | `font-family`, `font-family-text`, `font-family-mono`, `font-cjk`, `font-size-small`, `-meta`, `-body`, `-title` (each with `-phone`), `-code`, `line-height-meta`, `-body`, `-title`, `font-weight-medium`, `-strong` |
| Density | `space` (the spacing unit), `comment-padding-y`, `avatar-gap`, `reply-indent` |
| Shape and lines | `radius-surface`, `-control`, `-mark`, `-pill`, `border-width`, `indicator-width`, `focus-ring-width`, `focus-ring-offset`, `shadow-overlay` |
| Avatars | `avatar-size`, `-size-small`, `-radius`, `-font-weight`, `-bg`, `-fg`, `avatar-{bg,fg}-{saturation,lightness}` |
| Icons | `icon-size`, `icon-size-small`, `icon-stroke` |
| Motion | `duration-fast`, `-base`, `-exit`, `ease-out`, `ease-in` |
| Layout | `text-max-width`, `sheet-width`, `toast-max-width`, `toast-offset` |
| Layers | `z-sheet`, `z-popover`, `z-toast` |

The defaults and what each token is for are in [docs/theming.md](../docs/theming.md), whose tables `pnpm tokens docs` writes from the registry (also into `docs/zh-CN/theming.md`). `src/design/example-themes.css` has example themes for site CSS (brand colour, serif reading, compact density, square avatars, dark-only sites, one app only, pinned comments). It is not part of the bundles.

To add or change a token: edit `tokens.json`, run `pnpm tokens css` and `pnpm tokens docs`, and read it as `var(--gt-v-<name>)`. `tests/tokens.test.ts` fails when tokens.css or the docs tables differ from the registry, when the source reads a token the registry lacks or the registry has a token nothing reads, when a default colour pair misses WCAG AA in light or night mode, or when a name contains "left" or "right" (CSSJanus would rename it). `pnpm check` fails when a bundle declares a public token or reads one without a fallback.

Inside the apps the tokens become Tailwind utilities through `@theme inline` in `src/design/index.css`: `gt:text-fg`, `gt:bg-surface-subtle`, `gt:border-border-control`, `gt:text-accent-text`, `gt:rounded-control`, `gt:rounded-mark`, `gt:rounded-pill`, `gt:rounded-avatar`, `gt:text-meta`, `gt:text-small`, `gt:font-medium`, `gt:font-semibold` (the strong weight), `gt:font-text`, `gt:shadow-overlay`, `gt:size-icon` (also `icon-sm`, and `icon-xs`, `icon-lg`, `icon-xl`, `icon-2xl`, which scale with `icon-size`), `gt:size-avatar`, `gt:z-sheet`, `gt:z-popover`, `gt:z-toast`. `gt:border` is `border-width` wide. Spacing utilities are multiples of `--glowthread-space` (0.25rem by default, so `gt:p-3` is 12 px). Touch targets are written as `gt:min-h-[2.75rem]` (44 px), off the grid, so a denser spacing unit keeps them at 44 px. A token without a utility is read as `gt:max-w-(--gt-v-text-max-width)`. The only breakpoint is `gt:sm:` (640 px and up). Styles without it are for phones.

Helper classes (plain CSS, in `src/design/base.css` and `motion.css`): `gt-hit` (44 px touch target on touch screens without changing the layout), `gt-flip-rtl` (mirrors an icon in a right-to-left interface), `gt-tip` with `data-tip` (a text hint above the element on hover and keyboard focus), `gt-num` (tabular figures), `gt-scroll` (thin themed scrollbar), `gt-sr-only`, `gt-fold` (6-line fold), `gt-sticky-bar` (a bar stuck to the bottom: on Citizen phones it keeps clear of the floating page actions), `gt-avatar`, `gt-comment-row` (a comment without its replies, drawn on `comment-bg`), and the motion classes `gt-anim-pop`, `gt-anim-fade`, `gt-anim-sheet`, `gt-anim-enter`, `gt-anim-like`, `gt-highlight`, `gt-pulse`, `gt-spin`. Reduced motion turns every animation off.

Layers (`z-sheet`, `z-popover`, `z-toast`): sheets 450, menus, popovers, tooltips and the @mention list 470 (also above a sheet), toasts 480. Skin headers sit at 200 and their dropdowns at 350. Floating parts that are not Reka layers but belong to an open sheet (the @mention list, toasts) carry `data-gt-floating`, so touching them does not close the sheet.

Keyboard: menus and lists draw the focus ring (`color-focus-ring`, `focus-ring-width`) inside the focused item. F8 moves focus to the newest toast (to reach Undo in time).

## Part hooks

Site CSS selects the parts of the apps by stable class names, `ext-glowthread-<part>`, and state attributes, `data-glowthread-*`. `src/design/parts.ts` is the registry: each part with where it renders (the comment section, the overlay container, the hub, or both apps), whether it is a container, and its descriptions in English and Simplified Chinese. `pnpm tokens docs` writes the part tables of `docs/theming.md` and `docs/zh-CN/theming.md` from it. The apps do not import it.

- Templates write the class as a plain string next to the utilities (`class="ext-glowthread-meta gt-num gt:ml-auto ..."`), or in the shared component (`Avatar`, `Badge`, `buttonVariants`, `DropdownMenuContent`, `SheetContent` and the like), so a hook costs nothing at render time. `tests/parts.test.ts` fails when the source, `example-themes.css` or this README uses a hook the registry lacks, when a part is used nowhere, when the containers differ from `CONTAINER_PARTS`, or when the docs tables differ. `tests/parts-render.test.ts` renders a comment and checks its hooks.
- Container parts (a comment, the composer, a menu, a sheet, a toast, a queue item and the others marked in the registry) resolve every token again, so a token set on one applies inside it. The other parts take plain CSS properties.
- State: a comment carries `data-glowthread-depth` (0 to 2, as shown), `data-glowthread-status` (`visible`, `held`, `deleted`, `erased`), `data-glowthread-pinned` and `data-glowthread-own`. Queue items carry the status too, badges and notices `data-glowthread-tone`, buttons `data-glowthread-variant`. The internal `data-comment` and `data-pinned` stay as they are: the app and the end-to-end tests use them.
- Names say what a part is, never where it sits: no "left" or "right" (CSSJanus would flip them in a right-to-left interface). The other `ext-glowthread-` names are not parts: `ext-glowthread-theme` on the app roots, the switch classes `-night` and `-day`, the loader's `-reserve` and `-size-N`, the notices `-nojs` and `-admin-nojs`, and the hub's container ID `ext-glowthread-admin`.
- To add a part: add it to `parts.ts` (and to `CONTAINER_PARTS` in `scripts/tokens.mjs` if it should take tokens, then `pnpm tokens css`), write the class in full in the template (the build looks for it in each app's JavaScript, and stops when it finds a part's class put together from pieces), run `pnpm tokens docs`.

Plain properties need weight. Every rule of the app weighs two IDs and one or more classes (`.gt-scope.gt-scope:not(#_):not(#_) .gt\:text-fg`, see [how the CSS stays inside the app](#how-the-css-stays-inside-the-app)), so a site rule with one or two IDs loses to it on purpose. A rule with three IDs outweighs every rule of the app without `!important`. Repeating an ID in a selector is valid CSS and adds its weight each time. Use the container the part renders in: overlays, and the hub's record when it opens in a sheet, render in `#glowthread-portal`.

```css
/* MediaWiki:Common.css */
/* The comment section */
#glowthread#glowthread#glowthread .ext-glowthread-name { font-weight: 600; }
/* Menus, popovers, sheets, toasts and the @mention list (the overlay container at the end of <body>) */
#glowthread-portal#glowthread-portal#glowthread-portal .ext-glowthread-menu { border-radius: 0; }
/* The moderation hub */
#ext-glowthread-admin#ext-glowthread-admin#ext-glowthread-admin .ext-glowthread-queue-item { padding-top: 1rem; padding-bottom: 1rem; }
```

Such a rule also outweighs the app's hover, focus, selected and open states for that property, so give it its own `:hover` where it matters, and it applies in light and night mode alike. Inline styles still win (the positions Floating UI sets on overlays), and so do the app's few `!important` rules (heading faces, paragraph margins in the hub, `[hidden]`, reduced motion): set `--glowthread-font-family` for the faces. Prefer a token where one exists, colours above all: it reaches every part that uses it, with its own night value.

## Components (`src/ui/`)

Ported by hand from shadcn-vue (its CLI breaks prefixed classes) onto Reka UI and restyled with the tokens. Import from the folder: `import { Button } from '@/ui/button'`.

| Component | Built on | Notes |
|---|---|---|
| `Button` | `cva` (Reka `Primitive` only for `asChild`) | `variant`: primary (the one main action of a view), secondary, ghost, subtle, danger, link. `size`: sm, md, icon, icon-sm, inline. `as="a"` for links |
| `DropdownMenu`, `…Trigger`, `…Content`, `…Item` (`variant="danger"`), `…Separator`, `…Label`, `…Group` | Reka DropdownMenu | Content is portaled, aligned to the end by default |
| `Popover`, `…Trigger`, `…Anchor`, `…Content`, `…Close` | Reka Popover | `PopoverAnchor :reference` anchors to any element (the report form uses it) |
| `Sheet`, `SheetContent` (`title`, `description`, `closeLabel`) | Reka Dialog | Bottom sheet on phones, side sheet from 640 px |
| `Tabs`, `TabsList`, `TabsTrigger`, `TabsContent` | Reka Tabs | Selected tab: 2 px accent underline, neutral label |
| `Textarea` | native | Grows from `rows` to `maxRows` |
| `Avatar` (`name`, `generic`, `size`) | | Monogram on a name-derived hue, or a person icon |
| `Tooltip` (`content`) + `TooltipProvider` | Reka Tooltip | Opens on hover and keyboard focus; the trigger must be focusable. For a plain text hint prefer the CSS-only `gt-tip` (about 2 kB less JavaScript), as the comment section does for exact times |
| `Badge` (`tone`: neutral, accent, warning, danger, success) | | Quiet status labels |
| `Checkbox`, `RadioGroup` + `RadioGroupItem` | Reka | Accent fill when checked |
| `Toaster` + `toast()` | own | Live regions exist before any toast; errors are assertive; an action (Undo) keeps it 6 s; pauses on hover and focus |
| `Skeleton`, `Spinner` | | Spinner only inside a control, never in place of content |

Shared domain parts (`src/shared/`): `CommentBody` (segments to text, links and mentions, never `v-html`; a link's text always shows its real host, without any `user@` part, with long paths shortened in the middle), `UserName`, `RelativeTime`, `types.ts` (API shapes), `undo.ts` (how an action is undone: the server's exact undo with its log IDs, or a reverse action decided from the server's `old` status, never the page's copy). Helpers (`src/lib/`): `api.ts` (REST fetch, Action API writes with a CSRF token), `errors.ts` (error codes to messages), `i18n.ts` (`t('key')` for `glowthread-ui-key`), `time.ts`, `avatar.ts`, `segments.ts`, `wiki.ts` (links into the wiki), `media.ts`, `clipboard.ts`, `mount.ts`.

### Adding a component

1. Create `src/ui/<name>/<Name>.vue` and `index.ts`. Wrap the Reka primitive, take `class` for layout additions only (there is no tailwind-merge, so never pass a utility that conflicts with the component's own), use token utilities, and keep every state: hover, focus (the global ring), disabled, open and closed (`gt-anim-pop` for floating content).
2. Portaled content needs `gt:z-popover` (or `gt:z-sheet` for a modal surface) and `gt:bg-surface gt:border gt:border-overlay-border gt:shadow-overlay`.
3. Interactive parts on phones get `gt-hit`, or are 44 px themselves.
4. Run `pnpm build`: `pnpm check` rejects selectors and colour functions the browser floor drops.

### Adding text

UI messages live in `i18n/ui/{en,zh-hans,zh-hant,qqq}.json` with the prefix `glowthread-ui-`. ResourceLoader ships every key of `en.json` with the app modules (no change to extension.json). Use `t('key', ...params)` with MediaWiki's `$1` and `{{PLURAL:$1|…}}`. A message never ends in a colon or a space to have the template add the rest: it takes the rest as a parameter, and `tParts('key', n)` splits it around its parameters for a template that styles or links them (`Held for review: $1`). A space a message needs at its end is written `&#32;`. Whole sentences are joined with `sentences()`, which uses the language's word separator. `pnpm test` fails when a key used in the source is missing or the files disagree.

## Adding an app

1. Create `src/<name>/main.ts` (copy `src/admin/main.ts`: polyfills first, then the stylesheet, then `mountApp` from `src/lib/mount.ts`).
2. Create `src/<name>/styles.css`: `@import "../design/index.css";`, the utilities import with `source(none)`, and `@source` lines for `../ui`, `../shared` and `./`.
3. `pnpm build` finds every `src/<name>/main.ts` and writes `resources/dist/<name>.js`, `.css` and `.licenses.txt` (the comment section is the exception, `app.js`, because of its module name). Nothing in `scripts/` or `vite.config.ts` needs to change. Add a budget for it in `scripts/check-dist.mjs`. An `import()` in an app makes a chunk `<name>-<chunk>.js`, served as module `ext.glowThread.<name>.<chunk>` (`scripts/vite-plugin-mw-chunks.mjs`): add it to extension.json with the modules it requires as dependencies, and a budget.

## Comment section (`src/comments/`)

- The server reserves the section's space before the first paint: `PageDisplayHooks` adds the styles module `ext.glowThread.reserve` (`resources/init/reserve.css`, about 0.2 kB) and the body classes `ext-glowthread-reserve ext-glowthread-size-N`, where N steps with the page's floor counter (0, 1, 2, 3, 5, 8, 13, 21), or `off` on a closed page (the height of its one-line note). The CSS gives `#bodyContent::after` the median height of measured first-page sections of that size (phones and desktop), only with `.client-js`.
- `resources/init/index.js` (hand-written ES2017, not built) creates `#glowthread.ext-glowthread` after `#bodyContent` (legacy Vector), or last inside it on Minerva, Vector 2022 and other grid layouts that have no place for it (Citizen's rows are "content" then "footer"), unless site CSS gives `#glowthread` a grid area. Inside `#bodyContent` it takes the place of the space the server reserved there, so a column beside a short article that grows after load (Vector 2022's pinned tools) does not move it. It ignores elements with the skin's IDs inside page text (the parser output, a DISPLAYTITLE in the `h1`, indicators) and adopts an existing `#glowthread` only where it would put one: page text cannot capture or hide the section. The overlay container `#glowthread-portal` is reused only as a child of `<body>`, and the section heading is looked up inside the app. It fills the container with a static skeleton exactly as tall as the reservation and removes the reservation class in the same frame, so nothing moves. It loads `ext.glowThread.app` at once for `#gt-<id>`, `#glowthread` and `#flowthread` (links made before a wiki moved from FlowThread); after the page's `load` event when the section is on screen; otherwise only after the reader scrolls (also before the loader ran), once the section is within a screen below the viewport. On phones MobileFrontend collapses the article, so the section often sits just below the first screen on views where nobody reads comments. If the module fails, it removes the skeleton. With the module it requests the first page of the list (`listUrls`) with the app's own request (same URL, headers and credentials, so Varnish serves the same cached object) and hands it to `mount()`: the app's first `restGet` takes it for that URL (`useEarlyResponse` in `src/lib/api.ts`), and a failure ends in the error state with Try again as before. For logged-in readers it also requests `ext.glowThread.app.extras`, in a request of its own.
- The app is three modules (`lazy.ts`): `ext.glowThread.app`, `ext.glowThread.app.menu` (`menu.ts`, the ⋯ menu's items, loaded on hover, focus or tap of a ⋯ button or a touch in the section) and `ext.glowThread.app.extras` (`extras.ts`, the composer, report form and moderation tools, loaded for readers who are logged in or may post, whose list waits for it, at most 5 s after it arrives). Use their components through `lazy.ts` only: `tests/split.test.ts` fails when the first module imports one of their files.
- The app keeps exactly the reserved height (`data-gt-reserve`) until the list is drawn. Permalinks (`#gt-<id>`, `#glowthread`, `#flowthread`) are looked up inside the app root only.
- The icons every comment draws (⋯, the heart, Reply, the reply chevrons, the person avatar) are Lucide's markup written out in the templates, cheaper than a component each: `tests/icons.test.ts` fails when they differ from what Lucide renders, and shows the markup to paste.
- `store.ts` holds state and actions with injected transport (unit-tested): canonical list URLs, cursor paging, optimistic idempotent likes (last tap wins), posting and replies, own deletion, moderation with undo, page state.
- `App.vue` composes the header (count, Top · Newest), moderator tools, composer or the reason one cannot post, the list, the linked conversation (`LinkedThread.vue`), the report form and the toaster.
- Permalinks `#gt-<id>` and the popular strip scroll to and highlight the comment. When it is not in the list, its conversation is shown in place: unfolded in the list when its thread is there (`showComment` completes it from `/comment/{id}/thread`), otherwise in a Conversation box above the list that the reader can close. A comment of another page is only named there, with a link to it.
- Conversations never open in a sheet. Under a comment, "Show N more replies" loads the next replies in place (`/comment/{id}/replies`, 50 per request, oldest first) at both levels, until all are shown, and "Hide replies" folds a conversation the reader opened. Phones fold replies behind "Show N replies". The reader's own new reply stays visible. Copy link gives `Special:GlowThreadLink/<id>`, which finds the comment's page, so the link survives page moves.

## Moderation hub (`src/admin/`)

`ext.glowThread.admin`, loaded only by Special:GlowThread (readers never load it), about 88 kB gzip with its own Vue (budget 120 kB). `main.ts` mounts the app into `#ext-glowthread-admin`, replacing the server's no-JavaScript notice.

- Queue order (`sort`: Most reported, Newest, Oldest) and the reporter filter (`reporter`, reached from the frequent-reporter badge and from a user record) are part of the address. Authors' registration and block come with each comment; word-filter holds show the recorded matching lines (`mod.holdMatches`), with the filter test as the fallback.
- Layout by the app's own width (a `ResizeObserver`, since container queries need Chrome 105): from 1080 px a rail (Queue, Settings, log and filter links), the queue and the record side by side; from 720 px the rail becomes a row above (Queue and Settings as a segmented switch, so it does not look like the queue tabs) and the record stays beside the queue; below that the record opens as a sheet (a bottom sheet on phones).
- Each queue item: author, account age (young accounts in bold, not in colour), page, `#seq · time` kept together, status chips except the one the tab already says, the text, the reasons (only the icons tinted, the "N reports in this list" badge the one amber flag), then the actions on their own row, left aligned: Approve and Delete or Restore first, the occasional ones after, View in context last (an icon on phones). The focused item is tinted only with a fine pointer. Hub links use `gt-link` (body colour, themed underline): the accent stays for focus, selection and the tab underline.
- `router.ts`: the subpages `queue?tab=&page=&user=&q=`, `user/<name>`, `page/<title>` and `settings`, under the special page's own localized title (for example `特殊:评论管理` on a wiki in Chinese). Navigation uses `history.pushState`, each entry carries its route for back and forward, `useskin` and `uselang` stay, and the document title follows the view.
- `queue.ts`: the queue store with injected transport. Cursor paging, 50 items per request, more loaded when j passes the end or fewer than 9 items are left, with Load more as the visible fallback. Actions are optimistic: items leave the tab (or change in place in All), counts follow, focus moves to the next item, and failures put them back where they were (server order is remembered). `moderation.ts` holds the pure rules: which action applies to which comment (Approve publishes a held comment and closes the reports of a visible one), what it does, which tab a comment belongs to, how it is undone, report grouping, and the frequent-reporter marker.
- `keyboard.ts`: j, k, x, a, d, r, /, ?, Esc. Never while typing in a field, during IME composition, with a modifier, on key repeat for actions, inside a dialog or menu, or while focus is outside the hub. a, d and r act on the item that holds keyboard focus (after Tab or View in context), or on the selection when that item is in it or focus is on no item (`actionTargets`).
- Performance: list items are memoized (`v-memo`) and get their feed position from the DOM, so an action re-renders only the items it changes, also with hundreds loaded.
- `history.ts`: the author or page record, following the focused item (150 ms debounce, one-minute cache). `settings.ts`: the settings draft, its difference, saving with a reason, and the word filter (edited with the edit API, checked and tested by `glowthreadfiltertest`). `wikiApi.ts`: core Action API reads (rights and block of the moderator, author facts, title and name suggestions, the filter page, `paraminfo`). `errors.ts`: the hub's own error codes before the shared ones.
- Undo: exact when the server offers `do=undo` (see `docs/api.md`, detected through `paraminfo`), otherwise the reverse action where it does no more than undo.
- Tests: `tests/admin-*.test.ts` (routing, keyboard, rules, store, settings, errors), `tests/e2e/specs/admin.spec.ts` and `admin-skins.spec.ts`. The dev wiki's seed (`dev/seed/`) fills the queue with held, reported and deleted comments.
