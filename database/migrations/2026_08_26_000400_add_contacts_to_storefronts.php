<?php

use App\Core\Settings\SettingsManager;
use App\Models\Storefront;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contact details become part of the storefront rather than of the module that
 * happens to display them.
 *
 * They lived in torgash.contacts' own settings, which had the ownership
 * backwards: disabling that module dropped the store's phone number entirely,
 * and anything else that wants it — an order email, an invoice, the footer,
 * schema.org markup — would have had to depend on a HEADER WIDGET to find out
 * how to reach the shop. Contact details are store identity; the header block
 * is one consumer of them.
 *
 * One jsonb column rather than a column per channel: this is a bag of optional
 * contact channels that keeps growing (messengers especially), none of it is
 * ever queried, and the storefront already stores domains/locales/currencies
 * the same way.
 *
 * Values are carried across per storefront using the SAME resolution the app
 * used at runtime (per-storefront override, else global), so nothing visibly
 * changes on upgrade. The old settings rows are removed so there is exactly one
 * source of truth afterwards; down() puts them back.
 */
return new class extends Migration
{
    private const MODULE = 'torgash.contacts';

    private const KEYS = ['phone', 'email', 'hours', 'telegram', 'whatsapp', 'viber', 'callback_url'];

    public function up(): void
    {
        Schema::table('storefronts', function (Blueprint $table) {
            $table->jsonb('contacts')->nullable();
        });

        if (! Schema::hasTable('settings')) {
            return;
        }

        $settings = app(SettingsManager::class);

        foreach (Storefront::all() as $storefront) {
            $contacts = [];

            foreach (self::KEYS as $key) {
                // Same precedence the runtime used: storefront override wins
                // over the global value, so each storefront keeps exactly what
                // it was already showing.
                $value = $settings->get(self::MODULE, $key, null, $storefront->id)
                    ?? $settings->get(self::MODULE, $key);

                if (trim((string) $value) !== '') {
                    $contacts[$key] = (string) $value;
                }
            }

            $storefront->update(['contacts' => $contacts ?: null]);
        }

        DB::table('settings')->where('module', self::MODULE)->whereIn('key', self::KEYS)->delete();
    }

    public function down(): void
    {
        // Back to global module settings, taken from the default storefront —
        // the shape they had before, since settings have no per-storefront
        // meaning without a storefront to attach them to.
        if (Schema::hasTable('settings')) {
            $default = Storefront::where('is_default', true)->first() ?? Storefront::first();

            foreach ($default?->contacts ?? [] as $key => $value) {
                if (in_array($key, self::KEYS, true)) {
                    DB::table('settings')->updateOrInsert(
                        ['module' => self::MODULE, 'key' => $key, 'storefront_id' => null],
                        ['value' => $value],
                    );
                }
            }
        }

        Schema::table('storefronts', function (Blueprint $table) {
            $table->dropColumn('contacts');
        });
    }
};
