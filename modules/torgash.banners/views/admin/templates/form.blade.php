@extends('admin.layout')

@section('title', $template->exists ? $template->label() : ($template->isFrame() ? __('New frame') : __('New banner template')))
@section('own_heading', '1')

@section('content')
    @php
        $isFrame = $template->isFrame();
        $htmlTabs = $isFrame ? ['frame' => __('Frame')] : ['wide' => __('Wide slide'), 'card' => __('Upright card'), 'strip' => __('Low strip')];
        $editHtml = [];
        foreach ($htmlTabs as $tabKey => $tabLabel) {
            $editHtml[$tabKey] = (string) (old('html.'.$tabKey, ($template->html ?? [])[$tabKey] ?? ''));
        }
        // The fields as the form edits them: a choice's options as "value | Label" lines.
        $editFields = collect($template->fieldList())->mapWithKeys(fn ($field) => [$field['key'] => [
            'key' => $field['key'],
            'type' => $field['type'],
            'label' => $field['label'],
            'help' => $field['help'],
            'default' => is_bool($field['default']) ? ($field['default'] ? '1' : '') : (string) $field['default'],
            'options' => collect($field['options'])->map(fn ($option) => $option['value'].($option['label'] !== $option['value'] ? ' | '.$option['label'] : ''))->implode("\n"),
            'on' => $field['on'],
            'fallback' => $field['fallback'] ?? '',
        ]])->all();
        $typeNames = [
            'text' => __('Line of text'), 'rich' => __('Text with tags'), 'image' => __('Picture'), 'link' => __('Link'),
            'color' => __('Colour'), 'select' => __('Choice'), 'checkbox' => __('Switch'), 'icon' => __('Icon'),
            'links' => __('Links (chips)'),
            'video' => __('Video (file)'),
        ];
    @endphp

    <form method="POST" id="template-form"
          action="{{ $template->exists ? route('admin.banners.templates.update', $template) : route('admin.banners.templates.store') }}"
          class="flex flex-col gap-4"
          x-data="{
              html: @js($editHtml),
              tab: @js(array_key_first($htmlTabs)),
              fields: @js((object) $editFields),
              engine: @js($engine),
              typeNames: @js($typeNames),
              preview: '',
              timer: null,
              get tokens() {
                  const found = [];
                  const pattern = /\{([a-z][a-z0-9_-]*)(?::[^}|]*)?((?:\|[a-z_]+)*)\}/gi;
                  for (const text of Object.values(this.html)) {
                      for (const m of text.matchAll(pattern)) {
                          const key = m[1].toLowerCase();
                          if (!this.engine.includes(key) && !found.includes(key)) found.push(key);
                      }
                  }
                  // The fields keep the order they have; a new token joins at the end.
                  const known = Object.keys(this.fields).filter((key) => found.includes(key));
                  return [...known, ...found.filter((key) => !known.includes(key))];
              },
              row(key) {
                  if (!this.fields[key]) {
                      this.fields[key] = { key, type: 'text', label: key.charAt(0).toUpperCase() + key.slice(1).replace(/[-_]/g, ' '), help: '', default: '', options: '', on: key, fallback: '' };
                  }
                  return this.fields[key];
              },
              refresh() {
                  clearTimeout(this.timer);
                  this.timer = setTimeout(async () => {
                      const data = new FormData(this.$root);
                      data.delete('_method');
                      const answer = await fetch(@js(route('admin.banners.templates.preview')), {
                          method: 'POST', body: data,
                          headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                      });
                      // A srcdoc frame runs under THIS page's script policy, so the
                      // preview's scripts take this page's nonce (PLAN §53).
                      this.preview = answer.ok ? (await answer.text()).replace(/nonce=\x22[^\x22]*\x22/g, 'nonce=\x22{{ csp_nonce() }}\x22') : '<p style=\'font:14px system-ui;color:#b00\'>HTTP ' + answer.status + '</p>';
                  }, 700);
              },
              init() { this.refresh(); },
          }"
          x-on:input="refresh()" x-on:change="refresh()">
        @csrf
        @if ($template->exists) @method('PUT') @endif
        <input type="hidden" name="kind" value="{{ $template->kind }}">

        <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
            <a href="{{ route('admin.banners.templates.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to templates') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $isFrame ? __('Frame') : __('Banner template') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $template->exists ? $template->label() : ($isFrame ? __('New frame') : __('New banner template')) }}</div>
            </div>
            @if ($template->exists)
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-64 mt-2 p-2 z-50 border border-base-300">
                        <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                        <li><a href="{{ route('admin.banners.templates.export', $template) }}"><x-icon name="download" /> {{ __('Export') }}</a></li>
                        @if ($preset)
                            <li><button type="submit" form="template-restore"><x-icon name="rotate-ccw" /> {{ __('Restore from the preset') }}</button></li>
                        @endif
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="template-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                    </ul>
                </div>
            @endif
            <a href="{{ route('admin.banners.templates.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button class="btn btn-primary btn-sm">{{ $template->exists ? __('Save') : __('Create') }}</button>
        </div>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif
        @if ($differs)
            <div class="alert text-sm">{{ __('Changed since it came from the preset ":preset". "Restore from the preset" in the gear menu puts it back; the banners keep what was typed into them.', ['preset' => $preset['preset'] ?? '']) }}</div>
        @endif

        <div class="grid gap-4 2xl:grid-cols-[minmax(0,1fr)_26rem] items-start">
            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <div class="grid gap-4 md:grid-cols-3">
                            <fieldset class="fieldset p-0 md:col-span-{{ $template->exists ? 2 : 1 }}"><legend class="fieldset-legend">{{ __('Name') }} *</legend>
                                <input name="name" value="{{ old('name', $template->exists ? $template->label() : '') }}" required maxlength="190" class="input input-bordered w-full"
                                       placeholder="{{ $isFrame ? __('Slider with a column') : __('Card with chips') }}">
                            </fieldset>
                            @unless ($template->exists)
                                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Key') }} *</legend>
                                    <input name="key" value="{{ old('key') }}" required maxlength="64" pattern="[a-z][a-z0-9_-]*" class="input input-bordered w-full font-mono" placeholder="promo-card">
                                    <p class="label text-xs opacity-60 whitespace-normal">{{ __('Latin, for CSS: [data-template=promo-card].') }}</p>
                                </fieldset>
                            @endunless
                            @if ($isFrame)
                                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Shape of its slots') }}</legend>
                                    <select name="shape" class="select select-bordered w-full">
                                        @foreach (['wide' => __('Wide slide'), 'card' => __('Upright card'), 'strip' => __('Low strip')] as $shapeKey => $shapeLabel)
                                            <option value="{{ $shapeKey }}" @selected(old('shape', $template->shape) === $shapeKey)>{{ $shapeLabel }}</option>
                                        @endforeach
                                    </select>
                                </fieldset>
                            @endif
                        </div>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <div class="flex items-center justify-between gap-3 flex-wrap">
                            <h3 class="font-semibold">HTML</h3>
                            @unless ($isFrame)
                                <div role="tablist" class="tabs tabs-box tabs-sm">
                                    @foreach ($htmlTabs as $tabKey => $tabLabel)
                                        <button type="button" role="tab" class="tab gap-1.5" :class="tab === @js($tabKey) && 'tab-active'" @click="tab = @js($tabKey)">
                                            {{ $tabLabel }}
                                            <span class="w-1.5 h-1.5 rounded-full bg-success" x-show="html[@js($tabKey)].trim() !== ''" x-cloak></span>
                                        </button>
                                    @endforeach
                                </div>
                            @endunless
                        </div>
                        @foreach ($htmlTabs as $tabKey => $tabLabel)
                            <textarea name="html[{{ $tabKey }}]" x-model="html[@js($tabKey)]" x-show="tab === @js($tabKey)" rows="16" spellcheck="false"
                                      class="textarea textarea-bordered w-full font-mono text-xs leading-relaxed"
                                      placeholder="{{ $isFrame ? '' : __('Empty — this template does not draw this shape.') }}"></textarea>
                        @endforeach
                        <details class="text-sm">
                            <summary class="cursor-pointer opacity-70">{{ __('How to write it') }}</summary>
                            <div class="mt-2 flex flex-col gap-1.5 opacity-80">
                                <p>{{ __('{title} — a field: it appears on the right, where you say what it is. A banner\'s form shows those fields, never this HTML.') }}</p>
                                <p>{{ __('[<p>{text}</p>] — square brackets vanish with what is inside them when the field in them is empty.') }}</p>
                                <p>{{ __('{alt|optional} — may be empty without taking its [ ] away.') }}</p>
                                @if ($isFrame)
                                    <p>{{ __('Filled by the engine: {slides} the strip that turns, {items} all banners at once with no script, {dots}, {prev}, {next}, {counter}, {progress}, {aside} the slider chosen "Beside it".') }}</p>
                                @else
                                    <p>{{ __('A picture: {image} its address, {image|width} and {image|height} its size, {image|small} a thumbnail. {loading} — the first slide\'s picture at once, the others when near.') }}</p>
                                    <p>{{ __('Each banner is wrapped in <div data-template="key" data-shape="wide|card|strip">, so the CSS can start with [data-template=key].') }}</p>
                                @endif
                                <p>{{ __('A <style> written here moves to the CSS on saving; a <script> is removed — scripts belong in Code snippets.') }}</p>
                            </div>
                        </details>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">CSS</h3>
                        <textarea name="css" rows="12" spellcheck="false" class="textarea textarea-bordered w-full font-mono text-xs leading-relaxed">{{ old('css', $template->css) }}</textarea>
                        <p class="text-xs opacity-60">{{ __('Printed once on a page that shows this template, and nowhere else.') }}</p>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="font-semibold">{{ __('Preview') }}</h3>
                            <span class="text-xs opacity-60">{{ __('Sample values; updates as you type.') }}</span>
                        </div>
                        <iframe :srcdoc="preview" class="w-full rounded-box border border-base-300 bg-base-200" style="height: 34rem" title="{{ __('Preview') }}"></iframe>
                    </div>
                </section>
            </div>

            <section class="card bg-base-100 border border-base-300 2xl:sticky 2xl:top-36">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Fields') }}</h3>
                    <p class="text-xs opacity-60">{{ __('Every {token} of the HTML. Say what each one is; the banner\'s form asks for it that way.') }}</p>
                    <p class="text-sm opacity-60" x-show="!tokens.length">{{ __('No {tokens} in the HTML yet.') }}</p>
                    <template x-for="(key, i) in tokens" :key="key">
                        <div class="rounded-box border border-base-300 p-3 flex flex-col gap-2">
                            <div class="flex items-center gap-2">
                                <code class="font-mono text-sm" x-text="'{' + key + '}'"></code>
                                <input type="hidden" :name="'fields[' + i + '][key]'" :value="key">
                                <select :name="'fields[' + i + '][type]'" x-model="row(key).type" class="select select-bordered select-sm ml-auto w-40">
                                    <template x-for="(name, type) in typeNames" :key="type">
                                        <option :value="type" x-text="name" :selected="row(key).type === type"></option>
                                    </template>
                                </select>
                            </div>
                            <input :name="'fields[' + i + '][label]'" x-model="row(key).label" class="input input-bordered w-full" placeholder="{{ __('Label in the banner\'s form') }}">
                            <input :name="'fields[' + i + '][help]'" x-model="row(key).help" class="input input-bordered w-full" placeholder="{{ __('Hint under it (optional)') }}">
                            <template x-if="row(key).type === 'select'">
                                <textarea :name="'fields[' + i + '][options]'" x-model="row(key).options" rows="3" class="textarea textarea-bordered w-full font-mono text-xs"
                                          placeholder="light | {{ __('Light') }}&#10;dark | {{ __('Dark') }}"></textarea>
                            </template>
                            <template x-if="row(key).type === 'checkbox'">
                                <input :name="'fields[' + i + '][on]'" x-model="row(key).on" class="input input-bordered w-full font-mono" placeholder="{{ __('What it prints when on, e.g. a class') }}">
                            </template>
                            <template x-if="row(key).type === 'image'">
                                <input :name="'fields[' + i + '][fallback]'" x-model="row(key).fallback" class="input input-bordered w-full font-mono" placeholder="{{ __('Without a picture of its own, take this field\'s (optional)') }}">
                            </template>
                            <template x-if="['text', 'color', 'select', 'checkbox', 'link', 'icon'].includes(row(key).type)">
                                <input :name="'fields[' + i + '][default]'" x-model="row(key).default" class="input input-bordered w-full" placeholder="{{ __('Default (optional)') }}">
                            </template>
                        </div>
                    </template>
                </div>
            </section>
        </div>
    </form>

    @if ($template->exists)
        @if ($preset)
            <form id="template-restore" method="POST" action="{{ route('admin.banners.templates.restore', $template) }}" class="hidden"
                  data-confirm="{{ __('Put the HTML, the CSS and the fields back as the preset has them? What you changed here is lost; the banners keep their values.') }}">@csrf</form>
        @endif
        <form id="template-delete" method="POST" action="{{ route('admin.banners.templates.destroy', $template) }}" class="hidden"
              data-confirm="{{ __('Delete this template?') }}">@csrf @method('DELETE')</form>
    @endif
@endsection
