@extends('admin.layout')

@section('title', __('Customers'))

@section('content')
    @php
        $isFiltered = $filters['q'] !== '' || $filters['group'] !== '' || $filters['sort'] !== 'newest';
        $contentLocale = admin_content_locale();
        $groupTabs = ['' => __('All')];
        foreach ($groups as $group) {
            $groupTabs[(string) $group->id] = $group->t('name', $contentLocale);
        }
        $groupTabs['none'] = __('No group');
    @endphp

    @include('admin.customers._nav', ['customerScreen' => 'customers'])

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    <div class="card bg-base-100 border border-base-300" x-data="{ selected: [] }">
        <div class="card-body gap-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <p class="text-sm opacity-70 max-w-3xl">
                    {{ __('Everyone who has an account in the shop. The group decides the prices a customer sees; what they have bought is on their card. Staff are not listed here — they are under Administrators.') }}
                </p>
                <a href="{{ route('admin.customers.create') }}" class="btn btn-primary btn-sm">+ {{ __('New customer') }}</a>
            </div>

            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label class="input input-sm w-full sm:w-80 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Name, email or phone…') }}">
                </label>
                <select name="sort" class="select select-bordered select-sm w-full sm:w-52" data-submit-on-change>
                    <option value="newest" @selected($filters['sort'] === 'newest')>{{ __('Newest first') }}</option>
                    <option value="oldest" @selected($filters['sort'] === 'oldest')>{{ __('Oldest first') }}</option>
                    <option value="name" @selected($filters['sort'] === 'name')>{{ __('Name A–Z') }}</option>
                </select>
                <input type="hidden" name="group" value="{{ $filters['group'] }}">
                @if ($isFiltered)
                    <a href="{{ route('admin.customers.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <div class="join flex-wrap -mt-1">
                @foreach ($groupTabs as $groupKey => $groupLabel)
                    <a href="{{ request()->fullUrlWithQuery(['group' => $groupKey === '' ? null : $groupKey, 'page' => null]) }}"
                       @class(['btn btn-sm join-item', 'btn-active' => $filters['group'] === (string) $groupKey])>
                        {{ $groupLabel }} <span class="opacity-50">{{ $counts[(string) $groupKey] ?? 0 }}</span>
                    </a>
                @endforeach
            </div>

            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Tick customers to move several into a group at once, or delete them.') }}
                </p>
                <form method="POST" action="{{ route('admin.customers.bulk') }}"
                      x-show="selected.length" x-cloak
                      class="w-full flex flex-wrap items-center gap-2 bg-base-200 rounded-box p-2">
                    @csrf
                    <template x-for="id in selected" :key="id">
                        <input type="hidden" name="ids[]" :value="id">
                    </template>
                    <span class="text-sm px-1"><span x-text="selected.length"></span> {{ __('selected') }}</span>
                    <select name="customer_group_id" class="select select-bordered select-sm w-auto" aria-label="{{ __('Customer group') }}">
                        <option value="">{{ __('No group') }}</option>
                        @foreach ($groups as $group)
                            <option value="{{ $group->id }}">{{ $group->t('name', $contentLocale) }}</option>
                        @endforeach
                    </select>
                    <button name="action" value="group" class="btn btn-sm">{{ __('Move to this group') }}</button>
                    <button name="action" value="delete" class="btn btn-sm btn-error btn-outline gap-1.5"
                            data-confirm="{{ __('Delete the selected customers? Their accounts, addresses and wishlists go; orders and enquiries stay under the name they were placed with.') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Delete') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($customers->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="users" size="xl" class="opacity-40" />
                    <p class="mt-2 max-w-xl mx-auto">{{ $isFiltered ? __('No customers match this search.') : __('No customers yet. An account appears here when somebody registers or places an order signed in.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                           x-on:change="selected = $event.target.checked ? @js($customers->getCollection()->pluck('id')->map(fn ($id) => (string) $id)->values()) : []"
                                           :checked="selected.length === {{ $customers->count() }}">
                                </th>
                                <th>{{ __('Customer') }}</th>
                                <th>{{ __('Activity') }}</th>
                                <th>{{ __('Registered') }}</th>
                                <th>{{ __('Group') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($customers as $customer)
                                <tr class="hover:bg-base-200/50" :class="selected.includes('{{ $customer->id }}') && 'bg-primary/5'">
                                    <td>
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $customer->id }}" x-model="selected" aria-label="{{ $customer->name }}">
                                    </td>
                                    <td class="min-w-0">
                                        <div class="flex items-center gap-3">
                                            <div class="avatar avatar-placeholder shrink-0">
                                                <div class="bg-base-200 text-base-content/70 w-9 rounded-full">
                                                    <span class="text-sm font-semibold">{{ mb_strtoupper(mb_substr($customer->name, 0, 1)) }}</span>
                                                </div>
                                            </div>
                                            <div class="min-w-0">
                                                <a href="{{ route('admin.customers.edit', $customer) }}" class="font-medium link link-hover">{{ $customer->name }}</a>
                                                <div class="text-xs opacity-60 flex items-center gap-1">
                                                    {{-- No email is an ordinary answer (PLAN §44, 3b): the phone then. --}}
                                                    {{ $customer->email ?: ($customer->phone ? \App\Core\Support\Phones::display($customer->phone) : '—') }}
                                                    @if ($customer->email && $customer->email_verified_at)
                                                        <span title="{{ __('Email confirmed') }}"><x-icon name="badge-check" size="sm" class="text-success" /></span>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-sm">
                                        @forelse ($facts[$customer->id] ?? [] as $fact)
                                            <div class="whitespace-nowrap">
                                                @if (! empty($fact['url']))
                                                    <a href="{{ $fact['url'] }}" class="link link-hover">{{ $fact['label'] }}</a>
                                                @else
                                                    {{ $fact['label'] }}
                                                @endif
                                                @if (! empty($fact['value']))
                                                    <span class="opacity-40">·</span> <span class="font-semibold tabular-nums">{{ $fact['value'] }}</span>
                                                @endif
                                            </div>
                                            @if (! empty($fact['hint']))
                                                <div class="text-xs opacity-50 whitespace-nowrap">{{ $fact['hint'] }}</div>
                                            @endif
                                        @empty
                                            <span class="opacity-40 text-xs">{{ __('nothing yet') }}</span>
                                        @endforelse
                                    </td>
                                    <td class="text-sm whitespace-nowrap">
                                        {{ $customer->created_at->format('d.m.Y') }}
                                        <div class="text-xs opacity-50">{{ $customer->created_at->diffForHumans() }}</div>
                                    </td>
                                    @include('admin.customers._group', ['customer' => $customer, 'groups' => $groups])
                                    <td class="text-end whitespace-nowrap">
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ route('admin.customers.edit', $customer) }}" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            {{-- The card's dialog lists what goes and what stays;
                                                 from the list the confirmation says it in general. --}}
                                            <form method="POST" action="{{ route('admin.customers.destroy', $customer) }}" class="inline"
                                                  data-confirm="{{ __('Delete :name? The account, addresses and wishlist go; orders and enquiries stay under the name they were placed with.', ['name' => $customer->name]) }}">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}">
                                                    <x-icon name="trash-2" />
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{ $customers->links() }}
            @endif
        </div>
    </div>
@endsection
