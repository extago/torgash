{{--
    A `rows` field (BlockTypes::ROW_PARTS): rows the shop adds, orders by
    dragging (the arrows under a finger) and fills in. Posted as
    settings[key][i][part] in the order shown; BlockTypes::castRows() casts
    them. A translated part shows and posts the content language picked at
    the top, merged by the row's id — the base language's text is its
    placeholder. A picture is stored the moment it is picked
    (admin.blocks.image) and posted as its path.

    Everything the script needs is in x-data through @js — and no double
    quote anywhere inside an attribute: it would end it (the slider's form
    printed its code on the page that way).
--}}
@php
    $rowsKey = $rowsField['key'];
    $rowsLocale = function_exists('admin_content_locale') ? admin_content_locale() : app()->getLocale();
    $rowsBase = content_base_locale();
    $rowsWord = fn ($text, $fallback = '') => label_text($text, app()->getLocale(), (string) $fallback);

    $rowsKinds = collect((array) ($rowsField['kinds'] ?? []))
        ->map(fn ($kind) => is_array($kind) ? ['value' => (string) $kind['value'], 'label' => $rowsWord($kind['label'] ?? null, $kind['value'])] : ['value' => (string) $kind, 'label' => (string) $kind])
        ->values()->all();
    $rowsParts = collect((array) ($rowsField['fields'] ?? []))
        ->filter(fn ($part) => isset($part['key']) && in_array($part['type'] ?? 'text', \Torgash\Blocks\BlockTypes::ROW_PARTS, true))
        ->map(fn ($part) => [
            'key' => $part['key'],
            'type' => $part['type'] ?? 'text',
            'label' => $rowsWord($part['label'] ?? null, $part['key']),
            'placeholder' => $rowsWord($part['placeholder'] ?? null, ''),
            // A pick's empty choice, when it means something ("Whole"), and
            // the part it waits for (`with`: shown once the row has it).
            'empty' => $rowsWord($part['empty'] ?? null, __('Choose…')),
            'with' => $part['with'] ?? null,
            'wide' => (bool) ($part['wide'] ?? in_array($part['type'] ?? 'text', ['pick', 'link'], true)),
            'kinds' => isset($part['kinds']) ? array_values((array) $part['kinds']) : null,
            'translatable' => ! empty($part['translatable']),
            'options' => ($part['type'] ?? null) === 'pick'
                ? collect(\Torgash\Blocks\BlockTypes::options($part))->map(fn ($option) => [
                    'value' => (string) (is_array($option) ? ($option['value'] ?? '') : $option),
                    'label' => is_array($option) ? $rowsWord($option['label'] ?? null, $option['value'] ?? '') : (string) $option,
                ])->values()->all()
                : [],
        ])->values()->all();

    // What the form starts with: the rows as stored (an old bare id is made
    // the row it meant), a translated part in the content language with the
    // base language's text beside it, a picture with its address.
    $rowsStart = collect(\Torgash\Blocks\BlockTypes::castRows($rowsField, $rowsValue, null, 'json'))->map(function (array $row) use ($rowsParts, $rowsLocale, $rowsBase) {
        foreach ($rowsParts as $part) {
            if ($part['translatable']) {
                $map = \Torgash\Blocks\BlockTypes::map($row[$part['key']] ?? null);
                $row[$part['key']] = $map[$rowsLocale] ?? '';
                $row[$part['key'].'__base'] = $rowsLocale === $rowsBase ? '' : ($map[$rowsBase] ?? '');
            } elseif ($part['type'] === 'image') {
                $row[$part['key'].'__url'] = \Torgash\Blocks\BlockImages::url($row[$part['key']] ?? null);
            }
        }

        return $row;
    })->values()->all();
