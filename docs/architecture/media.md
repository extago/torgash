# Media and the rich-text editor

Architecture rules moved out of CLAUDE.md (2026-09-30), verbatim. CLAUDE.md keeps a one-line index;
this file is the reasoning. Read it before changing anything it covers.

- Media: spatie/laravel-medialibrary, disk `public` (`php.exe artisan storage:link`
  already run — while `public` is a local folder, files serve from `/storage/...`). Product photos are a `gallery`
  media collection. `image_url` is pictures BY EXTERNAL LINK — several,
  comma separated, https or the shop's own path, read only through
  `Product::externalImageUrls()`, only while the catalogue's `external_images`
  setting is on (off by default; the form warns why) and only where the
  product has no photos of its own. A form without the field keeps what is
  stored.
Images: all writes go through `Torgash\Media\ImageProcessor`; thumbnail sizes,
WebP and limits are the media module's settings. A model with a gallery calls
`Gallery::conversions($this)` from `registerMediaConversions()`. After changing
thumbnail settings, run the "Rebuild all thumbnails" action in its settings
(or `php.exe artisan media-library:regenerate --force`).

**A picture INSIDE a text is a second media collection, not a gallery photo.**
`Torgash\Media\Content::COLLECTION` (`content`), declared per model with
`MediaOwners::register(..., content: true)`, and the differences are the design:
no conversions (four thumbnails of a size chart is four files nothing requests —
the exception lives in `Gallery::conversions($model, $media)`, one place, not in
each model's `registerMediaConversions`), no order and no stored caption (where
it appears and what it says are properties of the `<img>`, so the same diagram
can be used twice with two alts), and the same ownership as the gallery — it
dies with the record, because a shop-wide file library is a place where nobody
knows what is safe to delete. Files are NEVER swept up on save: the panel marks
what the text no longer mentions and a person decides, since an illustration cut
out of a paragraph is usually going back in. The seam is two hooks facing
opposite ways — core forwards `:record` through `core.field.rich`,
`torgash.editor` asks `editor.images` for somewhere to put files, and
`torgash.media` answers with `['endpoint', 'allow_remote']`; neither module
names a class of the other's. **The button only exists after the first save** (no
id, nothing to hang a file on) — the rule the gallery card already follows.
Size is `data-size` on the tag (absent / `manual` / `full`) with width+height
always written for CLS, and `.torgash-rich img[data-size="full"]` is the one rule
that overrides them. TipTap's image node is registered UNCONDITIONALLY: a schema
without it makes ProseMirror drop every `<img>` it parses, so a description
written in the HTML source view would come back without its pictures. Adding
`@tiptap/extension-image` re-ran `tools/vendor-tiptap.php`, which pins
`@tiptap/*` and NOT the prosemirror-* packages under them — vendor into a temp
dir, diff, and take only what you meant to.
**Markup the schema cannot carry is an HTML BLOCK, never lost.** TipTap
drops whatever no extension describes (a div, a table, an iframe, any
`style`/`class`), and the next visual edit writes the text back without it.
`torgash.editor`'s `htmlblock.mjs` is an atom node holding its HTML verbatim,
saved as `<div data-html-block>`; `wrapUnrepresentable()` turns every
top-level element the schema would mangle into one BEFORE the editor parses —
on load and when coming back from the source view. Its preview is a sandboxed
iframe (no scripts) with the storefront's stylesheet. Verbatim in the editor,
CLEANED on the storefront (`safe_html()`, see access.md): its `class`, `style`,
tables and frames from allowed sites stay, while a `<style>` element, scripts,
forms and handlers go — a block's own CSS belongs in the theme's CSS.
Lightbox: PhotoSwipe 5 (MIT), vendored in `modules/torgash.media/assets/vendor/`,
opened via `openLightbox()` from `assets/lightbox.mjs` — reuse it for storefront
galleries rather than adding a second library. `storefront: true` is the shop's
viewer — on a phone back, zoom, "1/5", a heart that PRESSES the page's own
`[data-wishlist-toggle]`, a thumbnail strip; from 1024px the product's NAME top
left (`title`), only a cross top right, rounded-square arrows at the sides and the strip
over a dimmed, blurred page (the blur sits on `.pswp` itself — it animates its
own opacity, so it is the backdrop root and a blur inside it sees nothing); its icons and words come from
`<template data-gallery-icon="…" data-label="…">` on the page, so the JS holds
no SVG and no language — the zoom button too: PhotoSwipe's own button (it
knows when a photo can be zoomed) wearing the page's Lucide `zoom-in` through
`zoomSVG`, its upright bar given PhotoSwipe's `pswp__zoom-icn-bar-v`, so
PhotoSwipe's CSS turns "+" into "−" when zoomed in. Its colours are the
shop's: the backdrop and the strip are the bar colour (`--color-neutral`)
mixed darker (`SHADE`), the plates a step lighter — a slate of its own looked
foreign on a graphite or warm theme. A pressed heart is filled through
`style.fill`: `svg.lucide { fill: none }` beats a `fill` attribute. The
product page's gallery is `torgash.catalog::partials.product-gallery` (a
scroll-snap strip).

