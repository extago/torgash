@extends('admin.layout')

@section('title', __('API tokens'))

@section('content')
    @include('torgash.api::admin._nav', ['apiScreen' => 'tokens'])

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    @if (session('new_token'))
        {{-- Shown once, and said to be shown once. The row keeps a hash, so
             there is no screen anywhere that could show it again. --}}
        <div class="alert alert-warning flex-col items-start gap-2" x-data="{ copied: false }">
            <div class="font-semibold">{{ __('Copy it now — it is not shown again.') }}</div>
            <div class="flex items-center gap-2 w-full flex-wrap">
                <code class="font-mono text-sm break-all select-all bg-base-100 rounded px-2 py-1 flex-1">{{ session('new_token') }}</code>
                <button type="button" class="btn btn-sm gap-1.5"
                        @click="navigator.clipboard.writeText(@js(session('new_token'))); copied = true">
                    <x-icon name="copy" size="sm" /> <span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"></span>
                </button>
            </div>
        </div>
    @endif

    {{-- One Alpine root: the bulk bar and every row's checkbox share
         `selected` — the categories list's own arrangement. --}}
    <div class="card bg-base-100 border border-base-300" x-data="{ selected: [] }">
        <div class="card-body gap-4">
            <div class="flex justify-between items-start gap-3 flex-wrap">
                <p class="text-sm opacity-70 max-w-3xl">
                    {{ __('A token is how another program reaches the shop: 1C, a CRM, a mobile app. It can never do more than the account that issued it, and narrowing it to "read" keeps a price feed from being able to change an order.') }}
                </p>
                <button type="button" class="btn btn-primary btn-sm" data-open-dialog="modal-token-new">+ {{ __('Issue a token') }}</button>
            </div>

            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label class="input input-sm w-full sm:w-72 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Name or owner…') }}">
                </label>
                <div class="join">
                    @foreach (['' => __('All'), 'active' => __('Active'), 'expired' => __('Expired'), 'unused' => __('Never used')] as $statusKey => $statusLabel)
                        <button type="submit" name="status" value="{{ $statusKey }}"
                                @class(['btn btn-sm join-item', 'btn-active' => $filters['status'] === $statusKey])>
                            {{ $statusLabel }} <span class="opacity-50">{{ $statusCounts[$statusKey] }}</span>
                        </button>
                    @endforeach
                </div>
                @if ($filters['q'] !== '' || $filters['status'])
                    <a href="{{ route('admin.api.tokens') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Tick tokens to revoke several at once.') }}
                </p>
                <form method="POST" action="{{ route('admin.api.tokens.bulk') }}"
                      x-show="selected.length" x-cloak
                      class="w-full flex flex-wrap items-center gap-2 bg-base-200 rounded-box p-2">
                    @csrf
                    <template x-for="id in selected" :key="id">
                        <input type="hidden" name="ids[]" :value="id">
                    </template>
                    <span class="text-sm px-1"><span x-text="selected.length"></span> {{ __('selected') }}</span>
                    <button class="btn btn-sm btn-error btn-outline gap-1.5"
                            data-confirm="{{ __('Revoke the selected tokens? Whatever uses them stops working at once.') }}">
                        <x-icon name="ban" size="sm" /> {{ __('Revoke') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($tokens->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="key-round" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $filters['q'] !== '' || $filters['status'] ? __('No tokens match this search.') : __('No tokens yet.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                           x-on:change="selected = $event.target.checked ? @js($tokens->pluck('id')->map(fn ($id) => (string) $id)) : []"
                                           :checked="selected.length === {{ $tokens->count() }}">
                                </th>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('May') }}</th>
                                <th>{{ __('Expires') }}</th>
                                <th>{{ __('Last used') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($tokens as $token)
                                <tr class="hover:bg-base-200/50" :class="selected.includes('{{ $token->id }}') && 'bg-primary/5'">
                                    <td>
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $token->id }}" x-model="selected" aria-label="{{ $token->name }}">
                                    </td>
                                    <td class="min-w-48">
                                        <div class="font-medium">{{ $token->name }}</div>
                                        <div class="text-xs opacity-50">{{ $token->user?->email ?? '—' }}</div>
                                    </td>
                                    <td class="text-sm">
                                        @if ($token->abilities)
                                            {{ collect($token->abilities)->map(fn ($ability) => $ability === 'read' ? __('read') : __('write'))->implode(', ') }}
                                        @else
                                            <span class="opacity-60">{{ __('everything its owner may') }}</span>
                                        @endif
                                    </td>
                                    <td class="text-sm whitespace-nowrap">{{ $token->expires_at?->format('d.m.Y') ?? __('never') }}</td>
                                    <td class="text-sm opacity-70 whitespace-nowrap">{{ $token->last_used_at?->diffForHumans() ?? __('never') }}</td>
                                    <td>
                                        <span @class(['badge badge-sm', 'badge-success' => ! $token->isExpired(), 'badge-ghost' => $token->isExpired()])>
                                            {{ $token->isExpired() ? __('Expired') : __('Active') }}
                                        </span>
                                    </td>
                                    <td class="text-end whitespace-nowrap">
                                        <form method="POST" action="{{ route('admin.api.tokens.destroy', $token) }}" class="inline"
                                              data-confirm="{{ __('Revoke this token? Whatever uses it stops working at once.') }}">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Revoke') }}" aria-label="{{ __('Revoke') }}"><x-icon name="ban" /></button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <x-modal name="token-new" :title="__('Issue a token')" icon="key-round">
        <form method="POST" action="{{ route('admin.api.tokens.store') }}" class="flex flex-col gap-4">
            @csrf
            <fieldset class="fieldset p-0">
                <legend class="fieldset-legend">{{ __('What is it for') }}</legend>
                <input name="name" required class="input input-bordered w-full" placeholder="{{ __('1C exchange') }}">
            </fieldset>
            <fieldset class="fieldset p-0">
                <legend class="fieldset-legend">{{ __('May') }}</legend>
                <div class="flex gap-6">
                    @foreach (['read' => __('read'), 'write' => __('write')] as $value => $label)
                        <label class="label cursor-pointer gap-2 p-0">
                            <input type="checkbox" name="abilities[]" value="{{ $value }}" class="checkbox checkbox-sm" checked>
                            <span class="label-text">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <fieldset class="fieldset p-0">
                <legend class="fieldset-legend">{{ __('Expires in, days') }}</legend>
                <input name="expires_in_days" type="number" min="1" class="input input-bordered w-40" placeholder="{{ __('never') }}">
            </fieldset>
            <p class="text-xs opacity-60">{{ __('Issued to you: it can never do more than your own account may. The token is shown once, right after this.') }}</p>
            <div>
                <button class="btn btn-primary btn-sm">{{ __('Issue') }}</button>
            </div>
        </form>
    </x-modal>
@endsection
