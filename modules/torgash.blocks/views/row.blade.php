{{-- Blocks side by side (ZoneRenderer::row): a 12-column grid from the
     tablet up, one column on a phone. The class list is spelled out so the
     Tailwind scanner sees every span it may be asked for. --}}
@php
    $spanClass = [
        1 => 'md:col-span-1', 2 => 'md:col-span-2', 3 => 'md:col-span-3', 4 => 'md:col-span-4',
        5 => 'md:col-span-5', 6 => 'md:col-span-6', 7 => 'md:col-span-7', 8 => 'md:col-span-8',
        9 => 'md:col-span-9', 10 => 'md:col-span-10', 11 => 'md:col-span-11', 12 => 'md:col-span-12',
    ];
@endphp
<div class="torgash-block-row grid grid-cols-1 md:grid-cols-12 gap-6 items-start">
    @foreach ($cells as $cell)
        <div class="min-w-0 {{ $spanClass[$cell['span']] ?? 'md:col-span-12' }}">{!! $cell['html'] !!}</div>
    @endforeach
</div>
