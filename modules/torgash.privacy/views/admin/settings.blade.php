@extends('admin.layout')

{{--
    Personal data → Settings (PLAN §49). Choosing whose law fills in the rest
    of the form (Alpine, from the presets) — nothing is saved until Save, and
    every value can be changed after the preset filled it.
--}}
@section('title', __('Personal data'))

@section('content')
    @php
        $regimes = [
            'eu' => __('European Union (GDPR)'),
            'us' => __('United States (CCPA and the like)'),
            'ru' => __('Russia (152-FZ)'),
            'ua' => __('Ukraine'),
            'custom' => __('My own settings'),
        ];
        $categoryWords = collect(\App\Core\Privacy\Consent::CATEGORIES)->map(fn ($label) => __($label));
    @endphp

    <form method="POST" action="{{ route('admin.privacy.settings.update') }}" class="flex flex-col gap-4"
          x-data="{
              v: {{ \Illuminate\Support\Js::from(old() ?: $values) }},
              presets: {{ \Illuminate\Support\Js::from($presets) }},
              apply() {
                  const p = this.presets[this.v.regime];
                  if (!p) return;
                  this.v.consent_mode = p.consent_mode;
                  this.v.respect_gpc = p.respect_gpc;
                  this.v.google_consent_mode = p.google_consent_mode;
                  this.v.answer_days = p.answer_days;
                  for (const key in p.retention) if (key in this.v.retention) this.v.retention[key] = p.retention[key];
              },
          }">
        @csrf
        @method('PUT')

        @include('torgash.privacy::admin._tabs')

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-2 items-start">
            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-4">
                    <h3 class="font-semibold">{{ __('Whose law') }}</h3>
                    <select name="regime" x-model="v.regime" @change="apply()" class="select select-bordered w-full">
                        @foreach ($regimes as $regimeKey => $regimeLabel)
                            <option value="{{ $regimeKey }}">{{ $regimeLabel }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs opacity-70">{{ __('Choosing fills in the settings below the way that law asks; change any of them afterwards. Not legal advice: the law also asks for things no software does — the policy\'s text, notifying the regulator, where the server stands.') }}</p>

                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('Cookies and counters') }}</legend>
                        <select name="consent_mode" x-model="v.consent_mode" class="select select-bordered w-full">
                            <option value="opt_in">{{ __('Ask first — nothing but the necessary until the visitor agrees') }}</option>
                            <option value="opt_out">{{ __('Allowed until the visitor says no') }}</option>
                            <option value="off">{{ __('Do not ask — everything runs') }}</option>
                        </select>
                    </fieldset>

                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('The cookie banner') }}</legend>
                        <select name="banner_style" x-model="v.banner_style" class="select select-bordered w-full">
                            <option value="bar">{{ __('A strip along the bottom') }}</option>
                            <option value="corner">{{ __('A card in the corner') }}</option>
                        </select>
                    </fieldset>

                    <label class="label cursor-pointer justify-start gap-3">
                        <input type="checkbox" name="google_consent_mode" value="1" class="toggle toggle-sm" x-model="v.google_consent_mode">
                        <span class="label-text whitespace-normal">{{ __('Google Consent Mode v2') }}</span>
                    </label>
                    <label class="label cursor-pointer justify-start gap-3">
                        <input type="checkbox" name="respect_gpc" value="1" class="toggle toggle-sm" x-model="v.respect_gpc">
                        <span class="label-text whitespace-normal">{{ __('Honour the browser\'s Global Privacy Control') }}</span>
                    </label>

                    <div class="grid sm:grid-cols-2 gap-3">
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ __('Privacy policy page') }}</legend>
                            <input name="policy_url" x-model="v.policy_url" class="input input-bordered w-full" placeholder="/privacy">
                        </fieldset>
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ __('Consent to processing (a separate document)') }}</legend>
                            <input name="consent_doc_url" x-model="v.consent_doc_url" class="input input-bordered w-full" placeholder="/consent">
                        </fieldset>
                    </div>

                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('Answer a request within, days') }}</legend>
                        <input type="number" min="1" name="answer_days" x-model="v.answer_days" class="input input-bordered w-32">
                    </fieldset>

                    <p class="text-xs opacity-70">{!! __('To let a visitor change their choice later, link :link from the footer menu or the policy page.', ['link' => '<code class="font-mono">#cookie-settings</code>']) !!}</p>
                </div>
            </section>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('How long it is kept') }}</h3>
                        <p class="text-xs opacity-70">{{ __('Cleared every night once older than this. 0 — kept for ever.') }}</p>
                        <table class="table table-sm">
                            <tbody>
                                @foreach ($retained as $retainedKey => $retainedEntry)
                                    <tr>
                                        <td>{{ label_text($retainedEntry['label'], null, $retainedKey) }}</td>
                                        <td class="w-36">
                                            <label class="input input-bordered input-sm flex items-center gap-2 w-32">
                                                <input type="number" min="0" name="retention[{{ $retainedKey }}]" x-model="v.retention['{{ $retainedKey }}']" class="grow w-16">
                                                <span class="text-xs opacity-60">{{ __('days') }}</span>
                                            </label>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('What waits for consent') }}</h3>
                        @if ($inventory === [])
                            <p class="text-sm opacity-70">{{ __('Nothing on the site needs consent: no counters, pixels or chats have been added.') }}</p>
                        @else
                            <table class="table table-sm">
                                <tbody>
                                    @foreach ($inventory as $item)
                                        <tr>
                                            <td>
                                                @if (! empty($item['url']))
                                                    <a href="{{ $item['url'] }}" class="link link-hover">{{ $item['label'] }}</a>
                                                @else
                                                    {{ $item['label'] }}
                                                @endif
                                            </td>
                                            <td class="text-sm whitespace-nowrap">{{ $categoryWords[$item['category']] ?? $item['category'] }}</td>
                                            <td class="text-xs text-warning">
                                                @if (! empty($item['guess']) && $item['guess'] !== $item['category'])
                                                    {{ __('looks like ":category"', ['category' => $categoryWords[$item['guess']] ?? $item['guess']]) }}
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            <p class="text-xs opacity-70">{{ __('"Necessary" runs always. Your own code that talks to no other service belongs there; a counter, a pixel or a chat does not.') }}</p>
                        @endif
                    </div>
                </section>
            </div>
        </div>

        <div class="flex justify-end">
            <button class="btn btn-primary btn-sm">{{ __('Save') }}</button>
        </div>
    </form>
@endsection
