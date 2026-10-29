@extends('admin.layout')

@section('title', __('Blocking'))
@section('title_hint', __('An address is blocked when it collects too many refusals — wrong passwords, refused forms, unknown API tokens — or by hand. A block runs out by itself; one that comes back stays longer each time.'))

{{--
    The addresses the shop has stopped talking to — or would have.

    In watch mode (the default) every row here is a rehearsal: what would have
    been refused, and how many requests that would have been. That is what the
    screen is for before enforcement is switched on, so the default tab holds
    the watched rows beside the real ones rather than hiding them behind a
    filter. Blocking by hand is a dialog off the card's button, the way every
    other list in this admin adds a row.
--}}

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    {{-- What the shop is currently doing, said in a sentence rather than left
         to be inferred from a settings page in another part of the admin. --}}
    <div class="alert {{ $mode === 'on' ? 'alert-warning' : 'alert-info' }} items-start">
        <x-icon name="{{ $mode === 'on' ? 'shield-ban' : ($mode === 'off' ? 'shield-off' : 'eye') }}" size="md" />
        <div class="text-sm">
            @if ($mode === 'on')
                <strong>{{ __('Blocking is on.') }}</strong>
                {{ __('Addresses marked as blocked below are refused with a 429. Requests that present valid credentials — a signed-in session, an API token, 1C, a payment callback — are never touched.') }}
            @elseif ($mode === 'off')
                <strong>{{ __('Blocking is off.') }}</strong>
                {{ __('Nothing is recorded and nobody is refused.') }}
            @else
                <strong>{{ __('Watching, not blocking.') }}</strong>
                {{ __('Everything below would have been refused. Nobody actually was. Read this list first, then switch enforcement on in the module settings.') }}
            @endif
            @can('settings.manage')
                <a href="{{ url('/admin/settings/torgash.guard#setting-blocking_mode') }}" class="link">{{ __('Settings') }}</a>
            @endcan
            <div class="mt-1 opacity-70">
                {{ __('Locked yourself out? The way back in does not need this screen:') }}
                <code class="font-mono">php artisan guard:unblock --all --watch</code>
            </div>
        </div>
    </div>

    @php
        $tabs = [
            'standing' => [__('Standing'), 'shield'],
            'enforced' => [__('Enforced'), 'shield-ban'],
            'observed' => [__('Only watched'), 'eye'],
            'past' => [__('Lifted or expired'), 'history'],
            'all' => [__('All'), 'list'],
        ];
        $tabUrl = fn (string $tab) => route('admin.guard.blocks.index', array_filter([
            'tab' => $tab === 'standing' ? null : $tab,
            'q' => $filters['q'] ?: null,
            'signal' => $filters['signal'] ?: null,
        ]));
        $signals = [
            \Torgash\Guard\Blocking\Signals::LOGIN,
            \Torgash\Guard\Blocking\Signals::FORM,
            \Torgash\Guard\Blocking\Signals::API,
            \Torgash\Guard\Blocking\Signals::MANUAL,
        ];
    @endphp

    {{-- The states as one row of tabs over the card, the screen's action at
         its end (app.css, `admin-tabs`); a count that is a problem is red. --}}
    <div class="admin-tabs-row">
        <nav class="admin-tabs" aria-label="{{ __('Blocking') }}">
            @foreach ($tabs as $tabKey => [$tabLabel, $tabIcon])
                @php $alarm = $tabKey === 'enforced' && $counts['enforced']; @endphp
                <a href="{{ $tabUrl($tabKey) }}" @class(['admin-tab', 'is-active' => $filters['tab'] === $tabKey])>
                    <x-icon :name="$tabIcon" size="sm" /> {{ $tabLabel }}
                    <span @class(['admin-tab-count', 'is-error' => $alarm])>{{ $counts[$tabKey] }}</span>
                </a>
            @endforeach
        </nav>
        <div class="admin-tabs-actions">
            <button type="button" class="btn btn-primary" data-open-dialog="modal-block-new">+ {{ __('Block an address') }}</button>
        </div>
    </div>

    {{-- One Alpine root: the bulk bar and every row's box share `selected`. --}}
    <div class="card bg-base-100 border border-base-300" x-data="{ selected: [] }">
        <div class="card-body gap-4">

            <form method="GET" class="flex flex-wrap items-center gap-2">
                @if ($filters['tab'] !== 'standing')
                    <input type="hidden" name="tab" value="{{ $filters['tab'] }}">
                @endif
                <label class="input input-sm grow min-w-48 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Address, note or who…') }}">
                </label>
                <select name="signal" class="select select-sm w-auto" data-submit-on-change aria-label="{{ __('What it was doing') }}">
                    <option value="">{{ __('Any reason') }}</option>
                    @foreach ($signals as $signal)
                        <option value="{{ $signal }}" @selected($filters['signal'] === $signal)>{{ \Torgash\Guard\Blocking\Signals::label($signal) }}</option>
                    @endforeach
                </select>
                @if ($filters['q'] !== '' || $filters['signal'] !== '')
                    <a href="{{ route('admin.guard.blocks.index', array_filter(['tab' => $filters['tab'] === 'standing' ? null : $filters['tab']])) }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            @if ($blocks->isNotEmpty())
                <div class="min-h-12 flex items-center">
                    <p x-show="!selected.length" class="text-xs opacity-50">{{ __('Tick addresses to let several back in at once.') }}</p>
                    <form method="POST" action="{{ route('admin.guard.blocks.bulk') }}" x-show="selected.length" x-cloak
                          class="w-full flex flex-wrap items-center gap-2 bg-base-200 rounded-box p-2">
                        @csrf
                        <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
                        <span class="text-sm px-1"><span x-text="selected.length"></span> {{ __('selected') }}</span>
                        <button name="action" value="release" class="btn btn-sm gap-1.5"><x-icon name="lock-open" size="sm" /> {{ __('Let back in') }}</button>
                        <button name="action" value="enforce" class="btn btn-sm btn-ghost text-error gap-1.5"><x-icon name="shield-ban" size="sm" /> {{ __('Block for real') }}</button>
                        <button type="button" class="btn btn-ghost btn-sm" x-on:click="selected = @js(array_map('strval', $foundIds))">
                            {{ __('Select all :n found', ['n' => count($foundIds)]) }}
                        </button>
                        <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-8"></th>
                                <th>{{ __('Address') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('What it was doing') }}</th>
                                <th class="text-right">{{ __('Times blocked') }}</th>
                                <th class="text-right">{{ __('Requests since') }}</th>
                                <th>{{ __('Blocked until') }}</th>
                                <th class="w-24"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($blocks as $block)
                                <tr :class="selected.includes('{{ $block->id }}') && 'bg-primary/5'">
                                    <td>
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $block->id }}" x-model="selected" aria-label="{{ $block->ip }}">
                                    </td>
                                    <td>
                                        <div class="flex items-center gap-2">
                                            <span class="font-mono font-medium">{{ $block->ip }}</span>
                                            @if ($block->ip === $yourSubject)
                                                <span class="badge badge-sm badge-info">{{ __('You') }}</span>
                                            @endif
                                            @if (str_contains($block->ip, '/'))
                                                <span class="badge badge-sm badge-ghost" title="{{ __('An IPv6 address is one of the /64 network its owner holds, so the whole network is counted and blocked together.') }}">{{ __('Network') }}</span>
                                            @endif
                                        </div>
                                        @if ($block->note)
                                            {{-- Through __(): the automatic note is stored in English (Blocks::block);
                                             a note somebody typed has no translation and prints as typed. --}}
                                        <div class="text-xs opacity-60 max-w-xs">{{ __($block->note) }}</div>
                                        @endif
                                    </td>
                                    <td><span class="badge badge-sm {{ $block->badge() }} whitespace-nowrap">{{ $block->state() }}</span></td>
                                    <td class="text-sm">
                                        {{ $block->what() }}
                                        <div class="text-xs opacity-50">
                                            {{ $block->user_name ? __('By hand, :who', ['who' => $block->user_name]) : __('Automatically') }}
                                        </div>
                                    </td>
                                    <td class="text-right tabular-nums">{{ $block->strikes }}</td>
                                    <td class="text-right tabular-nums">{{ $block->hits }}</td>
                                    <td class="text-sm whitespace-nowrap">
                                        @if ($block->released_at)
                                            <span class="opacity-50" title="{{ $block->released_at->format('d.m.Y H:i') }}">{{ __('Let back in :when', ['when' => $block->released_at->diffForHumans()]) }}</span>
                                        @elseif ($block->until)
                                            <span title="{{ $block->until->format('d.m.Y H:i') }}" @class(['opacity-50' => $block->until->isPast()])>{{ $block->until->diffForHumans() }}</span>
                                        @else
                                            {{ __('No end date') }}
                                        @endif
                                    </td>
                                    <td>
                                        <div class="flex items-center justify-end gap-1">
                                            @if ($block->isActive() && $block->mode !== \Torgash\Guard\Models\Block::ENFORCED)
                                                <button type="submit" form="block-one" formaction="{{ route('admin.guard.blocks.enforce', $block) }}"
                                                        class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Block for real') }}" aria-label="{{ __('Block for real') }}">
                                                    <x-icon name="shield-ban" size="sm" />
                                                </button>
                                            @endif
                                            @if ($block->isActive())
                                                <button type="submit" form="block-one" formaction="{{ route('admin.guard.blocks.release', $block) }}"
                                                        class="btn btn-ghost btn-sm btn-square" title="{{ __('Let back in') }}" aria-label="{{ __('Let back in') }}">
                                                    <x-icon name="lock-open" size="sm" />
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{ $blocks->links() }}
            @else
                <p class="text-sm opacity-60 py-6 text-center">
                    @if ($filters['q'] !== '' || $filters['signal'] !== '')
                        {{ __('No address matches this search.') }}
                    @elseif (in_array($filters['tab'], ['standing', 'enforced', 'observed'], true))
                        {{ __('Nobody has done anything worth blocking. This is the good outcome.') }}
                    @else
                        {{ __('Nothing here.') }}
                    @endif
                </p>
            @endif
        </div>
    </div>

    <form id="block-one" method="POST" class="hidden">@csrf</form>

    <x-modal name="block-new" :title="__('Block an address')" icon="shield-ban">
        <form method="POST" action="{{ route('admin.guard.blocks.store') }}" class="flex flex-col gap-4">
            @csrf
            <fieldset class="fieldset p-0">
                <legend class="fieldset-legend">{{ __('Address') }}</legend>
                <input type="text" name="ip" value="{{ old('ip', request('block')) }}" required class="input input-bordered w-full font-mono" placeholder="192.0.2.10">
                <p class="label">{{ __('You are on :ip', ['ip' => $yourIp]) }}</p>
                <p class="label whitespace-normal">{{ __('An IPv6 address blocks its whole /64 network: one household, phone or server holds all of it.') }}</p>
            </fieldset>
            <fieldset class="fieldset p-0">
                <legend class="fieldset-legend">{{ __('For, minutes') }}</legend>
                <input type="number" name="minutes" value="{{ old('minutes') }}" min="1" class="input input-bordered w-80" placeholder="{{ __('empty — until let back in') }}">
            </fieldset>
            <fieldset class="fieldset p-0">
                <legend class="fieldset-legend">{{ __('Why') }}</legend>
                <input type="text" name="note" value="{{ old('note', request('note')) }}" maxlength="250" class="input input-bordered w-full">
            </fieldset>
            <p class="text-xs opacity-60">
                {{ __('A block by hand is applied even while the module is only watching: watching is a statement about the guard\'s own judgement, not about yours.') }}
            </p>
            <div>
                <button class="btn btn-sm btn-error btn-outline gap-1.5"><x-icon name="shield-ban" size="sm" /> {{ __('Block') }}</button>
            </div>
        </form>
    </x-modal>

    {{-- A refused address comes back with the dialog open, what was typed in it;
         `?block=` is another screen asking for one (an order's "Block this address"). --}}
    @if ($errors->any() || request()->filled('block'))
        <script nonce="{{ csp_nonce() }}">document.getElementById('modal-block-new').showModal()</script>
    @endif
@endsection
