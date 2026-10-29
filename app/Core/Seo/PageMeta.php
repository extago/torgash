<?php

namespace App\Core\Seo;

use App\Core\Content\Placeholders;
use App\Core\Storefronts\LocaleResolver;
use Illuminate\Support\Facades\Route;

/**
 * Everything the head of a page says about itself, in one object.
 *
 * In core and as ONE object because a page has one head: two sides printing
 * tags independently give two `<title>`s and — the expensive one — two
 * canonicals, which is a page telling a search engine two different things
 * about which address it lives at. So nothing prints a meta tag of its own any
 * more; everything fills this in, and the theme prints it once through
 * `<x-seo.head>`.
 *
 * The filling order IS the answer to "who overrides whom":
 *
 *     the controller puts a default in (the product's name)
 *       → the entity's own columns, when they are filled
 *         → a torgash.seo template, by its own mode
 *           → the `core.page.meta` filter — the last word (geo pages, filter
 *             pages), applied at render time, not at set time
 *
 * which works because {@see set()} IGNORES an empty value: a blank
 * meta_description column is not an answer and must not erase the sentence the
 * controller already had. Later wins, empty never does.
 *
 * H1 lives here too although it is printed in the body. A shop that wants
 * "H1 ≠ title" otherwise has to grow the same three lines of fallback in every
 * template; here the view asks `page_meta()->h1()` and gets the title when
 * nobody said otherwise.
 *
 * Per REQUEST (a scoped binding, not a static): under a persistent worker a
 * static would hand the next visitor the last one's title.
 */
class PageMeta
{
    /** @var array<string, string> */
    protected array $values = [];

    /** @var array<string, string>|null locale => URL, null = work it out */
    protected ?array $alternates = null;

    /**
     * Query parameters that are part of the page's identity.
     *
     * `page` by default and deliberately: the canonical of `?page=2` points at
     * ITSELF. Pointing it at page one is the common recipe and it hides every
     * product that is not on the first page — and `rel=next/prev` is not
     * printed at all, because no search engine has used it since 2019.
     *
     * Everything else is dropped, which is what keeps `?utm_source=…` and a
     * filter combination from each becoming a page of their own. A controller
     * that owns a parameter naming real content says so with
     * {@see canonicalParams()}.
     *
     * @var list<string>
     */
    protected array $canonicalParams = ['page'];

    /** @var list<array{name: string, content: string, property: bool}> */
    protected array $metaTags = [];

    /** @var list<array<string, string>> */
    protected array $links = [];

    protected bool $suffix = true;

    protected bool $resolved = false;

    protected ?PageSubject $subject = null;

    /**
     * The record this page is about, and its own answers applied on top of
     * whatever the controller worked out.
     *
     * Two things at once on purpose. The columns are read here so that every
     * page gets them by writing one line rather than five; and the subject is
     * REMEMBERED, because the next link in the chain — an SEO template — has to
     * be able to ask the record itself whether its own field was empty. Asking
     * the merged values instead would never fire: the controller has already
     * put the product's name in the title.
     */
    public function describe(PageSubject $subject): static
    {
        $this->subject = $subject;
        $meta = $subject->pageMeta();

        // The record's own words take the placeholders an SEO template takes
        // ({min_price} in a filter page's own title), with the same context —
        // unescaped, as there: the head and the h1 escape what they print.
        $context = static::contextOf($subject);
        $expand = fn (?string $text) => $text === null ? null
            : trim(app(Placeholders::class)->expand($text, $context, false));

        $this->set([
            'title' => $expand($meta['title'] ?? null),
            'description' => $expand($meta['description'] ?? null),
            'h1' => $expand($meta['h1'] ?? null),
            'canonical' => $meta['canonical'] ?? null,
            // The record's own picture for a shared link, over the one the
            // controller found (a product's photo, a category's tile).
            'image' => $meta['image'] ?? null,
        ]);

        if ($meta['noindex'] ?? false) {
            $this->noindex();
        }

        return $this;
    }

    public function subject(): ?PageSubject
    {
        return $this->subject;
    }

    /**
     * What a placeholder in a record's words may ask about: the record under
     * its own kind, which is exactly what the catalogue's tokens look for, and
     * as `subject`, plus whatever the record adds itself — asked of the record
     * rather than typed here, so a city page hands over the town it is about
     * (`place_id`, read by a token `torgash.locations` registers) and core
     * names neither module's classes. One context for the record's own fields,
     * its texts on the page (`safe_html($text, PageMeta::contextOf($page))`)
     * and an SEO template, so a token means the same thing in all three.
     *
     * @return array<string, mixed>
     */
    public static function contextOf(PageSubject $subject): array
    {
        $context = [$subject->pageMetaKind() => $subject, 'subject' => $subject];

        if (method_exists($subject, 'pageMetaContext')) {
            $context += $subject->pageMetaContext();
        }

        return $context;
    }

