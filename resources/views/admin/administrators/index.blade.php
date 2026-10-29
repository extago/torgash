@extends('admin.layout')

@section('title', __('Administrators'))
@section('title_hint', __('The people who sign in to this admin panel. What each of them may do is decided by their permission groups; an account with no group is a customer, not staff.'))

{{--
    The staff half of the users table: anybody holding at least one permission
    group (User::staff()). What modules know about them — the guard's second
    factor — arrives through `core.admin.staff.facts`, the customers list's own
    seam, so this screen names no module.
--}}

@section('content')
    @include('admin.access._nav', ['accessScreen' => 'administrators'])

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    @php
        $isFiltered = $search !== '' || $group !== '';
        $groupLabel = fn (string $name) => $name === $superRole ? __('Administrator') : $name;
    @endphp

    {{-- The permission groups as one row of tabs over the card, the screen's
         action at its end (app.css, `admin-tabs`); LINKS carrying the search.
         One group alone is no choice: then there is no row, and the action
         stands level with the heading, as on the groups screen. --}}
    @if ($groups->count() > 1)
        <div class="admin-tabs-row">
            <nav class="admin-tabs" aria-label="{{ __('Administrators') }}">
                <a href="{{ request()->fullUrlWithQuery(['group' => null, 'page' => null]) }}" @class(['admin-tab', 'is-active' => $group === ''])>
                    {{ __('All') }} <span class="admin-tab-count">{{ $counts[''] }}</span>
                </a>
                @foreach ($groups as $role)
                    <a href="{{ request()->fullUrlWithQuery(['group' => $role->name, 'page' => null]) }}" @class(['admin-tab', 'is-active' => $group === $role->name])>
                        {{ $groupLabel($role->name) }} <span class="admin-tab-count">{{ $counts[$role->name] ?? 0 }}</span>
                    </a>
                @endforeach
            </nav>
            <div class="admin-tabs-actions">
                <a href="{{ route('admin.administrators.create') }}" class="btn btn-primary">+ {{ __('New administrator') }}</a>
            </div>
        </div>
    @else
        @section('title_actions')
            <a href="{{ route('admin.administrators.create') }}" class="btn btn-primary">+ {{ __('New administrator') }}</a>
        @endsection
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label class="input input-sm grow min-w-48 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $search }}" class="grow" placeholder="{{ __('Name or email…') }}">
                </label>
                <input type="hidden" name="group" value="{{ $group }}">
                @if ($isFiltered)
                    <a href="{{ route('admin.administrators.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            @if ($administrators->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="user-cog" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $isFiltered ? __('No administrators match this search.') : __('No administrators found.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('Administrator') }}</th>
                                <th>{{ __('Permission groups') }}</th>
                                @if ($facts)
                                    <th>{{ __('Security') }}</th>
                                @endif
                                <th>{{ __('Joined') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($administrators as $administrator)
                                @php $isYou = $administrator->is(auth()->user()); @endphp
                                <tr class="hover:bg-base-200/50">
                                    <td class="min-w-0">
                                        <div class="flex items-center gap-3">
                                            <div class="avatar avatar-placeholder shrink-0">
                                                <div class="bg-primary/15 text-primary w-9 rounded-full">
                                                    <span class="text-sm font-semibold">{{ mb_strtoupper(mb_substr($administrator->name, 0, 1)) }}</span>
                                                </div>
                                            </div>
                                            <div class="min-w-0">
                                                <div class="flex items-center gap-2">
                                                    <a href="{{ route('admin.administrators.edit', $administrator) }}" class="font-medium link link-hover">{{ $administrator->name }}</a>
                                                    @if ($isYou)
                                                        <span class="badge badge-sm badge-info">{{ __('You') }}</span>
                                                    @endif
                                                </div>
                                                <div class="text-xs opacity-60">{{ $administrator->email }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="flex flex-wrap gap-1">
                                            @foreach ($administrator->roles as $role)
                                                <span class="badge badge-sm {{ $role->name === $superRole ? 'badge-primary' : 'badge-neutral' }}">{{ $groupLabel($role->name) }}</span>
                                            @endforeach
                                        </div>
                                    </td>
                                    @if ($facts)
                                        <td class="text-sm">
                                            @forelse ($facts[$administrator->id] ?? [] as $fact)
                                                <div class="whitespace-nowrap flex items-center gap-1.5">
                                                    @if (! empty($fact['icon']))
                                                        <x-icon :name="$fact['icon']" size="sm" @class(['text-success' => ($fact['tone'] ?? null) === 'good', 'text-error' => ($fact['tone'] ?? null) === 'bad', 'opacity-50' => empty($fact['tone'])]) />
                                                    @endif
                                                    @if (! empty($fact['url']))
                                                        <a href="{{ $fact['url'] }}" class="link link-hover">{{ $fact['label'] }}</a>
                                                    @else
                                                        {{ $fact['label'] }}
                                                    @endif
                                                </div>
                                                @if (! empty($fact['hint']))
                                                    <div class="text-xs opacity-50 whitespace-nowrap">{{ $fact['hint'] }}</div>
                                                @endif
                                            @empty
                                                <span class="opacity-40 text-xs">—</span>
                                            @endforelse
                                        </td>
                                    @endif
                                    <td class="text-sm whitespace-nowrap">
                                        {{ $administrator->created_at?->format('d.m.Y') }}
                                        <div class="text-xs opacity-50">{{ $administrator->created_at?->diffForHumans() }}</div>
                                    </td>
                                    <td class="text-end whitespace-nowrap">
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ route('admin.administrators.edit', $administrator) }}" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            {{-- Your own account is not deleted from here: the
                                                 panel would sign you out mid-click. --}}
                                            @if ($isYou)
                                                <span class="btn btn-ghost btn-sm btn-square btn-disabled" title="{{ __('You cannot delete your own account.') }}"><x-icon name="trash-2" /></span>
                                            @else
                                                <button form="delete-administrator-{{ $administrator->id }}" class="btn btn-ghost btn-sm btn-square text-error"
                                                        title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"><x-icon name="trash-2" /></button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{ $administrators->links() }}
            @endif
        </div>
    </div>

    {{-- Delete forms live outside the table so no form is ever nested in another. --}}
    @foreach ($administrators as $administrator)
        @unless ($administrator->is(auth()->user()))
            <form id="delete-administrator-{{ $administrator->id }}" method="POST"
                  action="{{ route('admin.administrators.destroy', $administrator) }}" class="hidden"
                  data-confirm="{{ __('Delete :name? They will no longer be able to sign in.', ['name' => $administrator->name]) }}">
                @csrf @method('DELETE')
            </form>
        @endunless
    @endforeach
@endsection
