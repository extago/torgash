<?php

namespace Tests\Feature\Core;

use App\Core\Install\Installer;
use App\Core\Install\ServerCheck;
use RuntimeException;
use Tests\TestCase;

/**
 * The installer's parts that can be checked inside the suite (PLAN §54): the
 * module sets and their order, the refusal to install over a shop, the
 * server check. The whole install is exercised on an unpacked distribution
 * against a database of its own — see the progress log for 2026-10-20.
 */
class InstallerTest extends TestCase
{
    public function test_a_set_brings_what_it_needs_and_dependencies_come_first(): void
    {
        $modules = (new Installer)->resolve('minimal');

        $this->assertContains('torgash.catalog', $modules);
        $this->assertLessThan(array_search('torgash.catalog', $modules), array_search('torgash.media', $modules), 'media before the catalogue that needs it');
        $this->assertLessThan(array_search('torgash.checkout', $modules), array_search('torgash.cart', $modules));
        $this->assertLessThan(array_search('torgash.payments', $modules), array_search('torgash.checkout', $modules));
        $this->assertSame(count($modules), count(array_unique($modules)));
    }

    public function test_a_set_may_name_another(): void
    {
        $store = (new Installer)->resolve('store');

        foreach ((new Installer)->resolve('minimal') as $id) {
            $this->assertContains($id, $store);
        }
        $this->assertContains('torgash.search', $store);
    }

    public function test_all_is_everything_but_the_example(): void
    {
        $all = (new Installer)->resolve('all');

        $this->assertNotContains('torgash.hello', $all);
        $this->assertContains('torgash.commerceml', $all);
        $this->assertCount(count(glob(base_path('modules/*/manifest.json'))) - 1, $all);
    }

    public function test_a_list_gets_its_dependencies(): void
    {
        $this->assertSame(['torgash.media', 'torgash.catalog', 'torgash.wishlist'], (new Installer)->resolve(['torgash.wishlist']));
    }

    public function test_a_set_brings_the_modules_of_the_shops_country_and_no_others(): void
    {
        $installer = new Installer;

        $ukraine = $installer->resolve('minimal', 'UA');
        foreach (['torgash.novaposhta', 'torgash.liqpay', 'torgash.monobank', 'torgash.wayforpay'] as $id) {
            $this->assertContains($id, $ukraine);
        }
        $this->assertLessThan(array_search('torgash.novaposhta', $ukraine), array_search('torgash.shipping', $ukraine), 'their dependencies still come first');

        $this->assertNotContains('torgash.novaposhta', $installer->resolve('minimal'), 'no country, no country\'s modules');
        $this->assertNotContains('torgash.novaposhta', $installer->resolve('store', 'PL'));
        $this->assertSame($installer->resolve(['torgash.wishlist']), $installer->resolve(['torgash.wishlist'], 'UA'), 'a list of ids is taken as it is');
    }

    public function test_a_manifest_says_which_countries_it_is_for(): void
    {
        $manager = new \App\Core\Modules\ModuleManager(app());

        $this->assertSame(['UA'], $manager->find('torgash.novaposhta')->regions);
        $this->assertTrue($manager->find('torgash.novaposhta')->isFor('ua'));
        $this->assertFalse($manager->find('torgash.novaposhta')->isFor('PL'));
        $this->assertTrue($manager->find('torgash.catalog')->isFor('PL'), 'a module without regions is for every country');
    }

    public function test_the_modules_screen_marks_and_filters_by_country(): void
    {
        $page = $this->actingAs(\App\Models\User::role('admin')->firstOrFail())->get('http://localhost/admin/modules')->assertOk();

        $page->assertSee('data-modules-region', false);
        $page->assertSee('data-regions="UA"', false);
    }

