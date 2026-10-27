@props([
    'rules' => [],
    'match' => 'all',
    'heading' => null,
    'hint' => null,
    // Which groups of kinds to offer ('what', 'where', 'who'), or null for all.
    // Not decoration: a kind is answered from the CONTEXT its asker supplies,
    // and a rule the asker cannot answer fails closed — so offering "product
    // category" to something that is not asked about a product is offering a
    // way to switch it off everywhere and never be told why.
    'groups' => null,
])
{{--
    The editor for core's display rules — "show this only when…" — over the
    RuleKinds registry.

    In CORE rather than in whichever module happened to need it first, for the
    same reason the ENGINE is core's: the rules are asked by one module about
    another module's data, and a second copy of this markup would drift from
    the first within a month. It builds its own data from the registry, so a
    caller passes nothing but what it stores.

    Field names are `rules[i][...]` and `rules_match`, which is what every
    consumer already reads.

    A list of values ("category is one of…") is chips plus a search, the same
    way every other picker in the admin is — not a native multiple select, which
    shows three lines and needs Ctrl held down. Chips post as
    `rules[i][value][]` hidden inputs, so nothing reading the form changes.

        <x-admin.display-rules :rules="$block->rules" :match="$block->rules_match" />
--}}
@php
    use App\Core\Display\RuleKinds;

    $allowed = $groups === null ? null : array_map('strval', (array) $groups);

    $kinds = collect(RuleKinds::all())
        ->filter(fn (array $k) => $allowed === null || in_array($k['group'] ?? 'general', $allowed, true))
        ->all();

    $ruleGroups = collect(RuleKinds::grouped())
        ->filter(fn ($_, $group) => $allowed === null || in_array($group, $allowed, true))
        ->map(fn (array $kindsInGroup) => array_intersect_key($kindsInGroup, $kinds))
        ->filter(fn (array $kindsInGroup) => $kindsInGroup !== [])
        ->all();

    $initialRules = collect(old('rules', $rules ?? []))
        ->filter(fn ($r) => is_array($r) && ! empty($r['kind']))
        ->map(function ($r) use ($kinds) {
            $expects = $kinds[$r['kind']]['value'] ?? 'none';
            $value = match (true) {
                in_array($expects, RuleKinds::LISTS, true) => array_map('strval', (array) ($r['value'] ?? [])),
                // Periods as rows of two boxes; a saved rule always has at least one.
                $expects === 'periods' => array_values(array_map(
                    fn ($p) => ['from' => (string) ($p['from'] ?? ''), 'to' => (string) ($p['to'] ?? '')],
                    array_filter((array) ($r['value'] ?? []), 'is_array'),
                )) ?: [['from' => '', 'to' => '']],
                default => is_scalar($r['value'] ?? null) ? (string) $r['value'] : '',
            };

            return [
                'kind' => $r['kind'],
                'operator' => $r['operator'] ?? '',
                'value' => $value,
                // A searched kind's chosen ids carry their names: the page has no list to look them up in.
                'names' => $expects === 'search' ? (object) RuleKinds::labels($r['kind'], $value) : (object) [],
                'q' => '',
                'found' => [],
            ];
        })
        ->values();

    // Options as an ordered LIST, not a map: a map keyed by numeric ids is
    // re-sorted by the browser, and a category tree read in id order is no
    // tree at all.
    $ruleMeta = collect($kinds)->map(fn (array $k, string $name) => [
        'value' => $k['value'] ?? 'none',
        'format' => $k['format'] ?? 'date',
        'search' => ($k['value'] ?? '') === 'search' && is_callable($k['search'] ?? null) ? (string) ($k['search'])() : null,
        // Every operator word goes through label_text(): a DECLARED word is an
        // English string translated by the lang files (ADR-005).
        'operators' => collect($k['operators'] ?? [])
            ->map(fn ($labels, $op) => label_text($labels, null, $op))
            ->all(),
        // A searched kind sends no list at all — that is what it is for.
        'options' => ($k['value'] ?? '') === 'search' ? [] : collect(RuleKinds::options($name))
            ->map(fn ($label, $id) => ['id' => (string) $id, 'label' => (string) $label])
            ->values()->all(),
    ])->all();

    $currentMatch = old('rules_match', $match ?? 'all');
@endphp

