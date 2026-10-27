# Pictures in a text

A description is not only words. A size chart, a diagram, a screenshot of the
label on the box — these belong *inside* the text, at the point where they are
being talked about, and not in the product's photo gallery where the slider
would offer them as pictures of the goods.

So the editor has an image button, and it opens a small panel above the text.

## What belongs where

|                        | Photos (the gallery)              | Pictures in a text                |
|------------------------|-----------------------------------|-----------------------------------|
| What it is             | photographs **of** the thing      | illustrations **in** its text     |
| Where it shows         | the slider, the card, search      | wherever you put it in the text   |
| Order and captions     | dragged, captioned                | wherever it is; alt on the tag    |
| Thumbnails             | several sizes, generated          | none — it is not a preview        |
| Belongs to             | the record                        | the record                        |

That last row is the important one, and it is the same in both columns: an image
you upload here belongs to the product (or the page, or whatever record you are
editing). Delete the record for good and its pictures go with it. There is no
shop-wide file library, deliberately — a shared library is a place where nobody
ever knows which file is safe to delete.

## Adding one

The button appears **after the record has been saved once**. Before that there
is no record for a file to belong to, which is the same reason the Photos card
says captions and previews become available after the first save.

Two ways in, and they are the same two the gallery offers:

- **Upload a picture** — one or several at a time. Every one goes through the
  shop's media settings: the maximum file size, the maximum dimension it is
  downscaled to, and the check that a file claiming to be an image really is
  one.
- **Add by link** — pastes a picture from another address. Switched off unless
  the Media module's "allow remote" setting is on.

Whatever is already attached to this record is listed underneath, with its file
name, weight and pixel size. Clicking one puts it in the text at the cursor.
That list is the answer to "I have already uploaded this, where is it" — you
never need to upload the same diagram twice.

## Changing one

Click a picture in the text and the panel shows its properties:

- **Description (alt)** — what a reader with images switched off, a screen
  reader, or a search engine sees instead of the picture. Worth typing.
- **Picture size** — three answers rather than a slider:
  - *As uploaded* — its own size. On a narrow screen it still shrinks to fit;
    it never overflows.
  - *Set by hand* — type a width and the height follows the real proportions, so
    nothing comes out squashed.
  - *Full column width* — as wide as the text is.
- **Remove from the text** — takes the picture out of the description. The file
  stays.

## What is not used any more

When a picture is not mentioned in the text, its plaque is marked **"not in the
text"** — and nothing else happens to it. Files are not swept up on save on
purpose: an illustration taken out of a paragraph is very often going back in,
and a shop that quietly deleted it would be deleting the one copy.

Delete it yourself when you are sure — the button asks twice, and says so
plainly when the picture is still in the text.

## WebP

There is a Media setting for it, off by default. Thumbnails are worth converting
(they are generated in several sizes and served constantly), a picture in a text
is not obviously so: it is stored once and served as it is, and for a screenshot
full of small text or a PNG with transparency the WebP re-encode is sometimes
visibly worse than the original. Animated GIFs are never converted — the
animation would be lost.

## For the shop's developer

Any model can have this. Three things and nothing else:

1. the model is `HasMedia` (spatie/laravel-medialibrary, as a product is);
2. its module registers it — `MediaOwners::register('page', Page::class,
   'pages.manage', content: true)`;
3. the form passes the record to the field —
   `<x-admin.translatable-field ... rich :record="$page" />`.

The rest follows: the endpoints, the panel, the size modes, the ownership. The
editor asks `editor.images` for somewhere to keep the files and the media module
answers; neither names a class of the other's, so an editor with the media
module uninstalled simply has no image button.

## A gallery

For an article like "unboxing in 20 photos" putting every picture into a
paragraph is a chore, and reading them one under another is a long scroll. The
**Gallery** button puts a gallery where the cursor is: on the site it is one
picture at a time that the reader swipes or leafs with arrows, a "1/20", a strip
of thumbnails under it, and a click opens it full screen.

1. Press **Gallery**. An empty gallery appears in the text, selected, and the
   picture panel opens above.
2. While the gallery is selected, the panel fills IT: upload several files at
   once and they all go in, or click pictures already uploaded for this record.
3. Under each picture in the gallery: a description (alt), arrows to move it
   earlier or later, and a cross to take it out of the gallery (the file stays).
   **Add pictures** on the gallery's bar selects it again and opens the panel.

A gallery is ordinary pictures in the text's HTML, so a search engine, the .md
version of the page and anything else reading it simply see images.
