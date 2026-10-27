{{--
    The customer's own enquiries.

    Filtered by user_id in the controller and never by email — a guest enquiry
    left under the same address as an account registered afterwards is not this
    person's to read (§29.3/1).
--}}
@extends('layouts.storefront')

@section('title', __('Enquiries').' · '.current_storefront()?->name)

@section('content')
    <div class="flex flex-col md:flex-row gap-6">
        @include('account._nav')

        <div class="flex-1 min-w-0">
            <h1 class="text-xl font-bold mb-4">{{ __('Enquiries') }}</h1>

            @if (session('status'))
                <div class="alert alert-success mb-4">{{ session('status') }}</div>
            @endif

            @if ($enquiries->isEmpty())
                <div class="card bg-base-100 shadow-sm border border-base-300/60">
                    <div class="card-body text-center opacity-60 py-10">
                        {{ __('You have not asked us anything yet.') }}
                    </div>
                </div>
            @else
                {{-- Not a table: on a phone four columns squeezed the kind into
                     a word per line. Each enquiry is ONE link — what it is, what
                     it is about, the last thing said, where it stands — a card
                     on a phone, a row of one panel on a computer. --}}
                <div class="flex flex-col gap-3 md:gap-0 md:bg-base-100 md:rounded-box md:border md:border-base-300/60 md:shadow-sm md:divide-y md:divide-base-300/60">
                    @foreach ($enquiries as $enquiry)
                        @php
                            $subject = $enquiry->subject();
                            $enquiryUnread = $enquiry->hasUnreadReply();
                            $enquiryPreview = $enquiry->preview(160);
                        @endphp
                        <a href="{{ route('account.enquiries.show', $enquiry) }}"
                           class="group flex items-start gap-3 sm:gap-4 p-4 md:px-5 bg-base-100 rounded-box border border-base-300/60 shadow-sm md:rounded-none md:border-0 md:shadow-none md:bg-transparent hover:bg-base-200/60 transition-colors">
                            <span @class(['w-10 h-10 rounded-full grid place-items-center shrink-0', 'bg-primary/10 text-primary' => $enquiryUnread, 'bg-base-200' => ! $enquiryUnread])>
                                <x-icon :name="$enquiryUnread ? 'message-circle-more' : 'message-circle'" size="sm" />
                            </span>
                            <div class="flex-1 min-w-0">
                                <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                                    <span @class(['font-semibold' => $enquiryUnread, 'font-medium' => ! $enquiryUnread])>{{ $enquiry->kindLabel() }}</span>
                                    <time class="text-xs opacity-60 whitespace-nowrap" datetime="{{ $enquiry->created_at?->toIso8601String() }}">{{ $enquiry->created_at?->format('d.m.Y') }}</time>
                                </div>
                                @if ($subject)
                                    <div class="text-sm opacity-70 truncate">{{ $subject['label'] }}</div>
                                @endif
                                @if ($enquiryPreview !== '')
                                    <p class="text-sm opacity-60 mt-1 line-clamp-2 break-words">{{ $enquiryPreview }}</p>
                                @endif
                                <div class="flex flex-wrap items-center gap-1.5 mt-2">
                                    <span class="badge badge-sm {{ $enquiry->customerBadge() }}">{{ $enquiry->customerLabel() }}</span>
                                    @if ($enquiryUnread)
                                        <span class="badge badge-sm badge-primary">{{ __('New answer') }}</span>
                                    @endif
                                </div>
                            </div>
                            <x-icon name="chevron-right" size="sm" class="hidden md:block self-center opacity-30 group-hover:opacity-70 shrink-0" />
                        </a>
                    @endforeach
                </div>

                <div class="mt-4">{{ $enquiries->links() }}</div>
            @endif
        </div>
    </div>
@endsection
