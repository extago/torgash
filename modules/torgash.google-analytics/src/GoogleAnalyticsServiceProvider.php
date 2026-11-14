<?php

namespace Torgash\GoogleAnalytics;

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Torgash\Enquiries\Kinds;

/**
 * Google Analytics 4 (PLAN §73) — see Tag for what goes where.
 *
 * The tag is the module's whole storefront; what it reports comes from the
 * page's own events, which their owners raise (the basket, checkout, the
 * wishlist, the enquiries), so this module names none of them in PHP — but
 * for the list of forms a shop may call leads, offered when the enquiries
 * module is there to have forms.
 */
class GoogleAnalyticsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Google's id of the visitor, read when an order or an enquiry is
        // made: gtag.js writes it in plain text, so it is not decrypted — and
        // a page is drawn the same for every holder of it.
        EncryptCookies::except([Tag::CLIENT_COOKIE]);
        page_cache()->harmless(cookies: [Tag::CLIENT_COOKIE]);

        // ...as a fact a row writes down about the browser it came from, for
        // the shop to tick — then on the order's card, in the API and in the
        // export, and erased with the rest of the browser's facts.
        hook_listen('core.client_facts', fn (array $facts) => $facts + [
            Tag::FACT => [
                'label' => 'Google Analytics client ID',
                'capture' => fn (Request $request) => Tag::clientId($request),
            ],
        ]);

        // After the privacy module's Consent Mode defaults (it listens at 1).
        hook_listen('core.head', fn (string $html, array $context) => $html.app(Tag::class)->head($context['meta'] ?? null));
        hook_listen('core.footer', fn (string $html) => $html.app(Tag::class)->footer());

        // The forms a shop may count as leads are the enquiries' kinds; with
        // no enquiries module there is nothing to tick, and no field.
        hook_listen('core.settings.fields', function (array $fields, array $context) {
            if (($context['module'] ?? null) !== Tag::MODULE) {
                return $fields;
            }

            $kinds = class_exists(Kinds::class) ? array_keys(Kinds::all()) : [];

            return array_values(array_filter(array_map(function (array $field) use ($kinds) {
                if (in_array($field['key'] ?? null, ['leads', '_g_leads'], true) && $kinds === []) {
                    return null;
                }
                if (($field['key'] ?? null) === 'leads') {
                    $field['options'] = collect($kinds)->mapWithKeys(fn (string $kind) => [$kind => Kinds::label($kind)])->all();
                }

                return $field;
            }, $fields)));
        });

        hook_listen('core.settings.saving', function (array $errors, array $context) {
            if (($context['module'] ?? null) !== Tag::MODULE) {
                return $errors;
            }

            $id = strtoupper(trim((string) ($context['values']['tag_id'] ?? '')));

            if ($id !== '' && preg_match(Tag::ID_PATTERN, $id) !== 1) {
                $errors['tag_id'] = __('A measurement ID starts with G-, a Tag Manager container with GTM-: copy it from Google Analytics or Tag Manager as it is.');
            }

            return $errors;
        });
    }
}
