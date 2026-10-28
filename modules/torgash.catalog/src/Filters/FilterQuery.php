<?php

namespace Torgash\Catalog\Filters;

use Illuminate\Database\Query\Expression;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\FilterPage;

/**
 * What a filtered address SAYS, in both spellings.
 *
 * The shop reported `?feature%5B8%5D%5B%5D=22` and was right to: an address
 * made of database ids tells a visitor nothing, does not survive a re-import
 * that renumbers the catalogue, and is the one part of a shop a person actually
 * reads out loud. So a characteristic with a code and values with codes is
 * written the way it is spoken — `?material=stainless-steel,wood&brand=acme` —
 * and the ids stay as the fallback for everything else.
 *
 * Three rules, and each is here rather than in the controller because both
 * halves of the seam (reading an address, writing one) have to agree exactly:
 *
 * **Both spellings are read, for ever.** Links live in bookmarks, in other
 * people's pages and in a search index, and the id form is what this shop
 * published up to now. They also mix freely in one address, which is what a
 * half-coded catalogue produces.
 *
 * **A comma separates values of one characteristic**, because a checkbox group
 * cannot emit anything better: repeating `?material=steel&material=wood` leaves
 * PHP holding only the last one, and `material[]=` is the bracket soup this
 * exists to remove.
 *
 * **A characteristic coded like something the catalogue already owns keeps the
 * id form.** `?page=2` means the second page; a "Page" characteristic may not
 * take that word away from it.
 */
class FilterQuery
{
    /**
     * Query parameters the catalogue owns outright — see the class docblock.
     *
     * `in` is the category a sale's or a search's listing is narrowed to
     * (Listing::narrowToCategory()), `scope` what a search was asked about.
     *
     * The yes/no filters are NOT here: they are a registry now, so
     * {@see reserved()} adds whatever is in it.
     */
    public const RESERVED = ['page', 'q', 'sort', 'feature', 'price_min', 'price_max', 'price_currency', 'per_page', 'view', 'in', 'scope'];

    /**
     * The id of a value that cannot exist, for a code nobody knows — see
     * {@see resolve()}. Negative rather than 0, which `canonicalValues()`
     * discards along with every other empty answer.
     */
    public const MISSING = -1;

    /** @var array<string, Feature>|null url name => feature */
    protected ?array $byParam = null;

    /** @var array<string, array{selected: array<int, list<int>>, unknown: array<int, list<string>>}> */
    protected array $memo = [];

    /**
     * The characteristics this shop can spell out, keyed by what they are
     * called in an address.
     *
     * @return array<string, Feature>
     */
    public function features(): array
    {
        if ($this->byParam !== null) {
            return $this->byParam;
        }

        $map = [];

        // EVERY characteristic with a code, whatever a shop has ticked about
        // where it is drawn: what an address MEANS may not depend on whether
        // the sidebar happens to offer a control for it — the rule the flags
        // reserve their keys by. A published filter page is a set of values at
        // an address of its own, so a characteristic that stopped narrowing
        // when its box was unticked would widen every page built on it into
        // its whole category. See Features\Places.
        foreach (Feature::whereNotNull('code')->get() as $feature) {
            $param = $this->nameOf($feature->code);

            // A collision between two codes that differ only in case is not
            // worth a rule: the first one keeps the word and the second falls
            // back to its id, which still works.
            if ($param === null || isset($map[$param])) {
                continue;
            }

            $map[$param] = $feature;
        }

        return $this->byParam = $map;
    }

    /** What this characteristic is called in an address, or null when it has to use its id. */
    public function paramFor(Feature $feature): ?string
    {
        $param = $this->nameOf($feature->code);

        return $param !== null && ($this->features()[$param] ?? null)?->is($feature) ? $param : null;
    }

    /** What one value is called there — null when it has no code, or its characteristic has none. */
    public function valueParamFor(FeatureValue $value, ?Feature $feature = null): ?string
    {
        $feature ??= $value->feature;

        if (! $feature || ! $this->paramFor($feature)) {
            return null;
        }

        return $this->nameOf($value->code);
    }

    /**
     * Everything the visitor has filtered by, whichever way they said it.
     *
     * @return array<int, list<int>>
     */
    public function parse(Request $request): array
    {
        return $this->resolve($request)['selected'];
    }

    /**
     * Characteristics whose codes this shop has never heard of — a stale link,
     * a typo, a value deleted since.
     *
     * They are worth reporting because they are otherwise INVISIBLE: the page
     * shows nothing and says nothing about why, with no way back except editing
     * the address by hand.
     *
     * @return array<int, list<string>> feature id => the codes as they were written
     */
    public function unknown(Request $request): array
    {
        return $this->resolve($request)['unknown'];
    }

