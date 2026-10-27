<?php

namespace Torgash\Shipping\Http\Admin;

use App\Http\Controllers\Controller;
use App\Models\Language;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Shipping\Money;
use Torgash\Shipping\Models\ShippingRate;
use Torgash\Shipping\Models\ShippingRateRow;
use Torgash\Shipping\Models\Zone;

/**
 * One rate — what a method costs in one zone — edited in place on the method
 * screen. Every action here answers with ONE table row: the row as it reads,
 * or the row expanded into its editor.
 *
 * Why a controller of its own rather than fields in the method form: a rate
 * carries repeatable bands and saves independently, and its editor is a
 * <form> — which may not be nested inside the method's form. So the rates tab
 * lives beside that form and each row talks to these endpoints over HTMX. The
 * merchant still never leaves the method, which is the point: "where do I
 * deliver by courier, and for how much" is one screen, not a sub-section.
 *
 * The pair (method, zone) is the identity — the table has a unique index on it
 * — so there is no create/update split: save() upserts, destroy() takes the row
 * back to "not offered here".
 */
class ShippingRatesController extends Controller
{
    /** The row as it reads — what "Cancel" asks for. */
    public function show(Request $request, ShippingMethod $shippingMethod, Zone $zone)
    {
        return $request->hasHeader('HX-Request')
            ? $this->row($shippingMethod, $zone)
            : $this->backToMethod($shippingMethod);
    }

    public function edit(Request $request, ShippingMethod $shippingMethod, Zone $zone)
    {
        if (! $request->hasHeader('HX-Request')) {
            return $this->backToMethod($shippingMethod);
        }

        $rate = $this->rate($shippingMethod, $zone);

        return $this->editor($shippingMethod, $zone, $rate !== null, $this->formValues($rate), $this->rowsForForm($rate));
    }

    public function save(Request $request, ShippingMethod $shippingMethod, Zone $zone)
    {
        $validator = Validator::make($request->all(), [
            'base_rate' => 'required|numeric|min:0',
            'free_over' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3|exists:currencies,code',
            'delivery_time' => 'nullable|array',
            'delivery_time.*' => 'nullable|string|max:60',
        ]);

        if ($validator->fails()) {
            // A swap has no redirect to carry old input back on, so the answer
            // is the editor the admin is looking at, refilled from the request.
            return $this->editor(
                $shippingMethod,
                $zone,
                $this->rate($shippingMethod, $zone) !== null,
                $this->submittedValues($request),
                $this->rowsFromRequest($request),
                $validator->errors()->first(),
            );
        }

        $rate = ShippingRate::firstOrNew([
            'shipping_method_id' => $shippingMethod->id,
            'zone_id' => $zone->id,
        ]);

        $rate->fill($this->data($request, $validator->validated()))->save();

        $this->syncRows($request, $rate);

        return $this->row($shippingMethod, $zone);
    }

    public function destroy(Request $request, ShippingMethod $shippingMethod, Zone $zone)
    {
        $this->rate($shippingMethod, $zone)?->delete();

        return $request->hasHeader('HX-Request')
            ? $this->row($shippingMethod, $zone)
            : $this->backToMethod($shippingMethod);
    }

    protected function rate(ShippingMethod $method, Zone $zone): ?ShippingRate
    {
        return $method->rates()->with('rows')->where('zone_id', $zone->id)->first();
    }

    protected function row(ShippingMethod $method, Zone $zone)
    {
        return view('torgash.shipping::admin.shipping_rates.partials.row', [
            'method' => $method,
            'zone' => $zone,
            'rate' => $this->rate($method, $zone),
        ]);
    }

    /**
     * @param  array<string, mixed>  $form   display values, in the units a human types
     * @param  list<array<string, mixed>>  $rows
     */
    protected function editor(
        ShippingMethod $method,
        Zone $zone,
        bool $exists,
        array $form,
        array $rows,
        ?string $error = null,
    ) {
        return view('torgash.shipping::admin.shipping_rates.partials.editor', [
            'method' => $method,
            'zone' => $zone,
            'exists' => $exists,
            'form' => $form,
            'rows' => $rows,
            'languages' => $this->languages((array) ($form['delivery_time'] ?? [])),
            'error' => $error,
        ]);
    }

    /** Someone followed a fragment URL by hand (or without JS) — send them to the screen it belongs to. */
    protected function backToMethod(ShippingMethod $method)
    {
        return redirect()->route('admin.shipping.methods.edit', [$method, 'tab' => 'rates']);
    }

    /**
     * The editor speaks major units (money) and the table stores minor ones, so
     * both directions live next to each other and cannot drift apart.
     *
     * @return array<string, mixed>
     */
    protected function formValues(?ShippingRate $rate): array
    {
        $currency = $rate?->currency;

        return [
            'currency' => $currency ?? '',
            'base_rate' => $rate ? Money::toMajor($rate->base_rate, $currency) : '0',
            'free_over' => $rate && $rate->free_over !== null ? Money::toMajor($rate->free_over, $currency) : '',
            'delivery_time' => $rate->delivery_time ?? [],
            'enabled' => $rate?->enabled ?? true,
        ];
    }

    /** @return array<string, mixed> */
    protected function submittedValues(Request $request): array
    {
        return [
            'currency' => (string) $request->input('currency', ''),
            'base_rate' => (string) $request->input('base_rate', ''),
            'free_over' => (string) $request->input('free_over', ''),
            'delivery_time' => array_filter((array) $request->input('delivery_time', []), 'is_string'),
            'enabled' => $request->boolean('enabled'),
        ];
    }

