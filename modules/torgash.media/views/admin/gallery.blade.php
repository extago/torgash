{{--
    Gallery editor for any entity registered in MediaOwners.

        @include('torgash.media::admin.gallery', ['owner' => 'product', 'model' => $product])

    Renders no <form> of its own on purpose — it sits inside the owning
    entity's form, and a nested form would leak its _method into the outer
    submit. Uploads, deletes and rotation go straight to the JSON endpoints;
    captions and order ride along with the outer form as
    media[<id>][alt|title|sort] and are applied by Gallery::applyFormInput().
--}}
@once
    @push('head')
        <link rel="stylesheet" data-photoswipe
              href="{{ module_asset('torgash.media', 'vendor/photoswipe.css') }}">
    @endpush
    @push('scripts')
        <script nonce="{{ csp_nonce() }}">
            document.addEventListener('alpine:init', () => {
                Alpine.data('torgashGallery', (config) => ({
                    base: config.base,
                    assets: config.assets,
                    field: config.field,
                    items: config.items,
                    allowRemote: config.allowRemote,
                    maxSide: config.maxSide,
                    busy: false,
                    errors: [],
                    urlOpen: false,
                    urls: '',
                    dropZone: false,
                    confirming: null,

                    csrf() {
                        return document.querySelector('meta[name="csrf-token"]').content;
                    },

                    async send(path, options = {}) {
                        this.busy = true;
                        this.errors = [];
                        try {
                            const response = await fetch(this.base + path, {
                                method: options.method || 'POST',
                                headers: Object.assign(
                                    { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' },
                                    options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' },
                                ),
                                body: options.body,
                            });
                            const data = await response.json().catch(() => null);
                            if (!data) {
                                this.errors = ['HTTP ' + response.status];
                                return;
                            }
                            this.items = data.items || this.items;
                            this.errors = data.errors || [];
                            if (typeof data.allow_remote === 'boolean') {
                                this.allowRemote = data.allow_remote;
                            }
                        } catch (e) {
                            this.errors = [String(e)];
                        } finally {
                            this.busy = false;
                            this.confirming = null;
                        }
                    },

                    async upload(fileList) {
                        const files = Array.from(fileList || []);
                        if (!files.length) return;
                        this.busy = true;
                        const body = new FormData();
                        for (const file of files) body.append('images[]', await this.shrink(file), file.name);
                        return this.send('/upload', { body });
                    },

                    /* A photo straight from a phone's camera is 3–8 MB, and over
                       a mobile connection sending it is the slow part. Whatever
                       is larger than the stored original would be anyway (the
                       Media setting "Max dimension") is drawn smaller in the
                       browser first: the server would reduce it to that size on
                       arrival, so nothing is lost — and nothing is touched when
                       the shop keeps originals untouched (maxSide 0). JPEG and
                       PNG from a megabyte up; what the browser cannot decode,
                       or would not make smaller, goes as it is. The orientation
                       a camera writes is applied, so the pixels arrive upright. */
                    async shrink(file) {
                        if (!this.maxSide || !/^image\/(jpeg|png)$/.test(file.type) || file.size < 1048576) return file;
                        try {
                            const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
                            const scale = this.maxSide / Math.max(bitmap.width, bitmap.height);
                            if (scale >= 1) { bitmap.close(); return file; }
                            const canvas = document.createElement('canvas');
                            canvas.width = Math.round(bitmap.width * scale);
                            canvas.height = Math.round(bitmap.height * scale);
                            canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
                            bitmap.close();
                            const blob = await new Promise((resolve) => canvas.toBlob(resolve, file.type, 0.9));
                            return blob && blob.size < file.size ? new File([blob], file.name, { type: file.type }) : file;
                        } catch (e) {
                            return file;
                        }
                    },

                    /* By a drag (x-sort — Alpine Sort, which works under a finger,
                       by the picture) or by the arrows a finger gets (PLAN §62).
                       Named by the photo's id, not its index: Alpine Sort drops a
                       key of 0 as "none". */
                    move(id, to) {
                        const from = this.items.findIndex((item) => item.id === id);
                        if (from === -1 || from === to || to < 0 || to >= this.items.length) return;
                        const moved = this.items.splice(from, 1)[0];
                        this.items.splice(to, 0, moved);
                    },

                    addFromUrl() {
                        const urls = this.urls.trim();
                        if (!urls) return;
                        return this.send('/from-url', { body: JSON.stringify({ urls }) })
                            .then(() => { if (!this.errors.length) { this.urls = ''; this.urlOpen = false; } });
                    },

                    rotate(item, degrees) {
                        return this.send('/' + item.id + '/rotate', { body: JSON.stringify({ degrees }) })
                            .then(() => this.bustCache());
                    },

                    destroy(item) {
                        return this.send('/' + item.id, { method: 'DELETE' });
                    },

                    destroyAll() {
                        return this.send('', { method: 'DELETE' });
                    },

                    /* Rotation rewrites the same filename, so the browser would keep showing the stale bytes. */
                    bustCache() {
                        const stamp = Date.now();
                        this.items = this.items.map((item) => Object.assign({}, item, {
                            thumb: item.thumb.split('?')[0] + '?v=' + stamp,
                            url: item.url.split('?')[0] + '?v=' + stamp,
                        }));
                    },

                    /* The first photo is the main one; the order is saved with
                       the form (the hidden `sort` inputs), like a drag. */
                    makeMain(index) {
                        if (index === 0) return;
                        const moved = this.items.splice(index, 1)[0];
                        this.items.unshift(moved);
                    },

                    /* PhotoSwipe is pulled in on first preview, not on page load —
                       most visits to this screen never open it. */
                    async preview(index) {
                        const { openLightbox } = await import(this.assets + '/lightbox.mjs');
                        await openLightbox({ items: this.items, index, base: this.assets });
                    },
                }));
            });
        </script>
    @endpush
@endonce

@php
    // `$galleryField`, not `$field`: a Blade @include inherits EVERY variable in
    // the caller's scope, so a partial that defaults an ordinarily-named one
    // silently takes whatever the caller happened to leave lying around. The
    // product form loops `@foreach ([...] as $field => $label)` over its
    // dimensions two hundred lines earlier, and `$field` was still 'height'
    // when it reached this include — so every input here was named
    // `height[12][alt]`. The gallery's alt and title were therefore never
    // saved, and posting the form sent `height` as an ARRAY, which failed
    // validation and then made the re-rendered form throw a 500 on
    // `old('height')`. One leaked variable, three symptoms, no error anywhere
    // near the cause.
    $field = $galleryField ?? 'media';
    $collection = $collection ?? 'gallery';
    // One picture (a cover): a new upload replaces it, and there is no order,
    // no "main" and no "delete all" — see MediaOwners::isSingle().
    $singlePicture = app(\Torgash\Media\MediaOwners::class)->isSingle($owner);
    $initialItems = $model->getMedia($collection)->map(fn ($media) => [
        'id' => $media->id,
        'url' => $media->getUrl(),
        'thumb' => \Torgash\Media\Gallery::thumbUrl($media, 240),
        'name' => $media->file_name,
        'alt' => \Torgash\Media\Gallery::alt($media),
        'title' => \Torgash\Media\Gallery::title($media),
        'size' => $media->size,
    ] + \Torgash\Media\Gallery::dimensions($media))->values();

    // Directory this module's assets are served from — lightbox.mjs resolves
    // ./vendor/* against it.
    $assetBase = rtrim(dirname(route('module.asset', ['module' => 'torgash.media', 'path' => 'x'])), '/');
@endphp

<div x-data="torgashGallery({
        base: {{ \Illuminate\Support\Js::from(url("/admin/media/{$owner}/{$model->getKey()}")) }},
        assets: {{ \Illuminate\Support\Js::from($assetBase) }},
        field: {{ \Illuminate\Support\Js::from($field) }},
        items: {{ \Illuminate\Support\Js::from($initialItems) }},
        allowRemote: {{ \Torgash\Media\ImageSettings::allowRemote() ? 'true' : 'false' }},
        maxSide: {{ \Torgash\Media\ImageSettings::originalUntouched() ? 0 : (int) \Torgash\Media\ImageSettings::maxDimension() }},
     })" class="flex flex-col gap-3">

    <div class="flex items-center gap-2 flex-wrap">
        <div class="text-sm opacity-60" x-show="items.length" x-cloak @if ($singlePicture) hidden @endif>
            {{-- Under a finger dragging is unreliable (PLAN §62): there the arrows do it. --}}
            <span class="pointer-coarse:hidden">{{ __('The first photo is the main one; drag to change the order.') }}</span>
            <span class="hidden pointer-coarse:inline">{{ __('The first photo is the main one; the arrows change the order.') }}</span>
        </div>
        <div class="ml-auto flex items-center gap-2">
            <span x-show="busy" x-cloak class="loading loading-spinner loading-sm"></span>
            <button type="button" class="btn btn-sm gap-1.5" @click="urlOpen = !urlOpen" x-show="allowRemote">
                <x-icon name="link" size="sm" /> {{ __('Add by URL') }}
            </button>
            @unless ($singlePicture)
            <template x-if="confirming !== 'all'">
                <button type="button" class="btn btn-ghost btn-sm gap-1.5 text-error" x-show="items.length"
                        @click="confirming = 'all'"><x-icon name="trash-2" size="sm" /> {{ __('Delete all') }}</button>
            </template>
            <template x-if="confirming === 'all'">
                <span class="flex items-center gap-2 text-sm">
                    {{ __('Delete all photos?') }}
                    <button type="button" class="btn btn-error btn-sm" @click="destroyAll()">{{ __('Yes') }}</button>
                    <button type="button" class="btn btn-ghost btn-sm" @click="confirming = null">{{ __('No') }}</button>
                </span>
            </template>
            @endunless
        </div>
    </div>

    <template x-if="errors.length">
        <div class="alert alert-error text-sm flex-col items-start gap-1 py-2">
            <template x-for="(e, i) in errors" :key="i"><div x-text="e"></div></template>
        </div>
    </template>

    <div x-show="urlOpen" x-cloak class="flex flex-col gap-2 p-3 rounded-box bg-base-200/60 border border-base-300">
        <div class="text-xs opacity-60">{{ $singlePicture ? __('The image URL.') : __('One image URL per line.') }}</div>
        <textarea x-model="urls" rows="3" aria-label="{{ $singlePicture ? __('The image URL.') : __('One image URL per line.') }}" class="textarea textarea-bordered w-full font-mono text-sm"
                  placeholder="https://example.com/photo.jpg"></textarea>
        <div class="flex gap-2">
            <button type="button" class="btn btn-primary btn-sm" :disabled="busy || !urls.trim()" @click="addFromUrl()">
                {{ __('Download') }}
            </button>
            <button type="button" class="btn btn-ghost btn-sm" @click="urlOpen = false; urls = ''">{{ __('Cancel') }}</button>
        </div>
    </div>

    {{-- On a phone one photo per row, the tools under it (app.css, `admin-gallery`). --}}
    <div class="admin-gallery grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3"
         @unless ($singlePicture) x-sort="move($item, $position)" x-sort:config="{ delay: 150, delayOnTouchOnly: true }" @endunless>
        <template x-for="(item, i) in items" :key="item.id">
            <div class="admin-gallery-item rounded-box border border-base-300 bg-base-100 overflow-hidden flex flex-col" x-sort:item="item.id">

                {{-- The picture is what is dragged; the fields and buttons stay theirs. --}}
                <div class="admin-gallery-pic relative bg-base-200 aspect-square cursor-move group" x-sort:handle>
                    <img :src="item.thumb" :alt="item.alt" class="absolute inset-0 h-full w-full object-contain p-1" draggable="false">

                    <span x-show="i === 0 && {{ $singlePicture ? 'false' : 'true' }}" class="badge badge-primary badge-sm absolute top-2 left-2">{{ __('Main') }}</span>

                    <div x-show="confirming === item.id" x-cloak
                         class="absolute inset-0 bg-base-100/90 flex flex-col items-center justify-center gap-2 p-2 text-center">
                        <div class="text-sm">{{ __('Remove this photo?') }}</div>
                        <div class="flex gap-2">
                            <button type="button" class="btn btn-ghost btn-sm text-error" @click="destroy(item)">{{ __('Remove') }}</button>
                            <button type="button" class="btn btn-ghost btn-sm" @click="confirming = null">{{ __('Cancel') }}</button>
                        </div>
                    </div>
                </div>

                {{-- Always visible (a touch screen has no hover), the same order as
                     a list row's: tools, then — set apart — delete. --}}
                <div class="admin-gallery-tools flex items-center gap-1 px-2 pt-2">
                    <button type="button" class="btn btn-ghost btn-sm btn-square" :disabled="busy"
                            title="{{ __('Rotate left') }}" aria-label="{{ __('Rotate left') }}" @click="rotate(item, -90)">
                        <x-icon name="rotate-ccw" />
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm btn-square" :disabled="busy"
                            title="{{ __('Rotate right') }}" aria-label="{{ __('Rotate right') }}" @click="rotate(item, 90)">
                        <x-icon name="rotate-cw" />
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('Preview') }}" aria-label="{{ __('Preview') }}" @click="preview(i)">
                        <x-icon name="maximize-2" />
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm btn-square" x-show="i !== 0 && {{ $singlePicture ? 'false' : 'true' }}"
                            title="{{ __('Make it the main photo') }}" aria-label="{{ __('Make it the main photo') }}" @click="makeMain(i)">
                        <x-icon name="star" />
                    </button>
                    {{-- Under a finger, where dragging is unreliable: one place
                         earlier, one place later. --}}
                    <button type="button" class="btn btn-ghost btn-sm btn-square hidden pointer-coarse:inline-flex"
                            x-show="i > 0 && {{ $singlePicture ? 'false' : 'true' }}"
                            title="{{ __('Move earlier') }}" aria-label="{{ __('Move earlier') }}" @click="move(item.id, i - 1)">
                        <x-icon name="arrow-up" />
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm btn-square hidden pointer-coarse:inline-flex"
                            x-show="i < items.length - 1 && {{ $singlePicture ? 'false' : 'true' }}"
                            title="{{ __('Move later') }}" aria-label="{{ __('Move later') }}" @click="move(item.id, i + 1)">
                        <x-icon name="arrow-down" />
                    </button>
                    <span class="w-px h-5 shrink-0 bg-base-300 mx-1 ms-auto" aria-hidden="true"></span>
                    <button type="button" class="btn btn-ghost btn-sm btn-square text-error" :disabled="busy"
                            title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}" @click="confirming = item.id">
                        <x-icon name="trash-2" />
                    </button>
                </div>

                <div class="admin-gallery-meta p-2 flex flex-col gap-2">
                    <input type="text" x-model="item.alt" :name="field + '[' + item.id + '][alt]'"
                           class="input input-bordered w-full" placeholder="{{ __('Alt text') }}" aria-label="{{ __('Alt text') }}">
                    <input type="text" x-model="item.title" :name="field + '[' + item.id + '][title]'"
                           class="input input-bordered w-full" placeholder="{{ __('Caption') }}" aria-label="{{ __('Caption') }}">
                    <input type="hidden" :name="field + '[' + item.id + '][sort]'" :value="i">
                    <div class="text-xs opacity-50 truncate" :title="item.name">
                        <span x-text="item.name"></span>
                        <span x-show="item.width" x-text="' · ' + item.width + '×' + item.height"></span>
                    </div>
                </div>
            </div>
        </template>

        <label class="admin-gallery-add rounded-box border-2 border-dashed border-base-300 hover:border-primary hover:bg-base-200/50
                      aspect-square flex flex-col items-center justify-center gap-1 cursor-pointer transition-colors text-center p-2"
               :class="dropZone ? 'border-primary bg-base-200/50' : ''"
               @dragover.prevent="dropZone = true" @dragleave="dropZone = false"
               @drop.prevent="dropZone = false; upload($event.dataTransfer.files)">
            <x-icon name="plus" size="xl" class="opacity-40" />
            @if ($singlePicture)
                <span class="text-sm opacity-60" x-text="items.length ? @js(__('Replace the picture')) : @js(__('Upload or drop a picture'))">{{ __('Upload or drop a picture') }}</span>
            @else
                <span class="text-sm opacity-60">{{ __('Upload or drop images') }}</span>
            @endif
            <input type="file" accept="image/*" @unless ($singlePicture) multiple @endunless class="hidden"
                   @change="upload($event.target.files); $event.target.value = ''">
        </label>
    </div>

    {{-- Preview is PhotoSwipe (see assets/lightbox.mjs); it renders its own
         overlay into <body>, so there is nothing to mark up here. --}}
</div>