    /** product | category | brand | page — or '' on a page that is about nothing in particular. */
    public function subjectKind(): string
    {
        return $this->subject?->pageMetaKind() ?? '';
    }

    /**
     * Fill in one value, or several at once. An empty string or null is
     * ignored — see the class docblock.
     *
     * @param  string|array<string, mixed>  $key  title|description|h1|robots|canonical|image|type|site_name
     */
    public function set(string|array $key, mixed $value = null): static
    {
        foreach (is_array($key) ? $key : [$key => $value] as $name => $item) {
            $item = is_string($item) ? trim($item) : $item;

            if ($item === null || $item === '') {
                continue;
            }

            $this->values[$name] = (string) $item;
        }

        return $this;
    }

    /** The page's own title, without the shop's name — what a template or an SEO rule set. */
    public function rawTitle(): string
    {
        return $this->resolve()->values['title'] ?? '';
    }

    /**
     * What goes in `<title>`: the page's own title and the shop's name after
     * it, unless the title already carries it (an SEO template writes the whole
     * line) or the page asked for it to be left off.
     */
    public function title(): string
    {
        $title = $this->rawTitle();
        $site = $this->siteName();

        if ($title === '') {
            return $site;
        }

        if (! $this->suffix || $site === '' || str_contains($title, $site)) {
            return $title;
        }

        return $title.' · '.$site;
    }

    /** Print the title on its own — for a home page, or a template that wrote the whole line. */
    public function withoutSuffix(bool $without = true): static
    {
        $this->suffix = ! $without;

        return $this;
    }

    public function description(): string
    {
        return $this->resolve()->values['description'] ?? '';
    }

    /** The heading in the body, falling back to the title — see the class docblock. */
    public function h1(): string
    {
        return $this->resolve()->values['h1'] ?? $this->rawTitle();
    }

    public function siteName(): string
    {
        return $this->resolve()->values['site_name'] ?? (string) (current_storefront()?->name ?? '');
    }

    /** `website`, `product`, `article` — OpenGraph's word for what this page is. */
    public function type(): string
    {
        return $this->resolve()->values['type'] ?? 'website';
    }

    /**
     * The preview picture: this page's own, else its storefront's (Admin →
     * Storefronts — the home page's and every other page's that has none),
     * else the address in the core setting, the way it was done before.
     */
    public function image(): string
    {
        return $this->resolve()->values['image']
            ?? SharePicture::url(current_storefront()?->share_image)
            ?? (string) setting('core', 'og_image', '');
    }

    /**
     * The `robots` line, or '' when there is nothing to say.
     *
     * Only ever printed when it is restrictive: a page that says `index,follow`
     * says exactly what a crawler already assumed. A page whose route is in the
     * "not for the index" registry gets its answer from there without anybody
     * writing it down twice — the same registry the `Disallow:` lines in
     * robots.txt come from.
     */
    public function robots(): string
    {
        if ($explicit = $this->resolve()->values['robots'] ?? null) {
            return $explicit;
        }

        return app(PrivateRoutes::class)->matches(Route::current()) ? 'noindex, nofollow' : '';
    }

    public function noindex(bool $follow = false): static
    {
        return $this->set('robots', $follow ? 'noindex, follow' : 'noindex, nofollow');
    }

    public function isIndexable(): bool
    {
        return ! str_contains($this->robots(), 'noindex');
    }

    /**
     * The one address this page lives at.
     *
     * Worked out rather than declared, because the thing that knows it is the
     * request: the current URL, minus every query parameter that is not part of
     * what the page IS ({@see $canonicalParams}). A controller may override it
     * outright — a filter page pointing at its category, a printed variant
     * pointing at the product.
     */
    public function canonical(): string
    {
        if ($explicit = $this->resolve()->values['canonical'] ?? null) {
            return $explicit;
        }

        // An address in a language the record has no words in shows another
        // language's text: it is a copy of that page, and says so.
        if (($elsewhere = $this->translationElsewhere()) !== null) {
            return $elsewhere;
        }

        $request = request();
        $params = [];

        foreach ($this->canonicalParams as $name) {
            if ($request->filled($name)) {
                $params[$name] = $request->input($name);
            }
        }

        ksort($params);

        // Request::url() drops the query and the trailing slash; the root would
        // otherwise come out as a bare host, which is not an address.
        $url = rtrim($request->url(), '/');
        $url = $request->path() === '/' ? $url.'/' : $url;

        return $params === [] ? $url : $url.'?'.http_build_query($params);
    }

    /** @param  list<string>|string  $names */
    public function canonicalParams(array|string $names): static
    {
        $this->canonicalParams = array_values(array_unique([...$this->canonicalParams, ...(array) $names]));

        return $this;
    }