@endphp
<fieldset class="fieldset p-0"
          x-data="{
              rows: @js($rowsStart), kinds: @js($rowsKinds), parts: @js($rowsParts), uploading: null, failed: '',
              has(part, row) { return (!part.kinds || part.kinds.includes(row.kind)) && (!part.with || !!row[part.with]) },
              optionLabel(part, value) { const found = part.options.find((o) => o.value === String(value)); return found ? found.label.replace(/^[\s—–-]+/, '') : '' },
              hint(part, row) {
                  if (part.translatable && row[part.key + '__base']) return row[part.key + '__base'];
                  const pick = this.parts.find((p) => p.type === 'pick' && this.has(p, row));
                  if (part.translatable && pick && row[pick.key]) return this.optionLabel(pick, row[pick.key]);
                  return part.placeholder;
              },
              add(kind) { this.rows.push({ id: Math.random().toString(36).slice(2, 12).padEnd(8, 'x'), kind: kind }); },
              /* Named by the row's id: Alpine Sort drops a key of 0 as none. */
              move(id, to) {
                  const from = this.rows.findIndex((row) => row.id === id);
                  if (from === -1 || from === to || to < 0 || to >= this.rows.length) return;
                  this.rows.splice(to, 0, this.rows.splice(from, 1)[0]);
              },
              async upload(row, part, input) {
                  const file = input.files[0];
                  if (!file) return;
                  this.uploading = row.id + part.key; this.failed = '';
                  const body = new FormData(); body.append('file', file);
                  try {
                      const answer = await fetch(@js(route('admin.blocks.image')), { method: 'POST', body: body,
                          headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
                      const data = await answer.json();
                      if (!answer.ok) throw new Error(data.errors ? Object.values(data.errors)[0][0] : data.message);
                      row[part.key] = data.path; row[part.key + '__url'] = data.url;
                  } catch (error) { this.failed = error.message || @js(__('The picture could not be uploaded.')); }
                  this.uploading = null; input.value = '';
              },
          }">
    <legend class="fieldset-legend">{{ $txt($rowsField, 'label') ?? $rowsKey }}</legend>

    <div class="flex flex-col gap-2" x-sort="move($item, $position)" x-sort:config="{ delay: 150, delayOnTouchOnly: true }">
        <template x-for="(row, i) in rows" :key="row.id">
            <div class="flex items-start gap-3 rounded-box border border-base-300 p-2 bg-base-100" x-sort:item="row.id">
                <input type="hidden" :name="'settings[{{ $rowsKey }}][' + i + '][id]'" :value="row.id">
                <input type="hidden" :name="'settings[{{ $rowsKey }}][' + i + '][kind]'" :value="row.kind">

                <div class="flex flex-col items-center shrink-0">
                    <span class="cursor-move opacity-40 p-1 pointer-coarse:p-2" aria-hidden="true" x-sort:handle><x-icon name="grip-vertical" size="sm" /></span>
                    <span class="hidden pointer-coarse:flex flex-col">
                        <button type="button" class="btn btn-ghost btn-sm btn-square" :disabled="i === 0"
                                title="{{ __('Move earlier') }}" aria-label="{{ __('Move earlier') }}" x-on:click="move(row.id, i - 1)"><x-icon name="arrow-up" size="sm" /></button>
                        <button type="button" class="btn btn-ghost btn-sm btn-square" :disabled="i === rows.length - 1"
                                title="{{ __('Move later') }}" aria-label="{{ __('Move later') }}" x-on:click="move(row.id, i + 1)"><x-icon name="arrow-down" size="sm" /></button>
                    </span>
                </div>

                {{-- The picture first: it is what a tile is seen by. --}}
                <template x-for="part in parts.filter((p) => p.type === 'image' && has(p, row))" :key="part.key">
                    <div class="shrink-0 flex flex-col items-center gap-1">
                        <input type="hidden" :name="'settings[{{ $rowsKey }}][' + i + '][' + part.key + ']'" :value="row[part.key] || ''">
                        <label class="relative w-16 h-16 rounded-lg border border-dashed border-base-300 bg-base-200 overflow-hidden grid place-items-center cursor-pointer"
                               :title="part.label">
                            <img x-show="row[part.key + '__url']" :src="row[part.key + '__url']" alt="" class="w-full h-full object-contain">
                            <span x-show="!row[part.key + '__url']" class="opacity-50"><x-icon name="image-plus" size="md" /></span>
                            <span x-show="uploading === row.id + part.key" class="absolute inset-0 grid place-items-center bg-base-100/70"><span class="loading loading-spinner loading-sm"></span></span>
                            <input type="file" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" class="hidden"
                                   x-on:change="upload(row, part, $event.target)">
                        </label>
                        <button type="button" class="link link-hover text-xs opacity-60" x-show="row[part.key]"
                                x-on:click="row[part.key] = ''; row[part.key + '__url'] = null">{{ __('Remove') }}</button>
                    </div>
                </template>

                <div class="grow min-w-0 grid gap-2 sm:grid-cols-2">
                    <template x-for="part in parts.filter((p) => p.type !== 'image' && has(p, row))" :key="part.key">
                        <label class="flex flex-col gap-1 min-w-0" :class="part.wide && 'sm:col-span-2'">
                            <span class="text-xs opacity-60" x-text="part.label"></span>
                            <template x-if="part.type === 'pick'">
                                <select class="select w-full" :name="'settings[{{ $rowsKey }}][' + i + '][' + part.key + ']'" x-model="row[part.key]">
                                    <option value="" x-text="part.empty"></option>
                                    <template x-for="option in part.options" :key="option.value">
                                        <option :value="option.value" x-text="option.label" :selected="String(row[part.key]) === option.value"></option>
                                    </template>
                                </select>
                            </template>
                            <template x-if="part.type !== 'pick'">
                                <input class="input w-full" :class="part.type === 'class' && 'font-mono'"
                                       :name="'settings[{{ $rowsKey }}][' + i + '][' + part.key + ']'" x-model="row[part.key]"
                                       :placeholder="hint(part, row)" autocomplete="off">
                            </template>
                        </label>
                    </template>
                </div>

                <button type="button" class="btn btn-ghost btn-sm btn-square shrink-0" title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}"
                        x-on:click="rows.splice(i, 1)"><x-icon name="x" /></button>
            </div>
        </template>
        <p x-show="!rows.length" class="text-sm opacity-60">{{ $txt($rowsField, 'empty') ?? __('Nothing selected.') }}</p>
    </div>

    <p class="text-sm text-error" x-show="failed" x-text="failed" x-cloak></p>
    <div class="flex flex-wrap gap-2 mt-1">
        <template x-for="kind in kinds" :key="kind.value">
            <button type="button" class="btn btn-sm" x-on:click="add(kind.value)"><x-icon name="plus" size="sm" /> <span x-text="kind.label"></span></button>
        </template>
    </div>
    @if ($txt($rowsField, 'help'))
        <p class="label text-xs opacity-60 whitespace-normal">{{ $txt($rowsField, 'help') }}</p>
    @endif
</fieldset>
