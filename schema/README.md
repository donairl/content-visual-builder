# Shared contract — Content Visual Builder

This directory is the single source of truth shared by the **editor** (`editor/`, TypeScript) and the
**backend** (`backend/`, PHP/Yii3). Both sides load these JSON files at runtime/build time. Anything
described here must behave identically on both sides; `fixtures/` holds the cases that prove it.

| File | Purpose |
|---|---|
| `config.json` | Schema version, devices, breakpoints, limits, shared regex patterns, page-settings spec |
| `style-properties.json` | Every style key: value type, CSS property, units, ranges. **Key order = CSS declaration order.** |
| `controls.json` | Widget palette order, categories, control groups, common Advanced-tab controls |
| `widgets/*.json` | One manifest per widget: props, style targets, default styles, panel controls |
| `fonts.json` | Font keys → CSS font stacks (system fonts only, no web-font downloads) |
| `icons.json` | Icon keys → trusted inner SVG markup |
| `base.css` | Static widget base styles, loaded before generated CSS in both editor and public pages |
| `fixtures/documents/*.json` | Parity fixtures: TS and PHP must render identical HTML (DOM-equal) and CSS (byte-equal); an optional `{name}.context.json` holds the site data (`globals`) the fixture renders against |
| `fixtures/validation/*.json` | Validation cases both validators must agree on |
| `globals.json` | Default global styles (§9), used until the site saves its own |
| `templates/*.json` | Built-in templates (§11): `{ name, kind, description, document }` |

Patterns in `config.json` are written for both JS (`new RegExp(p, 'u')`) and PCRE (`'~' . $p . '~u'`).
They avoid syntax that differs between the engines (`\s`, `\d` and Unicode classes are not used).

---

## 1. Document format (`schemaVersion: 1`)

```jsonc
{
  "schemaVersion": 1,
  "rootIds": ["k3j9x0aa"],                  // top-level node ids, in order
  "nodes": {                                // flat map, key === node.id
    "k3j9x0aa": {
      "id": "k3j9x0aa",                     // ^[a-z0-9]{8}$
      "type": "container",                  // a key of widgets/*.json
      "props": { "tag": "section" },        // content settings, typed by the manifest
      "style": {                            // style key → responsive value
        "padding": { "desktop": { "top": 40, "right": 20, "bottom": 40, "left": 20, "unit": "px" },
                     "mobile":  { "top": 20, "unit": "px" } }
      },
      "advanced": { "cssId": "", "cssClasses": "", "hideOn": [], "customCss": "" },
      "children": ["p0q1r2s3"]              // ordered child ids; [] for leaf widgets
    }
  },
  "settings": { "metaDescription": "", "contentWidth": 1140, "backgroundColor": null, "textColor": null, "fontFamily": null,
                "customCss": "" }
}
```

Structural rules (validator MUST enforce):

- Every node is reachable exactly once from `rootIds` (no orphans, no shared children, no cycles).
- `nodes[key].id === key`; ids match `patterns.nodeId`.
- `children` must be `[]` unless the widget has `acceptsChildren: true`.
- Depth ≤ `limits.maxDepth` (a root node has depth 1); node count ≤ `limits.maxNodes`.
- Raw JSON size of the document ≤ `limits.maxDocumentBytes`.
- Unknown keys are rejected at every level (document, node, props, style, advanced, settings, value objects).
- Missing `props` keys fall back to the manifest default **at render time**; the editor writes all defaults
  when it inserts a widget.

### Responsive values

`style[key]` is an object with optional keys `desktop`, `tablet`, `mobile`. A device value may be `null`
(meaning "unset", same as absent). Resolution for display: `mobile → tablet → desktop` fallback, which is
exactly what the desktop-first CSS cascade produces.

## 2. Value types

String lengths (`maxLength`, `maxUrlLength`, `metaDescription`) are counted in **Unicode code points**
(JS `[...s].length`, PHP `mb_strlen($s, 'UTF-8')`). Numbers are JSON numbers only (never strings/booleans);
"integer" means integer-valued (`5` and `5.0` both pass). "≤ 3 decimals" means
`abs(n * 1000 - round(n * 1000)) < 1e-6`.

