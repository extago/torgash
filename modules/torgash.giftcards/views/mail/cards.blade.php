{{-- The cards an order bought, as a block of the buyer's letter. Inline
     styles only — a mail client reads nothing else. --}}
<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; margin:0 0 14px;">
    @foreach ($mailCards as $mailCard)
        <tr>
            <td style="padding:8px 0; border-bottom:1px solid #f1f5f9;">
                <strong>{{ $mailCard['amount'] }}</strong>
                <span style="color:#64748b;">{{ $mailCard['masked'] }}</span>
                @if ($mailCard['for'])
                    <br><span style="color:#64748b; font-size:13px;">{{ __('For') }}: {{ $mailCard['for'] }}@if ($mailCard['on']) · {{ __('Send on') }}: {{ $mailCard['on'] }}@endif</span>
                @endif
            </td>
            <td align="right" style="padding:8px 0; border-bottom:1px solid #f1f5f9; white-space:nowrap;">
                <a href="{{ $mailCard['url'] }}" style="color:inherit;">{{ __('Open the card') }}</a>
            </td>
        </tr>
    @endforeach
</table>
