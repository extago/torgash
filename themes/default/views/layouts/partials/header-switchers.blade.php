{{-- The language and currency pickers of BOTH headers — one markup, the
     trigger's look given by the caller ($switcherTrigger). Popovers (core's
     component), like every small menu that opens from a button.

     The currency is a POST form per row: a GET switcher gets followed by the
     browser's link prefetcher on its own. Each form IS the menu item, so
     `p-0 grid-cols-1` — daisyUI lays an item out as a grid sized to its
     content, and without it only the word took the click. --}}
@if ($languages->count() > 1 && theme_setting('show_language', true))
    <x-popover id="language-menu" :class="$switcherTrigger" panel-class="p-2 w-44" :label="__('Language')">
        <ul class="menu menu-sm w-full p-0">
            @foreach ($languages as $lang)
                <li>
                    <a href="{{ $localeLinks[$lang->code] }}" @class(['font-semibold text-primary' => app()->getLocale() === $lang->code])>
                        {{ $lang->native_name }}
                    </a>
                </li>
            @endforeach
        </ul>

        <x-slot:trigger>
            {{ strtoupper(app()->getLocale()) }}
            <x-icon name="chevron-down" size="sm" class="opacity-60" />
        </x-slot:trigger>
    </x-popover>
@endif

@if ($currencies->count() > 1 && theme_setting('show_currency', true))
    <x-popover id="currency-menu" :class="$switcherTrigger" panel-class="p-2 w-44" :label="__('Currency')">
        <ul class="menu menu-sm w-full p-0">
            @foreach ($currencies as $currency)
                <li>
                    <form method="POST" action="{{ route('currency.set') }}" class="p-0 grid-cols-1">
                        @csrf
                        <input type="hidden" name="currency" value="{{ $currency->code }}">
                        <button type="submit" @class(['w-full text-left px-3 py-1.5', 'font-semibold text-primary' => $activeCurrency === $currency->code])>
                            {{ $currency->code }} ({{ $currency->symbol }})
                        </button>
                    </form>
                </li>
            @endforeach
        </ul>

        <x-slot:trigger>
            {{ $activeCurrency }}
            <x-icon name="chevron-down" size="sm" class="opacity-60" />
        </x-slot:trigger>
    </x-popover>
@endif
