{{--
    One thread, as the person who started it sees it.

    The enquiry's own answers ARE the first message — they are not copied into
    the message table, so they are printed here as the opening of the
    conversation rather than read out of it.
--}}
@extends('layouts.storefront')

@section('title', $enquiry->kindLabel().' · '.current_storefront()?->name)

@php
    $subject = $enquiry->subject();
    $closed = $enquiry->isLocked();
@endphp

@section('content')
    <div class="flex flex-col md:flex-row gap-6">
        @include('account._nav')

        <div class="flex-1 flex flex-col gap-4">
            <div class="flex flex-wrap items-center gap-3">
                <a href="{{ route('account.enquiries.index') }}" class="btn btn-ghost btn-sm gap-1">
                    <x-icon name="arrow-left" size="sm" />
                    {{ __('Enquiries') }}
                </a>
                <span class="badge {{ $enquiry->customerBadge() }}">
                    {{ $enquiry->customerLabel() }}
                </span>
            </div>

            @if (session('warning'))
                <div class="alert alert-warning py-2 text-sm">{{ session('warning') }}</div>
            @endif

            @if (session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif

            <div class="card bg-base-100 shadow-sm border border-base-300/60">
                <div class="card-body gap-3">
                    <h1 class="card-title text-base">{{ $enquiry->kindLabel() }}</h1>

                    @if ($subject)
                        <div class="text-sm">
                            <span class="opacity-60">{{ __('About') }}:</span>
                            @if ($subject['url'])
                                <a href="{{ $subject['url'] }}" class="link link-primary">{{ $subject['label'] }}</a>
                            @else
                                {{ $subject['label'] }}
                            @endif
                        </div>
                    @endif

                    <div class="text-xs opacity-60">{{ $enquiry->created_at?->format('Y-m-d H:i') }}</div>

                    <table class="table table-sm">
                        <tbody>
                            @foreach ($enquiry->answers() as $answer)
                                <tr>
                                    <td class="whitespace-nowrap opacity-70 w-1/3">{{ $answer['label'] }}</td>
                                    <td class="whitespace-pre-line">{{ $answer['value'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card bg-base-100 shadow-sm border border-base-300/60">
                <div class="card-body gap-3">
                    <h2 class="card-title text-base">{{ __('Conversation') }}</h2>

                    {{-- $post, not $message: @error binds a $message of its own. --}}
                    @forelse ($enquiry->messages as $post)
                        <div class="flex flex-col gap-1 rounded-box p-3 {{ $post->is_staff ? 'bg-base-200' : 'border border-base-300' }}">
                            <div class="flex items-baseline gap-2 flex-wrap">
                                <span class="font-medium">{{ $post->is_staff ? __('The shop') : __('You') }}</span>
                                <span class="text-xs opacity-50">{{ $post->created_at?->format('Y-m-d H:i') }}</span>
                            </div>
                            <div class="text-sm whitespace-pre-line">{{ $post->body }}</div>
                            @include('torgash.enquiries::partials.attachments', ['attachmentOwner' => $post])
                        </div>
                    @empty
                        <p class="text-sm opacity-60">{{ __('We have not replied yet — we will, here.') }}</p>
                    @endforelse

                    @if ($closed)
                        <p class="text-sm opacity-60">{{ __('This conversation is closed.') }}</p>
                    @else
                        <form method="POST" action="{{ route('account.enquiries.reply', $enquiry) }}" enctype="multipart/form-data" class="flex flex-col gap-2">
                            @csrf
                            <textarea name="body" rows="3" required maxlength="5000"
                                      class="textarea textarea-bordered w-full"
                                      placeholder="{{ __('Add to this conversation') }}">{{ old('body') }}</textarea>
                            @error('body')
                                <span class="text-error text-xs">{{ $message }}</span>
                            @enderror
                            @include('torgash.enquiries::partials.attach-field')
                            <div>
                                <button class="btn btn-primary btn-sm">{{ __('Send') }}</button>
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
