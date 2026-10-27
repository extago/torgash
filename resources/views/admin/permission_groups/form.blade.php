@extends('admin.layout')

@section('title', $isSuper ? __('Administrator') : ($group->exists ? $group->name : __('New permission group')))
@section('own_heading', '1')

@section('content')
    <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
        <a href="{{ route('admin.permission-groups.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to permission groups') }}">
            <x-icon name="arrow-left" size="lg" />
        </a>
        <div class="flex-1 min-w-0">
            <div class="text-xs opacity-50 leading-none">{{ __('Permission group') }}</div>
            <div class="font-semibold truncate leading-tight">
                {{ $isSuper ? __('Administrator') : ($group->exists ? $group->name : __('New permission group')) }}
            </div>
        </div>
        @unless ($isSuper)
            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}">
                    <x-icon name="settings" size="lg" />
                </div>
                <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-64 mt-2 p-2 z-50 border border-base-300">
                    <li><button type="submit" form="group-form" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                    @if ($group->exists)
                        <li class="menu-title p-0 my-1"><span class="h-px bg-base-300 block"></span></li>
                        @if ($members->isEmpty())
                            <li><button type="submit" form="group-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete the group') }}</button></li>
                        @else
                            <li class="disabled" title="{{ __('Move its members to another group first.') }}"><span><x-icon name="trash-2" /> {{ __('Delete the group') }}</span></li>
                        @endif
                    @endif
                </ul>
            </div>
        @endunless
        <a href="{{ route('admin.permission-groups.index') }}" class="btn btn-ghost btn-sm">{{ $isSuper ? __('Back') : __('Cancel') }}</a>
        @unless ($isSuper)
            <button type="submit" form="group-form" class="btn btn-primary btn-sm">{{ __('Save') }}</button>
        @endunless
    </div>

    @if (session('status'))
        <div class="alert alert-success text-sm">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    <form method="POST" id="group-form"
          action="{{ $group->exists ? route('admin.permission-groups.update', $group) : route('admin.permission-groups.store') }}"
          class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_24rem] items-start">
        @csrf
        @if ($group->exists) @method('PUT') @endif

        <div class="flex flex-col gap-4 xl:order-2">
            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('Name') }} *</legend>
                        <input name="name" value="{{ old('name', $isSuper ? __('Administrator') : $group->name) }}" required @disabled($isSuper)
                               placeholder="{{ __('e.g. Sales manager') }}" class="input input-bordered w-full">
                    </fieldset>
                </div>
            </section>

            @if ($group->exists)
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-2">
                        <h3 class="font-semibold flex items-center gap-2"><x-icon name="users" size="sm" /> {{ __('Members') }} <span class="opacity-50 font-normal">{{ $members->count() }}</span></h3>
                        @forelse ($members as $member)
                            <a href="{{ route('admin.administrators.edit', $member) }}" class="text-sm link link-hover w-fit">{{ $member->name }}
                                <span class="opacity-50">{{ $member->email }}</span></a>
                        @empty
                            <p class="text-sm opacity-60">{{ __('Nobody is in this group yet. A group is given to people on the administrator\'s form.') }}</p>
                        @endforelse
                    </div>
                </section>
            @endif
        </div>

        <section class="card bg-base-100 border border-base-300 xl:order-1">
            <div class="card-body gap-4">
                <h3 class="font-semibold flex items-center gap-2"><x-icon name="key-round" size="sm" /> {{ __('Permissions') }}</h3>

                @if ($isSuper)
                    <div class="alert alert-info text-sm">
                        {{ __('The built-in administrator group holds every permission, including ones added by modules installed later. There is nothing to tick and nothing to rename.') }}
                    </div>
                @else
                    <p class="text-sm opacity-70">
                        {{ __('Grouped by the module that introduced them. A module has to be installed for its permissions to appear here.') }}
                    </p>

                    <div class="grid gap-x-8 gap-y-5 lg:grid-cols-2">
                        @foreach ($catalog as $section)
                            {{-- "All of this module" is a convenience over the boxes
                                 below, never a value of its own: what is posted is
                                 still the ticked permissions. --}}
                            <div x-data="{ toggle(on) { this.$root.querySelectorAll('input[data-permission]:not(:disabled)').forEach(b => b.checked = on) } }">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="text-xs font-semibold uppercase tracking-wider opacity-50">{{ $section['title'] }}</span>
                                    @if (count($section['permissions']) > 1)
                                        <button type="button" class="link link-hover text-xs opacity-60 ms-auto" x-on:click="toggle(true)">{{ __('all') }}</button>
                                        <button type="button" class="link link-hover text-xs opacity-60" x-on:click="toggle(false)">{{ __('none') }}</button>
                                    @endif
                                </div>
                                <div class="flex flex-col gap-0.5">
                                    @foreach ($section['permissions'] as $permission)
                                        @php
                                            $canAssign = isset($assignable[$permission['name']]);
                                            $checked = old('permissions') !== null
                                                ? in_array($permission['name'], (array) old('permissions'), true)
                                                : $held->has($permission['name']);
                                        @endphp
                                        <label @class(['flex items-start gap-3 rounded-box px-2 py-1', 'cursor-pointer hover:bg-base-200/60' => $canAssign, 'opacity-50' => ! $canAssign])
                                               @unless ($canAssign) title="{{ __('You cannot grant a permission you do not have.') }}" @endunless>
                                            <input type="checkbox" name="permissions[]" value="{{ $permission['name'] }}" data-permission
                                                   class="checkbox checkbox-sm mt-0.5" @checked($checked) @disabled(! $canAssign)>
                                            <span class="min-w-0">
                                                <span class="text-sm">{{ $permission['label'] }}</span>
                                                <code class="block text-xs opacity-40">{{ $permission['name'] }}</code>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>
    </form>

    @if ($group->exists && ! $isSuper && $members->isEmpty())
        <form id="group-delete" method="POST" action="{{ route('admin.permission-groups.destroy', $group) }}" class="hidden"
              data-confirm="{{ __('Delete this permission group?') }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
