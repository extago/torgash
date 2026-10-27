{{--
    A phone or an email that offers two things when pressed — copy it, or
    call / write (the owner's own footer). Core's popover: the browser's
    Popover API placed by CSS Anchor Positioning, no library. Copying needs a
    script (Alpine, on every storefront page); the call or the letter does
    not. In HTML a shop writes, it is {phone_actions} and {email_actions}
    (Content\ContactPlaceholders).
--}}
@props(['value', 'kind' => 'phone'])
@php
    $actionsHref = $kind === 'email' ? 'mailto:'.$value : 'tel:'.preg_replace('/[^\d+]/', '', $value);
@endphp
<x-popover {{ $attributes->merge(['class' => 'hover:text-primary']) }} panel-class="p-1.5 whitespace-nowrap">
    <div class="flex items-center gap-1" x-data="{ copied: false }">
        <button type="button" class="btn btn-ghost btn-sm gap-1.5"
                x-on:click="navigator.clipboard?.writeText(@js($value)); copied = true; setTimeout(() => copied = false, 1500)">
            <x-icon name="copy" size="sm" />
            <span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span>
        </button>
        <span class="w-px h-4 bg-base-300" aria-hidden="true"></span>
        <a href="{{ $actionsHref }}" class="btn btn-ghost btn-sm gap-1.5">
            <x-icon :name="$kind === 'email' ? 'send' : 'phone'" size="sm" /> {{ $kind === 'email' ? __('Write') : __('Call') }}
        </a>
    </div>
    <x-slot:trigger>{{ $value }}</x-slot:trigger>
</x-popover>
