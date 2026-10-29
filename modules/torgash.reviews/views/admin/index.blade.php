@extends('admin.layout')

@section('title', __('Reviews'))
@section('title_hint', __('What customers wrote about the products. Nothing is shown on the storefront until it is published; the product\'s average counts published reviews only.'))

@section('content')
    @php
        use Torgash\Reviews\Models\Review;
        use Torgash\Reviews\Photos;

        $tabs = [
            Review::PENDING => __('Waiting'),
            Review::PUBLISHED => __('Published'),
            Review::REJECTED => __('Rejected'),
            'all' => __('All'),
        ];
        // Everything but the tab, carried by the tabs' links and by "every
        // review found" in the bulk bar, so both mean what the screen shows.
        $filterQuery = array_filter([
            'q' => $filters['q'], 'rating' => $filters['rating'], 'answered' => $filters['answered'],
            'verified' => $filters['verified'] ? 1 : null, 'photos' => $filters['photos'] ? 1 : null,
            'product' => $filters['product'], 'sort' => $filters['sort'] !== 'new' ? $filters['sort'] : null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== false);
        $isFiltered = $filterQuery !== [] && array_keys($filterQuery) !== ['sort'];
        $pageIds = collect($reviews->items())->pluck('id')->map(fn ($id) => (string) $id);
    @endphp

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    {{-- The states as one row of tabs over the card, the screen's actions at
         its end (app.css, `admin-tabs`): LINKS carrying every other filter;
         reviews waiting are amber. --}}
    <div class="admin-tabs-row">
        <nav class="admin-tabs" aria-label="{{ __('Reviews') }}">
            @foreach ($tabs as $tabKey => $tabLabel)
                <a href="{{ route('admin.reviews.index', $filterQuery + ['status' => $tabKey]) }}"
                   @class(['admin-tab', 'is-active' => $filters['status'] === $tabKey])>
                    {{ $tabLabel }}
                    <span @class(['admin-tab-count', 'is-warning' => $tabKey === Review::PENDING && ($statusCounts[$tabKey] ?? 0) > 0])>{{ $statusCounts[$tabKey] ?? 0 }}</span>
                </a>
            @endforeach
        </nav>
        <div class="admin-tabs-actions">
            <a href="{{ route('admin.reviews.criteria.index') }}" class="btn btn-ghost gap-1.5"><x-icon name="list-checks" size="sm" /> {{ __('Rating criteria') }}</a>
            {{-- The way to put right what `reviews.aggregate_matches_reviews`
                 reports: the average is a denormalised row per product. --}}
            <form method="POST" action="{{ route('admin.reviews.recount') }}">
                @csrf
                <button class="btn btn-ghost gap-1.5" title="{{ __('Recomputes every product\'s average and review count from its published reviews.') }}">
                    <x-icon name="calculator" size="sm" /> {{ __('Recount the averages') }}
                </button>
            </form>
        </div>
    </div>

    <div class="card bg-base-100 border border-base-300"
         x-data="{ selected: [], found: false, pageIds: @js($pageIds), open: {} }"
         x-init="$watch('selected', v => { if (v.length < pageIds.length) found = false })">
        <div class="card-body gap-4">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <input type="hidden" name="status" value="{{ $filters['status'] }}">
                @if ($filters['product'])
                    <input type="hidden" name="product" value="{{ $filters['product'] }}">
                @endif
                <label class="input input-sm grow min-w-48 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Text, author, product or SKU…') }}">
                </label>
                <select name="rating" class="select select-sm w-auto" data-submit-on-change aria-label="{{ __('Rating') }}">
                    <option value="">{{ __('Any rating') }}</option>
                    <option value="low" @selected($filters['rating'] === 'low')>{{ __('Poor (1–2)') }}</option>
                    <option value="high" @selected($filters['rating'] === 'high')>{{ __('Good (4–5)') }}</option>
                    @foreach ([5, 4, 3, 2, 1] as $stars)
                        <option value="{{ $stars }}" @selected($filters['rating'] === (string) $stars)>{{ str_repeat('★', $stars) }}</option>
                    @endforeach
                </select>
                <select name="answered" class="select select-sm w-auto" data-submit-on-change aria-label="{{ __('Answer') }}">
                    <option value="">{{ __('Answered or not') }}</option>
                    <option value="no" @selected($filters['answered'] === 'no')>{{ __('Not answered by the shop') }}</option>
                    <option value="yes" @selected($filters['answered'] === 'yes')>{{ __('Answered by the shop') }}</option>
                </select>
                <select name="sort" class="select select-sm w-auto" data-submit-on-change aria-label="{{ __('Sort') }}">
                    @foreach (['new' => __('Newest first'), 'old' => __('Oldest first'), 'low' => __('Worst first'), 'high' => __('Best first'), 'helpful' => __('Most helpful first')] as $sortKey => $sortLabel)
                        <option value="{{ $sortKey }}" @selected($filters['sort'] === $sortKey)>{{ $sortLabel }}</option>
                    @endforeach
                </select>
                <label class="label cursor-pointer gap-1.5 text-sm">
                    <input type="checkbox" name="verified" value="1" class="checkbox checkbox-sm" data-submit-on-change @checked($filters['verified'])>
                    {{ __('Verified purchase') }}
                </label>
                <label class="label cursor-pointer gap-1.5 text-sm">
                    <input type="checkbox" name="photos" value="1" class="checkbox checkbox-sm" data-submit-on-change @checked($filters['photos'])>
                    {{ __('With photos') }}
                </label>
                @if ($isFiltered)
                    <a href="{{ route('admin.reviews.index', ['status' => $filters['status']]) }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            @if ($product)
                <div class="flex items-center gap-2 text-sm -mt-1">
                    <span class="opacity-60">{{ __('Product') }}:</span>
                    <span class="badge badge-lg gap-1.5">
                        {{ $product->t('name', admin_content_locale()) ?: $product->sku }}
                        <a href="{{ request()->fullUrlWithQuery(['product' => null, 'page' => null]) }}" class="opacity-60 hover:opacity-100" title="{{ __('All products') }}" aria-label="{{ __('All products') }}"><x-icon name="x" size="sm" /></a>
                    </span>
                </div>
            @endif

            <div class="min-h-12 max-md:min-h-0 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Tick reviews to publish, reject or delete several at once — or every one the filters found.') }}
                </p>
                <form method="POST" action="{{ route('admin.reviews.bulk') }}"
                      x-show="selected.length" x-cloak
                      class="admin-bulk-bar w-full flex flex-wrap items-center gap-2 bg-base-200 rounded-box p-2">
                    @csrf
                    {{-- The ticked ids, or — "every review found" — the filters
                         themselves, read again by the same query the list used. --}}
                    <template x-if="!found">
                        <div class="contents">
                            <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
                        </div>
                    </template>
                    <template x-if="found">
                        <div class="contents">
                            <input type="hidden" name="scope" value="found">
                            <input type="hidden" name="status" value="{{ $filters['status'] }}">
                            @foreach ($filterQuery as $filterKey => $filterValue)
                                <input type="hidden" name="{{ $filterKey }}" value="{{ $filterValue }}">
                            @endforeach
                        </div>
                    </template>
                    <span class="text-sm px-1">
                        <span x-show="!found"><span x-text="selected.length"></span> {{ __('selected') }}</span>
                        <span x-show="found" class="font-medium">{{ __('All :n found are selected', ['n' => $foundCount]) }}</span>
                    </span>
                    @if ($foundCount > $reviews->count())
                        <button type="button" class="btn btn-ghost btn-sm" x-show="!found && selected.length === pageIds.length" @click="found = true">
                            {{ __('Select all :n found', ['n' => $foundCount]) }}
                        </button>
                    @endif
                    <div class="join">
                        <button name="action" value="{{ Review::PUBLISHED }}" class="btn btn-sm btn-success join-item">{{ __('Publish') }}</button>
                        <button name="action" value="{{ Review::REJECTED }}" class="btn btn-sm join-item">{{ __('Reject') }}</button>
                    </div>
                    <button name="action" value="delete" class="btn btn-sm btn-error btn-outline gap-1.5"
                            data-confirm="{{ __('Delete the selected reviews for good? Rejecting keeps the record; deleting is for what should never have been stored.') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Delete') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" @click="selected = []; found = false">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($reviews->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="message-square-quote" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $filters['status'] === Review::PENDING && ! $isFiltered ? __('Nothing is waiting — every review has been dealt with.') : __('No reviews match this search.') }}</p>
                </div>
            @else
                <div class="flex items-center gap-3 px-2 text-sm opacity-70">
                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                           @change="selected = $event.target.checked ? [...pageIds] : []" :checked="selected.length === pageIds.length">
                    <span>{{ __('Select all on this page') }}</span>
                </div>

                <div class="flex flex-col divide-y divide-base-300 border-y border-base-300 -mt-2">
                    @foreach ($reviews as $review)
                        @php
                            $photoCount = Photos::of($review)->count();
                            $shopReplied = $review->replies->contains('is_staff', true);
                            $reviewProduct = $review->product;
                        @endphp
                        {{-- On a phone the buttons go under the text, not beside it. --}}
                        <div class="flex gap-3 py-3 px-2 hover:bg-base-200/40 max-md:flex-wrap" :class="selected.includes('{{ $review->id }}') && 'bg-primary/5'">
                            <input type="checkbox" class="checkbox checkbox-sm mt-1" value="{{ $review->id }}" x-model="selected" aria-label="#{{ $review->id }}">

                            <div class="flex-1 min-w-0 flex flex-col gap-1.5">
                                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <span @class(['inline-flex items-center gap-1 font-semibold', 'text-error' => $review->rating < 3])>
                                        <span class="inline-flex text-warning" aria-hidden="true">
                                            @for ($i = 1; $i <= 5; $i++)
                                                <x-icon name="star" size="sm" class="{{ $i <= $review->stars() ? 'fill-current' : 'opacity-25' }}" />
                                            @endfor
                                        </span>
                                        {{ number_format((float) $review->rating, 1) }}
                                    </span>
                                    <span class="font-medium">{{ $review->author_name ?: __('Anonymous') }}</span>
                                    @if ($review->verified)
                                        <span class="badge badge-success badge-sm gap-1"><x-icon name="badge-check" size="sm" /> {{ __('Verified purchase') }}</span>
                                    @endif
                                    <span class="text-xs opacity-50">{{ $review->created_at?->format('d.m.Y H:i') }}</span>
                                    @if ($shopReplied)
                                        <span class="badge badge-sm badge-primary gap-1"><x-icon name="message-square-reply" size="sm" /> {{ __('Answered by the shop') }}</span>
                                    @endif
                                    @if ($review->helpful_yes)
                                        <span class="text-xs opacity-60 inline-flex items-center gap-1" title="{{ __('Found helpful') }}"><x-icon name="thumbs-up" size="sm" /> {{ $review->helpful_yes }}</span>
                                    @endif
                                </div>

                                @if ($reviewProduct)
                                    <div class="flex items-center gap-2 text-sm">
                                        <x-icon name="package" size="sm" class="opacity-50" />
                                        <a href="{{ route('admin.catalog.products.edit', $reviewProduct) }}" class="link link-hover truncate">
                                            {{ $reviewProduct->t('name', admin_content_locale()) ?: $reviewProduct->sku }}
                                        </a>
                                        @unless ($filters['product'])
                                            <a href="{{ request()->fullUrlWithQuery(['product' => $reviewProduct->id, 'page' => null]) }}"
                                               class="btn btn-ghost btn-sm" title="{{ __('Only this product\'s reviews') }}" aria-label="{{ __('Only this product\'s reviews') }}"><x-icon name="filter" size="sm" /></a>
                                        @endunless
                                    </div>
                                @endif

                                {{-- Three lines, with the rest one click away: a list of
                                     a thousand is read by its first lines. --}}
                                <div class="text-sm space-y-1" :class="open[{{ $review->id }}] ? '' : 'line-clamp-3'">
                                    @if ($review->pros)
                                        <div><span class="text-success font-medium">+</span> {{ $review->pros }}</div>
                                    @endif
                                    @if ($review->cons)
                                        <div><span class="text-error font-medium">−</span> {{ $review->cons }}</div>
                                    @endif
                                    @if ($review->body)
                                        <p class="whitespace-pre-line">{{ $review->body }}</p>
                                    @endif
                                </div>
                                @if (mb_strlen($review->pros.$review->cons.$review->body) > 240)
                                    <button type="button" class="link link-hover text-xs opacity-60 w-fit" @click="open[{{ $review->id }}] = ! open[{{ $review->id }}]"
                                            x-text="open[{{ $review->id }}] ? @js(__('less')) : @js(__('more'))"></button>
                                @endif

                                @if ($review->ratings->isNotEmpty())
                                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs opacity-60">
                                        @foreach ($review->ratings as $score)
                                            <span>{{ $score->criterion?->label() ?? __('(deleted criterion)') }}: <b>{{ $score->value }}</b></span>
                                        @endforeach
                                    </div>
                                @endif

                                @if ($photoCount)
                                    <div class="flex gap-1.5">
                                        @foreach (Photos::of($review)->take(6) as $photo)
                                            <a href="{{ $photo->getUrl() }}" target="_blank" rel="noopener" class="h-12 w-12 rounded bg-base-200 overflow-hidden">
                                                <img src="{{ \Torgash\Media\Gallery::thumbUrl($photo, 120) }}" alt="" class="h-full w-full object-cover" loading="lazy">
                                            </a>
                                        @endforeach
                                    </div>
                                @endif

                                @foreach ($review->replies as $reply)
                                    <div class="ms-2 ps-3 border-s-2 border-primary/30 text-sm">
                                        <span class="badge badge-primary badge-sm me-1">{{ __('Shop') }}</span>
                                        <span class="whitespace-pre-line">{{ $reply->body }}</span>
                                    </div>
                                @endforeach

                                <div x-data="{ replying: false }">
                                    <form method="POST" action="{{ route('admin.reviews.reply', $review) }}" x-show="replying" x-cloak class="flex flex-col gap-2 mt-1">
                                        @csrf
                                        <textarea name="body" rows="2" required maxlength="5000" class="textarea textarea-bordered w-full"
                                                  placeholder="{{ __('Answer publicly — it is shown under the review') }}"></textarea>
                                        <div class="flex gap-2">
                                            <button class="btn btn-sm btn-primary">{{ __('Publish the answer') }}</button>
                                            <button type="button" class="btn btn-sm btn-ghost" @click="replying = false">{{ __('Cancel') }}</button>
                                        </div>
                                        <p class="text-xs opacity-60">{{ __('Answering publishes the review too — an answer to a review nobody can read makes no sense.') }}</p>
                                    </form>
                                    <button type="button" class="btn btn-ghost btn-sm gap-1 -ms-2" x-show="!replying" @click="replying = true">
                                        <x-icon name="reply" size="sm" /> {{ $shopReplied ? __('Answer again') : __('Answer') }}
                                    </button>
                                </div>
                            </div>

                            {{-- One row, level with the author: the status, then the
                                 view on the storefront and — set apart — delete. --}}
                            <div class="flex items-center gap-1 shrink-0 self-start max-md:w-full max-md:flex-wrap max-md:justify-end">
                                @include('torgash.reviews::admin._status', ['review' => $review])
                                @if ($reviewProduct && $review->status === Review::PUBLISHED)
                                    <a href="{{ route('catalog.product', $reviewProduct->slug) }}#reviews" target="_blank" rel="noopener"
                                       class="btn btn-ghost btn-sm btn-square ms-2" title="{{ __('Open on the storefront') }}" aria-label="{{ __('Open on the storefront') }}"><x-icon name="external-link" /></a>
                                @else
                                    <span class="btn btn-ghost btn-sm btn-square ms-2 invisible" aria-hidden="true"></span>
                                @endif
                                <span class="w-px h-5 shrink-0 bg-base-300 mx-1" aria-hidden="true"></span>
                                <form method="POST" action="{{ route('admin.reviews.destroy', $review) }}" class="inline"
                                      data-confirm="{{ __('Delete this review for good?') }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"><x-icon name="trash-2" /></button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{ $reviews->links() }}
            @endif
        </div>
    </div>
@endsection
