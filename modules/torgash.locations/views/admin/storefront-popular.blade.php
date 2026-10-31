{{-- "Popular towns" on the storefront's form (PopularPlaces): ids with
     commas, the towns they name written out under the field so a typo shows. --}}
<section class="card bg-base-100 border border-base-300">
    <div class="card-body gap-3">
        <h3 class="font-semibold">{{ __('Popular towns') }}</h3>
        <input type="hidden" name="popular_section" value="1">
        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Town ids, separated by commas') }}</legend>
            <input name="popular_places" value="{{ $popularTyped }}" class="input input-bordered w-full font-mono" placeholder="12, 345, 6789" autocomplete="off">
            @error('popular_places') <p class="label text-error whitespace-normal">{{ $message }}</p> @enderror
            <p class="label whitespace-normal">
                {{ __('Offered as buttons — one click chooses the town. The id is in the list of towns.') }}
                @can('locations.manage')
                    <a href="{{ route('admin.locations.index') }}" class="link" target="_blank">{{ __('Towns') }}</a>
                @endcan
            </p>
        </fieldset>
        @if ($popularPlaces->isNotEmpty())
            <div class="flex flex-wrap gap-1.5">
                @foreach ($popularPlaces as $popularPlace)
                    <span class="badge badge-sm badge-ghost">{{ $popularPlace->id }} · {{ $popularPlace->label() }}</span>
                @endforeach
            </div>
        @endif
        <label class="label justify-start gap-2 p-0 cursor-pointer">
            <input type="checkbox" name="popular_in_dialog" value="1" class="checkbox checkbox-sm" @checked(old('popular_in_dialog', $popularShown['dialog']))>
            <span class="label-text whitespace-normal">{{ __('In the "your town" window') }}</span>
        </label>
        <label class="label justify-start gap-2 p-0 cursor-pointer">
            <input type="checkbox" name="popular_at_checkout" value="1" class="checkbox checkbox-sm" @checked(old('popular_at_checkout', $popularShown['checkout']))>
            <span class="label-text whitespace-normal">{{ __('At checkout, under the town') }}</span>
        </label>
    </div>
</section>
