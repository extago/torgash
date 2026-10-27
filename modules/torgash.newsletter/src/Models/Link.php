<?php

namespace Torgash\Newsletter\Models;

use Illuminate\Database\Eloquent\Model;

/** An address a campaign's letter points at, and how many times it was followed. */
class Link extends Model
{
    protected $table = 'm_newsletter_links';

    protected $guarded = [];

    public $timestamps = false;

    /** The row for this address in this campaign, made the first time it is seen. */
    public static function for(Campaign $campaign, string $url): self
    {
        return self::firstOrCreate(['campaign_id' => $campaign->id, 'hash' => sha1($url)], ['url' => $url]);
    }
}