    /**
     * `hreflang` — the same page in the shop's other languages.
     *
     * Computed by CORE and by nothing else, because only core knows the shape
     * of a route per locale: StorefrontRoutes registers `/product/x` and
     * `/ru/product/x` as two named routes, and a module has no business
     * learning that. A locale is offered only when the route table really has
     * that shape of the current route — otherwise a page that exists in one
     * language would advertise translations that 404.
     *
     * Built by rewriting the canonical's path, not by calling route() again:
     * the path is right for any URL the visitor can be on, parameters and all,
     * and it keeps the canonical and its alternates saying the same thing.
     *
     * @return array<string, string> locale => URL, empty for a single-language shop
     */
    public function alternates(): array
    {
        if ($this->alternates !== null) {
            return $this->alternates;
        }

        $storefront = current_storefront();
        $locales = $storefront?->locales ?? [];

        if (count($locales) < 2 || ! ($name = Route::currentRouteName())) {
            return $this->alternates = [];
        }

        $prefix = LocaleResolver::prefixOf(Route::current());
        $base = $prefix ? substr($name, strlen($prefix) + 1) : $name;
        $default = $storefront?->default_locale;

        // Only the languages this page really has: a route registered in one
        // shape and not another would otherwise be advertised as a translation
        // that 404s.
        $eligible = array_values(array_filter(
            $locales,
            fn (string $locale) => Route::has($locale === $default ? $base : $locale.'.'.$base),
        ));

        // ...and only the languages the RECORD has words in: an address that
        // would show another language's text is not a translation.
        if (($has = $this->subjectLanguages()) !== null) {
            $eligible = array_values(array_intersect($eligible, $has));
        }

        // The URLs themselves come from the one place that knows the shape of a
        // localised address — the same one the sitemap uses, so the page and
        // the sitemap cannot advertise different translations.
        $alternates = Alternates::forUrl($this->canonical(), $eligible, $default);

        return $this->alternates = count($alternates) > 1 ? $alternates : [];
    }

    /** The languages the described record has words in, or null when it cannot tell / nothing is described. */
    protected function subjectLanguages(): ?array
    {
        if ($this->subject === null || ! method_exists($this->subject, 'pageLanguages')) {
            return null;
        }

        $languages = $this->subject->pageLanguages();

        return $languages === null || $languages === [] ? null : $languages;
    }

    /**
     * The same page in a language the record HAS, when the current one is
     * not among them — the storefront's default first — or null.
     */
    protected function translationElsewhere(): ?string
    {
        $has = $this->subjectLanguages();
        $storefront = current_storefront();

        if ($has === null || in_array(app()->getLocale(), $has, true) || ! $storefront) {
            return null;
        }

        $default = $storefront->default_locale;
        $target = in_array($default, $has, true) ? $default : (array_values(array_intersect($storefront->locales ?? [], $has))[0] ?? null);
        if ($target === null) {
            return null;
        }

        $request = request();
        $url = rtrim($request->url(), '/');

        return Alternates::forUrl($request->path() === '/' ? $url.'/' : $url, null, $default)[$target] ?? null;
    }

    /** @param  array<string, string>  $alternates */
    public function setAlternates(array $alternates): static
    {
        $this->alternates = $alternates;

        return $this;
    }

    /** The language `x-default` points at: the storefront's own. */
    public function defaultLocale(): ?string
    {
        return current_storefront()?->default_locale;
    }

    /** A meta tag the head has no opinion about — `<meta name="…" content="…">`. */
    public function meta(string $name, ?string $content, bool $property = false): static
    {
        if ($content !== null && trim($content) !== '') {
            $this->metaTags[] = ['name' => $name, 'content' => trim($content), 'property' => $property];
        }

        return $this;
    }

    /** @return list<array{name: string, content: string, property: bool}> */
    public function metaTags(): array
    {
        $this->resolve();

        return $this->metaTags;
    }

    /** A `<link>` in the head — the text rendition, a feed, a preconnect. */
    public function link(string $rel, string $href, array $attributes = []): static
    {
        $this->links[] = ['rel' => $rel, 'href' => $href] + $attributes;

        return $this;
    }

    /** @return list<array<string, string>> */
    public function linkTags(): array
    {
        $this->resolve();

        return $this->links;
    }

    /**
     * The last word, asked once and at PRINT time rather than when a value is
     * set: a listener that wants to override the title has to be able to see
     * the title everything else settled on.
     */
    protected function resolve(): static
    {
        if ($this->resolved) {
            return $this;
        }

        $this->resolved = true;

        $values = hook_filter('core.page.meta', $this->values, ['meta' => $this]);

        if (is_array($values)) {
            $this->values = $values;
        }

        return $this;
    }
}
