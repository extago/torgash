@extends('admin.layout')

@section('title', $campaign->exists ? __('Edit campaign') : __('New campaign'))
@section('own_heading', '1')

@section('content')
    @php
        $campaignEditable = ! $campaign->exists || $campaign->isEditable();
        $campaignRules = collect(old('rules', $campaign->segment['rules'] ?? []))->values();
        $campaignStatus = [
            'draft' => ['badge-ghost', __('Draft')],
            'scheduled' => ['badge-warning', __('Scheduled')],
            'sending' => ['badge-info', __('Sending')],
            'sent' => ['badge-success', __('Sent out')],
            'cancelled' => ['badge-error', __('Stopped')],
        ];
        [$campaignBadge, $campaignBadgeText] = $campaignStatus[$campaign->status] ?? ['badge-ghost', $campaign->status];
    @endphp

    <form method="POST" id="campaign-form"
          action="{{ $campaign->exists ? route('admin.newsletter.campaigns.update', $campaign) : route('admin.newsletter.campaigns.store') }}"
          class="flex flex-col gap-4"
          x-data="{
              kinds: {{ \Illuminate\Support\Js::from($segmentKinds) }},
              rules: {{ \Illuminate\Support\Js::from($campaignRules) }},
              when: '{{ old('when', $campaign->status === 'scheduled' && $campaign->scheduled_at?->isFuture() ? 'at' : 'now') }}',
              add() { this.rules.push({ kind: Object.keys(this.kinds)[0] || '' }); this.recount(); },
              remove(i) { this.rules.splice(i, 1); this.recount(); },
              recount() { this.$nextTick(() => this.$refs.audience?.dispatchEvent(new Event('recount'))); },
          }">
        @csrf
        @if ($campaign->exists) @method('PUT') @endif

        <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
            <a href="{{ route('admin.newsletter.campaigns.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to campaigns') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $campaign->exists ? __('Edit campaign') : __('New campaign') }}</div>
                <div class="font-semibold truncate leading-tight flex items-center gap-2">
                    {{ $campaign->exists ? $campaign->name : __('New campaign') }}
                    @if ($campaign->exists)<span class="badge badge-sm {{ $campaignBadge }}">{{ $campaignBadgeText }}</span>@endif
                </div>
            </div>

            @if ($campaign->exists)
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                        @if ($campaignEditable)
                            <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                        @endif
                        <li><a href="{{ route('admin.newsletter.campaigns.preview', $campaign) }}" target="_blank" rel="noopener"><x-icon name="external-link" /> {{ __('Open the letter') }}</a></li>
                        <li><button type="submit" form="campaign-duplicate"><x-icon name="copy" /> {{ __('Duplicate') }}</button></li>
                        @if ($campaign->status !== 'sending')
                            <li class="menu-title p-0 my-1" role="separator"><span class="block h-px bg-base-300"></span></li>
                            <li>
                                <button type="submit" form="campaign-delete" class="text-error" data-confirm="{{ __('Delete this campaign and its numbers?') }}">
                                    <x-icon name="trash-2" /> {{ __('Delete this campaign') }}
                                </button>
                            </li>
                        @endif
                    </ul>
                </div>
            @endif
            <a href="{{ route('admin.newsletter.campaigns.index') }}" class="btn btn-ghost btn-sm">{{ $campaignEditable ? __('Cancel') : __('Back') }}</a>
            @if ($campaignEditable)
                <button class="btn btn-primary btn-sm">{{ $campaign->exists ? __('Save') : __('Create') }}</button>
            @endif
        </div>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm flex-col items-start gap-1">
                @foreach ($errors->all() as $formError)
                    <div>{{ $formError }}</div>
                @endforeach
            </div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_24rem] items-start">
            {{-- Left: the letter. Once it is going out, the letter as it was sent. --}}
            <div class="flex flex-col gap-4 min-w-0">
                @if ($campaign->exists && ! $campaignEditable)
                    @include('torgash.newsletter::admin.campaigns._stats')
                @endif

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Letter') }}</h3>
                        @if ($campaignEditable)
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Internal name') }} *</legend>
                                <input name="name" value="{{ old('name', $campaign->name) }}" required class="input input-bordered w-full"
                                       placeholder="{{ __('Seen only in the admin, e.g. November sale') }}">
                            </fieldset>
                            <x-admin.translatable-field name="subject" :label="__('Subject')" :value="(array) ($campaign->subject ?? [])" :required="true"
                                                         :placeholder="__('e.g. {name}, the November sale is on')" />
                            <x-admin.translatable-field name="preheader" :label="__('Preheader')" :value="(array) ($campaign->preheader ?? [])"
                                                         :placeholder="__('The grey line an inbox shows beside the subject')" />
                            <x-admin.translatable-field name="heading" :label="__('Heading in the letter')" :value="(array) ($campaign->heading ?? [])"
                                                         :placeholder="__('Empty — no heading')" />
                            <x-admin.translatable-field name="body" :label="__('Text of the letter')" :value="(array) ($campaign->body ?? [])" :rich="true" :rows="14" :record="$campaign->exists ? $campaign : null" />
                            <div class="text-xs opacity-60 -mt-2 flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span><code>{product:SKU}</code> {{ __('alone in a paragraph — the product\'s card with its picture, price and button') }}</span>
                                <span><code>{products:SKU1,SKU2,SKU3}</code> {{ __('— a grid of tiles with pictures; |2 at the end for two a row') }}</span>
                                <span><code>{name}</code> <code>{email}</code> {{ __('the reader\'s own') }}</span>
                                @unless ($campaign->exists)
                                    <span>{{ __('Pictures inside the letter can be added once the campaign is saved.') }}</span>
                                @endunless
                            </div>
                        @endif

                        @if ($campaign->exists)
                            <div class="flex items-center justify-between gap-2 {{ $campaignEditable ? 'mt-2' : '' }}">
                                <span class="text-sm font-medium">{{ __('As it will be read') }} <span class="opacity-40 font-normal">({{ strtoupper(admin_content_locale()) }})</span></span>
                                @if ($campaignEditable)
                                    <button type="submit" formaction="{{ route('admin.newsletter.campaigns.preview', $campaign) }}" formtarget="campaign-preview" formnovalidate
                                            class="btn btn-sm gap-1.5"><x-icon name="refresh-cw" /> {{ __('Refresh the preview') }}</button>
                                @endif
                            </div>
                            <iframe name="campaign-preview" src="{{ route('admin.newsletter.campaigns.preview', $campaign) }}" sandbox
                                    class="w-full h-[640px] rounded-box border border-base-300 bg-white" title="{{ __('As it will be read') }}"></iframe>
                        @endif
                    </div>
                </section>
            </div>

            {{-- Right: who, when, a test. --}}
            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <div class="flex items-baseline justify-between gap-2">
                            <h3 class="font-semibold">{{ __('Who receives it') }}</h3>
                            <span class="badge badge-primary" x-ref="audience"
                                  hx-get="{{ route('admin.newsletter.campaigns.count') }}" hx-include="[name^='rules[']" hx-trigger="recount, change from:#campaign-rules delay:300ms">
                                {{ trans_choice(':count address|:count addresses', $campaign->status === 'sent' || $campaign->status === 'sending' ? $campaign->recipients : $audience) }}
                            </span>
                        </div>
                        <p class="text-xs opacity-60">{{ __('Every subscribed address — and, with conditions, those that meet ALL of them. Counted again when the campaign goes out.') }}</p>

                        <div id="campaign-rules" class="flex flex-col gap-2">
                            <template x-for="(rule, i) in rules" :key="i">
                                <div class="rounded-box border border-base-300 p-3 flex flex-col gap-2">
                                    <div class="flex items-center gap-2">
                                        <select class="select select-bordered w-full" :name="'rules[' + i + '][kind]'" x-model="rule.kind" @disabled(! $campaignEditable) aria-label="{{ __('Condition') }}">
                                            <template x-for="(kind, key) in kinds" :key="key">
                                                <option :value="key" x-text="kind.label" :selected="key === rule.kind"></option>
                                            </template>
                                        </select>
                                        @if ($campaignEditable)
                                            <button type="button" class="btn btn-ghost btn-sm btn-square text-error" x-on:click="remove(i)" title="{{ __('Remove the condition') }}"><x-icon name="x" /></button>
                                        @endif
                                    </div>
                                    <template x-for="field in (kinds[rule.kind]?.fields || [])" :key="rule.kind + field.key">
                                        <fieldset class="fieldset p-0">
                                            <legend class="fieldset-legend" x-text="field.label"></legend>
                                            <template x-if="field.type === 'select'">
                                                <select class="select select-bordered w-full" :name="'rules[' + i + '][' + field.key + ']'" x-model="rule[field.key]" @disabled(! $campaignEditable)>
                                                    <option value="">—</option>
                                                    <template x-for="(optionLabel, optionKey) in field.options" :key="optionKey">
                                                        <option :value="optionKey" x-text="optionLabel" :selected="String(optionKey) === String(rule[field.key])"></option>
                                                    </template>
                                                </select>
                                            </template>
                                            <template x-if="field.type !== 'select'">
                                                <input class="input input-bordered w-full" :type="field.type === 'number' ? 'number' : 'text'" min="0"
                                                       :name="'rules[' + i + '][' + field.key + ']'" x-model="rule[field.key]" @disabled(! $campaignEditable)>
                                            </template>
                                            <p class="label text-xs opacity-60 whitespace-normal" x-show="field.help" x-text="field.help"></p>
                                        </fieldset>
                                    </template>
                                </div>
                            </template>
                            <p x-show="rules.length === 0" class="text-sm opacity-60">{{ __('No conditions — every subscribed address.') }}</p>
                        </div>
                        @if ($campaignEditable)
                            <button type="button" class="btn btn-sm gap-1.5 self-start" x-on:click="add()"><x-icon name="plus" /> {{ __('Add a condition') }}</button>
                        @endif
                    </div>
                </section>

                @if ($campaign->exists && $campaignEditable)
                    <section class="card bg-base-100 border border-base-300">
                        <div class="card-body gap-3">
                            <h3 class="font-semibold">{{ __('When') }}</h3>
                            <label class="label cursor-pointer justify-start gap-3">
                                <input type="radio" name="when" value="now" class="radio radio-sm" x-model="when">
                                <span class="label-text">{{ __('Now') }}</span>
                            </label>
                            <label class="label cursor-pointer justify-start gap-3">
                                <input type="radio" name="when" value="at" class="radio radio-sm" x-model="when">
                                <span class="label-text">{{ __('At a time') }}</span>
                            </label>
                            <input type="datetime-local" name="scheduled_at" x-show="when === 'at'" x-cloak aria-label="{{ __('At a time') }}"
                                   value="{{ old('scheduled_at', $campaign->scheduled_at?->format('Y-m-d\TH:i')) }}" class="input input-bordered w-full">
                            @if ($campaign->status === 'scheduled')
                                <div class="alert alert-warning text-sm">
                                    <x-icon name="clock" />
                                    <span>{{ __('Waiting for :date.', ['date' => $campaign->scheduled_at?->format('d.m.Y H:i')]) }}</span>
                                </div>
                                <button type="submit" form="campaign-cancel" class="btn btn-sm">{{ __('Back to a draft') }}</button>
                            @endif
                            <button type="submit" formaction="{{ route('admin.newsletter.campaigns.send', $campaign) }}" class="btn btn-primary gap-1.5"
                                    data-confirm="{{ __('Send this campaign? The letters cannot be called back.') }}">
                                <x-icon name="send" /> <span x-text="when === 'now' ? @js(__('Send now')) : @js(__('Schedule the sending'))"></span>
                            </button>
                            <p class="text-xs opacity-60">{{ __('The form is saved first. Letters go out at the pace set in the module\'s settings — a large list takes a while.') }}</p>
                        </div>
                    </section>

                    <section class="card bg-base-100 border border-base-300">
                        <div class="card-body gap-3">
                            <h3 class="font-semibold">{{ __('Test letter') }}</h3>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Send to') }}</legend>
                                <input type="email" name="test_email" value="{{ old('test_email') }}" placeholder="{{ auth()->user()->email }}" class="input input-bordered w-full">
                                <p class="label text-xs opacity-60 whitespace-normal">{{ __('Empty — to your own address. The form is saved first; a test counts nothing.') }}</p>
                            </fieldset>
                            <button type="submit" formaction="{{ route('admin.newsletter.campaigns.test', $campaign) }}" class="btn gap-1.5"><x-icon name="mail" /> {{ __('Send a test') }}</button>
                        </div>
                    </section>
                @elseif ($campaign->status === 'sending')
                    <section class="card bg-base-100 border border-base-300">
                        <div class="card-body gap-3">
                            <h3 class="font-semibold">{{ __('Going out') }}</h3>
                            <p class="text-sm opacity-70">{{ __('The letters go out in the background. Stopping leaves the ones already sent as they are.') }}</p>
                            <button type="submit" form="campaign-cancel" class="btn btn-error btn-outline" data-confirm="{{ __('Stop sending this campaign?') }}">{{ __('Stop sending') }}</button>
                        </div>
                    </section>
                @endif
            </div>
        </div>
    </form>

    @if ($campaign->exists)
        <form id="campaign-delete" method="POST" action="{{ route('admin.newsletter.campaigns.destroy', $campaign) }}" class="hidden">@csrf @method('DELETE')</form>
        <form id="campaign-duplicate" method="POST" action="{{ route('admin.newsletter.campaigns.duplicate', $campaign) }}" class="hidden">@csrf</form>
        <form id="campaign-cancel" method="POST" action="{{ route('admin.newsletter.campaigns.cancel', $campaign) }}" class="hidden">@csrf</form>
    @endif
@endsection
