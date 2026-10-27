# Infrastructure: files, cache, speed, background tasks

Architecture rules moved out of CLAUDE.md (2026-09-30), verbatim. CLAUDE.md keeps a one-line index;
this file is the reasoning. Read it before changing anything it covers.

- **The shop's files live on two disks, `public` and `private`, and each is
  a local folder or an S3-compatible bucket** (config/filesystems.php, chosen
  by `TORGASH_STORAGE` in .env with the AWS_ keys — never in the settings
  table, a secret there is in every dump). The disk NAMES never change,
  because media rows store them. **Nothing reads a stored file by its local
  path**: a library that needs a real file gets a LOCAL COPY from
  `App\Core\Files\FileStorage` (`mediaCopy()` / `localCopy()` → work →
  `putBack()` → `release()`), a private file is streamed with
  `Storage::disk(...)->response()`, a folder is listed through the disk — one
  code path for both, so the suite on a folder proves the bucket's code too.
  `private` holds what a route guards (enquiry attachments, option files);
  `local` stays the engine's own scratch (exports, imports, exchanges) on the
  server whatever is chosen. Moving is `php artisan torgash:files-copy
  --to=s3|local` (copies, resumable, the private side only what a row knows
  about) BEFORE switching; Admin → File storage shows where, how much and a
  write-read-delete check, which the guard states too
  (`core.file_storage_answers`).
