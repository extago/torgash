{{-- On the order screen (checkout.order.side_panels) for an order of goods
     with an age (torgash.age, PLAN §71): the one real check is the document
     at the door, and this is where the manager is told to ask for it. --}}
<section class="card bg-base-100 border border-warning">
    <div class="card-body gap-2">
        <h3 class="font-semibold flex items-center gap-1.5"><x-icon name="shield-alert" size="sm" class="text-warning" />{{ __(':age+: check an ID', ['age' => $ageNote->age]) }}</h3>
        <p class="text-sm">{{ __('The order has goods sold only to people of :age or older. Hand it over only after seeing a document that shows the buyer\'s age.', ['age' => $ageNote->age]) }}</p>
        <p class="text-xs opacity-60">
            @if ($ageNote->confirmed_at)
                {{ __('The buyer ticked ":age or older" when placing it — their word, not a check.', ['age' => $ageNote->age]) }}
            @else
                {{ __('Placed without the buyer\'s tick: by a manager, through the API, from 1C, or while the tick was off in the settings.') }}
            @endif
        </p>
    </div>
</section>
