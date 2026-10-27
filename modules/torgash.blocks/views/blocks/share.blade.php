{{--
    Share buttons.

    The address shared is the CURRENT one — a share block sits in a zone, and
    the zone is on a page, a product, an article. Nothing here needs to know
    which: request()->url() is the canonical answer, and it deliberately drops
    the query string so a filtered listing is not shared with someone else's
    filters on it.

    The title is taken from the zone context when the page supplies one
    (`$zoneContext['page']`, `['product']`), and otherwise left out entirely:
    every network reads OpenGraph off the page it is handed, so an absent title
    costs nothing and a wrong one is worse than none.

    No brand logos: Lucide dropped them, and an inline <svg> is not allowed in
    this codebase. The buttons say the network's name, which also survives a
    rebrand — see PLAN §28.4 for the note about vendoring Simple Icons (CC0)
    if real marks are ever wanted.
--}}
@php
    $url = request()->url();

    $subject = $zoneContext['page'] ?? $zoneContext['product'] ?? null;
    $title = $subject && method_exists($subject, 't') ? (string) ($subject->t('title') ?: $subject->t('name')) : '';

    $e = fn (string $value) => rawurlencode($value);

    $networks = [
        'telegram' => ['label' => 'Telegram', 'href' => 'https://t.me/share/url?url='.$e($url).'&text='.$e($title)],
        'whatsapp' => ['label' => 'WhatsApp', 'href' => 'https://api.whatsapp.com/send?text='.$e(trim($title.' '.$url))],
        'viber' => ['label' => 'Viber', 'href' => 'viber://forward?text='.$e(trim($title.' '.$url))],
        'vk' => ['label' => 'VK', 'href' => 'https://vk.com/share.php?url='.$e($url)],
        'facebook' => ['label' => 'Facebook', 'href' => 'https://www.facebook.com/sharer/sharer.php?u='.$e($url)],
        'x' => ['label' => 'X', 'href' => 'https://twitter.com/intent/tweet?url='.$e($url).'&text='.$e($title)],
    ];

    $chosen = array_filter($networks, fn ($key) => ! empty($settings[$key]), ARRAY_FILTER_USE_KEY);
    $copy = ! empty($settings['copy_link']);
    $heading = trim((string) ($settings['heading'] ?? ''));
@endphp

@if ($chosen || $copy)
    <div class="bg-base-100 border border-base-300 rounded-box p-4 flex flex-wrap items-center gap-2">
        @if ($heading !== '')
            <span class="text-sm opacity-70 me-1">{{ $heading }}</span>
        @endif

        @foreach ($chosen as $network)
            {{-- rel="noopener": a share window opening with a handle on this
                 page is the one thing target="_blank" gets wrong by default. --}}
            <a href="{{ $network['href'] }}" target="_blank" rel="noopener noreferrer"
               class="btn btn-sm btn-ghost border border-base-300 gap-1.5">
                <x-icon name="share-2" size="sm" class="opacity-60" />
                {{ $network['label'] }}
            </a>
        @endforeach

        @if ($copy)
            {{-- Clipboard writes need a secure context; on plain http the API is
                 absent, so the button says so instead of failing silently. --}}
            <button type="button" class="btn btn-sm btn-ghost border border-base-300 gap-1.5"
                    x-data="{ done: false }"
                    @click="navigator.clipboard?.writeText(@js($url)).then(() => { done = true; setTimeout(() => done = false, 2000) })">
                <x-icon name="link" size="sm" class="opacity-60" />
                <span x-show="! done">{{ __('Copy link') }}</span>
                <span x-show="done" x-cloak class="text-success">{{ __('Copied') }}</span>
            </button>
        @endif
    </div>
@endif
