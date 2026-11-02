{{-- One-click towns (PopularPlaces). In the window a button calls the
     picker's own pick(); at checkout it names the town the way anything
     else on the page does — the `choose-place` event the town field takes. --}}
@if ($popularOffered->isNotEmpty())
    <div class="flex flex-wrap gap-1.5" aria-label="{{ __('Popular towns') }}">
        @foreach ($popularOffered as $popularPlace)
            @if ($popularWhere === 'dialog')
                <button type="button" class="btn btn-sm" x-on:click="pick({{ $popularPlace->id }})">{{ $popularPlace->t('name') }}</button>
            @else
                <button type="button" class="btn btn-sm"
                        x-on:click="$dispatch('choose-place', @js(['id' => $popularPlace->id, 'label' => $popularPlace->label(), 'name' => (string) $popularPlace->t('name'), 'country' => $popularPlace->country]))">{{ $popularPlace->t('name') }}</button>
            @endif
        @endforeach
    </div>
@endif