### Prop types (`widgets/*.json` → `props`)

| type | JSON shape | Rules |
|---|---|---|
| `text` | string | length ≤ `maxLength`. Rendered **escaped**. |
| `richtext` | string (HTML) | length ≤ `maxLength`. Rendered **sanitized** (§6). |
| `enum` | string | one of `options` |
| `bool` | boolean | |
| `number` | number | finite, `min`/`max`, `integer` if set, ≤ 3 decimals |
| `link` | `{ url, newTab, nofollow }` | `url` is `""` or matches `patterns.linkUrl` (≤ `maxUrlLength`); flags are booleans; keys optional |
| `image` | `{ url, width, height, id }` | `url` is `""` or matches `patterns.mediaUrl`; `width`/`height` integer 1..20000 or null; `id` positive integer or null; keys optional |
| `videoUrl` | string | `""` or matches `patterns.videoUrl` |
| `icon` | string | a key of `icons.json → icons` |

### Style types (`style-properties.json`)

All numbers: finite, within `min`/`max` of the property (box/shadow components included), ≤ 3 decimal places.

| type | JSON shape | Declarations emitted (see §4 for `fmt`) |
|---|---|---|
| `color` | string matching `patterns.color` | `{css}:{value}` |
| `size` | `{ "value": n, "unit": u }`, `u` ∈ `units` | `{css}:{fmt(n)}{u}` (for each css if `css` is an array) |
| `number` | number | `{css}:{fmt(n)}` |
| `enum` | string ∈ `options` | `{css}:{value}` |
| `fontFamily` | string, key of `fonts.json` | `{css}:{fonts[key].stack}` |
| `box` | `{ top?, right?, bottom?, left?, unit }` — sides number or null, `unit` required | per non-null side in order top,right,bottom,left: `{css}-{side}:{fmt(n)}{unit}`; with `"corners": true` the sides map to `border-top-left-radius`, `border-top-right-radius`, `border-bottom-right-radius`, `border-bottom-left-radius` |
| `border` | `{ style?, width?, color? }` — style ∈ `none,solid,dashed,dotted,double,groove`; width 0..100 or null; color or null | in order: `{css}-style:{style}`, `{css}-width:{fmt(w)}px`, `{css}-color:{color}` (each only if set) |
| `shadow` | `{ x, y, blur, spread, color, inset }` — all required; x/y/spread −200..200, blur 0..300 | `box-shadow:{inset ? "inset " : ""}{x}px {y}px {blur}px {spread}px {color}` |
| `background` | `{ type, color?, gradientFrom?, gradientTo?, gradientAngle?, imageUrl?, imageSize?, imagePosition?, imageRepeat? }` | see below |

`background` (`type` ∈ `none,color,gradient,image`; colors match `patterns.color`; `gradientAngle` 0..360;
`imageUrl` matches `patterns.mediaUrl`; `imageSize` ∈ `auto,cover,contain`; `imagePosition` ∈
`center center,top center,bottom center,center left,center right,top left,top right,bottom left,bottom right`;
`imageRepeat` ∈ `no-repeat,repeat,repeat-x,repeat-y`). Only `type` is required; every other key is optional
and may be `null` (= unset); `imageUrl` may also be `""` (= unset). "Set" below means present, non-null and
non-empty. Keys irrelevant to `type` are validated but ignored:

- `none` → `background:none`
- `color` → `background-color:{color}` (nothing if `color` unset)
- `gradient` → `background-image:linear-gradient({fmt(angle ?? 180)}deg,{from},{to})` (nothing unless both colors set)
- `image` → `background-color:{color}` if set; then, if `imageUrl` set: `background-image:url("{imageUrl}")`,
  `background-size:{imageSize ?? cover}`, `background-position:{imagePosition ?? center center}`,
  `background-repeat:{imageRepeat ?? no-repeat}`

### Advanced (`node.advanced`)

| key | rule |
|---|---|
| `cssId` | `""` or `patterns.cssId` |
| `cssClasses` | `""` or `patterns.cssClasses` |
| `hideOn` | array of distinct device names |
| `customCss` | custom CSS (below), ≤ `limits.maxCustomCssLength` code points |

