{{-- Writing one.

     The stars are radio buttons, not a script: a rating control built out of
     JavaScript is one that does nothing on a phone with a blocked script and
     tells nobody why. daisyUI's `rating` is exactly this — five radios drawn as
     stars — so the form works with no JavaScript at all, and the popup is a
     native <dialog>.

     The criteria are whatever this product is rated by (see Criteria); with
     none configured the form asks for one overall mark instead, because a
     review with no number in it cannot be aggregated and this section is the
     number. --}}
<x-modal :name="$modalName" :title="__('What do you think of this product?')" icon="message-square-quote" size="lg">
    {{-- Multipart only where photographs are actually on offer: a plain
         review form posts what it always did. --}}
    <form method="POST" action="{{ route('reviews.store', $product->slug) }}" class="flex flex-col gap-4"
          @if (\Torgash\Reviews\Photos::enabled()) enctype="multipart/form-data" @endif>
        @csrf
        <x-form-guard form="review" />

        @if ($rewardNote)
            {{-- The shop's own words: this engine awards nothing by itself, so
                 whatever is promised here is promised by a person who can keep
                 it. --}}
            <div class="alert alert-info text-sm">{{ $rewardNote }}</div>
        @endif

        <div class="text-sm opacity-70">{{ $product->t('name') }}</div>

        @if ($criteria->isEmpty())
            <fieldset class="fieldset p-0">
                <legend class="fieldset-legend">{{ __('Your rating') }}</legend>
                <div class="rating rating-lg">
                    @for ($i = 1; $i <= 5; $i++)
                        <input type="radio" name="rating" value="{{ $i }}"
                               class="mask mask-star-2 bg-(--torgash-star)"
                               aria-label="{{ trans_choice('{1}:count star|[2,*]:count stars', $i, ['count' => $i]) }}"
                               @checked((int) old('rating') === $i)>
                    @endfor
                </div>
            </fieldset>
        @else
            <fieldset class="fieldset p-0">
                <legend class="fieldset-legend">{{ __('How do you rate it?') }}</legend>
                <div class="flex flex-col gap-2">
                    @foreach ($criteria as $criterion)
                        <div class="flex items-center justify-between gap-4">
                            <span class="text-sm">{{ $criterion->label() }}</span>
                            <div class="rating">
                                @for ($i = 1; $i <= 5; $i++)
                                    <input type="radio" name="criteria[{{ $criterion->id }}]" value="{{ $i }}"
                                           class="mask mask-star-2 bg-(--torgash-star)"
                                           aria-label="{{ $criterion->label() }}: {{ $i }}"
                                           @checked((int) old('criteria.'.$criterion->id) === $i)>
                                @endfor
                            </div>
                        </div>
                    @endforeach
                </div>
                <p class="label text-xs opacity-60 whitespace-normal">
                    {{ __('Rate what you can judge — the overall mark is the average of what you fill in.') }}
                </p>
            </fieldset>
        @endif

        @if (setting('torgash.reviews', 'pros_cons', true))
            <div class="grid md:grid-cols-2 gap-3">
                <label class="fieldset p-0">
                    <span class="fieldset-legend text-xs">{{ __('Pros') }}</span>
                    <textarea name="pros" rows="2" class="textarea textarea-bordered w-full">{{ old('pros') }}</textarea>
                </label>
                <label class="fieldset p-0">
                    <span class="fieldset-legend text-xs">{{ __('Cons') }}</span>
                    <textarea name="cons" rows="2" class="textarea textarea-bordered w-full">{{ old('cons') }}</textarea>
                </label>
            </div>
        @endif

        <label class="fieldset p-0">
            <span class="fieldset-legend text-xs">{{ __('Your review') }}</span>
            <textarea name="body" rows="4" class="textarea textarea-bordered w-full"
                      placeholder="{{ __('What you thought, what you would tell another buyer') }}">{{ old('body') }}</textarea>
        </label>

        @if (\Torgash\Reviews\Photos::enabled())
            {{-- Rides the same POST as the words: there is no upload endpoint
                 of its own, so there is no second public door to guard and
                 the review form's own rate limit already counts it. A file
                 that will not do is reported and the review is still
                 saved. --}}
            <fieldset class="fieldset p-0">
                <legend class="fieldset-legend text-xs">{{ __('Photos') }}</legend>
                <input aria-label="{{ __('Photos') }}" type="file" name="photos[]" multiple accept="image/*"
                       class="file-input file-input-bordered file-input-sm w-full">
                <p class="text-xs opacity-50">
                    {{ trans_choice('{1}One photo|[2,*]Up to :count photos', \Torgash\Reviews\Photos::maxFiles(), ['count' => \Torgash\Reviews\Photos::maxFiles()]) }}
                </p>
            </fieldset>
        @endif

        @if (setting('torgash.reviews', 'recommendation', true))
            <fieldset class="fieldset p-0">
                <legend class="fieldset-legend text-xs">{{ __('Would you recommend it?') }}</legend>
                <div class="flex flex-wrap gap-2">
                    @foreach (['yes' => __('I recommend it'), 'neutral' => __('Neutral'), 'no' => __('I do not recommend it')] as $value => $label)
                        <label class="btn btn-sm has-[:checked]:btn-primary">
                            <input type="radio" name="recommend" value="{{ $value }}" class="hidden"
                                   @checked(old('recommend') === $value)>
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
        @endif

        <p class="text-xs opacity-60">
            @if (setting('torgash.reviews', 'premoderate', true))
                {{ __('Your review is published once the shop has read it.') }}
            @else
                {{ __('Your review appears on the page straight away.') }}
            @endif
        </p>

        <button type="submit" class="btn btn-primary self-start">{{ __('Leave a review') }}</button>
    </form>
</x-modal>
