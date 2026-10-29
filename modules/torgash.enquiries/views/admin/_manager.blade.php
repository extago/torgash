{{-- Who deals with it, switched in place. "Take it" is first, because that is
     the move a manager makes most often from the list. --}}
<td id="enquiry-manager-{{ $enquiry->id }}" class="whitespace-nowrap" data-cell="manager">
    <div class="dropdown dropdown-end">
        <div tabindex="0" role="button" @class(['btn btn-ghost btn-sm gap-1 font-normal', 'opacity-60' => ! $enquiry->manager])
             title="{{ __('Change manager') }}">
            @if ($enquiry->manager)
                <x-icon name="user" size="sm" class="opacity-60" /> {{ $enquiry->manager->name }}
            @else
                {{ __('Nobody yet') }}
            @endif
            <x-icon name="chevron-down" size="sm" class="opacity-50" />
        </div>
        <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-56 mt-1 p-1 z-50 border border-base-300 max-h-72 overflow-y-auto flex-nowrap">
            @if ($enquiry->manager_id !== auth()->id())
                <li>
                    <button type="button" hx-patch="{{ route('admin.enquiries.manager', $enquiry) }}" hx-vals='@json(['manager_id' => 'me'])'
                            hx-target="#enquiry-manager-{{ $enquiry->id }}" hx-swap="outerHTML" class="font-medium">
                        <x-icon name="hand" size="sm" /> {{ __('Take it myself') }}
                    </button>
                </li>
            @endif
            @foreach ($managers as $manager)
                <li>
                    <button type="button" hx-patch="{{ route('admin.enquiries.manager', $enquiry) }}" hx-vals='@json(['manager_id' => (string) $manager->id])'
                            hx-target="#enquiry-manager-{{ $enquiry->id }}" hx-swap="outerHTML"
                            @class(['font-semibold text-primary' => $enquiry->manager_id === $manager->id])>
                        {{ $manager->name }}
                    </button>
                </li>
            @endforeach
            @if ($enquiry->manager_id)
                <li>
                    <button type="button" hx-patch="{{ route('admin.enquiries.manager', $enquiry) }}" hx-vals='@json(['manager_id' => ''])'
                            hx-target="#enquiry-manager-{{ $enquiry->id }}" hx-swap="outerHTML" class="opacity-70">
                        {{ __('Nobody') }}
                    </button>
                </li>
            @endif
        </ul>
    </div>
</td>
