{{-- The town field, shared by the zone editor and the storefront.
     One component so both behave identically — a customer being offered a town
     the admin cannot pick (or the reverse) is a bug nobody reproduces. --}}
@once
    @push('scripts')
        <script nonce="{{ csp_nonce() }}">
            document.addEventListener('alpine:init', () => {
                // `label` disambiguates ("Ivanivka (Bashtanka district)") and is
                // what the box shows; `name` is the bare town name, which is what
                // an order records as its city. Callers bind whichever they mean.
                Alpine.data('placePicker', ({ endpoint, label = '', name = '', placeId = '', country = '' }) => ({
                    label, name, placeId, country,
                    results: [],
                    open: false,
                    timer: null,
                    term: '',
                    page: 1,
                    more: false,
                    loading: false,
                    root: null,

                    init() {
                        // The element the outside world hears us on. Kept here
                        // because $el means "the element the call came from",
                        // and half our calls come from a suggestion button that
                        // is gone by the time we speak — see announce().
                        this.root = this.$el;
                    },

                    search(term) {
                        this.label = term;
                        // Typed text is both, until a pick separates them.
                        this.name = term;
                        // Typing clears the previous choice: the id and the text
                        // in the box must never disagree.
                        this.placeId = '';
                        this.term = term;
                        clearTimeout(this.timer);

                        if (term.trim().length < 2) {
                            this.results = [];
                            this.more = false;
                            return;
                        }

                        // Debounced, or every keystroke is a query against
                        // 225 000 rows.
                        this.timer = setTimeout(() => this.load(1), 250);
                    },

                    /**
                     * Page 1 replaces the list, later pages append to it. The
                     * list is capped server-side, so without this the eleventh
                     * namesake was simply unreachable.
                     */
                    load(page) {
                        if (this.loading || this.term.trim().length < 2) return;

                        this.loading = true;
                        const url = new URL(endpoint, window.location.origin);
                        url.searchParams.set('q', this.term);
                        url.searchParams.set('page', page);
                        if (this.country) url.searchParams.set('country', this.country);

                        fetch(url, { headers: { Accept: 'application/json' } })
                            .then((response) => response.json())
                            .then((data) => {
                                this.results = page === 1 ? data.items : this.results.concat(data.items);
                                this.more = data.more;
                                this.page = page;
                                this.open = true;
                            })
                            .catch(() => { if (page === 1) this.results = []; })
                            .finally(() => { this.loading = false; });
                    },

                    /** Near the bottom of the dropdown, fetch the next page. */
                    onScroll(event) {
                        const box = event.target;

                        if (this.more && box.scrollTop + box.clientHeight >= box.scrollHeight - 32) {
                            this.load(this.page + 1);
                        }
                    },

                    /**
                     * A town belongs to exactly one country, so changing the
                     * country invalidates the pick rather than narrowing it.
                     */
                    setCountry(code) {
                        this.country = code;
                        this.placeId = '';
                        this.label = '';
                        this.name = '';
                        this.term = '';
                        this.results = [];
                        this.more = false;
                        this.open = false;
                        this.announce();
                    },

                    pick(item) {
                        this.placeId = item.id;
                        this.label = item.label;
                        this.name = item.name;
                        this.results = [];
                        this.more = false;
                        this.open = false;
                        this.announce();
                    },

                    /**
                     * "The destination changed" — the one event anything
                     * outside this component listens for, and only once the
                     * bindings have reached the DOM.
                     *
                     * Alpine writes :value on the next tick, while a listener
                     * (htmx, here) reads the form the instant the event fires.
                     * Announcing first therefore ships the PREVIOUS town and a
                     * stale place id: a quote for the wrong place, with nothing
                     * anywhere reporting an error. The select's own change event
                     * has the same problem, which is why the country goes out
                     * through here too instead of being listened to directly.
                     *
                     * Dispatched on the component root and NOT through
                     * $dispatch, which fires on the element the call came from.
                     * Picking a suggestion calls in from a button inside the
                     * x-for, and clearing the list removes that button before
                     * the tick is up: the event then goes out on an element
                     * that is no longer in the document, reaches nobody, and
                     * the price silently stays the previous town's. The pick
                     * itself worked, so the box showed the new town all the
                     * while — which is exactly why this took a network log to
                     * find rather than a look at the screen.
                     */
                    announce() {
                        this.$nextTick(() => this.root.dispatchEvent(new CustomEvent('destination-changed', {
                            bubbles: true,
                            detail: { placeId: this.placeId, country: this.country },
                        })));
                    },
                }));
            });
        </script>
    @endpush
@endonce
