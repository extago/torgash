<?php

namespace App\Http\Controllers\Account;

use App\Core\Modules\ModuleManager;
use App\Core\Support\Phones;
use App\Core\Verification\Channels;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function index(Channels $channels)
    {
        return view('account.dashboard', [
            'user' => auth()->user()->load('customerGroup'),
            'phones' => $channels->offers('phone'),
        ]);
    }

    /**
     * The name only. The email and the phone are changed through a code sent
     * to the new one (ContactController) — a box that rewrote the email on
     * Save let anybody at an unlocked screen take the account over.
     */
    public function updateProfile(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:190',
        ]);

        $request->user()->update($validated);

        return back()->with('status', __('Profile updated.'));
    }

    /**
     * The number to ring this customer on. No code, unlike the phone that
     * signs in: it opens nothing, so there is nothing to prove, and a shop
     * with no SMS provider can keep one too. Emptied, it is forgotten.
     */
    public function updateContactPhone(Request $request)
    {
        $typed = $request->validate(['contact_phone' => 'nullable|string|max:40'])['contact_phone'] ?? null;
        $phone = Phones::parse($typed);

        if (filled($typed) && ! $phone) {
            throw ValidationException::withMessages(['contact_phone' => __('This does not look like a phone number. Type it with the country code, like :example.', ['example' => Phones::callingCode(Phones::defaultCountry()).' …'])]);
        }

        $request->user()->forceFill(['contact_phone' => $phone['e164'] ?? null])->save();

        return back()->with('status', $phone ? __('Phone saved.') : __('Phone removed.'));
    }

    public function updatePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $request->user()->update(['password' => $validated['password']]);

        return back()->with('status', __('Password updated.'));
    }

    public function orders(ModuleManager $modules)
    {
        $orders = collect();

        // torgash.checkout is a module (soft dependency, not a hard require) —
        // the account area still needs to render if it's ever disabled.
        if ($modules->isEnabled('torgash.checkout')) {
            $orders = \Torgash\Checkout\Models\Order::where('user_id', auth()->id())
                ->latest()
                ->get();
        }

        // Each order shows its products' pictures: every product of every
        // order in ONE query, not one per line.
        $pictures = [];
        $productIds = $orders->flatMap(fn ($order) => collect($order->items ?? [])->pluck('product_id'))->filter()->unique()->values();

        if ($productIds->isNotEmpty() && $modules->isEnabled('torgash.catalog')) {
            $pictures = \Torgash\Catalog\Models\Product::query()->whereIn('id', $productIds)->with('media')->get()
                ->mapWithKeys(fn ($product) => [$product->id => $product->primaryImageUrl(160)])->filter()->all();
        }

        return view('account.orders', ['orders' => $orders, 'pictures' => $pictures]);
    }
}
