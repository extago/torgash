{{--
    Rendered into any admin field marked rich (see the core translatable-field
    component's core.field.rich filter). The <textarea> stays the real form
    control — TipTap only writes HTML into it — so validation, old() and the
    controller are untouched by the editor being on or off.

    The toolbar is markup rather than JavaScript so its buttons use the same
    <x-icon> (Lucide) as the rest of the admin, and so it is on screen before
    editor.mjs loads.
--}}
@php
    $id = 'tiptap-'.\Illuminate\Support\Str::slug($name).'-'.\Illuminate\Support\Str::random(6);
    $buttons = \Torgash\Editor\Toolbar::parse((string) setting('torgash.editor', 'toolbar', ''));

    // $images is what answered `editor.images`: where an image inside this
    // text may be kept, or null. Null drops the button rather than disabling
    // it — a button that opens a panel with nowhere to upload to is worse than
    // no button, and the commonest reason for null is the ordinary one: the
    // row has not been saved yet, so it has no id to hang a file on.
    $images = $images ?? null;

    if (! $images) {
        // The gallery's pictures come through the same panel, so it goes too.
        $buttons = array_values(array_filter($buttons, fn ($b) => ! in_array($b['key'], ['image', 'gallery'], true)));
    }

    $minHeight = (int) setting('torgash.editor', 'min_height', 220);
    $showSource = (bool) setting('torgash.editor', 'show_source', true);
@endphp

@once
    @push('head')
        <link rel="stylesheet" href="{{ module_asset('torgash.editor', 'editor.css') }}">
    @endpush
    @push('scripts')
        <script nonce="{{ csp_nonce() }}" type="module" src="{{ module_asset('torgash.editor', 'editor.mjs') }}"></script>
    @endpush
@endonce

@once
    {{-- The HTML block's words, read by htmlblock.mjs — the JS holds no language. --}}
    <template data-tiptap-html-labels data-title="{{ __('HTML block') }}" data-edit="{{ __('Edit HTML') }}"
              data-apply="{{ __('Apply') }}" data-cancel="{{ __('Cancel') }}"></template>
    {{-- The gallery's words and icons, read by gallery.mjs. --}}
    <template data-tiptap-gallery-labels data-title="{{ __('Gallery') }}" data-add="{{ __('Add pictures') }}"
              data-empty="{{ __('No pictures yet. Upload them or click the ones already uploaded in the panel above — while the gallery is selected, they go into it.') }}"
              data-alt="{{ __('Description (alt)') }}" data-earlier="{{ __('Move earlier') }}"
              data-later="{{ __('Move later') }}" data-remove="{{ __('Remove from the gallery') }}"></template>
    @foreach (['image-plus', 'chevron-left', 'chevron-right', 'x'] as $galleryIcon)
        <template data-tiptap-gallery-icon="{{ $galleryIcon }}"><x-icon :name="$galleryIcon" size="sm" /></template>
    @endforeach
@endonce

<div class="rounded-box border border-base-300 bg-base-100 overflow-hidden"
     data-tiptap data-target="{{ $id }}">

    <div data-tiptap-toolbar class="flex flex-wrap items-center gap-0.5 p-1 border-b border-base-300 bg-base-200/60">
        @foreach ($buttons as $button)
            @if ($button['key'] === '|')
                <span class="w-px self-stretch bg-base-300 mx-0.5"></span>
            @else
                <button type="button" class="btn btn-sm btn-ghost px-1.5 pointer-coarse:min-w-10"
                        data-tiptap-command="{{ $button['key'] }}" title="{{ __($button['title']) }}" aria-label="{{ __($button['title']) }}">
                    <x-icon :name="$button['icon']" size="sm" />
                </button>
            @endif
        @endforeach

        @if ($showSource)
            <button type="button" class="btn btn-sm btn-ghost px-1.5 ml-auto gap-1 pointer-coarse:min-w-10"
                    data-tiptap-source title="{{ __('Edit HTML source') }}" aria-label="{{ __('Edit HTML source') }}">
                <x-icon name="code-xml" size="sm" />
            </button>
        @endif
    </div>

    <div data-tiptap-link class="hidden items-center gap-1 p-1.5 border-b border-base-300 bg-base-200/60">
        <input type="url" placeholder="https://…" aria-label="{{ __('Link address') }}" class="input input-bordered input-sm flex-1">
        <button type="button" data-tiptap-link-apply class="btn btn-sm btn-primary">{{ __('Apply') }}</button>
        <button type="button" data-tiptap-link-clear class="btn btn-sm btn-ghost pointer-coarse:min-w-10" title="{{ __('Remove link') }}" aria-label="{{ __('Remove link') }}">
            <x-icon name="unlink" size="sm" />
        </button>
    </div>

    @if ($images)
        {{--
            The image panel. Markup here rather than in JavaScript for the same
            reason the toolbar is: the classes are daisyUI's and the icons are
            core's <x-icon>, and a second set of both living in a .mjs file is
            how an admin screen starts looking like two admin screens.

            One <template> for a plaque, cloned per file — so even the repeated
            part keeps its markup in the template.
        --}}
        <div data-tiptap-images
             data-endpoint="{{ $images['endpoint'] }}"
             class="hidden flex-col gap-2 p-2 border-b border-base-300 bg-base-200/60">

            <div class="flex flex-wrap items-center gap-1.5">
                <label class="btn btn-sm gap-1">
                    <x-icon name="upload" size="sm" /> {{ __('Upload a picture') }}
                    <input type="file" accept="image/*" multiple class="hidden" data-tiptap-image-file>
                </label>

                @if ($images['allow_remote'] ?? false)
                    <input type="url" data-tiptap-image-url placeholder="https://…" aria-label="{{ __('Picture address') }}"
                           class="input input-bordered input-sm flex-1 min-w-40">
                    <button type="button" data-tiptap-image-fetch class="btn btn-sm">{{ __('Add by link') }}</button>
                @endif

                <span data-tiptap-image-busy class="loading loading-spinner loading-xs hidden"></span>

                <button type="button" data-tiptap-images-close class="btn btn-sm btn-ghost ml-auto"
                        title="{{ __('Close') }}">
                    <x-icon name="x" size="sm" />
                </button>
            </div>

            <div data-tiptap-image-errors class="hidden text-xs text-error whitespace-pre-line"></div>

            {{-- While a gallery is selected the panel fills it (images.mjs). --}}
            <div data-tiptap-gallery-hint class="hidden text-xs text-info">
                {{ __('A gallery is selected: a click on a picture below, or an upload, adds it to the gallery.') }}
            </div>

            {{-- Properties of the image selected in the text. Hidden until one
                 is, and the panel opens by itself when one is clicked: that
                 click is how a person asks to change it. --}}
            <div data-tiptap-image-props class="hidden flex-wrap items-end gap-2 pt-1 border-t border-base-300">
                <label class="flex flex-col gap-0.5">
                    <span class="text-xs opacity-60">{{ __('Description (alt)') }}</span>
                    <input type="text" data-tiptap-image-alt class="input input-bordered input-sm w-52">
                </label>

                <label class="flex flex-col gap-0.5">
                    <span class="text-xs opacity-60">{{ __('Picture size') }}</span>
                    <select data-tiptap-image-size class="select select-bordered select-sm">
                        <option value="auto">{{ __('As uploaded') }}</option>
                        <option value="manual">{{ __('Set by hand') }}</option>
                        <option value="full">{{ __('Full column width') }}</option>
                    </select>
                </label>

                <label class="flex flex-col gap-0.5" data-tiptap-image-manual>
                    <span class="text-xs opacity-60">{{ __('Width and height, px') }}</span>
                    {{-- The placeholders are numbers on purpose: an example needs no
                         translating, and "width" alone in a lang file is the generic
                         key that quietly re-translates somebody else's screen. --}}
                    <span class="flex items-center gap-1">
                        <input type="number" min="1" data-tiptap-image-width class="input input-bordered input-sm w-20"
                               placeholder="800" aria-label="{{ __('Picture width, px') }}">
                        <span class="opacity-40">×</span>
                        <input type="number" min="1" data-tiptap-image-height class="input input-bordered input-sm w-20"
                               placeholder="600" aria-label="{{ __('Picture height, px') }}">
                    </span>
                </label>

                <button type="button" data-tiptap-image-remove class="btn btn-sm btn-ghost text-error gap-1"
                        title="{{ __('Remove from the text') }}">
                    <x-icon name="trash-2" size="sm" />
                </button>
            </div>

            <div data-tiptap-image-list class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-1.5"></div>

            <div data-tiptap-image-empty class="text-xs opacity-50">
                {{ __('Nothing uploaded for this record yet. Whatever you add here belongs to it and goes when it goes.') }}
            </div>

            <template data-tiptap-image-plaque>
                <div class="group relative flex items-center gap-2 p-1 rounded border border-base-300 bg-base-100 text-left">
                    <button type="button" data-insert class="flex items-center gap-2 flex-1 min-w-0 text-left">
                        <img data-thumb alt="" class="h-10 w-10 rounded object-cover bg-base-200 shrink-0">
                        <span class="min-w-0">
                            <span data-name class="block text-xs truncate"></span>
                            <span data-meta class="block text-xs opacity-50"></span>
                        </span>
                    </button>
                    <span data-unused class="hidden badge badge-warning badge-xs shrink-0">{{ __('not in the text') }}</span>
                    <button type="button" data-delete
                            class="btn btn-ghost btn-sm px-1 opacity-0 group-hover:opacity-100 focus:opacity-100 shrink-0"
                            title="{{ __('Delete the file') }}"
                            data-armed-title="{{ __('Press again to delete this file') }}"
                            data-armed-used="{{ __('This one is used in the text — press again and it will disappear from the page') }}">
                        <x-icon name="trash-2" size="sm" />
                    </button>
                </div>
            </template>
        </div>
    @endif

    <div data-tiptap-surface class="torgash-editor-surface" style="min-height: {{ $minHeight }}px"></div>
</div>

<textarea id="{{ $id }}" name="{{ $name }}" rows="8" @if ($label ?? null) aria-label="{{ $label }}" @endif
          class="textarea textarea-bordered w-full font-mono text-xs hidden"
          placeholder="{{ $placeholder }}">{{ $value }}</textarea>
