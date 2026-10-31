<?php

namespace Torgash\Locations;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Torgash\Locations\Models\Place;

/**
 * "Popular towns": a storefront's own short list, offered as one-click
 * buttons in the "your town" window and under the checkout's town field.
 *
 * Kept ONLY per storefront (a Dnipro storefront offers Dnipro and its
 * neighbours, a Kyiv one Kyiv's), so the keys are not declared in the
 * manifest — the module's settings screen is shop-wide and would offer a
 * value no storefront reads. Written from the storefront's own form
 * (`core.storefront.form.sections` / `core.storefront.saved`), as ids
 * typed with commas: the admin finds a town's id in Locations → Places.
 */
class PopularPlaces
{
    public const MODULE = 'torgash.locations';

    public const PLACES = ['dialog' => 'popular_in_dialog', 'checkout' => 'popular_at_checkout'];

    /** @return list<int> the ids in the order typed, each once */
    public static function parse(string $typed): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', preg_split('/[\s,;]+/', $typed) ?: []),
            fn (int $id) => $id > 0,
        )));
    }

    /** @return list<int> */
    public function ids(int $storefrontId): array
    {
        return array_map('intval', (array) (setting(self::MODULE, 'popular_places', [], $storefrontId) ?: []));
    }

    /**
     * The towns to offer at `$where` ('dialog' / 'checkout') on the current
     * storefront — none when it keeps no list or does not show it there;
     * a town since switched off is left out, the order is the admin's.
     *
     * @return Collection<int, Place>
     */
    public function offered(string $where): Collection
    {
        $storefrontId = current_storefront()?->id;

        if ($storefrontId === null || ! (bool) setting(self::MODULE, self::PLACES[$where] ?? '', false, $storefrontId)) {
            return collect();
        }

        return $this->places($this->ids($storefrontId), available: true);
    }

    /** @return Collection<int, Place> in the order of `$ids` */
    public function places(array $ids, bool $available = false): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $found = Place::query()->with('state')->whereIn('id', $ids)
            ->when($available, fn ($query) => $query->available())
            ->get()->keyBy('id');

        return collect($ids)->map(fn (int $id) => $found->get($id))->filter()->values();
    }

    /** The storefront form's section. */
    public function section(array $context): string
    {
        $storefront = $context['storefront'] ?? null;
        $id = $storefront?->exists ? (int) $storefront->id : null;
        $ids = $id ? $this->ids($id) : [];

        return view('torgash.locations::admin.storefront-popular', [
            'popularTyped' => old('popular_places', implode(', ', $ids)),
            'popularPlaces' => $this->places($ids),
            'popularShown' => collect(self::PLACES)->map(fn (string $key) => $id ? (bool) setting(self::MODULE, $key, false, $id) : false)->all(),
        ])->render();
    }

    /** @return array<string, mixed> validation rules for the section's fields */
    public function rules(): array
    {
        return [
            'popular_places' => ['nullable', 'string', 'max:2000', function (string $attribute, $value, $fail) {
                $ids = self::parse((string) $value);
                $missing = array_diff($ids, Place::query()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all());
                if ($missing !== []) {
                    $fail(__('No town has the id :ids.', ['ids' => implode(', ', $missing)]));
                }
            }],
            ...collect(self::PLACES)->mapWithKeys(fn (string $key) => [$key => 'nullable|boolean'])->all(),
        ];
    }

    /** Writes the section — only when the form carried it, or a save from elsewhere would wipe the list. */
    public function save(Request $request, int $storefrontId): void
    {
        if (! $request->has('popular_section')) {
            return;
        }

        $settings = app(\App\Core\Settings\SettingsManager::class);
        $ids = self::parse((string) $request->input('popular_places', ''));

        $ids === []
            ? $settings->forget(self::MODULE, 'popular_places', $storefrontId)
            : $settings->set(self::MODULE, 'popular_places', $ids, $storefrontId);

        foreach (self::PLACES as $key) {
            $request->boolean($key)
                ? $settings->set(self::MODULE, $key, true, $storefrontId)
                : $settings->forget(self::MODULE, $key, $storefrontId);
        }
    }
}
