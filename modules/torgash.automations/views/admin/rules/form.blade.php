@extends('admin.layout')

@section('title', $rule->exists ? $rule->name : __('New rule'))
@section('own_heading', '1')

@section('content')
    @php
        use Illuminate\Support\Js;

        $conditionRows = old('conditions', $rule->exists
            ? $rule->conditions->map(fn ($c) => [
                'kind' => $c->kind,
                'operator' => $c->operator,
                'list' => $c->list(),
                'ids' => array_map('strval', $c->ids()),
                'text' => in_array($c->kind, ['country'], true) ? implode(', ', $c->list()) : $c->text(),
                'amount' => $c->amount() ? $c->amount() / 100 : '',
            ])->all()
            : []);

        // A bot token is a credential and is never drawn back: the row carries
        // only whether one is stored, and its id so a blank box keeps it. A
        // declared-fields action carries its config as it is (a status code, a
        // manager id — nothing secret by construction).
        $actionRows = old('actions', $rule->exists
            ? $rule->actions->map(fn ($a) => [
                'id' => $a->id,
                'type' => $a->type,
                'method' => $a->config('method', 'POST'),
                'url' => $a->config('url', ''),
                'headers' => $a->config('headers', ''),
                'body' => $a->config('body', ''),
                'chat_id' => (string) $a->config('chat_id', ''),
                'text' => (string) $a->config('text', ''),
                'has_token' => $a->type === 'telegram' && (string) $a->config('token', '') !== '',
                'config' => ($actionTypes[$a->type]['fields'] ?? []) !== []
                    ? collect($actionTypes[$a->type]['fields'])->map(fn ($f, $name) => (string) $a->config($name, ''))->all()
                    : (object) [],
            ])->all()
            : []);
    @endphp

    <form method="POST" id="rule-form"
          action="{{ $rule->exists ? route('admin.automations.update', $rule) : route('admin.automations.store') }}"
          class="flex flex-col gap-4">
        @csrf
        @if ($rule->exists) @method('PUT') @endif

        <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
            <a href="{{ route('admin.automations.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to automations') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $rule->exists ? __('Edit rule') : __('New rule') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $rule->exists ? $rule->name : __('New rule') }}</div>
            </div>
            @if ($rule->exists)
                <button type="submit" form="rule-test" class="btn btn-sm gap-1.5" title="{{ __('Runs the saved rule against the newest one, now, ignoring the wait.') }}">
                    <x-icon name="play" size="sm" /> {{ __('Test on the latest one') }}
                </button>
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                        <li>
                            <button type="submit" name="after_save" value="close">
                                <x-icon name="check" /> {{ __('Save and close') }}
                            </button>
                        </li>
                        <li><a href="{{ route('admin.automations.runs', ['rule' => $rule->id]) }}"><x-icon name="list-checks" /> {{ __('Run log') }}</a></li>
                        <li><a href="{{ route('admin.automations.export', $rule) }}"><x-icon name="download" /> {{ __('Export') }}</a></li>
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="rule-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete rule') }}</button></li>
                    </ul>
                </div>
            @endif
            <a href="{{ route('admin.automations.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button class="btn btn-primary btn-sm">{{ $rule->exists ? __('Save') : __('Create') }}</button>
        </div>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        {{-- One scope for the whole form: the EVENT decides what the rule is
             about (an order, an enquiry…), and every card below reads that
             subject to offer only its own conditions, actions and variables. --}}
        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start"
             x-data="{
                 event: {{ Js::from(old('event', $rule->event)) }},
                 subjectOf: {{ Js::from($subjectOf) }},
                 fieldEvents: {{ Js::from($fieldEvents) }},
                 get subject() { return this.subjectOf[this.event] || 'order'; },
             }">
            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold flex items-center gap-2"><x-icon name="zap" class="opacity-60" /> {{ __('When') }}</h3>

                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Name') }} *</legend>
                            <input name="name" value="{{ old('name', $rule->name) }}" required class="input input-bordered w-full"
                                   placeholder="{{ __('Push paid orders to the CRM') }}">
                        </fieldset>

                        <fieldset class="fieldset p-0 max-w-md"><legend class="fieldset-legend">{{ __('What happens') }}</legend>
                            <select name="event" x-model="event" class="select select-bordered w-full">
                                @foreach ($eventGroups as $group)
                                    <optgroup label="{{ $group['label'] }}">
                                        @foreach ($group['events'] as $value => $label)
                                            <option value="{{ $value }}" @selected(old('event', $rule->event) === $value)>{{ $label }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </fieldset>

                        {{-- Only a diff-carrying event can be narrowed by field:
                             on the others there is nothing to narrow, and a filter
                             would silently mean "never". --}}
                        @foreach ($fieldsBySubject as $subjectKey => $subjectFields)
                            @if ($subjectFields !== [])
                                <fieldset class="fieldset p-0" x-show="subject === @js($subjectKey) && fieldEvents.includes(event)" x-cloak>
                                    <legend class="fieldset-legend">{{ __('Only when one of these changed') }}</legend>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach ($subjectFields as $value => $label)
                                            <label class="label cursor-pointer gap-2 border border-base-300 rounded-lg px-3 py-1.5 has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                                                <input type="checkbox" name="fields[]" value="{{ $value }}" class="checkbox checkbox-sm"
                                                       @checked(in_array($value, old('fields', $rule->fields ?? []), true))>
                                                <span class="label-text">{{ $label }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    <p class="text-xs opacity-60 mt-1">{{ __('Nothing ticked means any change at all.') }}</p>
                                </fieldset>
                            @endif
                        @endforeach
                    </div>
                </section>

                {{-- Conditions, drawn from what the subject DECLARES (kinds,
                     operators, the kind of value each takes) — the same shape
                     and wording as a shipping method's requirements. --}}
                <section class="card bg-base-100 border border-base-300"
                         x-data="{
                             rows: {{ Js::from($conditionRows) }},
                             schemas: {{ Js::from($schemas) }},
                             get kinds() { return this.schemas[this.subject] || {}; },
                             first(obj) { return Object.keys(obj || {})[0] || ''; },
                             add() {
                                 const kind = this.first(this.kinds);
                                 this.rows.push({ kind, operator: this.first(this.kinds[kind]?.operators), list: [], ids: [], text: '', amount: '' });
                             },
                             onKindChange(row) { row.operator = this.first(this.kinds[row.kind]?.operators); row.list = []; row.ids = []; row.text = ''; },
                             input(row) {
                                 const kind = this.kinds[row.kind];
                                 if (! kind) return 'none';
                                 return (kind.bare || []).includes(row.operator) ? 'none' : kind.input;
                             },
                             init() { this.$watch('subject', () => { this.rows = this.rows.filter((row) => this.kinds[row.kind]); }); },
                         }">
                    <div class="card-body gap-4">
                        <div>
                            <h3 class="font-semibold flex items-center gap-2"><x-icon name="filter" class="opacity-60" /> {{ __('Only when') }}</h3>
                            <p class="text-xs opacity-60 mt-1">
                                {{ __('ALL of these must hold when the rule actually runs — which is after the wait, not when it was triggered. "One of these" goes inside a single row.') }}
                            </p>
                        </div>

                        <template x-for="(row, index) in rows" :key="index">
                            <div class="flex flex-wrap gap-3 items-end border-b border-base-200 pb-3">
                                <fieldset class="fieldset p-0 w-52">
                                    <legend class="fieldset-legend">{{ __('About') }}</legend>
                                    <select class="select select-bordered w-full" form="rule-form"
                                            :name="`conditions[${index}][kind]`" x-model="row.kind" @change="onKindChange(row)">
                                        <template x-for="(kind, key) in kinds" :key="key">
                                            <option :value="key" x-text="kind.label" :selected="key === row.kind"></option>
                                        </template>
                                    </select>
                                </fieldset>

                                <fieldset class="fieldset p-0 w-60">
                                    <legend class="fieldset-legend">{{ __('Requirement') }}</legend>
                                    <select class="select select-bordered w-full" form="rule-form"
                                            :name="`conditions[${index}][operator]`" x-model="row.operator">
                                        <template x-for="(label, op) in (kinds[row.kind]?.operators || {})" :key="op">
                                            <option :value="op" x-text="label" :selected="op === row.operator"></option>
                                        </template>
                                    </select>
                                </fieldset>

                                {{-- x-if rather than x-show: only the control for this
                                     kind is in the form, so nothing else is posted. --}}
                                <template x-if="input(row) === 'list'">
                                    <fieldset class="fieldset p-0 w-64">
                                        <legend class="fieldset-legend">{{ __('Value') }}</legend>
                                        <select multiple size="4" class="select select-bordered w-full h-auto" form="rule-form"
                                                :name="`conditions[${index}][list][]`" x-model="row.list">
                                            <template x-for="option in kinds[row.kind].options" :key="option.value">
                                                <option :value="option.value" x-text="option.label" :selected="row.list.includes(option.value)"></option>
                                            </template>
                                        </select>
                                    </fieldset>
                                </template>

                                <template x-if="input(row) === 'categories'">
                                    <fieldset class="fieldset p-0 w-64">
                                        <legend class="fieldset-legend">{{ __('Categories') }}</legend>
                                        <select multiple size="4" class="select select-bordered w-full h-auto" form="rule-form"
                                                :name="`conditions[${index}][ids][]`" x-model="row.ids">
                                            <template x-for="option in kinds[row.kind].options" :key="option.value">
                                                <option :value="option.value" x-text="option.label" :selected="row.ids.includes(option.value)"></option>
                                            </template>
                                        </select>
                                    </fieldset>
                                </template>

                                <template x-if="input(row) === 'amount'">
                                    <fieldset class="fieldset p-0 w-40">
                                        <legend class="fieldset-legend">{{ __('Amount') }}</legend>
                                        <input type="number" step="0.01" min="0" class="input input-bordered w-full" form="rule-form"
                                               :name="`conditions[${index}][amount]`" x-model="row.amount">
                                    </fieldset>
                                </template>

                                <template x-if="input(row) === 'text' || input(row) === 'csv'">
                                    <fieldset class="fieldset p-0 w-64">
                                        <legend class="fieldset-legend">{{ __('Value') }}</legend>
                                        <input class="input input-bordered w-full" form="rule-form"
                                               :placeholder="kinds[row.kind].placeholder || ''"
                                               :name="`conditions[${index}][text]`" x-model="row.text">
                                    </fieldset>
                                </template>

                                <button type="button" class="btn btn-ghost btn-square text-error ms-auto" title="{{ __('Remove') }}" @click="rows.splice(index, 1)">
                                    <x-icon name="trash-2" />
                                </button>
                            </div>
                        </template>

                        <div class="flex flex-wrap gap-2 items-center">
                            <button type="button" class="btn btn-sm gap-1.5" @click="add()"><x-icon name="plus" size="sm" /> {{ __('Add a requirement') }}</button>
                            <span class="text-xs opacity-50" x-show="rows.length === 0" x-cloak>
                                {{ __('No requirements: runs on every trigger.') }}
                            </span>
                        </div>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300"
                         x-data="{
                             rows: {{ Js::from($actionRows) }},
                             types: {{ Js::from($actionTypes) }},
                             get available() {
                                 return Object.fromEntries(Object.entries(this.types).filter(([, t]) => t.subjects.length === 0 || t.subjects.includes(this.subject)));
                             },
                             add() {
                                 this.rows.push({ id: null, type: 'telegram' in this.available ? 'telegram' : Object.keys(this.available)[0], method: 'POST', url: '', headers: '', body: '', chat_id: '', text: '', has_token: false, config: {} });
                             },
                             init() { this.$watch('subject', () => { this.rows = this.rows.filter((row) => this.available[row.type]); }); },
                         }">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold flex items-center gap-2"><x-icon name="play" class="opacity-60" /> {{ __('Then') }}</h3>

                        <template x-for="(row, index) in rows" :key="index">
                            <div class="flex flex-col gap-3 border border-base-300 rounded-box p-4">
                                <input type="hidden" form="rule-form" :name="`actions[${index}][id]`" :value="row.id ?? ''">
                                <div class="flex flex-wrap gap-3 items-end">
                                    <fieldset class="fieldset p-0 w-80">
                                        <legend class="fieldset-legend">{{ __('Do') }}</legend>
                                        <select class="select select-bordered w-full" form="rule-form"
                                                :name="`actions[${index}][type]`" x-model="row.type" @change="row.config = {}">
                                            <template x-for="(type, key) in available" :key="key">
                                                <option :value="key" x-text="type.label" :selected="key === row.type"></option>
                                            </template>
                                        </select>
                                    </fieldset>

                                    {{-- Whatever an action declares (HasFields): a status,
                                         a manager — drawn from its own description. --}}
                                    <template x-for="(field, name) in (types[row.type]?.fields || {})" :key="name">
                                        <fieldset class="fieldset p-0 w-64">
                                            <legend class="fieldset-legend" x-text="field.label"></legend>
                                            <select class="select select-bordered w-full" form="rule-form"
                                                    :name="`actions[${index}][config][${name}]`" x-model="row.config[name]">
                                                <template x-for="option in field.options" :key="option.value">
                                                    <option :value="option.value" x-text="option.label" :selected="option.value === (row.config[name] ?? '')"></option>
                                                </template>
                                            </select>
                                        </fieldset>
                                    </template>

                                    <fieldset class="fieldset p-0 w-32" x-show="row.type === 'webhook'" x-cloak>
                                        <legend class="fieldset-legend">{{ __('Method') }}</legend>
                                        <select class="select select-bordered w-full" form="rule-form"
                                                :name="`actions[${index}][method]`" x-model="row.method">
                                            @foreach (['POST', 'PUT', 'PATCH', 'GET'] as $verb)
                                                <option value="{{ $verb }}">{{ $verb }}</option>
                                            @endforeach
                                        </select>
                                    </fieldset>

                                    <fieldset class="fieldset p-0 grow min-w-72" x-show="row.type === 'webhook'" x-cloak>
                                        <legend class="fieldset-legend">{{ __('URL') }}</legend>
                                        <input class="input input-bordered w-full font-mono" form="rule-form"
                                               placeholder="https://hooks.example.com/orders"
                                               :name="`actions[${index}][url]`" x-model="row.url">
                                    </fieldset>

                                    <button type="button" class="btn btn-ghost btn-square text-error ms-auto" title="{{ __('Remove') }}" @click="rows.splice(index, 1)">
                                        <x-icon name="trash-2" />
                                    </button>
                                </div>

                                <fieldset class="fieldset p-0" x-show="row.type === 'webhook'" x-cloak>
                                    <legend class="fieldset-legend">{{ __('Headers, one per line') }}</legend>
                                    <textarea rows="2" class="textarea textarea-bordered w-full font-mono text-sm" form="rule-form"
                                              placeholder="Authorization: Bearer …"
                                              :name="`actions[${index}][headers]`" x-model="row.headers"></textarea>
                                </fieldset>

                                <fieldset class="fieldset p-0" x-show="row.type === 'webhook'" x-cloak>
                                    <legend class="fieldset-legend">{{ __('Body') }}</legend>
                                    <textarea rows="8" class="textarea textarea-bordered w-full font-mono text-sm" form="rule-form"
                                              :name="`actions[${index}][body]`" x-model="row.body"></textarea>
                                </fieldset>

                                {{-- Telegram: the token is a credential — never drawn
                                     back, kept when the box is left blank. --}}
                                <div class="flex flex-col gap-3" x-show="row.type === 'telegram'" x-cloak>
                                    <div class="grid md:grid-cols-2 gap-3">
                                        <fieldset class="fieldset p-0">
                                            <legend class="fieldset-legend">{{ __('Bot token') }}</legend>
                                            <input type="password" autocomplete="new-password" class="input input-bordered w-full font-mono" form="rule-form"
                                                   :name="`actions[${index}][token]`"
                                                   :placeholder="row.has_token ? @js(__('saved — leave blank to keep it')) : '123456789:AA…'">
                                            <p class="text-xs opacity-60 mt-1">{{ __('Create a bot with @BotFather in Telegram; it gives you this token.') }}</p>
                                        </fieldset>
                                        <fieldset class="fieldset p-0">
                                            <legend class="fieldset-legend">{{ __('Chat id') }}</legend>
                                            <input class="input input-bordered w-full font-mono" form="rule-form" placeholder="-1001234567890"
                                                   :name="`actions[${index}][chat_id]`" x-model="row.chat_id">
                                            <p class="text-xs opacity-60 mt-1">{{ __('Add the bot to the chat or group, write anything there, and open api.telegram.org/bot<token>/getUpdates — the number after "chat":{"id": is it.') }}</p>
                                        </fieldset>
                                    </div>
                                    <fieldset class="fieldset p-0">
                                        <legend class="fieldset-legend">{{ __('Message') }}</legend>
                                        <textarea rows="9" class="textarea textarea-bordered w-full text-sm" form="rule-form"
                                                  :name="`actions[${index}][text]`" x-model="row.text"></textarea>
                                        <p class="text-xs opacity-60 mt-1">{{ __('Plain text with the variables listed below. Bold, italic and links are written as in HTML: <b>…</b>, <i>…</i>, <a href="…">…</a>.') }}</p>
                                    </fieldset>
                                </div>
                            </div>
                        </template>

                        <div>
                            <button type="button" class="btn btn-sm gap-1.5" @click="add()"><x-icon name="plus" size="sm" /> {{ __('Add an action') }}</button>
                        </div>
                    </div>
                </section>
            </div>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Status') }}</h3>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success"
                                   @checked(old('enabled', $rule->enabled ?? true))>
                            <span class="label-text">{{ __('Enabled') }}</span>
                        </label>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Wait, min') }}</legend>
                            <input type="number" min="0" max="10080" name="delay_minutes"
                                   value="{{ old('delay_minutes', $rule->delay_minutes ?? 0) }}" class="input input-bordered w-full">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('0 runs at once. The conditions are checked again when the wait is over.') }}</p>
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Sort order') }}</legend>
                            <input type="number" min="0" name="position" value="{{ old('position', $rule->position ?? 0) }}" class="input input-bordered w-full">
                        </fieldset>
                    </div>
                </section>

                {{-- The picker: composing a message against guessed variable
                     names is how a rule ends up shipping "order.sum" forever.
                     One list per subject, the rule's own shown. A click puts the
                     variable where the cursor was in the message (or the body,
                     the URL, a header); with no field chosen yet it is copied
                     instead. The braces are built in JavaScript: written
                     literally here, Blade would read them as its own echo. --}}
                <section class="card bg-base-100 border border-base-300 xl:sticky xl:top-36"
                         x-data="{
                             last: null,
                             copied: '',
                             open: String.fromCharCode(123, 123),
                             close: String.fromCharCode(125, 125),
                             token(path) { return this.open + ' ' + path + ' ' + this.close; },
                             put(text) {
                                 const field = this.last;
                                 if (field && document.body.contains(field)) {
                                     field.focus();
                                     field.setRangeText(text, field.selectionStart, field.selectionEnd, 'end');
                                     field.dispatchEvent(new Event('input', { bubbles: true }));
                                 } else {
                                     navigator.clipboard?.writeText(text);
                                 }
                                 this.copied = text;
                                 setTimeout(() => this.copied = '', 1500);
                             },
                             loop() {
                                 const o = this.open, c = this.close;
                                 this.put(o + '#order.items' + c + '• ' + this.token('item.name') + ' × ' + this.token('item.qty') + '\n' + o + '/order.items' + c);
                             },
                         }"
                         x-init="document.getElementById('rule-form').addEventListener('focusin', (e) => {
                             if (e.target.matches('textarea, input:not([type]), input[type=text]')) last = e.target;
                         })">
                    <div class="card-body gap-3 max-h-[calc(100vh-10rem)] overflow-y-auto">
                        <div>
                            <h3 class="font-semibold flex items-center gap-2"><x-icon name="braces" class="opacity-60" /> {{ __('Variables') }}</h3>
                            <p class="text-xs opacity-60 mt-1">{{ __('Click one to put it where the cursor is in the message.') }}</p>
                        </div>
                        @foreach ($variables as $subjectKey => $groups)
                            <div class="flex flex-col gap-3" x-show="subject === @js($subjectKey)" x-cloak>
                                @foreach ($groups as $group => $paths)
                                    <div class="flex flex-col gap-0.5">
                                        <div class="text-xs font-medium opacity-60 mt-1">{{ __($group) }}</div>
                                        @foreach ($paths as $path => $label)
                                            <button type="button" @click="put(token(@js($path)))"
                                                    class="flex items-baseline justify-between gap-2 text-left rounded px-1.5 py-1 -mx-1.5 hover:bg-base-200 group">
                                                <span class="text-sm">{{ __($label) }}</span>
                                                <code class="text-xs font-mono opacity-50 group-hover:opacity-100 shrink-0">{{ $path }}</code>
                                            </button>
                                        @endforeach
                                        @if (str_starts_with((string) array_key_first($paths), 'item.'))
                                            <button type="button" @click="loop()" class="btn btn-sm btn-ghost justify-start gap-1 mt-1">
                                                <x-icon name="repeat" size="sm" /> {{ __('Insert the list of items') }}
                                            </button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                        <p class="text-xs opacity-60">{{ __('Amounts without _decimal are in minor units (cents).') }}</p>
                        <div x-show="copied" x-cloak class="toast toast-end toast-bottom z-50">
                            <div class="alert alert-success text-sm py-2">{{ __('Inserted') }}: <code class="font-mono" x-text="copied.length > 40 ? copied.slice(0, 40) + '…' : copied"></code></div>
                        </div>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-2 text-sm">
                        <h3 class="font-semibold">{{ __('How to check it') }}</h3>
                        <p class="opacity-70">{{ __('Save, then press "Test on the latest one": the rule runs against the newest order or enquiry right away, with its conditions but without the wait, and the run log shows what was sent and what came back.') }}</p>
                    </div>
                </section>
            </div>
        </div>
    </form>

    @if ($rule->exists)
        {{-- Beside the form, never inside it: a form nested in a form loses its
             method spoof and the outer submit carries the inner fields. --}}
        <form method="POST" id="rule-test" action="{{ route('admin.automations.test', $rule) }}" class="hidden">@csrf</form>
        <form method="POST" id="rule-delete" action="{{ route('admin.automations.destroy', $rule) }}" class="hidden"
              data-confirm="{{ __('Delete this rule?') }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
