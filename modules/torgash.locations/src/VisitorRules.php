<?php

namespace Torgash\Locations;

use App\Core\Display\RuleKinds;
use Torgash\Locations\Models\Country;
use Torgash\Locations\Models\Place;
use Torgash\Locations\Models\State;

/**
 * "Show this only to visitors from…" (PLAN §51): display rules about the
 * town the visitor chose in "your town" — or named at checkout — read
 * through checkout's `storefront.visitor_place`, the one place it is kept.
 *
 * Nothing is guessed from the address the visitor came from (the owner's
 * call): a visitor who has not chosen a town is in no town, so "the town is
 * Kyiv" is a No for them and "is not Kyiv" a Yes — and "has not chosen a
 * town" is a condition of its own, for the banner they should see instead.
 *
 * Towns are SEARCHED in the editor (a registry holds 174 000 of them); regions
 * and countries are short enough to list.
 */
class VisitorRules
{
    public static function register(): void
    {
        RuleKinds::register('visitor_town', [
            'label' => 'Visitor\'s town',
            'group' => 'who',
            'value' => 'search',
            'search' => fn () => route('places.suggest'),
            'labels' => fn (array $ids) => Place::query()->with('state')->whereIn('id', array_map('intval', $ids))->get()
                ->mapWithKeys(fn (Place $place) => [(string) $place->id => $place->label()])->all(),
            'operators' => [
                'in' => 'is one of',
                'not_in' => 'is not one of',
            ],
            'matches' => fn (array $rule) => self::inList(self::place()?->id, $rule),
        ]);

        RuleKinds::register('visitor_region', [
            'label' => 'Visitor\'s region',
            'group' => 'who',
            'value' => 'options',
            'options' => fn () => State::query()->where('enabled', true)->orderBy('country')->get()
                ->mapWithKeys(fn (State $state) => [$state->id => $state->t('name').' ('.$state->country.')'])->all(),
            'operators' => [
                'in' => 'is one of',
                'not_in' => 'is not one of',
            ],
            'matches' => fn (array $rule) => self::inList(self::place()?->state_id, $rule),
        ]);

        RuleKinds::register('visitor_country', [
            'label' => 'Visitor\'s country',
            'group' => 'who',
            'value' => 'options',
            'options' => fn () => Country::query()->where('enabled', true)->get()
                ->mapWithKeys(fn (Country $country) => [$country->code => (string) $country->t('name')])->sort()->all(),
            'operators' => [
                'in' => 'is one of',
                'not_in' => 'is not one of',
            ],
            'matches' => fn (array $rule) => self::inList(self::place()?->country, $rule),
        ]);

        RuleKinds::register('visitor_town_chosen', [
            'label' => 'Visitor\'s town is known',
            'group' => 'who',
            'value' => 'none',
            'operators' => [
                'yes' => 'they have chosen a town',
                'no' => 'they have not chosen a town',
            ],
            'matches' => fn (array $rule) => ($rule['operator'] ?? 'yes') === 'no' ? self::place() === null : self::place() !== null,
        ]);
    }

    /**
     * The visitor's town, resolved per rule and per request: the memo is
     * `Places`, a per-request singleton — never a property here, which would
     * outlive the request under a persistent worker.
     */
    protected static function place(): ?Place
    {
        $id = (int) hook_filter('storefront.visitor_place', null);

        return $id ? app(Places::class)->find($id) : null;
    }

    /** As core's own lists: an unknown value is not "one of" them, and is "not one of" them. */
    protected static function inList(mixed $value, array $rule): bool
    {
        $present = $value !== null && in_array((string) $value, array_map('strval', (array) ($rule['value'] ?? [])), true);

        return ($rule['operator'] ?? 'in') === 'not_in' ? ! $present : $present;
    }
}
