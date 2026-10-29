@extends('admin.layout')

@section('title', __($definition['label']))
@section('own_heading', '1')

{{--
    One letter's words, in the content language picked at the top
    (LettersController). An empty box is the module's own words — shown as
    the placeholder, so it is clear what goes out when nothing is typed. The
    preview is the letter as it would go NOW, words in the form included,
    about a real record; "send to me" and the preview's refresh are buttons
    of the same form aimed elsewhere (formaction), never a form inside it.
--}}
@section('content')
    @php
        $defaults = new \App\Models\MailText(['event' => $event]);
        $placeholder = fn (string $field) => \App\Core\Mail\Letters::words($event, $field, $locale, $defaults);
        $help = (array) ($definition['help'] ?? []) + ['shop_name' => 'The shop\'s name', 'shop_url' => 'The shop\'s address'];
    @endphp

    <div class="flex flex-col gap-4"
         x-data="{ last: null, insert(token) {
             const f = this.last || this.$refs.body;
             if (! f) return;
             const s = f.selectionStart ?? f.value.length, e = f.selectionEnd ?? f.value.length;
             f.value = f.value.slice(0, s) + token + f.value.slice(e);
             f.focus(); f.selectionStart = f.selectionEnd = s + token.length;
         } }"
         @focusin="if ($event.target.matches('#letter-form input:not([type=checkbox]), #letter-form textarea')) last = $event.target">
        <x-admin.form-bar>
            <a href="{{ route('admin.letters.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to letters') }}" aria-label="{{ __('Back to letters') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ __('Letter') }} · {{ __($definition['group']) }}</div>
                <div class="font-semibold truncate leading-tight">{{ __($definition['label']) }}</div>
            </div>
            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                    <x-icon name="settings" size="lg" />
                </div>
                <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                    <li><button type="submit" form="letter-form" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                    @if ($hasSample)
                        <li><button type="submit" form="letter-form" formaction="{{ route('admin.letters.test', $event) }}" formmethod="POST"><x-icon name="send" /> {{ __('Send it to me') }}</button></li>
                    @endif
                </ul>
            </div>
            <x-slot:actions>
                <a href="{{ route('admin.letters.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                <button form="letter-form" class="btn btn-primary btn-sm">{{ __('Save') }}</button>
            </x-slot:actions>
        </x-admin.form-bar>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="alert alert-error text-sm">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            <div class="flex flex-col gap-4 min-w-0">
                <form id="letter-form" method="POST" action="{{ route('admin.letters.update', $event) }}" class="card bg-base-100 border border-base-300">
                    {{-- POST, no _method: the preview and "send to me" submit this
                         same form elsewhere, and a PUT would follow them there. --}}
                    @csrf
                    <div class="card-body gap-4">
                        <p class="text-sm opacity-60">{{ __('Leave a box empty to send the default words (shown faded inside it). A blank line starts a new paragraph; a {token} alone on its line draws that block.') }}</p>

                        <x-admin.translatable-field name="subject" :label="__('Subject')" :value="$row->subject ?? []" :placeholder="$placeholder('subject')" autocomplete="off" />
                        <x-admin.translatable-field name="preheader" :label="__('Preheader — the line beside the subject in the inbox')" :value="$row->preheader ?? []" :placeholder="$placeholder('preheader') ?: $placeholder('heading')" autocomplete="off" />
                        <x-admin.translatable-field name="heading" :label="__('Heading')" :value="$row->heading ?? []" :placeholder="$placeholder('heading')" autocomplete="off" />
                        <x-admin.translatable-field name="body" :label="__('Text')" :value="$row->body ?? []" :placeholder="$placeholder('body')" textarea :rows="10" x-ref="body" />
                        @if (! empty($definition['button']))
                            <x-admin.translatable-field name="button" :label="__('Button')" :value="$row->button ?? []" :placeholder="$placeholder('button')" autocomplete="off" />
                        @endif
                    </div>
                </form>

                {{-- The letter as it would go now. Refreshed by posting the form
                     above into the frame, so unsaved words show too. --}}
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="font-semibold">{{ __('Preview') }}</h3>
                            <button type="submit" form="letter-form" formaction="{{ route('admin.letters.preview', $event) }}" formmethod="POST" formtarget="letter-preview"
                                    class="btn btn-ghost btn-sm gap-1.5"><x-icon name="refresh-cw" size="sm" /> {{ __('Show with the words above') }}</button>
                        </div>
                        <iframe name="letter-preview" src="{{ route('admin.letters.preview', $event) }}" sandbox
                                class="w-full h-[85vh] min-h-[720px] rounded-box border border-base-300 bg-base-200"></iframe>
                    </div>
                </section>
            </div>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Status') }}</h3>
                        @if ($definition['required'])
                            <p class="text-sm opacity-70">{{ __('Always sent: signing in, getting back in or the account\'s safety depend on it.') }}</p>
                        @else
                            <label class="label cursor-pointer justify-start gap-3">
                                <input type="checkbox" name="enabled" value="1" form="letter-form" class="toggle toggle-sm toggle-success" @checked(old('enabled', $row->enabled ?? true))>
                                <span class="label-text">{{ __('Send this letter') }}</span>
                            </label>
                        @endif

                        {{-- Only a letter to the shop's OWN inbox takes addresses;
                             one to a particular manager goes to them. --}}
                        @if ($definition['audience'] === 'shop' && array_key_exists('recipients', $definition))
                            <fieldset class="fieldset p-0">
                                <legend class="fieldset-legend">{{ __('To whom') }}</legend>
                                <textarea name="recipients" rows="3" form="letter-form" class="textarea textarea-bordered w-full font-mono text-sm"
                                          placeholder="director@shop.com">{{ old('recipients', $row->recipients) }}</textarea>
                                <p class="label text-xs opacity-60 whitespace-normal">{{ __('One address per line.') }}</p>
                            </fieldset>
                        @endif
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-2 text-sm">
                        <h3 class="font-semibold">{{ __('Insert') }}</h3>
                        <p class="text-xs opacity-60">{{ __('Click to put it where the cursor was.') }}</p>
                        <div class="flex flex-col gap-1">
                            @foreach ($help as $token => $tokenLabel)
                                <button type="button" class="flex items-baseline gap-2 text-left rounded px-2 py-1 hover:bg-base-200"
                                        @click="insert(@js('{'.$token.'}'))">
                                    <code class="text-xs text-primary shrink-0">{{ '{'.$token.'}' }}</code>
                                    <span class="text-xs opacity-70">{{ __($tokenLabel) }}</span>
                                </button>
                            @endforeach
                        </div>
                        <p class="text-xs opacity-60 mt-1">{{ __('The shop\'s contacts work too: {phone}, {email}.') }}</p>
                    </div>
                </section>
            </div>
        </div>
    </div>
@endsection