    /**
     * Both answers, worked out once.
     *
     * Memoised on the query string rather than recomputed: three separate
     * places ask this about the same request — the grid's query, the sidebar's
     * ticked boxes and the row of active filters — and each miss is a database
     * round trip per characteristic named in the address.
     *
     * The rule that shapes it: **codes nobody knows match NOTHING**, never
     * "no filter at all". `?feature[8][]=999` in the other spelling already
     * matches nothing, and two spellings of one address that disagree about
     * that would show the whole catalogue as though it were the filtered set.
     *
     * @return array{selected: array<int, list<int>>, unknown: array<int, list<string>>}
     */
    protected function resolve(Request $request): array
    {
        $key = (string) $request->server('QUERY_STRING');

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $selected = (array) $request->input('feature', []);
        $unknown = [];

        foreach ($this->features() as $param => $feature) {
            $codes = $this->codesIn($request, $param);

            if ($codes === []) {
                continue;
            }

            // One query per characteristic NAMED IN THE ADDRESS, not one per
            // characteristic the shop has: a filtered page mentions one or two.
            $found = FeatureValue::where('feature_id', $feature->id)
                ->whereIn(new Expression('lower(code)'), $codes)
                ->get(['id', 'code']);
            $ids = $found->pluck('id')->all();

            // A code merged into another value (PLAN §60) still means that
            // value — a bookmark of `?color=temno-siniy` keeps finding the
            // products it found. Asked only for codes no value has, so a value
            // that takes such a code again wins, and an ordinary address costs
            // nothing more.
            $merged = array_values(array_diff($codes, $found->map(fn (FeatureValue $value) => Str::lower((string) $value->code))->all()));

            if ($merged !== []) {
                $ids = [...$ids, ...FeatureValue::where('feature_id', $feature->id)
                    ->whereRaw(
                        "exists (select 1 from jsonb_array_elements(coalesce(aliases, '[]'::jsonb)) a where lower(a->>'code') in (".implode(', ', array_fill(0, count($merged), '?')).'))',
                        $merged,
                    )
                    ->pluck('id')->all()];
            }

            if ($ids === []) {
                $unknown[$feature->id] = $codes;
            }

            $selected[$feature->id] = [
                ...(array) ($selected[$feature->id] ?? []),
                ...($ids ?: [self::MISSING]),
            ];
        }

        return $this->memo[$key] = [
            'selected' => FilterPage::canonicalValues($selected),
            'unknown' => $unknown,
        ];
    }

    /**
     * A selection written back out, spoken where it can be and numbered where
     * it cannot.
     *
     * The caller hands over the value records it already has, because the one
     * place this is used — the row of active filters — has just loaded them to
     * put their names on screen.
     *
     * @param  array<int, list<int>>  $selected
     * @param  Collection<int, FeatureValue>  $values  keyed by id
     * @return array<string, mixed>
     */
    public function queryFor(array $selected, Collection $values): array
    {
        $query = [];

        foreach ($selected as $featureId => $valueIds) {
            $spoken = [];
            $numbered = [];

            foreach ($valueIds as $valueId) {
                $value = $values->get($valueId);
                $param = $value ? $this->valueParamFor($value) : null;

                // All or nothing per characteristic: half a list spoken and
                // half numbered would be two parameters meaning one thing.
                if ($param === null) {
                    $numbered = $valueIds;
                    $spoken = [];
                    break;
                }

                $spoken[] = $param;
            }

            if ($spoken !== []) {
                $query[$this->paramFor($values->get($valueIds[0])->feature)] = implode(',', $spoken);

                continue;
            }

            $query['feature'][$featureId] = array_values($numbered);
        }

        return $query;
    }

    /** For tests and for an admin that has just renamed a code. */
    public function flush(): void
    {
        $this->byParam = null;
        $this->memo = [];
    }

    /**
     * The codes named for one characteristic: `?material=steel,wood`, and also
     * `?material[]=steel` because somebody will write it that way by hand.
     *
     * @return list<string>
     */
    protected function codesIn(Request $request, string $param): array
    {
        $raw = $request->query($param);

        if ($raw === null) {
            return [];
        }

        $parts = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_filter(array_map(
            fn ($code) => Str::lower(trim((string) $code)),
            $parts,
        )));
    }

    /**
     * Every word a characteristic may not be called.
     *
     * The flags are included whether or not the shop OFFERS them: what an
     * address means may not change because somebody ticked a box on a settings
     * screen, and a catalogue whose "Page" characteristic started answering to
     * `?on_sale=` the day the sale filter was switched off would be exactly
     * that.
     *
     * @return list<string>
     */
    public function reserved(): array
    {
        return [...self::RESERVED, ...array_keys(app(Flags::class)->all())];
    }

    /** A code as it appears in an address: lower case, and never one of ours. */
    protected function nameOf(?string $code): ?string
    {
        $name = Str::lower(trim((string) $code));

        if ($name === '' || in_array($name, $this->reserved(), true)) {
            return null;
        }

        return $name;
    }
}
