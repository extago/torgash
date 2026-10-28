<?php

namespace Torgash\Promotions\Models;

use App\Core\Display\DisplayRules;
use App\Core\Support\HasTranslations;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Torgash\Catalog\Models\Product;
use Torgash\Promotions\Conditions;
use Torgash\Promotions\Schedule;

/**
 * One sale event: a zone, a priority/combine stance against other
 * promotions, and an ordered list of Tiers that carry the actual
 * conditions+discount. See the migration's docblock for why tiers exist and
 * what `combine` means.
 */
class Promotion extends Model implements HasMedia
{
    use HasTranslations, InteractsWithMedia;

    protected $table = 'm_promotions_promotions';

    protected $guarded = [];

    protected ?Schedule $scheduleObject = null;

    public const ZONE_CATALOG = 'catalog';
    public const ZONE_CART = 'cart';

    public const REPLACE = 'replace';
    public const STACK_ADD = 'stack_add';
    public const STACK_MULTIPLY = 'stack_multiply';

    /** What stands on the sale's tile (the `badge` column) — see tile(). */
    public const BADGES = ['auto', 'picture', 'text', 'icon', 'none'];

    /** The named colours a sale may wear, as hues (oklch). Empty = by id. */
    public const TINTS = [
        'red' => 25, 'orange' => 55, 'yellow' => 85, 'green' => 150,
        'teal' => 175, 'blue' => 250, 'violet' => 295, 'pink' => 340,
    ];

    /**
     * Tints that follow the shop's own colours (PLAN §52): the hue of the
     * theme's base, second or accent colour as the shop set it — change the
     * brand colour and a sale tinted "base" changes with it.
     */
    public const BRAND_TINTS = ['primary', 'secondary', 'accent'];

    /** "Automatic": by id, in the order the sale cards always used, so neighbours differ. */
    public const AUTO_HUES = [25, 250, 150, 340, 205, 80, 295, 175];

