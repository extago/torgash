<?php

namespace Torgash\Cdek\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;
use Torgash\Cdek\Models\Waybill;
use Torgash\Cdek\Waybills\Waybills;
use Torgash\Checkout\Models\Order;

/**
 * The CDEK card on the order screen: make the waybill, see its number and
 * status, print it, withdraw it. Every action answers with the card again
 * (HTMX swaps it in place); a refusal is shown inside it.
 */
class WaybillsController extends Controller
{
    public function store(Request $request, Order $order, Waybills $waybills)
    {
        $input = $request->validate([
            'weight_g' => 'required|integer|min:1|max:1000000',
            'length_cm' => 'required|integer|min:1|max:1000',
            'width_cm' => 'required|integer|min:1|max:1000',
            'height_cm' => 'required|integer|min:1|max:1000',
            'cod' => 'nullable|boolean',
            'comment' => 'nullable|string|max:255',
        ]);

        try {
            $waybills->create($order, $input, $request->user()?->id);
        } catch (ValidationException $e) {
            return $this->card($order, (string) collect($e->errors())->flatten()->first(), $input);
        }

        return $this->card($order);
    }

    public function refresh(Order $order, Waybill $waybill, Waybills $waybills)
    {
        abort_unless($waybill->order_id === $order->id, 404);

        $waybills->refresh($waybill);

        return $this->card($order);
    }

    public function cancel(Order $order, Waybill $waybill, Waybills $waybills)
    {
        abort_unless($waybill->order_id === $order->id, 404);

        try {
            $waybills->cancel($waybill);
        } catch (ValidationException $e) {
            return $this->card($order, (string) collect($e->errors())->flatten()->first());
        }

        return $this->card($order);
    }

    /**
     * The PDF itself, opened in a new tab. A POST because asking for it makes
     * CDEK draw a form — a GET link would be followed by the browser's
     * prefetcher on its own.
     */
    public function print(Order $order, Waybill $waybill, string $kind, Waybills $waybills)
    {
        abort_unless($waybill->order_id === $order->id && in_array($kind, ['waybill', 'label'], true), 404);

        if (! $waybill->isPrintable()) {
            $waybills->refresh($waybill);
        }

        if (! $waybill->isPrintable()) {
            return response(__('CDEK has not given this waybill a number yet — refresh it in a minute.'), 409)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        try {
            $pdf = $waybills->pdf($waybill, $kind);
        } catch (Throwable $e) {
            report($e);

            return response(__('CDEK could not print it: :error', ['error' => $e->getMessage()]), 502)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        $name = ($kind === 'label' ? 'cdek-label-' : 'cdek-waybill-').$waybill->cdek_number.'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'"',
        ]);
    }

    /** @param  array<string, mixed>  $old */
    protected function card(Order $order, ?string $error = null, array $old = [])
    {
        return view('torgash.cdek::admin.waybill-panel', self::panelData($order) + [
            'waybillError' => $error,
            'waybillOld' => $old,
        ]);
    }

    /** @return array<string, mixed> */
    public static function panelData(Order $order): array
    {
        $history = Waybill::where('order_id', $order->id)->latest('id')->get();

        return [
            'order' => $order,
            'waybill' => $history->first(fn (Waybill $waybill) => in_array($waybill->state, [Waybill::REGISTERING, Waybill::ACTIVE], true)),
            'history' => $history,
            'draft' => app(Waybills::class)->draft($order),
        ];
    }
}
