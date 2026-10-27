@php use Torgash\Enquiries\Status; @endphp
{{-- One enquiry's state, switched in place — the categories list's control.
     A kind with a second axis (a return's own state) says so beside it. --}}
<td id="enquiry-status-{{ $enquiry->id }}" class="whitespace-nowrap">
    <div class="dropdown dropdown-end">
        <div tabindex="0" role="button" class="badge badge-sm gap-1 cursor-pointer {{ Status::badge($enquiry->status) }}"
             title="{{ __('Change status') }}">
            {{ Status::label($enquiry->status) }}
            <x-icon name="chevron-down" size="sm" />
        </div>
        <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-44 mt-1 p-1 z-50 border border-base-300">
            @foreach (Status::options() as $value => $text)
                <li>
                    <button type="button"
                            hx-patch="{{ route('admin.enquiries.status', $enquiry) }}"
                            hx-vals='@json(['status' => $value])'
                            hx-target="#enquiry-status-{{ $enquiry->id }}" hx-swap="outerHTML"
                            @class(['font-semibold text-primary' => $enquiry->status === $value])>
                        {{ $text }}
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
    {!! hook_filter('enquiries.admin.badge', '', ['enquiry' => $enquiry]) !!}
    @if ($enquiry->locked_at)
        <x-icon name="lock" size="sm" class="opacity-50 align-middle" title="{{ __('Closed. Nobody can add to this conversation.') }}" />
    @endif
</td>
