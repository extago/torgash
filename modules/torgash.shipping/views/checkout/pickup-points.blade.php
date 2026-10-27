{{-- Where the customer will collect the order. Rendered under the selected
     option by the checkout form; the value is read back by
     PickupProvider::snapshot() and frozen onto the order.

     A radio list, not a searchable one: these are the shop's own points in one
     town — a handful, not Kyiv's thousand Nova Poshta branches — and every one
     of them already holds the whole order, since a point that does not is not
     on this list at all.

     The server-rendered `checked` is what makes the form submittable before
     Alpine has run; the binding is what keeps the address block in step after
     it has. --}}
@php
    // One point in town is not a choice — but it still has to be submitted, and
    // the customer still has to be told where they are going.
    $only = count($points) === 1 ? $points[0] : null;
    $selected = $chosen ?? ($only['id'] ?? null);
@endphp

<div x-data="{ chosen: {{ $selected ?? 'null' }} }" class="flex flex-col gap-2">
    <div class="text-xs font-semibold uppercase tracking-wide opacity-50">{{ __('Where to collect') }}</div>

    @foreach ($points as $point)
        <label class="flex items-start gap-3 border border-base-300 rounded-lg px-3 py-2 cursor-pointer
                      has-[:checked]:border-primary has-[:checked]:bg-primary/5">
            <input type="radio" name="shipping_fields[pickup_point]" value="{{ $point['id'] }}"
                   class="radio radio-primary mt-0.5" required
                   x-model.number="chosen" @checked($selected === $point['id'])>
            <span class="text-sm">
                <span class="font-medium block">{{ $point['name'] }}</span>
                @if (! empty($point['address']))
                    <span class="opacity-70 block">{{ $point['address'] }}</span>
                @endif
                @if (! empty($point['phone']))
                    <span class="opacity-70 block">{{ $point['phone'] }}</span>
                @endif
                @if (! empty($point['hours']))
                    <span class="opacity-60 block text-xs mt-1">
                        @foreach ($point['hours'] as $line)
                            <span class="inline-block mr-3">{{ $line['days'] }} {{ $line['time'] }}</span>
                        @endforeach
                    </span>
                @endif
            </span>
        </label>
    @endforeach
</div>
