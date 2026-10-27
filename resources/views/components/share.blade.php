{{--
    "Share" — a button that opens where the page can be sent: the shop's
    networks (core setting "Share to", App\Core\Content\Share) and "Copy the
    link". On a phone the system's own share sheet is offered instead, and
    preventDefault() keeps the popover shut.

        <x-share :url="$post->url()" :title="$post->t('title')" :label="__('Share the article')" />
--}}
@props(['url', 'title' => '', 'label' => null])
@php $shareLinks = app(\App\Core\Content\Share::class)->links((string) $url, (string) $title); @endphp
<div x-data="{ copied: false }" {{ $attributes }}>
    <x-popover placement="top" class="btn btn-sm btn-outline gap-1.5" panel-class="w-52 p-2"
               x-on:click="if (navigator.share && window.matchMedia('(pointer: coarse)').matches) { $event.preventDefault(); navigator.share({ title: {{ \Illuminate\Support\Js::from((string) $title) }}, url: {{ \Illuminate\Support\Js::from((string) $url) }} }).catch(() => {}); }">
        <ul class="menu menu-sm w-full p-0">
            @foreach ($shareLinks as $shareLink)
                <li><a href="{{ $shareLink['href'] }}" target="_blank" rel="noopener nofollow" data-share="{{ $shareLink['slug'] }}">{!! $shareLink['mark'] !!} {{ $shareLink['name'] }}</a></li>
            @endforeach
            <li>
                <button type="button" x-on:click="navigator.clipboard.writeText({{ \Illuminate\Support\Js::from((string) $url) }}).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                    <x-icon name="link" size="sm" />
                    <span x-show="! copied">{{ __('Copy the link') }}</span>
                    <span x-show="copied" x-cloak class="text-success">{{ __('Copied') }}</span>
                </button>
            </li>
        </ul>

        <x-slot:trigger>
            <x-icon name="share-2" size="sm" /> {{ $label ?? __('Share') }}
        </x-slot:trigger>
    </x-popover>
</div>
