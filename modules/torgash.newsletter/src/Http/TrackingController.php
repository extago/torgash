<?php

namespace Torgash\Newsletter\Http;

use App\Http\Controllers\Controller;
use Torgash\Newsletter\Campaigns\Tracking;
use Torgash\Newsletter\Models\Delivery;
use Torgash\Newsletter\Models\Link;
use Torgash\Newsletter\Subscriptions;

/**
 * The addresses inside a campaign's letter (outside `web`: no session, no
 * cookie, no CSRF — a mail client is not a visitor). An unknown token is
 * answered like a known one where it costs nothing to: the pixel is always a
 * pixel. A click goes ONLY to the address stored for that link of that
 * campaign: the route is not a redirector anybody can point anywhere.
 */
class TrackingController extends Controller
{
    /** A transparent 1×1 GIF. */
    protected const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function open(string $token, Tracking $tracking)
    {
        if ($delivery = Delivery::where('token', $token)->first()) {
            $tracking->opened($delivery);
        }

        return response(base64_decode(self::PIXEL), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }

    public function click(string $token, int $link, Tracking $tracking)
    {
        $delivery = Delivery::where('token', $token)->firstOrFail();
        $target = Link::where('campaign_id', $delivery->campaign_id)->findOrFail($link);

        $tracking->clicked($delivery, $target);

        return redirect()->away($target->url);
    }

    /** RFC 8058 one-click: the mail client's own "Unsubscribe" button, a POST with no page. */
    public function oneClick(string $token, Tracking $tracking, Subscriptions $subscriptions)
    {
        $delivery = Delivery::where('token', $token)->first();

        if ($delivery?->subscriber) {
            $subscriptions->unsubscribe($delivery->subscriber);
            $tracking->unsubscribed($delivery);
        }

        return response()->noContent();
    }
}
