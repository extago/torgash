{{-- A filterable checkbox list — the "shuffle regions across" widget, without a
     second listbox: the picked items show as removable chips above the filtered
     list, and one hidden input per pick carries the value on submit.

     `all`      : [{ value, label, hint? }]  everything pickable
     `chosen`   : [value, …]                 currently in the zone
     `field`    : string                     the hidden input name, e.g. "regions[]"
     --}}
@once
    @push('scripts')
        <script nonce="{{ csp_nonce() }}">
            document.addEventListener('alpine:init', () => {
                Alpine.data('pickList', ({ all = [], chosen = [], field = '' }) => ({
                    all,
                    field,
                    chosen: [...chosen],
                    filter: '',

                    labelFor(value) {
                        return (this.all.find((o) => o.value === value) || {}).label || value;
                    },

                    visible() {
                        const q = this.filter.trim().toLowerCase();

                        return this.all.filter((o) => {
                            if (this.chosen.includes(o.value)) return false;
                            if (!q) return true;

                            return (o.label + ' ' + (o.hint || '') + ' ' + o.value).toLowerCase().includes(q);
                        });
                    },

                    toggle(value) {
                        this.chosen.includes(value)
                            ? (this.chosen = this.chosen.filter((v) => v !== value))
                            : this.chosen.push(value);
                    },
                }));
            });
        </script>
    @endpush
@endonce
