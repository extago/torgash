{{-- Writing a review of the shop, in a popup. The stars are radio buttons
     (daisyUI `rating`), so the form works with no JavaScript at all. With
     criteria the overall mark is their average; with none, one mark is
     asked. A guest gives a name and an email (never shown). --}}
@php
    $srUser = auth()->user();
    $srAsk = fn (string $key) => (bool) setting('torgash.shopreviews', $key, true);
@endphp
<x-modal name="shop-review" :title="__('Your review of the shop')" icon="message-square-heart" size="lg">
    <form method="POST" action="{{ route('shopreviews.store') }}" class="flex flex-col gap-4">
        @csrf
        <x-form-guard form="shop_review" />

        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        @if ($criteria->isEmpty())
            <fieldset class="fieldset p-0">
                <legend class="fieldset-legend">{{ __('Your rating') }}</legend>
                <div class="rating rating-lg">
                    @for ($i = 1; $i <= 5; $i++)
                        <input type="radio" name="rating" value="{{ $i }}" class="mask mask-star-2 bg-(--torgash-star)"
                               aria-label="{{ trans_choice('{1}:count star|[2,*]:count stars', $i, ['count' => $i]) }}"
                               @checked((int) old('rating') === $i)>
                    @endfor
                </div>
            </fieldset>
        @else
            <fieldset class="fieldset p-0">
                <legend class="fieldset-legend">{{ __('Please rate the shop on each of these') }}</legend>
                <div class="flex flex-col gap-2">
                    @foreach ($criteria as $srCriterion)
                        <div class="flex items-center justify-between gap-4">
                            <span>{{ $srCriterion->label() }}</span>
                            <div class="rating">
                                @for ($i = 1; $i <= 5; $i++)
                                    <input type="radio" name="criteria[{{ $srCriterion->id }}]" value="{{ $i }}"
                                           class="mask mask-star-2 bg-(--torgash-star)"
                                           aria-label="{{ $srCriterion->label() }}: {{ $i }}"
                                           @checked((int) old('criteria.'.$srCriterion->id) === $i)>
                                @endfor
                            </div>
                        </div>
                    @endforeach
                </div>
                <p class="label text-xs opacity-60 whitespace-normal">{{ __('Rate what you can judge — the overall mark is the average of what you fill in.') }}</p>
            </fieldset>
        @endif

        @if ($srAsk('ask_recommend') || $srAsk('ask_return'))
            <div class="flex flex-col gap-2">
                @if ($srAsk('ask_recommend'))
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="hidden" name="recommend" value="0">
                        <input type="checkbox" name="recommend" value="1" class="checkbox checkbox-primary" @checked(old('recommend', '1') === '1')>
                        <span>{{ __('I would recommend this shop to people I know') }}</span>
                    </label>
                @endif
                @if ($srAsk('ask_return'))
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="hidden" name="will_return" value="0">
                        <input type="checkbox" name="will_return" value="1" class="checkbox checkbox-primary" @checked(old('will_return', '1') === '1')>
                        <span>{{ __('I will buy here again') }}</span>
                    </label>
                @endif
            </div>
        @endif

        @if ($srAsk('pros_cons'))
            <div class="grid md:grid-cols-2 gap-3">
                <label class="fieldset p-0">
                    <span class="fieldset-legend">{{ __('Pros') }}</span>
                    <textarea name="pros" rows="2" maxlength="2000" class="textarea textarea-bordered w-full">{{ old('pros') }}</textarea>
                </label>
                <label class="fieldset p-0">
                    <span class="fieldset-legend">{{ __('Cons') }}</span>
                    <textarea name="cons" rows="2" maxlength="2000" class="textarea textarea-bordered w-full">{{ old('cons') }}</textarea>
                </label>
            </div>
        @endif

        <label class="fieldset p-0">
            <span class="fieldset-legend">{{ __('Your review') }}</span>
            <textarea name="body" rows="4" required maxlength="5000" class="textarea textarea-bordered w-full"
                      placeholder="{{ __('How the order went: the delivery, the service, what you would tell a friend') }}">{{ old('body') }}</textarea>
        </label>

        <div class="grid md:grid-cols-2 gap-3">
            <label class="fieldset p-0">
                <span class="fieldset-legend">{{ __('Your name') }}</span>
                <input name="name" value="{{ old('name', $srUser?->name) }}" maxlength="120" class="input input-bordered w-full" @unless ($srUser) required @endunless>
            </label>
            @if ($srAsk('ask_city'))
                <label class="fieldset p-0">
                    <span class="fieldset-legend">{{ __('Town') }}</span>
                    <input name="city" value="{{ old('city') }}" maxlength="120" class="input input-bordered w-full">
                </label>
            @endif
            @unless ($srUser)
                <label class="fieldset p-0">
                    <span class="fieldset-legend">{{ __('Email') }}</span>
                    <input type="email" name="email" value="{{ old('email') }}" maxlength="190" required class="input input-bordered w-full">
                    <span class="label text-xs opacity-60 whitespace-normal">{{ __('Not shown on the site — the shop\'s answer is sent there.') }}</span>
                </label>
            @endunless
        </div>

        <p class="text-xs opacity-60">
            {{ setting('torgash.shopreviews', 'premoderate', true) ? __('Your review is published once the shop has read it.') : __('Your review appears on the page straight away.') }}
        </p>

        <button type="submit" class="btn btn-primary btn-lg self-start">{{ __('Leave a review') }}</button>
    </form>
</x-modal>

@if ($errors->any())
    {{-- Sent back with a mistake: the popup opens again with what was typed. --}}
    <div x-data x-init="document.getElementById('modal-shop-review')?.showModal()"></div>
@endif