### Page settings (`document.settings`, spec in `config.json → settings`)

`metaDescription` text ≤ 300; `contentWidth` integer 320..3000; `backgroundColor`/`textColor` color or null;
`fontFamily` font key or null; `customCss` custom CSS ≤ 50000. All settings keys are optional (missing =
default from `config.json`); unknown keys are rejected. `advanced` keys are likewise optional (`""`, `""`,
`[]`, `""`).

### Custom CSS

Custom CSS is emitted verbatim inside a wrapper rule (§4) and relies on native CSS nesting for scoping, so a
value is only valid when the browser is guaranteed to see the same block structure as the validator — nothing
inside may close the wrapper. Scanning code point by code point (`core/customCss.ts`, `CustomCss.php`):

- `<` and control characters (U+0000–U+001F except tab/LF/CR/FF, and U+007F) are rejected anywhere.
- `/*` opens a comment that must be closed by `*/`.
- `"` or `'` opens a string closed by the same quote; inside, `\` skips the next code point (which must
  exist), and a raw LF/CR/FF is rejected.
- `\` outside strings and comments is rejected (an escape could hide a bracket from the validator).
- `url(` (ASCII case-insensitive, whatever precedes it), then optional whitespace: if a quote follows it is an
  ordinary function (push `)`); otherwise the body up to `)` must not contain quotes, `(`, `\`, `{`, `}`, `[`,
  `]` or whitespace other than whitespace directly before the `)`.
- `(`, `[`, `{` push; `)`, `]`, `}` must match the top of the stack; the stack must end empty.

Length is checked separately. Only conditional group rules (`@media`, `@supports`, …) work inside nesting;
other at-rules are ignored by browsers.

### Allowed style keys per widget

A node may only carry style keys referenced by its manifest controls (`content` + `style` tabs, with
`group` entries expanded via `controls.json → groups`) **plus** the keys of `controls.json → commonAdvanced`.
When a widget's own controls reference a key that also appears in `commonAdvanced`, the panel hides the
common control (the widget's `styleTargets` decide where that key applies).

## 3. Validation errors

Both validators return a list of `{ "path": string, "message": string }`. Paths are dot-joined:

```
document                              size limit
schemaVersion | rootIds | settings.{key}
nodes.{id} | nodes.{id}.id | nodes.{id}.type | nodes.{id}.children
nodes.{id}.props.{key}
nodes.{id}.style.{key} | nodes.{id}.style.{key}.{device}
nodes.{id}.advanced.{key}
```

Messages are free text; **paths must match** between TS and PHP for every case in `fixtures/validation/`.

## 4. CSS generation

`fmt(n)`: integers (or floats with no fractional part) print as integers (`10`, `-5`, `0`); otherwise
`toFixed(3)` / `sprintf('%.3F')` with trailing zeros and a trailing dot removed (`1.5`, `0.125`).
The validator guarantees ≤ 3 decimals, so both sides round identically.

Selector for a node style key: `.cvb-el-{id}` + (`:hover` if the property has `"state": "hover"`) +
(`manifest.styleTargets[key]` or `""`), concatenated verbatim, e.g. `.cvb-el-ab12cd34 > .cvb-container__inner`.

Algorithm (both modes):

1. **Settings rule** (first line, omitted if empty): `.cvb-root{...}` with, in order,
   `--cvb-content-width:{n}px`, `background-color:{c}`, `color:{c}`, `font-family:{stack}` for each set setting.
2. Walk nodes depth-first pre-order from `rootIds`. For each node, iterate style keys **in
   `style-properties.json` order**; skip keys the node lacks or the widget does not allow. For each device,
   take the device value; skip `null`/absent and **skip any value that fails validation** (so the generator
   is safe on its own). Append the declarations to the rule for its selector in that device's bucket.
   Rules keep first-insertion order; declarations keep append order.
3. A rule is printed as `{selector}{{decl};{decl}}` (declarations joined by `;`, no trailing `;`), one per line.

**Publish mode (`media`)**: lines joined with `\n`, no trailing newline:

```
<settings rule>
<desktop rules>
@media (max-width:1024px){
<tablet rules>
}
@media (max-width:767px){
<mobile rules>
}
@media (min-width:1025px){            ← hideOn desktop
.cvb-el-{id}{display:none}
}
@media (min-width:768px) and (max-width:1024px){   ← hideOn tablet
...
}
@media (max-width:767px){             ← hideOn mobile
...
}
```

Empty blocks are omitted. Breakpoint numbers come from `config.json → breakpoints`.

**Custom CSS** (both modes) follows everything else, one line per rule: `.cvb-root{<settings.customCss>}`, then
`.cvb-el-{id}{<advanced.customCss>}` for each node in walk order. Empty or invalid values are skipped.

**Editor mode (`editor`, device D)**: no at-rules. Output desktop rules, then (if D is tablet or mobile)
tablet rules, then (if D is mobile) mobile rules — the same cascade the media queries produce, but
driven by the selected device instead of the canvas width. Nodes hidden on D get
`.cvb-el-{id}{opacity:0.35}` instead of `display:none` so they stay selectable. Editor mode is not
part of the parity contract.

## 5. HTML rendering

Every node's root element carries `class="cvb-el cvb-el-{id} cvb-{type}[ modifiers][ cssClasses]"` and
`id="{cssId}"` when `cssId` is non-empty. Text and attribute values are HTML-escaped. Children are
concatenated without whitespace. Parity is checked on the parsed DOM (attribute order and entity style
don't matter).

Link attributes (`link` prop with non-empty `url`): `href="{url}"`; if `newTab`: `target="_blank"`; `rel` is
the space-joined list of `nofollow` (if `nofollow`) then `noopener noreferrer` (if `newTab`), omitted when empty.

| type | markup |
|---|---|
| container | `<{tag} class="… cvb-container cvb-container--{contentWidth}"><div class="cvb-container__inner">{children}</div></{tag}>` |
| heading | `<{tag} class="…">{text}</{tag}>`, or `<{tag} class="…"><a {link}>{text}</a></{tag}>` when `link.url` non-empty |
| text | `<div class="…">{sanitized html}</div>` |
| button | `<a class="… cvb-button--{size}" {link}>{text}</a>` when `link.url` non-empty, else `<span class="… cvb-button--{size}">{text}</span>` |
| image | `<figure class="…">` + (if `image.url`: `<img src alt loading="lazy" decoding="async" [width height]>`, wrapped in `<a {link}>` when `link.url` non-empty) + (if `caption`: `<figcaption class="cvb-image__caption">{caption}</figcaption>`) + `</figure>`. `alt` is always present (may be empty); `width`/`height` only when both are set. |
| video | `<div class="…">{player}</div>`; player per §7, empty if the URL is unknown/empty |
| spacer | `<div class="…" aria-hidden="true"></div>` |
| divider | `<hr class="…">` |
| component | `<div class="…">{content}</div>`: the component's published document rendered as in §10, empty if the component is unknown |
| icon | `<span class="…" {a11y}>{inner}</span>`. With a link: span has no a11y attrs and `inner = <a {link}[ aria-label="{label}"]>{svg}</a>`. Without a link: `role="img" aria-label="{label}"` if `label` non-empty, else `aria-hidden="true"`; `inner = {svg}`. `svg = <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">{icons[icon].svg}</svg>` |

