{{-- The dropdown under a placePicker input.

     Its own partial because it is used from three screens and they must scroll,
     paginate and disambiguate identically; a fourth copy is how one of them
     quietly keeps showing only the first ten matches.

     $onPick is extra Alpine to run alongside pick(), for callers that also keep
     the choice somewhere of their own. --}}
<div class="absolute top-full left-0 right-0 z-30 bg-base-100 border border-base-300 rounded-box shadow-md mt-1 max-h-72 overflow-y-auto"
     x-show="open && results.length" x-cloak
     @click.outside="open = false" @scroll="onScroll($event)">
    <template x-for="item in results" :key="item.id">
        <button type="button" class="block w-full text-left px-3 py-2 hover:bg-base-200 text-sm"
                @click="pick(item){{ $onPick ?? '' }}">
            <span x-text="item.name" class="font-medium block"></span>
            {{-- District AND region: a customer who cannot name their district
                 still recognises their region, and namesakes are the whole
                 reason this line exists. --}}
            <span x-text="item.context" x-show="item.context" class="opacity-60 text-xs block"></span>
        </button>
    </template>
    <div class="px-3 py-2 text-xs opacity-50" x-show="loading">{{ __('Loading…') }}</div>
</div>