- **Where the cache and sessions live is chosen on Admin → Cache and
  sessions** (`/admin/cache`, core's, `cache.manage`; `App\Core\Performance\
  CacheSetup`) — a folder, the database or Redis — and WRITTEN TO .env
  (`App\Core\Support\EnvFile`, named keys only, the rest of the file
  untouched), because the cache is needed before settings can be read and the
  Redis password is a secret. A choice is saved only after it was written to
  and read back AS TYPED. "Redis" always means Redis with the database behind
  it (`redis-fallback`, `FallbackStore`): a Redis that stops costs one timeout
  and is then skipped for 30 s, the outage is recorded in
  storage/framework/cache (`FallbackState`, never in the cache it is about),
  and the guard's `core.cache_answers` says so — the shop slows, never stops.
  Cache is Redis db 1, sessions db 2, so "Clear the cache" signs nobody out.
  "Redis" means any server speaking its protocol — Redis or Valkey (the
  Linux Foundation fork, BSD, what the clouds now offer): the engine uses
  only commands both have, never a feature of one of them. Valkey goes on
  reporting `redis_version:7.2.4`, so the screen names the server by
  `CacheSetup::serverName()` (`server_name` / `valkey_version`).
  Moving sessions hands the current one to the new handler in the same request
  so the admin stays signed in; everyone else starts over, and the form says
  so. The speed module only DISPLAYS the choice and links here.
- **Speed is measured by `torgash.speed`, through one core seam:**
  `HookBus::observe(HookObserver)` — `enter()`/`leave()` around every listener
  call (in a `finally`), null when nobody listens, which is the whole cost of an
  unmeasured request. A listener's module is the FILE its callable was written
  in (`Torgash\Speed\Attribution`). A request is ARMED at boot / on `request`
  rebinding and DECIDED at the route match — `setting()` is not readable at
  provider boot, and the container rebinds `request` inside one request, so
  deciding at either measured nothing or measured from the middle. Under tests
  it measures only when `torgash.speed.measure_in_tests` is set, or the random
  sample would make query-counting tests flaky. The dashboard is extensible
  through `core.admin.dashboard` (HTML).
A tracked task extends `App\Core\Tasks\TrackedJob`: implement `label()` (English
source string) and `run()`, report with `total()` / `progress()` /
`message($key, $params)` / `result()`, and start it with `SomeJob::start(...)`,
which writes the `background_tasks` row and pushes the job. `SomeJob::startIfIdle(...)`
is the same but no-ops when an instance is already queued or running (keyed by
`label()`+`module()`) — for jobs the scheduler re-runs so a nightly copy does
not stack on one still going. Message strings are stored as a KEY plus params,
never a finished sentence: the worker runs in the app's default locale and the
admin reading the list may be in another one. Progress written from inside a DB
transaction only becomes visible when that transaction commits (`RunImport`
documents why it keeps one anyway).
A job over a library that only grows never relies on finishing inside
`$timeout`: it checks `timeIsUp()` (`$budget`, 45 min) between items and hands
the rest to `continueWith(new static($lastId, …))` — the same row, done only
after the last part (`RebuildThumbnails`; 3105 images took 28 min live, a
bigger run hit `TimeoutExceededException`).
A RUNNING row nobody has written to for longer than any job may run
(`BackgroundTask::STALE_AFTER_SECONDS`) is a task whose worker died: the
screen and the bell say so, and `startIfIdle()` writes such rows off first
(`Tasks::reapStale()`) — before that, one crash stopped a nightly job from
ever being scheduled again. Stopping a task is `Tasks::cancel()`: a queued or
dead one is cancelled now (its job taken off the queue), a live one is ASKED
(`cancel_requested_at`) and stops itself at its next `progress()` —
`stopIfAsked()` for a job that reports none. Cancelled is its own status, not
a failure, and never reaches failed_jobs.
**A job's `$timeout` stays under every queue's `retry_after`** (3700 s in
`config/queue.php`; `QueueTimeoutTest` reads every queued class in core and the
modules). `retry_after` is how long a reserved job may run before the queue
hands it to another worker as abandoned: with the framework's 90 s, an import
running for two minutes was started a second time by another worker and, with
one try, failed as "attempted too many times" (the Nova Poshta warehouse sync,
2026-10-06). The price of the long value is that a job whose worker was killed
outright waits that long to be retried.

Periodic work: the framework scheduler is wired in exactly one place,
`CoreServiceProvider::registerSchedule()`. A module adds its own by listening on
the `core.schedule` hook and calling methods on the `Schedule` it is handed
(`$context['schedule']`) — same shape as adding routes or a menu item, no
patching of core. See `NovaPoshtaServiceProvider::schedule()` (nightly branch
top-up, weekly directory refresh, both gated on the API key) and
`EximServiceProvider` (`exim:rebuilds` every minute: export files due by their
cron or by a change of their data go to the queue). Nothing fires until a
cron runs `php.exe artisan schedule:run` every minute — on Windows/OSPanel that
is a Task Scheduler entry, and it is a deployment step the project still owns
(dev sessions just run the jobs from their admin buttons). `schedule:list` shows
what is registered.

Admin sees all of it under System → Background tasks, together with failed
queue jobs — that list is the only place an undeliverable order email surfaces,
and it can retry or delete them. The page polls only while something is active,
and warns when a task has been queued for a while (i.e. no worker is running).

## System page and backups (PLAN §45)

**Admin → System** (`/admin/system`, core's, `system.manage`) COLLECTS rather
than duplicates: versions (`config('torgash.version')`, Laravel, PHP, Postgres),
the web PHP's limits and extensions, the database's size, largest tables and
LOCALE (it says plainly when Cyrillic is not folded — the C-locale trap), free
disk, and whether background work moves: the scheduler writes a pulse every
minute (`core:heartbeat` → `SystemFacts::HEARTBEAT` in the cache), and the age
of the oldest waiting job says whether a worker runs. Warnings lead the page as
sentences. `composer audit` runs as a tracked task (`AuditDependencies`, it is
network) and keeps its finding as the core setting `dependency_audit`, not in
the cache "Clear the cache" would empty. Updates are not here: there is no
update server yet.

**A backup is one zip** (`App\Core\Backups\Backups`): `database.dump`
(`pg_dump -Fc`), `files/public|private/…` while files are a local folder (a
bucket is not copied; scratch folders in `Backups::SKIP` never are), optionally
`code/modules|themes/…`, and `manifest.json`. Never in it: `.env` (secrets —
the screen says keep it apart) and `vendor/`. Kept on `local` (the server) or
`private` (the bucket under S3) — never `public`. Named
`torgash-YYYY-MM-DD-HHMMSS.zip` and reachable ONLY by a name matching that
pattern. Made by the button or the schedule (`CreateBackup`, `startIfIdle`),
pruned to `backup_keep`. pg_dump/pg_restore come from `TORGASH_PG_BIN` (.env;
empty = PATH). **The way back is the console**, `torgash:backup-restore` (no
name lists them; `--database=` restores into another, existing database to
check a backup without touching the shop) — an update that broke the admin
shuts the door a button would be behind. Verified by restoring a real backup
into a scratch database and comparing row counts.

**The stylesheet is built on the shop's own server** (PLAN §45.2,
`App\Core\Assets\StyleBuilder`, `php artisan torgash:styles [--fetch]`, the
System page's "Rebuild"). It holds only the classes some template uses (core,
every theme, every module — the `@source` lines), so a module or an
overridden template bringing a new class is drawn without it until the file
is built again. The builder is tailwind-cli-extra (MIT), PINNED in
`config/torgash.php` → `styles`: the release that built the shipped file and a
sha256 per platform; one downloaded by `--fetch` is checked before it is made
executable and deleted when it does not match. Looked for in
`TORGASH_STYLE_BUILDER` → `tools/` → `storage/app/tools/`. The new file is
written beside the old and moved over it — a page never loads half a
stylesheet, a failed build leaves the last good one. The last outcome is the
core setting `style_build`. NB `config()` reads a dot as nesting, so the sums
are read as an array (`windows-x64.exe` has a dot).

**Every file's address carries its version** (PLAN §45.3): `asset_url()`,
`module_asset()`, `theme_asset()` add `?v=` (short hash of mtime + size,
`App\Core\Assets\AssetVersions`, scoped). Module and theme files are kept a
year (`immutable`) ONLY under the address naming their current version;
without `v` or with an old one they are `no-cache` (revalidated, 304). Never
write `/assets/…` or `route('module.asset', …)` in a template —
`AssetVersionsTest` fails on it (a base address a script appends names to,
built with `dirname()`, is the one exception).

**After code changes, one procedure** (PLAN §45.4,
`App\Core\System\CodeChanged::refresh()`, by hand `torgash:refresh`): drop
the caches made FROM code (config, routes, events, compiled views — never the
application cache, which holds data such as sign-in pauses), `opcache_reset()`
where this process reaches it, `queue:restart` (a worker keeps the code it
booted with), rebuild the stylesheet. Each step on its own — one failing does
not stop the rest. Run by core's listener on `core.module.changed`
(install, update from a zip, uninstall, enable, disable). Off in the test
suite (`TORGASH_REFRESH_ON_CHANGE=false` in phpunit.xml).

**`requires.core` is enforced** (PLAN §45.5, `ModuleManifest::fitsCore()`,
composer/semver; an unreadable constraint does NOT fit): refused at install,
enable and zip upload (before anything on disk moves). A module switched on
in the table that no longer fits — the core was rolled back — is HELD BACK:
`ModuleManager::isEnabled()` says no, so it is not registered, and so is
everything that needs it (`blocked()` is transitive: their code calls classes
that are not loaded). The modules screen says why. One module that does not
fit never takes the shop down.

**A module's migrations land all together or not at all**
(`ModuleManager::migrate()`, install and `upgrade()`): one transaction around
the whole `migrate --path` run, each migration's own becoming a savepoint —
Postgres runs DDL inside a transaction. Without it the second of three
failing left the first applied: the install could not be retried (its table
was there), and `ModuleArchive` put the old folder back over half of the new
version's schema. So a module migration must be able to run inside a
transaction (no `CREATE INDEX CONCURRENTLY`, no `$withinTransaction = false`).

**Jobs whose code is gone** (PLAN §45.6, `App\Core\Tasks\OrphanJobs`): an
uninstalled module takes its WAITING jobs with it (by the namespaces its
manifest autoloads); failed jobs whose class is nowhere — every module's
autoload registered first, so a switched-off module's are not counted — are
counted on the System page and cleared by its button or
`torgash:jobs-prune-missing`. A job of a merely DISABLED module is left alone.

**Core updates are signed packages** (PLAN §45.7, `App\Core\Updates`).
- A release OWNS exact paths (`Distribution`): `app config database lang
  resources routes vendor data/geo public/assets`, `artisan`,
  `composer.json/lock`, `bootstrap/app.php|providers.php`,
  `public/index.php|.htaccess|favicon.ico`, every bundled module (no
  `author`) and the engine's theme (no author or author "Torgash"). `.env`,
  `storage`, `tools/`, `public/storage`, third-party modules and themes are
  never touched. A package that claims another path, or names a file outside
  its paths, is refused (`Distribution::ownable/safePath`).
- Built where the secret key is (`torgash:release`, key from
  `TORGASH_RELEASE_KEY`, made by `torgash:release-key`; the dev key lives in
  `D:\WebDev\openserver3\home\keys\`, NEVER in the repository): git-tracked
  files of a clean tree + `composer install --no-dev` in a scratch copy →
  `storage/app/releases/torgash-<version>.zip` = `release.json` (paths + sha256
  of every file) + `release.sig` (Ed25519 over release.json's bytes) +
  `files/…`. The shop trusts `config('torgash.updates.public_keys')` — a list,
  so a key is changed by one release that ships both.
- Installed by `torgash:update <zip>` (`Updater`): signature → forwards only
  and PHP fits → DB backup → unpack, every file against its sum → copy
  `rollback.php.stub` to `storage/app/update/rollback.php` → `down --secret`
  → swap path by path, each written into `plan.json` BEFORE it moves (old →
  `update/<v>/previous/`) → `torgash:update-finish` in a NEW process on the
  new code (drop bootstrap caches, `package:discover`, migrate, `upgrade()` of
  installed bundled modules, `CodeChanged::refresh()`, `up`). Anything failing
  after the first move runs the rollback script itself.
- **The way back is a plain-PHP file**: `php storage/app/update/rollback.php
  <plan.json>` puts back exactly the paths the plan says were started, then
  lets the OLD code `torgash:backup-restore <backup> --no-files --force` and
  `up`. Twice is harmless (`state: rolled back`). `torgash:update-rollback`
  runs the same script. Only the last run's previous version is kept.
- `UpdaterTest` runs all of it on a throwaway install in a temp folder. Not
  yet exercised end to end on a real second install — that is the first job
  of the update server (§45.8).

**The update delivery service** (PLAN §45.8) lives OUTSIDE this repository:
`D:\WebDev\openserver3\home\extago.local\uds\torgash` (plain PHP, no
framework, README there), to be `https://extago.com/uds/torgash/`. It is
trusted with availability only — it stores and hands out packages signed
elsewhere and checks their signature itself before offering one; a published
version is never replaced. Shop side: `App\Core\Updates\UpdateServer`
(`torgash.updates.server` = `TORGASH_UDS_URL`, `verify_tls` off only for the
local self-signed certificate): `check()` keeps the answer as the core
setting `update_check`, `offer()` is the newest still newer than this code,
`download()` checks the announced sha256. System → "Updates": check, notes,
"Install" (`InstallUpdate`, a tracked job: download → `Updater::apply`).
`torgash:update` with no package does the same from the console;
`torgash:release --publish --notes=` puts a build on the server
(`TORGASH_UDS_TOKEN`, only on the publishing machine). The scheduler asks
once a day (`core:update-check`, 04:MM with the minute taken from APP_KEY so
shops do not all knock at once) and the bell has a row while a newer version
waits (`core_update`, `system.manage` only) — read from the last check, never
by asking the server during a page. Asking is all it does: installing stays
the owner's click.
The address is the owner's to change on that card (core setting
`update_server`, blank = the built-in `TORGASH_UDS_URL`; changing it forgets
the last answer). It needs no more trust than that: another server can only
stop updates, never install one, since every package is checked against the
keys in the code.

The way back is the NEW version's `rollback.php.stub` when the package has
one (signed like the rest), else the running code's — so a fix to the
rollback protects the update that ships it. The script runs the old
version's `torgash:refresh` (or `view:clear`): Blade judges compiled views by
modification time, and files put back are older than what the new version
compiled. Found by the first real update of a copy of the shop
(torgash-test.local, 2026-10-16), as was the sweep of old runs deleting the
just-downloaded package (0.1.2).

A rollback restores the backup the INSTALL JOB made, so the restored database
holds that job's task as "running" and its job as reserved: an hour later the
queue would hand it out again and reinstall what the owner went back from.
`InstallUpdate` installs nothing when its task is older than the last
rollback (the plan's mtime, `rolledBackAt()`), and the System page writes
such a row off (`settleAfterRollback()`) so Install is not held back. A
version without this (0.1.0–0.1.4) rolled back to keeps the ghost — clear
it by hand (cancel the task, delete its job).

- **Module and theme files are served OUTSIDE the `web` group**
  (`routes/assets.php`, registered by `bootstrap/app.php` `then:`): no
  session, CSRF cookie, storefront or locale for a script, and a 304 for an
  unchanged file (`isNotModified`) — OSPanel's Caddy forces
  `max-age=0, must-revalidate` on everything, so every load revalidates. A
  viewer that pulls several files on the first click is warmed BEFORE it
  (`torgash.media` `preloadLightbox()`: on idle and on pointer-enter): fetched
  on the click, the photo viewer's chain took 0.7–1.7 s to open.

**APP_DEBUG shows its pages only to this machine** (`App\Core\Http\DebugOnlyLocally`,
prepended globally): a request is local when its host is localhost / `*.local` /
`*.test` / `*.localhost` AND it carries no proxy header (`X-Forwarded-For`,
`Forwarded`, `CF-Connecting-IP`, `CF-Ray`, …). A Cloudflare tunnel rewrites Host
to the dev name but adds its headers, so the dev box opened through
the tunnel answers errors as a live shop. The switch is put back after
the request (persistent workers).

**A new shop comes from the distribution** (PLAN §54, `App\Core\Install`).
`torgash:release` builds TWO archives from the same staged files: the update
package and `torgash-<v>-install.zip` (the files in `torgash-<v>/`, plus
`DISTRIBUTION_EXTRAS`: `.env.example`, empty `storage/` and `bootstrap/cache/`,
INSTALL notes), each with `.sha256` and `.sig`. `torgash:install` runs
`Installer`: every step may run again, so a failed install is finished by
running it again; `storage/app/installing` marks one in progress and the lock
`storage/app/installed` ends it. `Installer::installed()` also counts a
database that already has modules (and writes the lock) — the console never
installs over a shop that predates the lock. The process starts with no
`.env`, so everything resolved at boot (the cache store, spatie's permission
registrar) is re-pointed when the database is switched. `ServerCheck` is the
one list of requirements (its extensions are `SystemFacts`').

**The web installer** (`/install`, `InstallController`) drives the same
`Installer` one step per request: `begin()` writes the answers into the
`installing` marker, each request runs `step(n)` and the page asks for n+1.
Its routes stand outside the `web` group (no app key yet, so no cookie,
session or CSRF); the owner is proven by the code in `storage/app/install-key`.
`SendToInstaller`, global and first, sends every other address to `/install`
until the lock exists. Strings it prints live in CORE's `lang/ru.json`: no
module's lang file is loaded before the install. The queue needs no worker of
its own: with `QUEUE_WORKER=scheduler` (the default) the scheduler starts
`queue:work --stop-when-empty` every minute, never two at once.

**Worker mode (FrankenPHP through Laravel Octane, 2026-10-21).** One
application is booted and kept; Octane clones it for every request, so what a
request RESOLVES dies with it — but what was resolved while booting
(`ModuleManager`, `HookBus`, `SettingsManager`, `ThemeManager`, `ZoneRegistry`,
the translator, the view finder…) is the same object for every request of
that worker. `App\Core\Support\WorkerMode` remembers what a request may
change in them when the worker has booted (`WorkerStarting`) and puts it back
when a request, task or tick begins: the hook listeners (`HookBus::freeze/thaw`
— a listener added at request time would stay subscribed), the theme
(`ThemeManager::reset`, back to the booted one — the admin after a storefront
on another theme drew in it), the translator's JSON paths and the zone list
(both ADDED by a theme). The memos of small tables registered with
`PerRequest::flushPerJob` are emptied at the same moment, so a setting changed
through one worker reaches the others. None of it runs outside Octane.
Measured on the dev box: booting the application costs ~130 ms, a whole
request through a booted worker ~57 ms.

A worker keeps the code it booted with. `WebWorkers::restart()` (step of
`CodeChanged::refresh()`, and on a storefront's languages changing — they
decide the route table, `LocaleResolver::routePrefixes`, read once per
process) writes `storage/framework/workers-version`; the worker loop
(`App\Core\Support\FrankenPhpWorker`, entered from `public/index.php` when
FrankenPHP runs it as a worker — `worker index.php` in the Caddyfile)
— Octane's worker loop plus that check, shipped with every update — reads it
after each request and stops once it differs, and FrankenPHP starts a fresh
worker. From the console it also calls FrankenPHP's admin endpoint
(`TORGASH_WORKER_RESTART_URL`) so every worker restarts at once; never from
inside a worker, which would wait for itself.

Testing it without FrankenPHP: `Tests\Concerns\RunsInOctaneWorker` boots
Octane's real `Worker` with its `FakeClient` (the socket is the only fake),
hands it the test's PDO, and serves a list of requests through ONE
application. `WorkerModeTest` holds the leaks found so far, each verified to
fail without its fix.

**PHP is started from PHP through `App\Core\Support\Php::command()`**, never
the bare `PHP_BINARY` constant: under FrankenPHP that is `frankenphp`, and
the update's second half, its rollback and `composer audit` would all fail
(the rollback reads the command from the plan, a list; an older plan's string
is accepted). The `PHP_BINARY` environment variable wins — Laravel's
scheduler reads the same one, so a FrankenPHP server sets it once.

**GitHub's Releases are an update source too** (`App\Core\Updates\GitHubReleases`,
2026-10-22): the update address on the System page may be a repository
(`https://github.com/owner/repo`), and `TORGASH_GITHUB_REPO` names one that is
asked when the address does not answer — `UpdateServer::sources()`, first that
answers wins, and the System page says which. A release is a tag `vX.Y.Z` with
`torgash-X.Y.Z.zip` among its assets; drafts, pre-releases and a release with
no update package are not offered. The sum is GitHub's asset `digest`, else a
published `.zip.sha256`; a download with no sum announced is refused. Trusted
with availability only, like the delivery service — the package's own
signature decides. Made for the shop that gets a cut-off from extago.com but
reaches GitHub. The built-in repository is `extago/torgash`; every release goes
there as well: `torgash:release --github` (`GitHubPublisher`, token
`TORGASH_GITHUB_PUBLISH_TOKEN` on the publishing machine only) makes a DRAFT,
uploads both archives with their `.sha256`/`.sig`, and publishes only then —
a shop asking in between never sees a release without its package; a tag
already there is refused, never replaced; a failed upload deletes the draft.

**An update can be installed from a file** (System → Updates → Install from a
file, `IncomingPackages`): for a shop that cannot download from the update
server. Uploaded, or put into `storage/app/update/incoming/` by FTP when it is
larger than PHP's upload limit; checked when it arrives (`UpdatePackage::open`:
signed by the makers, newer than this code) and installed by the same
`InstallUpdate` job given the path (`new InstallUpdate($path)`), which deletes
it after a successful install. Nothing about the checks depends on how a
package arrived.

