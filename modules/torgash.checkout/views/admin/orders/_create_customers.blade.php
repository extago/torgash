{{-- The accounts a typed word matches, under the box. Picking one hands its
     contacts and its default address to the form (Alpine `pick` on the
     page); the list is emptied by the same hand. --}}
@if ($needle !== '')
    <div class="absolute inset-x-0 top-full z-30 mt-1 border border-base-300 rounded-box bg-base-100 shadow-lg divide-y divide-base-300 max-h-72 overflow-y-auto">
        @forelse ($customers as $customer)
            @php
                $address = $customer->addresses->first();
                $picked = [
                    'id' => $customer->id,
                    'name' => $address?->full_name ?: $customer->name,
                    'fields' => [
                        'email' => $customer->email,
                        'phone' => $customer->phone ?: $address?->phone,
                        'address_line' => $address?->address_line,
                        'city' => $address?->city,
                        'postal_code' => $address?->postal_code,
                    ],
                ];
            @endphp
            <button type="button" class="w-full text-left flex items-center justify-between gap-3 px-3 py-2 hover:bg-base-200"
                    x-on:click="pick(@js($picked))">
                <span class="min-w-0">
                    <span class="block truncate">{{ $customer->name }}</span>
                    <span class="block text-xs opacity-60 truncate">{{ $customer->email }}@if ($customer->phone) · {{ $customer->phone }}@endif</span>
                </span>
                @if ($address)
                    <span class="text-xs opacity-50 truncate max-w-48">{{ $address->city }}, {{ $address->address_line }}</span>
                @endif
            </button>
        @empty
            <div class="px-3 py-3 text-sm opacity-50">{{ __('No account matches — the order will be a guest\'s.') }}</div>
        @endforelse
    </div>
@endif
