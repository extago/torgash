<?php

namespace Database\Seeders;

use App\Core\Access\AccessGuard;
use App\Core\Settings\CoreSettings;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Storefront;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class CoreSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'enabled' => true, 'sort' => 0],
            ['code' => 'ru', 'name' => 'Russian', 'native_name' => 'Русский', 'enabled' => true, 'sort' => 1],
            ['code' => 'uk', 'name' => 'Ukrainian', 'native_name' => 'Українська', 'enabled' => false, 'sort' => 2],
        ] as $language) {
            Language::updateOrCreate(['code' => $language['code']], $language);
        }

        // Their cases, only where a language has said nothing — a seeder runs
        // again over an existing database and may not undo the shop's edit.
        \App\Core\Support\GrammaticalCases::seedDefaults();

        foreach ([
            ['code' => 'USD', 'symbol' => '$', 'name' => 'US Dollar', 'rate' => 1, 'decimals' => 2, 'sort' => 0],
            ['code' => 'EUR', 'symbol' => '€', 'name' => 'Euro', 'rate' => 0.86, 'decimals' => 2, 'sort' => 1],
            ['code' => 'RUB', 'symbol' => '₽', 'name' => 'Russian Ruble', 'rate' => 81, 'decimals' => 0, 'sort' => 2],
            ['code' => 'UAH', 'symbol' => '₴', 'name' => 'Ukrainian Hryvnia', 'rate' => 41, 'decimals' => 0, 'sort' => 3],
        ] as $currency) {
            Currency::updateOrCreate(['code' => $currency['code']], $currency);
        }

        Storefront::updateOrCreate(['code' => 'main'], [
            'name' => 'Torgash Store',
            'domains' => ['torgash.local', 'www.torgash.local'],
            'theme' => 'default',
            'default_locale' => 'en',
            'locales' => ['en', 'ru'],
            'default_currency' => 'USD',
            'currencies' => ['USD', 'EUR', 'RUB'],
            'is_default' => true,
            'enabled' => true,
        ]);

        // Core's permissions come from the same declaration the permission-group
        // screen reads, so a new one is added in one place. Core is never
        // installed, so nothing else would create these rows.
        foreach (CoreSettings::permissions() as $permission) {
            Permission::findOrCreate($permission['name']);
        }

        $admin = Role::findOrCreate(AccessGuard::SUPER_ROLE);
        $admin->givePermissionTo('admin.access');

        // admin/admin only where it is harmless: a developer's machine and the
        // tests. A live shop gets its owner from the console, with a password
        // somebody chose (App\Core\Access\InstallPassword says why).
        if (! \App\Core\Access\InstallPassword::allowedHere()) {
            $this->command?->warn('No administrator was made. Create one: php artisan torgash:admin you@example.com');

            return;
        }

        $user = User::firstOrCreate(
            ['email' => 'admin@torgash.local'],
            ['name' => 'Admin', 'password' => bcrypt(\App\Core\Access\InstallPassword::PASSWORD)],
        );
        $user->assignRole($admin);
    }
}
