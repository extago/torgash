{{--
    The 3D models of one product, on its form ("3D" tab).

    No <form> of its own — it sits inside the product's form, and a nested
    form leaks its inputs into the outer submit. Uploading, a poster and
    removing go straight to the JSON endpoints; titles and order ride along
    with the product's Save as models3d[<id>][title|position].

    The poster can be the model itself: the admin turns it in the preview and
    "Use this view as the poster" sends a picture of the viewer's canvas. A
    model uploaded without one gets its first view that way by itself.

    Variables are named distinctively on purpose: an included view inherits
    the caller's whole scope (docs/LESSONS.md).
--}}
@once
    @push('scripts')
        <script nonce="{{ csp_nonce() }}">
            document.addEventListener('alpine:init', () => {
                Alpine.data('torgashModels3d', (config) => ({
                    base: config.base,
                    items: config.items,
                    busy: false,
                    errors: [],
                    confirming: null,
                    previewing: null,
                    dragIndex: null,

                    async send(path, options = {}) {
                        this.busy = true;
                        this.errors = [];
                        try {
                            const response = await fetch(this.base + path, {
                                method: options.method || 'POST',
                                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' },
                                body: options.body,
                            });
                            const data = await response.json().catch(() => null);
                            if (!data) {
                                this.errors = [response.status === 413 ? config.tooBig : 'HTTP ' + response.status];
                                return null;
                            }
                            // Titles typed and not yet saved survive a redraw.
                            const typed = Object.fromEntries(this.items.map((item) => [item.id, item.title]));
                            this.items = (data.items || this.items).map((item) => item.id in typed ? Object.assign(item, { title: typed[item.id] }) : item);
                            this.errors = data.errors || [];
                            return data;
                        } catch (e) {
                            this.errors = [String(e)];
                            return null;
                        } finally {
                            this.busy = false;
                            this.confirming = null;
                        }
                    },

                    viewer() {
                        if (!customElements.get('model-viewer')) import(config.viewer);
                    },

                    async upload(files) {
                        const file = files && files[0];
                        if (!file) return;
                        if (file.size > config.maxBytes) {
                            this.errors = [config.tooBigSetting];
                            return;
                        }
                        const body = new FormData();
                        body.append('model', file);
                        const data = await this.send('', { body });
                        // A new model opens in the preview and, once drawn,
                        // gives itself a poster from its first view.
                        if (data && data.added) this.preview(this.items.find((item) => item.id === data.added), true);
                    },

                    preview(item, autoPoster = false) {
                        if (!item) return;
                        this.viewer();
                        this.previewing = item.id;
                        if (!autoPoster || item.poster) return;
                        this.$nextTick(() => {
                            const mv = this.$root.querySelector('model-viewer[data-model="' + item.id + '"]');
                            mv?.addEventListener('load', () => setTimeout(() => this.posterFromView(item), 600), { once: true });
                        });
                    },

                    async posterFromView(item) {
                        const mv = this.$root.querySelector('model-viewer[data-model="' + item.id + '"]');
                        if (!mv || !mv.loaded) return;
                        const blob = await mv.toBlob({ mimeType: 'image/png', idealAspect: true });
                        const body = new FormData();
                        body.append('poster', new File([blob], 'poster-' + item.id + '.png', { type: 'image/png' }));
                        await this.send('/' + item.id + '/poster', { body });
                    },

                    posterFile(item, files) {
                        if (!files || !files.length) return;
                        const body = new FormData();
                        body.append('poster', files[0]);
                        return this.send('/' + item.id + '/poster', { body });
                    },

                    destroy(item) {
                        if (this.previewing === item.id) this.previewing = null;
                        return this.send('/' + item.id, { method: 'DELETE' });
                    },

                    drop(target) {
                        if (this.dragIndex === null || this.dragIndex === target) return;
                        const moved = this.items.splice(this.dragIndex, 1)[0];
                        this.items.splice(target, 0, moved);
                        this.dragIndex = null;
                    },
                }));
            });
        </script>
    @endpush
@endonce

