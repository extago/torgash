{{-- Auto-generated content_type="payment_methods" / "shipping_methods".
     A method's note is core's hint (<x-popover hover>) behind an ⓘ on a wide
     screen, and a line of its own on a phone and a tablet — read there
     without a tap.

     Logos (the method's own `logo`, uploaded on its admin form) are what a
     buyer recognises before reading — a card scheme, a carrier's colours. Once
     ANY method has one, every row gets the same slot, a method without one
     standing in with a quiet icon, so the names stay in one column; with none
     at all the list stays the plain dashes it always was. --}}
@php $methodsWithLogos = $methods->contains(fn ($method) => ! empty($method['logo'])); @endphp
<ul @class(['flex flex-col', 'gap-1' => ! $methodsWithLogos, 'gap-1.5' => $methodsWithLogos])>
    @foreach ($methods as $method)
        <li class="flex flex-wrap items-center gap-x-1.5">
            @if ($methodsWithLogos)
                <span class="torgash-method-logo" aria-hidden="true">
                    @if (! empty($method['logo']))
                        <img src="{{ $method['logo'] }}" alt="" loading="lazy" decoding="async">
                    @else
                        <x-icon :name="$methodIcon ?? 'circle'" size="sm" class="opacity-40" />
                    @endif
                </span>
                <span class="ms-1">{{ $method['name'] }}</span>
            @else
                <span>— {{ $method['name'] }}</span>
            @endif
            @if ($method['note'])
                <x-popover hover placement="top" :label="__('More about :method', ['method' => $method['name']])"
                           class="max-lg:hidden inline-flex opacity-40 hover:opacity-100">
                    {{ $method['note'] }}
                    <x-slot:trigger><x-icon name="info" size="sm" /></x-slot:trigger>
                </x-popover>
                <span @class(['lg:hidden basis-full text-xs opacity-70', 'pl-3.5' => ! $methodsWithLogos, 'pl-13' => $methodsWithLogos])>{{ $method['note'] }}</span>
            @endif
        </li>
    @endforeach
</ul>
