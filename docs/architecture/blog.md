# Blog (torgash.blog)

PLAN §47. Rubrics are coloured PLATES (a flat list with a Lucide icon and a
#hex), an article can be in several; there are NO tags and NO monthly archive —
the owner's decision: nobody looks for an article that way any more.

On the feed the rubrics are a COLUMN on the left (like the catalogue's
categories), with the count of listed articles each — a row of plates across
the top stopped working at twenty. On a phone the same list is a sideways
strip above the articles; a CSS grid puts the shop's `blog.sidebar` blocks
under the rubrics on a computer and after the articles on a phone — one
markup, no second copy.

**An article's address never depends on its rubric** (`/blog/{slug}`; a rubric
is `/blog/rubric/{slug}`), so moving it between rubrics breaks no link;
renaming the slug records a 301 (core's redirects). `status` is draft /
published / hidden (published at its address, in no feed); a published article
whose `published_at` is still ahead is SCHEDULED — `Post::listed()` /
`reachable()` compare with now, so nothing has to run for it to appear.
`reading_minutes` is worked out on save (≈180 words a minute, the longest
language). Views are counted once per session and never for a crawler, with a
plain query so `updated_at` does not move.

**The text is prepared once per request** (`Article::prepare()`): every h2/h3
gets an `id` (kept if given) and `data-blog-heading`, and the list of them is
the CONTENTS beside the article, lit as the reader scrolls (an
IntersectionObserver in Alpine; plain anchors without it). `{product:SKU}` on
its own line becomes the product's card (`partials/inline-product`, marked
`torgash-rich-embed`, which app.css exempts from the text's link style); an
unknown SKU or a product the visitor may not see (closed assortment) leaves
nothing. "Products in this article" is a list of SKUs on the form, drawn with
the catalogue's own carousel.

**A like per browser** (`Likes`): a long-lived random cookie, only its HMAC
stored (`m_blog_likes`, unique per article), a second press takes it back, a
crawler counts nothing; `likes_count` is recounted from the rows on every
toggle. The heart is a form that works without a script. "Share" is plain
share links (Telegram, Viber, WhatsApp, Facebook, X) plus copy-the-link; on a
touch screen the system's own share sheet first — nothing is loaded from any
network until somebody presses.

**The feed** is ordinary pages; "Show more" asks the next page over HTMX
(`?more=1`) and gets only its cards plus the next button. The newest article of
the first page is drawn wide. An article with no cover gets its first rubric's
colour and icon instead of an empty grey box.

Seams used (the module names nobody's classes except where it requires them):
`media.owners` (`blog_post`: the cover is a SINGLE-picture gallery — a new
upload replaces it, see media.md —, pictures and galleries in the text are
the `content` collection), zones `blog.sidebar` (a column only
when filled) and `blog.post.bottom` (comments go there — `comments.targets`
registers `blog_post`), `menus.pages`, `catalog.sitemap.urls`, `seo.subjects`
(`blog_post`, `blog_rubric`), `core.llms.sections` + `/blog/{slug}.md`
(`PostText`), `search.suggest.sections`, JSON-LD `BlogPosting` through
`structured_data()`.

**The cover is one picture** (the owner's call, 2026-10-01): the loader used to
take twenty and the article showed the first, which let a person believe the
other nineteen went somewhere. Several photos belong IN the text — one by one
with the image button, or as a gallery (torgash.editor's gallery node, see
media.md). `blog.sidebar` stretches the wide card and "Show more" with
`col-span-full`, never a fixed count: the grid has two columns beside a
sidebar and three without, and a span of three in a two-column grid makes an
implicit third column that squeezes every card after it.
