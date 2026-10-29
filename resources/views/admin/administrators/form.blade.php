@extends('admin.layout')

@section('title', $administrator->exists ? $administrator->name : __('New administrator'))
@section('own_heading', '1')

@section('content')
    @php
        $isYou = $administrator->exists && $administrator->is(auth()->user());
    @endphp

    <x-admin.form-bar>
        <a href="{{ route('admin.administrators.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to administrators') }}" aria-label="{{ __('Back to administrators') }}">
            <x-icon name="arrow-left" size="lg" />
        </a>
        @if ($administrator->exists)
            <div class="avatar avatar-placeholder shrink-0">
                <div class="bg-primary/15 text-primary w-9 rounded-full">
                    <span class="text-sm font-semibold">{{ mb_strtoupper(mb_substr($administrator->name, 0, 1)) }}</span>
                </div>
            </div>
        @endif
        <div class="flex-1 min-w-0">
            <div class="text-xs opacity-50 leading-none">{{ __('Administrator') }}</div>
            <div class="font-semibold truncate leading-tight">{{ $administrator->exists ? $administrator->name : __('New administrator') }}</div>
        </div>
        <div class="dropdown dropdown-end">
            <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                <x-icon name="settings" size="lg" />
            </div>
            <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                <li><button type="submit" form="administrator-form" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                @if ($administrator->exists)
                    <li><a href="mailto:{{ $administrator->email }}"><x-icon name="mail" /> {{ __('Write an email') }}</a></li>
                    @unless ($isYou)
                        <li class="menu-title p-0 my-1"><span class="h-px bg-base-300 block"></span></li>
                        <li><button type="submit" form="administrator-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete the administrator') }}</button></li>
                    @endunless
                @endif
            </ul>
        </div>
        <x-slot:actions>
            <a href="{{ route('admin.administrators.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button type="submit" form="administrator-form" class="btn btn-primary btn-sm">{{ __('Save') }}</button>
        </x-slot:actions>
    </x-admin.form-bar>

    @if (session('status'))
        <div class="alert alert-success text-sm">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    <form method="POST" id="administrator-form"
          action="{{ $administrator->exists ? route('admin.administrators.update', $administrator) : route('admin.administrators.store') }}"
          class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_24rem] items-start">
        @csrf
        @if ($administrator->exists) @method('PUT') @endif

        {{-- First in the page so a phone shows the account first; beside the groups on a wide screen. --}}
        <div class="flex flex-col gap-4 xl:order-2">
            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3" x-data="{ invite: @js(! $administrator->exists && (bool) old('send_invite', true)) }">
                    <h3 class="font-semibold">{{ __('Account') }}</h3>

                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('Full name') }} *</legend>
                        <input name="name" value="{{ old('name', $administrator->name) }}" required class="input input-bordered w-full">
                    </fieldset>

                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('Email') }} *</legend>
                        <input name="email" type="email" value="{{ old('email', $administrator->email) }}" required class="input input-bordered w-full">
                    </fieldset>

                    @unless ($administrator->exists)
                        {{-- The new manager chooses their own password from a
                             letter (PasswordLinks) — nobody else ever knows it. --}}
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="send_invite" value="1" class="checkbox checkbox-sm" x-model="invite" @checked(old('send_invite', true))>
                            <span class="label-text">{{ __('Send them a letter to choose their own password') }}</span>
                        </label>
                    @endunless
                    <fieldset class="fieldset p-0" @unless ($administrator->exists) x-show="! invite" @endunless>
                        <legend class="fieldset-legend">{{ __('Password') }} @unless ($administrator->exists) * @endunless</legend>
                        <input name="password" type="password" autocomplete="new-password" class="input input-bordered w-full"
                               @unless ($administrator->exists) :required="! invite" @endunless>
                    </fieldset>
                    <fieldset class="fieldset p-0" @unless ($administrator->exists) x-show="! invite" @endunless>
                        <legend class="fieldset-legend">{{ __('Repeat password') }}</legend>
                        <input name="password_confirmation" type="password" autocomplete="new-password" class="input input-bordered w-full">
                        @if ($administrator->exists)
                            <p class="label">{{ __('Leave both blank to keep the current password.') }}</p>
                        @endif
                    </fieldset>

                    @if ($administrator->exists)
                        <div class="text-xs opacity-50">{{ __('Joined') }}: {{ $administrator->created_at?->format('d.m.Y') }}</div>
                    @endif
                </div>
            </section>

            {{-- What modules know about this person (the guard: their second
                 factor), through the list's own seam. --}}
            @if ($facts)
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-2 text-sm">
                        <h3 class="font-semibold flex items-center gap-2"><x-icon name="shield-check" size="sm" /> {{ __('Security') }}</h3>
                        @foreach ($facts as $fact)
                            <div class="flex items-center gap-1.5">
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
                                <p class="text-xs opacity-60 -mt-1">{{ $fact['hint'] }}</p>
                            @endif
                        @endforeach
                    </div>
                </section>
            @endif
        </div>

        <section class="card bg-base-100 border border-base-300 xl:order-1">
            <div class="card-body gap-3">
                <h3 class="font-semibold flex items-center gap-2"><x-icon name="key-round" size="sm" /> {{ __('Permission groups') }}</h3>
                <p class="text-sm opacity-70">{{ __('An account with no group is not staff and cannot sign in to the admin panel.') }}</p>

                @if ($isSelf)
                    <div class="alert alert-info text-sm">
                        {{ __('You cannot change your own permissions. Ask another administrator to do it.') }}
                    </div>
                @endif

                <div class="flex flex-col gap-1">
                    @foreach ($roles as $role)
                        @php
                            $canAssign = $assignable->has($role->name);
                            $checked = old('roles') !== null
                                ? in_array($role->name, (array) old('roles'), true)
                                : $administrator->exists && $administrator->hasRole($role->name);
                        @endphp
                        <label @class(['flex items-start gap-3 rounded-box p-2', 'cursor-pointer hover:bg-base-200/60' => $canAssign && ! $isSelf, 'opacity-60' => ! $canAssign || $isSelf])>
                            <input type="checkbox" name="roles[]" value="{{ $role->name }}" class="checkbox checkbox-sm mt-0.5"
                                   @checked($checked) @disabled($isSelf || ! $canAssign)>
                            <span class="min-w-0">
                                <span class="font-medium">{{ $role->name === $superRole ? __('Administrator') : $role->name }}</span>
                                <span class="block text-xs opacity-60">
                                    @if ($role->name === $superRole)
                                        {{ __('Holds every permission, including ones added by modules installed later.') }}
                                    @else
                                        {{ $role->permissions->isEmpty()
                                            ? __('No permissions')
                                            : __('Permissions: :count', ['count' => $role->permissions->count()]) }}
                                    @endif
                                    @unless ($canAssign)
                                        · {{ __('You cannot grant a group that holds permissions you do not have.') }}
                                    @endunless
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
                <a href="{{ route('admin.permission-groups.index') }}" class="link link-hover text-xs opacity-60 w-fit">{{ __('What each group may do') }} →</a>
            </div>
        </section>
    </form>

    @if ($administrator->exists && ! $isYou)
        <form id="administrator-delete" method="POST" action="{{ route('admin.administrators.destroy', $administrator) }}" class="hidden"
              data-confirm="{{ __('Delete :name? They will no longer be able to sign in.', ['name' => $administrator->name]) }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
