<?php

namespace Torgash\Newsletter\Demo;

use App\Core\Demo\DemoPack;
use Illuminate\Support\Str;
use Torgash\Newsletter\Models\Subscriber;

/**
 * Its part of the demo shop (PLAN §55): a list of subscribers in every state,
 * from every place a shop collects them, so the Subscribers screen has
 * something to show. Addresses are at example.com — a domain reserved for
 * examples, so nothing sent to them reaches a person.
 */
class NewsletterDemo
{
    public function install(DemoPack $pack): void
    {
        foreach ($pack->json('newsletter/subscribers') as $row) {
            $email = Str::lower((string) ($row['email'] ?? ''));

            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || Subscriber::where('email', $email)->exists()) {
                continue;
            }

            $since = now()->subDays((int) ($row['days_ago'] ?? 1))->setTime(random_int(8, 22), random_int(0, 59));
            $status = in_array($row['status'] ?? null, [Subscriber::PENDING, Subscriber::SUBSCRIBED, Subscriber::UNSUBSCRIBED], true) ? $row['status'] : Subscriber::SUBSCRIBED;

            Subscriber::create([
                'email' => $email,
                'status' => $status,
                'source' => in_array($row['source'] ?? null, Subscriber::SOURCES, true) ? $row['source'] : 'block',
                'locale' => $row['locale'] ?? null,
                'token' => Str::random(40),
                'consent_text' => __('Demo data.'),
                'confirmation_sent_at' => $status === Subscriber::PENDING ? $since : null,
                'confirmed_at' => $status === Subscriber::PENDING ? null : $since,
                'unsubscribed_at' => $status === Subscriber::UNSUBSCRIBED ? $since->copy()->addDays(random_int(3, 30))->min(now()) : null,
                'created_at' => $since,
                'updated_at' => $since,
            ]);
        }

        $pack->say(Subscriber::count().' subscribers.');
    }
}
