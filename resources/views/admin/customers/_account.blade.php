{{-- The account's own fields — the same on the new customer's page and on
     the card. `$customer` may be unsaved. --}}
@php
    $accountLocale = admin_content_locale();
    $accountContact = app(\App\Core\Access\AccountContact::class);
@endphp

<fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Full name') }}</legend>
    <input name="name" value="{{ old('name', $customer->name) }}" required class="input input-bordered w-full">
</fieldset>

<fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Email') }}</legend>
    <input name="email" type="email" value="{{ old('email', $customer->email) }}" @required($accountContact->requires('email')) class="input input-bordered w-full">
    @if ($customer->exists)
        <p class="label text-xs whitespace-normal {{ $customer->email_verified_at ? 'text-success' : 'opacity-60' }}">
            {{ $customer->email_verified_at ? __('Confirmed :date', ['date' => $customer->email_verified_at->format('d.m.Y')]) : __('Not confirmed') }}
        </p>
    @endif
</fieldset>

{{-- The account's own phone: signs in and gets the account back by a
     code. Typed here it counts as proven — the shop vouches for it. --}}
<fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Phone') }}</legend>
    <input name="phone" type="tel" value="{{ old('phone', $customer->phone ? \App\Core\Support\Phones::display($customer->phone) : '') }}" @required($accountContact->requires('phone')) class="input input-bordered w-full">
    @if ($accountContact->eitherWillDo())
        <p class="label text-xs opacity-60 whitespace-normal">{{ __('An email or a phone — either will do.') }}</p>
    @endif
</fieldset>

<fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Customer group') }}</legend>
    <select name="customer_group_id" class="select select-bordered w-full">
        <option value="">{{ __('No group — list prices') }}</option>
        @foreach ($groups as $group)
            <option value="{{ $group->id }}" @selected((int) old('customer_group_id', $customer->customer_group_id) === $group->id)>
                {{ $group->t('name', $accountLocale) }}@if ($group->discount_percent > 0) (−{{ $group->discount_percent }}%)@endif
            </option>
        @endforeach
    </select>
    <p class="label text-xs opacity-60 whitespace-normal">{{ __('Decides the prices this customer sees when signed in.') }}</p>
</fieldset>

<fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ $customer->exists ? __('New password') : __('Password') }}</legend>
    <input name="password" type="password" autocomplete="new-password" minlength="8" class="input input-bordered w-full">
    <p class="label text-xs opacity-60 whitespace-normal">
        {{ $customer->exists
            ? __('Leave blank to keep the one they have.')
            : __('At least 8 characters. Leave blank and nobody can sign in to this account until a password is set here.') }}
    </p>
</fieldset>
