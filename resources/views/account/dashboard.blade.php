@extends('layouts.storefront')

@section('title', __('My account').' · '.current_storefront()?->name)

@section('content')
    <div class="flex flex-col md:flex-row gap-6">
        @include('account._nav')

        <div class="flex flex-col gap-6 flex-1 min-w-0">
            <h1 class="text-xl font-bold">{{ __('Profile') }}</h1>

            @if (session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('account.profile.update') }}" class="card bg-base-100 shadow-sm border border-base-300/60">
                @csrf @method('PUT')
                <div class="card-body gap-4">
                    {{-- The name and the button on one line: one field needs
                         no room of its own below it. --}}
                    <div class="flex flex-col sm:flex-row sm:items-end gap-3 max-w-2xl">
                        <label class="fieldset p-0 flex-1"><span class="fieldset-legend">{{ __('Full name') }} *</span>
                            <input name="name" value="{{ old('name', $user->name) }}" required autocomplete="name" class="input w-full">
                        </label>
                        <button class="btn btn-primary">{{ __('Save changes') }}</button>
                    </div>

                    @if ($user->customerGroup)
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="opacity-70">{{ __('Pricing tier') }}:</span>
                            <span class="badge badge-soft badge-primary font-medium">{{ $user->customerGroup->t('name') }}</span>
                            @if ($user->customerGroup->discount_percent > 0)
                                <span class="opacity-70">{{ $user->customerGroup->discount_percent }}% {{ __('off list price') }}</span>
                            @endif
                        </div>
                    @endif
                </div>
            </form>

            {{-- How the account is reached: each changed only through a code
                 sent to the new address (ContactController). --}}
            <section class="card bg-base-100 shadow-sm border border-base-300/60">
                <div class="card-body gap-0 p-0">
                    <h2 class="card-title text-base px-6 pt-5 pb-2">{{ __('Sign-in and contacts') }}</h2>
                    <div class="flex items-center gap-4 px-6 py-4 border-t border-base-300/60">
                        <span class="w-10 h-10 rounded-box bg-base-200 grid place-items-center shrink-0"><x-icon name="mail" /></span>
                        <div class="flex-1 min-w-0">
                            <div class="text-xs opacity-60">Email</div>
                            @if ($user->email)
                                <div class="font-medium break-all">{{ $user->email }}</div>
                                @unless ($user->email_verified_at)
                                    <div class="text-xs font-medium text-warning flex items-center gap-1"><x-icon name="circle-alert" size="sm" /> {{ __('not confirmed yet') }}</div>
                                @endunless
                            @else
                                <div class="opacity-60">{{ __('No email — the shop sends you no letters') }}</div>
                            @endif
                        </div>
                        <a href="{{ route('account.contact', 'email') }}" class="btn btn-ghost btn-sm btn-square" title="{{ $user->email ? __('Change the email') : __('Add an email') }}"><x-icon :name="$user->email ? 'pencil' : 'plus'" size="sm" /></a>
                        {{-- Only while something else still signs in (AccountContact). --}}
                        @if ($user->email && ! app(\App\Core\Access\AccountContact::class)->removalRefusal($user, 'email'))
                            <form method="POST" action="{{ route('account.contact.email.destroy') }}" data-confirm="{{ __('Remove the email from your account? You will sign in with your phone.') }}">
                                @csrf @method('DELETE')
                                <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Remove the email') }}"><x-icon name="trash-2" size="sm" /></button>
                            </form>
                        @endif
                    </div>
                    @if ($phones || $user->phone)
                        <div class="flex items-center gap-4 px-6 py-4 border-t border-base-300/60">
                            <span class="w-10 h-10 rounded-box bg-base-200 grid place-items-center shrink-0"><x-icon name="phone" /></span>
                            <div class="flex-1 min-w-0">
                                <div class="text-xs opacity-60">{{ __('Phone') }}</div>
                                @if ($user->phone)
                                    <div class="font-medium">{{ \App\Core\Support\Phones::display($user->phone) }}</div>
                                    <div class="text-xs opacity-60">{{ __('You can sign in with it and get codes on it.') }}</div>
                                @else
                                    <div class="text-sm opacity-60">{{ __('Not added. Add one to sign in with it and get codes on it.') }}</div>
                                @endif
                            </div>
                            @if ($phones)
                                <a href="{{ route('account.contact', 'phone') }}" class="btn btn-ghost btn-sm btn-square" title="{{ $user->phone ? __('Change the phone') : __('Add a phone') }}">
                                    <x-icon :name="$user->phone ? 'pencil' : 'plus'" size="sm" />
                                </a>
                            @endif
                            @if ($user->phone && ! app(\App\Core\Access\AccountContact::class)->removalRefusal($user, 'phone'))
                                <form method="POST" action="{{ route('account.contact.phone.destroy') }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Remove the phone') }}"><x-icon name="trash-2" size="sm" /></button>
                                </form>
                            @endif
                        </div>
                    @endif
                </div>
            </section>

            <form method="POST" action="{{ route('account.password.update') }}" class="card bg-base-100 shadow-sm border border-base-300/60">
                @csrf @method('PUT')
                <div class="card-body gap-4">
                    <h2 class="card-title text-base">{{ __('Change password') }}</h2>

                    <div class="grid sm:grid-cols-2 gap-x-4 gap-y-1 max-w-2xl">
                        <label class="fieldset p-0 sm:col-span-2 sm:max-w-[calc(50%-0.5rem)]"><span class="fieldset-legend">{{ __('Current password') }} *</span>
                            <input type="password" name="current_password" required autocomplete="current-password" class="input w-full">
                        </label>
                        <label class="fieldset p-0"><span class="fieldset-legend">{{ __('New password') }} *</span>
                            <input type="password" name="password" required autocomplete="new-password" class="input w-full">
                        </label>
                        <label class="fieldset p-0"><span class="fieldset-legend">{{ __('Confirm new password') }} *</span>
                            <input type="password" name="password_confirmation" required autocomplete="new-password" class="input w-full">
                        </label>
                    </div>

                    <div class="card-actions">
                        <button class="btn btn-primary">{{ __('Update password') }}</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endsection