<div x-data="{
        rules: {{ \Illuminate\Support\Js::from($initialRules) }},
        meta: {{ \Illuminate\Support\Js::from($ruleMeta) }},
        add() { this.rules.push({ kind: '', operator: '', value: [], names: {}, q: '', found: [] }) },
        remove(i) { this.rules.splice(i, 1) },
        onKind(row) {
            const m = this.meta[row.kind];
            row.operator = m ? Object.keys(m.operators)[0] : '';
            row.value = m && ['options', 'search'].includes(m.value) ? [] : (m && m.value === 'periods' ? [{ from: '', to: '' }] : '');
            row.names = {};
            row.found = [];
            row.q = '';
        },
        expects(row) { return (this.meta[row.kind] || {}).value || 'none' },
        listed(row) { return ['options', 'search'].includes(this.expects(row)) },
        format(row) { return (this.meta[row.kind] || {}).format || 'date' },
        operators(row) { return (this.meta[row.kind] || {}).operators || {} },
        options(row) { return (this.meta[row.kind] || {}).options || [] },
        labelOf(row, id) {
            if (row.names && row.names[id]) return row.names[id];
            const o = this.options(row).find(o => o.id === String(id));
            return o ? o.label.replace(/^[\s–—-]+/, '') : id;
        },
        // What the search box offers: not chosen yet, and matching what was typed —
        // from the list the page has, or, for a searched kind, from the server.
        offered(row) {
            if (this.expects(row) === 'search') return row.found.filter(o => ! row.value.includes(o.id));
            const q = (row.q || '').trim().toLowerCase();
            return this.options(row).filter(o => ! row.value.includes(o.id) && (q === '' || o.label.toLowerCase().includes(q))).slice(0, 50);
        },
        async lookup(row) {
            const url = (this.meta[row.kind] || {}).search;
            const q = (row.q || '').trim();
            if (! url || q.length < 2) { row.found = []; return; }
            const asked = q;
            const answer = await fetch(url + (url.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } }).then(r => r.json()).catch(() => ({ items: [] }));
            if ((row.q || '').trim() !== asked) return;
            row.found = (answer.items || []).map(item => ({ id: String(item.id), label: String(item.label || item.name || item.id) }));
        },
        pick(row, id) {
            if (! row.value.includes(id)) row.value.push(id);
            const o = row.found.find(f => f.id === id);
            if (o) row.names[id] = o.label;
            row.q = '';
            row.found = [];
        },
        unpick(row, id) { row.value = row.value.filter(v => v !== id) },
     }" class="flex flex-col gap-3">
    <div class="flex items-center gap-2 flex-wrap">
        <h3 class="font-semibold">{{ $heading ?: __('Show when') }}</h3>
        <select name="rules_match" class="select select-bordered w-auto" x-show="rules.length > 1" x-cloak>
            <option value="all" @selected($currentMatch !== 'any')>{{ __('all conditions match') }}</option>
            <option value="any" @selected($currentMatch === 'any')>{{ __('any condition matches') }}</option>
        </select>
    </div>

    <template x-for="(row, i) in rules" :key="i">
        <div class="rounded-box border border-base-300 bg-base-200/40 p-3 flex flex-col gap-3">
            <div class="flex flex-wrap items-start gap-2">
                <select class="select select-bordered grow sm:grow-0 sm:w-64"
                        :name="`rules[${i}][kind]`" x-model="row.kind" @change="onKind(row)">
                    <option value="">— {{ __('choose') }} —</option>
                    @foreach ($ruleGroups as $group => $groupKinds)
                        <optgroup label="{{ __(ucfirst($group)) }}">
                            @foreach ($groupKinds as $name => $label)
                                <option value="{{ $name }}">{{ $label }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>

                <select class="select select-bordered grow sm:grow-0 sm:w-56" x-show="row.kind"
                        :name="`rules[${i}][operator]`" x-model="row.operator">
                    <template x-for="(label, op) in operators(row)" :key="op">
                        <option :value="op" x-text="label" :selected="op === row.operator"></option>
                    </template>
                </select>

                <template x-if="expects(row) === 'text' || expects(row) === 'number'">
                    <input class="input input-bordered grow sm:w-64" :type="expects(row) === 'number' ? 'number' : 'text'"
                           :name="`rules[${i}][value]`" x-model="row.value"
                           placeholder="{{ __('value') }}">
                </template>

                <button type="button" @click="remove(i)" class="btn btn-ghost btn-square text-error ms-auto"
                        title="{{ __('Remove condition') }}" aria-label="{{ __('Remove condition') }}">
                    <x-icon name="x" />
                </button>
            </div>

            {{-- A list of values: chips for what is chosen, a search for the rest. --}}
            <template x-if="listed(row)">
                <div class="flex flex-col gap-2">
                    <div class="flex flex-wrap gap-1.5" x-show="row.value.length">
                        <template x-for="id in row.value" :key="id">
                            <span class="badge badge-lg gap-1 bg-base-100 border-base-300 font-normal">
                                <span x-text="labelOf(row, id)"></span>
                                <input type="hidden" :name="`rules[${i}][value][]`" :value="id">
                                <button type="button" class="opacity-50 hover:opacity-100" @click="unpick(row, id)" aria-label="{{ __('Remove') }}">
                                    <x-icon name="x" size="sm" />
                                </button>
                            </span>
                        </template>
                    </div>
                    <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                        <label class="input input-bordered w-full gap-2">
                            <x-icon name="search" size="sm" class="opacity-50" />
                            <input type="text" class="grow" x-model="row.q" @focus="open = true" @input="open = true"
                                   @input.debounce.300ms="expects(row) === 'search' && lookup(row)"
                                   @keydown.escape="open = false"
                                   @keydown.enter.prevent="offered(row)[0] && pick(row, offered(row)[0].id)"
                                   :placeholder="expects(row) === 'search' ? @js(__('Type two letters or more…')) : @js(__('Find and add…'))">
                        </label>
                        <ul x-show="open && offered(row).length" x-cloak
                            class="absolute z-30 mt-1 w-full max-h-64 overflow-y-auto menu menu-sm bg-base-100 rounded-box border border-base-300 shadow-lg p-1 flex-nowrap">
                            <template x-for="o in offered(row)" :key="o.id">
                                <li><button type="button" class="whitespace-pre" @click="pick(row, o.id)" x-text="o.label"></button></li>
                            </template>
                        </ul>
                    </div>
                </div>
            </template>

            {{-- From–to pairs: days of the year, or hours of the day. --}}
            <template x-if="expects(row) === 'periods'">
                <div class="flex flex-col gap-2">
                    <template x-for="(period, p) in row.value" :key="p">
                        <div class="flex items-center gap-2">
                            <input class="input input-bordered w-36 font-mono" :name="`rules[${i}][value][${p}][from]`" x-model="period.from"
                                   :placeholder="format(row) === 'time' ? '18:00' : '25.12'">
                            <span class="opacity-50">—</span>
                            <input class="input input-bordered w-36 font-mono" :name="`rules[${i}][value][${p}][to]`" x-model="period.to"
                                   :placeholder="format(row) === 'time' ? '21:00' : '08.01'">
                            <button type="button" class="btn btn-ghost btn-sm btn-square" x-show="row.value.length > 1"
                                    @click="row.value.splice(p, 1)" aria-label="{{ __('Remove') }}"><x-icon name="x" size="sm" /></button>
                        </div>
                    </template>
                    <div class="flex items-center gap-3 flex-wrap">
                        <button type="button" class="btn btn-sm btn-ghost gap-1.5" @click="row.value.push({ from: '', to: '' })">
                            <x-icon name="plus" size="sm" /> {{ __('Another period') }}
                        </button>
                        <span class="text-xs opacity-60" x-show="format(row) === 'date'">{{ __('25.12 — every year; 25.12.2026 — that year only, for a holiday whose date moves. Without an end — that one day. 25.12–08.01 runs over New Year.') }}</span>
                        <span class="text-xs opacity-60" x-show="format(row) === 'time'">{{ __('On the shop\'s clock — its time zone is in the shop\'s settings. 22:00–02:00 runs over midnight.') }}</span>
                    </div>
                </div>
            </template>
        </div>
    </template>

    <button type="button" @click="add()" class="btn btn-sm gap-1.5 self-start">
        <x-icon name="plus" size="sm" /> {{ __('Add condition') }}
    </button>

    @if ($hint)
        <p class="text-xs opacity-60">{{ $hint }}</p>
    @endif
</div>
