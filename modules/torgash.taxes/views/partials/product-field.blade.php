{{-- Sits in the product form's pricing section, saved by the
     catalog.product.saved listener in TaxesServiceProvider. --}}
<div x-data="{ mode: '{{ old('taxes_mode', $hasOverride ? 'custom' : 'default') }}' }">
    <div class="text-xs font-semibold uppercase tracking-wide opacity-50">{{ __('Taxes') }}</div>

    <label class="label cursor-pointer justify-start gap-2 py-1">
        <input type="radio" name="taxes_mode" value="default" x-model="mode" class="radio radio-sm">
        <span class="label-text text-sm">
            {{ __('Default') }}
            <span class="opacity-50">
                @if (count($defaults) > 0)
                    — {{ collect($defaults)->map(fn ($tax) => $tax->t('name', admin_content_locale()))->implode(', ') }}
                @else
                    — {{ __('no tax applies by default') }}
                @endif
            </span>
        </span>
    </label>

    <label class="label cursor-pointer justify-start gap-2 py-1">
        <input type="radio" name="taxes_mode" value="custom" x-model="mode" class="radio radio-sm">
        <span class="label-text text-sm">{{ __('This product is taxed differently') }}</span>
    </label>

    <div class="pl-6 flex flex-wrap gap-x-4 gap-y-1" x-show="mode === 'custom'" x-cloak>
        @foreach ($taxes as $tax)
            <label class="label cursor-pointer justify-start gap-2 py-1">
                <input type="checkbox" name="taxes[]" value="{{ $tax->id }}" class="checkbox checkbox-sm"
                       @checked(in_array($tax->id, old('taxes', $selected)))>
                <span class="label-text text-sm">
                    {{ $tax->t('name', admin_content_locale()) }}
                    <span class="opacity-50">{{ $tax->rateLabel() }}{{ $tax->price_includes_tax ? ', '.__('in the price') : '' }}</span>
                </span>
            </label>
        @endforeach
    </div>

    <div class="text-xs opacity-50 mt-1">
        {{ __('A product that is exempt is not untaxed — give it a 0% tax, so its invoice line still says so.') }}
    </div>
</div>