### Public page document (backend only)

```html
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{page title}</title>
<meta name="description" content="{metaDescription}">        <!-- only if non-empty -->
<link rel="stylesheet" href="/assets/cvb-base.{hash}.css">
<link rel="stylesheet" href="/assets/page/{pageId}.{cssHash}.css">  <!-- only if CSS non-empty -->
</head>
<body style="margin:0">
<div class="cvb-root">{nodes}</div>
</body>
</html>
```

No JavaScript is emitted for Scope 1 widgets.

## 6. Rich-text sanitizing

Allowed elements: `p br strong b em i u s a ul ol li blockquote code`. Allowed attributes: `a[href]` (must
match `patterns.linkUrl`, otherwise the attribute is dropped), `a[target]` (only `_blank`, which forces
`rel="noopener noreferrer"`). Every other attribute is removed. Elements `script style iframe object embed
template noscript svg math form input textarea select button` are removed **with** their content; any
other disallowed element is unwrapped (children kept). Comments are removed.

The backend MUST sanitize at render time (publish/preview) and SHOULD also sanitize `richtext` props on
save. The editor MUST sanitize before injecting stored HTML into the canvas (another user may have
authored it) and on paste.

## 7. Video URLs

Matching uses these exact regexes on the whole URL (flag `i`), first match wins:

| provider | regex | embed |
|---|---|---|
| YouTube | `^https?://(?:www\.\|m\.)?(?:youtube\.com/(?:watch\?(?:[^#]*&)?v=\|embed/\|shorts/\|live/)\|youtu\.be/\|youtube-nocookie\.com/embed/)([A-Za-z0-9_-]{11})(?:[?&#/].*)?$` | `https://www.youtube-nocookie.com/embed/{id}` |
| Vimeo | `^https?://(?:www\.\|player\.)?vimeo\.com/(?:video/)?([0-9]{1,12})(?:[?#/].*)?$` | `https://player.vimeo.com/video/{id}` |
| File | `^https?://[^?#]+\.(?:mp4\|webm\|ogv\|ogg)(?:[?#].*)?$` | `<video>` |

(`\|` above is an escaped table pipe; the regex uses `|`.)

Embed query parameters, in this order, joined with `&` and prefixed with `?` when any exist:
YouTube — `autoplay=1` (autoplay), `mute=1` (muted or autoplay), `loop=1&playlist={id}` (loop),
`controls=0` (not controls). Vimeo — `autoplay=1`, `muted=1` (muted or autoplay), `loop=1`, `controls=0`.

Iframe: `<iframe src="{embed}" title="{title}" loading="lazy" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>`

File: `<video src="{url}" preload="metadata" playsinline[ controls][ autoplay][ muted][ loop]></video>`
(`muted` is forced when `autoplay` is set).

## 8. REST API (backend)

JSON in and out (`Content-Type: application/json`), session-cookie auth, CSRF token in the
`X-CSRF-Token` header on every non-GET request (missing/invalid → 422 from the CSRF middleware).
Errors: `{ "error": "message" }`, plus `"errors": [{path, message}]` on validation failures.

| Method | Path | Capability | Request | Success |
|---|---|---|---|---|
| GET | `/api/session` | logged in | — | `{ user: {id,name,email,role}, capabilities: string[], csrfToken }` |
| GET | `/api/pages` | `edit_pages` | `?q=&status=` | `{ items: PageSummary[] }` |
| POST | `/api/pages` | `edit_pages` | `{ title, slug? }` | 201 `PageDetail` |
| GET | `/api/pages/{id}` | edit (see ownership) | — | `PageDetail` |
| PATCH | `/api/pages/{id}` | edit | `{ title?, slug? }` | `PageSummary` |
| PUT | `/api/pages/{id}/draft` | edit | `{ document, baseVersion }` | `{ version, updatedAt }` |
| POST | `/api/pages/{id}/publish` | publish | `{ baseVersion }` | `PageSummary` |
| POST | `/api/pages/{id}/unpublish` | publish | — | `PageSummary` |
| DELETE | `/api/pages/{id}` | delete | — | 204 |
| POST | `/api/media` | `upload_files` | multipart `file` | 201 `{ id, url, width, height, mime, size }` |
| GET | `/api/pages/{id}/revisions` | edit | — | `{ items: RevisionSummary[] }`, newest first |
| GET | `/api/pages/{id}/revisions/{rid}` | edit | — | `RevisionSummary & { document }` |
| GET | `/api/components` | `edit_pages` | — | `{ items: ComponentSummary[] }` (§10) |
| POST | `/api/components` | `edit_pages` | `{ name, document }` | 201 `ComponentDetail` |
| GET | `/api/components/{id}` | `edit_pages` | — | `ComponentDetail` |
| PATCH | `/api/components/{id}` | edit (ownership) | `{ name }` | `ComponentSummary` |
| PUT | `/api/components/{id}/draft` | edit (ownership) | `{ document, baseVersion }` | `{ version, updatedAt }` |
| POST | `/api/components/{id}/publish` | publish (ownership) | `{ baseVersion }` | `ComponentSummary & { rerendered }` |
| DELETE | `/api/components/{id}` | delete (ownership) | — | 204 |
| GET | `/api/templates` | `edit_pages` | — | `{ items: TemplateSummary[] }` (§11) |
| POST | `/api/templates` | `edit_pages` | `{ name, kind, document }` | 201 `TemplateSummary` |
| GET | `/api/templates/{id}` | `edit_pages` | — | `TemplateSummary & { document }` |
| PATCH | `/api/templates/{id}` | edit (ownership) | `{ name }` | `TemplateSummary` |
| DELETE | `/api/templates/{id}` | delete (ownership) | — | 204 |
| GET | `/api/globals` | `edit_pages` | — | `{ globals, version, updatedAt }` (§9) |
| PUT | `/api/globals` | `edit_theme_options` | `{ globals, baseVersion }` | `{ version, updatedAt, rerendered }` (§9) |

