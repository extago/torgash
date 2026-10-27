<?php

namespace App\Http\Controllers\Admin;

use App\Core\Currency\FetchRates;
use App\Core\Currency\RateFreshness;
use App\Core\Currency\RateSources;
use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Storefront;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The currencies the shop sells in, and how each is written.
 *
 * A currency a storefront keeps its books in is load-bearing — every stored
 * amount is in it, its rate is 1 by construction — so it can be neither
 * switched off nor deleted from here. `inUse()` is the one answer to "which
 * are those", asked by the list (the trash is drawn dimmed), the toggle and
 * the delete alike.
 */
class CurrenciesController extends Controller
{
    public function index()
    {
        return view('admin.currencies.index', [
            'currencies' => Currency::orderBy('sort')->orderBy('code')->get(),
            'source' => app(RateSources::class)->current(),
            'stale' => app(RateFreshness::class)->stale()->pluck('code')->flip(),
            'inUse' => $this->inUse(),
        ]);
    }

    /**
     * Fetch today's rates now, rather than waiting for the schedule.
     *
     * In the background like every other slow job: a bank endpoint is a network
     * call the admin should not be watching a spinner for, and the result —
     * including every rate refused as too large a jump — belongs in the same
     * task list as everything else.
     */
    public function refresh()
    {
        if (! app(RateSources::class)->current()) {
            return back()->withErrors(__('Pick where rates come from first, in Settings.'));
        }

        $started = FetchRates::startIfIdle();

        return back()->with('status', $started
            ? __('Fetching the rates. Watch it under System → Background tasks.')
            : __('Already running.'));
    }

    /** On or off from the list, answered with the row's own cell. */
    public function status(Request $request, Currency $currency)
    {
        $on = $request->boolean('enabled');

        if (! $on && isset($this->inUse()[$currency->code])) {
            return response(__('A storefront keeps its books in :code — it cannot be switched off.', ['code' => $currency->code]), 422);
        }

        $currency->update(['enabled' => $on]);

        return view('admin.currencies._status', ['currency' => $currency, 'inUse' => $this->inUse()]);
    }

    public function create()
    {
        return $this->form(new Currency(['enabled' => true, 'auto_rate' => true, 'decimals' => 2, 'rate' => 1]));
    }

    public function store(Request $request)
    {
        $currency = Currency::create($this->data($request, new Currency));

        return $this->afterSave($request, $currency, __('Currency created.'));
    }

    public function edit(Currency $currency)
    {
        return $this->form($currency);
    }

    public function update(Request $request, Currency $currency)
    {
        $currency->update($this->data($request, $currency));

        return $this->afterSave($request, $currency, __('Currency updated.'));
    }

    public function destroy(Currency $currency)
    {
        if (isset($this->inUse()[$currency->code])) {
            throw ValidationException::withMessages([
                'code' => __('A storefront keeps its books in :code — it cannot be deleted.', ['code' => $currency->code]),
            ]);
        }

        $currency->delete();

        return redirect()->route('admin.currencies.index')->with('status', __('Currency deleted.'));
    }

    protected function form(Currency $currency)
    {
        return view('admin.currencies.form', [
            'currency' => $currency,
            'source' => app(RateSources::class)->current(),
            'inUse' => $this->inUse(),
        ]);
    }

    /** Saving stays on the form; "Save and close" in the gear goes back to the list. */
    protected function afterSave(Request $request, Currency $currency, string $status)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.currencies.index')
            : redirect()->route('admin.currencies.edit', $currency))
            ->with('status', $status);
    }

    /**
     * The currencies some storefront keeps its books in.
     *
     * @return array<string, true>
     */
    protected function inUse(): array
    {
        return Storefront::query()->pluck('default_currency')->filter()
            ->mapWithKeys(fn ($code) => [strtoupper($code) => true])->all();
    }

    protected function data(Request $request, Currency $currency): array
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:3', Rule::unique('currencies', 'code')->ignore($currency)],
            'symbol' => 'required|string|max:8',
            'name' => 'required|string|max:255',
            'rate' => 'required|numeric|gt:0',
            'decimals' => 'required|integer|min:0|max:4',
            'sort' => 'nullable|integer',
            'rate_key' => 'nullable|string|max:190',
            'symbol_position' => ['required', Rule::in(Currency::SYMBOL_POSITIONS)],
            'decimal_separator' => ['required', Rule::in(['.', ','])],
            'thousands_separator' => ['required', Rule::in(['space', 'none', ',', '.', "'"])],
        ]);

        $code = strtoupper($validated['code']);
        $enabled = $request->boolean('enabled');

        // The books' currency stays on whatever the box said — see inUse().
        if (! $enabled && $currency->exists && isset($this->inUse()[$currency->code])) {
            $enabled = true;
        }

        return [
            'code' => $code,
            'symbol' => $validated['symbol'],
            'name' => $validated['name'],
            'rate' => $validated['rate'],
            'decimals' => (int) $validated['decimals'],
            'sort' => (int) ($validated['sort'] ?? 0),
            'enabled' => $enabled,
            'auto_rate' => $request->boolean('auto_rate'),
            'rate_key' => ($validated['rate_key'] ?? '') ?: null,
            'symbol_position' => $validated['symbol_position'],
            'decimal_separator' => $validated['decimal_separator'],
            // Laravel trims a lone space to nothing, and "no separator" is a
            // real answer ("1299.00") — so the select sends a word for each.
            'thousands_separator' => match ($validated['thousands_separator']) {
                'space' => ' ', 'none' => '', default => $validated['thousands_separator'],
            },
        ];
    }
}
