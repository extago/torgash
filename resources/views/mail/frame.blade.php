{{--
    The one frame every letter is written in (App\Core\Mail\Letters). Tables
    and inline styles, because that is all a mail client reliably draws —
    Gmail drops <style>, Outlook draws with Word. $body and $footer are HTML
    already escaped by Letters; everything else here is escaped as usual.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject }}</title>
</head>
<body style="margin:0; padding:0; background:{{ $frame['background'] }}; font-family:-apple-system, 'Segoe UI', Roboto, Arial, sans-serif; color:#1e293b; font-size:15px; line-height:1.55;">
{{-- The preheader: what an inbox shows beside the subject, never on the page itself. --}}
<div style="display:none; max-height:0; overflow:hidden; mso-hide:all; font-size:1px; line-height:1px; color:{{ $frame['background'] }};">{{ $preheader ?? '' }}&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{{ $frame['background'] }};">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;">
                <tr>
                    <td style="padding:0 4px 16px;">
                        @if (($topline ?? '') !== '')
                            <span style="float:right; font-size:13px; color:#64748b; line-height:40px;">{{ $topline }}</span>
                        @endif
                        @if ($frame['logo'])
                            <img src="{{ $frame['logo'] }}" alt="{{ current_storefront()?->name ?? config('app.name') }}" style="max-height:40px; max-width:220px; display:block; border:0;">
                        @else
                            <span style="font-size:20px; font-weight:700; color:{{ $frame['color'] }};">{{ current_storefront()?->name ?? config('app.name') }}</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="background:#ffffff; border-radius:12px; padding:28px 28px 20px; border-top:4px solid {{ $frame['color'] }};">
                        @if ($heading !== '')
                            <h1 style="margin:0 0 16px; font-size:22px; line-height:1.3; color:#0f172a;">{{ $heading }}</h1>
                        @endif

                        {!! $body !!}

                        @if ($button)
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 12px;">
                                <tr>
                                    <td style="border-radius:8px; background:{{ $frame['color'] }};">
                                        <a href="{{ $button['url'] }}" style="display:inline-block; padding:12px 22px; color:#ffffff; text-decoration:none; font-weight:600; border-radius:8px;">{{ $button['label'] }}</a>
                                    </td>
                                </tr>
                            </table>
                        @endif

                        @if (($append ?? '') !== '')
                            <div style="margin-top:16px; padding-top:12px; border-top:1px solid #e2e8f0; font-size:12px; color:#64748b;">{!! $append !!}</div>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 8px; font-size:12px; color:#64748b; text-align:center;">
                        {!! $footer !!}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