```ts
PageSummary = { id, title, slug, status: 'draft'|'published', authorId, authorName, version,
                publishedVersion: number|null, hasUnpublishedChanges: boolean,
                createdAt, updatedAt, publishedAt: string|null, url: string /* "/{slug}" */ }
PageDetail  = PageSummary & { document: PageDocument }
```

```ts
RevisionSummary = { id, kind: 'draft'|'published', version, authorId, authorName, createdAt, updatedAt,
                    size /* document length */ }
```

**Revisions.** A successful `PUT draft` records the stored document: it updates the page's latest revision
instead when that one is a draft by the same author last updated less than 10 minutes ago, so an editing
session is one revision. `publish` always adds a `published` revision. Only the newest 50 revisions of a page
are kept; deleting a page deletes them. Restoring is an editor operation: the revision's document becomes a
normal (undoable) edit that autosave stores as the next draft version.

Status codes: 401 not logged in · 403 missing capability · 404 unknown page · 409 version conflict
(`PUT draft` / `publish` when `baseVersion` ≠ current: body `{ error, currentVersion, updatedAt }`) ·
413 document too large · 422 validation (`errors`) · 400 malformed JSON.

`PUT draft` is an atomic compare-and-swap (`UPDATE … WHERE id = ? AND draft_version = ?`); success
increments the version. `publish` renders the **stored** draft (after re-validating it) into
`published_*` columns; editing the draft afterwards never changes the live page.

Public routes: `GET /{slug}` (published only, else 404), `GET /assets/cvb-base.{hash}.css`,
`GET /assets/page/{id}.{hash}.css` (immutable cache), `GET /preview/{id}` (logged in + edit capability,
renders the draft, `X-Robots-Tag: noindex`). Reserved slugs: `api assets editor login logout preview uploads`.

### Capabilities

| capability | admin | editor | author |
|---|---|---|---|
| `edit_pages` | ✓ | ✓ | ✓ |
| `edit_others_pages` | ✓ | ✓ | |
| `publish_pages` | ✓ | ✓ | ✓ (own pages) |
| `delete_pages` | ✓ | ✓ | ✓ (own pages) |
| `delete_others_pages` | ✓ | ✓ | |
| `upload_files` | ✓ | ✓ | ✓ |
| `manage_users` | ✓ | | |
| `edit_theme_options` | ✓ | ✓ | |

Acting on a page owned by someone else additionally requires `edit_others_pages` (for edit/publish) or
`delete_others_pages` (for delete).

## 9. Global styles

Site-wide colors and typography presets that documents reference instead of repeating values
(`core/globals.ts`, `GlobalsValidator.php`, `SiteContext.php`). They are stored once per site
(`site_settings.name = 'globals'`); until saved, `globals.json` applies (version 0).

```jsonc
{
  "colors": [{ "id": "primary", "name": "Primary", "value": "#5b50e6" }],
  "fonts":  [{ "id": "display", "name": "Display",
               "style": { "fontFamily": { "desktop": "serif" }, "fontSize": { "desktop": { "value": 48, "unit": "px" } } } }]
}
```

