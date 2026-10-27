<?php

namespace Torgash\Guard\Invariants;

use App\Models\Currency;
use App\Models\CustomerGroup;
use App\Models\Storefront;

/**
 * The statements about CORE's own data.
 *
 * This module ships these and no others, and the reason is the rule the engine
 * is built on: whoever owns the data says what must be true about it. Core owns
 * currencies, storefronts and customer groups and has no module to speak for
 * it, so the guard speaks for it. Everything about an order belongs to
 * checkout, everything about a stock row to warehouses, and this class must
 * never learn their table names.
 *
 * All three are about money that silently becomes wrong rather than about
 * something breaking — which is the kind of thing an invariant is for. A broken
 * page is reported by the customer within the hour; a rate of 0 quietly prices
 * a catalogue at nothing.
 */
class CoreInvariants
{
    /** @return array<string, array<string, mixed>> */
    public function register(): array
    {
        return [
            // A bucket whose key was revoked, or a folder that filled up,
            // leaves every page looking right — the pictures already there
            // still show — until somebody uploads one and it vanishes.
            'core.file_storage_answers' => [
                'label' => 'The storage for the shop\'s files takes a file and gives it back',
                'explain' => 'Every photograph, attachment and uploaded file is written there. Check the storage settings in .env (TORGASH_STORAGE and the AWS_ keys) and the storage screen\'s own check.',
                'check' => function () {
                    $files = app(\App\Core\Files\FileStorage::class);
                    $problems = [];

                    foreach (\App\Core\Files\FileStorage::DISKS as $disk) {
                        $probe = $files->probe($disk);

                        if (! $probe['ok']) {
                            $problems[] = [
                                'id' => $disk,
                                'title' => $disk === 'public' ? 'Public files' : 'Private files',
                                'detail' => ['The check failed: :reason', ['reason' => (string) $probe['error']]],
                                'url' => route('admin.storage'),
                            ];
                        }
                    }

                    return $problems;
                },
            ],

            // Redis stopping does not stop the shop — the cache and the
            // sessions carry on in the database (FallbackStore) — which is
            // exactly why nobody would notice it without being told.
            'core.cache_answers' => [
                'label' => 'Redis, where the shop keeps its cache or sessions, is answering',
                'explain' => 'While Redis does not answer, the shop works on the database cache: slower, and sessions started there are lost when Redis comes back. Start Redis, or check its address and password on Admin → Cache and sessions.',
                'check' => function () {
                    $setup = app(\App\Core\Performance\CacheSetup::class);

                    if (! $setup->usesRedis()) {
                        return [];
                    }

                    $probe = $setup->probe('redis');

                    return $probe['ok'] ? [] : [[
                        'id' => 'redis',
                        'title' => 'Redis',
                        'detail' => ['The check failed: :reason', ['reason' => (string) $probe['error']]],
                        'url' => route('admin.cache'),
                    ]];
                },
            ],

            // A form told to confirm its contact that no channel can reach
            // lets everything through (FormVerification fails open, rather
            // than refusing every order over a missing SMS gateway) — so the
            // setting would read "on" and do nothing, which nobody would see.
            'core.verification_reachable' => [
                'label' => 'Every form told to confirm its contact by a code can send one',
                'explain' => 'The form is ticked in Settings → Store, but no switched-on channel can reach the kind of contact it carries (an SMS gateway for a phone, or email for an email), so it asks nothing. Switch a channel on under Customers → Code delivery, or untick the form.',
                'check' => function () {
                    $verification = app(\App\Core\Verification\FormVerification::class);
                    $forms = \App\Core\Verification\FormVerification::forms();
                    $problems = [];

                    foreach ((array) setting('core', 'verify_forms', []) as $form) {
                        if (isset($forms[$form]) && $verification->kindFor($form) === null) {
                            $problems[] = [
                                'id' => $form,
                                'title' => $forms[$form]['label'],
                                'detail' => ['Nothing can reach: :kinds', ['kinds' => implode(', ', $forms[$form]['kinds'])]],
                                'url' => route('admin.settings.edit', 'core'),
                            ];
                        }
                    }

                    return $problems;
                },
            ],

            'core.currency_rate_positive' => [
                'label' => 'Every currency the shop offers has a rate above zero',
                'explain' => 'A rate of zero or less makes every price in that currency meaningless — usually free. Set the rate on Settings → Currencies, or switch the currency off.',
                'check' => fn (int $limit) => Currency::where('enabled', true)
                    ->where('rate', '<=', 0)
                    ->limit($limit)
                    ->get()
                    ->map(fn (Currency $c) => [
                        'id' => $c->code,
                        'title' => $c->code.' — '.$c->name,
                        'detail' => ['Rate: :rate', ['rate' => (string) $c->rate]],
                        'url' => route('admin.currencies.index'),
                    ]),
            ],

            // The one that would be hardest to notice by looking at a screen,
            // and the most expensive to be wrong about: it is the assumption
            // every money column in the engine is stored under.
            'core.books_currency_rate' => [
                'label' => 'A storefront\'s own currency has a rate of exactly 1',
                'explain' => 'Amounts are stored in the shop\'s own currency, which is 1 by construction — every other rate is read against it. Editing it shifts every conversion in the shop at once. Set it back to 1 and change the other rates instead.',
                'check' => function (int $limit) {
                    $rates = Currency::pluck('rate', 'code');

                    return Storefront::query()
                        ->get()
                        ->filter(fn (Storefront $s) => isset($rates[$s->default_currency])
                            && (float) $rates[$s->default_currency] !== 1.0)
                        ->take($limit)
                        ->map(fn (Storefront $s) => [
                            'id' => (string) $s->id,
                            'title' => $s->name.' — '.$s->default_currency,
                            'detail' => ['Rate: :rate', ['rate' => (string) $rates[$s->default_currency]]],
                            'url' => route('admin.currencies.index'),
                        ])
                        ->values();
                },
            ],

            // A source that stopped answering leaves last month's rate looking
            // exactly like today's. Measured by when each rate last MOVED,
            // against the schedule it is fetched on (Currency\RateFreshness,
            // the same answer the admin's bell gives).
            'core.currency_rates_fresh' => [
                'label' => 'Rates that update themselves have been updated recently',
                'explain' => 'The rate source has not given this currency a new rate for longer than its schedule allows — usually a bank that changed its API or a mirror that went away. Open Settings → Exchange rates, press "Fetch the rates now" and read the result in Background tasks; switch on the fallback sources, or choose another source.',
                'check' => fn (int $limit) => app(\App\Core\Currency\RateFreshness::class)->stale($limit)
                    ->map(fn (Currency $c) => [
                        'id' => $c->code,
                        'title' => $c->code.' — '.$c->name,
                        'detail' => $c->rate_updated_at
                            ? ['Last updated :when', ['when' => $c->rate_updated_at->format('Y-m-d H:i')]]
                            : ['Never updated automatically', []],
                        'url' => route('admin.currencies.index'),
                    ])
                    ->values(),
            ],

            'core.one_default_customer_group' => [
                'label' => 'Exactly one customer group is the default one',
                'explain' => 'A new customer is put into the default group. With none, they land in no group and see no group prices; with two, which one they get depends on the order rows come back in.',
                'severity' => 'warning',
                'check' => function () {
                    $count = CustomerGroup::where('is_default', true)->count();

                    if ($count === 1) {
                        return [];
                    }

                    return [[
                        // Not about a row — about the shop, so the fixed word
                        // the engine reserves for exactly this.
                        'id' => 'shop',
                        'title' => ['Groups marked as default: :n', ['n' => $count]],
                        'url' => route('admin.customer-groups.index'),
                    ]];
                },
            ],
        ];
    }
}
