<?php

namespace Torgash\Checkout\Shipping;

use Illuminate\Http\Request;

/**
 * Where this visitor is having their order sent, remembered between pages.
 *
 * The cart page asks the question ("deliver to?") and the checkout form has to
 * still know the answer, so it lives in the session rather than in the URL.
 * What is kept is deliberately thin — the place id, the text that was in the
 * box, and the postal code — because Location turns that into a match on its
 * own, and a shop running without the locations registry still remembers a
 * postal code the customer typed.
 */
class LocationStore
{
    protected string $sessionKey = 'checkout_location';

    /** What the session remembers — used when rendering a page, not a form post. */
    public function current(): Location
    {
        return $this->build($this->placeId(), $this->postal(), $this->country());
    }

    /** The text that was in the town box, so the field comes back filled in. */
    public function town(): ?string
    {
        return session($this->sessionKey)['town'] ?? null;
    }

    public function placeId(): ?int
    {
        $id = session($this->sessionKey)['place_id'] ?? null;

        return $id ? (int) $id : null;
    }

    public function postal(): ?string
    {
        return session($this->sessionKey)['postal'] ?? null;
    }

    public function country(): ?string
    {
        return session($this->sessionKey)['country'] ?? null;
    }

    /**
     * The destination this request is about, remembered for the next page.
     *
     * The request wins over the session, deliberately: a customer who picked
     * Kyiv on the cart page and then typed another town into checkout is going
     * to that other town, and quoting them Kyiv's rate because the session
     * still said Kyiv is exactly the bug this ordering prevents.
     */
    public function fromRequest(Request $request): Location
    {
        $previous = $this->placeId();
        $placeId = ((int) $request->input('place_id')) ?: null;
        $postal = trim((string) $request->input('postal_code', '')) ?: null;
        $town = trim((string) $request->input('city', '')) ?: null;
        $country = strtoupper(trim((string) $request->input('country', ''))) ?: null;

        session([$this->sessionKey => [
            'place_id' => $placeId,
            'postal' => $postal,
            'town' => $town,
            'country' => $country,
        ]]);

        // "The customer has named a town" — the same announcement a geo page
        // and the pickup-point picker make, now made by the town box as well,
        // which is where it is named in almost every order. A carrier hears it
        // and can have that town's branches ready before the customer reaches
        // the delivery step (Nova Poshta does).
        //
        // Only on a CHANGE: a quote re-runs on every keystroke in the rest of
        // the form, and announcing the same town thirty times is thirty
        // listeners' worth of work for one fact.
        if ($placeId !== null && $placeId !== $previous) {
            hook_action('storefront.place_chosen', [
                'place_id' => $placeId,
                'source' => 'checkout',
            ]);
        }

        return $this->build($placeId, $postal, $country);
    }

    /**
     * Records a place named somewhere other than the town box — picking one of
     * the shop's own points is the case that exists today.
     *
     * The town TEXT is deliberately left to the registry rather than passed in:
     * the box shows a name in the visitor's language, and a caller from another
     * module would be guessing at both the language and the disambiguating
     * suffix. What is kept is the id, which is the only part zones match on;
     * the box fills itself from it on the next render.
     */
    public function rememberPlace(int $placeId): void
    {
        $names = (array) hook_filter('locations.place.names', [], ['ids' => [$placeId]]);

        session([$this->sessionKey => [
            'place_id' => $placeId,
            'postal' => $this->postal(),
            // The disambiguating half ("Ivanivka — Bashtanka district") belongs
            // in a picker, not on an invoice, so only the name is kept.
            'town' => trim(explode('—', (string) ($names[$placeId] ?? ''))[0]) ?: $this->town(),
            'country' => Location::forPlace($placeId)->country ?? $this->country(),
        ]]);
    }

    public function forget(): void
    {
        session()->forget($this->sessionKey);
    }

    /**
     * A picked town settles the country by itself — it sits in exactly one —
     * so the registry's answer wins over the select. The select still matters
     * on its own: a country chosen before any town already narrows the zone,
     * which is how "we ship to Poland at this rate" quotes before the customer
     * has named a Polish town.
     */
    protected function build(?int $placeId, ?string $postal, ?string $country): Location
    {
        if (! $placeId) {
            return new Location(country: $country, postal: $postal);
        }

        $resolved = Location::forPlace($placeId, $postal);

        return $resolved->country
            ? $resolved
            : new Location(country: $country, state: $resolved->state, placeId: $placeId, postal: $resolved->postal);
    }
}
