{{--
    The product page's "Documentation" section: one row per document — what
    kind of file, its name, how big — and one action. A PDF opens in the
    browser's own viewer; anything else is saved. A document for signed-in
    customers shows a guest that it is there, and the way in. The rows fill
    the section: two to a line on a computer, one on a tablet or a phone.
--}}
@php $documentsSignedIn = auth()->check(); @endphp
<ul class="grid grid-cols-1 gap-2 lg:grid-cols-2">
    @foreach ($documents as $document)
        @php
            $documentLocked = $document->forAccountsOnly() && ! $documentsSignedIn;
            $documentHref = $documentLocked ? route('account.login') : $document->href();
        @endphp
        <li class="min-w-0">
            <a href="{{ $documentHref }}"
               @if ($document->isLink() || ($document->opensInline() && ! $documentLocked)) target="_blank" rel="noopener{{ $document->isLink() ? ' noreferrer' : '' }}" @endif
               class="flex h-full items-center gap-3 p-3 border border-base-300 rounded-box hover:bg-base-200 hover:border-base-content/20 transition-colors group">
                <span class="size-10 shrink-0 rounded-field bg-base-200 group-hover:bg-base-100 flex items-center justify-center text-primary">
                    <x-icon :name="$document->icon()" size="md" />
                </span>
                <span class="grow min-w-0">
                    <span class="block font-medium line-clamp-2 break-words group-hover:text-primary">{{ $document->label() }}</span>
                    <span class="block text-sm opacity-60 truncate">
                        {{ $document->details() }}
                        @if ($documentLocked)
                            · {{ __('for signed-in customers') }}
                        @endif
                    </span>
                </span>
                <span class="btn btn-sm btn-ghost shrink-0 gap-1.5 pointer-events-none">
                    @if ($documentLocked)
                        <x-icon name="lock" size="sm" /> <span class="max-sm:hidden">{{ __('Sign in') }}</span>
                    @elseif ($document->isLink())
                        <x-icon name="external-link" size="sm" /> <span class="max-sm:hidden">{{ __('Open') }}</span>
                    @elseif ($document->opensInline())
                        <x-icon name="eye" size="sm" /> <span class="max-sm:hidden">{{ __('Open') }}</span>
                    @else
                        <x-icon name="download" size="sm" /> <span class="max-sm:hidden">{{ __('Download') }}</span>
                    @endif
                </span>
            </a>
        </li>
    @endforeach
</ul>
