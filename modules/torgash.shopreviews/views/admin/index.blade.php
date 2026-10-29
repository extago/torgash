@extends('admin.layout')

@section('title', __('Shop reviews'))
@section('title_hint', __('What customers say about the shop itself. Only published reviews are on the page and count towards the shop\'s rating.'))

@section('content')
    @php
        use Torgash\ShopReviews\Models\ShopReview;

        $tabs = [
            ShopReview::PENDING => __('Waiting'),
            ShopReview::PUBLISHED => __('Published'),
            ShopReview::REJECTED => __('Rejected'),
            'all' => __('All'),
        ];
        $filterQuery = array_filter([
            'q' => $filters['q'], 'rating' => $filters['rating'], 'answered' => $filters['answered'],
            'sort' => $filters['sort'] !== 'new' ? $filters['sort'] : null,
        ], fn ($v) => $v !== null && $v !== '');
        $isFiltered = $filterQuery !== [] && array_keys($filterQuery) !== ['sort'];
        $pageIds = collect($reviews->items())->pluck('id')->map(fn ($id) => (string) $id);
        $signature = trim((string) setting('torgash.shopreviews', 'reply_signature', '')) ?: auth()->user()?->name;
    @endphp

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    @include('torgash.shopreviews::admin._nav')

    {{-- The states as one row of tabs over the card, the screen's actions at
         its end (app.css, `admin-tabs`): LINKS carrying every other filter;
         reviews waiting are amber. --}}
    <div class="admin-tabs-row">
        <nav class="admin-tabs" aria-label="{{ __('Shop reviews') }}">
            @foreach ($tabs as $tabKey => $tabLabel)
                <a href="{{ route('admin.shopreviews.index', $filterQuery + ['status' => $tabKey]) }}"
                   @class(['admin-tab', 'is-active' => $filters['status'] === $tabKey])>
                    {{ $tabLabel }}
                    <span @class(['admin-tab-count', 'is-warning' => $tabKey === ShopReview::PENDING && ($statusCounts[$tabKey] ?? 0) > 0])>{{ $statusCounts[$tabKey] ?? 0 }}</span>
                </a>
            @endforeach
        </nav>
        <div class="admin-tabs-actions">
            <a href="{{ route('shopreviews.index') }}" target="_blank" rel="noopener" class="btn btn-ghost btn-square"
               title="{{ __('Open on the storefront') }}" aria-label="{{ __('Open on the storefront') }}"><x-icon name="external-link" /></a>
            <a href="{{ route('admin.shopreviews.create') }}" class="btn btn-primary">+ {{ __('Add a review') }}</a>
        </div>
    </div>

    <div class="card bg-base-100 border border-base-300"
         x-data="{ selected: [], found: false, pageIds: @js($pageIds), open: {} }"
         x-init="$watch('selected', v => { if (v.length < pageIds.length) found = false })">
        <div class="card-body gap-4">
            @if ($summary['count'] > 0)
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                    <span class="inline-flex items-center gap-1.5">
                        @include('torgash.shopreviews::storefront._stars', ['srRating' => $summary['rating'], 'srSize' => 'sm'])
                        <b>{{ number_format($summary['rating'], 1) }}</b>
                        <span class="opacity-60">{{ trans_choice('{1}:count review|[2,*]:count reviews', $summary['count'], ['count' => $summary['count']]) }}</span>
                    </span>
                    @if ($summary['recommend'] !== null)
                        <span class="opacity-70">{{ __(':share% recommend the shop', ['share' => $summary['recommend']]) }}</span>
                    @endif
                </div>
            @endif

            <form method="GET" class="flex flex-wrap items-center gap-2">
                <input type="hidden" name="status" value="{{ $filters['status'] }}">
                <label class="input input-sm grow min-w-48 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Text, author, email or town…') }}">
                </label>
                <select name="rating" class="select select-sm w-auto" data-submit-on-change aria-label="{{ __('Rating') }}">
                    <option value="">{{ __('Any rating') }}</option>
                    <option value="low" @selected($filters['rating'] === 'low')>{{ __('Poor (1–2)') }}</option>
                    <option value="high" @selected($filters['rating'] === 'high')>{{ __('Good (4–5)') }}</option>
                </select>
                <select name="answered" class="select select-sm w-auto" data-submit-on-change aria-label="{{ __('Answer') }}">
                    <option value="">{{ __('Answered or not') }}</option>
                    <option value="no" @selected($filters['answered'] === 'no')>{{ __('Not answered by the shop') }}</option>
                    <option value="yes" @selected($filters['answered'] === 'yes')>{{ __('Answered by the shop') }}</option>
                </select>
                <select name="sort" class="select select-sm w-auto" data-submit-on-change aria-label="{{ __('Sort') }}">
                    @foreach (['new' => __('Newest first'), 'low' => __('Worst first'), 'high' => __('Best first')] as $sortKey => $sortLabel)
                        <option value="{{ $sortKey }}" @selected($filters['sort'] === $sortKey)>{{ $sortLabel }}</option>
                    @endforeach
                </select>
                @if ($isFiltered)
                    <a href="{{ route('admin.shopreviews.index', ['status' => $filters['status']]) }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Tick reviews to publish, reject or delete several at once — or every one the filters found.') }}
                </p>
                <form method="POST" action="{{ route('admin.shopreviews.bulk') }}"
                      x-show="selected.length" x-cloak
                      class="w-full flex flex-wrap items-center gap-2 bg-base-200 rounded-box p-2">
                    @csrf
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
                        <button name="action" value="{{ ShopReview::PUBLISHED }}" class="btn btn-sm btn-success join-item">{{ __('Publish') }}</button>
                        <button name="action" value="{{ ShopReview::REJECTED }}" class="btn btn-sm join-item">{{ __('Reject') }}</button>
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
                    <x-icon name="message-square-heart" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $filters['status'] === ShopReview::PENDING && ! $isFiltered ? __('Nothing is waiting — every review has been dealt with.') : __('No reviews match this search.') }}</p>
                </div>
            @else
                <div class="flex items-center gap-3 px-2 text-sm opacity-70">
                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                           @change="selected = $event.target.checked ? [...pageIds] : []" :checked="selected.length === pageIds.length">
                    <span>{{ __('Select all on this page') }}</span>
                </div>

                <div class="flex flex-col divide-y divide-base-300 border-y border-base-300 -mt-2">
                    @foreach ($reviews as $review)
                        <div class="flex gap-3 py-3 px-2 hover:bg-base-200/40" :class="selected.includes('{{ $review->id }}') && 'bg-primary/5'">
                            <input type="checkbox" class="checkbox checkbox-sm mt-1" value="{{ $review->id }}" x-model="selected" aria-label="#{{ $review->id }}">

                            <div class="flex-1 min-w-0 flex flex-col gap-1.5">
                                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <span @class(['inline-flex items-center gap-1 font-semibold', 'text-error' => $review->rating < 3])>
                                        @include('torgash.shopreviews::storefront._stars', ['srRating' => $review->rating, 'srSize' => 'sm'])
                                        {{ number_format((float) $review->rating, 1) }}
                                    </span>
                                    <span class="font-medium">{{ $review->author_name }}@if ($review->city)<span class="font-normal opacity-60">, {{ $review->city }}</span>@endif</span>
                                    @if ($review->email)
                                        <a href="mailto:{{ $review->email }}" class="text-xs link link-hover opacity-60">{{ $review->email }}</a>
                                    @endif
                                    @if ($review->verified)
                                        <span class="badge badge-success badge-sm gap-1"><x-icon name="badge-check" size="sm" /> {{ __('Customer of the shop') }}</span>
                                    @endif
                                    @if ($review->source)
                                        <span class="badge badge-sm badge-ghost">{{ __('From :source', ['source' => $review->source]) }}</span>
                                    @endif
                                    <span class="text-xs opacity-50">{{ $review->created_at?->format('d.m.Y H:i') }}</span>
                                </div>

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

                                @if ($review->ratings->isNotEmpty() || $review->recommend !== null || $review->will_return !== null)
                                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs opacity-60">
                                        @foreach ($review->ratings as $score)
                                            <span>{{ $score->criterion?->label() ?? __('(deleted criterion)') }}: <b>{{ $score->value }}</b></span>
                                        @endforeach
                                        @if ($review->recommend !== null)
                                            <span>{{ __('Recommends') }}: <b>{{ $review->recommend ? __('Yes') : __('No') }}</b></span>
                                        @endif
                                        @if ($review->will_return !== null)
                                            <span>{{ __('Will buy again') }}: <b>{{ $review->will_return ? __('Yes') : __('No') }}</b></span>
                                        @endif
                                    </div>
                                @endif

                                @if ($review->reply)
                                    <div class="ms-2 ps-3 border-s-2 border-primary/30 text-sm">
                                        <span class="badge badge-primary badge-sm me-1">{{ $review->reply_author ?: __('Shop') }}</span>
                                        <span class="whitespace-pre-line">{{ $review->reply }}</span>
                                    </div>
                                @endif

                                <div x-data="{ replying: false }">
                                    <form method="POST" action="{{ route('admin.shopreviews.reply', $review) }}" x-show="replying" x-cloak class="flex flex-col gap-2 mt-1">
                                        @csrf
                                        <textarea name="reply" rows="3" maxlength="5000" class="textarea textarea-bordered w-full"
                                                  placeholder="{{ __('Answer publicly — it is shown under the review') }}">{{ $review->reply }}</textarea>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <label class="input input-sm w-64 gap-2" title="{{ __('Signed as') }}">
                                                <x-icon name="pen-line" size="sm" class="opacity-50" />
                                                <input name="reply_author" value="{{ $review->reply_author ?: $signature }}" maxlength="120" class="grow" aria-label="{{ __('Signed as') }}">
                                            </label>
                                            <button class="btn btn-sm btn-primary">{{ __('Publish the answer') }}</button>
                                            <button type="button" class="btn btn-sm btn-ghost" @click="replying = false">{{ __('Cancel') }}</button>
                                        </div>
                                        <p class="text-xs opacity-60">
                                            {{ __('Answering publishes the review too — an answer to a review nobody can read makes no sense.') }}
                                            @if ($review->reply)
                                                {{ __('Empty the text to take the answer away.') }}
                                            @endif
                                        </p>
                                    </form>
                                    <button type="button" class="btn btn-ghost btn-sm gap-1 -ms-2" x-show="!replying" @click="replying = true">
                                        <x-icon name="reply" size="sm" /> {{ $review->reply ? __('Edit the answer') : __('Answer') }}
                                    </button>
                                </div>
                            </div>

                            <div class="flex items-center gap-1 shrink-0 self-start">
                                @include('torgash.shopreviews::admin._status', ['review' => $review])
                                @if ($review->status === ShopReview::PUBLISHED)
                                    <a href="{{ route('shopreviews.index') }}#review-{{ $review->id }}" target="_blank" rel="noopener"
                                       class="btn btn-ghost btn-sm btn-square ms-2" title="{{ __('Open on the storefront') }}" aria-label="{{ __('Open on the storefront') }}"><x-icon name="external-link" /></a>
                                @else
                                    <span class="btn btn-ghost btn-sm btn-square ms-2 invisible" aria-hidden="true"></span>
                                @endif
                                <span class="w-px h-5 shrink-0 bg-base-300 mx-1" aria-hidden="true"></span>
                                <form method="POST" action="{{ route('admin.shopreviews.destroy', $review) }}" class="inline"
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
