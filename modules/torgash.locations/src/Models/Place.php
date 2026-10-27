<?php

namespace Torgash\Locations\Models;

use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Torgash\Locations\Declension;

class Place extends Model
{
    use HasTranslations;

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Locality';
    }

    /** search_text is maintained by the database; writing to it is an error. */
    public const GENERATED_COLUMNS = ['search_text'];

    /**
     * Stored as a code, shown as a phrase. The phrase is also the translation
     * key, because English source strings are what this project translates
     * from — printing the raw code was leaking "region_center" into the UI.
     *
     * The manifest repeats these codes and phrases as the options of the
     * `hidden_types` setting (a JSON file cannot read a PHP constant): a type
     * added here and not there simply cannot be switched off.
     */
    public const TYPES = [
        'capital' => 'Capital',
        'region_center' => 'Regional centre',
        'district_center' => 'District centre',
        'community_center' => 'Community centre',
        'settlement' => 'Town or village',
        'city_district' => 'City district',
    ];

    protected $table = 'm_locations_places';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'district' => 'array',
            'forms' => 'array',
            'enabled' => 'bool',
            'edited' => 'bool',
            'missing_in_source' => 'bool',
            'codes' => 'array',
            'population' => 'int',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /**
     * What a customer types, matched against every name and alias at once.
     *
     * Ordered by population, not by relevance: someone typing "Дн" means
     * Дніпро, not a hamlet of eight people that happens to sort first.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = mb_strtolower(trim($term));

        if ($term === '') {
            return $query->whereRaw('false');
        }

        return $query
            ->where('search_text', 'like', '%'.addcslashes($term, '%_').'%')
            // The id breaks ties: without it, two villages of equal size can
            // swap places between one page of suggestions and the next, which
            // shows a duplicate and hides a real match.
            ->orderByDesc('population')
            ->orderBy('id');
    }

    /**
     * Nearest to a point first — "update by GPS", a guess by IP. Plain
     * degrees with longitude shrunk by the latitude's cosine, which is plenty
     * inside one town's worth of distance; among the few nearest, a populated
     * place beats a hamlet on its edge. Only within half a degree: nothing
     * there is no town, not one three regions away.
     */
    public function scopeNearest(Builder $query, float $lat, float $lng): Builder
    {
        $cos = max(0.1, cos(deg2rad($lat)));

        return $query
            ->whereBetween('latitude', [$lat - 0.5, $lat + 0.5])
            ->whereBetween('longitude', [$lng - 0.5 / $cos, $lng + 0.5 / $cos])
            ->orderByRaw('(power(latitude - ?, 2) + power((longitude - ?) * ?, 2)) / (1 + ln(1 + population) / 10)', [$lat, $lng, $cos]);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * What a customer may actually pick: the place is on, and so are the region
     * and the country it sits in. Switching a country off has to take its towns
     * with it, or "we don't ship there" would need doing three times.
     *
     * Whole TYPES can be switched off too (`hidden_types`). That is a filter,
     * not a flag on the rows: a city district is a real settlement the pack is
     * right to carry and a zone may already match, it is just not something
     * anybody writes in a delivery address — so it disappears from the field
     * and stays in the registry, one tick away from coming back.
     */
    public function scopeAvailable(Builder $query): Builder
    {
        $hidden = array_filter((array) setting('torgash.locations', 'hidden_types', []));

        return $query->where('enabled', true)
            ->when($hidden, fn ($sub) => $sub->whereNotIn('type', $hidden))
            ->whereExists(fn ($sub) => $sub->selectRaw('1')
                ->from('m_locations_countries as c')
                ->whereColumn('c.code', 'm_locations_places.country')
                ->where('c.enabled', true))
            ->where(fn ($outer) => $outer
                ->whereNull('state_id')
                ->orWhereExists(fn ($sub) => $sub->selectRaw('1')
                    ->from('m_locations_states as s')
                    ->whereColumn('s.id', 'm_locations_places.state_id')
                    ->where('s.enabled', true)));
    }

    /** Human label for the stored type code. */
    public function typeLabel(): string
    {
        return __(self::TYPES[$this->type] ?? $this->type);
    }

    /**
     * The name in one grammatical case: what the shop typed, else what the
     * rules derive, else the plain name.
     *
     * The order is the whole design. A pack brings 192 000 towns and nobody
     * types six forms for each, so the rules answer by default
     * ({@see Declension}); a shop publishing a city page fixes that one city on
     * its own screen and the correction wins for ever. Same shape as
     * `Product::resolvedWeight()`.
     *
     * Read at ONE locale with no cross-language fallback, unlike `t()`: a
     * Russian genitive is not an answer to a question asked in Ukrainian, and
     * printing it would be worse than printing the nominative.
     */
    public function form(int $case = 1, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $name = (string) ($this->name[$locale] ?? '');

        if ($typed = trim((string) ($this->forms[$locale][$case] ?? $this->forms[$locale][(string) $case] ?? ''))) {
            return $typed;
        }

        // No name in this language at all: t() may fall through to another
        // one, and a name is better than nothing — but it cannot be declined
        // by this language's rules, so it is returned as it stands.
        if ($name === '') {
            return (string) ($this->t('name', $locale) ?? '');
        }

        return app(Declension::class)->form($name, $locale, $case);
    }

    /**
     * Every form for one language, for the admin's own screen: what is typed,
     * with the derivation beside it as the placeholder.
     *
     * @return array<int, array{case: int, label: string, typed: string, derived: string}>
     */
    public function formRows(string $locale): array
    {
        $declension = app(Declension::class);
        $name = (string) ($this->name[$locale] ?? '');
        $rows = [];

        foreach ($declension->cases($locale) as $case) {
            $rows[$case] = [
                'case' => $case,
                'label' => $declension->label($locale, $case),
                'typed' => (string) ($this->forms[$locale][$case] ?? $this->forms[$locale][(string) $case] ?? ''),
                'derived' => $name === '' ? '' : $declension->form($name, $locale, $case),
            ];
        }

        return $rows;
    }

    /** "Шевченкове (Баштанський район)" — the label that makes a namesake pickable. */
    public function label(?string $locale = null): string
    {
        $name = $this->t('name', $locale) ?? '';
        $district = $this->t('district', $locale);

        return $district ? "{$name} ({$district})" : $name;
    }
}
