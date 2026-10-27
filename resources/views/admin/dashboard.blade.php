@extends('admin.layout')

@section('title', __('Dashboard'))
@section('own_heading', '1')

{{--
    Widgets from whoever owns the data (App\Core\Admin\Dashboard), laid out as
    a band across the top and two or three COLUMNS below — each column a stack
    of its own, so widgets of different heights never leave holes the way a
    grid of cells does. "Arrange" swaps the page for a board of compact tiles:
    pick the columns, drag tiles between the band, the columns and the tray of
    hidden ones (or use the arrows and the zone picker on each tile — a drag
    needs a mouse), then save. It is the person's own, stored on their row.
--}}
@section('content')
    @php
        $widgets = $board['widgets'];
        $zones = $board['zones'];
        $span = [4 => 'lg:col-span-4', 6 => 'lg:col-span-6', 8 => 'lg:col-span-8', 12 => 'lg:col-span-12'];
        $anyShown = collect($zones)->flatten()->isNotEmpty();
        $meta = collect($widgets)->map(fn ($w) => ['title' => $w['title'], 'icon' => $w['icon']])->all();
        // Icons for the tiles, drawn once here and cloned by key — the board
        // is Alpine, and Blade cannot draw an <x-icon> for a key it does not know yet.
        $tileIcons = collect($widgets)->pluck('icon')->push('grip-vertical', 'eye-off', 'eye', 'arrow-up', 'arrow-down')->unique()->values();
    @endphp

    <div x-data="dashboardBoard(@js([
            'layout' => $board['layout'],
            'layouts' => \App\Core\Admin\Dashboard::LAYOUTS,
            'zones' => (object) $zones,
            'hidden' => $board['hidden'],
            'meta' => (object) $meta,
         ]))" class="flex flex-col gap-4">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold">{{ __('Hello, :name', ['name' => auth()->user()?->name]) }}</h1>
                <p class="text-sm opacity-60 mt-0.5">{{ now()->translatedFormat('l, j F Y') }}</p>
            </div>
            @if ($widgets)
                <button type="button" class="btn btn-sm gap-1.5" x-show="! arranging" @click="arranging = true">
                    <x-icon name="layout-dashboard" size="sm" /> {{ __('Arrange') }}
                </button>
            @endif
        </div>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif

        {{-- ─── Arranging ───────────────────────────────────────────── --}}
        @if ($widgets)
            <div class="hidden">
                @foreach ($tileIcons as $tileIcon)
                    <span data-tile-icon="{{ $tileIcon }}"><x-icon :name="$tileIcon" size="sm" /></span>
                @endforeach
            </div>

            <form method="POST" action="{{ route('admin.dashboard.layout') }}" x-show="arranging" x-cloak
                  class="card bg-base-100 border border-base-300">
                @csrf
                <input type="hidden" name="layout" :value="layout">
                <template x-for="zone in zoneNames()" :key="'in-' + zone">
                    <div>
                        <template x-for="key in zones[zone]" :key="'in-' + zone + key">
                            <input type="hidden" :name="'zones[' + zone + '][]'" :value="key">
                        </template>
                    </div>
                </template>
                <template x-for="key in hidden" :key="'h-' + key">
                    <input type="hidden" name="hidden[]" :value="key">
                </template>

                <div class="card-body gap-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <p class="text-sm opacity-70 max-w-2xl">{{ __('Choose the columns, then drag the widgets where you want them — into the band across the top, into a column, or down into the hidden ones. This is your own dashboard; nobody else\'s changes.') }}</p>
                        <div class="flex gap-2">
                            <button type="button" class="btn btn-ghost btn-sm" @click="arranging = false">{{ __('Cancel') }}</button>
                            <button class="btn btn-primary btn-sm">{{ __('Save') }}</button>
                        </div>
                    </div>

                    {{-- The columns, each drawn as what it looks like. --}}
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm opacity-70 mr-1">{{ __('Columns') }}:</span>
                        <template x-for="(widths, name) in layouts" :key="name">
                            <button type="button" @click="setLayout(name)"
                                    class="btn btn-sm h-auto py-1.5 px-2"
                                    :class="layout === name ? 'btn-primary' : 'btn-ghost border-base-300'"
                                    :title="widths.map(w => fraction(w)).join(' + ')">
                                <span class="flex gap-0.5 w-16 h-6">
                                    <template x-for="(w, i) in widths" :key="i">
                                        <span class="h-full rounded-[3px]" :class="layout === name ? 'bg-primary-content/70' : 'bg-base-content/25'" :style="'flex:' + w"></span>
                                    </template>
                                </span>
                            </button>
                        </template>
                    </div>

                    {{-- The band across the top. --}}
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide opacity-50 mb-1">{{ __('Across the whole width') }}</div>
                        <div class="rounded-box border-2 border-dashed border-base-300 p-2 min-h-14 flex flex-col gap-2"
                             :class="over === 'top' && 'border-primary bg-primary/5'"
                             @dragover.prevent="dragOver('top', $event)" @dragleave="over = null" @drop.prevent="dropAt('top')">
                            <template x-for="(key, index) in zones.top" :key="'top-' + key">
                                <div x-html="tile(key, 'top', index)"></div>
                            </template>
                            <p class="text-xs opacity-40 text-center py-2" x-show="zones.top.length === 0">{{ __('Drag a widget here to stretch it across the page') }}</p>
                        </div>
                    </div>

                    {{-- The columns. --}}
                    <div class="grid gap-3 grid-cols-1" :style="window.innerWidth >= 1024 ? 'grid-template-columns:' + layouts[layout].map(w => w + 'fr').join(' ') : ''">
                        <template x-for="(w, i) in layouts[layout]" :key="layout + i">
                            <div>
                                <div class="text-xs font-semibold uppercase tracking-wide opacity-50 mb-1" x-text="@js(__('Column')) + ' ' + (i + 1) + ' · ' + fraction(w)"></div>
                                <div class="rounded-box border-2 border-dashed border-base-300 p-2 min-h-32 flex flex-col gap-2"
                                     :class="over === 'c' + i && 'border-primary bg-primary/5'"
                                     @dragover.prevent="dragOver('c' + i, $event)" @dragleave="over = null" @drop.prevent="dropAt('c' + i)">
                                    <template x-for="(key, index) in zones['c' + i]" :key="'c' + i + '-' + key">
                                        <div x-html="tile(key, 'c' + i, index)"></div>
                                    </template>
                                    <p class="text-xs opacity-40 text-center py-6" x-show="zones['c' + i].length === 0">{{ __('Empty column') }}</p>
                                </div>
                            </div>
                        </template>
                    </div>

                    {{-- The tray of hidden ones. --}}
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide opacity-50 mb-1">{{ __('Hidden') }}</div>
                        <div class="rounded-box border-2 border-dashed border-base-300 p-2 min-h-14 flex flex-wrap gap-2"
                             :class="over === 'hidden' && 'border-primary bg-primary/5'"
                             @dragover.prevent="dragOver('hidden', $event)" @dragleave="over = null" @drop.prevent="dropAt('hidden')">
                            <template x-for="(key, index) in hidden" :key="'hid-' + key">
                                <div x-html="tile(key, 'hidden', index)"></div>
                            </template>
                            <p class="text-xs opacity-40 w-full text-center py-2" x-show="hidden.length === 0">{{ __('Drag a widget here to hide it') }}</p>
                        </div>
                    </div>

                    <div>
                        <button type="submit" name="reset" value="1" class="btn btn-ghost btn-sm gap-1.5"
                                data-confirm="{{ __('Show every widget again, in the order they came?') }}">
                            <x-icon name="rotate-ccw" size="sm" /> {{ __('Back to the original layout') }}
                        </button>
                    </div>
                </div>
            </form>
        @endif

        {{-- ─── The dashboard itself ────────────────────────────────── --}}
        @if ($anyShown)
            <div class="flex flex-col gap-4" x-show="! arranging">
                @foreach ($zones['top'] as $widgetKey)
                    @include('admin.dashboard._widget', ['dashWidget' => $widgets[$widgetKey]])
                @endforeach

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 items-start">
                    @foreach ($board['widths'] as $columnIndex => $columnWidth)
                        <div class="flex flex-col gap-4 min-w-0 {{ $span[$columnWidth] ?? 'lg:col-span-12' }}">
                            @foreach ($zones['c'.$columnIndex] ?? [] as $widgetKey)
                                @include('admin.dashboard._widget', ['dashWidget' => $widgets[$widgetKey]])
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @elseif ($widgets)
            <div class="card bg-base-100 border border-base-300" x-show="! arranging">
                <div class="card-body items-center text-center py-12 gap-2">
                    <x-icon name="eye-off" size="xl" class="opacity-30" />
                    <p class="text-sm opacity-70">{{ __('Every widget is hidden.') }}</p>
                    <button type="button" class="btn btn-sm" @click="arranging = true">{{ __('Arrange') }}</button>
                </div>
            </div>
        @else
            <div class="card bg-base-100 border border-base-300">
                <div class="card-body items-center text-center py-12">
                    <p class="text-sm opacity-70">{{ __('Nothing to show here for your permissions.') }}</p>
                </div>
            </div>
        @endif
    </div>

    <script nonce="{{ csp_nonce() }}">
        function dashboardBoard(initial) {
            const words = @js([
                'up' => __('Move up'),
                'down' => __('Move down'),
                'hide' => __('Hide'),
                'show' => __('Show'),
                'move' => __('Move to'),
                'top' => __('Across the whole width'),
                'column' => __('Column'),
            ]);
            const icon = (name) => (document.querySelector('[data-tile-icon="' + name + '"]') || {}).innerHTML || '';
            const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

            return {
                arranging: false,
                layout: initial.layout,
                layouts: initial.layouts,
                zones: Object.assign({ top: [], c0: [], c1: [], c2: [] }, initial.zones),
                hidden: initial.hidden,
                meta: initial.meta,
                dragging: null,
                over: null,
                overIndex: null,

                zoneNames() { return ['top', ...this.layouts[this.layout].map((w, i) => 'c' + i)]; },
                fraction(w) { return { 4: '1/3', 6: '1/2', 8: '2/3', 12: '1/1' }[w] || w + '/12'; },

                // Fewer columns: what stood in the ones that went moves into the last one left.
                setLayout(name) {
                    const count = this.layouts[name].length;
                    for (let i = count; i < 3; i++) {
                        this.zones['c' + (count - 1)].push(...this.zones['c' + i]);
                        this.zones['c' + i] = [];
                    }
                    this.layout = name;
                },

                take(key) {
                    for (const zone of Object.keys(this.zones)) {
                        const at = this.zones[zone].indexOf(key);
                        if (at !== -1) { this.zones[zone].splice(at, 1); return; }
                    }
                    const at = this.hidden.indexOf(key);
                    if (at !== -1) this.hidden.splice(at, 1);
                },
                put(key, zone, index) {
                    const list = zone === 'hidden' ? this.hidden : this.zones[zone];
                    list.splice(index === null || index > list.length ? list.length : index, 0, key);
                },
                moveTo(key, zone) { this.take(key); this.put(key, zone, null); },
                shift(key, zone, delta) {
                    const list = this.zones[zone];
                    const at = list.indexOf(key), to = at + delta;
                    if (to < 0 || to >= list.length) return;
                    list.splice(at, 1); list.splice(to, 0, key);
                },

                // Where the pointer is inside a zone decides the index: before
                // the first tile whose middle is below it.
                dragOver(zone, event) {
                    this.over = zone;
                    const tiles = [...event.currentTarget.querySelectorAll('[data-tile]')];
                    const i = tiles.findIndex((t) => { const r = t.getBoundingClientRect(); return event.clientY < r.top + r.height / 2; });
                    this.overIndex = i === -1 ? null : i;
                },
                dropAt(zone) {
                    if (!this.dragging) return;
                    const key = this.dragging;
                    let index = this.overIndex;
                    const list = zone === 'hidden' ? this.hidden : this.zones[zone];
                    const from = list.indexOf(key);
                    this.take(key);
                    if (from !== -1 && index !== null && from < index) index--;
                    this.put(key, zone, index);
                    this.dragging = null; this.over = null; this.overIndex = null;
                },

                tile(key, zone, index) {
                    const m = this.meta[key] || { title: key, icon: 'square' };
                    const isHidden = zone === 'hidden';
                    const options = this.zoneNames().map((z) => '<option value="' + z + '"' + (z === zone ? ' selected' : '') + '>' +
                        esc(z === 'top' ? words.top : words.column + ' ' + (Number(z.slice(1)) + 1)) + '</option>').join('');
                    return '<div data-tile draggable="true" data-key="' + esc(key) + '" ' +
                        'class="flex items-center gap-2 rounded-field bg-base-100 border border-base-300 px-2 py-1.5 cursor-grab active:cursor-grabbing shadow-xs' + (isHidden ? ' opacity-60' : '') + '">' +
                        '<span class="opacity-40">' + icon('grip-vertical') + '</span>' +
                        '<span class="opacity-70">' + icon(m.icon) + '</span>' +
                        '<span class="grow text-sm truncate">' + esc(m.title) + '</span>' +
                        (isHidden ? '' :
                            '<select data-move class="select select-xs w-auto max-w-32" aria-label="' + esc(words.move) + '">' + options + '</select>' +
                            '<button type="button" data-shift="-1" class="btn btn-ghost btn-sm btn-square" title="' + esc(words.up) + '"' + (index === 0 ? ' disabled' : '') + '>' + icon('arrow-up') + '</button>' +
                            '<button type="button" data-shift="1" class="btn btn-ghost btn-sm btn-square" title="' + esc(words.down) + '"' + (index === this.zones[zone].length - 1 ? ' disabled' : '') + '>' + icon('arrow-down') + '</button>') +
                        '<button type="button" data-toggle class="btn btn-ghost btn-sm btn-square" title="' + esc(isHidden ? words.show : words.hide) + '">' + icon(isHidden ? 'eye' : 'eye-off') + '</button>' +
                        '</div>';
                },

                init() {
                    // The tiles are x-html, so their events are delegated from here.
                    this.$el.addEventListener('dragstart', (e) => {
                        const t = e.target.closest('[data-tile]');
                        if (!t) return;
                        this.dragging = t.dataset.key;
                        e.dataTransfer.effectAllowed = 'move';
                        e.dataTransfer.setData('text/plain', t.dataset.key);
                    });
                    this.$el.addEventListener('dragend', () => { this.dragging = null; this.over = null; });
                    this.$el.addEventListener('click', (e) => {
                        const t = e.target.closest('[data-tile]');
                        if (!t) return;
                        const key = t.dataset.key;
                        const zone = Object.keys(this.zones).find((z) => this.zones[z].includes(key)) || 'hidden';
                        const shift = e.target.closest('[data-shift]');
                        if (shift) { this.shift(key, zone, Number(shift.dataset.shift)); return; }
                        if (e.target.closest('[data-toggle]')) {
                            this.moveTo(key, zone === 'hidden' ? 'c' + (this.layouts[this.layout].length - 1) : 'hidden');
                        }
                    });
                    this.$el.addEventListener('change', (e) => {
                        const select = e.target.closest('[data-move]');
                        if (select) this.moveTo(select.closest('[data-tile]').dataset.key, select.value);
                    });
                },
            };
        }
    </script>
@endsection
