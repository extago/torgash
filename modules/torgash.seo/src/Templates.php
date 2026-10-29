<?php

namespace Torgash\Seo;

use App\Core\Content\Placeholders;
use App\Core\Seo\PageMeta;
use App\Core\Seo\PageSubject;
use Illuminate\Support\Collection;
use Torgash\Seo\Models\Template;

/**
 * Which template applies to a page, and what it says once it is filled in.
 *
 * One class, used by both halves of the module: the storefront (which applies
 * the answer to the page being rendered) and the audit screen (which shows what
 * a template WOULD say about a row nobody has written a title for). They have
 * to be the same code, or the screen becomes a promise the pages do not keep.
 *
 * The nearest scope wins. For a product that means its own categories first and
 * then their ancestors; for a category, itself and then its ancestors; for
 * anything else, only the shop-wide row. `skip` is an answer like any other and
 * stops the search — that is what lets one category opt out of a global rule.
 *
 * Per request (a scoped binding): a listing screen asks about fifty rows and
 * they nearly all resolve to the same one or two templates.
 */
class Templates
{
    /** @var array<string, ?Template> */
    protected array $memo = [];

    /** @var Collection<int, Template>|null */
    protected $all = null;

    /**
     * The template that governs this record, or null when none does.
     *
     * A `skip` row is returned rather than skipped over, because the caller has
     * to be able to tell "nothing applies" from "something explicitly says to
     * leave this alone" — the second one must not fall back to the global rule.
     */
    public function for(PageSubject $subject): ?Template
    {
        $key = $subject->pageMetaKind().':'.($subject->getKey() ?? '0');

        return $this->memo[$key] ??= $this->resolve($subject);
    }

    protected function resolve(PageSubject $subject): ?Template
    {
        $candidates = $this->candidates()->where('subject', $subject->pageMetaKind());

        if ($candidates->isEmpty()) {
            return null;
        }

        foreach ($this->scopes($subject) as $scope) {
            if ($found = $candidates->firstWhere('scope', $scope)) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Where to look, nearest first, ending at the shop-wide row.
     *
     * @return list<string>
     */
    protected function scopes(PageSubject $subject): array
    {
        $scopes = [];

        foreach ($this->categoryChain($subject) as $categoryId) {
            $scopes[] = 'category:'.$categoryId;
        }

        $scopes[] = 'global';

        return array_values(array_unique($scopes));
    }

    /**
     * The categories this record hangs under, innermost first.
     *
     * Asked of the record rather than typed here, so a module that invents a
     * kind of page with a category of its own answers by HAVING one — either a
     * `categories` relation or a single `category` — and one that has none at
     * all (a brand, a text page) says nothing and gets the global template.
     *
     * @return list<int>
     */
    protected function categoryChain(PageSubject $subject): array
    {
        $direct = [];

        if ($subject->pageMetaKind() === 'category' && $subject->getKey()) {
            // A category is its own innermost scope: a rule written for
            // "Laptops" governs the Laptops page as well as everything under it.
            $direct[] = $subject;
        } elseif (method_exists($subject, 'categories')) {
            $direct = $subject->categories->all();
        } elseif (method_exists($subject, 'category')) {
            // A record that hangs under exactly one category — a filter page
            // does. Stated as "has a category", not as a list of classes: this
            // module names no other module's model, and the next kind of page
            // that has one is governed by the right rule without touching this.
            $direct = array_filter([$subject->category]);
        }

        $chain = array_map(fn ($category) => (int) $category->getKey(), $direct);

        // ...and then upwards, all the direct ones before any ancestor, so that
        // a rule on the category a product is actually in beats one on a parent.
        foreach ($direct as $category) {
            foreach (array_reverse($category->ancestors()) as $ancestor) {
                $chain[] = (int) $ancestor->getKey();
            }
        }

        return array_values(array_unique($chain));
    }

    /** Every enabled template, read once — there are a handful, and every page asks. */
    protected function candidates()
    {
        return $this->all ??= Template::query()
            ->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('storefront_id')->orWhere('storefront_id', current_storefront()?->id))
            // A storefront's own row beats the one that applies everywhere;
            // after that, the shop's own ordering.
            ->orderByRaw('storefront_id is null')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * What the template says about this record: the three fields, filled in,
     * with an empty one meaning "it had nothing to say".
     *
     * Placeholders are expanded UNESCAPED — this text ends up in an attribute
     * that Blade escapes on the way out, and escaping twice is how an ampersand
     * in a product name becomes `&amp;amp;`.
     *
     * @return array{title: string, description: string, h1: string}
     */
    public function render(Template $template, PageSubject $subject): array
    {
        $context = $this->context($subject);
        $rendered = [];

        foreach (['title', 'description', 'h1'] as $field) {
            $text = (string) ($template->{$field}[app()->getLocale()] ?? '');

            $rendered[$field] = $text === ''
                ? ''
                : trim(app(Placeholders::class)->expand($text, $context, false));
        }

        return $rendered;
    }

    /**
     * What the tokens are allowed to ask about — core's answer
     * ({@see PageMeta::contextOf()}), which the record's own fields are
     * expanded with too, so a token reads the same in a template and in the
     * field the template stands in for.
     *
     * @return array<string, mixed>
     */
    public function context(PageSubject $subject): array
    {
        return PageMeta::contextOf($subject);
    }

    /** For tests and for a screen that has just saved a template. */
    public function flush(): void
    {
        $this->memo = [];
        $this->all = null;
    }
}
