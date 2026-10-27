@extends('admin.layout')

@section('title', __('New request'))

@section('content')
    <div class="flex flex-col gap-4 max-w-2xl">
        @include('torgash.privacy::admin._tabs')

        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('admin.privacy.requests.store') }}" class="card bg-base-100 border border-base-300">
            @csrf
            <div class="card-body gap-3">
                <p class="text-sm opacity-70">{{ __('For a person who wrote or called. Their orders and enquiries are found by the email and the phone given here — make sure it is really them before erasing anything.') }}</p>
                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend">{{ __('What they ask') }}</legend>
                    <select name="type" class="select select-bordered w-full">
                        <option value="export" @selected(old('type') === 'export')>{{ __('Download my data') }}</option>
                        <option value="erase" @selected(old('type') === 'erase')>{{ __('Erase my data') }}</option>
                    </select>
                </fieldset>
                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend">{{ __('Name') }}</legend>
                    <input name="name" value="{{ old('name') }}" class="input input-bordered w-full">
                </fieldset>
                <div class="grid sm:grid-cols-2 gap-3">
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('Email') }}</legend>
                        <input name="email" type="email" value="{{ old('email') }}" class="input input-bordered w-full">
                    </fieldset>
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('Phone') }}</legend>
                        <input name="phone" value="{{ old('phone') }}" class="input input-bordered w-full">
                    </fieldset>
                </div>
                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend">{{ __('Note') }}</legend>
                    <textarea name="note" rows="3" class="textarea textarea-bordered w-full">{{ old('note') }}</textarea>
                </fieldset>
                <div class="flex gap-2 justify-end">
                    <a href="{{ route('admin.privacy.requests.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                    <button class="btn btn-primary btn-sm">{{ __('Open the request') }}</button>
                </div>
            </div>
        </form>
    </div>
@endsection
