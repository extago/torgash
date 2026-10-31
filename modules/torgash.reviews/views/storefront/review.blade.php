@php
    $labels = $criteria->keyBy('id');
    $stars = $review->stars();
    $initial = mb_strtoupper(mb_substr(trim((string) $review->author_name), 0, 1)) ?: '?';
    // A colour per PERSON, worked out from the name: twenty grey circles read
    // as one anonymous crowd, and a hue is the cheapest way to tell people
    // apart. Mid lightness, so it reads on both the light and the dark theme.
    $hue = crc32((string) $review->author_name) % 360;
@endphp

{{-- One review: somebody's whole opinion, read in the order a person reads
     it — who, how much, what they liked, what they did not, then the prose,
     then what others made of it. A mark at the bottom makes a reader scroll to
     find out whether the paragraph above was worth reading.

     Not a box: the list divides reviews with a line, so the page reads as a
     list of people rather than a grid of cards. --}}
<article class="py-6 flex flex-col gap-4">
    <header class="flex items-start gap-4">
        <div class="w-11 h-11 rounded-full shrink-0 flex items-center justify-center font-semibold"
             style="background: oklch(70% 0.12 {{ $hue }} / 0.18); color: oklch(55% 0.14 {{ $hue }});" aria-hidden="true">
            {{ $initial }}
        </div>

        <div class="grow min-w-0">
            <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                <span class="font-semibold">{{ $review->author_name }}</span>
                @if ($review->verified)
                    {{-- The mark that does the work: a reader believes "bought
                         it here" far more than any number of stars. --}}
                    <span class="inline-flex items-center gap-1 text-xs text-success font-medium">
                        <x-icon name="badge-check" size="sm" />
                        {{ __('Verified purchase') }}
                    </span>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-0.5 text-sm">
                <time class="opacity-50" datetime="{{ $review->created_at?->toDateString() }}">{{ $review->created_at?->format('d.m.Y') }}</time>
                {{-- Written about ANOTHER variation of this model (variations
                     share their reviews): said, so a remark about the black
                     one's colour is not read as one about the white. --}}
                @if ((int) $review->product_id !== ($reviewPageProductId ?? (int) $review->product_id) && ($reviewVariants[(int) $review->product_id] ?? null))
                    <span class="inline-flex items-center gap-1 opacity-60">
                        <x-icon name="layers" size="sm" /> {{ __('About the variant: :variant', ['variant' => $reviewVariants[(int) $review->product_id]]) }}
                    </span>
                @endif
                @if ($review->recommend)
                    <span class="inline-flex items-center gap-1 {{ $review->recommend === 'yes' ? 'text-success' : ($review->recommend === 'no' ? 'text-error' : 'opacity-60') }}">
                        @switch($review->recommend)
                            @case('yes')
                                <x-icon name="thumbs-up" size="sm" /> {{ __('Recommends this product') }}
                                @break
                            @case('no')
                                <x-icon name="thumbs-down" size="sm" /> {{ __('Does not recommend this product') }}
                                @break
                            @default
                                {{ __('Neutral about this product') }}
                        @endswitch
                    </span>
                @endif
            </div>
        </div>

        <div class="flex items-center gap-2 shrink-0" title="{{ __(':rating out of 5', ['rating' => number_format((float) $review->rating, 1)]) }}">
            <span class="hidden sm:flex items-center text-(--torgash-star)" aria-hidden="true">
                @for ($i = 1; $i <= 5; $i++)
                    <x-icon name="star" size="sm" class="{{ $i <= $stars ? 'fill-current' : 'opacity-25' }}" />
                @endfor
            </span>
            <span class="sm:hidden text-(--torgash-star)"><x-icon name="star" size="sm" class="fill-current" /></span>
            <span class="font-semibold tabular-nums">{{ number_format((float) $review->rating, 1) }}</span>
        </div>
    </header>

    <div class="flex flex-col gap-4 sm:ps-15">
        @if ($review->ratings->isNotEmpty())
            <div class="flex flex-wrap gap-x-5 gap-y-1.5 text-sm">
                @foreach ($review->ratings as $score)
                    {{-- A score whose criterion the shop has since deleted keeps
                         its number and loses its name, which is the whole of
                         what deleting a criterion means. --}}
                    @php $criterion = $labels[$score->criterion_id] ?? null; @endphp
                    @continue(! $criterion)
                    <span class="inline-flex items-center gap-1.5">
                        <span class="opacity-60">{{ $criterion->label() }}</span>
                        <span class="font-semibold tabular-nums">{{ $score->value }}</span>
                    </span>
                @endforeach
            </div>
        @endif

        @if ($review->pros || $review->cons)
            {{-- What they liked and what they did not: the sign carries the
                 colour, the words stay the page's own. --}}
            <div class="grid sm:grid-cols-2 gap-x-8 gap-y-3">
                @if ($review->pros)
                    <div class="flex gap-2.5">
                        <x-icon name="circle-plus" class="text-success shrink-0 mt-0.5" />
                        <div class="min-w-0">
                            <div class="font-semibold text-sm">{{ __('Pros') }}</div>
                            <div class="whitespace-pre-line leading-relaxed opacity-85">{{ $review->pros }}</div>
                        </div>
                    </div>
                @endif
                @if ($review->cons)
                    <div class="flex gap-2.5">
                        <x-icon name="circle-minus" class="text-error shrink-0 mt-0.5" />
                        <div class="min-w-0">
                            <div class="font-semibold text-sm">{{ __('Cons') }}</div>
                            <div class="whitespace-pre-line leading-relaxed opacity-85">{{ $review->cons }}</div>
                        </div>
                    </div>
                @endif
            </div>
        @endif

        @if ($review->body)
            <p class="whitespace-pre-line leading-relaxed max-w-[75ch]">{{ $review->body }}</p>
        @endif

        {{-- What they photographed. Drawn with the review and therefore
             governed by the review's own moderation — a pending review shows
             nobody its pictures without a line of code saying so. A click opens
             the media module's own viewer (assets/photos.mjs); the link is the
             stored file itself, so without the script it still opens. --}}
        @php $photos = \Torgash\Reviews\Photos::of($review); @endphp
        @if ($photos->isNotEmpty())
            <div class="flex flex-wrap gap-2" data-review-photos>
                @foreach ($photos as $photo)
                    @php $photoSize = \Torgash\Media\Gallery::dimensions($photo); @endphp
                    <a href="{{ $photo->getUrl() }}"
                       class="block w-24 h-24 rounded-box overflow-hidden bg-base-200 hover:opacity-90"
                       data-review-photo data-width="{{ $photoSize['width'] }}" data-height="{{ $photoSize['height'] }}"
                       target="_blank" rel="noopener">
                        <img src="{{ \Torgash\Media\Gallery::thumbUrl($photo, 160) }}"
                             alt="{{ __('Photo by :author', ['author' => $review->author_name]) }}"
                             class="w-full h-full object-cover" loading="lazy" decoding="async">
                    </a>
                @endforeach
            </div>
        @endif

        @if ($review->replies->isNotEmpty())
            {{-- The conversation under it: a rail on the left, the shop's own
                 answers marked with the shop's colour. --}}
            <div class="flex flex-col gap-3 border-s-2 border-base-300 ps-4">
                @foreach ($review->replies as $reply)
                    <div @class(['rounded-box px-4 py-3', 'bg-primary/8' => $reply->is_staff, 'bg-base-200/70' => ! $reply->is_staff])>
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm mb-1">
                            @if ($reply->is_staff)
                                <span class="inline-flex items-center gap-1 font-semibold text-primary">
                                    <x-icon name="store" size="sm" /> {{ __('Shop') }}
                                </span>
                                <span class="opacity-60">{{ $reply->author_name }}</span>
                            @else
                                <span class="font-semibold">{{ $reply->author_name }}</span>
                            @endif
                            <time class="opacity-45">{{ $reply->created_at?->format('d.m.Y') }}</time>
                        </div>
                        <div class="whitespace-pre-line leading-relaxed">{{ $reply->body }}</div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- What others made of it and the way to answer, on one line.
             Answering is open for anybody signed in — including the author of
             the review, who is usually the person the shop's answer was
             written for. A <details> rather than an open box: twenty reviews
             each showing an empty textarea is a page of empty textareas. --}}
        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
            @include('torgash.reviews::storefront.helpful', [
                'review' => $review,
                'mine' => $myVotes[$review->id] ?? null,
            ])

            @auth
                {{-- Open, the "Reply" that opened it steps aside: a button
                     still saying "Reply" above the box read as a second way to
                     answer, while what it did was close it. "Cancel" beside
                     "Send" says that, where the eye already is. --}}
                <details class="group/reply text-sm basis-full sm:basis-auto sm:order-none order-last [&[open]]:basis-full"
                         x-data x-on:toggle="$el.open && $nextTick(() => $refs.body.focus())">
                    <summary class="list-none cursor-pointer inline-flex items-center gap-1.5 btn btn-ghost btn-sm group-open/reply:hidden">
                        <x-icon name="message-square-reply" size="sm" />
                        {{ __('Reply') }}
                    </summary>

                    <form method="POST" action="{{ route('reviews.reply', $review) }}" class="flex flex-col gap-2">
                        @csrf
                        <x-form-guard form="review_reply" />
                        <textarea name="body" rows="3" required x-ref="body"
                                  class="textarea textarea-bordered w-full"
                                  placeholder="{{ __('Your answer') }}"></textarea>
                        <div class="flex items-center gap-2">
                            <button class="btn btn-sm btn-primary">{{ __('Send') }}</button>
                            <button type="button" class="btn btn-sm btn-ghost" x-on:click="$el.closest('details').open = false">{{ __('Cancel') }}</button>
                        </div>
                    </form>
                </details>
            @else
                <a href="{{ route('account.login') }}" class="btn btn-ghost btn-sm opacity-70">{{ __('Sign in to reply') }}</a>
            @endauth
        </div>
    </div>
</article>
