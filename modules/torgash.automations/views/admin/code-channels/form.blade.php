@extends('admin.layout')

@section('title', $channel->exists ? __('Edit channel') : __('New channel'))
@section('own_heading', '1')

{{--
    One way of delivering a code by an HTTP request (CodeChannelsController),
    in the admin's form shape: a sticky bar with Save, tabs over the main
    cards, Status and the tries beside them. The request and its rules are
    written out in full, because that is what is sent — a preset only filled
    them in. The try-it forms stand in the side column, never inside the
    channel form (a form inside a form leaks its fields into the outer submit).
--}}
@section('content')
    @php
        $tabs = ['general' => __('General'), 'request' => __('Request'), 'answer' => __('Answer rules')];

        $initialTab = request('tab');
        $initialTab = isset($tabs[$initialTab]) ? $initialTab : 'general';
        if ($errors->hasAny(['name', 'kinds', 'countries', 'daily_limit'])) {
            $initialTab = 'general';
        } elseif ($errors->hasAny(['method', 'url', 'headers', 'body_format', 'body', 'secrets'])) {
            $initialTab = 'request';
        } elseif ($errors->hasAny(['success_when', 'invalid_when', 'unreachable_when', 'check_url'])) {
            $initialTab = 'answer';
        }

        $panelStyle = fn (string $key) => $key === $initialTab ? '' : 'display:none';
    @endphp

    <div x-data="{ tab: '{{ $initialTab }}' }" class="flex flex-col gap-4">
        <x-admin.form-bar>
            <a href="{{ route('admin.automations.channels.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to code delivery') }}" aria-label="{{ __('Back to code delivery') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $channel->exists ? __('Edit channel') : __('New channel') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $channel->exists ? $channel->name : ($channel->name ?: __('New channel')) }}</div>
            </div>
            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                    <x-icon name="settings" size="lg" />
                </div>
                <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                    <li><button type="submit" form="code-channel-form" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                    @if ($channel->exists)
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="code-channel-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                    @endif
                </ul>
            </div>
            <x-slot:actions>
                <a href="{{ route('admin.automations.channels.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                <button form="code-channel-form" class="btn btn-primary btn-sm">{{ $channel->exists ? __('Save') : __('Create') }}</button>
            </x-slot:actions>
        </x-admin.form-bar>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="alert alert-error text-sm break-all">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            <div class="flex flex-col gap-4 min-w-0">
                <div role="tablist" class="tabs tabs-border">
                    @foreach ($tabs as $key => $label)
                        <a role="tab" class="tab" href="#" @click.prevent="tab = '{{ $key }}'"
                           :class="tab === '{{ $key }}' ? 'tab-active font-semibold' : ''">{{ $label }}</a>
                    @endforeach
                </div>

                <form id="code-channel-form" method="POST"
                      action="{{ $channel->exists ? route('admin.automations.channels.update', $channel) : route('admin.automations.channels.store') }}"
                      class="flex flex-col gap-4">
                    @csrf
                    @if ($channel->exists) @method('PUT') @endif
                    <input type="hidden" name="tab" :value="tab">
                    <input type="hidden" name="preset" value="{{ $channel->preset }}">

                    {{-- ── General ───────────────────────────────────────── --}}
                    <div x-show="tab === 'general'" style="{{ $panelStyle('general') }}">
                        <section class="card bg-base-100 border border-base-300">
                            <div class="card-body gap-4">
                                <fieldset class="fieldset p-0">
                                    <legend class="fieldset-legend">{{ __('Name') }} *</legend>
                                    <input name="name" value="{{ old('name', $channel->name) }}" required autocomplete="off" class="input input-bordered w-full">
                                    <p class="label text-xs opacity-60 whitespace-normal">{{ __('The customer sees it on the button: "Send the code by …".') }}</p>
                                </fieldset>

                                <fieldset class="fieldset p-0">
                                    <legend class="fieldset-legend">{{ __('Delivers to') }} *</legend>
                                    <div class="flex flex-wrap gap-4">
                                        @foreach (['phone' => __('Phone'), 'email' => 'Email'] as $kind => $kindLabel)
                                            <label class="label cursor-pointer justify-start gap-2">
                                                <input type="checkbox" name="kinds[]" value="{{ $kind }}" class="checkbox checkbox-sm" @checked(in_array($kind, old('kinds', (array) $channel->kinds), true))>
                                                <span class="label-text">{{ $kindLabel }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>

                                <div class="grid sm:grid-cols-[minmax(0,1fr)_14rem] gap-4">
                                    <fieldset class="fieldset p-0">
                                        <legend class="fieldset-legend">{{ __('Only to these countries') }}</legend>
                                        <input name="countries" value="{{ old('countries', $channel->countries) }}" class="input input-bordered w-full font-mono" placeholder="UA PL">
                                        <p class="label text-xs opacity-60 whitespace-normal">{{ __('Empty — any. A paid channel should name its countries: a form that sends codes on demand is what gets pointed at premium numbers abroad.') }}</p>
                                    </fieldset>
                                    <fieldset class="fieldset p-0">
                                        <legend class="fieldset-legend">{{ __('Codes a day, at most') }}</legend>
                                        <input name="daily_limit" type="number" min="1" value="{{ old('daily_limit', $channel->daily_limit) }}" class="input input-bordered w-full">
                                        <p class="label text-xs opacity-60 whitespace-normal">{{ __('Past it the channel stops offering itself until tomorrow.') }}</p>
                                    </fieldset>
                                </div>
                            </div>
                        </section>
                    </div>

                    {{-- ── Request ───────────────────────────────────────── --}}
                    <div x-show="tab === 'request'" style="{{ $panelStyle('request') }}">
                        <section class="card bg-base-100 border border-base-300">
                            <div class="card-body gap-4">
                                <div class="grid sm:grid-cols-[9rem_minmax(0,1fr)] gap-4">
                                    <fieldset class="fieldset p-0">
                                        <legend class="fieldset-legend">{{ __('Method') }}</legend>
                                        <select name="method" class="select select-bordered w-full">
                                            @foreach (['POST', 'GET', 'PUT'] as $method)
                                                <option @selected(old('method', $channel->method) === $method)>{{ $method }}</option>
                                            @endforeach
                                        </select>
                                    </fieldset>
                                    <fieldset class="fieldset p-0">
                                        <legend class="fieldset-legend">{{ __('Address') }} *</legend>
                                        <input name="url" value="{{ old('url', $channel->url) }}" required class="input input-bordered w-full font-mono text-sm">
                                    </fieldset>
                                </div>

                                <fieldset class="fieldset p-0">
                                    <legend class="fieldset-legend">{{ __('Headers, one per line') }}</legend>
                                    <textarea name="headers" rows="2" class="textarea textarea-bordered w-full font-mono text-sm">{{ old('headers', $channel->headers) }}</textarea>
                                </fieldset>

                                <div class="grid sm:grid-cols-[11rem_minmax(0,1fr)] gap-4">
                                    <fieldset class="fieldset p-0">
                                        <legend class="fieldset-legend">{{ __('Body') }}</legend>
                                        <select name="body_format" class="select select-bordered w-full">
                                            @foreach (['json' => 'JSON', 'form' => __('Form fields'), 'none' => __('No body')] as $format => $formatLabel)
                                                <option value="{{ $format }}" @selected(old('body_format', $channel->body_format) === $format)>{{ $formatLabel }}</option>
                                            @endforeach
                                        </select>
                                    </fieldset>
                                    <fieldset class="fieldset p-0">
                                        <legend class="fieldset-legend">&nbsp;</legend>
                                        <textarea name="body" rows="6" class="textarea textarea-bordered w-full font-mono text-sm">{{ old('body', $channel->body) }}</textarea>
                                    </fieldset>
                                </div>

                                <fieldset class="fieldset p-0">
                                    <legend class="fieldset-legend">{{ __('Secrets, one per line: name=value') }}</legend>
                                    <textarea name="secrets" rows="2" autocomplete="off" class="textarea textarea-bordered w-full font-mono text-sm">{{ $secretsText }}</textarea>
                                    <p class="label text-xs opacity-60 whitespace-normal">{{ __('Written into the request as :token. Values are stored encrypted and never shown again — an empty value keeps the saved one, a line removed removes the secret.', ['token' => '{'.'{ secret.name }'.'}']) }}</p>
                                </fieldset>
                            </div>
                        </section>
                    </div>

                    {{-- ── Answer rules ──────────────────────────────────── --}}
                    <div x-show="tab === 'answer'" style="{{ $panelStyle('answer') }}">
                        <section class="card bg-base-100 border border-base-300">
                            <div class="card-body gap-4">
                                <p class="text-sm opacity-60">{{ __('One rule per line: path.in.the.answer = value (several values with |). A reply other than 2xx is never a success.') }}</p>
                                @foreach (['success_when' => __('Sent, when ALL hold'), 'invalid_when' => __('Wrong number, when ANY holds'), 'unreachable_when' => __('Cannot reach it, when ANY holds')] as $field => $fieldLabel)
                                    <fieldset class="fieldset p-0">
                                        <legend class="fieldset-legend">{{ $fieldLabel }}</legend>
                                        <textarea name="{{ $field }}" rows="3" class="textarea textarea-bordered w-full font-mono text-sm">{{ old($field, $channel->{$field}) }}</textarea>
                                    </fieldset>
                                @endforeach
                                <fieldset class="fieldset p-0">
                                    <legend class="fieldset-legend">{{ __('Free check request (GET)') }}</legend>
                                    <input name="check_url" value="{{ old('check_url', $channel->check_url) }}" class="input input-bordered w-full font-mono text-sm" placeholder="https://…/balance">
                                    <p class="label text-xs opacity-60 whitespace-normal">{{ __('A request that proves the key works without sending a message, such as the balance.') }}</p>
                                </fieldset>
                            </div>
                        </section>
                    </div>
                </form>
            </div>

            {{-- Beside every tab. Status belongs to the channel form, which it
                 is not inside, so its fields name it with form="". --}}
            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Status') }}</h3>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="enabled" value="1" form="code-channel-form" class="toggle toggle-sm toggle-success" @checked(old('enabled', $channel->enabled))>
                            <span class="label-text">{{ __('Enabled') }}</span>
                        </label>
                        <p class="text-xs opacity-60">{{ __('Switched off, it is not offered to customers; its own test still works.') }}</p>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Position') }}</legend>
                            <input name="position" type="number" form="code-channel-form" value="{{ old('position', $channel->position ?? 0) }}" class="input input-bordered w-full">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('Order of the ways offered to the customer, smallest first.') }}</p>
                        </fieldset>
                        @if ($channel->exists)
                            <p class="text-xs opacity-60">{{ __('Sent today: :sent of :limit', ['sent' => $sentToday, 'limit' => $channel->daily_limit]) }}</p>
                        @endif
                    </div>
                </section>

                @if ($channel->exists)
                    <section class="card bg-base-100 border border-base-300">
                        <div class="card-body gap-3 text-sm">
                            <h3 class="font-semibold">{{ __('Try it') }}</h3>
                            <form method="POST" action="{{ route('admin.automations.channels.check', $channel) }}">
                                @csrf
                                <button class="btn btn-sm w-full gap-1.5" @disabled(! $channel->check_url)><x-icon name="plug-zap" size="sm" /> {{ __('Check the connection') }}</button>
                            </form>
                            @if ($check ?? null)
                                <div @class(['alert text-xs py-2 break-all', 'alert-success' => $check['ok'], 'alert-error' => ! $check['ok']])>
                                    <span>{{ $check['status'] ? 'HTTP '.$check['status'].': ' : '' }}{{ $check['body'] }}</span>
                                </div>
                            @endif

                            <form method="POST" action="{{ route('admin.automations.channels.test', $channel) }}" class="flex flex-col gap-2 border-t border-base-300 pt-3">
                                @csrf
                                <input name="address" required class="input input-bordered w-full font-mono"
                                       placeholder="{{ in_array('phone', (array) $channel->kinds, true) ? '380671234567' : 'me@example.com' }}">
                                <button class="btn btn-sm btn-outline w-full gap-1.5"><x-icon name="send" size="sm" /> {{ __('Send a test code') }}</button>
                                <p class="text-xs opacity-60">{{ __('One real message — it costs what the provider charges, and counts against the day. Save the form first: the test uses what is saved.') }}</p>
                            </form>
                        </div>
                    </section>
                @endif

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-2 text-sm">
                        <h3 class="font-semibold">{{ __('In the request') }}</h3>
                        <ul class="font-mono text-xs flex flex-col gap-1">
                            <li>@{{ phone }} <span class="font-sans opacity-60">— 380671234567</span></li>
                            <li>@{{ phone_plus }} <span class="font-sans opacity-60">— +380671234567</span></li>
                            <li>@{{ email }}</li>
                            <li>@{{ code }} <span class="font-sans opacity-60">— {{ __('six digits') }}</span></li>
                            <li>@{{ text }} <span class="font-sans opacity-60">— {{ __('the whole message, in the customer\'s language') }}</span></li>
                            <li>@{{ secret.name }}</li>
                        </ul>
                    </div>
                </section>
            </div>
        </div>
    </div>

    @if ($channel->exists)
        <form method="POST" id="code-channel-delete" action="{{ route('admin.automations.channels.destroy', $channel) }}"
              data-confirm="{{ __('Delete :name?', ['name' => $channel->name]) }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
