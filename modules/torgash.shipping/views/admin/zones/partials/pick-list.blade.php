{{-- Body of a pickList (see pick-list-script.blade.php). Must sit inside an
     x-data="pickList({...})" element.
       $field             — hidden input name, e.g. "regions[]"
       $filterPlaceholder — text for the filter box --}}

{{-- Chosen, as removable chips. --}}
<div class="flex flex-wrap gap-1" x-show="chosen.length">
    <template x-for="value in chosen" :key="value">
        <button type="button" class="badge badge-sm badge-primary gap-1 cursor-pointer" @click="toggle(value)">
            <span x-text="labelFor(value)"></span>
            <span aria-hidden="true">&times;</span>
        </button>
    </template>
</div>

<input type="text" class="input input-bordered w-full" x-model="filter"
       placeholder="{{ $filterPlaceholder }}" autocomplete="off">

<div class="max-h-56 overflow-y-auto border border-base-200 rounded-box divide-y divide-base-200">
    <template x-for="opt in visible()" :key="opt.value">
        <label class="flex items-center gap-2 px-3 py-1.5 text-sm cursor-pointer hover:bg-base-200">
            <input type="checkbox" class="checkbox checkbox-sm" @change="toggle(opt.value)">
            <span x-text="opt.label"></span>
            <span class="opacity-40 font-mono text-xs" x-text="opt.value" x-show="opt.value !== opt.label"></span>
        </label>
    </template>
    <div class="px-3 py-2 text-xs opacity-40" x-show="!visible().length">{{ __('Nothing matches.') }}</div>
</div>

<template x-for="value in chosen" :key="'h-' + value">
    <input type="hidden" name="{{ $field }}" :value="value">
</template>
