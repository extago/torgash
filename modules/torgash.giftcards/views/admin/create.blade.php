@extends('admin.layout')

@section('title', __('Issue gift cards'))
@section('own_heading', '1')

@section('content')
    <form method="POST" action="{{ route('admin.giftcards.store') }}" class="flex flex-col gap-4"
          x-data="{ mode: {{ \Illuminate\Support\Js::from(old('mode', 'single')) }} }">
        @csrf

        <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
            <a href="{{ route('admin.giftcards.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to gift cards') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ __('Gift cards') }}</div>
                <div class="font-semibold truncate leading-tight">{{ __('Issue gift cards') }}</div>
            </div>
            <a href="{{ route('admin.giftcards.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button class="btn btn-primary btn-sm">{{ __('Issue') }}</button>
        </div>

        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <div class="join">
                            <label class="btn btn-sm join-item" :class="mode === 'single' && 'btn-active'">
                                <input type="radio" name="mode" value="single" class="hidden" x-model="mode"> {{ __('One card, for somebody') }}
                            </label>
                            <label class="btn btn-sm join-item" :class="mode === 'batch' && 'btn-active'">
                                <input type="radio" name="mode" value="batch" class="hidden" x-model="mode"> {{ __('A batch for printing') }}
                            </label>
                        </div>
                        <p class="text-xs opacity-60 -mt-2" x-show="mode === 'batch'" x-cloak>
                            {{ __('Cards in a batch are not money until a manager activates the one handed over at the till — a stolen box of printed cards is worth nothing. Download the codes for the printer from the list.') }}
                        </p>

                        <div class="grid md:grid-cols-2 gap-4">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Amount of each card') }} *</legend>
                                <label class="input input-bordered w-full">
                                    <input name="amount" type="number" step="0.01" min="0.01" required class="grow" value="{{ old('amount') }}">
                                    <span class="opacity-50">{{ shop_currency() }}</span>
                                </label>
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Valid until') }}</legend>
                                <input name="expires_at" type="date" class="input input-bordered w-full" value="{{ old('expires_at', $defaultExpiry) }}">
                                <p class="label text-xs opacity-60 whitespace-normal">{{ __('Empty — no limit. Filled in from the module\'s settings.') }}</p>
                            </fieldset>
                        </div>

                        <div class="grid md:grid-cols-2 gap-4" x-show="mode === 'batch'" x-cloak>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('How many') }} *</legend>
                                <input name="count" type="number" min="1" max="1000" class="input input-bordered w-full" value="{{ old('count', 10) }}" :disabled="mode !== 'batch'">
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Batch name') }} *</legend>
                                <input name="batch" maxlength="64" class="input input-bordered w-full" value="{{ old('batch', 'batch-'.now()->format('Y-m-d')) }}" :disabled="mode !== 'batch'">
                                <p class="label text-xs opacity-60 whitespace-normal">{{ __('To find these cards again and download their codes.') }}</p>
                            </fieldset>
                            <label class="label cursor-pointer justify-start gap-3 md:col-span-2">
                                <input type="checkbox" name="active" value="1" class="checkbox checkbox-sm" @checked(old('active')) :disabled="mode !== 'batch'">
                                <span class="label-text">{{ __('Active at once (for a partner, not for a till)') }}</span>
                            </label>
                        </div>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300" x-show="mode === 'single'">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('For whom') }}</h3>
                        <div class="grid md:grid-cols-2 gap-4">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Recipient\'s name') }}</legend>
                                <input name="recipient_name" maxlength="190" class="input input-bordered w-full" value="{{ old('recipient_name') }}">
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Recipient\'s email') }}</legend>
                                <input name="recipient_email" type="email" maxlength="190" class="input input-bordered w-full" value="{{ old('recipient_email') }}">
                                <p class="label text-xs opacity-60 whitespace-normal">{{ __('The card is sent there. Empty — no letter.') }}</p>
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('From') }}</legend>
                                <input name="sender_name" maxlength="190" class="input input-bordered w-full" value="{{ old('sender_name', current_storefront()?->name) }}">
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Send on') }}</legend>
                                <input name="deliver_at" type="datetime-local" class="input input-bordered w-full" value="{{ old('deliver_at') }}">
                                <p class="label text-xs opacity-60 whitespace-normal">{{ __('Empty — at once.') }}</p>
                            </fieldset>
                        </div>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Message') }}</legend>
                            <textarea name="message" rows="3" maxlength="500" class="textarea textarea-bordered w-full">{{ old('message') }}</textarea>
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Put it in this customer\'s account') }}</legend>
                            <input name="owner_email" type="email" maxlength="190" class="input input-bordered w-full max-w-md" value="{{ old('owner_email') }}" placeholder="customer@example.com">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('Then only that account can spend it, with one click at checkout.') }}</p>
                        </fieldset>
                    </div>
                </section>

                @include('torgash.giftcards::admin._rules', ['giftRules' => []])
            </div>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Note') }}</h3>
                        <textarea name="note" rows="4" maxlength="1000" class="textarea textarea-bordered w-full"
                                  placeholder="{{ __('Why it was issued — the shop\'s own note, never shown to the customer.') }}">{{ old('note') }}</textarea>
                    </div>
                </section>
            </div>
        </div>
    </form>
@endsection
