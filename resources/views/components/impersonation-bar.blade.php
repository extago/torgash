{{-- The strip that says a manager is looking through a customer's eyes
     (App\Core\Access\Impersonation), with the way out. Printed by core through
     `core.body_start` only while that is so, and such a page is never kept
     for guests: whoever sees it is signed in. --}}
<div class="bg-warning text-warning-content text-sm" role="status" data-impersonation-bar>
    <div class="max-w-screen-2xl mx-auto px-4 py-1.5 flex flex-wrap items-center gap-x-4 gap-y-1">
        <span class="inline-flex items-center gap-2"><x-icon name="eye" size="sm" /> {{ __('You are viewing the shop as the customer :name', ['name' => $customer->name]) }}</span>
        <form method="POST" action="{{ route('admin.impersonation.stop') }}">
            @csrf
            <button class="btn btn-sm btn-neutral">{{ __('Leave the customer view') }}</button>
        </form>
        @if (session(\App\Core\Access\Impersonation::REFUSED))
            <span class="inline-flex items-center gap-2 font-semibold w-full"><x-icon name="ban" size="sm" /> {{ __('Only the customer can do this.') }}</span>
        @endif
    </div>
</div>
