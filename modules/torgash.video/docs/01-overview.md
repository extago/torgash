# Video

A product can show videos beside its photos. Open the product, go to the
**Video** tab and paste one of:

- a link to a video on **YouTube**, **Vimeo**, **VK Video** or **Bilibili** —
  the page's address, a short link or the "embed" code, any of them;
- the **embed code** (`<iframe …>`) of another site — only if that site is
  listed in the module's settings under "Sites whose embed code is accepted".

The video takes its title and poster from the platform when the platform
gives them. YouTube and Vimeo always do; VK and Bilibili often refuse
servers, and then you upload a poster yourself — click the empty frame.
The title is optional: an empty one means the product's name.

## On the storefront

- The videos stand after the photos in the gallery's strip, with a play
  mark on their thumbnails, in the order you dragged them into.
- The visitor sees **your copy of the poster** and a play button. Nothing is
  loaded from YouTube (or anywhere else) until play is pressed — the page is
  as fast as without the video, and the platform learns nothing about a
  visitor who does not watch.
- Pressing play puts the platform's player in place of the poster. Moving on
  to another photo stops it.
- Catalogue cards of a product with a video show a small play mark beside
  the rating (a setting).
- Search engines are told about the video (schema.org `VideoObject`) when it
  has a poster.

## Why embed code from other sites is limited

The engine never stores pasted code. It keeps only the address of the
player and draws the `<iframe>` itself, in a sandbox. The list of allowed
sites exists because the right to edit a product is not the right to put
somebody's script on the storefront — that is what "Code on pages" is for —
and an address can also arrive from an import nobody read line by line.

## Your own file

**Upload a file** beside the link box takes an **mp4** (H.264) or **webm**
file up to the size set in the module's settings (200 MB unless changed).
It is sent in pieces, so a big file only takes longer — the server's own
upload limit does not stop it; the bar shows how far it got, and "Cancel"
stops it. The file is checked by its contents, not its name.

If the server has **ffmpeg** (where it is — the module's settings), the
file is prepared in the background once it has arrived: a frame becomes its
poster, its length is read, and a file that not every browser plays (not
H.264, or webm), or one bigger than "Shrink uploaded videos to" (1080p by
default, measured by the shorter side), is converted. While that runs the
list says so and the uploaded file already plays. A file uploaded before
ffmpeg was found has **Prepare for the web**. The background tasks need a
running queue worker — see System → Background tasks.

Without ffmpeg a file brings no poster with it: upload one by clicking the
frame, or the visitor sees a plain dark frame with a play button. A poster of
your own is never replaced by a frame. The video plays in the browser's own
player, from the shop's own storage — nothing is asked of anybody else.
Removing the video removes the file.

## In articles and pages

A blog article and a page have a **Video** section on their form, the same
list as a product's. **Into the text** puts `{video:12}` on its own line
where the cursor is in the text; on the site it becomes the poster and a
play button, and the player loads only when pressed. A text can only play
its own record's videos.

## Import and export

Products have a **Videos** column: links separated by commas. On import the
cell is the full list of platform videos — new ones are added, ones missing
from the cell are removed, the order follows the cell. Your own uploaded
files are not touched by an import.

## Long videos

A webinar or anything long is better on YouTube, Vimeo or Rutube: they pick
the quality for the visitor's connection and carry the traffic. A file of
your own suits what a shop usually shows — a review, an unboxing, an
assembly guide of a few minutes.
