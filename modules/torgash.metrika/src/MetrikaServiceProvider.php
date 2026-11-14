<?php

namespace Torgash\Metrika;

use App\Core\Support\Currencies;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

/**
 * Yandex Metrica (PLAN §73) — see Counter for what goes where.
 *
 * The counter is the module's whole storefront; what it reports comes from
 * the page's own events, which their owners raise (the basket, checkout,
 * the wishlist, the enquiries), so this module names none of them in PHP.
 */
class MetrikaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The counter's id of the visitor, read when an order or an enquiry
        // is made: tag.js writes it in plain text, so it is not decrypted —
        // and a page is drawn the same for every holder of it.
        EncryptCookies::except([Counter::CLIENT_COOKIE]);
        page_cache()->harmless(cookies: [Counter::CLIENT_COOKIE]);

        // ...as a fact a row writes down about the browser it came from, for
        // the shop to tick (an order's, an enquiry's) — then on the order's
        // card, in the API and in the export, and erased with the rest of the
        // browser's facts (ClientFacts, OrderPersonalData).
        hook_listen('core.client_facts', fn (array $facts) => $facts + [
            Counter::FACT => [
                'label' => 'Yandex Metrica ClientID',
                'capture' => fn (Request $request) => Counter::clientId($request),
            ],
        ]);

        hook_listen('core.head', fn (string $html, array $context) => $html.app(Counter::class)->head($context['meta'] ?? null));
        hook_listen('core.footer', fn (string $html) => $html.app(Counter::class)->footer());

        // Metrica's click map and Webvisor show the shop's pages inside
        // Metrica: allowed to, by the page's own policy (never the admin's).
        hook_listen('core.csp.frame_ancestors', fn (array $sources) => app(Counter::class)->frameable()
            ? [...$sources, ...Counter::FRAME_ANCESTORS]
            : $sources);

        // The counter's currency is one of the shop's.
        hook_listen('core.settings.fields', function (array $fields, array $context) {
            if (($context['module'] ?? null) !== Counter::MODULE) {
                return $fields;
            }

            return array_map(function (array $field) {
                if (($field['key'] ?? null) === 'currency') {
                    $field['options'] = ['' => __('The shop’s accounting currency (:code)', ['code' => shop_currency()])]
                        + collect(app(Currencies::class)->all())
                            ->mapWithKeys(fn ($currency) => [(string) $currency->code => (string) $currency->code])
                            ->all();
                }

                return $field;
            }, $fields);
        });

        hook_listen('core.settings.saving', function (array $errors, array $context) {
            if (($context['module'] ?? null) !== Counter::MODULE) {
                return $errors;
            }

            $id = trim((string) ($context['values']['counter_id'] ?? ''));

            if ($id !== '' && preg_match('/^\d{1,12}$/', $id) !== 1) {
                $errors['counter_id'] = __('A counter number is digits only — the number inside ym(…) in the counter’s code.');
            }

            return $errors;
        });
    }
}