<section class="card bg-base-100 border border-base-300">
    <div class="card-body gap-4">
        <h3 class="font-semibold">{{ __('3D model') }}</h3>

        @if (! $model3dProduct->exists)
            <p class="text-sm opacity-60">{{ __('A 3D model can be added once it is saved.') }}</p>
        @else
            @php
                $model3dService = app(\Torgash\Model3d\Models3d::class);
                $model3dItems = \Torgash\Model3d\Http\Admin\ModelsController::items($model3dService->of('product', (int) $model3dProduct->id));
                $model3dMaxMb = (int) ($model3dService->maxBytes() / 1048576);
            @endphp
            <div x-data="torgashModels3d({
                    base: {{ \Illuminate\Support\Js::from(url('/admin/model3d/product/'.$model3dProduct->id)) }},
                    items: {{ \Illuminate\Support\Js::from($model3dItems) }},
                    viewer: {{ \Illuminate\Support\Js::from(module_asset('torgash.model3d', 'vendor/model-viewer/model-viewer.min.js')) }},
                    maxBytes: {{ $model3dService->maxBytes() }},
                    tooBig: {{ \Illuminate\Support\Js::from(__('The file is bigger than the server accepts.')) }},
                    tooBigSetting: {{ \Illuminate\Support\Js::from(__('The shop takes models up to :max MB (the 3D view module\'s settings).', ['max' => $model3dMaxMb])) }},
                 })" class="flex flex-col gap-3">

                <p class="text-sm opacity-60">
                    {{ __('A model in binary glTF (.glb) — one file with its textures inside; most 3D programs export it. It stands after the photos; the visitor turns it and zooms in, and the viewer loads only when the model is opened.') }}
                </p>

                <div class="flex flex-wrap items-center gap-2">
                    <label class="btn btn-primary gap-1.5 shrink-0" :class="busy && 'btn-disabled'">
                        <span x-show="busy" x-cloak class="loading loading-spinner loading-xs"></span>
                        <x-icon name="upload" size="sm" x-show="!busy" /> {{ __('Upload a model') }}
                        <input type="file" accept=".glb,model/gltf-binary" class="hidden" :disabled="busy"
                               @change="upload($event.target.files); $event.target.value = ''">
                    </label>
                    <span class="text-xs opacity-60">{{ __('.glb, up to :max MB.', ['max' => $model3dMaxMb]) }}</span>
                </div>

                <template x-if="errors.length">
                    <div class="alert alert-warning text-sm flex-col items-start gap-1 py-2">
                        <template x-for="(e, i) in errors" :key="i"><div x-text="e"></div></template>
                    </div>
                </template>

                <div class="flex flex-col gap-2">
                    <template x-for="(item, i) in items" :key="item.id">
                        <div class="flex flex-col gap-2 rounded-box border border-base-300 bg-base-100 p-2"
                             :class="dragIndex === i ? 'opacity-40' : ''"
                             :draggable="previewing !== item.id" @dragstart="dragIndex = i" @dragend="dragIndex = null"
                             @dragover.prevent @drop.prevent="drop(i)">
                            <div class="flex gap-3 items-start">
                                <span class="self-center cursor-move opacity-40" aria-hidden="true"><x-icon name="grip-vertical" size="sm" /></span>

                                <button type="button" class="relative shrink-0 w-28 h-28 rounded-lg overflow-hidden bg-base-200 border border-base-300 group"
                                        @click="previewing === item.id ? previewing = null : preview(item)" title="{{ __('Turn it in the preview') }}">
                                    <img x-show="item.poster" :src="item.poster" alt="" class="absolute inset-0 h-full w-full object-contain" draggable="false">
                                    <span class="absolute inset-0 flex items-center justify-center opacity-70 group-hover:opacity-100">
                                        <x-icon name="rotate-3d" size="xl" class="bg-base-100/80 rounded-full p-0.5" />
                                    </span>
                                </button>

                                <div class="grow min-w-0 flex flex-col gap-1.5">
                                    <div class="flex items-center gap-2 text-xs min-w-0">
                                        <span class="badge badge-sm badge-ghost shrink-0">GLB</span>
                                        <a :href="item.src" target="_blank" rel="noopener" class="link link-hover opacity-60 truncate" x-text="item.name"></a>
                                        <span class="opacity-60 tabular-nums shrink-0" x-text="item.size"></span>
                                        <span x-show="!item.poster" x-cloak class="badge badge-sm badge-warning shrink-0">{{ __('No poster') }}</span>
                                    </div>
                                    <input type="text" x-model="item.title" :name="'models3d[' + item.id + '][title]'" maxlength="255" aria-label="{{ __('Title') }}"
                                           class="input w-full" :placeholder="item.hint || @js(__('Title — the product\'s name if empty'))">
                                    <input type="hidden" :name="'models3d[' + item.id + '][position]'" :value="i + 1">
                                    <div class="flex flex-wrap gap-1">
                                        <label class="btn btn-sm gap-1.5" :class="busy && 'btn-disabled'">
                                            <x-icon name="image-up" size="sm" /> {{ __('Upload a poster') }}
                                            <input type="file" accept="image/*" class="hidden" @change="posterFile(item, $event.target.files); $event.target.value = ''">
                                        </label>
                                    </div>
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
                                            <button type="button" class="btn btn-error btn-sm" @click="destroy(item)">{{ __('Remove') }}</button>
                                            <button type="button" class="btn btn-ghost btn-sm" @click="confirming = null">{{ __('Cancel') }}</button>
                                        </span>
                                    </template>
                                </div>
                            </div>

                            {{-- The preview: turn the model, then keep that view as the poster. --}}
                            <template x-if="previewing === item.id">
                                <div class="flex flex-col gap-2">
                                    <div class="relative h-96 rounded-lg overflow-hidden border border-base-300 bg-base-100">
                                        <model-viewer :data-model="item.id" :src="item.src" camera-controls shadow-intensity="1"
                                                      class="absolute inset-0 h-full w-full"></model-viewer>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <button type="button" class="btn btn-sm gap-1.5" :disabled="busy" @click="posterFromView(item)">
                                            <x-icon name="camera" size="sm" /> {{ __('Use this view as the poster') }}
                                        </button>
                                        <span class="text-xs opacity-60">{{ __('Turn the model the way it should be seen first.') }}</span>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>

                <p x-show="items.length > 1" x-cloak class="text-xs opacity-50">{{ __('Drag to change the order; titles and order are saved with the rest of the form.') }}</p>
            </div>
        @endif
    </div>
</section>
