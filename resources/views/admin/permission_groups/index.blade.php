@extends('admin.layout')

@section('title', __('Permission groups'))
@section('title_hint', __('A permission group is a named set of what its members may do — "Sales manager", "Content editor". An administrator can be in several; the built-in Administrator group may do everything, including what modules installed later add.'))
@section('title_actions')
    <a href="{{ route('admin.permission-groups.create') }}" class="btn btn-primary">+ {{ __('New group') }}</a>
@endsection

@section('content')
    @include('admin.access._nav', ['accessScreen' => 'groups'])

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">

            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('Name') }}</th>
                            <th>{{ __('Permissions') }}</th>
                            <th>{{ __('Members') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($groups as $group)
                            @php $isSuper = $group->name === $superRole; @endphp
                            <tr class="hover:bg-base-200/50">
                                <td>
                                    <div class="flex items-center gap-2">
                                        @if ($isSuper)
                                            <span class="font-medium">{{ __('Administrator') }}</span>
                                            <span class="badge badge-primary badge-sm">{{ __('Built-in') }}</span>
                                        @else
                                            <a href="{{ route('admin.permission-groups.edit', $group) }}" class="font-medium link link-hover">{{ $group->name }}</a>
                                        @endif
                                    </div>
                                </td>
                                <td class="text-sm">
                                    {{ $isSuper ? __('Everything') : ($group->permissions_count ?: __('None')) }}
                                </td>
                                <td class="text-sm">
                                    @if ($group->users_count)
                                        <a href="{{ route('admin.administrators.index', ['group' => $group->name]) }}" class="link link-hover">{{ $group->users_count }}</a>
                                    @else
                                        <span class="opacity-50">0</span>
                                    @endif
                                </td>
                                <td class="text-end whitespace-nowrap">
                                    {{-- The built-in group has nothing to edit and cannot go. --}}
                                    @unless ($isSuper)
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ route('admin.permission-groups.edit', $group) }}" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            @if ($group->users_count === 0)
                                                <button form="delete-group-{{ $group->id }}" class="btn btn-ghost btn-sm btn-square text-error"
                                                        title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"><x-icon name="trash-2" /></button>
                                            @else
                                                <span class="btn btn-ghost btn-sm btn-square btn-disabled" title="{{ __('Move its members to another group first.') }}"><x-icon name="trash-2" /></span>
                                            @endif
                                        </div>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($groups->count() === 1)
                <p class="text-sm opacity-60">
                    {{ __('Only the built-in group exists, so everybody on the staff may do everything. Make a group for each role in the shop and tick only what it needs.') }}
                </p>
            @endif
        </div>
    </div>

    {{-- Kept out of the table: a form inside a form loses its method spoof. --}}
    @foreach ($groups as $group)
        @if ($group->name !== $superRole && $group->users_count === 0)
            <form id="delete-group-{{ $group->id }}" method="POST"
                  action="{{ route('admin.permission-groups.destroy', $group) }}" class="hidden"
                  data-confirm="{{ __('Delete this permission group?') }}">
                @csrf @method('DELETE')
            </form>
        @endif
    @endforeach
@endsection
