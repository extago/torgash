<?php

namespace Torgash\Locations\Content;

use App\Core\Content\Placeholders;
use App\Core\Support\GrammaticalCases;
use Torgash\Locations\Models\Place;
use Torgash\Locations\Places;

/**
 * What a template may say about the town a page is about.
 *
 * Registered HERE and not in the catalogue, although it is the catalogue that
 * publishes a page per city: the words belong to whoever owns them, and the
 * declined forms of "Киев" are this module's data. The page hands over a
 * `place_id` and nothing else, which is also all a warehouse or a zone rule
 * ever stores — so nothing in the catalogue names this module's classes, and a
 * shop with no place registry simply has tokens that resolve to nothing.
 *
 * `{location}` is the nominative and `{location:6}` a case by number, using the
 * ARGUMENT syntax rather than a token per case (some engines ship
 * `[location_form_1]`…`[location_form_7]`, which is seven rows in the picker
 * saying the same thing). The numbers are the ones in the language's own list
 * of cases (core's GrammaticalCases, typed on the language form); a template
 * is written per language, so "6" is read in the cases of that language, and
 * the picker lists every declining language's cases by name.
 *
 * A case nobody's language has resolves to the nominative rather than to
 * nothing: a title is better slightly wrong than half missing, and English has
 * exactly one form.
 */
class LocationPlaceholders
{
    public function register(Placeholders $placeholders): void
    {
        $group = 'page';

        $placeholders->register('location', [
            'label' => 'Town, in a given case — the number from the language\'s own list of cases',
            'group' => $group,
            'argument' => [
                'label' => 'case',
                'example' => '6',
                // Each declining language's cases, from its own settings, so
                // the picker names them in words and a language added
                // tomorrow is offered the day its cases are typed in.
                'choices' => fn () => collect(app(GrammaticalCases::class)->all())
                    ->flatMap(fn (array $cases, string $code) => collect($cases)->map(fn (array $case, int $number) => [
                        'value' => (string) $number,
                        'label' => $code.' · '.$number.' — '.app(GrammaticalCases::class)->label($code, $number),
                    ]))
                    ->values()->all(),
            ],
            'example' => '{location:6}',
            'resolve' => fn (array $context = [], ?string $case = null) => $this->place($context)
                ?->form(max(1, (int) $case ?: 1)) ?? '',
        ]);

        $placeholders->register('location_region', [
            'label' => 'The town\'s region',
            'group' => $group,
            'resolve' => fn (array $context = []) => $this->place($context)?->state?->t('name') ?? '',
        ]);
    }

    /**
     * The town this page is about, from whichever end the caller has it.
     *
     * A record that carries a place hands over its id — see
     * `FilterPage::pageMetaContext()` — and the generator, which is naming a
     * page it has not saved yet, hands over the row itself.
     *
     * The memo is RESOLVED here rather than held on this object: these
     * closures are registered once at boot and outlive the request, so a
     * property on them would be a static wearing a different hat — the rule
     * every per-request memo in this codebase is written to.
     *
     * @param  array<string, mixed>  $context
     */
    protected function place(array $context): ?Place
    {
        if (($place = $context['place'] ?? null) instanceof Place) {
            return $place;
        }

        if (! $id = (int) ($context['place_id'] ?? 0)) {
            return null;
        }

        return app(Places::class)->find($id);
    }
}
