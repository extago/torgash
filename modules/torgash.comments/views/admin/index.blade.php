@extends('admin.layout')

@section('title', __('Comments'))
@section('title_hint', __('What visitors wrote under pages and posts. Nothing is shown on the storefront until it is published; answering a comment publishes it with the answer.'))

@section('content')
    @php
        use Torgash\Comments\Models\Comment;

        $tabs = [
            Comment::PENDING => __('Waiting'),
            Comment::APPROVED => __('Published'),
            Comment::REJECTED => __('Rejected'),
            'all' => __('All'),
        ];
        // Everything but the tab, carried by the tabs' links and by "every
        // comment found" in the bulk bar, so both mean what the screen shows.
        $filterQuery = array_filter([
            'q' => $filters['q'], 'target_type' => $filters['target_type'], 'answered' => $filters['answered'],
            'sort' => $filters['sort'] !== 'new' ? $filters['sort'] : null,
        ], fn ($v) => $v !== null && $v !== '');
        $isFiltered = $filterQuery !== [] && array_keys($filterQuery) !== ['sort'];
        $pageIds = collect($comments->items())->pluck('id')->map(fn ($id) => (string) $id);
    @endphp

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    {{-- The states as one row of tabs over the card (app.css, `admin-tabs`):
         LINKS carrying every other filter; comments waiting are amber. --}}
    <nav class="admin-tabs" aria-label="{{ __('Comments') }}">
        @foreach ($tabs as $tabKey => $tabLabel)
            <a href="{{ route('admin.comments.index', $filterQuery + ['status' => $tabKey]) }}"
               @class(['admin-tab', 'is-active' => $filters['status'] === $tabKey])>
                {{ $tabLabel }}
                <span @class(['admin-tab-count', 'is-warning' => $tabKey === Comment::PENDING && ($statusCounts[$tabKey] ?? 0) > 0])>{{ $statusCounts[$tabKey] ?? 0 }}</span>
            </a>
        @endforeach
    </nav>

    <div class="card bg-base-100 border border-base-300"
         x-data="{ selected: [], found: false, pageIds: @js($pageIds), open: {} }"
         x-init="$watch('selected', v => { if (v.length < pageIds.length) found = false })">
        <div class="card-body gap-4">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <input type="hidden" name="status" value="{{ $filters['status'] }}">
                <label class="input input-sm grow min-w-48 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Text, name or email…') }}">
                </label>
                @if (count($targets) > 1)
                    <select name="target_type" class="select select-sm w-auto" data-submit-on-change aria-label="{{ __('Under what') }}">
                        <option value="">{{ __('Under anything') }}</option>
                        @foreach ($targets as $type => $definition)
                            <option value="{{ $type }}" @selected($filters['target_type'] === $type)>{{ label_text($definition['label'] ?? null, null, $type) }}</option>
                        @endforeach
                    </select>
                @endif
                <select name="answered" class="select select-sm w-auto" data-submit-on-change aria-label="{{ __('Answer') }}">
                    <option value="">{{ __('Answered or not') }}</option>
                    <option value="no" @selected($filters['answered'] === 'no')>{{ __('Not answered by the shop') }}</option>
                    <option value="yes" @selected($filters['answered'] === 'yes')>{{ __('Answered by the shop') }}</option>
                </select>
                <select name="sort" class="select select-sm w-auto" data-submit-on-change aria-label="{{ __('Sort') }}">
                    <option value="new" @selected($filters['sort'] === 'new')>{{ __('Newest first') }}</option>
                    <option value="old" @selected($filters['sort'] === 'old')>{{ __('Oldest first') }}</option>
                </select>
                @if ($isFiltered)
                    <a href="{{ route('admin.comments.index', ['status' => $filters['status']]) }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Tick comments to publish, reject or delete several at once — or every one the filters found.') }}
                </p>
                <form method="POST" action="{{ route('admin.comments.bulk') }}"
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
                    @if ($foundCount > $comments->count())
                        <button type="button" class="btn btn-ghost btn-sm" x-show="!found && selected.length === pageIds.length" @click="found = true">
                            {{ __('Select all :n found', ['n' => $foundCount]) }}
                        </button>
                    @endif
                    <div class="join">
                        <button name="action" value="{{ Comment::APPROVED }}" class="btn btn-sm btn-success join-item">{{ __('Publish') }}</button>
                        <button name="action" value="{{ Comment::REJECTED }}" class="btn btn-sm join-item">{{ __('Reject') }}</button>
                    </div>
                    <button name="action" value="delete" class="btn btn-sm btn-error btn-outline gap-1.5"
                            data-confirm="{{ __('Delete the selected comments with their answers for good? Rejecting keeps the record.') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Delete') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" @click="selected = []; found = false">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($comments->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="message-circle" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $filters['status'] === Comment::PENDING && ! $isFiltered ? __('Nothing is waiting — every comment has been dealt with.') : __('No comments match this search.') }}</p>
                </div>
            @else
                <div class="flex items-center gap-3 px-2 text-sm opacity-70">
                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                           @change="selected = $event.target.checked ? [...pageIds] : []" :checked="selected.length === pageIds.length">
                    <span>{{ __('Select all on this page') }}</span>
                </div>

                <div class="flex flex-col divide-y divide-base-300 border-y border-base-300 -mt-2">
                    @foreach ($comments as $comment)
                        @php
                            $target = $described[$comment->target_type.':'.$comment->target_id] ?? ['title' => '', 'url' => null];
                            $shopReplied = $comment->replies->contains('is_staff', true);
                        @endphp
                        <div class="flex gap-3 py-3 px-2 hover:bg-base-200/40" :class="selected.includes('{{ $comment->id }}') && 'bg-primary/5'">
                            <input type="checkbox" class="checkbox checkbox-sm mt-1" value="{{ $comment->id }}" x-model="selected" aria-label="#{{ $comment->id }}">

                            <div class="flex-1 min-w-0 flex flex-col gap-1.5">
                                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <span class="font-medium">{{ $comment->author_name }}</span>
                                    @if ($comment->author_email)
                                        <a href="mailto:{{ $comment->author_email }}" class="text-xs opacity-60 link link-hover">{{ $comment->author_email }}</a>
                                    @endif
                                    <span class="text-xs opacity-50">{{ $comment->created_at?->format('d.m.Y H:i') }}</span>
                                    @if ($shopReplied)
                                        <span class="badge badge-sm badge-primary gap-1"><x-icon name="message-square-reply" size="sm" /> {{ __('Answered by the shop') }}</span>
                                    @endif
                                </div>

                                <div class="flex items-center gap-2 text-sm">
                                    <x-icon name="file-text" size="sm" class="opacity-50" />
                                    @if ($target['url'])
                                        <a href="{{ $target['url'] }}" target="_blank" rel="noopener" class="link link-hover truncate">{{ $target['title'] }}</a>
                                    @else
                                        <span class="truncate opacity-70">{{ $target['title'] }}</span>
                                    @endif
                                </div>

                                {{-- Three lines, with the rest one click away. --}}
                                <p class="text-sm whitespace-pre-line" :class="open[{{ $comment->id }}] ? '' : 'line-clamp-3'">{{ $comment->body }}</p>
                                @if (mb_strlen($comment->body) > 240)
                                    <button type="button" class="link link-hover text-xs opacity-60 w-fit" @click="open[{{ $comment->id }}] = ! open[{{ $comment->id }}]"
                                            x-text="open[{{ $comment->id }}] ? @js(__('less')) : @js(__('more'))"></button>
                                @endif

                                @foreach ($comment->replies as $reply)
                                    <div class="ms-2 ps-3 border-s-2 border-primary/30 text-sm">
                                        @if ($reply->is_staff)
                                            <span class="badge badge-primary badge-sm me-1">{{ __('Shop') }}</span>
                                        @else
                                            <span class="font-medium me-1">{{ $reply->author_name }}</span>
                                        @endif
                                        <span class="whitespace-pre-line">{{ $reply->body }}</span>
                                    </div>
                                @endforeach

                                <div x-data="{ replying: false }">
                                    <form method="POST" action="{{ route('admin.comments.reply', $comment) }}" x-show="replying" x-cloak class="flex flex-col gap-2 mt-1">
                                        @csrf
                                        <textarea name="body" rows="2" required maxlength="5000" class="textarea textarea-bordered w-full"
                                                  placeholder="{{ __('Answer publicly — it is shown under the comment') }}"></textarea>
                                        <div class="flex gap-2">
                                            <button class="btn btn-sm btn-primary">{{ __('Publish the answer') }}</button>
                                            <button type="button" class="btn btn-sm btn-ghost" @click="replying = false">{{ __('Cancel') }}</button>
                                        </div>
                                        <p class="text-xs opacity-60">{{ __('Answering publishes the comment too — an answer to a comment nobody can read makes no sense.') }}</p>
                                    </form>
                                    <button type="button" class="btn btn-ghost btn-sm gap-1 -ms-2" x-show="!replying" @click="replying = true">
                                        <x-icon name="reply" size="sm" /> {{ $shopReplied ? __('Answer again') : __('Answer') }}
                                    </button>
                                </div>
                            </div>

                            {{-- One row, level with the author: the status, then the
                                 view on the storefront and — set apart — delete. --}}
                            <div class="flex items-center gap-1 shrink-0 self-start">
                                @include('torgash.comments::admin._status', ['comment' => $comment])
                                @if ($target['url'] && $comment->status === Comment::APPROVED)
                                    <a href="{{ $target['url'] }}" target="_blank" rel="noopener"
                                       class="btn btn-ghost btn-sm btn-square ms-2" title="{{ __('Open on the storefront') }}" aria-label="{{ __('Open on the storefront') }}"><x-icon name="external-link" /></a>
                                @else
                                    <span class="btn btn-ghost btn-sm btn-square ms-2 invisible" aria-hidden="true"></span>
                                @endif
                                <span class="w-px h-5 shrink-0 bg-base-300 mx-1" aria-hidden="true"></span>
                                <form method="POST" action="{{ route('admin.comments.destroy', $comment) }}" class="inline"
                                      data-confirm="{{ __('Delete this comment and its replies?') }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"><x-icon name="trash-2" /></button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{ $comments->links() }}
            @endif
        </div>
    </div>
@endsection
