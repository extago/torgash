{{-- In place of a part of the page that failed (App\Core\Content\Fragment),
     for staff only — a customer gets nothing there. Says what failed, why,
     and where to fix it; the error itself is already in the log. --}}
<div class="alert alert-error my-2 text-sm items-start" role="alert" data-fragment-failed>
    <x-icon name="triangle-alert" />
    <div class="flex flex-col gap-1 min-w-0">
        <span class="font-semibold">{{ __('This part of the page failed and is hidden from customers: :what', ['what' => $fragmentWhat]) }}</span>
        <span class="font-mono text-xs break-words opacity-80">{{ $fragmentError }}</span>
        <span class="text-xs opacity-80">{{ __('Only staff see this notice. The error is written to the log.') }}
            @if ($fragmentEditUrl)
                <a href="{{ $fragmentEditUrl }}" class="link font-semibold">{{ __('Edit') }}</a>
            @endif
        </span>
    </div>
</div>
