@extends('admin.layout')

@section('title', $snippet->exists ? $snippet->name : __('New snippet'))
@section('own_heading', '1')

@section('content')
    <form method="POST"
          action="{{ $snippet->exists ? route('admin.snippets.update', $snippet) : route('admin.snippets.store') }}"
          class="flex flex-col gap-4">
        @csrf
        @if ($snippet->exists) @method('PUT') @endif

        <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
            <a href="{{ route('admin.snippets.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to snippets') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $snippet->exists ? __('Edit snippet') : __('New snippet') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $snippet->exists ? $snippet->name : __('New snippet') }}</div>
            </div>
            @if ($snippet->exists)
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                        <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                        <li><button type="submit" form="snippet-duplicate"><x-icon name="copy" /> {{ __('Duplicate') }}</button></li>
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="snippet-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                    </ul>
                </div>
            @endif
            <a href="{{ route('admin.snippets.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button class="btn btn-primary btn-sm">{{ $snippet->exists ? __('Save') : __('Create') }}</button>
        </div>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Code') }}</h3>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Name') }}</legend>
                            <input name="name" value="{{ old('name', $snippet->name) }}" required autocomplete="off"
                                   class="input input-bordered w-full" placeholder="{{ __('Google Analytics, Meta pixel, the chat widget…') }}">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('For your own list — it is never printed on the page.') }}</p>
                        </fieldset>

                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Code') }}</legend>
                            {{-- A plain textarea, deliberately: the rich editor would
                                 rewrite what is pasted here, and the whole point is that
                                 the code reaches the page exactly as the provider gave
                                 it to the shop. --}}
                            <textarea name="code" id="snippet-code" rows="16" spellcheck="false"
                                      class="textarea textarea-bordered w-full font-mono text-xs leading-relaxed"
                                      placeholder="&lt;script&gt;…&lt;/script&gt;">{{ old('code', $snippet->code) }}</textarea>
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('Printed exactly as written — including the script tags.') }} {{ __('Anything the shop does not recognise is left alone, so ordinary JavaScript is safe to paste.') }}</p>
                        </fieldset>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <x-admin.display-rules
                            :rules="$snippet->rules ?? []"
                            :match="$snippet->rules_match ?? 'all'"
                            :groups="['where', 'when', 'who']"
                            :heading="__('Shown when')"
                            :hint="__('No conditions means every storefront page — never the admin panel. A condition whose module is switched off hides the snippet rather than being ignored.')" />
                    </div>
                </section>
            </div>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Status') }}</h3>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success" @checked(old('enabled', $snippet->enabled ?? true))>
                            <span class="label-text">{{ __('Printed') }}</span>
                        </label>

                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Where on the page') }}</legend>
                            <select name="placement" class="select select-bordered w-full">
                                <option value="head" @selected(old('placement', $snippet->placement) === 'head')>{{ __('Page head') }}</option>
                                <option value="body_start" @selected(old('placement', $snippet->placement) === 'body_start')>{{ __('Start of the page body') }}</option>
                                <option value="footer" @selected(old('placement', $snippet->placement) === 'footer')>{{ __('End of the page') }}</option>
                            </select>
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('Counters usually go in the head; a <noscript> pixel at the start of the body; a chat widget at the end.') }}</p>
                        </fieldset>

                        @php
                            $snippetCategory = old('category', $snippet->category ?: 'necessary');
                            $snippetGuess = \Torgash\Snippets\Models\Snippet::guessCategory((string) $snippet->code);
                        @endphp
                        <fieldset class="fieldset p-0" x-data="{ onClick: @js((bool) old('on_click', $snippet->on_click)) }">
                            <legend class="fieldset-legend">{{ __('What it is, for consent') }}</legend>
                            <select name="category" class="select select-bordered w-full">
                                @foreach (\App\Core\Privacy\Consent::CATEGORIES as $categoryKey => $categoryLabel)
                                    <option value="{{ $categoryKey }}" @selected($snippetCategory === $categoryKey)>{{ __($categoryLabel) }}</option>
                                @endforeach
                            </select>
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('Anything but "Necessary" waits until the visitor agrees to that kind of code — when a privacy module asks them. Your own code that talks to no other service is necessary.') }}</p>
                            @if ($snippetGuess && $snippetGuess !== $snippetCategory)
                                <p class="text-xs text-warning whitespace-normal">{{ __('The addresses in this code look like ":category".', ['category' => __(\App\Core\Privacy\Consent::CATEGORIES[$snippetGuess])]) }}</p>
                            @endif
                            <label class="label cursor-pointer justify-start gap-3 mt-2">
                                <input type="checkbox" name="on_click" value="1" class="toggle toggle-sm" x-model="onClick">
                                <span class="label-text whitespace-normal">{{ __('Load on click — a button on the page, the code only after it is pressed') }}</span>
                            </label>
                            <div x-show="onClick" class="mt-1">
                                <x-admin.translatable-field name="click_label" :label="__('Button')" :value="$snippet->click_label ?? []" :placeholder="__('Chat')" />
                                <p class="text-xs opacity-60 whitespace-normal">{{ __('For a chat: the page stays light, and pressing the button is the visitor asking for it.') }}</p>
                            </div>
                        </fieldset>

                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Storefront') }}</legend>
                            <select name="storefront_id" class="select select-bordered w-full">
                                <option value="">{{ __('All storefronts') }}</option>
                                @foreach ($storefronts as $id => $name)
                                    <option value="{{ $id }}" @selected((int) old('storefront_id', $snippet->storefront_id) === (int) $id)>{{ $name }}</option>
                                @endforeach
                            </select>
                        </fieldset>

                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Position') }}</legend>
                            <input name="position" type="number" value="{{ old('position', $snippet->position ?? 0) }}" class="input input-bordered w-full">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('The order among the snippets printed in the same place.') }}</p>
                        </fieldset>
                    </div>
                </section>

                {{-- The tokens as buttons that type themselves at the cursor.
                     Braces are built in JavaScript: Blade reads a literal pair
                     in a template as its own echo. --}}
                <section class="card bg-base-100 border border-base-300"
                         x-data="{ put(token) {
                             const field = document.getElementById('snippet-code');
                             const text = String.fromCharCode(123) + token + String.fromCharCode(125);
                             const at = field.selectionStart ?? field.value.length;
                             field.setRangeText(text, at, field.selectionEnd ?? at, 'end');
                             field.focus();
                         } }">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Values') }}</h3>
                        <p class="text-xs opacity-60">{{ __('Click to insert at the cursor. Bare values with no currency sign — what an analytics tag expects.') }}</p>
                        <div class="text-xs font-semibold opacity-60">{{ __('Anywhere') }}</div>
                        <div class="flex flex-wrap gap-1">
                            @foreach (['storefront', 'currency'] as $token)
                                <button type="button" class="btn btn-sm font-mono" @click="put('{{ $token }}')">{{ $token }}</button>
                            @endforeach
                        </div>
                        <div class="text-xs font-semibold opacity-60">{{ __('On the order confirmation page') }}</div>
                        <div class="flex flex-wrap gap-1">
                            @foreach (['order_number', 'order_total', 'order_currency', 'order_items_json'] as $token)
                                <button type="button" class="btn btn-sm font-mono" @click="put('{{ $token }}')">{{ $token }}</button>
                            @endforeach
                        </div>
                        <p class="text-xs opacity-60">{{ __('For the confirmation page only, add the condition "Page is the route checkout.confirmation".') }}</p>
                    </div>
                </section>
            </div>
        </div>
    </form>

    {{-- Beside the form, never inside it — see docs/LESSONS.md. --}}
    @if ($snippet->exists)
        <form id="snippet-duplicate" method="POST" action="{{ route('admin.snippets.duplicate', $snippet) }}" class="hidden">@csrf</form>
        <form id="snippet-delete" method="POST" action="{{ route('admin.snippets.destroy', $snippet) }}" class="hidden"
              data-confirm="{{ __('Delete this snippet?') }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