**Where a product has no photo is ONE component, `<x-no-image>`** — the
theme's `no_image` setting (an `image`-type theme field: uploaded through
`admin.themes.image`, stored as a path `ThemeSettings` itself wrote, read
with `theme_image()`), else a quiet frame in the page's colours. Such a field
(the logos too) takes an SVG only as `SafeSvg::clean()` rebuilds it — the
shop's origin would run a script for whoever opened the file's address — and
refuses one with nothing drawable left; colours an editor wrote as CSS
(Illustrator's `<style>.cls-1{…}`, Inkscape's `style=`) are moved into
attributes first, or a logo came out black.
`primaryImageUrl()` stays null for such a product: a feed must never be
handed a placeholder. A category's icon may be its own picture
(`icon_image`, `Category::iconImageUrl()`), which wins over the Lucide name.

**A gallery can be ONE picture** — `MediaOwners::register(..., single: true)`
(`isSingle()`), a blog article's cover. The upload endpoints take the first
file of a batch and remove the others only AFTER the new one is stored, so a
refused file keeps the old cover; the gallery card loses its order, "main"
and "delete all" and says "Replace the picture".

**A gallery INSIDE a text** is plain markup, `<div data-gallery>` of `<img>`
(width/height/alt/`loading=lazy` on each), written by `torgash.editor`'s
`gallery.mjs` — an atom node whose pictures are one attribute, drawn as tiles
with an alt, earlier/later and remove. It has no upload of its own: the image
panel FILLS whatever gallery is selected (a click on a plaque, and every file
uploaded while it is selected), so there is one place that keeps files. It is
registered unconditionally like the image node, and `wrapUnrepresentable()`
leaves `div[data-gallery]` alone. On the storefront `app.css` shows the bare
images as a swiping strip; `torgash.media` prints on every page (`core.footer`)
an inert `<template data-content-gallery-kit>` with the icons and words, plus a
loader that imports `content-gallery.mjs` only where a `[data-gallery]` exists
(and after an HTMX swap brings one). That builds the stage (scroll-snap),
arrows for a pointer, "1/5", thumbnails, and opens `openLightbox` with
`storefront: true` and the page's h1 as the title. Thumbnails are the same
files as the slides — a content image has no conversions, and each file is
downloaded once for both. Plain images to every other reader (.md, search,
feeds).

## Video (`torgash.video`, PLAN §50)

**A video is `source` + `ref`, never markup.** `m_video_videos` stores the
platform's key (`youtube`, `vimeo`, `vk`, `bilibili`, `embed`) and what that
platform needs (`{"id": …}`); the player's address is built at render time by
the source (`Sources\VideoSource::playerUrl()`), so a new embed domain or
player is one change for every video. Platforms are a REGISTRY
(`Sources\Sources`, hook `video.sources`), asked in order; `Embed` is always
last, so a known platform is never stored as somebody's iframe.

**Pasted code is parsed, not kept.** `Sources::address()` takes the `src` of
the first `<iframe>` and throws the rest away; a host the engine does not
know by name must be https and listed in `allowed_hosts` (subdomains
included), and never the shop's own host — the generic player is drawn by
the engine in a `sandbox` with scripts and same-origin, which is no sandbox
for a page of that origin. Known platforms play unsandboxed (they break in
one).

