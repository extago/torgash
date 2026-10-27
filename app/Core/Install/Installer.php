<?php

namespace App\Core\Install;

use App\Core\Demo\DemoData;
use App\Core\Modules\ModuleManager;
use App\Core\Support\EnvFile;
use App\Models\Currency;
use App\Models\Storefront;
use Database\Seeders\CoreSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * A shop from an unpacked distribution (PLAN §54): `.env`, the database, the
 * schema, core's rows, the modules, the storefront, the owner, the lock.
 *
 * **Every step may run again.** An install that stopped half way — a
 * dropped connection, a module whose migration failed — is finished by
 * running the installer again with the same answers: a database that exists
 * is used, applied migrations are skipped, installed modules are left alone,
 * the seeder only fills what is missing, the owner is given the password
 * again. That is what makes it safe to offer from a console and from a web
 * page alike, and it is why there is no "undo".
 *
 * **The lock** (`storage/app/installed`) is what says it is done: the
 * console refuses to install over it and the web wizard does not exist
 * while it is there. It is the last step, so a shop is never marked
 * installed without its owner.
 *
 * The console (`torgash:install`) and the web wizard drive the same steps;
 * neither has an install of its own.
 */
class Installer
{
    public const LOCK = 'app/installed';

    /** Written when an install starts, removed by the lock: an install that stopped is not a shop. */
    public const INSTALLING = 'app/installing';

    public const LOCALES = ['en', 'ru', 'uk'];

    /**
     * The country a books currency belongs to, for a shop that named none.
     * Only currencies that ARE one country: a dollar or a euro says nothing.
     * The first live shop was installed with RUB and no country — no default
     * country, no Russian carriers in its set, phones read as nobody's.
     */
    public const COUNTRY_BY_CURRENCY = ['UAH' => 'UA', 'RUB' => 'RU', 'KZT' => 'KZ', 'BYN' => 'BY', 'PLN' => 'PL'];

    /** The clock a shop in that country keeps, unless it said otherwise. */
    public const TIMEZONE_BY_COUNTRY = [
        'UA' => 'Europe/Kyiv', 'RU' => 'Europe/Moscow', 'KZ' => 'Asia/Almaty', 'BY' => 'Europe/Minsk',
        'PL' => 'Europe/Warsaw', 'DE' => 'Europe/Berlin', 'US' => 'America/New_York',
    ];

    /** @var callable(string): void */
    protected $say;

    public function __construct(protected ?string $envPath = null)
    {
        $this->envPath ??= app()->environmentFilePath();
        $this->say = fn () => null;
    }

    /**
     * The lock — or, where there is none, a database that already has
     * modules in it: a shop installed before the lock existed (every one
     * updated from 0.1.x, the developer's machine) must never be offered an
     * install over itself. Such a shop gets its lock written here, so the
     * database is asked once.
     */
    public static function installed(): bool
    {
        if (is_file(storage_path(self::LOCK))) {
            return true;
        }

        // Half way through: its modules are in the database, and running the
        // installer again is how it is finished.
        if (is_file(storage_path(self::INSTALLING))) {
            return false;
        }

        try {
            $found = \Illuminate\Support\Facades\Schema::hasTable('modules') && DB::table('modules')->exists();
        } catch (Throwable) {
            return false;
        }

        if ($found) {
            (new self)->lock(DB::table('modules')->pluck('module_id')->all(), 'found');
        }

        return $found;
    }

    /** What to type when the database user may not create databases — the one statement that gets the locale right. */
    public static function createDatabaseSql(string $name, string $libc = 'C.UTF-8'): string
    {
        return 'CREATE DATABASE "'.str_replace('"', '""', $name).'" TEMPLATE template0 ENCODING \'UTF8\' '
            ."LOCALE_PROVIDER icu ICU_LOCALE 'und' LC_COLLATE '".$libc."' LC_CTYPE '".$libc."';";
    }