    /**
     * The DB default, mirrored: Postgres does not hand it back after an
     * INSERT, and a sale created in code would otherwise have no look.
     */
    protected $attributes = [
        'badge' => 'auto',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'page_title' => 'array',
            'meta_description' => 'array',
            'badge_text' => 'array',
            'enabled' => 'bool',
            'published' => 'bool',
            'show_countdown' => 'bool',
            'show_schedule' => 'bool',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'rules' => 'array',
            'schedule' => 'array',
        ];
    }

    /**
     * Does this promotion apply HERE — on this storefront, in this language, to
     * this visitor? Core's display rules, the same ones a content block is
     * shown by; no rules means everywhere, which is every promotion that has
     * ever existed until now.
     *
     * `$context` is for the callers that know better than the current request:
     * an order being re-priced in the admin belongs to the storefront it was
     * PLACED on, not to whatever the manager is looking at — without that, a
     * manager editing an order would silently drop a storefront-scoped
     * discount, because a rule with no context to judge by fails closed.
     *
     * @param  array<string, mixed>  $context
     */
    public function appliesIn(array $context = []): bool
    {
        return app(DisplayRules::class)->passes(
            (array) ($this->rules ?? []),
            $context,
            $this->rules_match === DisplayRules::MATCH_ANY ? DisplayRules::MATCH_ANY : DisplayRules::MATCH_ALL,
        );
    }

    public function tiers(): HasMany
    {
        return $this->hasMany(PromotionTier::class)->orderBy('position');
    }

    public function isRunning(): bool
    {
        return $this->status() === 'running';
    }

    /**
     * The repeating window inside the dates — see Schedule.
     *
     * Not named schedule(): the column is called that, and a model method
     * sharing a column's name is read by Eloquent as a RELATION the moment
     * the attribute is absent (a brand-new Promotion in the create form),
     * which throws instead of answering "no schedule".
     */
    public function scheduleWindows(): Schedule
    {
        return $this->scheduleObject ??= Schedule::fromArray($this->schedule);
    }

    public function isOpenNow(): bool
    {
        return $this->scheduleWindows()->isOpenAt(now());
    }

    /**
     * The moment a customer is counting down to: whichever comes first, the
     * close of today's window or the end of the sale itself. NULL when the
     * sale has neither — an open-ended promotion has nothing to count.
     *
     * Deliberately not "the end of the day" (which is one of the usual two
     * countdown settings): a timer that runs out while the price stays the
     * same teaches customers that the timer is decoration.
     */
    public function countdownEndsAt(): ?Carbon
    {
        // Nothing to count while the sale is not on. Without this a paused
        // deal-of-the-day counted down to the END DATE of a sale that is not
        // running this minute — a timer beside full-price goods, which is the
        // one thing a countdown must never be (measured on the sale page: the
        // page said "opens in 2 days" and the card beside it ticked).
        if (! $this->isRunning()) {
            return null;
        }

        $closes = $this->scheduleWindows()->closesAt(now());
        $ends = $this->ends_at;

        if ($closes && $ends) {
            return $closes->lessThan($ends) ? $closes : $ends;
        }

        return $closes ?: $ends;
    }

    /** The windows in words, for the admin list and the sale page. */
    public function describeSchedule(): string
    {
        return $this->scheduleWindows()->describe();
    }

    /** When a paused promotion opens again, for "starts at 18:00". */
    public function opensAt(): ?Carbon
    {
        return $this->scheduleWindows()->opensAt(now());
    }

    /**
     * Four states, not two — "off" alone hides the difference between a sale
     * that hasn't started, one that quietly expired last month, and one that
     * is live right now, and that difference is exactly what a merchant
     * scanning this list needs to catch a forgotten promotion.
     *
     * @return 'off'|'scheduled'|'ended'|'paused'|'running'
     */
    public function status(): string
    {
        if (! $this->enabled) {
            return 'off';
        }

        $now = now();

        if ($this->starts_at && $now->lt($this->starts_at)) {
            return 'scheduled';
        }

        if ($this->ends_at && $now->gt($this->ends_at)) {
            return 'ended';
        }

        // In its dates, outside today's window — a fifth state only a
        // scheduled promotion can be in, and one a merchant must be able to
        // tell from 'running': otherwise the list says a sale is live while
        // the storefront charges full price, which is exactly the report we
        // would get as a bug.
        if (! $this->isOpenNow()) {
            return 'paused';
        }

        return 'running';
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('banner')->singleFile();
        $this->addMediaCollection('badge')->singleFile();
    }

    public function badgeUrl(): ?string
    {
        return $this->getFirstMediaUrl('badge') ?: null;
    }

    /**
     * The hue the sale is drawn in: the shop's choice, or — left empty — one
     * of the same eight picked by id, so a sale keeps its colour from page to
     * page without anybody choosing.
     */
    public function hue(): int
    {
        if (in_array($this->tint, self::BRAND_TINTS, true) && ($hue = self::brandHue($this->tint)) !== null) {
            return $hue;
        }

        return self::TINTS[$this->tint] ?? self::AUTO_HUES[$this->id % count(self::AUTO_HUES)];
    }

    /** The OKLCH hue of one of the shop's colours, or null when the theme has no such colour. */
    public static function brandHue(string $role): ?int
    {
        $hex = app(\App\Core\Themes\ShopPalette::class)->colours(current_storefront())[$role] ?? null;

        return $hex ? (int) round(\App\Core\Support\Colour::oklch($hex)[2]) : null;
    }

    /**
     * What stands on the sale's tile, as one of
     * `['picture', url]`, `['text', words]`, `['icon', name]` or null (none).
     *
     * `auto` is what it always was: the banner, else the offer when it is
     * short enough to read on a tile (`$offer`, Showcase::headline()), else an
     * icon. A choice that has nothing behind it (a picture never uploaded,
     * empty words) falls back to `auto` rather than drawing an empty square.
     *
     * @return array{0: string, 1: string}|null
     */
    public function tile(?string $offer): ?array
    {
        $icon = $this->scheduleWindows()->isEmpty() ? 'badge-percent' : 'repeat';

        $chosen = match ($this->badge) {
            'none' => false,
            'picture' => ($url = $this->badgeUrl()) ? ['picture', $url] : null,
            'text' => ($words = trim((string) $this->t('badge_text'))) !== '' ? ['text', $words] : null,
            'icon' => ['icon', $this->badge_icon ?: $icon],
            default => null,
        };

        if ($chosen !== null) {
            return $chosen ?: null;
        }

        return match (true) {
            (bool) $this->bannerUrl() => ['picture', $this->bannerUrl()],
            $offer !== null && mb_strlen($offer) <= 5 => ['text', $offer],
            default => ['icon', $icon],
        };
    }

    public function bannerUrl(): ?string
    {
        return $this->getFirstMediaUrl('banner') ?: null;
    }

    /**
     * Has a page on the storefront. Deliberately not "is running": a link in
     * last week's newsletter must reach a page that says the sale is over
     * rather than a 404 — the same rule catalog's reachable() draws for a
     * product that has been hidden.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('published', true)->whereNotNull('slug');
    }

    /** What the page is titled for search engines, falling back to its name. */
    public function pageTitle(?string $locale = null): string
    {
        return $this->t('page_title', $locale) ?: $this->t('name', $locale);
    }

    /**
     * Everything this sale applies to, as a QUERY — the sale page's own list,
     * and the only place a tier's conditions are asked in SQL rather than of
     * one loaded product.
     *
     * "Any tier matches", not "the first one wins": the winning tier decides
     * HOW MUCH a product is discounted, but every product any tier matches is
     * in the sale, and that is the question a page listing it asks.
     *
     * Bounded to Product::listed() — the sale page is a storefront listing
     * like any other, so a product hidden from this visitor stays hidden even
     * with a promotion pointing at it.
     */
    public function productQuery(?User $user = null): Builder
    {
        return $this->constrainProducts(Product::listed(), $user);
    }

    /**
     * The same narrowing, onto a query somebody else started — a product block
     * showing this sale (see the `catalog.product_sources` answer in the
     * provider), whose query is already the listing's.
     */
    public function constrainProducts(Builder $query, ?User $user = null): Builder
    {
        if ($this->zone !== self::ZONE_CATALOG || $this->tiers->isEmpty()) {
            // A cart-zone sale is money off an order, not a set of products;
            // its page describes the offer instead of listing a catalogue.
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $outer) use ($user) {
            foreach ($this->tiers as $tier) {
                $outer->orWhere(fn (Builder $inner) => Conditions::applyProductQuery(
                    $inner, $tier->rules(), $tier->matchAny(), $user,
                ));
            }
        });
    }

    /**
     * The total cap and "once per customer" gate — cart zone only, checked
     * against this promotion's OWN snapshot rows (m_promotions_order_promotions
     * joined to the orders that carry them), same table CartPricing::snapshot()
     * writes to. Not identity-independent like the rest of matching: a guest
     * with no order yet has no customer_ref to check against, so an anonymous
     * $customerRef of null is let through here and re-checked for real once the
     * order (and its customer_ref) exists — see PromotionsServiceProvider.
     *
     * **The cap is advisory under concurrency, deliberately.** This is read,
     * and `used_count` incremented, from `checkout.order_placed`, which fires
     * AFTER PlaceOrder's transaction has committed — so two checkouts landing
     * together can both see the last redemption and both take it. Closing that
     * would mean locking this row inside another module's order transaction,
     * which checkout exposes no hook for, and the alternatives are worse: a
     * conditional increment would hide the overshoot rather than prevent it,
     * and un-applying the discount afterwards would move a total the customer
     * has already been shown on their confirmation. `used_count` is therefore
     * allowed to exceed `usage_limit` and the admin list prints it in warning
     * colour when it does — the shop sees the truth, one order late.
     */
    public function usableBy(?User $user, ?string $customerRef): bool
    {
        if ($this->usage_limit !== null && $this->used_count >= $this->usage_limit) {
            return false;
        }

        if (! $this->once_per_customer) {
            return true;
        }

        if ($user) {
            return ! DB::table('m_promotions_order_promotions')
                ->join('m_checkout_orders', 'm_checkout_orders.id', '=', 'm_promotions_order_promotions.order_id')
                ->where('m_promotions_order_promotions.promotion_id', $this->id)
                ->where('m_checkout_orders.user_id', $user->id)
                ->exists();
        }

        if ($customerRef) {
            return ! DB::table('m_promotions_order_promotions')
                ->join('m_checkout_orders', 'm_checkout_orders.id', '=', 'm_promotions_order_promotions.order_id')
                ->where('m_promotions_order_promotions.promotion_id', $this->id)
                ->where('m_checkout_orders.customer_ref', $customerRef)
                ->exists();
        }

        return true;
    }

    /**
     * The next moment any enabled sale starts, ends, or opens or closes its
     * window — prices change then with nothing written, and a page kept
     * ready for guests is kept only until then (PLAN §61). Null: never.
     */
    public static function nextChange(): ?Carbon
    {
        $now = now();
        $next = null;

        $sooner = function (?CarbonInterface $at) use (&$next, $now) {
            if ($at !== null && $at->gt($now) && ($next === null || $at->lt($next))) {
                $next = Carbon::instance($at);
            }
        };

        $running = static::query()
            ->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->get();

        foreach ($running as $promotion) {
            $sooner($promotion->starts_at);
            // In its dates through the end's whole second (`>= now()`).
            $sooner($promotion->ends_at?->copy()->addSecond());

            $windows = $promotion->scheduleWindows();

            if (! $windows->isEmpty()) {
                $sooner($windows->isOpenAt($now) ? $windows->closesAt($now) : $windows->opensAt($now));
            }
        }

        return $next;
    }

    /**
     * Enabled, in-date promotions in a zone, priority order (lowest first) —
     * the order both cross-promotion combine and the admin's tie-break read.
     */
    public static function activeInZone(string $zone)
    {
        return static::query()
            ->where('zone', $zone)
            ->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->with('tiers')
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            // The window is the one part of "is it running" that SQL is not
            // asked: a weekly pattern in jsonb would be a date_part()
            // expression nobody could read, over a handful of rows that are
            // already in memory. Everything narrower — the dates, the switch
            // — stays in the query, so this filters a short list.
            ->filter->isOpenNow()
            ->values();
    }
}
