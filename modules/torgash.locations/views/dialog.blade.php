{{--
    The body of "your town" (PLAN §38, after the owner's own mock-up):
    GPS → country → town, then whatever other modules add for that town
    (`locations.dialog.sections` — the shop's points and "stock at this shop"),
    and one button.

    Picking a town (from the list or by GPS) asks for this body again with
    `place_id`, so the sections below follow the choice before anything is
    saved. The ONE form holds the town and the sections' fields, and posts on
    "Save and close"; the page reloads, because what it shows may change.

    The chosen town IS the field's value (by GPS too), not a hint beside an
    empty field — its district and region under it while it stands; focusing
    it selects it, so typing replaces it. The
    suggestions are in the flow under the field, and the body scrolls the
    field to its top when they open — absolutely placed inside a scrolling
    body they were cut off below the fold, out of sight.

    Named loc* — an @include hands a partial everything its caller has.
--}}
@php
    $locDialogUrl = route('locations.dialog');
    // District and region, minus whatever only repeats the town's own name
    // ("Kyiv, Kyiv" says nothing twice).
    $locContext = $place ? collect([$place->t('district'), $place->state?->t('name')])
        ->filter()->unique()->reject(fn ($part) => mb_strtolower($part) === mb_strtolower((string) $place->t('name')))
        ->implode(', ') : '';
    $locChosen = $place ? (string) $place->t('name') : '';
@endphp
<form method="POST" action="{{ route('locations.save') }}" hx-post="{{ route('locations.save') }}" hx-swap="none"
      class="flex flex-col min-h-0"
      x-data="{
          q: @js($locChosen), items: [], open: false, busy: false, gpsError: '',
          country: @js($country),
          async search() {
              if (this.q.trim().length < 2) { this.items = []; this.open = false; return; }
              const url = new URL(@js(route('places.suggest')), location.origin);
              url.searchParams.set('q', this.q);
              if (this.country) url.searchParams.set('country', this.country);
              const answer = await fetch(url, { headers: { Accept: 'application/json' } }).then(r => r.json());
              this.items = answer.items || [];
              this.open = true;
              this.$nextTick(() => {
                  const body = this.$refs.town.closest('.overflow-y-auto');
                  if (body && this.items.length) body.scrollTop += this.$refs.town.getBoundingClientRect().top - body.getBoundingClientRect().top - 16;
              });
          },
          pick(id) {
              const url = new URL(@js($locDialogUrl), location.origin);
              url.searchParams.set('place_id', id);
              htmx.ajax('GET', url.toString(), { target: '#torgash-location-body', swap: 'innerHTML' });
          },
          gps() {
              if (! navigator.geolocation) { this.gpsError = @js(__('This browser cannot tell where it is.')); return; }
              this.busy = true; this.gpsError = '';
              navigator.geolocation.getCurrentPosition(async (pos) => {
                  const url = new URL(@js(route('places.nearest')), location.origin);
                  url.searchParams.set('lat', pos.coords.latitude);
                  url.searchParams.set('lng', pos.coords.longitude);
                  const found = await fetch(url, { headers: { Accept: 'application/json' } }).then(r => r.json());
                  this.busy = false;
                  found.id ? this.pick(found.id) : this.gpsError = @js(__('No town of ours is near where you are — choose it below.'));
              }, () => { this.busy = false; this.gpsError = @js(__('The browser did not share where you are — choose the town below.')); }, { timeout: 10000 });
          },
      }">
    @csrf
    <input type="hidden" name="place_id" value="{{ $place?->id }}">

    {{-- The house style of a window (<x-modal>, "buy in one click"): icon,
         title and close in one ruled row; body; a tinted footer. --}}
    <div class="flex items-center gap-2.5 px-5 py-3 border-b border-base-300 shrink-0">
        <x-icon name="map-pin" size="lg" class="text-primary shrink-0" />
        <h3 id="torgash-location-title" class="font-semibold leading-tight">{{ __('Let us get your town right') }}</h3>
        <button type="button" class="btn btn-ghost btn-sm btn-circle ms-auto shrink-0" data-location-close aria-label="{{ __('Close') }}"><x-icon name="x" /></button>
    </div>

    <div class="overflow-y-auto min-h-0 px-5 py-4 flex flex-col gap-4">
        <button type="button" x-on:click="gps()" :disabled="busy" class="btn btn-primary w-full gap-2">
            <span x-show="! busy"><x-icon name="locate-fixed" /></span>
            <span x-show="busy" x-cloak class="loading loading-spinner loading-sm"></span>
            {{ __('Find me by GPS') }}
        </button>
        <p x-show="gpsError" x-cloak x-text="gpsError" class="text-sm text-error -mt-2"></p>

        @if ($countries->count() > 1)
            <label class="flex flex-col gap-1.5">
                <span class="text-sm opacity-60">{{ __('Country') }}</span>
                <select x-model="country" class="select select-bordered w-full">
                    @foreach ($countries as $locCountry)
                        <option value="{{ $locCountry->code }}">{{ $locCountry->t('name') }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        <div class="flex flex-col gap-1.5" x-ref="town">
            <span class="text-sm opacity-60">{{ __('Town') }}</span>
            <label class="input input-bordered w-full flex items-center gap-2">
                {{-- Autofocus only while nothing is chosen: after GPS it would
                     pull the phone's keyboard over the answer. --}}
                <input type="search" @if (! $place) autofocus @endif x-model="q" x-on:input.debounce.250ms="search()" x-on:keydown.escape="open = false"
                       x-on:focus="$el.select()" class="grow min-w-0" autocomplete="off"
                       placeholder="{{ __('Start typing the town') }}" aria-label="{{ __('Town') }}">
                <x-icon name="search" class="opacity-50 shrink-0" />
            </label>
            @if ($place && $locContext !== '')
                <div class="text-xs opacity-60" x-show="q === @js($locChosen)">{{ $locContext }}</div>
            @endif
            <ul x-show="open && items.length" x-cloak x-on:click.outside="open = false"
                class="bg-base-100 border border-base-300 rounded-box shadow-lg max-h-64 overflow-y-auto p-1">
                <template x-for="item in items" :key="item.id">
                    <li>
                        <button type="button" x-on:click="pick(item.id)" class="w-full text-left px-3 py-2 rounded-field hover:bg-base-200">
                            <span class="font-medium" x-text="item.name"></span>
                            <span class="text-xs opacity-60 block" x-text="item.context"></span>
                        </button>
                    </li>
                </template>
            </ul>
        </div>

        {!! $sections !!}
    </div>

    <div class="px-5 py-3 border-t border-base-300 bg-base-200/40 flex flex-wrap justify-end gap-2 shrink-0">
        <button type="button" class="btn btn-ghost" data-location-close>{{ __('Cancel') }}</button>
        <button class="btn btn-primary" @disabled(! $place)>{{ __('Save and close') }}</button>
    </div>
</form>
