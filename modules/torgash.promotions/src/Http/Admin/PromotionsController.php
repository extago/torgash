<?php

namespace Torgash\Promotions\Http\Admin;

use App\Core\Display\DisplayRules;
use App\Http\Controllers\Controller;
use App\Models\CustomerGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Media\ImageProcessor;
use Torgash\Promotions\Models\Promotion;
use Torgash\Promotions\Models\PromotionTier;
use Torgash\Promotions\Schedule;

class PromotionsController extends Controller
{
    /** The list's tabs: a promotion's computed state, in the order a merchant scans them. */
    public const STATES = ['running', 'scheduled', 'paused', 'ended', 'off'];

    public function index(Request $request)
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'state' => in_array($request->query('state'), self::STATES, true) ? $request->query('state') : '',
            'zone' => in_array($request->query('zone'), [Promotion::ZONE_CATALOG, Promotion::ZONE_CART], true) ? $request->query('zone') : '',
        ];

        // A shop runs a handful of promotions, and the state is computed (dates,
        // a repeating window, the switch) rather than a column — so the list is
        // read whole and narrowed here, which is also what lets every tab say
        // how many of the searched ones it holds.
        $found = Promotion::with('tiers')->orderBy('zone')->orderBy('priority')->orderBy('id')->get()
            ->when($filters['zone'] !== '', fn ($all) => $all->where('zone', $filters['zone']))
            ->when($filters['q'] !== '', fn ($all) => $all->filter(fn (Promotion $promotion) => collect((array) $promotion->name)
                ->contains(fn ($name) => mb_stripos((string) $name, $filters['q']) !== false)));

        $stateCounts = ['' => $found->count()] + array_fill_keys(self::STATES, 0);
        foreach ($found as $promotion) {
            $stateCounts[$promotion->status()]++;
        }

        $promotions = $filters['state'] === ''
            ? $found->values()
            : $found->filter(fn (Promotion $promotion) => $promotion->status() === $filters['state'])->values();
        $tiers = $promotions->flatMap->tiers;

        return view('torgash.promotions::admin.promotions.index', [
            'promotions' => $promotions,
            'filters' => $filters,
            'stateCounts' => $stateCounts,
            // Only the give_group/free_product tiers actually reference these —
            // named lookups so the list reads "Gift: WIDGET-1" rather than an id.
            'groupNames' => CustomerGroup::whereIn('id', $tiers->pluck('give_group_id')->filter())
                ->get()->mapWithKeys(fn (CustomerGroup $g) => [$g->id => $g->t('name', admin_content_locale())])->all(),
            'productSkus' => Product::whereIn('id', $tiers->pluck('free_product_id')->filter())
                ->get(['id', 'sku'])->mapWithKeys(fn (Product $p) => [$p->id => $p->sku])->all(),
        ]);
    }

    public function create()
    {
        return view('torgash.promotions::admin.promotions.form', $this->pickerData() + [
            'promotion' => new Promotion(['zone' => Promotion::ZONE_CATALOG, 'combine' => Promotion::REPLACE, 'enabled' => true]),
        ]);
    }

    public function store(Request $request)
    {
        $promotion = Promotion::create($this->data($request));
        $this->saveTiers($request, $promotion);
        $this->saveBanner($request, $promotion);

        return $this->afterSave($request, $promotion, __('Promotion created.'));
    }

    /** Back to the form, as every edit form here does; "Save and close" is the way out. */
    protected function afterSave(Request $request, Promotion $promotion, string $message)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.promotions.index')
            : redirect()->route('admin.promotions.edit', $promotion))->with('status', $message);
    }

    /** The list's in-place switch: answers with the state cell. */
    public function setStatus(Request $request, Promotion $promotion)
    {
        $request->validate(['enabled' => 'required|boolean']);
        $promotion->update(['enabled' => $request->boolean('enabled')]);

        return view('torgash.promotions::admin.promotions._status', ['promotion' => $promotion]);
    }

    /** Several at once, through the models so the audit hears each one. */
    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['enable', 'disable', 'delete'])],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $promotions = Promotion::whereIn('id', $validated['ids'])->get();

        if ($validated['action'] === 'delete') {
            $promotions->each->delete();

            return back()->with('status', trans_choice(':count promotion deleted.|:count promotions deleted.', $promotions->count(), ['count' => $promotions->count()]));
        }

        $promotions->each(fn (Promotion $promotion) => $promotion->update(['enabled' => $validated['action'] === 'enable']));

        return back()->with('status', trans_choice(':count promotion updated.|:count promotions updated.', $promotions->count(), ['count' => $promotions->count()]));
    }

    /**
     * A copy to start the next sale from — "Black Friday" becomes "Cyber
     * Monday" by changing a date. It arrives SWITCHED OFF and without a page:
     * two identical sales running at once, or two pages at one address, is not
     * something a click may cause. Tiers are copied; the counter starts at
     * zero; the banner stays with the original.
     */
    public function duplicate(Promotion $promotion)
    {
        $copy = $promotion->replicate(['used_count', 'slug']);
        $copy->name = collect((array) $promotion->name)->map(fn ($name) => $name ? $name.' ('.__('copy').')' : $name)->all();
        $copy->enabled = false;
        $copy->published = false;
        $copy->used_count = 0;
        $copy->save();

        foreach ($promotion->tiers as $tier) {
            $copy->tiers()->create($tier->replicate(['promotion_id'])->toArray());
        }

        return redirect()->route('admin.promotions.edit', $copy)->with('status', __('A copy was made. It is switched off until you turn it on.'));
    }

    public function edit(Promotion $promotion)
    {
        return view('torgash.promotions::admin.promotions.form', $this->pickerData() + [
            'promotion' => $promotion->load('tiers'),
        ]);
    }

    /**
     * Everything a condition row's value picker draws from. The product list
     * is capped rather than paginated or searched — a stopgap until this
     * grows the same picker as the locations module's place autocomplete;
     * fine for a catalogue this module has been tried against so far.
     */
    protected function pickerData(): array
    {
        return [
            // Disabled categories are offered deliberately: a hidden, technical
            // category is the plainest way to hand a promotion an arbitrary set
            // of products, and it is the same set an automation rule can
            // condition on. Marked, so it is not mistaken for a real one.
            'categoryOptions' => (function () {
                $tree = app(CategoryTree::class);

                // Tree order and indented: a promotion aimed at the wrong
                // "Coffee" is a discount nobody notices until the month ends.
                return $tree->ordered()->map(fn (Category $c) => [
                    'id' => $c->id,
                    'label' => $tree->indent($c)
                        .($c->isListed() ? '' : ' · '.mb_strtolower($c->statusLabel())),
                ])->all();
            })(),
            'productOptions' => Product::orderBy('sku')->limit(500)->get(['id', 'sku'])
                ->map(fn (Product $p) => ['id' => $p->id, 'label' => $p->sku])->all(),
            'groupOptions' => CustomerGroup::orderBy('id')->get()
                ->map(fn (CustomerGroup $g) => ['id' => $g->id, 'label' => $g->t('name', admin_content_locale())])->all(),
            'featureOptions' => Feature::with('values')->orderBy('id')->get()
                ->map(fn (Feature $f) => [
                    'id' => $f->id,
                    'label' => $f->t('name', admin_content_locale()),
                    'values' => $f->values->map(fn ($v) => ['id' => $v->id, 'label' => $v->t('value', admin_content_locale())])->all(),
                ])->all(),
            'paymentMethodOptions' => PaymentMethod::orderBy('position')->get()
                ->map(fn (PaymentMethod $m) => ['id' => $m->id, 'label' => $m->t('name', admin_content_locale())])->all(),
        ];
    }

    public function update(Request $request, Promotion $promotion)
    {
        $promotion->update($this->data($request, $promotion));
        $this->saveTiers($request, $promotion);
        $this->saveBanner($request, $promotion);

        return $this->afterSave($request, $promotion, __('Promotion saved.'));
    }

    public function destroy(Promotion $promotion)
    {
        $promotion->delete();

        return redirect()->route('admin.promotions.index')->with('status', __('Promotion deleted.'));
    }

    protected function data(Request $request, ?Promotion $promotion = null): array
    {
        $locale = admin_content_locale();

        $validated = $request->validate([
            'name' => 'nullable|string|max:190',
            'zone' => ['required', Rule::in([Promotion::ZONE_CATALOG, Promotion::ZONE_CART])],
            'priority' => 'nullable|integer',
            'combine' => ['required', Rule::in([Promotion::REPLACE, Promotion::STACK_ADD, Promotion::STACK_MULTIPLY])],
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date',
            'usage_limit' => 'nullable|integer|min:1',
            'once_per_customer' => 'nullable|boolean',
            'slug' => 'nullable|string|max:190',
            'description' => 'nullable|string',
            'page_title' => 'nullable|string|max:190',
            'meta_description' => 'nullable|string|max:300',
            'banner' => 'nullable|image|max:8192',
            'badge' => ['nullable', Rule::in(Promotion::BADGES)],
            'badge_text' => 'nullable|string|max:12',
            'badge_icon' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]*$/'],
            'badge_image' => 'nullable|image|max:4096',
            'tint' => ['nullable', Rule::in([...array_keys(Promotion::TINTS), ...Promotion::BRAND_TINTS])],
        ]);

        // An icon name the pack does not have would draw nothing (and throw
        // in debug) — kept only when the file is there.
        $badgeIcon = ($validated['badge_icon'] ?? '') !== '' && is_file(resource_path('icons/lucide/'.$validated['badge_icon'].'.svg'))
            ? $validated['badge_icon'] : null;

        $name = $this->translatable($promotion?->name, $validated['name'] ?? '', $locale);

        return [
            'name' => $name,
            // Where it applies, through core's own engine — normalised there
            // too, because a rule the engine cannot evaluate would silently
            // switch the promotion off everywhere.
            'rules' => app(DisplayRules::class)->normalise($request->input('rules', [])),
            'rules_match' => $request->input('rules_match') === DisplayRules::MATCH_ANY
                ? DisplayRules::MATCH_ANY
                : DisplayRules::MATCH_ALL,
            'zone' => $validated['zone'],
            'priority' => (int) ($validated['priority'] ?? 0),
            'combine' => $validated['combine'],
            'enabled' => $request->boolean('enabled'),
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
            // Normalised through the value object that will later be asked
            // whether the sale is open — a half-typed window (no times) is
            // dropped here rather than stored to be ignored for ever, and the
            // admin gets the row back exactly as the engine reads it.
            'schedule' => $this->schedule($request),
            // Cart zone only (see Promotion::usableBy) — harmless if set on a
            // catalog promotion, since nothing there ever checks it.
            'usage_limit' => $validated['usage_limit'] ?? null,
            'once_per_customer' => $request->boolean('once_per_customer'),
            // The storefront half. `published` is not `enabled`: a sale can run
            // without a page, and a page must outlive its sale (a link in last
            // week's newsletter), so neither implies the other.
            'published' => $request->boolean('published'),
            'show_countdown' => $request->boolean('show_countdown'),
            'show_schedule' => $request->boolean('show_schedule'),
            'slug' => $this->slug($validated['slug'] ?? '', $name, $promotion),
            'description' => $this->translatable($promotion?->description, $validated['description'] ?? '', $locale),
            'page_title' => $this->translatable($promotion?->page_title, $validated['page_title'] ?? '', $locale),
            'meta_description' => $this->translatable($promotion?->meta_description, $validated['meta_description'] ?? '', $locale),
            'badge' => $validated['badge'] ?? 'auto',
            'badge_text' => $this->translatable($promotion?->badge_text, trim($validated['badge_text'] ?? ''), $locale) ?: null,
            'badge_icon' => $badgeIcon,
            'tint' => ($validated['tint'] ?? '') ?: null,
        ];
    }

    /**
     * A translated string edited one locale at a time — blank means "not
     * touched in this language", never "cleared everywhere", which is what a
     * naive assignment would do the moment a manager saves the form while the
     * content switcher is on RU.
     *
     * @param  array<string, string>|null  $current
     * @return array<string, string>
     */
    protected function translatable(?array $current, string $value, string $locale): array
    {
        // Core's one rule: blank removes this language, the others stay.
        return put_translation($current, $value, $locale);
    }

    /**
     * The page's address. Derived from the name when the field is left empty,
     * and made unique by suffix rather than by refusing the save: the column
     * carries a unique index, and a merchant naming two sales "Sale" should
     * get two pages, not a 500.
     *
     * @param  array<string, string>  $name
     */
    protected function slug(string $typed, array $name, ?Promotion $promotion): ?string
    {
        $base = $typed !== '' ? $typed : ($promotion?->slug ?? '');

        if ($base === '') {
            $baseLocale = content_base_locale();
            $base = $name[$baseLocale] ?? (reset($name) ?: '');
        }

        $slug = Str::slug($base);

        if ($slug === '') {
            // Nothing to build an address from (a name in an alphabet the
            // transliterator does not know, or no name at all). NULL rather
            // than a made-up string: published() requires a slug, so the sale
            // simply has no page until it is given one.
            return null;
        }

        $candidate = $slug;
        for ($n = 2; Promotion::where('slug', $candidate)->where('id', '!=', $promotion?->id)->exists(); $n++) {
            $candidate = $slug.'-'.$n;
        }

        return $candidate;
    }

    /**
     * Through the media module's processor, not addMedia() straight off the
     * request: that is where the size limits, the downscale and the format
     * rules live, and a sale banner is exactly the file a merchant uploads
     * at 6000px straight from a camera.
     */
    protected function saveBanner(Request $request, Promotion $promotion): void
    {
        if ($request->boolean('banner_remove')) {
            $promotion->clearMediaCollection('banner');
        }

        if ($request->hasFile('banner')) {
            app(ImageProcessor::class)->addUpload($promotion, $request->file('banner'), 'banner');
        }

        // The tile's own picture, the same way.
        if ($request->boolean('badge_image_remove')) {
            $promotion->clearMediaCollection('badge');
        }

        if ($request->hasFile('badge_image')) {
            app(ImageProcessor::class)->addUpload($promotion, $request->file('badge_image'), 'badge');
        }
    }

    /**
     * The repeating window, or NULL — "no windows" and "no schedule" are the
     * same thing to everything downstream, and storing an empty list would
     * make Promotion::activeInZone's filter answer a question nobody asked.
     *
     * @return array{windows: list<array{days: list<int>, from: string, to: string}>}|null
     */
    protected function schedule(Request $request): ?array
    {
        $windows = Schedule::fromArray((array) $request->input('schedule'))->toArray();

        return $windows === [] ? null : ['windows' => $windows];
    }

    /**
     * Tiers are replaced wholesale on every save — the admin edits the whole
     * ordered list at once (same as a shipping rate's rows), so there is no
     * "which existing row is this" to reconcile.
     */
    protected function saveTiers(Request $request, Promotion $promotion): void
    {
        $promotion->tiers()->delete();

        foreach ((array) $request->input('tiers', []) as $position => $tier) {
            $rules = [];
            foreach ((array) ($tier['rules'] ?? []) as $rule) {
                if (empty($rule['kind']) || empty($rule['operator'])) {
                    continue;
                }

                $row = ['kind' => $rule['kind'], 'operator' => $rule['operator']];

                if (! empty($rule['ids'])) {
                    $row['ids'] = array_values(array_unique(array_filter(array_map('intval', (array) $rule['ids']))));
                }
                if (($rule['value'] ?? '') !== '') {
                    $row['value'] = $rule['kind'] === 'subtotal' || $rule['kind'] === 'price'
                        ? (int) round((float) $rule['value'] * 100)
                        : (int) $rule['value'];
                }
                if (! empty($rule['feature_id'])) {
                    $row['feature_id'] = (int) $rule['feature_id'];
                }

                $rules[] = $row;
            }

            $discountType = in_array($tier['discount_type'] ?? null, [
                ...PromotionTier::PRICE_TYPES,
                PromotionTier::GIVE_GROUP, PromotionTier::FREE_PRODUCT,
            ], true) ? $tier['discount_type'] : PromotionTier::PERCENT;

            // Restricts a percent/fixed discount to specific products/categories
            // within the cart ("discount on products / categories") —
            // meaningless, so ignored, for any other discount type.
            $scopeType = $tier['discount_scope_type'] ?? '';
            $discountScope = in_array($discountType, PromotionTier::ADJUSTING_TYPES, true)
                && in_array($scopeType, ['product', 'category'], true)
                && ! empty($tier['discount_scope_ids'])
                ? ['type' => $scopeType, 'ids' => array_values(array_unique(array_filter(array_map('intval', (array) $tier['discount_scope_ids']))))]
                : null;

            PromotionTier::create([
                'promotion_id' => $promotion->id,
                'position' => (int) $position,
                'conditions' => ['match' => ($tier['match'] ?? 'all') === 'any' ? 'any' : 'all', 'rules' => $rules],
                'discount_type' => $discountType,
                // A money value is typed in major units and stored in minor
                // ones; a percent is the number itself. One list answers it,
                // so a new money-valued type cannot be added and forgotten.
                'discount_value' => in_array($discountType, PromotionTier::MONEY_VALUE_TYPES, true)
                    ? (int) round((float) ($tier['discount_value'] ?? 0) * 100)
                    : (int) ($tier['discount_value'] ?? 0),
                'discount_scope' => $discountScope,
                'give_group_id' => $discountType === PromotionTier::GIVE_GROUP ? (int) ($tier['give_group_id'] ?? 0) ?: null : null,
                'free_product_id' => $discountType === PromotionTier::FREE_PRODUCT ? (int) ($tier['free_product_id'] ?? 0) ?: null : null,
                'free_qty' => max(1, (int) ($tier['free_qty'] ?? 1)),
            ]);
        }
    }
}