    public function test_an_unknown_module_or_set_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        (new Installer)->resolve(['torgash.catalog', 'torgash.no-such-thing']);
    }

    public function test_an_unknown_set_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        (new Installer)->resolve('everything');
    }

    public function test_a_database_with_modules_counts_as_installed_and_gets_its_lock(): void
    {
        $lock = storage_path(Installer::LOCK);
        $saved = is_file($lock) ? file_get_contents($lock) : null;
        @unlink($lock);

        try {
            $this->assertTrue(Installer::installed());
            $this->assertFileExists($lock, 'a shop from before the lock gets one');
            $this->assertArrayHasKey('found_at', json_decode((string) file_get_contents($lock), true));
        } finally {
            $saved === null ? @unlink($lock) : file_put_contents($lock, $saved);
        }
    }

    public function test_the_command_refuses_to_install_over_a_shop(): void
    {
        $this->artisan('torgash:install', ['--check' => true, '--no-interaction' => true])
            ->expectsOutputToContain('already installed')
            ->assertFailed();
    }

    public function test_the_server_check_reads_this_server_and_this_database(): void
    {
        $check = new ServerCheck;
        $server = $check->server();

        $this->assertTrue(collect($server)->firstWhere('label', __('PHP extension :name', ['name' => 'pdo_pgsql']))['ok']);

        $connection = config('database.connections.pgsql');
        $database = $check->database([
            'host' => $connection['host'], 'port' => $connection['port'], 'database' => $connection['database'],
            'username' => $connection['username'], 'password' => $connection['password'],
        ]);

        $this->assertTrue(ServerCheck::passes($database), json_encode($database, JSON_UNESCAPED_UNICODE));
    }

    public function test_an_unreachable_database_fails_the_check(): void
    {
        $database = (new ServerCheck)->database(['host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'x', 'password' => 'x']);

        $this->assertFalse(ServerCheck::passes($database));
    }

    public function test_the_web_installer_does_not_exist_on_an_installed_shop(): void
    {
        $this->get('http://localhost/install')->assertNotFound();
        $this->post('http://localhost/install/start')->assertNotFound();
        $this->post('http://localhost/install/step')->assertNotFound();
    }

    public function test_an_installed_shop_is_not_sent_to_the_installer(): void
    {
        $this->get('http://localhost/')->assertOk();
    }

    public function test_the_steps_are_one_module_each_between_the_schema_and_the_owner(): void
    {
        $installer = new Installer;
        $modules = $installer->resolve('minimal');
        $keys = array_column($installer->steps(['module_list' => $modules, 'styles' => false]), 'key');

        $this->assertSame(['env', 'database', 'migrate', 'seed'], array_slice($keys, 0, 4));
        $this->assertSame(array_map(fn ($id) => 'module:'.$id, $modules), array_slice($keys, 4, count($modules)));
        $this->assertSame(['queue', 'storefront', 'owner', 'link', 'lock'], array_slice($keys, 4 + count($modules)));

        // The demo after the storefront, whose currency its prices are converted into.
        $keys = array_column($installer->steps(['module_list' => $modules, 'styles' => false, 'demo' => true]), 'key');
        $this->assertSame(['queue', 'storefront', 'demo', 'owner', 'link', 'lock'], array_slice($keys, 4 + count($modules)));
    }

    /**
     * The first live shop was installed with RUB and no country: no default
     * country, no Russian carriers, and a clock on UTC. A currency that IS one
     * country names it, and the country names the clock.
     */
    public function test_a_country_is_read_off_the_currency_and_the_clock_off_the_country(): void
    {
        $answers = [
            'url' => 'https://shop.example.com', 'name' => 'Shop', 'locale' => 'ru', 'currency' => 'RUB',
            'db' => ['host' => '127.0.0.1', 'port' => 5432, 'database' => 'shop', 'username' => 'u', 'password' => ''],
            'modules' => 'minimal', 'owner' => ['email' => 'o@example.com', 'password' => ''],
        ];

        try {
            $settled = (new Installer)->begin($answers);
            $this->assertSame(['RU', 'Europe/Moscow'], [$settled['country'], $settled['timezone']]);

            $settled = (new Installer)->begin(['currency' => 'USD'] + $answers);
            $this->assertSame([null, 'UTC'], [$settled['country'], $settled['timezone']], 'a dollar says nothing about where the shop is');

            $settled = (new Installer)->begin(['country' => 'ua', 'timezone' => 'Asia/Tokyo'] + $answers);
            $this->assertSame(['UA', 'Asia/Tokyo'], [$settled['country'], $settled['timezone']], 'what was said wins');
        } finally {
            @unlink(storage_path(Installer::INSTALLING));
        }
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function badAnswers(): array
    {
        $good = [
            'url' => 'https://shop.example.com', 'name' => 'Shop', 'locale' => 'ru', 'currency' => 'UAH',
            'db' => ['host' => '127.0.0.1', 'port' => 5432, 'database' => 'shop', 'username' => 'u', 'password' => ''],
            'modules' => 'minimal', 'owner' => ['email' => 'o@example.com', 'password' => ''],
        ];

        return [
            'no name' => [array_replace($good, ['name' => ' '])],
            'not a url' => [array_replace($good, ['url' => 'shop.example.com'])],
            'a short password' => [array_replace($good, ['owner' => ['email' => 'o@example.com', 'password' => 'short']])],
            'the install password' => [array_replace($good, ['owner' => ['email' => 'o@example.com', 'password' => 'admin']])],
            'a database name with a quote' => [array_replace_recursive($good, ['db' => ['database' => 'x"; drop']])],
            'an unknown language' => [array_replace($good, ['locale' => 'de'])],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badAnswers')]
    public function test_bad_answers_are_refused_before_anything_is_written(array $answers): void
    {
        try {
            (new Installer)->begin($answers);
            $this->fail('The answers were accepted.');
        } catch (RuntimeException) {
            $this->assertFileDoesNotExist(storage_path(Installer::INSTALLING));
        }
    }

    public function test_the_create_statement_sets_the_icu_locale(): void
    {
        $this->assertSame(
            'CREATE DATABASE "shop" TEMPLATE template0 ENCODING \'UTF8\' LOCALE_PROVIDER icu ICU_LOCALE \'und\' LC_COLLATE \'C.UTF-8\' LC_CTYPE \'C.UTF-8\';',
            Installer::createDatabaseSql('shop'),
        );
        // A Linux server's names first; `und` is a Windows server's answer only.
        $this->assertSame('C.UTF-8', Installer::LIBC_LOCALES[0]);
        $this->assertSame('und', Installer::LIBC_LOCALES[array_key_last(Installer::LIBC_LOCALES)]);
    }
}
