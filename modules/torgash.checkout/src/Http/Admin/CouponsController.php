<?php

namespace Torgash\Checkout\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Torgash\Checkout\Models\Coupon;

class CouponsController extends Controller
{
    public function index(Request $request)
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'state' => in_array($request->query('state'), Coupon::STATES, true) ? $request->query('state') : '',
        ];

        // The state is computed (dates, the counter, the switch), so the list
        // is read whole and narrowed here — a shop's coupons are dozens, not
        // thousands — which is also what lets every tab count the searched set.
        $found = Coupon::orderBy('code')->get()
            ->when($filters['q'] !== '', fn ($all) => $all->filter(fn (Coupon $coupon) => mb_stripos($coupon->code, $filters['q']) !== false));

        $stateCounts = ['' => $found->count()] + array_fill_keys(Coupon::STATES, 0);
        foreach ($found as $coupon) {
            $stateCounts[$coupon->state()]++;
        }

        return view('torgash.checkout::admin.coupons.index', [
            'coupons' => $filters['state'] === ''
                ? $found->values()
                : $found->filter(fn (Coupon $coupon) => $coupon->state() === $filters['state'])->values(),
            'filters' => $filters,
            'stateCounts' => $stateCounts,
        ]);
    }

    public function create()
    {
        return view('torgash.checkout::admin.coupons.form', ['coupon' => new Coupon(['type' => 'percent', 'enabled' => true])]);
    }

    public function store(Request $request)
    {
        $coupon = Coupon::create($this->data($request));

        return $this->afterSave($request, $coupon, __('Coupon created.'));
    }

    public function edit(Coupon $coupon)
    {
        return view('torgash.checkout::admin.coupons.form', ['coupon' => $coupon]);
    }

    public function update(Request $request, Coupon $coupon)
    {
        $coupon->update($this->data($request, $coupon));

        return $this->afterSave($request, $coupon, __('Coupon saved.'));
    }

    /** Back to the form, as every edit form here does; "Save and close" is the way out. */
    protected function afterSave(Request $request, Coupon $coupon, string $message)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.checkout.coupons.index')
            : redirect()->route('admin.checkout.coupons.edit', $coupon))->with('status', $message);
    }

    /**
     * Deleting a coupon rewrites no order: an order keeps the code it was
     * given and the discount it got, as frozen at placement.
     */
    public function destroy(Coupon $coupon)
    {
        $coupon->delete();

        return redirect()->route('admin.checkout.coupons.index')->with('status', __('Coupon deleted.'));
    }

    /** The list's in-place switch: answers with the state cell. */
    public function setStatus(Request $request, Coupon $coupon)
    {
        $request->validate(['enabled' => 'required|boolean']);
        $coupon->update(['enabled' => $request->boolean('enabled')]);

        return view('torgash.checkout::admin.coupons._status', ['coupon' => $coupon]);
    }

    /** Several at once, through the models so the audit hears each one. */
    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['enable', 'disable', 'delete'])],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $coupons = Coupon::whereIn('id', $validated['ids'])->get();

        if ($validated['action'] === 'delete') {
            $coupons->each->delete();

            return back()->with('status', trans_choice(':count coupon deleted.|:count coupons deleted.', $coupons->count(), ['count' => $coupons->count()]));
        }

        $coupons->each(fn (Coupon $coupon) => $coupon->update(['enabled' => $validated['action'] === 'enable']));

        return back()->with('status', trans_choice(':count coupon updated.|:count coupons updated.', $coupons->count(), ['count' => $coupons->count()]));
    }

    /**
     * A code nobody can guess and nobody misreads on a printed card: no 0/O,
     * no 1/I/L. The form asks for one when the shop has no word of its own.
     */
    public static function generateCode(int $length = 8): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        do {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (Coupon::where('code', $code)->exists());

        return $code;
    }

    protected function data(Request $request, ?Coupon $coupon = null): array
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('m_checkout_coupons', 'code')->ignore($coupon?->id)],
            'type' => 'required|in:percent,fixed',
            'value' => 'required|numeric|min:0',
            'min_subtotal' => 'nullable|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after_or_equal:starts_at',
        ], [
            'code.regex' => __('Latin letters, digits, dashes and underscores — a customer types it.'),
        ]);

        return [
            'code' => Str::upper($validated['code']),
            'type' => $validated['type'],
            'value' => $validated['type'] === 'percent'
                ? min(100, (int) $validated['value'])
                : (int) round($validated['value'] * 100),
            'min_subtotal' => isset($validated['min_subtotal']) ? (int) round($validated['min_subtotal'] * 100) : null,
            'usage_limit' => $validated['usage_limit'] ?? null,
            'starts_at' => $validated['starts_at'] ?? null,
            // A date that is the LAST day it works: through to its end, not its
            // first second — "until the 31st" means the 31st counts.
            'expires_at' => isset($validated['expires_at']) ? \Illuminate\Support\Carbon::parse($validated['expires_at'])->endOfDay() : null,
            'enabled' => $request->boolean('enabled'),
        ];
    }
}
