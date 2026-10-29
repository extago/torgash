{{--
    The documents of one product, on its form ("Documentation" tab).

    No <form> of its own — it sits inside the product's form, and a nested
    form leaks its inputs into the outer submit. Uploading, adding a link and
    removing go straight to the JSON endpoints; titles, who sees each and the
    order ride along with the product's Save as documents[<id>][…].

    Variables are named distinctively on purpose: an included view inherits
    the caller's whole scope (docs/LESSONS.md).
--}}
@once
    @push('scripts')
        <script nonce="{{ csp_nonce() }}">
            document.addEventListener('alpine:init', () => {
                Alpine.data('torgashDocuments', (config) => ({
                    base: config.base,
                    items: config.items,
                    typed: '',
                    busy: false,
                    errors: [],
                    confirming: null,

                    async send(path, options = {}) {
                        this.busy = true;
                        this.errors = [];
                        try {
                            const response = await fetch(this.base + path, {
                                method: options.method || 'POST',
                                headers: Object.assign(
                                    { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' },
                                    options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' },
                                ),
                                body: options.body,
                            });
                            const data = await response.json().catch(() => null);
                            if (!data) {
                                this.errors = [response.status === 413 ? config.tooBig : 'HTTP ' + response.status];
                                return false;
                            }
                            // What was typed and not yet saved survives a redraw.
                            const typed = Object.fromEntries(this.items.map((item) => [item.id, { title: item.title, access: item.access }]));
                            this.items = (data.items || this.items).map((item) => item.id in typed ? Object.assign(item, typed[item.id]) : item);
                            this.errors = data.errors || [];
                            return response.ok;
                        } catch (e) {
                            this.errors = [String(e)];
                            return false;
                        } finally {
                            this.busy = false;
                            this.confirming = null;
                        }
                    },

                    upload(files) {
                        if (!files || !files.length) return;
                        const body = new FormData();
                        Array.from(files).forEach((file) => body.append('files[]', file));
                        return this.send('/files', { body });
                    },

                    async addLink() {
                        const url = this.typed.trim();
                        if (!url) return;
                        if (await this.send('/link', { body: JSON.stringify({ url }) })) this.typed = '';
                    },

                    destroy(item) {
                        return this.send('/' + item.id, { method: 'DELETE' });
                    },

                    /* By a drag (x-sort — Alpine Sort, which works under a finger;
                       PLAN §62) or by the arrows a finger gets. Named by the
                       item's id, not its index: Alpine Sort drops a key of 0 as "none". */
                    move(id, to) {
                        const from = this.items.findIndex((item) => item.id === id);
                        if (from === -1 || from === to || to < 0 || to >= this.items.length) return;
                        const moved = this.items.splice(from, 1)[0];
                        this.items.splice(to, 0, moved);
                    },
                }));
            });
        </script>
    @endpush
@endonce

<section class="card bg-base-100 border border-base-300">
    <div class="card-body gap-4">
        <h3 class="font-semibold">{{ __('Documentation') }}</h3>

        @if (! $documentsProduct->exists)
            <p class="text-sm opacity-60">{{ __('Documents can be added once it is saved.') }}</p>
        @else
            @php
                $documentsService = app(\Torgash\Attachments\Documents::class);
                $documentsItems = \Torgash\Attachments\Http\Admin\DocumentsController::items($documentsService->of('product', (int) $documentsProduct->id));
                $documentsMaxMb = (int) ($documentsService->maxBytes() / 1048576);
            @endphp
            <div x-data="torgashDocuments({
                    base: {{ \Illuminate\Support\Js::from(url('/admin/attachments/product/'.$documentsProduct->id)) }},
                    items: {{ \Illuminate\Support\Js::from($documentsItems) }},
                    tooBig: {{ \Illuminate\Support\Js::from(__('The file is bigger than the server accepts.')) }},
                 })" class="flex flex-col gap-3">

                <p class="text-sm opacity-60">
                    {{ __('A specification sheet, a manual, drawings, certificates — files of your own, or links to them on the maker\'s site. They stand in the product page\'s "Documentation" section, in this order.') }}
                </p>

                <div class="flex flex-wrap gap-2 items-start">
                    {{-- A label, so the button IS the file picker. --}}
                    <label class="btn btn-primary gap-1.5 shrink-0" :class="busy && 'btn-disabled'">
                        <span x-show="busy" x-cloak class="loading loading-spinner loading-xs"></span>
                        <x-icon name="upload" size="sm" x-show="!busy" /> {{ __('Upload files') }}
                        <input type="file" multiple class="hidden" :disabled="busy"
                               accept="{{ collect($documentsService->types())->map(fn ($t) => '.'.$t)->implode(',') }}"
                               @change="upload($event.target.files); $event.target.value = ''">
                    </label>
                    {{-- Enter here would press the product form's own Save: it adds instead. --}}
                    <div class="join grow min-w-64">
                        <input type="url" x-model="typed" @keydown.enter.prevent="addLink()" aria-label="{{ __('Link to a document') }}" class="input join-item grow"
                               placeholder="https://…/manual.pdf">
                        <button type="button" class="btn join-item gap-1.5" :disabled="busy || !typed.trim()" @click="addLink()">
                            <x-icon name="link" size="sm" /> {{ __('Add a link') }}
                        </button>
                    </div>
                </div>
                <p class="text-xs opacity-60 -mt-1">
                    {{ __('Up to :max MB each: :types.', ['max' => $documentsMaxMb, 'types' => strtoupper(implode(', ', $documentsService->types()))]) }}
                </p>

                <template x-if="errors.length">
                    <div class="alert alert-warning text-sm flex-col items-start gap-1 py-2">
                        <template x-for="(e, i) in errors" :key="i"><div x-text="e"></div></template>
                    </div>
                </template>

                <div class="flex flex-col gap-2" x-sort="move($item, $position)" x-sort:config="{ delay: 150, delayOnTouchOnly: true }">
                    <template x-for="(item, i) in items" :key="item.id">
                        <div class="flex gap-3 items-center rounded-box border border-base-300 bg-base-100 p-2" x-sort:item="item.id">
                            {{-- The grip, and under a finger the arrows beneath it. --}}
                            <span class="flex flex-col items-center shrink-0">
                                <span class="cursor-move opacity-40" aria-hidden="true" x-sort:handle><x-icon name="grip-vertical" size="sm" /></span>
                                <button type="button" class="btn btn-ghost btn-sm btn-square hidden pointer-coarse:inline-flex" :disabled="i === 0"
                                        title="{{ __('Move earlier') }}" aria-label="{{ __('Move earlier') }}" @click="move(item.id, i - 1)"><x-icon name="arrow-up" size="sm" /></button>
                                <button type="button" class="btn btn-ghost btn-sm btn-square hidden pointer-coarse:inline-flex" :disabled="i === items.length - 1"
                                        title="{{ __('Move later') }}" aria-label="{{ __('Move later') }}" @click="move(item.id, i + 1)"><x-icon name="arrow-down" size="sm" /></button>
                            </span>

                            <div class="grow min-w-0 flex flex-col gap-1.5">
                                <div class="flex items-center gap-2 text-xs min-w-0">
                                    <span class="badge badge-sm badge-ghost shrink-0" x-text="item.link ? @js(__('Link')) : item.details.split(' · ')[0]"></span>
                                    <a :href="item.href" target="_blank" rel="noopener noreferrer" class="link link-hover opacity-60 truncate" x-text="item.name"></a>
                                    <span x-show="!item.link && item.details.includes(' · ')" class="opacity-60 tabular-nums shrink-0" x-text="item.details.split(' · ')[1]"></span>
                                    <span x-show="item.downloads > 0" x-cloak class="opacity-50 tabular-nums shrink-0 inline-flex items-center gap-0.5"
                                          title="{{ __('Opened from the shop') }}">
                                        <x-icon name="download" size="sm" /> <span x-text="item.downloads"></span>
                                    </span>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <input type="text" x-model="item.title" :name="'documents[' + item.id + '][title]'" maxlength="255" aria-label="{{ __('Document title') }}"
                                           class="input grow min-w-48" :placeholder="item.hint">
                                    <select x-model="item.access" :name="'documents[' + item.id + '][access]'" class="select w-auto"
                                            title="{{ __('Who sees it') }}">
                                        <option value="all">{{ __('Everyone') }}</option>
                                        <option value="account">{{ __('Signed-in customers') }}</option>
                                    </select>
                                </div>
                                <input type="hidden" :name="'documents[' + item.id + '][position]'" :value="i + 1">
                            </div>

                            <div class="shrink-0 flex items-center gap-1">
                                <template x-if="confirming !== item.id">
                                    <button type="button" class="btn btn-ghost btn-sm btn-square text-error" :disabled="busy"
                                            title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}" @click="confirming = item.id">
                                        <x-icon name="trash-2" />
                                    </button>
                                </template>
                                <template x-if="confirming === item.id">
                                    <span class="flex items-center gap-1 text-sm">
                                        <button type="button" class="btn btn-ghost btn-sm text-error" @click="destroy(item)">{{ __('Remove') }}</button>
                                        <button type="button" class="btn btn-ghost btn-sm" @click="confirming = null">{{ __('Cancel') }}</button>
                                    </span>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>

                <p x-show="items.length > 1" x-cloak class="text-xs opacity-50">
                    <span class="pointer-coarse:hidden">{{ __('Drag to change the order; titles and order are saved with the rest of the form.') }}</span>
                    <span class="hidden pointer-coarse:inline">{{ __('The arrows change the order; titles and order are saved with the rest of the form.') }}</span>
                </p>
            </div>
        @endif
    </div>
</section>
