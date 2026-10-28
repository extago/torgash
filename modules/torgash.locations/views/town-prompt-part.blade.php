{{-- "Is your town …?" (setting `town_prompt`): once a visit, only to a
     visitor who has not chosen a town. Marked as asked when it is printed,
     so the next page does not ask again whatever they did. A personal part
     of the page (LocationsServiceProvider): the guess is from the visitor's
     own address. --}}
@php
    $townGuesser = app(\Torgash\Locations\TownGuess::class);
    $townMode = $townGuesser->mode();
    $townAsk = $townMode !== 'off' && ! session('locations.town_prompted') && ! hook_filter('storefront.visitor_place', null);
    $townGuess = $townAsk ? $townGuesser->guess(request()->ip()) : null;

    // Without a town to name only the browser may still find one.
    $townAsk = $townAsk && ($townGuess || $townMode === 'browser');

    if ($townAsk) {
        session()->put('locations.town_prompted', true);
    }
@endphp
@if ($townAsk)
    @include('torgash.locations::town-prompt')
@endif