    /**
     * What LC_COLLATE / LC_CTYPE may be, tried in this order. They are the
     * OPERATING SYSTEM's locales even when ICU is the provider, and their
     * names differ: a Linux server has `C.UTF-8` (glibc spells it `C.utf8`
     * too) and usually `en_US.UTF-8`, while Windows knows neither and takes
     * `und`. The first `und`-only statement worked on Windows and failed on
     * the first Linux server (2026-10-20). ICU — what folds case and sorts —
     * is `und` whichever is picked; LC_CTYPE also classifies letters for the
     * text search parser and pg_trgm, which `searchWorks()` checks.
     */
    public const LIBC_LOCALES = ['C.UTF-8', 'C.utf8', 'en_US.UTF-8', 'en_US.utf8', 'und'];

    /**
     * The whole install, step after step — what the console does. The web
     * wizard calls `begin()` once and then `step()` one request at a time, so
     * no single request runs longer than one module's migrations.
     *
     * @param  array{
     *     url: string, name: string, locale: string, currency: string, country?: ?string, timezone?: ?string,
     *     db: array{host: string, port: int|string, database: string, username: string, password: ?string},
     *     modules: string|list<string>,
     *     owner: array{email: string, password?: ?string, name?: ?string},
     *     styles?: bool,
     * }  $answers
     * @param  callable(string): void|null  $say
     * @return array{owner_password: ?string, modules: list<string>}  a made-up password is returned once, to be shown
     */
    public function run(array $answers, ?callable $say = null): array
    {
        $this->say = $say ?? fn () => null;
        $answers = $this->begin($answers);

        foreach ($this->steps($answers) as $index => $step) {
            ($this->say)($step['label']);
            $this->step($answers, $index);
        }

        return ['owner_password' => ($answers['owner']['made_up'] ?? false) ? $answers['owner']['password'] : null, 'modules' => $answers['module_list']];
    }

