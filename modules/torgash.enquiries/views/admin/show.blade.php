@extends('admin.layout')

@section('title', $enquiry->kindLabel())
@section('own_heading', '1')

@php
    use Carbon\CarbonInterface;
    use Torgash\Enquiries\Status;

    $subject = $enquiry->subject();
    $since = $enquiry->waitingSince();
    $overdue = $enquiry->isOverdue();
    $isMine = $enquiry->manager_id === auth()->id();
    $person = trim(hook_filter('enquiries.admin.person', '', ['enquiry' => $enquiry]));
@endphp

@section('content')
    <div class="flex flex-col gap-4">
        <x-admin.form-bar class="flex-wrap">
            <a href="{{ route('admin.enquiries.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('All enquiries') }}" aria-label="{{ __('All enquiries') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                {{-- One line: on a phone, wrapped beside "Take it myself", it
                     stood four lines tall in a bar that stays on the screen. --}}
                <div class="text-xs opacity-50 leading-none truncate">
                    #{{ $enquiry->id }} · {{ $enquiry->kindLabel() }} · {{ $enquiry->created_at?->format('d.m.Y H:i') }}
                </div>
                <h1 class="font-semibold truncate leading-tight flex items-center gap-2">
                    {{ $enquiry->name ?: ($enquiry->phone ?: ($enquiry->email ?: __('No name'))) }}
                    <span class="badge badge-sm {{ Status::badge($enquiry->status) }}">{{ Status::label($enquiry->status) }}</span>
                    @if ($enquiry->isLocked())
                        <x-icon name="lock" size="sm" class="opacity-50" title="{{ __('Closed. Nobody can add to this conversation.') }}" />
                    @endif
                    @if ($since)
                        <span @class(['badge badge-sm gap-1 font-normal', 'badge-error' => $overdue, 'badge-warning' => ! $overdue])
                              title="{{ __('Waiting since :time', ['time' => $since->format('d.m.Y H:i')]) }}">
                            <x-icon name="clock" size="sm" /> {{ __('waiting :time', ['time' => $since->diffForHumans(null, CarbonInterface::DIFF_ABSOLUTE, true)]) }}
                        </span>
                    @endif
                </h1>
            </div>

            @unless ($isMine)
                <form method="POST" action="{{ route('admin.enquiries.take', $enquiry) }}">
                    @csrf
                    <button class="btn btn-sm gap-1.5"><x-icon name="hand" size="sm" /> {{ __('Take it myself') }}</button>
                </form>
            @endunless

            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                    <x-icon name="settings" size="lg" />
                </div>
                <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                    @if ($enquiry->isLocked())
                        <li><button form="reopen-enquiry"><x-icon name="rotate-ccw" /> {{ __('Reopen') }}</button></li>
                    @else
                        <li><button form="close-enquiry"><x-icon name="lock" /> {{ __('Close the conversation') }}</button></li>
                    @endif
                    <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                    <li><button form="delete-enquiry" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                </ul>
            </div>

            {{-- The queue's own order: the oldest enquiry still owed an answer,
                 so working through the inbox is one button per enquiry. --}}
            @if ($next)
                <a href="{{ route('admin.enquiries.show', $next->id) }}" class="btn btn-primary btn-sm gap-1.5">
                    {{ __('Next in the queue') }} <span class="badge badge-sm">{{ $queueSize - ($since ? 1 : 0) }}</span>
                    <x-icon name="arrow-right" size="sm" />
                </a>
            @else
                <span class="btn btn-sm btn-disabled gap-1.5">{{ __('The queue is empty') }}</span>
            @endif
        </x-admin.form-bar>

        {{-- The forms the header's buttons submit, beside everything rather than
             inside anything: a nested form is dropped by the parser while its
             inputs stay behind. --}}
        <div class="hidden">
        <form method="POST" action="{{ route('admin.enquiries.close', $enquiry) }}" id="close-enquiry">@csrf</form>
        <form method="POST" action="{{ route('admin.enquiries.reopen', $enquiry) }}" id="reopen-enquiry">@csrf</form>
        <form method="POST" action="{{ route('admin.enquiries.destroy', $enquiry) }}" id="delete-enquiry"
              data-confirm="{{ __('Delete this enquiry?') }}">
            @csrf @method('DELETE')
        </form>
        </div>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if (session('warning'))
            <div class="alert alert-warning text-sm">{{ session('warning') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_24rem] items-start">
            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <div class="flex items-start justify-between gap-3 flex-wrap">
                            <h3 class="font-semibold">{{ __('What they asked') }}</h3>
                            <div class="flex flex-wrap gap-2">
                                @if ($enquiry->phone)
                                    <a href="tel:{{ preg_replace('/[^\d+]/', '', $enquiry->phone) }}" class="btn btn-sm gap-1.5">
                                        <x-icon name="phone" size="sm" />{{ $enquiry->phone }}
                                    </a>
                                @endif
                                @if ($enquiry->email)
                                    <a href="mailto:{{ $enquiry->email }}" class="btn btn-sm gap-1.5">
                                        <x-icon name="mail" size="sm" />{{ $enquiry->email }}
                                    </a>
                                @endif
                            </div>
                        </div>

                        @if ($subject)
                            <div class="text-sm flex items-center gap-1.5">
                                <x-icon name="link-2" size="sm" class="opacity-50" />
                                <span class="opacity-60">{{ __('About') }}:</span>
                                @if ($subject['url'])
                                    <a href="{{ $subject['url'] }}" class="link link-primary" target="_blank" rel="noopener">{{ $subject['label'] }}</a>
                                @else
                                    {{ $subject['label'] }}
                                @endif
                            </div>
                        @endif

                        <div class="overflow-x-auto">
                            <table class="table table-sm">
                                <tbody>
                                    @foreach ($enquiry->answers() as $answer)
                                        <tr>
                                            <td class="whitespace-nowrap opacity-60 w-1/4 align-top">{{ $answer['label'] }}</td>
                                            <td class="whitespace-pre-line">{{ $answer['value'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        @include('torgash.enquiries::partials.attachments', ['attachmentOwner' => $enquiry])
                    </div>
                </section>

                {{-- Whatever the KIND of this enquiry adds. A return puts its lines,
                     its state and its buttons here — above the conversation,
                     because the manager decides from the goods and then writes.
                     Enquiries stays ignorant of what a return is: it draws
                     whatever answers, exactly as the storefront does for the
                     product buy box. --}}
                {!! hook_filter('enquiries.admin.extra', '', ['enquiry' => $enquiry]) !!}

                {{-- The conversation. The card above IS its first message — what
                     the person typed, under the labels they were asked it under —
                     so the thread starts empty and says so, rather than repeating
                     the question back at the manager as message number one.

                     With nobody to read it (only a phone number: no email to
                     mail a reply to, no account to leave it in) there is no
                     conversation at all — the same rows become the shop's CALL
                     LOG, and the page says so instead of offering a "reply"
                     that would go nowhere. --}}
                @php $reachable = $enquiry->canBeAnswered(); @endphp
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold flex items-center gap-2">
                            {{ $reachable ? __('Conversation') : __('Call log') }}
                            @if ($enquiry->messages->isNotEmpty())
                                <span class="badge badge-ghost badge-sm">{{ $enquiry->messages->count() }}</span>
                            @endif
                        </h3>

                        @unless ($reachable)
                            <div class="flex flex-wrap items-center gap-3 rounded-box bg-base-200 p-3 text-sm">
                                <x-icon name="phone-call" class="opacity-60" />
                                <span class="flex-1 min-w-60">{{ __('Only a phone number was left: no email and no account, so nobody would read a reply here. Ring them and write down what was agreed — the log is for the shop only.') }}</span>
                                @if ($enquiry->phone)
                                    <a href="tel:{{ preg_replace('/[^\d+]/', '', $enquiry->phone) }}" class="btn btn-primary btn-sm gap-1.5">
                                        <x-icon name="phone" size="sm" /> {{ $enquiry->phone }}
                                    </a>
                                @endif
                            </div>
                        @endunless

                        {{-- $post, not $message: @error binds $message of its own, and
                             a loop variable of that name two lines above is exactly
                             the kind of silent collision LESSONS.md is about. --}}
                        <div class="flex flex-col gap-3">
                            @forelse ($enquiry->messages as $post)
                                @if ($reachable)
                                    <div @class(['flex flex-col gap-1 max-w-[85%]', 'self-end items-end' => $post->is_staff, 'self-start' => ! $post->is_staff])>
                                        <div class="flex items-baseline gap-2 text-xs px-1">
                                            <span class="font-medium">{{ $post->authorName() }}</span>
                                            <span class="opacity-50">{{ $post->created_at?->format('d.m.Y H:i') }}</span>
                                        </div>
                                        <div @class([
                                            'rounded-box px-3 py-2 text-sm whitespace-pre-line',
                                            'bg-primary/10' => $post->is_staff,
                                            'bg-base-200' => ! $post->is_staff,
                                        ])>{{ $post->body }}</div>
                                        @include('torgash.enquiries::partials.attachments', ['attachmentOwner' => $post])
                                    </div>
                                @else
                                    <div class="flex gap-3 text-sm">
                                        <x-icon name="notebook-pen" size="sm" class="opacity-40 mt-0.5 shrink-0" />
                                        <div class="min-w-0">
                                            <div class="text-xs opacity-60">{{ $post->authorName() }} · {{ $post->created_at?->format('d.m.Y H:i') }}</div>
                                            <div class="whitespace-pre-line">{{ $post->body }}</div>
                                        </div>
                                    </div>
                                @endif
                            @empty
                                <p class="text-sm opacity-60">{{ $reachable ? __('Nobody has replied yet.') : __('Nothing written down yet.') }}</p>
                            @endforelse
                        </div>

                        @if ($enquiry->isLocked())
                            <div class="flex flex-wrap items-center gap-3 text-sm rounded-box bg-base-200 p-3">
                                <x-icon name="lock" size="sm" class="opacity-60" />
                                <span class="opacity-70">{{ __('Closed. Nobody can add to this conversation.') }}</span>
                                <button form="reopen-enquiry" class="btn btn-ghost btn-sm gap-1">
                                    <x-icon name="rotate-ccw" size="sm" /> {{ __('Reopen') }}
                                </button>
                            </div>
                        @else
                            <form method="POST" action="{{ route('admin.enquiries.reply', $enquiry) }}" enctype="multipart/form-data"
                                  class="flex flex-col gap-2 border-t border-base-300 pt-3">
                                @csrf
                                <textarea name="body" rows="4" required maxlength="5000"
                                          class="textarea textarea-bordered w-full"
                                          x-data x-on:keydown.ctrl.enter.prevent="$el.form.requestSubmit()"
                                          x-on:keydown.meta.enter.prevent="$el.form.requestSubmit()"
                                          placeholder="{{ $reachable ? __('Write a reply to the customer') : __('What was agreed on the phone') }}">{{ old('body') }}</textarea>
                                @error('body')
                                    <span class="text-error text-xs">{{ $message }}</span>
                                @enderror
                                @if ($reachable)
                                    @include('torgash.enquiries::partials.attach-field')
                                @endif
                                <div class="flex flex-wrap items-center gap-2">
                                    <button class="btn btn-primary btn-sm gap-1.5">
                                        <x-icon :name="$reachable ? 'send' : 'notebook-pen'" size="sm" /> {{ $reachable ? __('Reply') : __('Write down') }}
                                    </button>
                                    <button name="and_close" value="1" class="btn btn-sm gap-1.5">
                                        <x-icon name="circle-check" size="sm" /> {{ $reachable ? __('Reply and close') : __('Write down and close') }}
                                    </button>
                                    <span class="text-xs opacity-50 hidden sm:inline">Ctrl+Enter</span>
                                    {{-- The one-click end of it, next to the box the
                                         manager is already looking at: it is what they
                                         do after the last answer, not a field they set. --}}
                                    <button type="submit" form="close-enquiry" formnovalidate class="btn btn-ghost btn-sm gap-1.5 ms-auto">
                                        <x-icon name="circle-check" size="sm" /> {{ __('Question resolved') }}
                                    </button>
                                </div>
                                @if ($reachable)
                                    <p class="text-xs opacity-50">
                                        {{ trim((string) $enquiry->email) !== ''
                                            ? __('The customer gets the reply by email, in the language they asked in, and sees it in their account.')
                                            : __('The customer has no email here: the reply waits in their account.') }}
                                    </p>
                                @endif
                            </form>
                        @endif
                    </div>
                </section>

                @if ($enquiry->cart)
                    {{-- The one thing that cannot be reconstructed later: what they
                         had in the basket when they asked. --}}
                    <section class="card bg-base-100 border border-base-300">
                        <div class="card-body gap-2">
                            <h3 class="font-semibold">{{ __('In their basket at the time') }}</h3>
                            <table class="table table-sm">
                                <tbody>
                                    @foreach ($enquiry->cart as $line)
                                        <tr>
                                            <td>{{ $line['name'] ?? '' }}</td>
                                            <td class="w-16 text-right opacity-70">× {{ $line['qty'] ?? 1 }}</td>
                                            <td class="w-32 text-right">{{ admin_price((int) ($line['unit_price'] ?? 0)) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </section>
                @endif
            </div>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Handling') }}</h3>

                        <form method="POST" action="{{ route('admin.enquiries.update', $enquiry) }}" class="flex flex-col gap-3">
                            @csrf
                            @method('PATCH')

                            <fieldset class="fieldset p-0">
                                <legend class="fieldset-legend">{{ __('Status') }}</legend>
                                <select name="status" class="select select-bordered w-full">
                                    @foreach (Status::options() as $value => $label)
                                        <option value="{{ $value }}" @selected($enquiry->status === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </fieldset>

                            <fieldset class="fieldset p-0">
                                <legend class="fieldset-legend">{{ __('Manager') }}</legend>
                                <select name="manager_id" class="select select-bordered w-full">
                                    <option value="">{{ __('Nobody yet') }}</option>
                                    @foreach ($managers as $manager)
                                        <option value="{{ $manager->id }}" @selected($enquiry->manager_id === $manager->id)>{{ $manager->name }}</option>
                                    @endforeach
                                </select>
                            </fieldset>

                            <fieldset class="fieldset p-0">
                                <legend class="fieldset-legend">{{ __('Notes') }}</legend>
                                <textarea name="notes" rows="4" class="textarea textarea-bordered w-full"
                                          placeholder="{{ __('Only the shop sees this.') }}">{{ $enquiry->notes }}</textarea>
                            </fieldset>

                            <button class="btn btn-primary btn-sm">{{ __('Save') }}</button>
                        </form>
                    </div>
                </section>

                {{-- Who this is to the shop: everything else they have asked, and
                     — through enquiries.admin.person — whatever other modules know
                     about them (checkout adds their orders). --}}
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('This customer') }}</h3>

                        @if ($enquiry->user)
                            <div class="text-sm flex items-center gap-1.5">
                                <x-icon name="user-round" size="sm" class="opacity-60" />
                                {{ __('Account') }}: <span class="font-medium">{{ $enquiry->user->name }}</span>
                            </div>
                        @else
                            <div class="text-xs opacity-60">{{ __('Asked without an account.') }}</div>
                        @endif

                        {!! $person !!}

                        <div class="flex flex-col gap-1">
                            <div class="text-xs opacity-60 flex items-center justify-between">
                                <span>{{ __('Other enquiries') }}</span>
                                <span class="tabular-nums">{{ $samePerson->count() }}</span>
                            </div>
                            @forelse ($samePerson as $other)
                                <a href="{{ route('admin.enquiries.show', $other) }}"
                                   class="flex items-center gap-2 text-sm rounded-box px-2 py-1 -mx-2 hover:bg-base-200">
                                    <span class="badge badge-sm {{ Status::badge($other->status) }}" title="{{ Status::label($other->status) }}"></span>
                                    <span class="truncate">{{ $other->kindLabel() }}</span>
                                    <span class="text-xs opacity-50 ms-auto whitespace-nowrap">#{{ $other->id }} · {{ $other->created_at?->format('d.m.Y') }}</span>
                                </a>
                            @empty
                                <p class="text-xs opacity-50">{{ __('This is the first time they have asked.') }}</p>
                            @endforelse
                        </div>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-2 text-sm">
                        <h3 class="font-semibold">{{ __('Where it came from') }}</h3>

                        <div><span class="opacity-60">{{ __('Received') }}:</span> {{ $enquiry->created_at?->format('d.m.Y H:i') }}</div>
                        @if ($enquiry->answered_at)
                            <div>
                                <span class="opacity-60">{{ __('First answer') }}:</span> {{ $enquiry->answered_at->format('d.m.Y H:i') }}
                                <span class="opacity-50">({{ __('after :time', ['time' => $enquiry->created_at?->diffForHumans($enquiry->answered_at, CarbonInterface::DIFF_ABSOLUTE, true)]) }})</span>
                            </div>
                        @endif
                        @if ($enquiry->storefront)
                            <div><span class="opacity-60">{{ __('Storefront') }}:</span> {{ $enquiry->storefront->name }}</div>
                        @endif
                        @if ($enquiry->locale)
                            <div><span class="opacity-60">{{ __('Language') }}:</span> {{ strtoupper($enquiry->locale) }}</div>
                        @endif
                        @if ($enquiry->page_url)
                            <div class="break-all">
                                <span class="opacity-60">{{ __('Page') }}:</span>
                                <a href="{{ $enquiry->page_url }}" class="link" target="_blank" rel="noopener">{{ $enquiry->page_url }}</a>
                            </div>
                        @endif
                    </div>
                </section>
            </div>
        </div>
    </div>
@endsection
