{{-- "Your town is Kyiv? Yes / Change" (setting `town_prompt`, TownGuess):
     once a visit, to a visitor with no town, under the town in the header.
     One panel per page (core.footer) anchored by the script to whichever
     town button is VISIBLE — the header is drawn twice (desktop and phone),
     so the button carries no fixed anchor of its own. "Yes" is the window's
     own save, so the town is remembered exactly as one picked by hand;
     "Change" opens the window. In `browser` mode the town is filled in here,
     from the browser's coordinates, before the panel is shown. --}}
<div id="torgash-town-prompt" popover="manual" data-anchor="[data-location-open]" data-align="start" data-mode="{{ $townMode }}"
     data-nearest="{{ route('places.nearest') }}"
     class="torgash-popover torgash-bar-panel bg-base-100 text-base-content border border-base-300 rounded-box shadow-lg w-72 p-4 pe-9">
    <button type="button" popovertarget="torgash-town-prompt" popovertargetaction="hide"
            class="btn btn-ghost btn-sm btn-circle absolute top-1.5 end-1.5 opacity-60" aria-label="{{ __('Close') }}">
        <x-icon name="x" size="sm" />
    </button>
    <p class="flex items-start gap-2 leading-snug">
        <x-icon name="map-pin" class="text-primary shrink-0 mt-0.5" />
        <span>{{ __('Is your town') }} <b data-town-name>{{ $townGuess?->t('name') }}</b>?</span>
    </p>
    <form method="post" action="{{ route('locations.save') }}" class="flex items-center gap-2 mt-3">
        @csrf
        <input type="hidden" name="place_id" value="{{ $townGuess?->id }}">
        <button type="submit" class="btn btn-primary btn-sm px-5">{{ __('Yes') }}</button>
        <button type="button" class="btn btn-ghost btn-sm" data-town-change>{{ __('Change') }}</button>
    </form>
</div>
<script nonce="{{ csp_nonce() }}">
    (() => {
        const panel = document.getElementById('torgash-town-prompt');
        if (! panel || ! panel.showPopover) return;

        const field = panel.querySelector('[name="place_id"]');
        const anchor = () => [...document.querySelectorAll(panel.dataset.anchor)].find((el) => el.offsetParent !== null);

        const show = () => {
            const button = anchor();
            // No town in sight (a header without one): nothing to point at, no question.
            if (! button || ! field.value) return;
            button.style.anchorName = '--torgash-town-prompt';
            panel.style.positionAnchor = '--torgash-town-prompt';
            panel.showPopover();
        };

        panel.querySelector('[data-town-change]').addEventListener('click', () => {
            panel.hidePopover();
            anchor()?.click();
        });

        // A beat after the page, so the question arrives on a page that has
        // settled rather than with it.
        const later = () => setTimeout(show, 700);

        if (panel.dataset.mode === 'browser' && navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(async (position) => {
                try {
                    const url = new URL(panel.dataset.nearest, location.href);
                    url.searchParams.set('lat', position.coords.latitude);
                    url.searchParams.set('lng', position.coords.longitude);
                    const found = await (await fetch(url, { headers: { Accept: 'application/json' } })).json();
                    if (found.id) {
                        field.value = found.id;
                        panel.querySelector('[data-town-name]').textContent = found.name;
                    }
                } catch (e) {}
                later();
            }, later, { timeout: 8000, maximumAge: 3600000 });
        } else {
            later();
        }
    })();
</script>
