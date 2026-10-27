{{--
    One field of a template, as the form of a banner (or of a slider, for a
    frame's fields) draws it. Names are values[<key>] and, for a picture,
    images[<key>] / remove_images[<key>].

    Given: $fieldDef (Fields::shape()), $fieldValues (what the record has),
    $fieldRecord (the banner, for its pictures; null for a slider),
    $fieldPictures (whether pictures are offered at all).

    Variables are named distinctively on purpose: an included view inherits
    the caller's whole scope (docs/LESSONS.md).
--}}
@php
    $fieldKey = $fieldDef['key'];
    $fieldName = 'values['.$fieldKey.']';
    $fieldLabel = label_text($fieldDef['label']);
    $fieldHelp = $fieldDef['help'] !== '' ? label_text($fieldDef['help']) : '';
    $fieldStored = $fieldValues[$fieldKey] ?? null;
    $fieldCurrent = old('values.'.$fieldKey, is_array($fieldStored) ? null : ($fieldStored ?? $fieldDef['default']));
    $fieldWide = in_array($fieldDef['type'], ['rich', 'image'], true) ? 'md:col-span-2' : '';
@endphp
@switch($fieldDef['type'])
    @case('text')
        <div class="{{ $fieldWide }}">
            <x-admin.translatable-field :name="$fieldName" :label="$fieldLabel" :value="is_array($fieldStored) ? $fieldStored : []" maxlength="1000" />
            @if ($fieldHelp !== '')<p class="text-xs opacity-60 mt-1">{{ $fieldHelp }}</p>@endif
        </div>
        @break

    @case('rich')
        <div class="{{ $fieldWide }}">
            <x-admin.translatable-field :name="$fieldName" :label="$fieldLabel" :value="is_array($fieldStored) ? $fieldStored : []" textarea :rows="2" maxlength="5000" />
            @if ($fieldHelp !== '')<p class="text-xs opacity-60 mt-1">{{ $fieldHelp }}</p>@endif
        </div>
        @break

    @case('image')
        @if ($fieldPictures)
            @php $fieldPicture = $fieldRecord?->exists ? $fieldRecord->pictureUrl($fieldKey) : null; @endphp
            <fieldset class="fieldset p-0">
                <legend class="fieldset-legend">{{ $fieldLabel }}</legend>
                @if ($fieldPicture)
                    <div class="rounded-box border border-base-300 bg-base-200 overflow-hidden aspect-video flex items-center justify-center">
                        <img src="{{ $fieldPicture }}" alt="" class="max-w-full max-h-full object-contain">
                    </div>
                    <label class="label cursor-pointer justify-start gap-2 text-sm">
                        <input type="checkbox" name="remove_images[{{ $fieldKey }}]" value="1" class="checkbox checkbox-sm"> {{ __('Remove') }}
                    </label>
                @endif
                <input type="file" name="images[{{ $fieldKey }}]" accept="image/*" class="file-input file-input-bordered w-full">
                @if ($fieldHelp !== '')<p class="label text-xs opacity-60 whitespace-normal">{{ $fieldHelp }}</p>@endif
            </fieldset>
        @endif
        @break

    @case('color')
        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ $fieldLabel }}</legend>
            <div class="flex items-center gap-2" x-data="{ colour: @js((string) $fieldCurrent) }">
                <input type="color" x-model="colour" class="w-12 h-10 rounded-field border border-base-300 cursor-pointer bg-transparent">
                <input name="{{ $fieldName }}" x-model="colour" maxlength="7" pattern="#[0-9a-fA-F]{6}" class="input input-bordered w-32 font-mono">
            </div>
            @if ($fieldHelp !== '')<p class="label text-xs opacity-60 whitespace-normal">{{ $fieldHelp }}</p>@endif
        </fieldset>
        @break

    @case('select')
        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ $fieldLabel }}</legend>
            <select name="{{ $fieldName }}" class="select select-bordered w-full">
                @foreach ($fieldDef['options'] as $fieldOption)
                    <option value="{{ $fieldOption['value'] }}" @selected((string) $fieldCurrent === $fieldOption['value'])>{{ label_text($fieldOption['label']) }}</option>
                @endforeach
            </select>
            @if ($fieldHelp !== '')<p class="label text-xs opacity-60 whitespace-normal">{{ $fieldHelp }}</p>@endif
        </fieldset>
        @break

    @case('video')
        @if ($fieldPictures)
            @php
                $fieldVideo = $fieldRecord?->exists ? $fieldRecord->getFirstMedia(\Torgash\Banners\Fields::collection($fieldKey)) : null;
                $fieldVideoMax = (int) (\Torgash\Banners\Fields::videoMaxBytes() / 1048576);
            @endphp
            <fieldset class="fieldset p-0 md:col-span-2">
                <legend class="fieldset-legend">{{ $fieldLabel }}</legend>
                @if ($fieldVideo)
                    <div class="flex items-center gap-3">
                        <video src="{{ $fieldVideo->getUrl() }}" muted loop playsinline preload="metadata" class="w-56 aspect-video rounded-box bg-neutral object-cover"
                               x-data x-on:mouseenter="$el.play()" x-on:mouseleave="$el.pause()"></video>
                        <div class="text-sm">
                            <div class="font-mono">{{ $fieldVideo->file_name }}</div>
                            <div class="opacity-60">{{ number_format($fieldVideo->size / 1048576, 1) }} MB</div>
                            <label class="label cursor-pointer justify-start gap-2 text-sm">
                                <input type="checkbox" name="remove_images[{{ $fieldKey }}]" value="1" class="checkbox checkbox-sm"> {{ __('Remove') }}
                            </label>
                        </div>
                    </div>
                @endif
                <input type="file" name="images[{{ $fieldKey }}]" accept="video/mp4,video/webm,.mp4,.webm" class="file-input file-input-bordered w-full">
                <p class="label text-xs opacity-60 whitespace-normal">
                    {{ $fieldHelp !== '' ? $fieldHelp.' ' : '' }}{{ __('mp4 or webm, up to :max MB — a loop of a few seconds, without sound: it plays muted.', ['max' => $fieldVideoMax]) }}
                </p>
            </fieldset>
        @endif
        @break

    @case('links')
        @php
            // Each row's text in the language being edited; another language's
            // as the grey placeholder, the way every translatable field shows it.
            $fieldLocale = admin_content_locale();
            $fieldRows = collect(old('values.'.$fieldKey, array_values(array_filter((array) $fieldStored, 'is_array'))))
                ->map(fn ($row) => [
                    'text' => is_array($row['text'] ?? null) ? (string) ($row['text'][$fieldLocale] ?? '') : (string) ($row['text'] ?? ''),
                    'hint' => is_array($row['text'] ?? null) ? (string) pick_translation($row['text']) : '',
                    'link' => (string) ($row['link'] ?? ''),
                ])->values()->all();
        @endphp
        <fieldset class="fieldset p-0 md:col-span-2" x-data="{ rows: @js($fieldRows) }">
            <legend class="fieldset-legend">{{ $fieldLabel }} <span class="opacity-40 font-normal">({{ strtoupper($fieldLocale) }})</span></legend>
            <template x-for="(row, r) in rows" :key="r">
                <div class="flex gap-2 items-center mb-2">
                    <input :name="'{{ $fieldName }}[' + r + '][text]'" x-model="row.text" maxlength="100" class="input input-bordered w-56"
                           :placeholder="row.hint || @js(__('iPhone 17 Pro'))">
                    <input :name="'{{ $fieldName }}[' + r + '][link]'" x-model="row.link" maxlength="1000" class="input input-bordered grow font-mono text-sm" placeholder="/iphone/iphone-17-pro/">
                    <button type="button" class="btn btn-ghost btn-sm btn-square" @click="rows.splice(r, 1)" aria-label="{{ __('Remove') }}"><x-icon name="x" /></button>
                </div>
            </template>
            <button type="button" class="btn btn-sm gap-1.5 self-start" x-show="rows.length < {{ \Torgash\Banners\Fields::LINKS_MAX }}"
                    @click="rows.push({ text: '', hint: '', link: '' })"><x-icon name="plus" size="sm" /> {{ __('Add a link') }}</button>
            @if ($fieldHelp !== '')<p class="label text-xs opacity-60 whitespace-normal">{{ $fieldHelp }}</p>@endif
        </fieldset>
        @break

    @case('checkbox')
        <div class="flex flex-col justify-end">
            <label class="label cursor-pointer justify-start gap-3">
                <input type="hidden" name="{{ $fieldName }}" value="0">
                <input type="checkbox" name="{{ $fieldName }}" value="1" class="toggle toggle-sm" @checked(filter_var($fieldCurrent, FILTER_VALIDATE_BOOLEAN))>
                <span class="label-text whitespace-normal">{{ $fieldLabel }}</span>
            </label>
            @if ($fieldHelp !== '')<p class="text-xs opacity-60">{{ $fieldHelp }}</p>@endif
        </div>
        @break

    @default
        {{-- link, icon: one line of plain text, checked on saving. --}}
        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ $fieldLabel }}</legend>
            <input name="{{ $fieldName }}" value="{{ $fieldCurrent }}" maxlength="1000" autocomplete="off"
                   class="input input-bordered w-full font-mono text-sm"
                   placeholder="{{ $fieldDef['type'] === 'link' ? '/sale' : 'wallet-cards' }}">
            @if ($fieldHelp !== '')
                <p class="label text-xs opacity-60 whitespace-normal">{{ $fieldHelp }}</p>
            @elseif ($fieldDef['type'] === 'link')
                <p class="label text-xs opacity-60 whitespace-normal">{{ __('An address on this site (/sale) or a full one (https://…).') }}</p>
            @endif
        </fieldset>
@endswitch
