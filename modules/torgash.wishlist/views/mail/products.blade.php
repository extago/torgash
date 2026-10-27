{{--
    The products of a wishlist letter, as a block (WishlistLetters,
    {products}): photograph, name, the price — and, for a drop, what it was.
    Inline styles only. Variables are named letter* — an @include hands a
    partial everything its caller has.
--}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; margin:0 0 16px;">
    @foreach ($letterRows as $letterRow)
        <tr>
            <td style="padding:10px 12px 10px 0; border-bottom:1px solid #f1f5f9; width:72px; vertical-align:top;">
                @if ($letterRow['image'])
                    <a href="{{ $letterRow['url'] }}"><img src="{{ $letterRow['image'] }}" alt="" width="64" height="64" style="display:block; object-fit:contain; border-radius:6px; background:#f4f4f5; border:0;"></a>
                @endif
            </td>
            <td style="padding:10px 0; border-bottom:1px solid #f1f5f9; vertical-align:top;">
                <a href="{{ $letterRow['url'] }}" style="color:#0f172a; text-decoration:none;">{{ $letterRow['name'] }}</a>
                <div style="margin-top:6px;">
                    @if (isset($letterRow['new']))
                        <strong style="font-size:16px;">{{ $letterRow['new'] }}</strong>
                        <span style="text-decoration:line-through; color:#94a3b8; margin-left:6px;">{{ $letterRow['old'] }}</span>
                        <span style="color:#16a34a; margin-left:6px;">−{{ $letterRow['percent'] }}%</span>
                    @else
                        <strong style="font-size:16px;">{{ $letterRow['price'] }}</strong>
                    @endif
                </div>
            </td>
        </tr>
    @endforeach
</table>
