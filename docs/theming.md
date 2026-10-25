English | [简体中文](zh-CN/theming.md)

# Theming GlowThread

This page is for site administrators who want GlowThread to match their wiki. Site CSS sets public CSS custom properties, the tokens, for colours, type, density, shape, avatars, icons, motion and layout, in light and night mode. For anything the tokens do not cover, it selects stable part hooks. No rule needs `!important`, and everything on this page works from `MediaWiki:Common.css`.

Contents: [quick start](#quick-start), [how it fits on the page](#how-it-fits-on-the-page), [where CSS can live](#where-css-can-live-and-how-it-wins), [scoping](#scoping), [token reference](#token-reference), [light, night, dark-only and day-only](#light-night-dark-only-and-day-only), [part hooks](#part-hooks), [example themes](#example-themes), [accessibility](#accessibility), [placing the section](#placing-the-section), [what not to override](#what-not-to-override), [skin notes](#skin-notes).

## Quick start

Paste this into `MediaWiki:Common.css` to give the comment section, the moderation hub and their menus, sheets and toasts a blue accent in light and night mode:

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

Light values are `--glowthread-<name>`, night values `--glowthread-night-<name>`. A token you do not set keeps GlowThread's default. When you put in your own colours, check their contrast ([accessibility](#accessibility)).

## How it fits on the page

| Part | Element | Notes |
|---|---|---|
| Comment section container | `<div id="glowthread" class="ext-glowthread">` | Created by the loader after `#bodyContent`, or last inside it on Minerva, Vector 2022 and Citizen ([placing the section](#placing-the-section)). Yours to place with site CSS |
| App root | `<div class="gt-scope gt-root ext-glowthread-theme" data-glowthread-app="comments">` inside the container | Where the app mounts. `gt-scope` and `gt-root` are internal. `ext-glowthread-theme` and `data-glowthread-app` are public hooks for tokens ([scoping](#scoping)) |
| Overlay container | `<div id="glowthread-portal" class="gt-scope gt-portal ext-glowthread-theme" data-glowthread-app="comments">` at the end of `<body>` | Menus, popovers, sheets, dialogs, toasts and the @mention list. On Special:GlowThread it carries `data-glowthread-app="hub"` |
| No-JavaScript notice | `<noscript><p class="ext-glowthread-nojs">` at the end of the page content | Shown when JavaScript is off. Not added on pages whose comments are turned off |
| Moderation hub | `<div id="ext-glowthread-admin" class="ext-glowthread-admin">` on Special:GlowThread, with the app root `<div class="gt-scope gt-root ext-glowthread-theme" data-glowthread-app="hub">` inside | Same design system and tokens as the comment section |

Old links that end in `#flowthread` still open the comments. The container itself is `#glowthread`. If your wiki used FlowThread, change `#flowthread` to `#glowthread` in your site CSS (see [migrating from FlowThread](migrating-from-flowthread.md)).

Every rule of the apps is scoped to `.gt-scope`. It starts with a reset (`all: revert`) and then builds its own styles with selectors stronger than any skin or site rule with one ID. So `#content a { color: red }` or `.mw-body h2 { border: 0 }` does not reach the comments, and nothing in GlowThread styles the rest of the page. Apart from the space it reserves below the article ([reserved space](#reserved-space)) and the `@property` registrations of its internal `--tw-*` variables, GlowThread ships no global rule, and it declares no public custom property. It only reads the tokens your CSS sets.

## Where CSS can live and how it wins

Site CSS can set tokens from any of these places:

- `MediaWiki:Common.css`, for every skin.
- A skin's CSS page, such as `MediaWiki:Vector.css`, `MediaWiki:Vector-2022.css` or `MediaWiki:Citizen.css`, for that skin only.
- A skin's own stylesheet in its ResourceLoader module, which loads before GlowThread's.
- A gadget, for example a theme that readers turn on in their preferences.

GlowThread never declares a `--glowthread-*` property. Its stylesheet only reads each one, with GlowThread's default as the fallback (`var(--glowthread-color-accent, #dc6e7e)`), and resolves it into an internal value on its own elements. So a value your CSS declares always wins, whatever its selector, its position and the order in which the stylesheets load, without `!important`. This is measured on Vector 2022 in light and night mode, Citizen in light mode and Minerva in night mode, with CSS that loads after GlowThread's (the site styles) and with CSS that loads before it (a skin's module).

Tokens inherit like any custom property, so the nearest declaration wins: a value on `.ext-glowthread-theme` or on a part beats a value on `:root`. Plain CSS properties work differently: GlowThread's own rules weigh two IDs, and a site rule needs three to override them ([plain CSS properties](#plain-css-properties)).

## Scoping

The selector decides which parts of GlowThread a token reaches:

| Selector | Reaches | Notes |
|---|---|---|
| `:root`, `html` or `body` | Everything: the comment section, the moderation hub and their overlays (menus, popovers, the report form, sheets on phones, dialogs, toasts, the @mention list) | The usual choice |
| `.ext-glowthread-theme` | The same: both apps and their overlays | The class is on the app root of the section, the app root of the hub and `#glowthread-portal`. A value set here beats one on `:root` |
| `.ext-glowthread-theme[data-glowthread-app="comments"]` or `[data-glowthread-app="hub"]` | One app with its overlays | One app runs per page, and the overlay container takes its name |
| `#glowthread` | The comment section only, not its overlays | Menus, sheets and toasts keep the values from higher up |
| `#ext-glowthread-admin` | The moderation hub only, not its overlays | The same for the hub's record sheet and dialog |
| A container part, for example `.ext-glowthread-comment[data-glowthread-pinned]` | That part and everything inside it | Only the parts marked "yes" in the [part tables](#parts-and-state-attributes) |

Overlays do not render inside the section. Menus, popovers, sheets, dialogs, toasts and the @mention list render in `#glowthread-portal`, a child of `<body>`, so that they float above every layer of the skin. A token set on `#glowthread` or on `#ext-glowthread-admin` does not reach them: a dark section would open a light menu. Use `.ext-glowthread-theme` or `:root` for anything that should apply to the overlays too.

## Token reference

The tables list every token with its default and what it is for. They are written from [`client/src/design/tokens.json`](../client/src/design/tokens.json), the one source of the stylesheet and of these tables.

Tokens with a night value (the colours, `shadow-overlay`, the avatar colours and tones) also take `--glowthread-night-<name>` ([night values](#night-values)). On phones (narrower than 640 px) the sizes are computed from your base value: the three text sizes add a step (`font-size-body` 0.875rem becomes 0.9375rem) unless you set their `-phone` token, avatars are 0.25rem smaller, and toasts sit 48 px higher. Some defaults follow other tokens, also when you set those on a part: `color-accent-badge` is `color-accent-subtle` in light mode, `color-focus-ring` is `color-accent`, `font-family` contains `font-cjk`, `font-family-text` is `font-family`, and `reply-indent` is `avatar-size` plus `avatar-gap`. `font-cjk` has a default per interface language.

<!-- glowthread-tokens:start -->
### Colours

| Property | Light | Night | Used for |
|---|---|---|---|
| `--glowthread-color-fg` | `#171717` | `#ededed` | Text and author names |
| `--glowthread-color-fg-muted` | `#666666` | `#a1a1a1` | Times, floor numbers, counts, help lines, inactive tabs |
| `--glowthread-color-fg-subtle` | `#737373` | `#8f8f8f` | Placeholders, the dot between the sort tabs |
| `--glowthread-color-bg` | `transparent` | `transparent` | Background of the section and the hub. Keep it transparent so the skin's page shows through |
| `--glowthread-comment-bg` | `transparent` | `transparent` | Ground of a comment row. Set it on a comment part to tint some rows, for example pinned comments. Replies are rows of their own and inherit the value, so reset it on them if only the comment should be tinted |
| `--glowthread-color-surface` | `#ffffff` | `#1c1e22` | Fields, menus, popovers, sheets, toasts, secondary buttons |
| `--glowthread-color-surface-subtle` | `rgba(0, 0, 0, 0.04)` | `rgba(255, 255, 255, 0.06)` | Hover, skeletons, notices, neutral badges |
| `--glowthread-color-surface-strong` | `rgba(0, 0, 0, 0.08)` | `rgba(255, 255, 255, 0.1)` | Pressed and current states, always under `fg` text |
| `--glowthread-color-border` | `#e8e8e8` | `rgba(255, 255, 255, 0.1)` | Hairlines between comments and in headers |
| `--glowthread-color-border-control` | `#8a8a8a` | `rgba(255, 255, 255, 0.38)` | Outlines of fields, buttons, checkboxes and radios, scrollbar thumbs |
| `--glowthread-color-accent` | `#dc6e7e` | `#e88a98` | Marks only: liked heart, selected tab underline, focused field border, and the focus ring unless `color-focus-ring` is set |
| `--glowthread-color-focus-ring` | same as `color-accent` | same as `color-accent` | Keyboard focus ring, also drawn inside the focused item of menus and lists. Needs 3:1 |
| `--glowthread-color-accent-text` | `#b83f55` | `#e88a98` | Accent as text: links and mentions in comments, text actions, liked count, caret |
| `--glowthread-color-accent-bg` | `#b83f55` | `#e07a8a` | Primary button, checked checkboxes and radios |
| `--glowthread-color-accent-bg-hover` | `#a5384c` | `#e88a98` | Primary button on hover |
| `--glowthread-color-on-accent` | `#ffffff` | `#171717` | Text and check marks on `accent-bg` |
| `--glowthread-color-accent-subtle` | `#fbe5e9` | `rgba(232, 138, 152, 0.22)` | Text selection, matched line numbers in the word filter, the hub's "new items" chip |
| `--glowthread-color-accent-badge` | same as `color-accent-subtle` | `rgba(232, 138, 152, 0.16)` | Ground of the Pinned badge |
| `--glowthread-color-highlight` | `#fdf0f2` | `rgba(232, 138, 152, 0.12)` | A comment opened by a link, selected hub items |
| `--glowthread-color-danger` | `#c42b2b` | `#ff8a80` | Delete, hide and erase labels, errors, report flags |
| `--glowthread-color-danger-subtle` | `#fdecec` | `rgba(255, 138, 128, 0.12)` | Ground of danger badges and the delete confirmation |
| `--glowthread-color-warning` | `#8a5300` | `#f0b860` | Held comments, rate-limit waits |
| `--glowthread-color-warning-subtle` | `#fdf3dc` | `rgba(240, 184, 96, 0.12)` | Ground of warning badges and notes |
| `--glowthread-color-success` | `#1d7a3e` | `#6fd58f` | Passing word-filter test |
| `--glowthread-color-success-subtle` | `#e6f4ea` | `rgba(111, 213, 143, 0.12)` | Ground of success badges |
| `--glowthread-color-overlay-border` | `transparent` | `rgba(255, 255, 255, 0.12)` | Edge of floating layers in night mode |
| `--glowthread-color-scrim` | `rgba(0, 0, 0, 0.32)` | `rgba(0, 0, 0, 0.56)` | Dimming behind sheets |
| `--glowthread-color-scheme` | `light` | `dark` | `color-scheme` of native controls and scrollbars |

Contrast targets: text colours (`fg`, `fg-muted`, `fg-subtle`, `accent-text`) need 4.5:1 on `surface` and on your page background. `accent` and `border-control` need 3:1. Status colours need 4.5:1 on their own `-subtle` ground. `on-accent` needs 4.5:1 on `accent-bg`. Check your values in light and night mode.

### Other properties

#### Type

| Property | Default | Used for |
|---|---|---|
| `--glowthread-font-family` | system UI faces for Latin text, then `font-cjk`, then colour emoji | All text. GlowThread loads no web fonts |
| `--glowthread-font-family-text` | same as `font-family` | Comment text and report notes only, so a reading face does not reach the controls |
| `--glowthread-font-family-mono` | `ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", "Noto Sans Mono CJK SC", monospace` | The word-filter editor in the hub |
| `--glowthread-font-cjk` | Simplified Chinese system faces (PingFang SC, Microsoft YaHei, Noto Sans CJK SC and others), other faces by language | The CJK part of the font stack, so Han characters take the shapes of the reader's language. The default is Simplified Chinese faces, or Japanese, Korean or Traditional Chinese faces when the interface language is Japanese, Korean or Traditional Chinese (Taiwan or Hong Kong). A value you set applies to every language |
| `--glowthread-font-size-small` | `0.75rem` | Badges, the letter of small avatars |
| `--glowthread-font-size-meta` | `0.78125rem`, phones `0.8125rem` | Times, counts, small buttons |
| `--glowthread-font-size-meta-phone` | `font-size-meta` + 0.03125rem (0.8125rem) | `font-size-meta` on phones |
| `--glowthread-font-size-body` | `0.875rem`, phones `0.9375rem` | Comment text, fields, menus |
| `--glowthread-font-size-body-phone` | `font-size-body` + 0.0625rem (0.9375rem) | `font-size-body` on phones |
| `--glowthread-font-size-title` | `1rem`, phones `1.0625rem` | Section heading, sheet titles. The hub's settings heading is 1.125 times this size |
| `--glowthread-font-size-title-phone` | `font-size-title` + 0.0625rem (1.0625rem) | `font-size-title` on phones |
| `--glowthread-font-size-code` | `0.8125rem` | The word-filter editor in the hub |
| `--glowthread-line-height-meta` | `1.45` | Text in `font-size-meta` |
| `--glowthread-line-height-body` | `1.65` | Comment text and other text in `font-size-body` |
| `--glowthread-line-height-title` | `1.4` | Text in `font-size-title` |
| `--glowthread-font-weight-medium` | `500` | Author names, buttons, the selected tab |
| `--glowthread-font-weight-strong` | `600` | Headings, bold text, young accounts in the hub |

#### Density

| Property | Default | Used for |
|---|---|---|
| `--glowthread-space` | `0.25rem` | The spacing unit: padding, gaps and control heights are multiples of it. Touch targets on touch screens stay 44 px |
| `--glowthread-comment-padding-y` | `1rem` | Space above and below each comment in the list |
| `--glowthread-avatar-gap` | `0.75rem` | Space between the avatar and the text of a comment and of the composer |
| `--glowthread-reply-indent` | `calc(avatar-size + avatar-gap)` | Indent of replies. The default puts the thread line under the text of the comment |

#### Shape and lines

| Property | Default | Used for |
|---|---|---|
| `--glowthread-radius-surface` | `8px` | Menus, sheets, toasts, notices, the composer frame |
| `--glowthread-radius-control` | `6px` | Buttons, fields, menu items |
| `--glowthread-radius-mark` | `4px` | Badges, checkboxes, keyboard hints, small buttons inside fields |
| `--glowthread-radius-pill` | `9999px` | Filter chips and the hub's chips |
| `--glowthread-border-width` | `1px` | Hairlines and the outlines of fields, buttons and checkboxes |
| `--glowthread-indicator-width` | `0.125rem` | Underline of the selected tab |
| `--glowthread-focus-ring-width` | `2px` | Width of the keyboard focus ring |
| `--glowthread-focus-ring-offset` | `2px` | Gap between a control and its focus ring. Menus and lists draw the ring inside the item |
| `--glowthread-shadow-overlay` | `0 4px 12px rgba(0, 0, 0, 0.08), 0 12px 32px rgba(0, 0, 0, 0.12)`, night `0 0 #0000` | Elevation of floating layers. Light mode uses the shadow, night mode uses `color-overlay-border` |

#### Avatars

| Property | Default | Used for |
|---|---|---|
| `--glowthread-avatar-size` | `2.25rem`, phones `2rem` | Avatars of comments, the composer and the hub |
| `--glowthread-avatar-size-small` | `1.5rem` | Avatars of replies, the @mention list and the popular strip |
| `--glowthread-avatar-radius` | `9999px` | Corner radius of avatars and their loading placeholders. `4px` gives square avatars |
| `--glowthread-avatar-font-weight` | `500` | Letter of generated avatars |
| `--glowthread-avatar-bg` | the name hue with `avatar-bg-saturation` and `avatar-bg-lightness` | Ground of generated avatars as one colour. By default the hue comes from the user name and the tone from the next four properties |
| `--glowthread-avatar-fg` | the name hue with `avatar-fg-saturation` and `avatar-fg-lightness` | Letter of generated avatars as one colour. Needs 4.5:1 on `avatar-bg` |
| `--glowthread-avatar-bg-saturation` | `58%`, night `26%` | Saturation of the avatar ground |
| `--glowthread-avatar-bg-lightness` | `89%`, night `26%` | Lightness of the avatar ground |
| `--glowthread-avatar-fg-saturation` | `52%`, night `70%` | Saturation of the avatar letter |
| `--glowthread-avatar-fg-lightness` | `28%`, night `84%` | Lightness of the avatar letter. With the default tones every hue meets 4.5:1 |

#### Icons

| Property | Default | Used for |
|---|---|---|
| `--glowthread-icon-size` | `1rem` | Icons in buttons and menus. Smaller and larger icons (12, 18, 20 and 24 px) scale with it |
| `--glowthread-icon-size-small` | `0.875rem` | Icons next to small text: chevrons, flags, the hub's actions |
| `--glowthread-icon-stroke` | `2px` | Stroke width of icons in px, measured on their 24 px drawing and scaled with the icon. Check marks are 1.5 times as bold |

#### Motion

| Property | Default | Used for |
|---|---|---|
| `--glowthread-duration-fast` | `150ms` | Menus opening, hover and colour changes |
| `--glowthread-duration-base` | `200ms` | Sheets sliding in, new comments, fades |
| `--glowthread-duration-exit` | `150ms` | Sheets and fades closing. Menus and popovers close in two thirds of it |
| `--glowthread-ease-out` | `cubic-bezier(0.16, 1, 0.3, 1)` | All entrances |
| `--glowthread-ease-in` | `ease-in` | All exits |

#### Layout

| Property | Default | Used for |
|---|---|---|
| `--glowthread-text-max-width` | `46em` | Longest line of comment text |
| `--glowthread-sheet-width` | `440px` | Width of side sheets from 640 px (never wider than the screen) |
| `--glowthread-toast-max-width` | `420px` | Widest toast |
| `--glowthread-toast-offset` | `24px`, phones `72px` | Distance of toasts from the bottom of the screen. Phones add 48 px to clear bottom bars such as Citizen's |

#### Layers

| Property | Default | Used for |
|---|---|---|
| `--glowthread-z-sheet` | `450` | Sheets, their scrim and dialogs |
| `--glowthread-z-popover` | `470` | Menus, popovers and the @mention list, also above a sheet |
| `--glowthread-z-toast` | `480` | Toasts |
<!-- glowthread-tokens:end -->

### Fonts

To use another font, set the whole stack:

```css
:root { --glowthread-font-family: "Inter", "Noto Sans", sans-serif; }
```

`--glowthread-font-family-text` changes only comment text and report notes, so a reading face does not reach the buttons ([serif reading](#2-serif-reading)).

To change only the CJK faces, set `--glowthread-font-cjk`. MediaWiki puts the interface language on `<html lang>`, and GlowThread picks Japanese, Korean or Traditional Chinese faces by it. A value you set applies to every language. Add `:lang()` to the selector to set faces for one language:

```css
:root { --glowthread-font-cjk: "Noto Sans CJK SC", "Microsoft YaHei"; }
:root:lang(ja) { --glowthread-font-cjk: "Noto Sans CJK JP", "Yu Gothic"; }
```

### Sizes on phones

A text size you set also moves the phone size, which stays one step larger. Set the `-phone` token only when the phone size should be something else:

```css
:root {
    --glowthread-font-size-body: 0.9375rem;
    --glowthread-font-size-body-phone: 1rem;
}
```

### Avatars and writing direction

Avatars are monograms on a hue taken from the user name. Every hue meets 4.5:1 with the default tones. If you change the four tone tokens, check a few names in both modes. `avatar-bg` and `avatar-fg` replace the name hue with one colour ([square avatars](#4-square-avatars)).

No token name says left or right, so a token means the same in both writing directions. For a right-to-left interface language (Arabic, Hebrew, Persian and others) MediaWiki sets `dir="rtl"` on `<html>`, and ResourceLoader serves both app stylesheets mirrored: left and right swap. Icons that point along the reading direction (chevrons, arrows, the reply corner, send) are mirrored too, menus and arrow keys follow the page direction, and the side sheet opens from the left. Comment text and user names keep their own direction (`dir="auto"`), and a comment body is only as wide as its text, so a short English comment in a right-to-left interface still sits next to its avatar.

## Light, night, dark-only and day-only

GlowThread draws in light mode unless a night switch is on. The switches are classes:

| Switch | Set by | GlowThread uses |
|---|---|---|
| `skin-theme-clientpref-night` on `<html>` | Vector 2022, Minerva and Citizen, when the reader picks dark | Night values |
| `skin-theme-clientpref-os` on `<html>` | The same skins, when the reader picks automatic | Night values while the system is dark (`prefers-color-scheme: dark`), light values otherwise |
| `ext-glowthread-night` on `<html>` or `<body>` | `$wgGlowThreadTheme = 'night'`, a skin, a gadget or site JavaScript | Night values |
| `ext-glowthread-day` on `<html>` or `<body>` | `$wgGlowThreadTheme = 'day'`, a skin, a gadget or site JavaScript | Light values, also under every switch above |
| None of these | | Light values |

`ext-glowthread-night` and `ext-glowthread-day` work on any ancestor of an app, but only on `<html>` or `<body>` do they reach the overlays as well.

Vector 2022 and Minerva set the theme classes when the wiki offers dark mode. Citizen 3 sets them too, also with its pure black option, which keeps `skin-theme-clientpref-night`. Legacy Vector has no night mode, so GlowThread stays light there unless a switch class is set.

### Night values

Night mode reads `--glowthread-night-<name>` in place of `--glowthread-<name>`, each with GlowThread's night default. A theme that sets only light values leaves night mode as GlowThread ships it, and night values take the same plain selector:

```css
:root {
    --glowthread-color-accent: #3366cc;
    --glowthread-night-color-accent: #88a3e8;
}
```

The tokens with a Night column in the colour table have night values, and so do `shadow-overlay`, `avatar-bg`, `avatar-fg` and the four avatar tones. Sizes, fonts, motion and layout are the same in both modes. In night mode `color-focus-ring` follows the night accent, while `color-accent-badge` has a night default of its own: set `--glowthread-night-color-accent-badge` together with `--glowthread-night-color-accent-subtle`. For a token with a night value, a `--glowthread-*` value under a night selector (for example `html.skin-theme-clientpref-night`) does not change night mode. A token without one, such as a size or a radius, set under a night selector applies in night mode only.

### `$wgGlowThreadTheme`

`$wgGlowThreadTheme` in `LocalSettings.php` fixes the mode for the whole wiki ([configuration](configuration.md#localsettings-variables)). `'auto'`, the default, follows the switches above. `'night'` adds `ext-glowthread-night` and `'day'` adds `ext-glowthread-day` to `<html>`, on article views that carry the comment section and on Special:GlowThread. Other values count as `'auto'`.

The section has no background of its own, so the page behind it must have the same tone: use `'night'` only where the page is always dark, and `'day'` only where the page stays light, also while the skin's night mode is on. Otherwise the section's text lands on a page of its own tone.

### Dark-only sites

A site that is always dark has three ways, simplest first:

1. `$wgGlowThreadTheme = 'night';` in `LocalSettings.php`.
2. A skin, gadget or script that adds `ext-glowthread-night` to `<html>` ([example 5a](#5a-dark-only-with-the-switch-class)).
3. CSS alone: the night palette as the light values ([example 5b](#5b-dark-only-in-css-alone)). Prefer the first two: in the hub, the check mark of selected items is an image that turns dark only under a night switch.

### Day-only sites

`$wgGlowThreadTheme = 'day'`, or `ext-glowthread-day` on `<html>`, keeps GlowThread light while the skin's night classes are on. It suits a site whose content area stays light in every mode.

### Other dark themes

A skin theme with a class GlowThread does not know does not switch it: `skin-theme-clientpref-black` in the Citizen 4 preview, a wiki-defined Citizen theme `skin-theme-clientpref-<value>`, or a gadget's own dark class. Add `ext-glowthread-night` to `<html>` while that theme is on, or put the palette of [example 5b](#5b-dark-only-in-css-alone) under the theme's selector, for example `html.skin-theme-clientpref-black` in place of `:root`.

## Part hooks

Site CSS selects GlowThread's parts by stable class names, `ext-glowthread-<part>`, and their state by `data-glowthread-*` attributes. Use them for a token on one part, or for a plain CSS property the tokens do not cover. The tables come from [`client/src/design/parts.ts`](../client/src/design/parts.ts).

### Parts and state attributes

<!-- glowthread-parts:start -->
Each part is a class on one kind of element. The parts marked "yes" under Custom properties resolve every custom property again: a `--glowthread-*` property set on one of them applies inside it. Plain CSS properties need a selector with three IDs to outweigh GlowThread's own rules, which carry two: `#glowthread#glowthread#glowthread .ext-glowthread-name { font-weight: 600 }` in the comment section, `#glowthread-portal` three times for the overlays, `#ext-glowthread-admin` three times for the hub. Such a rule applies in light and night mode alike, so prefer the custom properties for colours.

#### Comment section, inside `#glowthread`

| Class | Custom properties | What it is |
|---|---|---|
| `ext-glowthread-section` |  | The comment section |
| `ext-glowthread-header` |  | The row with the heading and the sort tabs |
| `ext-glowthread-heading` |  | The section heading (Comments) |
| `ext-glowthread-count` |  | The number of comments in the heading |
| `ext-glowthread-sort` |  | The sort tabs (Top, Newest) |
| `ext-glowthread-modtools` |  | Moderator tools above the list: the Awaiting review and Reported filters, the page state, Select |
| `ext-glowthread-composer` | yes | The composer, on the page and under a comment while replying |
| `ext-glowthread-composer-field` |  | The frame of the text field (border and background) |
| `ext-glowthread-composer-toolbar` |  | The row under the text field: emoji, @, the character count, Cancel and Send |
| `ext-glowthread-send` |  | The Send button |
| `ext-glowthread-gate` | yes | In place of the composer when the reader cannot post (logged out, blocked, page locked) |
| `ext-glowthread-popular` | yes | Top comments, above the Newest list |
| `ext-glowthread-linked` | yes | The Conversation box above the list, for a linked comment that is not in the list |
| `ext-glowthread-list` |  | A list of comments: one sort order (pinned comments first) or a moderator filter |
| `ext-glowthread-comment` | yes | One comment with its replies, which are comments inside it |
| `ext-glowthread-comment-row` |  | The comment itself, without its replies, drawn on `comment-bg` |
| `ext-glowthread-meta` |  | Floor number and time |
| `ext-glowthread-menu-trigger` |  | The ⋯ button of a comment, and the page state button of the moderator tools |
| `ext-glowthread-actions` |  | The row under the text: like and Reply |
| `ext-glowthread-like` |  | The like button with its count |
| `ext-glowthread-reply` |  | The Reply button |
| `ext-glowthread-replies` |  | The replies under a comment, with the line beside them |
| `ext-glowthread-replies-toggle` |  | Show more replies and Hide replies |
| `ext-glowthread-mod-notes` | yes | Why a comment is held or reported, with the moderation buttons (moderators only) |

#### Overlays, inside `#glowthread-portal`

| Class | Custom properties | What it is |
|---|---|---|
| `ext-glowthread-menu` | yes | A menu: the ⋯ menu of a comment, the page state menu |
| `ext-glowthread-menu-item` |  | One item of a menu |
| `ext-glowthread-popover` | yes | A popover: the report form from 640 px, the emoji picker |
| `ext-glowthread-emoji` | yes | The emoji picker (also a popover) |
| `ext-glowthread-mentions` | yes | The @mention suggestions |
| `ext-glowthread-report` | yes | The report form, in a popover or, on phones, in a sheet |
| `ext-glowthread-sheet` | yes | A sheet: from the bottom on phones, from the side from 640 px (the report form on phones, the hub's record and shortcuts) |
| `ext-glowthread-dialog` |  | The hub's dialog that confirms erasing a comment |
| `ext-glowthread-toast` | yes | A toast message |

#### Moderation hub, inside `#ext-glowthread-admin`

| Class | Custom properties | What it is |
|---|---|---|
| `ext-glowthread-hub` |  | The moderation hub |
| `ext-glowthread-hub-nav` | yes | Navigation: Queue, Settings, the log, the word filter, shortcuts |
| `ext-glowthread-queue` |  | The queue view: tabs, filters and items |
| `ext-glowthread-queue-tabs` |  | The queue tabs |
| `ext-glowthread-queue-item` | yes | One comment in the queue |
| `ext-glowthread-record` | yes | The author or page record: beside the queue, or in a sheet (in the overlay container) when the hub is narrower than 720 px |
| `ext-glowthread-settings` | yes | The settings view |
| `ext-glowthread-filter-editor` | yes | The word filter in the settings |

#### Both apps and their overlays

| Class | Custom properties | What it is |
|---|---|---|
| `ext-glowthread-avatar` |  | An avatar. `avatar-bg` and `avatar-fg` set on it apply to it |
| `ext-glowthread-name` |  | An author's name |
| `ext-glowthread-body` |  | The text of a comment |
| `ext-glowthread-badge` |  | A status label: Pinned, Awaiting review, Reported, Hidden, and in the hub blocks, user groups and frequent reporters |
| `ext-glowthread-notice` |  | A message in the page: comments closed or turned off, the hub's notices |
| `ext-glowthread-button` |  | A button: Reply, Send, Load more, Approve and the like |
| `ext-glowthread-tab` |  | One tab of the sort tabs or the queue tabs |
| `ext-glowthread-empty` |  | No comments yet, or an empty queue |
| `ext-glowthread-loading` |  | Placeholders while loading |
| `ext-glowthread-error` |  | A failed load: the error state with Try again, the line above Load more, the line under replies |
| `ext-glowthread-load-more` |  | The Load more button |
| `ext-glowthread-bulk-bar` | yes | The bar for selected comments (Select in the section, the selection in the hub) |

#### State attributes

| Attribute | On | Values | Meaning |
|---|---|---|---|
| `data-glowthread-depth` | `ext-glowthread-comment` | `0`, `1`, `2` | Level as shown: 0 at the top of a list, 1 and 2 for replies |
| `data-glowthread-status` | `ext-glowthread-comment`, `ext-glowthread-queue-item` | `visible`, `held`, `deleted`, `erased` | `held` is awaiting review, `deleted` is hidden by a moderator or deleted by its author, `erased` has lost its text and author. Readers see only `visible` comments and their own `held` ones |
| `data-glowthread-pinned` | `ext-glowthread-comment` | present or absent | On a pinned comment (not on its replies) |
| `data-glowthread-own` | `ext-glowthread-comment` | present or absent | On the reader's own comments |
| `data-glowthread-tone` | `ext-glowthread-badge`, `ext-glowthread-notice` | `neutral`, `accent`, `warning`, `danger`, `success` | The colour of a badge or notice (notices: neutral, warning, danger) |
| `data-glowthread-variant` | `ext-glowthread-button` | `primary`, `secondary`, `ghost`, `subtle`, `danger`, `link` | `primary` is the one main action of a view (Send, Send report) |
<!-- glowthread-parts:end -->

### Tokens on a part

The parts marked "yes" resolve every token again, so a token set on one of them applies inside it. Square menus, the frame and its items:

```css
.ext-glowthread-menu {
    --glowthread-radius-surface: 0;
    --glowthread-radius-control: 0;
}
```

A token set on any other part does not change that part itself, apart from `avatar-bg` and `avatar-fg` on `ext-glowthread-avatar`. It still reaches the parts marked "yes" inside it, so `--glowthread-comment-bg` on `ext-glowthread-list` tints every comment of the list but not the gaps between them. Replies are comments inside their parent comment and inherit its values: [example 7](#7-pinned-comments-on-a-tint) tints pinned comments and resets their replies.

### Plain CSS properties

GlowThread's own rules weigh two IDs and one or more classes, so site rules with one ID, and most with two, lose to them on purpose. A rule with three IDs outweighs every one of them without `!important`. Repeating an ID in a selector is valid CSS and adds its weight each time. Use the container the part renders in: overlays, and the hub's record when it opens in a sheet, render in `#glowthread-portal`.

```css
/* MediaWiki:Common.css */
/* The comment section */
#glowthread#glowthread#glowthread .ext-glowthread-name { font-weight: 600; }
/* Menus, popovers, sheets, toasts and the @mention list (the overlay container at the end of <body>) */
#glowthread-portal#glowthread-portal#glowthread-portal .ext-glowthread-menu { border-radius: 0; }
/* The moderation hub */
#ext-glowthread-admin#ext-glowthread-admin#ext-glowthread-admin .ext-glowthread-queue-item { padding-top: 1rem; padding-bottom: 1rem; }
```

Such a rule also outweighs the app's hover, focus, selected and open states for that property, so give it its own `:hover` where it matters. It applies in light and night mode alike. Inline styles still win (the positions Floating UI sets on overlays), and so do the app's few `!important` rules (heading faces, paragraph margins in the hub, `[hidden]`, reduced motion): set `--glowthread-font-family` for the faces. Prefer a token where one exists, colours above all: it reaches every part that uses it, with its own night value.

### Internal names

The public names are the tokens, the part classes and attributes above, `ext-glowthread-theme` and `data-glowthread-app`, the switch classes `ext-glowthread-night` and `ext-glowthread-day`, the IDs `glowthread`, `glowthread-portal` and `ext-glowthread-admin`, and the page classes this page names: `ext-glowthread` on the container, `ext-glowthread-admin` on the hub's container, the notice `ext-glowthread-nojs`, and the reservation classes `ext-glowthread-reserve` and `ext-glowthread-size-N`. Everything else is internal and can change in any release: the `gt-*` classes (`gt-scope`, `gt-root`, `gt-hit` and the others), the `gt:` utility classes, the `--gt-v-*` values the tokens resolve into and the other `--gt-*` properties (`--gt-avatar-hue`, `--gt-lang-font-cjk`, `--gt-reserve`, `--gt-init-fill`, `--gt-sticky-top`), the `data-gt-*` attributes, the `data-comment` and `data-pinned` attributes, and the elements between the parts. Select a part by its class, not by its place in the tree.

## Example themes

[`client/src/design/example-themes.css`](../client/src/design/example-themes.css) holds blocks to copy into `MediaWiki:Common.css` or a skin's CSS page. GlowThread does not load the file. Each block works with a plain selector, in any order, alone or together with the others. Where two blocks set the same token (2 and 3 both set `line-height-body`, 1 and 5b both set the accent), the block that comes later wins. The end-to-end tests apply each block on its own (`tests/e2e/specs/theming.spec.ts`).

### 1. Brand colour

The block in [quick start](#quick-start): a blue accent, light and night. Its contrast is checked like the defaults. Light: `#3366cc` marks 5.37:1 on white, `#2a4b8d` text 8.44:1 on white and 7.37:1 on its tint `#eaf0fb`, white on the `#2a4b8d` button 8.44:1 (hover 10.23:1). Night: `#88a3e8` marks and text 6.72:1 on the night surface, 5.08:1 on the Pinned badge and 4.64:1 on its 20% tint, ink `#171717` on the `#6d8fe0` button 5.68:1 (hover 7.21:1). With your own colours, check the same pairs.

### 2. Serif reading

Comment text and report notes in a serif face, larger and with looser lines. The controls keep the interface face.

```css
:root {
    --glowthread-font-family-text: Georgia, "Noto Serif", "Source Han Serif SC", "Songti SC", serif;
    --glowthread-font-size-body: 0.9375rem;
    --glowthread-line-height-body: 1.75;
}
```

### 3. Compact density

A smaller spacing unit, tighter rows and smaller avatars. Padding, gaps and control heights are multiples of `--glowthread-space`, so one token scales them. Touch targets on touch screens stay 44 px.

```css
:root {
    --glowthread-space: 0.2rem;
    --glowthread-comment-padding-y: 0.625rem;
    --glowthread-avatar-gap: 0.5rem;
    --glowthread-avatar-size: 1.75rem;
    --glowthread-line-height-body: 1.5;
}
```

### 4. Square avatars

Square corners. The commented lines give every avatar one neutral colour in place of the colour from the name. Keep 4.5:1 between the two.

```css
:root {
    --glowthread-avatar-radius: 4px;
    /* --glowthread-avatar-bg: #e8e8e8;
       --glowthread-avatar-fg: #404040;
       --glowthread-night-avatar-bg: #33363b;
       --glowthread-night-avatar-fg: #ededed; */
}
```

### 5a. Dark-only, with the switch class

No CSS to copy: put the switch class `ext-glowthread-night` on `<html>` or `<body>`, and GlowThread uses its night values and any `--glowthread-night-*` the site sets. `$wgGlowThreadTheme = 'night'` does it for the whole wiki. A skin adds the class with `OutputPage::addHtmlClasses()`, a gadget or `MediaWiki:Common.js` with:

```js
document.documentElement.classList.add( 'ext-glowthread-night' );
```

`ext-glowthread-day` does the opposite: light values even under the skin's night classes.

### 5b. Dark-only, in CSS alone

The night palette as the light values. Copy all of it: the values only work together (`on-accent` turns dark because `accent-bg` turns light). Prefer 5a where you can: the hub's selection checkboxes draw their check mark as an image that is white unless night mode is on, so here it stays white on the light pink fill.

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

### 6. One app only

The app roots and the overlay container carry `data-glowthread-app` (`comments` or `hub`). This block makes the moderation hub denser, its menus and sheets included, and leaves the comment section as it is.

```css
.ext-glowthread-theme[data-glowthread-app="hub"] {
    --glowthread-space: 0.2rem;
    --glowthread-font-size-body: 0.8125rem;
}
```

### 7. Pinned comments on a tint

A token set on a comment applies to that comment and to the replies inside it, which inherit it. The second rule keeps the replies untinted. Contrast on the light tint: `fg` 16.9:1, `fg-muted` 5.4:1, `accent-text` 5.1:1.

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

## Accessibility

The defaults meet WCAG 2.2 AA in light and night mode, and the unit tests check the default colour pairs. Values you set are yours to check.

- **Contrast.** The targets are under the colour table: text colours 4.5:1 on `surface` and on your page background, `accent` and `border-control` 3:1, status colours 4.5:1 on their own `-subtle` ground, `on-accent` 4.5:1 on `accent-bg`, the avatar letter 4.5:1 on its ground. Check them in light and night mode, on every skin you offer (the section has no background, so the page shows through), and on the tints you add with `comment-bg` or `highlight`. Keep `color-focus-ring` at 3:1 against the page and the surfaces, and do not set `focus-ring-width` to 0.
- **Touch targets.** On touch screens controls answer taps in at least 44 by 44 px, and on phones menu items and option rows are 44 px tall. These sizes are fixed on purpose and do not follow `--glowthread-space`, so a compact theme keeps them.
- **Text size.** The default sizes are in rem, so they follow the reader's browser text size. Keep yours in rem or em. With the defaults no text is smaller than 12.5 px on desktop and 13 px on phones, apart from 12 px badges, 12 px letters of small avatars and 11 px key hints.
- **Reduced motion.** When the reader's system asks for reduced motion, GlowThread shortens every animation and transition to 1 ms, whatever the duration tokens say, and a comment opened by a link keeps a static tint in place of the fade.
- **Forced colours.** In forced colours mode (Windows contrast themes) the browser replaces the colours with the reader's system colours, so the colour tokens have no effect there. The focus ring is an outline and stays visible, and the hub's checkboxes fall back to the system control. One exception: the @mention suggestion chosen with the arrow keys is marked by an inset shadow, which this mode removes, so only screen readers learn which one is chosen. Do not carry meaning by colour alone: a tint you add for pinned or own comments disappears in that mode, while GlowThread's own badges keep their text.

## Placing the section

The container is the one element you may position, size and space with site CSS. The app mounts in a child of it, so your rules on the container stay in effect after the app loads.

```css
/* A narrower comment column, centred under the article */
#glowthread {
    max-width: 48rem;
    margin-left: auto;
    margin-right: auto;
}
```

Where the loader puts the container:

1. It looks for the skin's `#bodyContent`, or `#mw-content-text` when the skin has none. It ignores elements with these IDs inside page text, in the page heading or in indicators.
2. It reuses an existing `#glowthread` only when that element sits where it would put one (next to or inside the anchor). Page text cannot capture or hide the section.
3. Otherwise it creates the container right after the anchor. It puts the container last inside the anchor instead on Minerva, on Vector 2022, and wherever the anchor's parent is a CSS grid with no row for the container (Citizen, whose page grid has a content row and then the footer). There the section still sits below the article and above the footer, in the space the server reserved inside `#bodyContent`, so a side column that grows after load (Vector 2022's pinned tools) does not push it down. Except on Minerva, site CSS that gives `#glowthread` a grid row (`grid-row` or `grid-area`) keeps it after the anchor, as an item of that grid.

A site rule that gives `#glowthread` a grid area keeps it a grid item of the anchor's parent. Use that to place the section in another grid area of your skin or layout.

### Reserved space

So that the footer does not jump when comments load, the server reserves the section's height before the first paint. Eligible page views get the styles module `ext.glowThread.reserve` and two body classes: `ext-glowthread-reserve` and `ext-glowthread-size-N`, where N steps with the number of comments the page has had (or `off` when comments are turned off on the page). The reservation is an empty block drawn as `#bodyContent::after`. The loader replaces it with a skeleton of the same height in one frame, and the app keeps that height until the list is drawn. Readers without JavaScript get no reservation.

If your site CSS changes the section a lot (a much larger margin, a sidebar layout), the reserved height no longer matches and the page may shift once. Also watch for:

- Styling `#bodyContent::after` in site CSS. It collides with the reservation.
- A layout without `#bodyContent` (some custom skins). The loader then falls back to `#mw-content-text`, and no space is reserved, because the reservation is drawn on `#bodyContent`.

## What not to override

| Do not | Why |
|---|---|
| Style elements inside the apps with ordinary selectors (`#glowthread button`, `.gt-root h2`) | They lose to the scoped rules, which weigh two IDs. Use a token, or a part hook with three IDs ([plain CSS properties](#plain-css-properties)) |
| Write broad `!important` rules such as `h2 { font-family: serif !important }` or `.mw-body p { margin: 1em 0 !important }` | `!important` beats GlowThread's scope. GlowThread defends two common cases (heading fonts in the apps, paragraph margins in the hub) and no others |
| Use the internal names (`gt-*` classes, `gt:` utilities, `--gt-*` properties, `data-gt-*` attributes) | They are not a public interface and can change in any release ([internal names](#internal-names)) |
| Hide, move or restyle `#glowthread-portal` | Menus, sheets and toasts render there. It must stay a direct child of `<body>`. Tokens set on it are fine |
| Put fixed or sticky elements above `z-index` 450 | Sheets sit at 450, menus and popovers at 470, toasts at 480. A cookie banner or sticky header above them covers them. Move GlowThread's layers with `--glowthread-z-sheet`, `--glowthread-z-popover` and `--glowthread-z-toast` if you must |
| Give `--glowthread-color-bg` an opaque colour | The section is designed to sit on the skin's page. The washes for hover and pressed states are translucent and assume the page shows through |
| Set a colour as `--glowthread-*` under a night selector to change night mode | For tokens with a night value, night mode reads only `--glowthread-night-*`, so such a rule has no effect there ([night values](#night-values)) |

A few values are fixed and cannot be themed: the 44 px touch targets ([accessibility](#accessibility)), the 11 px keyboard hints in the hub, the 15 px letter of large avatars, the 20 px emoji in the picker, the timing of the link highlight (2.4 s), the loading pulse (1.6 s) and the spinner (0.8 s), the 640 px breakpoint and the hub's layout widths (720 px and 1080 px), the widths of the emoji picker (312 px), the erase dialog (440 px) and the settings view (760 px), the 72 px lift of the sticky bars on phones, the check mark of the hub's selection checkboxes (white in light mode, `#171717` in night mode, whatever `color-on-accent` says), and the grey of the loader's skeleton before the app arrives. The settings heading in the hub is 1.125 times `--glowthread-font-size-title`, so it follows that token.

## Skin notes

GlowThread looks the same on every skin rather than imitating each one. These are the skins it is tested on:

| Skin | Where the section goes | Notes |
|---|---|---|
| Vector (legacy) | After `#bodyContent`, inside the content box | No night mode. A dark gadget or site theme turns GlowThread dark with a switch class ([dark-only sites](#dark-only-sites)) |
| Vector 2022 | Last inside `#bodyContent`, below the article, so the pinned tools column cannot push it down | Night mode through the theme classes. The moderation hub keeps its sticky parts below Vector 2022's sticky header |
| Citizen | Last inside `#bodyContent`, above the footer, because Citizen's page grid has no row for it. Site CSS that gives `#glowthread` a grid row keeps it after `#bodyContent` | Night mode through the theme classes. The themes of the Citizen 4 preview need a switch ([other dark themes](#other-dark-themes)). On phones the sticky bars (bulk actions, the settings save bar) keep 48 px free on the right for Citizen's floating page actions |
| Minerva (MobileFrontend) | Last inside `#bodyContent`, so it shares Minerva's side gutters | Night mode through the theme classes. MobileFrontend collapses article sections, so the comments often start just below the first screen |

Other skins work when they have `#bodyContent` or `#mw-content-text`, but they are not tested. Check the section's placement, its night mode (if the skin has one, or `$wgGlowThreadTheme` if the skin is always dark), and that the skin's fixed headers or bars do not cover toasts and sheets.

On every skin, phones (narrower than 640 px) get bottom sheets, larger text and 44 px touch targets, and toasts and sticky bars float 72 px above the bottom of the viewport so they clear bottom bars such as Citizen's. From 640 px sheets open from the right side, toasts sit 24 px (`--glowthread-toast-offset`) and sticky bars 16 px above the bottom. In the hub, toasts move 64 px higher while the bulk bar shows.
