<?php

namespace Torgash\Newsletter\Campaigns;

use App\Core\Tasks\TrackedJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Torgash\Newsletter\Models\Campaign;
use Torgash\Newsletter\Models\Delivery;
use Torgash\Newsletter\Models\Subscriber;

/**
 * Sends what is due (PLAN §57.4). One job for every campaign, started by the
 * scheduler each minute (`startIfIdle`, so two never walk the same list) and
 * by "Send now" at once.
 *
 * A campaign whose time has come gets its DELIVERIES first — the segment's
 * addresses as they are at that moment, one row each — and is `sending`;
 * then the rows are walked, a letter each, at the shop's pace
 * (`per_minute`: a mail server that is sent too much at once starts
 * refusing, and the provider marks the shop as a spammer). A run stops
 * after RUN_MINUTES, under the queue's retry_after; the next minute's run
 * goes on from the first row still queued — nothing is sent twice, since a
 * row is marked the moment its letter has gone. An address unsubscribed
 * after the start is skipped, a cancelled campaign is left where it is.
 */
class SendCampaigns extends TrackedJob
{
    /** Minutes of sending per run — the queue's retry_after is an hour (QueueTimeoutTest). */
    public const RUN_MINUTES = 50;

    public int $timeout = 3300;

    /**
     * The language of each letter: the reader's ACCOUNT's (it follows them as
     * they switch the shop's language — CustomerLocale), else the one they
     * subscribed in, else the shop's base one when written.
     */
    public const LOCALE = 'coalesce('
        .'(select u.locale from users u where u.id = m_newsletter_subscribers.user_id), '
        .'(select u.locale from users u where lower(u.email) = m_newsletter_subscribers.email and u.locale is not null order by u.id limit 1), '
        .'m_newsletter_subscribers.locale)';

    public function label(): string
    {
        return 'Send newsletter campaigns';
    }

    public function module(): ?string
    {
        return 'torgash.newsletter';
    }

    protected function run(): void
    {
        $this->prepareDue();

        $until = now()->addMinutes(self::RUN_MINUTES);
        $pause = (int) round(60_000_000 / max(1, (int) setting('torgash.newsletter', 'per_minute', 120)));

        foreach (Campaign::where('status', Campaign::SENDING)->orderBy('started_at')->get() as $campaign) {
            $this->total($campaign->recipients);
            $this->message('Sending ":name"', ['name' => $campaign->name]);
            $done = $campaign->recipients - $campaign->deliveries()->where('status', Delivery::QUEUED)->count();

            while (now()->lt($until)) {
                $batch = $campaign->deliveries()->where('status', Delivery::QUEUED)->with('subscriber.user')->orderBy('id')->limit(50)->get();

                if ($batch->isEmpty()) {
                    $campaign->update(['status' => Campaign::SENT, 'finished_at' => now()]);

                    break;
                }

                // Cancelled from the admin since the last batch: stop here.
                if (Campaign::whereKey($campaign->id)->value('status') !== Campaign::SENDING) {
                    break;
                }

                foreach ($batch as $delivery) {
                    $this->deliver($campaign, $delivery);
                    $this->progress(++$done);
                    usleep($pause);
                }
            }

            if (now()->gte($until)) {
                break;
            }
        }
    }

    /** Due campaigns get their deliveries: the segment's addresses as they are now. */
    protected function prepareDue(): void
    {
        $segments = app(Segments::class);

        foreach (Campaign::where('status', Campaign::SCHEDULED)->where('scheduled_at', '<=', now())->get() as $campaign) {
            DB::transaction(function () use ($campaign, $segments) {
                // Taken under a lock: a second run must not make the rows twice.
                if (Campaign::whereKey($campaign->id)->where('status', Campaign::SCHEDULED)->lockForUpdate()->doesntExist()) {
                    return;
                }

                $addresses = $segments->query($campaign->segment)->toBase()->select([
                    DB::raw((int) $campaign->id), 'id', 'email', DB::raw(self::LOCALE),
                    DB::raw('substr(md5(random()::text || clock_timestamp()::text) || md5(random()::text || id::text), 1, 40)'),
                    DB::raw("'".Delivery::QUEUED."'"),
                ]);
                DB::table('m_newsletter_deliveries')->insertUsing(['campaign_id', 'subscriber_id', 'email', 'locale', 'token', 'status'], $addresses);

                $campaign->update([
                    'status' => Campaign::SENDING,
                    'started_at' => now(),
                    'recipients' => $campaign->deliveries()->count(),
                ]);
            });
        }
    }

    protected function deliver(Campaign $campaign, Delivery $delivery): void
    {
        if (! $delivery->subscriber || $delivery->subscriber->status !== Subscriber::SUBSCRIBED) {
            $delivery->update(['status' => Delivery::SKIPPED]);

            return;
        }

        try {
            $letter = CampaignLetter::compose($campaign, $delivery, $delivery->locale ?: content_base_locale());
            Mail::to($delivery->email)->send(new CampaignMail($letter['subject'], $letter['html'], CampaignLetter::oneClickUrl($delivery)));

            $delivery->update(['status' => Delivery::SENT, 'sent_at' => now()]);
            DB::table('m_newsletter_campaigns')->where('id', $campaign->id)->increment('sent');
        } catch (\Throwable $e) {
            $delivery->update(['status' => Delivery::FAILED, 'error' => mb_substr($e->getMessage(), 0, 500)]);
            DB::table('m_newsletter_campaigns')->where('id', $campaign->id)->increment('failed');
            report($e);
        }
    }
}