**Nothing is asked of the platform before play.** The poster is downloaded
once when the video is added (`Videos::fetchPoster()` →
`ImageProcessor::addFile()` — not `addFromUrl()`, which is a PERSON pasting an
address and obeys `allow_remote`) and kept as the video's own `poster`
media collection with the gallery's thumbnails. The player is printed inert
in a `<template data-video-player>` and put on the page by the press of play;
moving the strip on takes it away again. The platform's silence never blocks
a video: VK and Bilibili often refuse servers, and the admin uploads a poster.

**Owners are a registry like `MediaOwners`** (`VideoOwners`, hook
`video.owners`): the key (`product`) is what the admin URL and the ability
hang on. Titles and order ride with the owner's form (`videos[id][…]`,
applied on `catalog.product.saved` — only the owner's own ids); add, poster
and delete are JSON endpoints, since the list sits INSIDE the owner's form.
A product in the bin keeps its videos; `forceDeleted` takes them along.

**The catalogue never names a video.** The product page's strip is
`catalog.product.gallery` (an item with `video => true`, poster as
`thumb`/`stage`, its own `player`); the lightbox is handed the photos alone
and its index is translated both ways. The card mark is `card_meta`, primed
for the page by `catalog.products.listed` (`VideoMarks`, PerRequest).
schema.org: `subjectOf` VideoObject via `catalog.product.structured_data`,
only for a video with a poster (Google will not show one without).

**A file of the shop's own is one more source, `file`** (step 2). It is never
recognised from an address (`parse()` is null); it comes in only through
`Torgash\Video\Uploads`: start → raw chunks (`X-Upload-Offset`, each below
`post_max_size`, ≤ 8 MB) → finish, appended to a scratch part on THIS server
(`storage/app/video-uploads`, an append is not something S3 does), so php.ini
is not the ceiling — `file_max_mb` is. A chunk is taken only at the offset
the part has reached (a retry is answered with the size, never appended
twice); the token belongs to one user and one record. The finished file is
judged by its own bytes (`ftyp` / EBML), not its name, and goes to the
`public` disk through `FileStorage::putBack()` at `videos/<owner>/<id>/` —
the row's deletion takes that folder. `ref` = {disk, path, mime, size}. The
storefront plays it with the browser's own `<video>` (player.blade.php asks
`instanceof File`), served as a plain file: Range requests make seeking
work on a folder and a bucket alike. schema.org gets `contentUrl` instead of
`embedUrl`. Unfinished parts are swept daily (`video:sweep-uploads`).

**ffmpeg is optional and works AFTER the upload** (step 4). `Ffmpeg` runs
the programs named by `ffmpeg_path` (empty = PATH; the web server's and the
worker's PATH may differ, hence the setting) — always an argument list,
never a shell line. When it answers, `uploadFinish` hands the video to
`Jobs\PrepareVideoFile` (a `TrackedJob`; "Prepare for the web" does the same
for an older file): the length from ffprobe, a frame at a tenth (≤ 10 s) as
the poster unless one exists BY THEN, and the file into what every browser
plays — not H.264+AAC/MP3 in mp4, or the SHORT side above `file_max_side`
(so a phone's tall clip counts like a wide one), is converted (libx264 crf
23, `+faststart`); a playable mp4 with its `moov` after `mdat`
(`Ffmpeg::indexFirst()`, read from the boxes) is only remuxed. The result
takes a NEW name (`<stem>-web.mp4` — a browser may hold Range pieces of the
old address) and the old file goes. The row's `ref.state` (queued / working
/ failed + `error`) is what the editor shows and polls; the uploaded file
plays throughout. ffmpeg's refusal is the FILE's problem: written on the row
and the task's result, never thrown — the task is done, not failed.

