# Third-party software and data

Torgash's own code is under the MIT licence ([LICENSE](LICENSE)). It also
carries other people's work, unmodified unless said so, each under its own
licence. This file lists them with their notices, which is what the MIT, BSD
and ISC licences ask of anyone passing a copy on.

## In this repository and in every release

### Scripts

| What | Version | Where | Licence |
| --- | --- | --- | --- |
| [Alpine.js](https://alpinejs.dev), © Caleb Porzio and contributors | 3.16.2 | `public/assets/js/vendor/alpine.min.js` | MIT |
| [htmx](https://htmx.org), © Big Sky Software and contributors | 2.0.10 | `public/assets/js/vendor/htmx.min.js` | 0BSD |
| [PhotoSwipe](https://photoswipe.com), © Dmytro Semenov | 5.4.4 | `modules/torgash.media/assets/vendor/` | MIT |
| [Embla Carousel](https://www.embla-carousel.com) with autoplay and fade, © David Jerleke | 8.6.0 | `modules/torgash.banners/assets/embla/` | MIT |
| [Tiptap](https://tiptap.dev), © Tiptap GmbH | 3.30.3 | `modules/torgash.editor/assets/vendor/tiptap-*` | MIT |
| [ProseMirror](https://prosemirror.net), orderedmap, rope-sequence, w3c-keyname, © Marijn Haverbeke and others | as bundled by esm.sh | `modules/torgash.editor/assets/vendor/` | MIT |
| [unenv](https://github.com/unjs/unenv), Node.js shims, © Pooya Parsa | as bundled by esm.sh | `modules/torgash.editor/assets/vendor/node-*.mjs` | MIT |
| [model-viewer](https://modelviewer.dev), © Google LLC; bundles three.js (MIT, © three.js authors), Lit (BSD-3-Clause, © Google LLC) and gainmap-js (MIT, © MONOGRID) | 4.3.1 | `modules/torgash.model3d/assets/vendor/model-viewer/`, licence beside it | Apache-2.0 |

### Stylesheet

`public/assets/css/app.css` is built from [Tailwind CSS](https://tailwindcss.com)
4 (MIT, © Tailwind Labs, Inc.) and [daisyUI](https://daisyui.com) 5 (MIT,
© Pouya Saadeghi).

### Fonts

All under the [SIL Open Font License 1.1](https://openfontlicense.org). Each
file carries its copyright notice and the licence's address in its own
metadata.

| Font | Where | © |
| --- | --- | --- |
| Finlandica Text | `public/assets/fonts/` | The Finlandica Project Authors |
| Golos Text | `themes/default/assets/fonts/` | The Golos Text Project Authors |
| Manrope | `themes/default/assets/fonts/` | The Manrope Project Authors |
| Rubik | `themes/default/assets/fonts/` | The Rubik Project Authors |

### Icons

- [Lucide](https://lucide.dev), `resources/icons/lucide/`: ISC, licence beside
  them.
- Brand marks from [Simple Icons](https://simpleicons.org) 13.21.0,
  `modules/torgash.blocks/icons/brands/`:
  [CC0 1.0](https://creativecommons.org/publicdomain/zero/1.0/). The marks
  themselves stay their owners' trademarks.

### Data

- Regions and towns, `data/geo/UA/` and `data/geo/RU/`: derived from
  [OpenStreetMap](https://www.openstreetmap.org/copyright) (Geofabrik extracts
  of 2026-08-27), © OpenStreetMap contributors, under the
  [Open Database License 1.0](https://opendatacommons.org/licenses/odbl/1-0/).
  These files are under that licence, not under Torgash's MIT one.
- The country list, `data/geo/countries.csv`: built from
  [GeoNames](https://www.geonames.org), under
  [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/).

## In a release archive only

`vendor/`: the PHP packages Composer installs (Laravel and 96 others). Each
keeps its licence in its own folder; `composer licenses --no-dev` lists them.
All of them are MIT, BSD-2-Clause, BSD-3-Clause or Apache-2.0. Two of them,
`nette/schema` and `nette/utils`, offer BSD-3-Clause or GPL, and Torgash takes
them under BSD-3-Clause.

## Licence texts

### MIT

Applies to each work marked MIT above, with that work's own copyright line.

> Permission is hereby granted, free of charge, to any person obtaining a copy
> of this software and associated documentation files (the "Software"), to
> deal in the Software without restriction, including without limitation the
> rights to use, copy, modify, merge, publish, distribute, sublicense, and/or
> sell copies of the Software, and to permit persons to whom the Software is
> furnished to do so, subject to the following conditions:
>
> The above copyright notice and this permission notice shall be included in
> all copies or substantial portions of the Software.
>
> THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
> IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
> FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
> AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
> LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
> FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
> IN THE SOFTWARE.

### BSD-3-Clause

Applies to Lit inside model-viewer, © Google LLC.

> Redistribution and use in source and binary forms, with or without
> modification, are permitted provided that the following conditions are met:
>
> 1. Redistributions of source code must retain the above copyright notice,
>    this list of conditions and the following disclaimer.
> 2. Redistributions in binary form must reproduce the above copyright notice,
>    this list of conditions and the following disclaimer in the documentation
>    and/or other materials provided with the distribution.
> 3. Neither the name of the copyright holder nor the names of its
>    contributors may be used to endorse or promote products derived from
>    this software without specific prior written permission.
>
> THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
> AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
> IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
> ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE
> LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
> CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
> SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
> INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
> CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
> ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
> POSSIBILITY OF SUCH DAMAGE.

### The others

Apache-2.0 (model-viewer) and ISC (Lucide) sit beside the files they cover.
0BSD (htmx), the SIL Open Font License, the Open Database License, CC BY 4.0
and CC0 are at the addresses above.
