<?php

namespace Torgash\Newsletter\Campaigns;

use Illuminate\Support\Facades\DB;
use Torgash\Newsletter\Models\Delivery;
use Torgash\Newsletter\Models\Link;

/**
 * What a reader did with a campaign's letter, counted ONCE per letter on the
 * campaign's row: the first open, the first click (a click is an open too —
 * a client that blocks pictures never loads the pixel), the unsubscribe.
 * Each mark is a conditional UPDATE, so two requests at once (a client and
 * its link scanner) cannot count one reader twice.
 */
class Tracking
{
    public function opened(Delivery $delivery): void
    {
        if (Delivery::whereKey($delivery->id)->whereNull('opened_at')->update(['opened_at' => now()]) === 1) {
            DB::table('m_newsletter_campaigns')->where('id', $delivery->campaign_id)->increment('opened');
        }
    }

    public function clicked(Delivery $delivery, Link $link): void
    {
        $this->opened($delivery);
        DB::table('m_newsletter_links')->where('id', $link->id)->increment('clicks');

        if (Delivery::whereKey($delivery->id)->whereNull('clicked_at')->update(['clicked_at' => now()]) === 1) {
            DB::table('m_newsletter_campaigns')->where('id', $delivery->campaign_id)->increment('clicked');
        }
    }

    public function unsubscribed(Delivery $delivery): void
    {
        if (Delivery::whereKey($delivery->id)->whereNull('unsubscribed_at')->update(['unsubscribed_at' => now()]) === 1) {
            DB::table('m_newsletter_campaigns')->where('id', $delivery->campaign_id)->increment('unsubscribed');
        }
    }
}
