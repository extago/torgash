{{--
    Payment marks in a row of small plates — Visa, Mastercard, Apple Pay…
    One per line: a known mark (visa, mastercard, applepay, googlepay,
    samsungpay, paypal, stripe, klarna, alipay, americanexpress), or a mark of
    the shop's own as "Name | /storage/…/logo.svg" — Mir, SBP and local banks
    are not in Simple Icons and come this way.
--}}
@php
    $paymentsRows = [];

    foreach (preg_split('/\r\n|\r|\n/', (string) ($settings['items'] ?? '')) as $paymentsLine) {
        $paymentsParts = array_map('trim', explode('|', trim($paymentsLine)));
        $paymentsKey = strtolower($paymentsParts[0] ?? '');

        if ($paymentsKey === '') {
            continue;
        }

        $paymentsImage = $paymentsParts[1] ?? '';

        if ($paymentsImage !== '' && preg_match('#^(https?://|/(?!/))#i', $paymentsImage)) {
            $paymentsRows[] = ['name' => $paymentsParts[0], 'image' => $paymentsImage, 'svg' => null];
        } elseif ($paymentsSvg = \Torgash\Blocks\BrandIcons::svg($paymentsKey, 'h-7 w-auto max-w-16')) {
            $paymentsRows[] = ['name' => \Torgash\Blocks\BrandIcons::PAYMENT[$paymentsKey] ?? $paymentsKey, 'image' => null, 'svg' => $paymentsSvg];
        }
    }
@endphp
@if ($paymentsRows !== [])
    <ul class="torgash-block torgash-block-payments flex flex-wrap gap-2 lg:justify-end" aria-label="{{ __('We accept') }}">
        @foreach ($paymentsRows as $paymentsRow)
            {{-- Its name on hover (core's popover, hover mode) — a mark is
                 not always recognised by its picture. --}}
            <li>
                <x-popover hover placement="top" :label="$paymentsRow['name']"
                           class="h-11 min-w-16 px-3 rounded-lg bg-current/10 grid place-items-center cursor-default"
                           panel-class="px-2.5 py-1 text-xs font-medium leading-none whitespace-nowrap">
                    {{ $paymentsRow['name'] }}
                    <x-slot:trigger>
                        @if ($paymentsRow['svg'])
                            {!! $paymentsRow['svg'] !!}
                        @else
                            <img src="{{ $paymentsRow['image'] }}" alt="" class="h-5 w-auto max-w-16 object-contain" loading="lazy">
                        @endif
                    </x-slot:trigger>
                </x-popover>
            </li>
        @endforeach
    </ul>
@endif
