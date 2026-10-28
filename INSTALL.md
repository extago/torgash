# Installing Torgash

This folder is a whole shop: the engine, its modules, its theme and the PHP
libraries (`vendor/`), so the server needs no Composer and no Node.

## What the server needs

- **PHP 8.5** with the extensions `pdo_pgsql mbstring intl gd fileinfo openssl
  curl zip bcmath dom xml iconv` (recommended: `opcache sodium exif`, and
  `redis` if you use Redis or Valkey later), and `proc_open()` allowed.
- **PostgreSQL 15 or newer** (Torgash is built and tested on 18).
- A web server that hands PHP to PHP-FPM — Caddy, nginx or Apache.
- **cron** and a way to keep one process running (systemd, supervisor).

The installer checks all of it before it writes anything:

    php artisan torgash:install --check --db-username=torgash --db-password=…

## Ubuntu 24.04, step by step

```bash
# PHP 8.5 (ondrej PPA), PostgreSQL 18 (PGDG), Caddy
sudo add-apt-repository ppa:ondrej/php -y
sudo apt install -y php8.5-fpm php8.5-cli php8.5-pgsql php8.5-mbstring php8.5-intl \
  php8.5-gd php8.5-curl php8.5-zip php8.5-bcmath php8.5-xml php8.5-opcache unzip
sudo apt install -y postgresql-common && sudo /usr/share/postgresql-common/pgdg/apt.postgresql.org.sh -y
sudo apt install -y postgresql-18
sudo apt install -y caddy   # see caddyserver.com/docs/install for the repository

# The files
sudo mkdir -p /var/www && cd /var/www
sudo unzip torgash-X.Y.Z-install.zip && sudo mv torgash-X.Y.Z torgash
sudo chown -R www-data:www-data /var/www/torgash

# A database user that may create the shop's database
sudo -u postgres createuser --createdb --pwprompt torgash
```

### Install

**In a browser**: point the domain at the folder (below), open it, and the
wizard at `/install` does everything the command does. It asks for the code
in `storage/app/install-key` first — whoever can read the server's files is
the owner — and installs one step per request, so a host's time limit never
cuts it in half.

**From the console**:

```bash
cd /var/www/torgash
sudo -u www-data php artisan torgash:install \
  --url=https://shop.example.com --name="My shop" --locale=ru --currency=UAH --country=UA \
  --db-username=torgash --db-password='…' --db-database=torgash \
  --modules=store --owner=you@example.com
```

Left out, every answer is asked. `--modules` is `minimal`, `store` (the
default) or `all`, or module ids separated by commas. `--country` adds that
country's delivery services and payment gateways to the set; other countries'
modules stay in the folder, uninstalled, and can be installed or deleted later. Without
`--owner-password` a password is made up and shown once. `--demo` fills
the shop with the demo data (about sixty products with prices, sales, a
coupon, reviews, pages, a blog, the home page's slider); Admin → System
removes it in one go, leaving what you added yourself.

With `--country`, the shop also gets the world's list of countries (only its
own switched on) and, when a pack for that country ships in `data/geo`
(Ukraine and Russia do), its regions and towns — built from OpenStreetMap,
ODbL 1.0, "© OpenStreetMap contributors". Other countries: Admin →
Locations → Import says where a pack comes from.

If something fails, fix it and run the same command again: the installer goes
on from where it stopped. Over a finished install it refuses to run.

**The database locale matters.** The installer creates the database with the
ICU locale `und` and checks Russian search before it finishes. If you create
it yourself, do it like this — under the `C` locale PostgreSQL does not treat
Cyrillic as letters and search half-works with no error anywhere:

```sql
CREATE DATABASE torgash TEMPLATE template0 ENCODING 'UTF8'
  LOCALE_PROVIDER icu ICU_LOCALE 'und' LC_COLLATE 'C.UTF-8' LC_CTYPE 'C.UTF-8';
```

`LC_COLLATE`/`LC_CTYPE` are the operating system's locale names (`locale -a`
lists them): `C.UTF-8` on Linux, or `en_US.UTF-8`. Never plain `C`.

