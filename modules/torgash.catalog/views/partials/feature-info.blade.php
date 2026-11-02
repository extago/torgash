{{-- An "i" beside a characteristic's name that opens what the shop wrote
     about it — where the characteristic offers it ($infoWhere: 'product' or
     'filter', Feature::explanation()). Nothing at all otherwise.

     $infoPart: null draws both; 'button' or 'dialog' draws one, for a place
     where the dialog may not stand beside the button (a filter's <summary>
     holds the button, the dialog goes after it) — then both calls pass the
     same $infoName. Otherwise the name is made per drawing: one
     characteristic can stand in the specifications and beside the buy button.
     Variables are named info*: an @include inherits the caller's scope. --}}
@php
    $infoText = $infoFeature->explanation($infoWhere ?? 'product');
    $infoName ??= 'feature-info-'.$infoFeature->id.'-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(5));
    $infoPart ??= null;
@endphp
@if ($infoText !== '')
    @if ($infoPart !== 'dialog')
        <button type="button" class="inline-flex align-middle text-base-content opacity-50 hover:opacity-100 hover:text-primary cursor-pointer ms-1"
                data-open-dialog="modal-{{ $infoName }}"
                aria-label="{{ __('What is it') }}: {{ $infoFeature->t('name') }}" title="{{ __('What is it') }}">
            <x-icon name="info" size="sm" />
        </button>
    @endif
    @if ($infoPart !== 'button')
        <x-modal :name="$infoName" :title="$infoFeature->t('name')" icon="info" size="md">
            <div class="torgash-rich text-sm">{{ safe_html($infoText) }}</div>
        </x-modal>
    @endif
@endif