    /** @param  array<string, mixed>  $validated */
    protected function data(Request $request, array $validated): array
    {
        $deliveryTime = array_filter(
            array_map('trim', $validated['delivery_time'] ?? []),
            fn (string $value) => $value !== '',
        );

        // Empty means the shop's base currency, which is what a rate has always
        // been — stored as NULL rather than as the base code, so changing which
        // currency is base never rewrites a rate.
        $currency = ($validated['currency'] ?? '') ?: null;

        return [
            'currency' => $currency,
            // Minor units OF THAT CURRENCY, with its own decimals: a shop that
            // shows whole hryvnia stores whole hryvnia here too.
            'base_rate' => Money::toMinor($validated['base_rate'], $currency),
            'free_over' => isset($validated['free_over']) && $validated['free_over'] !== null
                ? Money::toMinor($validated['free_over'], $currency)
                : null,
            'delivery_time' => $deliveryTime ?: null,
            'enabled' => $request->boolean('enabled'),
        ];
    }

    /**
     * Bands are replaced wholesale, like zone rules: a handful of rows carrying
     * no state of their own, where a diff costs more code than it saves.
     */
    protected function syncRows(Request $request, ShippingRate $rate): void
    {
        $rate->rows()->delete();

        foreach ($this->rowsFromRequest($request) as $row) {
            $axis = $row['axis'];
            $isPercent = $row['value_type'] === 'percent';

            $rate->rows()->create([
                'axis' => $axis,
                'range_from' => $this->toStorage($axis, $row['range_from']),
                // An empty upper bound means "and everything above", which is
                // how the last band of any tariff table is written.
                'range_to' => $row['range_to'] === '' || $row['range_to'] === null
                    ? null
                    : $this->toStorage($axis, $row['range_to']),
                'value_type' => $isPercent ? 'percent' : 'fixed',
                // A fixed band charges money, so it is in the rate's currency
                // like its base rate; a percentage is a number and is not.
                'value_amount' => $isPercent ? null : Money::toMinor($row['value'], $rate->currency),
                'value_percent' => $isPercent
                    ? (float) str_replace(',', '.', (string) $row['value'])
                    : null,
                // Per unit means per kilogram or per item; on the order-total
                // axis there is no unit to multiply by, so it is not stored.
                'per_unit' => in_array($axis, ShippingRateRow::PER_UNIT_AXES, true) && ! empty($row['per_unit']),
            ]);
        }
    }

    /** @return list<array<string, mixed>> submitted bands, in the shape the repeatable rows expect */
    protected function rowsFromRequest(Request $request): array
    {
        $rows = [];

        foreach ((array) $request->input('rows', []) as $row) {
            $axis = $row['axis'] ?? '';

            if (! isset(ShippingRateRow::AXES[$axis])) {
                continue;
            }

            $rows[] = [
                'axis' => $axis,
                'range_from' => $row['range_from'] ?? 0,
                'range_to' => $row['range_to'] ?? '',
                'value_type' => ($row['value_type'] ?? 'fixed') === 'percent' ? 'percent' : 'fixed',
                'value' => $row['value'] ?? 0,
                'per_unit' => ! empty($row['per_unit']),
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> stored bands, in the same shape */
    protected function rowsForForm(?ShippingRate $rate): array
    {
        if (! $rate) {
            return [];
        }

        return $rate->rows
            ->sortBy(fn (ShippingRateRow $row) => [array_search($row->axis, array_keys(ShippingRateRow::AXES)), $row->range_from])
            ->map(fn (ShippingRateRow $row) => [
                'axis' => $row->axis,
                'range_from' => $this->toForm($row->axis, $row->range_from),
                'range_to' => $row->range_to === null ? '' : $this->toForm($row->axis, $row->range_to),
                'value_type' => $row->value_type,
                'value' => $row->value_type === 'percent'
                    ? rtrim(rtrim((string) $row->value_percent, '0'), '.')
                    : Money::toMajor((int) $row->value_amount, $rate->currency),
                'per_unit' => $row->per_unit,
            ])
            ->values()
            ->all();
    }

    /**
     * The form speaks the units a human types — money in major units, weight in
     * whatever the shop's unit setting says — and the table stores grams and
     * minor units. Both directions live next to each other so a band typed in
     * pounds cannot come back as kilograms.
     */
    protected function toStorage(string $axis, mixed $value, ?string $currency = null): int
    {
        $number = (float) str_replace(',', '.', (string) $value);

        return match ($axis) {
            // The order-total axis is money in the SHOP's currency: it is
            // compared against the cart, not charged, so it stays base units
            // whatever currency the rate is priced in.
            'C' => (int) round($number * 100),
            'W' => units()->weightToStorage($value) ?? 0,
            default => (int) round($number),
        };
    }

    protected function toForm(string $axis, int $value): string
    {
        return match ($axis) {
            'C' => $this->money($value),
            'W' => units()->weightToForm($value),
            default => (string) $value,
        };
    }

    protected function money(int $minor): string
    {
        return rtrim(rtrim(number_format($minor / 100, 2, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * @param  array<string, string>  $deliveryTime  so a locale that was
     *     switched off after the text was written still shows its text back
     * @return array<string, string>
     */
    protected function languages(array $deliveryTime): array
    {
        $languages = Language::where('enabled', true)->orderBy('sort')->get()
            ->mapWithKeys(fn (Language $language) => [$language->code => $language->native_name ?: $language->name])
            ->all();

        foreach (array_keys($deliveryTime) as $code) {
            $languages[$code] ??= strtoupper((string) $code);
        }

        return $languages;
    }
}