### The web server (Caddy)

`/etc/caddy/Caddyfile`:

```
shop.example.com {
    root * /var/www/torgash/public
    encode zstd gzip
    php_fastcgi unix//run/php/php8.5-fpm.sock
    file_server
}
```

`sudo systemctl reload caddy` — Caddy gets the HTTPS certificate itself.
Do not let the web server replace PHP's error pages (no `handle_response` on
5xx): the shop draws its own, and a closed storefront answers 503 on purpose.

### Background work

Letters, imports and nightly jobs need two things running:

```bash
# every minute — the scheduler
echo '* * * * * www-data cd /var/www/torgash && php artisan schedule:run >> /dev/null 2>&1' \
  | sudo tee /etc/cron.d/torgash

# all the time — the queue workers: one unit, as many copies as you like
sudo tee /etc/systemd/system/torgash-queue@.service > /dev/null <<'EOF'
[Unit]
Description=Torgash queue worker %i
After=network.target postgresql.service

[Service]
User=www-data
WorkingDirectory=/var/www/torgash
ExecStart=/usr/bin/php artisan queue:work --tries=3 --max-time=3600
Restart=always
# A stop waits for the job in hand: an import cut mid-row is worse than a slow stop.
TimeoutStopSec=3700

[Install]
WantedBy=multi-user.target
EOF
sudo systemctl enable --now torgash-queue@{1..4}
```

Without a worker of its own the scheduler starts a short one every minute,
so on a host with no long-running processes the cron line alone is enough.
With the systemd service above, tell the scheduler not to: `QUEUE_WORKER=external`
in `.env`.

Why four: an import fetches its photos in four lanes at once, and each lane
needs a worker of its own — with one, they wait for each other (a catalogue
of 3,000 photos takes hours instead of minutes). One copy is enough for a
small shop; more than the server has cores buys nothing. An update restarts
every copy by itself (`queue:restart`); by hand: `systemctl restart 'torgash-queue@*'`.

Admin → System says whether both are alive.

**PHP inside FrankenPHP** (or any server where `php` is a wrapper): set the
environment variable `PHP_BINARY=/usr/local/bin/php` — the path of a real PHP
command line — for the cron line and every service. The scheduler starts its
jobs with it, and so do updates and their rollback.

## Afterwards

- **Updates** come from Admin → System → Updates: a signed package, a backup
  first, rolled back by itself if anything fails.
- **Mail, payments, delivery** are set in the admin.
- **Backups**: Admin → System → Backups; restoring is done from the console,
  `php artisan torgash:backup-restore`.
- A forgotten owner password: `php artisan torgash:admin you@example.com`.

## Working from the console

For scripts (`php artisan tinker`) and whoever runs them on a live shop:

- **Run them as the shop's user** — `sudo -u www-data php artisan …`, as
  above. What a command run as root writes (a log, a cache, an import's copy
  of its file) is a file the shop cannot change afterwards.
- **Many products at once is an import**, not a script: names, addresses,
  descriptions, categories, prices, stock. Export the columns, change them,
  bring the file back — on the admin's Import screen or with
  `php artisan exim:import file.csv --dry-run` (then without `--dry-run`).
  The Import / Export module's help says how.
- **Write through the models**, not `DB::table(...)->update()`: codes,
  addresses and their redirects, the audit trail and the search index are
  kept by the models, and a query-builder update walks past all of them.
- **Products have no hidden filter** besides the bin: `Product::query()`
  finds every product that is not deleted, so `withoutGlobalScopes()` is not
  needed — it only adds the deleted ones, as `withTrashed()` does. What a
  visitor is shown is narrower: `Product::listed()`.
- **Changed files by hand?** `php artisan torgash:refresh` drops the caches
  made from code, rebuilds the stylesheet and restarts the workers. Updates
  and module changes from the admin do this themselves.
- **FrankenPHP in worker mode**: after replacing the shop's folder (copied
  over, or a new release moved into place) restart the service —
  `systemctl restart frankenphp`, or whatever yours is called — rather than
  reloading it. A reload restarts the workers inside the running process,
  which can go on serving the old files from memory.
