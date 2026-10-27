<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Address;
use Illuminate\Http\Request;

class AddressesController extends Controller
{
    public function index()
    {
        return view('account.addresses.index', ['addresses' => auth()->user()->addresses]);
    }

    public function create()
    {
        return view('account.addresses.form', ['address' => new Address]);
    }

    public function store(Request $request)
    {
        $address = auth()->user()->addresses()->create($this->data($request));
        $this->syncDefault($address);

        return redirect()->route('account.addresses.index')->with('status', __('Address added.'));
    }

    public function edit(Address $address)
    {
        $this->authorizeOwner($address);

        return view('account.addresses.form', ['address' => $address]);
    }

    public function update(Request $request, Address $address)
    {
        $this->authorizeOwner($address);

        $address->update($this->data($request));
        $this->syncDefault($address);

        return redirect()->route('account.addresses.index')->with('status', __('Address updated.'));
    }

    public function destroy(Address $address)
    {
        $this->authorizeOwner($address);
        $address->delete();

        return back()->with('status', __('Address removed.'));
    }

    public function makeDefault(Address $address)
    {
        $this->authorizeOwner($address);
        $this->syncDefault($address, force: true);

        return back()->with('status', __('Default address updated.'));
    }

    protected function authorizeOwner(Address $address): void
    {
        abort_unless($address->user_id === auth()->id(), 404);
    }

    protected function data(Request $request): array
    {
        return $request->validate([
            'label' => 'nullable|string|max:60',
            'full_name' => 'required|string|max:190',
            'phone' => 'nullable|string|max:40',
            'address_line' => 'required|string|max:255',
            'city' => 'required|string|max:120',
            'postal_code' => 'nullable|string|max:20',
        ]) + ['is_default' => $request->boolean('is_default')];
    }

    /** Exactly one default address per user. */
    protected function syncDefault(Address $address, bool $force = false): void
    {
        if (! $force && ! $address->is_default) {
            // First address a user ever adds becomes the default automatically.
            if (auth()->user()->addresses()->count() > 1) {
                return;
            }
        }

        auth()->user()->addresses()->where('id', '!=', $address->id)->update(['is_default' => false]);
        $address->update(['is_default' => true]);
    }
}
