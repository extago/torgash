# Pictures

A picture uploaded to a product, a category, a brand or an article is kept as
the original plus thumbnails made from it. Every place on a page shows the
thumbnail that suits it; the original is what opens when a picture is zoomed.

## Thumbnail sizes

Settings → Media → Thumbnails, "Thumbnail sizes": `120x120, 400x400,
800x800` by default — width × height, separated by commas. A place on a page
asks for a width and gets the smallest thumbnail at least that wide: 120 in
the cart and the image strip, 400 on catalogue cards, 800 on the product
page. So `400x400` → `500x500` gives the cards sharper (and heavier)
pictures, and without `400x400` they take the 800 one.

## How a picture fits its box

- **Contain** — the whole picture inside the box, at its own shape: nothing
  is cut, the thumbnails differ in shape.
- **Cover** — fills the box exactly; the edges of a tall or wide photo are
  cut off.
- **Pad** — the whole picture, padded with the padding colour to the exact
  box: identical cards and nothing cut, the safe choice for a catalogue grid.

The fit is a choice about the catalogue: an article's cover keeps its own
shape whatever it says, and its card cuts it to the frame.

"Trim uniform borders on upload" cuts away the empty margins studio photos
come with — from the stored original, so rebuilding thumbnails does not bring
them back; only uploading the picture again does.

## New settings and the pictures already there

Sizes, fit, padding and format apply to pictures uploaded from then on. The
ones already in the shop keep their thumbnails — pages go on showing them,
and a size a picture does not have yet is simply skipped — until **Rebuild
all thumbnails**, the button under these settings. It runs in the background
(Background tasks shows how far it got): a few thousand pictures take about
half an hour, and the shop works as usual meanwhile.

## Format and quality

Thumbnails are WebP by default ("Generate thumbnails as WebP", quality 82).
Pictures inside a text — a description, a page — are kept as uploaded unless
"Convert images inside text to WebP" is on: a screenshot or a PNG with
transparency can come out worse, and an animated GIF is never converted.

## What is accepted on upload

"Max file size, MB" refuses bigger files. "Max dimension, px" scales a bigger
original down when it is stored; "Store the original untouched" keeps the
file byte for byte instead, whatever its dimensions.