Validation (paths as in §3): an object with exactly `colors` (≤ `limits.maxGlobalColors`) and `fonts`
(≤ `limits.maxGlobalFonts`) lists. Every entry has exactly `id`, `name` and `value`/`style`: `id` matches
`patterns.globalId` and is unique within its list (a repeat is reported at the later `{list}.{i}.id`); `name` is
a non-blank string ≤ `limits.maxGlobalNameLength` code points; a color `value` is a **literal** color
(`patterns.color`); a font `style` maps typography keys (the `typography` group without `globalFont`) to
responsive values valid for their style property.

References in documents:

- **Colors** — anywhere a document holds a color (style `color` values, border/shadow/background colors, page
  settings `backgroundColor`/`textColor`) it may hold `global:<id>` (`patterns.globalColor`).
- **Fonts** — style key `globalFont` (type `globalFont`, in the `typography` group, emits nothing itself) holds a
  font id; only its `desktop` value is used.

Rendering resolves references against the site's globals, built from valid entries only (an invalid entry or a
later repeated id is ignored):

- A color reference resolves to the global's literal value; a reference to a missing global is **unset**: a
  `color` declaration is skipped, a border/background color is treated as unset, a shadow is skipped.
- With a font preset, each typography key's value on each device is the node's own value, or else the preset's
  value for that device (so the preset fills every device the node leaves unset). Selectors, order and
  `styleTargets` are those of the node.

Saving the globals (`PUT /api/globals`, compare-and-swap on `version`; 409 body as for drafts) re-renders every
published page from its published snapshot, so live pages follow the new values; drafts are unaffected until
they are published or previewed.

## 10. Reusable components

A component is a named document stored once and shown by any number of **instances** (widget `component`,
prop `componentId`). Editing and publishing the component changes every instance on every page.

```ts
ComponentSummary = { id, name, authorId, authorName, version, publishedVersion, hasUnpublishedChanges,
                     createdAt, updatedAt, publishedAt }
ComponentDetail  = ComponentSummary & { document /* draft */, publishedDocument }
```

- A component document is a page document (§1) with **exactly one** root and **no `component` nodes**
  (components do not nest); violations are 422 at `rootIds` / `nodes.{id}.type`. Its `settings` are ignored.
- Like a page it has a draft (autosaved, compare-and-swap on `version`) and a published document; it is
  published at creation (version 1). Publishing or deleting a component re-renders every published page.
- Ownership follows pages (`*_others_pages` to act on someone else's component); reading is open to everyone
  who edits pages.

Rendering an instance uses the component's **published** document:

- HTML: the instance's wrapper (§5) contains the component's roots rendered as usual, except that node CSS ids are
  omitted (a component may appear several times on a page) and nested `component` nodes render nothing.
- CSS: walking the page (§4 step 2), the first instance of each component is followed by the component's nodes
  in pre-order (skipping `component` nodes), as if they were the instance's descendants; later instances of the
  same component add nothing. Their `hideOn` and custom CSS rules are emitted likewise.
- An unknown component (deleted, invalid id) renders an empty wrapper and no extra CSS.

Detaching an instance (editor) replaces it with a copy of the component's content with fresh ids.

## 11. Template library

Templates are documents copied into pages; unlike components (§10) nothing stays linked.

```ts
TemplateSummary = { id, name, kind: 'page'|'section', authorId, authorName, createdAt, updatedAt }
```

- `kind: "section"` — exactly one root (422 at `rootIds` otherwise), inserted after the top-level section that
  holds the selection, or at the end of the page.
- `kind: "page"` — any number of roots, appended to the page or replacing its content (page settings are kept).
- The document must be valid (§1–§3); names are 1–120 characters. Rename/delete follow ownership like pages.
- Built-in templates ship in `templates/*.json` and are always available in the editor; they use global
  colors and fonts (§9) so they follow the site's styles.
- Inserting gives every node a fresh id and drops CSS ids (as any copy does), as one undoable edit.

