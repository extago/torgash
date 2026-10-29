{{--
    The videos of one record, on its form (the product's "Video" tab, a
    section of an article's or a page's form).

        @include('torgash.video::admin.editor', ['videoOwner' => 'product', 'videoRecord' => $product])

    `videoInText`: the record's text plays `{video:ID}` — each row then has
    "Into the text", which asks the form's rich editor to put the token at
    the cursor (the editor's `tiptap:insert`).

    No <form> of its own — it sits inside the owner's form, and a nested form
    leaks its inputs into the outer submit. Adding, a poster and removing go
    straight to the JSON endpoints; titles and order ride along with the
    owner's Save as videos[<id>][title|position].

    Variables are named distinctively on purpose: an included view inherits
    the caller's whole scope (docs/LESSONS.md).
--}}
@once
    @push('scripts')
        <script nonce="{{ csp_nonce() }}">
            document.addEventListener('alpine:init', () => {
                Alpine.data('torgashVideos', (config) => ({
                    base: config.base,
                    items: config.items,
                    typed: '',
                    busy: false,
                    errors: [],
                    confirming: null,
                    dragIndex: null,

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
                                this.errors = ['HTTP ' + response.status];
                                return false;
                            }
                            // Titles typed and not yet saved survive a redraw.
                            const typedTitles = Object.fromEntries(this.items.map((item) => [item.id, item.title]));
                            this.items = (data.items || this.items).map((item) => item.id in typedTitles ? Object.assign(item, { title: typedTitles[item.id] }) : item);
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

                    async add() {
                        const video = this.typed.trim();
                        if (!video) return;
                        const count = this.items.length;
                        await this.send('', { body: JSON.stringify({ video }) });
                        if (this.items.length > count) this.typed = '';
                    },

                    poster(item, files) {
                        if (!files || !files.length) return;
                        const body = new FormData();
                        body.append('poster', files[0]);
                        return this.send('/' + item.id + '/poster', { body });
                    },

                    destroy(item) {
                        return this.send('/' + item.id, { method: 'DELETE' });
                    },

                    prepare(item) {
                        return this.send('/' + item.id + '/prepare').then(() => this.watch());
                    },

                    // While ffmpeg works on a file (in the background, see
                    // PrepareVideoFile) the list asks how it is going — and
                    // stops asking once nothing is queued or working.
                    timer: null,

                    init() {
                        this.watch();
                    },

                    watch() {
                        const waiting = () => this.items.some((item) => item.state === 'queued' || item.state === 'working');
                        if (this.timer || !waiting()) return;
                        this.timer = setInterval(async () => {
                            try {
                                const response = await fetch(this.base, { headers: { 'Accept': 'application/json' } });
                                const data = await response.json();
                                const fresh = Object.fromEntries((data.items || []).map((item) => [item.id, item]));
                                // Only what ffmpeg changes: a title being typed is left alone.
                                this.items.forEach((item) => {
                                    const now = fresh[item.id];
                                    if (now) Object.assign(item, { state: now.state, error: now.error, prepared: now.prepared, size: now.size, poster: now.poster, duration: now.duration, href: now.href });
                                });
                            } catch (e) {}
                            if (!waiting()) {
                                clearInterval(this.timer);
                                this.timer = null;
                            }
                        }, 3000);
                    },

                    intoText(item, button) {
                        document.dispatchEvent(new CustomEvent('tiptap:insert', { detail: { text: '{video:' + item.id + '}', from: button } }));
                    },

                    // A file of the shop's own, in chunks (Torgash\Video\Uploads):
                    // the server says how big a chunk may be, each goes as the
                    // raw body with the offset it belongs at, and a failed one
                    // is tried again from where the server says the file is.
                    upload: null,

                    async uploadFile(files) {
                        const file = files && files[0];
                        if (!file || this.upload) return;
                        this.errors = [];
                        if (file.size > config.maxBytes) {
                            this.errors = [config.tooBig.replace(':size', Math.ceil(file.size / 1048576))];
                            return;
                        }
                        const headers = { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' };
                        this.upload = { name: file.name, sent: 0, total: file.size, token: null, cancelled: false };
                        try {
                            const started = await fetch(this.base + '/upload', {
                                method: 'POST',
                                headers: Object.assign({ 'Content-Type': 'application/json' }, headers),
                                body: JSON.stringify({ name: file.name, size: file.size }),
                            });
                            const begin = await started.json().catch(() => ({}));
                            if (!started.ok) throw new Error((begin.errors || ['HTTP ' + started.status]).join(' '));
                            this.upload.token = begin.token;

                            let offset = 0, failures = 0;
                            while (offset < file.size) {
                                if (this.upload.cancelled) return;
                                const piece = file.slice(offset, offset + begin.chunk);
                                try {
                                    const sent = await fetch(this.base + '/upload/' + begin.token, {
                                        method: 'POST',
                                        headers: Object.assign({ 'Content-Type': 'application/octet-stream', 'X-Upload-Offset': String(offset) }, headers),
                                        body: piece,
                                    });
                                    const answer = await sent.json().catch(() => ({}));
                                    if (sent.status === 422) throw Object.assign(new Error((answer.errors || []).join(' ')), { final: true });
                                    if (!sent.ok) throw new Error('HTTP ' + sent.status);
                                    offset = answer.received;
                                    failures = 0;
                                } catch (e) {
                                    if (e.final || ++failures > 3) throw e;
                                    await new Promise((resolve) => setTimeout(resolve, 1000 * failures));
                                }
                                this.upload.sent = offset;
                            }

                            await this.send('/upload/' + begin.token + '/finish');
                            this.watch();
                        } catch (e) {
                            if (!this.upload?.cancelled) this.errors = [e.message || String(e)];
                        } finally {
                            this.upload = null;
                        }
                    },

                    cancelUpload() {
                        if (!this.upload) return;
                        this.upload.cancelled = true;
                        if (this.upload.token) {
                            fetch(this.base + '/upload/' + this.upload.token, {
                                method: 'DELETE',
                                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' },
                            });
                        }
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
        <h3 class="font-semibold">{{ __('Video') }}</h3>

        @php $videoInText = $videoInText ?? false; @endphp
        @if (! $videoRecord->exists)
            <p class="text-sm opacity-60">{{ __('Videos can be added once it is saved.') }}</p>
        @else
            @php
                $videoItems = \Torgash\Video\Http\Admin\VideosController::items(
                    app(\Torgash\Video\Videos::class)->of($videoOwner, (int) $videoRecord->getKey())
                );
                $videoMaxBytes = app(\Torgash\Video\Uploads::class)->maxBytes();
            @endphp
            <div x-data="torgashVideos({
                    base: {{ \Illuminate\Support\Js::from(url("/admin/video/{$videoOwner}/{$videoRecord->getKey()}")) }},
                    items: {{ \Illuminate\Support\Js::from($videoItems) }},
                    maxBytes: {{ $videoMaxBytes }},
                    tooBig: {{ \Illuminate\Support\Js::from(__('The file is :size MB; the shop takes videos up to :max MB (the Video module\'s settings).', ['max' => (int) ($videoMaxBytes / 1048576)])) }},
                 })" class="flex flex-col gap-3">

                <p class="text-sm opacity-60">
                    @if ($videoInText)
                        {{ __('A link to a video on YouTube, Vimeo, VK Video or Bilibili, the embed code of a site allowed in the module\'s settings, or a file of your own. "Into the text" puts it where the cursor is in the text, as {video:N}; the player loads only when the visitor presses play.') }}
                    @else
                        {{ __('A link to a video on YouTube, Vimeo, VK Video or Bilibili, or the embed code of a site allowed in the module\'s settings. The videos stand after the photos, in this order; the platform\'s player loads only when the visitor presses play.') }}
                    @endif
                </p>

                {{-- Enter here would press the product form's own Save: it adds instead. --}}
                <div class="flex gap-2 items-start">
                    <textarea x-model="typed" rows="1" @keydown.enter.prevent="add()" aria-label="{{ __('Video link or embed code') }}"
                              class="textarea w-full font-mono text-sm min-h-10 field-sizing-content"
                              placeholder="https://www.youtube.com/watch?v=…"></textarea>
                    <button type="button" class="btn btn-primary gap-1.5" :disabled="busy || !typed.trim()" @click="add()">
                        <span x-show="busy" x-cloak class="loading loading-spinner loading-xs"></span>
                        <x-icon name="plus" size="sm" x-show="!busy" /> {{ __('Add') }}
                    </button>
                    {{-- Or a file of the shop's own. A label, so the button IS the file picker. --}}
                    <label class="btn gap-1.5 shrink-0" :class="(busy || upload) && 'btn-disabled'">
                        <x-icon name="upload" size="sm" /> {{ __('Upload a file') }}
                        <input type="file" accept="video/mp4,video/webm,.mp4,.m4v,.webm" class="hidden" :disabled="busy || upload"
                               @change="uploadFile($event.target.files); $event.target.value = ''">
                    </label>
                </div>
                <p class="text-xs opacity-60 -mt-1">{{ __('Your own file: mp4 (H.264) or webm, up to :max MB. It is sent in pieces, so a big file only takes time. If the server has ffmpeg, the poster and a file every browser plays are made by themselves.', ['max' => (int) ($videoMaxBytes / 1048576)]) }}</p>

                <template x-if="upload">
                    <div class="rounded-box border border-base-300 p-3 flex flex-col gap-2">
                        <div class="flex items-center gap-2 text-sm">
                            <x-icon name="file-video" size="sm" class="opacity-60 shrink-0" />
                            <span class="truncate grow" x-text="upload.name"></span>
                            <span class="tabular-nums opacity-60 shrink-0" x-text="Math.floor(upload.sent / upload.total * 100) + '%'"></span>
                            <button type="button" class="btn btn-ghost btn-sm" @click="cancelUpload()">{{ __('Cancel') }}</button>
                        </div>
                        <progress class="progress progress-primary w-full" :value="upload.sent" :max="upload.total"></progress>
                    </div>
                </template>

                <template x-if="errors.length">
                    <div class="alert alert-warning text-sm flex-col items-start gap-1 py-2">
                        <template x-for="(e, i) in errors" :key="i"><div x-text="e"></div></template>
                    </div>
                </template>

                <div class="flex flex-col gap-2" x-sort="move($item, $position)" x-sort:config="{ delay: 150, delayOnTouchOnly: true }">
                    <template x-for="(item, i) in items" :key="item.id">
                        <div class="flex gap-3 items-start rounded-box border border-base-300 bg-base-100 p-2" x-sort:item="item.id">
                            {{-- The grip, and under a finger the arrows beneath it. --}}
                            <span class="flex flex-col items-center shrink-0 self-center">
                                <span class="cursor-move opacity-40" aria-hidden="true" x-sort:handle><x-icon name="grip-vertical" size="sm" /></span>
                                <button type="button" class="btn btn-ghost btn-sm btn-square hidden pointer-coarse:inline-flex" :disabled="i === 0"
                                        title="{{ __('Move earlier') }}" aria-label="{{ __('Move earlier') }}" @click="move(item.id, i - 1)"><x-icon name="arrow-up" size="sm" /></button>
                                <button type="button" class="btn btn-ghost btn-sm btn-square hidden pointer-coarse:inline-flex" :disabled="i === items.length - 1"
                                        title="{{ __('Move later') }}" aria-label="{{ __('Move later') }}" @click="move(item.id, i + 1)"><x-icon name="arrow-down" size="sm" /></button>
                            </span>

                            {{-- The poster, and a click on it to replace it. --}}
                            <label class="relative shrink-0 w-40 aspect-video rounded-lg overflow-hidden bg-neutral cursor-pointer group"
                                   title="{{ __('Upload a poster') }}">
                                <img x-show="item.poster" :src="item.poster" alt="" class="absolute inset-0 h-full w-full object-cover" draggable="false">
                                <span class="absolute inset-0 flex items-center justify-center text-neutral-content">
                                    <x-icon name="circle-play" size="xl" class="drop-shadow" />
                                </span>
                                <span class="absolute inset-x-0 bottom-0 bg-black/60 text-white text-xs text-center py-0.5 opacity-0 group-hover:opacity-100 pointer-coarse:opacity-100 transition-opacity"
                                      x-text="item.poster ? @js(__('Replace the poster')) : @js(__('Upload a poster'))"></span>
                                <span x-show="item.duration" x-text="item.duration" class="absolute top-1 right-1 rounded bg-black/70 text-white text-xs px-1 tabular-nums"></span>
                                <input type="file" accept="image/*" class="hidden" @change="poster(item, $event.target.files); $event.target.value = ''">
                            </label>

                            <div class="grow min-w-0 flex flex-col gap-1.5">
                                <div class="flex items-center gap-2 text-xs">
                                    <span class="badge badge-sm badge-ghost" x-text="item.file ? @js(__('File')) : item.platform"></span>
                                    <a :href="item.href" target="_blank" rel="noopener noreferrer" class="link link-hover opacity-60 truncate" x-text="item.url"></a>
                                    <span x-show="item.missing" x-cloak class="badge badge-sm badge-warning">{{ __('The module that plays it is off') }}</span>
                                    <span x-show="item.size" x-cloak class="opacity-60 tabular-nums shrink-0" x-text="item.size"></span>
                                </div>
                                {{-- ffmpeg's work on a file of the shop's own (PrepareVideoFile). --}}
                                <template x-if="item.file && (item.state === 'queued' || item.state === 'working')">
                                    <div class="flex items-center gap-2 text-xs opacity-70">
                                        <span class="loading loading-spinner loading-xs"></span>
                                        <span x-text="item.state === 'queued' ? @js(__('Waiting for the background tasks to prepare it for the web…')) : @js(__('Being prepared for the web: a poster, and a file every browser plays…'))"></span>
                                    </div>
                                </template>
                                <template x-if="item.file && item.state === 'failed'">
                                    <div class="text-xs text-warning" x-text="@js(__('ffmpeg could not prepare it; the file plays as uploaded.')) + ' ' + (item.error || '')"></div>
                                </template>
                                <input type="text" x-model="item.title" :name="'videos[' + item.id + '][title]'" maxlength="255" aria-label="{{ __('Title') }}"
                                       class="input w-full" :placeholder="item.title_hint || @js($videoInText ? __('Title — the record\'s title if empty') : __('Title — the product\'s name if empty'))">
                                <input type="hidden" :name="'videos[' + item.id + '][position]'" :value="i + 1">
                            </div>

                            <div class="shrink-0 flex items-center gap-1">
                                @if ($videoInText)
                                    <button type="button" class="btn btn-sm gap-1.5" x-show="confirming !== item.id" @click="intoText(item, $el)"
                                            :title="'{video:' + item.id + '}'">
                                        <x-icon name="text-cursor-input" size="sm" /> {{ __('Into the text') }}
                                    </button>
                                @endif
                                <button type="button" class="btn btn-sm gap-1.5" :disabled="busy"
                                        x-show="item.file && !item.prepared && item.state !== 'queued' && item.state !== 'working' && confirming !== item.id" x-cloak
                                        title="{{ __('A poster from a frame, the length, and a file every browser plays — by ffmpeg, in the background') }}"
                                        @click="prepare(item)">
                                    <x-icon name="wand-sparkles" size="sm" /> {{ __('Prepare for the web') }}
                                </button>
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
