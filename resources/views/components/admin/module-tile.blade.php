@props(['manifest', 'size' => 'md', 'icon' => null])
{{-- How a module is recognised at a glance: its own picture when it ships one,
     else its icon on its own tint (ModuleManifest::logoFile / icon / color).
     The modules screen and a module's settings page draw the same tile. --}}
@php
    $tileBox = ['sm' => 'w-8 h-8', 'md' => 'w-11 h-11', 'lg' => 'w-14 h-14'][$size] ?? 'w-11 h-11';
    $tileIcon = ['sm' => 'md', 'md' => 'lg', 'lg' => 'xl'][$size] ?? 'lg';
    $tileColor = $manifest->color();
@endphp
@if ($manifest->logoFile())
    <img src="{{ route('admin.modules.logo', $manifest->id) }}" alt="" loading="lazy"
         {{ $attributes->class([$tileBox, 'shrink-0 rounded-box object-contain bg-base-200 p-1.5']) }}>
@else
    <div {{ $attributes->class([$tileBox, 'shrink-0 rounded-box grid place-items-center', 'bg-primary/10 text-primary' => ! $tileColor]) }}
         @if ($tileColor) style="background-color: color-mix(in oklab, {{ $tileColor }} 15%, transparent); color: {{ $tileColor }}" @endif>
        <x-icon :name="$icon ?? $manifest->icon()" :size="$tileIcon" />
    </div>
@endif
