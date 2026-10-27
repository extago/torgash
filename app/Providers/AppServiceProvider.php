<?php

namespace App\Providers;

use App\Models\CustomerGroup;
use App\Models\User;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::defaultView('pagination::daisyui');
        Paginator::defaultSimpleView('pagination::daisyui');

        $this->registerEximHooks();

        // After any sign-in the storefront follows the account's language (CustomerLocale).
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Login::class, fn () => \App\Core\Storefronts\CustomerLocale::signedIn());
    }

    /**
     * "customer" is the first entity core itself (rather than a module)
     * contributes to torgash.exim — User/CustomerGroup/Address are core,
     * so following the "the code that owns the data registers its own
     * exim hooks" convention already used by catalog (products) and
     * checkout (orders) means this has to live here, not in exim itself.
     * torgash.exim never appears anywhere in core's dependencies — these
     * registrations are harmless no-ops if it's never installed.
     */
    protected function registerEximHooks(): void
    {
        hook_listen('exim.entities', fn (array $v) => $v + ['customer' => 'Customer']);

        // Staff accounts share the same `users` table but aren't customers —
        // same exclusion Admin\CustomersController already applies.
        hook_listen('exim.query.customer', fn () => User::query()->customers());

        hook_listen('exim.match.customer', function ($v, array $ctx) {
            // The counterparty's id in the other system first: email is the key
            // a shop thinks in, and it is not stable enough to be a foreign one
            // — people change it, and 1C holds two contacts sharing an address.
            if ($externalId = $ctx['row']['external_id'] ?? null) {
                $linked = User::where('external_id', $externalId)->first();

                if ($linked) {
                    return ['model' => $linked, 'matchedBy' => 'external_id'];
                }
            }

            $email = $ctx['row']['email'] ?? null;
            if (! $email) {
                return ['model' => new User, 'matchedBy' => null];
            }

            $existing = User::where('email', $email)->first();

            return ['model' => $existing ?? new User, 'matchedBy' => $existing ? 'email' : null];
        });

        hook_listen('exim.schema.customer', fn (array $fields) => [...$fields,
            ['key' => 'email', 'label' => 'Email', 'get' => fn (User $u) => $u->email, 'set' => fn (User $u, $v) => $u->email = $v],
            ['key' => 'external_id', 'label' => 'External ID', 'get' => fn (User $u) => $u->external_id, 'set' => fn (User $u, $v) => $u->external_id = ($v ?: null)],
            ['key' => 'name', 'label' => 'Name', 'get' => fn (User $u) => $u->name, 'set' => fn (User $u, $v) => $u->name = $v],
            [
                'key' => 'customer_group', 'label' => 'Customer group',
                'get' => fn (User $u) => $u->customerGroup?->t('name'),
                // Matched case-insensitively against an existing group's name —
                // same care as FeaturesController::resolveGroupId, but this one
                // never auto-creates a group just because an import typo'd one.
                'set' => function (User $u, $v) {
                    $name = trim((string) $v);
                    if ($name === '') {
                        $u->customer_group_id = null;

                        return;
                    }
                    // By its name in any language the shop has.
                    $group = CustomerGroup::whereRaw('exists (select 1 from jsonb_each_text(name) as t(k, v) where lower(t.v) = lower(?))', [$name])
                        ->first();
                    if ($group) {
                        $u->customer_group_id = $group->id;
                    }
                },
            ],
            ['key' => 'registered_at', 'label' => 'Registered at', 'get' => fn (User $u) => $u->created_at?->toDateTimeString(), 'set' => null],
            ['key' => 'phone', 'label' => 'Phone', 'get' => fn (User $u) => $u->defaultAddress()?->phone, 'set' => null],
            ['key' => 'address_line', 'label' => 'Address', 'get' => fn (User $u) => $u->defaultAddress()?->address_line, 'set' => null],
            ['key' => 'city', 'label' => 'City', 'get' => fn (User $u) => $u->defaultAddress()?->city, 'set' => null],
            ['key' => 'postal_code', 'label' => 'Postal code', 'get' => fn (User $u) => $u->defaultAddress()?->postal_code, 'set' => null],
        ]);
    }
}