**Other modules bring their own records** (step 3): the blog and the pages
answer `video.owners` with `register(key, model, ability, seams)`, where the
seams are THEIR hook names — `form` (a filter their edit form prints with
`['record' => …]`; the video list is drawn inside it), `saved` (an action
fired after their save, titles and order applied) and `context` (the key
their text's placeholder context holds the record under). The provider wires
them on `booted()`; the model's `deleted` takes the videos (a soft delete
does not). So `torgash.video` names neither module, and neither needs it.
`{video:ID}` is a core placeholder (`block`, `raw`, group `page`): it plays
one of the CONTEXT record's own videos — an id of somebody else's resolves
to nothing — as `storefront.inline` (poster, play, the player in a
`<template>`). "Into the text" on the list dispatches the editor's generic
`tiptap:insert` ({text, from}), which puts the line into the last focused
rich editor of the same form. The product exchange has a "Videos" column:
links out; in, the cell is the truth about PLATFORM videos (added, removed,
ordered), the shop's own files are never touched, an unknown link is skipped
and logged.
- **A product's documentation is `torgash.attachments`** (2026-10-09): files
  of the shop's own and links to the maker's, one table keyed by the owner's
  KEY (`product`), like `torgash.video`. A file is kept AS IT ARRIVED (a
  re-encoded datasheet is not the maker's) — so the safety is
  `Torgash\Options\Files`'s: the extension comes from the sniffed BYTES and
  must be on the module's list (a container format — docx is a zip, step is
  text — keeps the name's extension only when the container agrees),
  `Documents::NEVER` refuses programs, pages and SVG whatever the list says,
  the disk is `private` under a random name, and `/attachments/{id}/{name}`
  is the only door (`{name}` is for the reader, never used to find
  anything): a document of a product that is not `reachable()` is nobody's
  (staff excepted), `access = account` sends a guest to sign in. A PDF or a
  picture is `inline`, anything else an attachment. CSP: `sandbox` for
  everything but a PDF — Chrome's viewer is a plugin and draws nothing inside
  a sandbox or under `object-src 'none'`. Tests must upload over REAL bytes
  (`new UploadedFile($tmp, $name, test: true)`): `UploadedFile::fake()`
  answers its type from the NAME and would pass a script called spec.pdf.
  The page section is `documents` (sort 25) through `catalog.product.tabs`;
  titles/access/order ride the product's Save as `documents[id][…]`. Not yet
  in exim.
- **A product's 3D model is `torgash.model3d`** (2026-10-09): binary glTF
  only (.glb — one file with its textures; a .gltf names files beside it),
  taken by its HEADER (`glTF`, version 2, declared length = file length),
  stored on the PUBLIC disk under a name of ours (the viewer fetches it by
  address; nothing in a .glb runs). It rides the gallery's played-item
  contract (`video => true` + an inert `player`), which grew three optional
  keys any module may use: `fill` (the whole slide on the page's background,
  not a dark 16:9 frame), `play_icon`, `play_label`; and the gallery now
  fires `torgash:gallery-play` on the stage after inserting a player. The
  viewer is Google's model-viewer (Apache-2.0, bundles three.js), vendored in
  the module's assets and IMPORTED on that event — never a script tag on the
  page. A ✕ in the player calls the gallery's `stop()`: inside the viewer a
  sideways swipe turns the model, so on a phone it is the way out. The poster
  is a media-library picture of the row, taken from the admin's preview with
  `toBlob()` (a new model does it by itself once drawn).

**A picture inside a printed text gets its width and height** (core's
`core.content.cleaned`, fired by `SafeHtml::clean()` after cleaning, only for
a text with `<img`; answered by `Torgash\Media\ContentImageSizes`). The editor
writes the numbers on what it inserts, but an import, the API, 1C or an older
text carries a bare `<img src>` and the words under it jumped when it loaded.
A picture is the library's when its address ends in `/<media id>/<that
media's file name>` (the default path layout on either disk); one query per
text, a tag that already has a width is left alone.

## A photo from a phone is made smaller before it is sent (PLAN §62, 2026-10-29)

A camera's photo is 3–8 MB, and over a mobile connection sending it is the
slow part of adding it. The gallery editor (`torgash.media::admin.gallery`,
`shrink()`) draws a JPEG or PNG of a megabyte and more down to the Media
setting "Max dimension" in the browser — `createImageBitmap` with the
camera's orientation applied, a canvas, `toBlob` at 0.9 — and sends that
under the file's own name. Nothing is lost that the server would have kept:
`ImageSettings::maxDimension()` is what the stored original is reduced to
on arrival anyway, and the server then has nothing left to downscale, so
the picture is encoded once, not twice. When the shop stores originals
untouched (`original_untouched`) the browser sends the bytes as they are
(`maxSide` 0); so it does with anything it cannot decode, or that would not
come out smaller (a PNG sometimes), or a type it does not re-encode
(GIF, SVG, HEIC — Safari turns an iPhone's HEIC into JPEG before the page
sees it). The plain file field a NEW product shows before its first save
sends the original: the server's downscale covers it. The order of photos
is changed with arrows under a finger (`move()`), beside dragging.
