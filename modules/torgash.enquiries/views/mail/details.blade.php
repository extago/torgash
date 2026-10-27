{{--
    What the person typed, as a block of a letter (EnquiryLetters,
    {enquiry_details}): what it is about, the answers under the labels they
    were asked with — and, for the SHOP only, the basket at the time and
    the page it came from.
    Inline styles only — it lands inside core's mail frame.
--}}
@php $enquirySubject = $enquiry->subject(); @endphp
@if ($enquirySubject)
    <p style="margin:0 0 12px;">
        <span style="color:#64748b;">{{ __('About') }}:</span>
        @if ($enquirySubject['url'])
            <a href="{{ $enquirySubject['url'] }}">{{ $enquirySubject['label'] }}</a>
        @else
            {{ $enquirySubject['label'] }}
        @endif
    </p>
@endif

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; margin:0 0 14px; font-size:14px;">
    @foreach ($enquiry->answers() as $answer)
        <tr>
            <td style="padding:6px 12px 6px 0; border-bottom:1px solid #f1f5f9; color:#64748b; vertical-align:top; width:35%;">{{ $answer['label'] }}</td>
            <td style="padding:6px 0; border-bottom:1px solid #f1f5f9; white-space:pre-line;">{{ $answer['value'] }}</td>
        </tr>
    @endforeach
</table>

{{-- What they were looking at: nothing can answer it after their next click. --}}
@if ($forShop && $enquiry->cart)
    <p style="margin:0 0 4px; font-weight:600; font-size:14px;">{{ __('In their basket at the time') }}</p>
    <ul style="margin:0 0 14px; padding-left:18px; font-size:14px;">
        @foreach ($enquiry->cart as $line)
            <li>{{ $line['name'] ?? '' }} × {{ $line['qty'] ?? 1 }}</li>
        @endforeach
    </ul>
@endif

@if ($forShop && $enquiry->page_url)
    <p style="margin:0 0 14px; font-size:12px; color:#64748b;">{{ __('Sent from') }}: {{ $enquiry->page_url }}</p>
@endif