    /**
     * Checks the answers, settles what is decided once (the module list, a
     * made-up owner password) and writes them into the in-progress marker —
     * which is where the web wizard's next request reads them from. The file
     * holds two passwords for the minutes the install takes, readable by the
     * server's user alone, and goes with the lock.
     *
     * @return array<string, mixed> the answers as the steps read them
     */
    public function begin(array $answers): array
    {
        $this->validate($answers);

        $answers['country'] = strtoupper(trim((string) ($answers['country'] ?? '')))
            ?: (self::COUNTRY_BY_CURRENCY[strtoupper((string) $answers['currency'])] ?? null);
        // The shop's clock — order times, letters, "from 18:00" — written into
        // .env, because it is needed before the database can be read.
        $timezone = trim((string) ($answers['timezone'] ?? ''));
        $answers['timezone'] = in_array($timezone, \DateTimeZone::listIdentifiers(), true)
            ? $timezone
            : (self::TIMEZONE_BY_COUNTRY[$answers['country'] ?? ''] ?? 'UTC');
        $answers['module_list'] = $this->resolve($answers['modules'], $answers['country']);

        if (($answers['owner']['password'] ?? '') === '') {
            $answers['owner']['password'] = Str::password(20, symbols: false);
            $answers['owner']['made_up'] = true;
        }

        $marker = storage_path(self::INSTALLING);
        @mkdir(dirname($marker), 0755, true);
        file_put_contents($marker, json_encode(['started_at' => now()->toIso8601String(), 'answers' => $answers], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        @chmod($marker, 0600);

        return $answers;
    }

    /** The answers of the install in progress, or null when there is none. */
    public static function pending(): ?array
    {
        $marker = storage_path(self::INSTALLING);
        $data = is_file($marker) ? json_decode((string) file_get_contents($marker), true) : null;

        return is_array($data['answers'] ?? null) ? $data['answers'] : null;
    }

    /**
     * The install as a list, one module a step.
     *
     * @return list<array{key: string, label: string}>
     */
    public function steps(array $answers): array
    {
        $steps = [
            ['key' => 'env', 'label' => 'Writing .env'],
            ['key' => 'database', 'label' => 'Preparing the database'],
            ['key' => 'migrate', 'label' => 'Creating the tables'],
            ['key' => 'seed', 'label' => 'Filling in languages, currencies and the storefront'],
        ];

        foreach ($answers['module_list'] as $id) {
            $steps[] = ['key' => 'module:'.$id, 'label' => '  '.$id];
        }

        $steps[] = ['key' => 'queue', 'label' => "Doing what the modules queued (the country's towns)"];

        $steps[] = ['key' => 'storefront', 'label' => 'Setting up the storefront'];
        if ($answers['demo'] ?? false) {
            $steps[] = ['key' => 'demo', 'label' => 'Filling the shop with the demo data'];
        }
        $steps[] = ['key' => 'owner', 'label' => 'Creating the owner\'s account'];
        $steps[] = ['key' => 'link', 'label' => 'Linking the public files folder'];
        if ($answers['styles'] ?? true) {
            $steps[] = ['key' => 'styles', 'label' => 'Building the stylesheet'];
        }
        $steps[] = ['key' => 'lock', 'label' => 'Finishing'];

        return $steps;
    }

    /** One step. Any step may run again; every step after the database's points this process at it first. */
    public function step(array $answers, int $index): void
    {
        $key = $this->steps($answers)[$index]['key'] ?? throw new RuntimeException('There is no step '.$index.'.');

        if (! in_array($key, ['env', 'database'], true) && ! $this->connected) {
            $this->useDatabase($answers['db']);
        }

        match (true) {
            $key === 'env' => $this->writeEnv($answers),
            $key === 'database' => $this->prepareDatabase($answers['db']),
            $key === 'migrate' => $this->artisan('migrate', ['--force' => true]),
            $key === 'seed' => $this->seed($answers),
            str_starts_with($key, 'module:') => $this->installModule(substr($key, 7)),
            $key === 'queue' => $this->runQueued(),
            $key === 'storefront' => $this->storefront($answers),
            $key === 'demo' => $this->demo(),
            $key === 'owner' => $this->owner($answers['owner']),
            $key === 'link' => $this->storageLink(),
            $key === 'styles' => $this->styles(),
            $key === 'lock' => $this->finish($answers['module_list']),
        };
    }

    protected bool $connected = false;

    protected function prepareDatabase(array $db): void
    {
        $this->createDatabase($db);
        $this->useDatabase($db);
    }

    /**
     * A bundle's name, or a list of module ids, as the modules to install —
     * dependencies added, dependencies FIRST. Unknown ids are refused rather
     * than skipped: a typo in a list should not produce a shop without its
     * catalogue.
     *
     * A SET also brings the modules made for the shop's country — its
     * carriers and payment gateways (`regions` in a manifest) — and leaves
     * other countries' on the disk, uninstalled: one distribution, every
     * country in it. A list of ids is taken as it is.
     *
     * @param  string|list<string>  $wanted
     * @return list<string>
     */
    public function resolve(string|array $wanted, ?string $country = null): array
    {
        $manager = $this->modules();
        $available = $manager->all();
        $examples = (array) config('torgash.example_modules', []);

        $ids = is_string($wanted) ? $this->expand($wanted) : $wanted;
        if (in_array('*', $ids, true)) {
            $ids = array_values(array_diff(array_keys($available), $examples));
        } elseif (is_string($wanted) && $country !== null) {
            foreach ($available as $id => $manifest) {
                if ($manifest->regions !== [] && $manifest->isFor($country) && ! in_array($id, $examples, true)) {
                    $ids[] = $id;
                }
            }
            $ids = array_values(array_unique($ids));
        }

        $unknown = array_values(array_diff($ids, array_keys($available)));
        if ($unknown !== []) {
            throw new RuntimeException('Unknown modules: '.implode(', ', $unknown).'.');
        }

        $ordered = [];
        $visit = function (string $id, array $path) use (&$visit, &$ordered, $manager, $available) {
            if (in_array($id, $ordered, true)) {
                return;
            }
            if (in_array($id, $path, true)) {
                throw new RuntimeException('Circular module dependency: '.implode(' → ', [...$path, $id]).'.');
            }
            if (! isset($available[$id])) {
                throw new RuntimeException('A module needs '.$id.', which is not here.');
            }

            foreach ($manager->find($id)->requires['modules'] ?? [] as $dependency) {
                $visit($dependency, [...$path, $id]);
            }

            $ordered[] = $id;
        };

        foreach ($ids as $id) {
            $visit($id, []);
        }

        return $ordered;
    }

    /** @return list<string> a bundle's own list, other bundles it names (`@minimal`) expanded */
    protected function expand(string $bundle, array $seen = []): array
    {
        $bundles = (array) config('torgash.bundles', []);
        if (! isset($bundles[$bundle])) {
            throw new RuntimeException('Unknown module set "'.$bundle.'". Known: '.implode(', ', array_keys($bundles)).'.');
        }
        if (in_array($bundle, $seen, true)) {
            throw new RuntimeException('Module sets name each other in a circle: '.$bundle.'.');
        }

        $ids = [];
        foreach ($bundles[$bundle] as $entry) {
            $ids = [...$ids, ...(str_starts_with($entry, '@') ? $this->expand(substr($entry, 1), [...$seen, $bundle]) : [$entry])];
        }

        return array_values(array_unique($ids));
    }

    /** What both doors refuse before anything is written — in words the web wizard shows as they are. */
    protected function validate(array $answers): void
    {
        $password = (string) ($answers['owner']['password'] ?? '');

        $problem = match (true) {
            trim((string) ($answers['name'] ?? '')) === '' => __('Give the shop a name.'),
            ! in_array($answers['locale'] ?? '', self::LOCALES, true) => __('The storefront language must be one of: :list.', ['list' => implode(', ', self::LOCALES)]),
            ! filter_var($answers['url'] ?? '', FILTER_VALIDATE_URL) || ! in_array(parse_url((string) $answers['url'], PHP_URL_SCHEME), ['http', 'https'], true) => __("The shop's address must be a full URL, like https://shop.example.com."),
            ! filter_var($answers['owner']['email'] ?? '', FILTER_VALIDATE_EMAIL) => __("The owner's email is not an email address."),
            $password !== '' && (mb_strlen($password) < 10 || $password === \App\Core\Access\InstallPassword::PASSWORD) => __("The owner's password needs at least 10 characters — or leave it empty to have one made up."),
            ! preg_match('/^[A-Z]{3}$/', (string) ($answers['currency'] ?? '')) => __('The currency is a three-letter code, like UAH or USD.'),
            ! preg_match('/^([A-Za-z]{2})?$/', trim((string) ($answers['country'] ?? ''))) => __('The country is a two-letter code, like UA, or nothing.'),
            trim((string) ($answers['timezone'] ?? '')) !== '' && ! in_array(trim((string) $answers['timezone']), \DateTimeZone::listIdentifiers(), true) => __('The time zone is a name like Europe/Moscow.'),
            ! preg_match('/^[A-Za-z0-9_]+$/', (string) ($answers['db']['database'] ?? '')) => __('The database name may hold Latin letters, digits and underscores only.'),
            default => null,
        };

        if ($problem !== null) {
            throw new RuntimeException($problem);
        }
    }

    /**
     * From the shipped `.env.example` the first time, then key by key — an
     * `.env` somebody already edited keeps every line it was not asked about.
     * The application key is made once and never replaced: it encrypts what
     * is already stored.
     */
    protected function writeEnv(array $answers): void
    {
        if (! is_file($this->envPath)) {
            $example = base_path('.env.example');
            if (! @copy($example, $this->envPath)) {
                throw new RuntimeException('Cannot create '.$this->envPath.' — is the folder writable?');
            }
        }

        $env = new EnvFile($this->envPath);
        $key = (string) $env->get('APP_KEY');
        if ($key === '') {
            $key = 'base64:'.base64_encode(random_bytes(32));
        }

        $db = $answers['db'];
        $env->set([
            'APP_NAME' => $answers['name'],
            'APP_ENV' => 'production',
            'APP_KEY' => $key,
            'APP_DEBUG' => 'false',
            'APP_URL' => rtrim($answers['url'], '/'),
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => $db['host'],
            'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => $db['database'],
            'DB_USERNAME' => $db['username'],
            'DB_PASSWORD' => (string) ($db['password'] ?? ''),
            // The shop's language is the admin's starting language and the one
            // its content is written in first; the translator's own fallback
            // (APP_FALLBACK_LOCALE) stays English — see content_base_locale().
            'APP_LOCALE' => $answers['locale'],
            'TORGASH_CONTENT_LOCALE' => $answers['locale'],
            'APP_TIMEZONE' => $answers['timezone'] ?? 'UTC',
        ]);

        // This process booted before the file existed; it goes on with what
        // it has just written.
        config([
            'app.key' => $key,
            'app.url' => rtrim($answers['url'], '/'),
            'app.name' => $answers['name'],
            'app.locale' => $answers['locale'],
            'torgash.content_locale' => $answers['locale'],
            'app.timezone' => $answers['timezone'] ?? 'UTC',
        ]);
        date_default_timezone_set($answers['timezone'] ?? 'UTC');
    }

    protected function createDatabase(array $db): void
    {
        try {
            $pdo = ServerCheck::connect($db, 'postgres');
        } catch (Throwable) {
            // No way into `postgres`: the database has to exist already, and
            // connecting to it below says so if it does not.
            return;
        }

        $exists = $pdo->prepare('select 1 from pg_database where datname = ?');
        $exists->execute([$db['database']]);

        if ($exists->fetchColumn()) {
            return;
        }

        $refusals = [];
        foreach (self::LIBC_LOCALES as $libc) {
            try {
                $pdo->exec(self::createDatabaseSql($db['database'], $libc));

                return;
            } catch (\PDOException $e) {
                // Only "this server has no such locale" moves on to the next
                // name; anything else (no right to create, a name taken) is
                // the answer.
                if (! preg_match('/locale|collation|LC_C/i', $e->getMessage())) {
                    throw $e;
                }
                $refusals[] = $libc;
            }
        }

        throw new RuntimeException('The database server knows none of the locales '.implode(', ', $refusals).'. Create the database yourself with a UTF-8 locale it has: '.self::createDatabaseSql($db['database'], '<a UTF-8 locale of this server, see: locale -a>'));
    }

    /**
     * Russian search, asked of the database itself before the shop is marked
     * installed — the failures this catches give no error anywhere else:
     * case folding (ICU), ILIKE, the text search parser and pg_trgm (both
     * classify letters by LC_CTYPE). A trigram check needs the extension a
     * module brings, so it runs only when that module is installed.
     */
    protected function searchWorks(): void
    {
        $row = DB::selectOne(
            "select lower('ЖЁЛТЫЙ') = 'жёлтый' as lower, 'Ноутбук' ilike 'НОУТ%' as ilike,
                    to_tsvector('simple', 'Ноутбук') @@ to_tsquery('simple', 'ноутбук') as words"
        );

        $broken = array_keys(array_filter(['lower()' => ! $row->lower, 'ILIKE' => ! $row->ilike, 'to_tsvector' => ! $row->words]));

        if (DB::selectOne("select exists(select 1 from pg_extension where extname = 'pg_trgm') as ok")->ok
            && ! DB::selectOne("select similarity('Ноутбук', 'ноутбук') > 0.9 as ok")->ok) {
            $broken[] = 'pg_trgm';
        }

        if ($broken !== []) {
            $info = DB::selectOne('select datcollate, datctype, datlocprovider::text as provider from pg_database where datname = current_database()');

            throw new RuntimeException('Russian search would not work in this database ('.implode(', ', $broken).' do not treat Cyrillic as letters; LC_CTYPE is '.$info->datctype.'). Create it again with another UTF-8 locale of the server: '.self::createDatabaseSql((string) DB::connection()->getDatabaseName(), '<a UTF-8 locale from: locale -a>'));
        }
    }

    protected function useDatabase(array $db): void
    {
        config(['database.default' => 'pgsql', 'database.connections.pgsql' => array_merge(config('database.connections.pgsql'), [
            'host' => $db['host'],
            'port' => (int) $db['port'],
            'database' => $db['database'],
            'username' => $db['username'],
            'password' => (string) ($db['password'] ?? ''),
        ])]);
        DB::purge('pgsql');

        // Anything resolved at boot holds the connection the process started
        // with — on a fresh server, the framework's default sqlite file that
        // does not exist. The install's own cache is memory; the permission
        // registrar, which grabbed a cache store when it was made, is given
        // the new one.
        config(['cache.default' => 'array']);
        app('cache')->forgetDriver(array_keys((array) config('cache.stores')));
        app(\Spatie\Permission\PermissionRegistrar::class)->initializeCache();

        $this->connected = true;

        // The one check that matters most and fails silently otherwise.
        if (! DB::selectOne("select lower('Ж') = 'ж' as ok")->ok) {
            throw new RuntimeException('The database "'.$db['database'].'" was created with the C locale and would break Russian search. Drop it and run the installer again, or create it yourself: '.self::createDatabaseSql($db['database']));
        }
    }

    /** The last step: the search check, then the lock — never a lock over a shop whose search is broken. */
    protected function finish(array $modules): void
    {
        $this->searchWorks();
        $this->lock($modules);
    }

    /**
     * Core's own rows, then the shop's country (how a phone typed without
     * its code is read, where the town window opens) — written BEFORE the modules,
     * because a module's install() may act on it (torgash.locations loads the
     * country's towns).
     */
    protected function seed(array $answers): void
    {
        $this->artisan('db:seed', ['--class' => CoreSeeder::class, '--force' => true]);

        if (! empty($answers['country'])) {
            app(\App\Core\Settings\SettingsManager::class)->set(\App\Core\Settings\CoreSettings::ID, 'default_country', $answers['country']);
        }
    }

    /**
     * What the modules' install() put on the queue, done now: nothing
     * supervises a worker yet on a shop that has just been unpacked, and the
     * owner's first look at the admin should not be at a country with no
     * towns. Not fatal — the queue keeps it for the worker or the cron line.
     */
    protected function runQueued(): void
    {
        try {
            $this->artisan('queue:work', ['--stop-when-empty' => true, '--tries' => 1, '--timeout' => 3600]);
        } catch (Throwable $e) {
            ($this->say)('  the queued work did not finish ('.$e->getMessage().') — the queue worker will do it');
        }
    }

    protected function installModule(string $id): void
    {
        $manager = $this->modules();

        if (! $manager->isInstalled($id)) {
            $manager->install($id);
        }
    }

    /**
     * The storefront the seeder made, made the owner's: its name, its
     * address, its language — and its currency, which is the shop's books
     * currency. Every other rate is restated against it so it is the one
     * whose rate is 1 (StorefrontsController refuses any other as the
     * default). Done only while the shop is empty: there is no stored price
     * a re-basing could change the meaning of.
     */
    protected function storefront(array $answers): void
    {
        $storefront = Storefront::where('is_default', true)->first() ?? Storefront::firstOrFail();
        $host = strtolower((string) parse_url($answers['url'], PHP_URL_HOST));
        $port = parse_url($answers['url'], PHP_URL_PORT);
        $domain = $host.($port ? ':'.$port : '');

        $currency = Currency::firstOrCreate(
            ['code' => $answers['currency']],
            ['symbol' => $answers['currency'], 'name' => $answers['currency'], 'rate' => 1, 'decimals' => 2, 'sort' => 0],
        );

        if ((float) $currency->rate !== 1.0) {
            $base = (float) $currency->rate;
            foreach (Currency::all() as $other) {
                $other->update(['rate' => round((float) $other->rate / $base, 6)]);
            }
        }

        $currency->update(['enabled' => true]);

        // The settings screen shows the clock .env was given.
        if (! empty($answers['timezone']) && $answers['timezone'] !== 'UTC') {
            app(\App\Core\Settings\SettingsManager::class)->set(\App\Core\Settings\CoreSettings::ID, 'timezone', $answers['timezone']);
        }

        // The shop's language is the base its content is written in — the
        // Languages screen shows it and the owner may change it there.
        app(\App\Core\Settings\SettingsManager::class)->set(\App\Core\Settings\CoreSettings::ID, 'content_locale', $answers['locale']);

        $storefront->update([
            'name' => $answers['name'],
            'domains' => array_values(array_unique(array_filter([$domain, str_starts_with($host, 'www.') || filter_var($host, FILTER_VALIDATE_IP) ? null : 'www.'.$domain]))),
            'default_locale' => $answers['locale'],
            'locales' => array_values(array_unique([$answers['locale'], ...($storefront->locales ?? [])])),
            'default_currency' => $currency->code,
            'currencies' => array_values(array_unique([$currency->code, ...array_intersect($storefront->currencies ?? [], Currency::pluck('code')->all())])),
        ]);
    }

    /**
     * The demo shop (PLAN §55), after the storefront — its prices are
     * converted into the books currency that step settles. Its parts are
     * declared in the modules' boot(), and the modules this process installed
     * never booted, so they are registered first (one already registered is
     * left as it is). Not fatal: a shop without its demo still works, and
     * `php artisan torgash:demo install` puts it in later.
     */
    protected function demo(): void
    {
        try {
            $this->modules()->registerEnabled();
            // Routes added after boot are not in the by-name index until it is
            // rebuilt: the menus part asked for `pages.page` and was told there
            // is no such route.
            app('router')->getRoutes()->refreshNameLookups();
            app('router')->getRoutes()->refreshActionLookups();
            $parts = app(DemoData::class)->install(fn (string $line) => ($this->say)('  '.$line));
            ($this->say)('  demo installed: '.implode(', ', $parts));
        } catch (Throwable $e) {
            // Half a demo is taken out again, so the command starts clean.
            rescue(fn () => app(DemoData::class)->remove(), report: false);
            ($this->say)('  the demo data was not installed ('.$e->getMessage().') — php artisan torgash:demo install puts it in');
        }
    }

    protected function owner(array $owner): void
    {
        $this->artisan('torgash:admin', array_filter([
            'email' => $owner['email'],
            '--name' => $owner['name'] ?? null,
            '--password' => $owner['password'],
        ]));
    }

    /** `public/storage` → `storage/app/public`; a host that forbids links is told so, not stopped. */
    protected function storageLink(): void
    {
        if (file_exists(public_path('storage'))) {
            return;
        }

        try {
            $this->artisan('storage:link');
        } catch (Throwable $e) {
            ($this->say)('  could not link public/storage ('.$e->getMessage().') — pictures will not show until it exists');
        }
    }

    /** The shipped stylesheet already works; a rebuilt one carries the shop's modules exactly. Not fatal. */
    protected function styles(): void
    {
        try {
            $this->artisan('torgash:styles', ['--fetch' => true]);
        } catch (Throwable $e) {
            ($this->say)('  the stylesheet was not rebuilt ('.$e->getMessage().') — the shipped one is used; System → Stylesheet rebuilds it');
        }
    }

    /**
     * @param  list<string>  $modules
     * @param  string  $how  `installed` by this installer, or `found` already in place
     */
    protected function lock(array $modules, string $how = 'installed'): void
    {
        $path = storage_path(self::LOCK);
        @mkdir(dirname($path), 0755, true);
        @file_put_contents($path, json_encode([
            'version' => config('torgash.version'),
            $how.'_at' => now()->toIso8601String(),
            'modules' => array_values($modules),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        @unlink(storage_path(self::INSTALLING));
    }

    protected function artisan(string $command, array $parameters = []): void
    {
        $code = Artisan::call($command, $parameters);
        if ($code !== 0) {
            throw new RuntimeException($command.' failed: '.trim(Artisan::output()));
        }
    }

    /** A fresh manager each time: the container's read the module table when there was none. */
    protected function modules(): ModuleManager
    {
        return new ModuleManager(app());
    }
}
