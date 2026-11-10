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
  deciding at either measured nothing or measured from the middle. A worker's
  request is armed when Octane hands it over (`RequestReceived` →
  `Meter::restart()`): what the worker's start armed, or a request that died
  before it terminated left, must not be its clock. The clock stops when the
  response is built (`RequestHandled`), and what runs after it — `defer()`,
  terminating callbacks — is not charged to the page (`Meter::profile()` is
  null from then on): nobody waited for it, and the parts must not add up to
  more than the whole. Under tests
  it measures only when `torgash.speed.measure_in_tests` is set, or the random
  sample would make query-counting tests flaky. The dashboard is extensible
  through `core.admin.dashboard` (HTML).
A tracked task extends `App\Core\Tasks\TrackedJob`: implement `label()` (English
source string) and `run()`, report with `total()` / `progress()` /
`message($key, $params)` / `result()`, and start it with `SomeJob::start(...)`,
which writes the `background_tasks` row and pushes the job. `SomeJob::startIfIdle(...)`
is the same but no-ops when an instance is already queued or running (keyed by
`label()`+`module()`) — for jobs the scheduler re-runs so a nightly copy does
not stack on one still going. `SomeJob::runHere(...)` writes the same row and
runs the job in this process (`dispatch_sync`), for a console command whose
user waits for the answer anyway (`exim:import`): Background tasks lists it,
the audit names whoever `auth()` held when it started, a failure is thrown as
the worker would see it — and a `continueWith()` part still goes to the
queue. Message strings are stored as a KEY plus params,
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
the screen says keep it apart) and `vendor/`. pg_dump/pg_restore come from
`TORGASH_PG_BIN` (.env; empty = PATH).

**Backups are made by PLANS and kept in STORAGES** (PLAN §67, tables
`backup_plans`, `backup_destinations`, pivot `backup_plan_destination` with
the per-storage `keep`, journal `backup_runs` + `backup_copies`). A plan says
what goes in, a LIST of moments (`PlanSchedule`: hourly / daily / weekly /
monthly / cron, each its own scheduler event in the shop's clock), a name
template (`Names`: must hold `{date}` and `{time}`), and its storages. One run
packs ONCE and uploads the file to every storage; a storage that refuses is
tried once more after a pause (`Backups::$retryPause`), and if it refuses
again makes the run `partial`, never costs the others. **A copy not stored
is sent again from one that was** (PLAN §67.11, `Backups::resend`, the task
`ResendBackup`, "send again" beside the copy on the list): fetched from the
server's copy — or any kept one — into the work folder and uploaded as the
run did, the run `done` once every copy is there; the backup is never made
anew for it. An S3 bucket takes a big file in parts, and a part refused with
a server error is sent again by the SDK in its `standard` mode up to the
storage's own `attempts` (`Destinations::ATTEMPTS` = 10 by default, the
form's "Attempts for each part"): the library's 3 lost a backup of
gigabytes to Backblaze's "internal incident" over two parts. A multipart
upload given up on is ABORTED (`Store::abortMultipart`): the bucket keeps
and bills the parts otherwise, and Backblaze shows them nowhere. Runs are QUEUED and made one at a
time by the single task `CreateBackup` (oldest first, one `continueWith` part
per run; `core:backup-queue` picks up a run left waiting). Keeping
(`Retention`): last N, daily/weekly/monthly/yearly, days, GB — applied only
after a run that stored something, never to the newest or a pinned copy, and
only to files the JOURNAL says are the plan's: someone else's file in the
same folder is never touched. Storage kinds (`Destinations`): the shop's
`local`/`private` disk with a folder, a folder on the server (never inside
`public/` or the site), an S3 bucket of its own, an SFTP, FTP(S) or WebDAV
server. **An SFTP server's key is confirmed by the owner, never trusted on
first sight** (`SftpConnection`): with none stored the connection refuses
with the key it saw (`HostKeyNotConfirmed`), the screen shows it beside
"trust", and trusting stores it only if the server shows it NOW; any other
key afterwards refuses in words and sends nothing. A proxy (HTTP CONNECT or
SOCKS5, with a login) is for S3, WebDAV and SFTP only (`Destinations::PROXIED`)
— curl/Guzzle speak it, for SFTP `Proxy::open()` makes the tunnel and hands
phpseclib the socket; FTP never (passive mode opens a second connection).
WebDAV sends Basic with the first request: offered "any", curl asks without
a login, gets 401 and cannot send a file stream again. A storage's error is
reported with its first cause (`Destinations::reason()`). Secrets encrypted with
APP_KEY (`BackupDestination::putSecrets`), a blank field keeps them, another
APP_KEY shows "type it again". The `system` storage (the server's
`backups/` folder) cannot be deleted: an update's backup goes there by
`Backups::forUpdate()`, still named `torgash-YYYY-MM-DD-HHMMSS.zip` for the
rollback, the last `KEEP_UPDATES` kept. The restore finds a name through the
journal, then — with no database to ask — in the disks' `backups/` folders
(`locate()`); names are only ever `SAFE_NAME`, downloads go by copy id.

**A module adds a storage KIND, never a driver inside core** (PLAN §67.1,
`App\Core\Backups\Destination`): it answers the filter
`core.backup.destinations` with `[driver => Destination]` — the object made
anew on every call, nothing captured — and core's `Destinations` merges it
into `drivers()`, opens it through `open()` (a `Store` over whatever
flysystem adapter the module brings), checks its fields through
`fromInput()` and draws them from `form()` inside a card of the SAME storage
screen (`$destination`, `$value`, `$kept` handed in); `where()` is the
list's column, `proxied()` lets the proxy fields in. A module's driver name
can neither shadow a core kind nor be anything but `[a-z0-9_]`. The first
one is `torgash.google-drive` (`google_drive`): Google Drive v3 by hand
over the HTTP client (`DriveClient`, no google/apiclient — a module carries
no composer dependencies), a `DriveAdapter` that walks paths over a store
that knows only ids (first child of each name, folder ids memoised per
instance, a write to a name that exists replaces the file), resumable
upload in 16 MiB chunks resumed from the byte Google says it has, a
download into a temporary file (pg_restore reads a file, a network stream
cannot be sought in). The Google Cloud CLIENT (ID + secret) is the shop's
one application, in the module's settings; the Google ACCOUNT is connected
per storage (`ConnectController`: POST to Google with a `state` kept in the
session, `access_type=offline` + `prompt=consent` so a refresh token comes
every time, the callback swaps the code and keeps the refresh token in the
row's encrypted secrets, then runs the storage's check). Scope is
`drive.file` alone: the shop sees only what it created, Google verifies
nothing — but an application left in "Testing" loses its tokens after seven
days, which the module's docs say. The connect buttons sit inside the
storage's own form and leave it with `formaction`, so the routes take PUT
as well as POST (the form's `_method` rides along).

**A backup's FORM is the plan's** (§67.3, `backup_plans.format/options/secret`,
`backup_runs.format/encrypted`): zip, tar, tar.gz, or `folder` ("without an
archive"). `stage()` lays the entries out once in ONE order — manifest,
`env/.env`, the database (`database.dump` or `database.sql`), files, code —
and every writer and every `Archive\Reader` keeps it, so a restore reads the
head and stops (`each()` → false) before the pictures. A folder's manifest
is uploaded LAST: a folder without one never finished. Encryption: zip is
AES-256 per entry (names stay visible), a tar is encrypted whole exactly as
`openssl enc -aes-256-cbc -pbkdf2 -iter 200000` (`Archive\Cipher`) — never
a format of our own that only the engine can open. `.env` goes only into an
encrypted backup; a folder is never encrypted. The plan's password is
`Crypt`-encrypted in its row — the screen says to keep it elsewhere too.

**Only the files changed since the last full backup** (PLAN §67.10, a
plan's `options.changed_only`): the database whole, the files only those
whose mtime is at or after the START of the newest full backup holding the
files that is still kept — by any plan (`Backups::lastFull()`); the run
records it (`backup_runs.base_run_id`, `files_since`) and so does the
manifest (`files_since`, `base`). Without one every file goes in and the
manifest says so. A restore puts the base's files back first
(`restoreBase()`: by its name through the journal or the storage, or
`--base=` on a new server; `restoreFiles()` does the files alone) and
refuses in words when it cannot — never "the pictures of the last day
alone". Keeping never deletes a full backup while something leans on it
(pinned by it in `expire()`); `BackupHealth` says when the newest backup's
base is gone anyway (`orphan`). Not "the last N hours" on purpose: a missed
run would leave a hole nobody sees. Files are taken by mtime, so after a
restore every file is new and the first differential after one is nearly
full — by design. `options.without_thumbnails` leaves every
`<id>/conversions/` folder out (`Backups::CONVERSIONS`); the manifest's
`thumbnails` is false and the console says to rebuild them.

**Raising a shop on a new server** (drilled 2026-11-03, docs/LESSONS.md):
fresh code of the same version, the OLD `.env` (its `APP_KEY` above all),
an empty database created with the ICU locale, then
`torgash:backup-restore --file=<zip> --code --force`, `migrate`,
`optimize:clear`, `torgash:styles`. Restoring into the shop's own database
also: creates the dump's extensions first (an empty database has no
pg_trgm), drops the runs the dump caught "running", checks the locale
(`cyrillic_ok`) and `APP_KEY` (`key_matches`, from the manifest's
`key_check`), writes the install lock (`Installer::lockAfterRestore()`) and
links `public/storage`. `--code` writes `code/modules|themes` over the
folders (never deletes what the backup lacks). The tools run with
`LC_MESSAGES=C` and UTF-8, so their error is a sentence and not an empty
line. **The way back is the console**, `torgash:backup-restore` (no
name lists them; `--database=` restores into another, existing database to
check a backup without touching the shop) — an update that broke the admin
shuts the door a button would be behind. Verified by restoring a real backup
into a scratch database and comparing row counts.

**A backup gone wrong is SAID, and so is silence** (PLAN §67, stage 4,
`App\Core\Backups\BackupHealth`): a plan needs attention when its last
backup failed or was not stored everywhere, its last check by restoring
failed, or it runs by a schedule and has no good backup within the longest
gap between two of its moments plus three hours (a stopped scheduler or
worker writes no error anywhere). The bell (`backups`, `system.manage`) and
the System page's warnings read that list; nothing is stored as "seen", a
good backup clears it. The letter `backup.problem` (core's registry, to the
staff with `system.manage` unless the letters' screen names others) goes
on every failed/partial run and failed check, and once a day (09:10) for
each overdue plan. **A check by restoring** (`Backups::verify()`, job
`VerifyBackup`, a plan's `verify` weekly/monthly or the shield button on a
row): the backup is restored into `<db>_check_<run>` made with the shop
database's own locale, every table's rows are compared with `rows` in the
manifest — counted inside the very snapshot pg_dump was handed
(`--snapshot`), so the numbers are exact on a shop taking orders — and the
database is dropped, whatever happened; the shop's own database and files
are not touched. A check KILLED half way (a restart, a timeout) never reaches
that `finally`, and each check is named after its own run, so the next check
drops any `<db>_check_<digits>` nobody is connected to (no FORCE: one still
working is refused) and any job asking for a work folder deletes those
untouched for two days (`backup-tmp` holds the dump in the clear, even of an
encrypted backup). It needs CREATEDB for the shop's database user. Without it
the check does not begin and the run says `blocked`, never `failed`: nothing
is known about the backup. The plan's form warns beforehand, the bell clears
as soon as the right is given, and the task ends through `TaskRefused`
(failed on the Background tasks screen, not in failed_jobs).
**The console reads a storage itself**:
`torgash:backup-restore --destination=<id|name>` lists the backups found
in it (a file the engine names, or a folder with `manifest.json`) and
restores one — the journal of a restored or new database does not know
them. The option that puts the backup's .env in place is `--with-env`:
`--env` is Laravel's own global option, and a command declaring it does
not even start (tests calling the command directly never noticed).

**What a restore runs must be what the shop made** (third-party review of
0.5.9, 2026-11-09). A backup lies in somebody's bucket or SFTP account, and
restoring it runs it, with nobody watching in a check by restoring.
`assertAsMade()` compares the journal's `sha256` before the file is opened, so
an encrypted backup swapped for a plain archive fails too. A folder is no one
file, so there `sha256` is its dump's (a folder made before this has none): the
check by restoring reads only the database, but the files and code in a folder
are compared with nothing, and whoever restores a folder's code from a storage
others can write to takes that on. A backup the journal does not know (`--file`,
`--destination`, the installer) has nothing to compare with, and the person
naming it answers for it: that is also the way round a refusal for a file
somebody trusts. The journal, not the storage, says whether a backup is a folder.

psql gets SQL only: `-X`, and as the first line `\restrict` with a key made for
this restore. pg_dump's own `\restrict`/`\unrestrict` come out, and the
extension filter leaves COPY data alone (it used to eat rows beginning with
those words). `\restrict` leaves one command open, `\unrestrict`, and psql before
18.6, 17.11, 16.15 and 15.19 evaluates its argument BEFORE comparing the key
(CVE-2026-18408): `` \unrestrict `cmd` `` ran on 18.3 with a wrong key. So a dump
the journal cannot vouch for is refused when that word stands anywhere but on
pg_dump's own closing line. Every line is searched: which lines are COPY data is
only the loop's reading, and a comment can mislead it. Only an odd run of
backslashes counts; COPY doubles a backslash in somebody's text, so a customer
typing that word cannot make a backup unrestorable. A psql without `\restrict`
at all (before 15.14, 16.10, 17.6, 18) refuses a dump the journal cannot vouch
for. Never "reject lines that begin with a backslash": a real dump has them, and
`SELECT 1; \! x` has none. `\restrict` does not stop SQL: `COPY … FROM PROGRAM`
still runs for a superuser, which is why the checksum comes first and why a
shop's database user must not be one.

**The installer's other door: a shop from a backup** (PLAN §67.8, stage 6,
`Installer::beginRestore()`, `torgash:install --restore=`, the wizard's
"Restore from a backup" tab). `begin()` writes NOTHING until it has read the
backup's head (`Backups::inspect()` — manifest and, asked, the .env, without
unpacking the rest), refused a backup of a newer engine, a key that does not
open `key_check` (`Backups::keyFits()`), a database with tables in it
(except this very restore's, stopped half way — running it again finishes
it), and found pg_restore/psql (the wizard's field, then this server's
`TORGASH_PG_BIN`, then the backup .env's, then PATH). A refusal deletes the
staged .env (the old shop's passwords) and an uploaded file. Its steps:
`.env` (the backup's own or the example; this server's DB, address, key and
tool folder over it), the database, the restore (`Backups::restore(…,
lock: false)` — a lock in the middle would end the wizard), `upgrade`
(core `migrate` + `ModuleManager::upgrade()` for every module the restored
shop had; one not in this code is named, not fatal), `settle` (name,
language and clock from the restored settings into .env when it is not the
backup's; the new host added to the default storefront), styles, the lock
`restored`. No owner step: the staff are the backup's. Drilled on a fresh
copy of the code, by the console and by the wizard (path, upload, new key).

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
  `update/<v>/previous/`) → bootstrap caches dropped → `torgash:update-finish`
  in a NEW process on the new code (`package:discover`, migrate, `upgrade()`
  of installed bundled modules, `CodeChanged::refresh()`, `up`). Anything
  failing after the first move runs the rollback script itself. **The caches
  are dropped BEFORE the new process, and the new `artisan` drops them again
  at its own door** (2026-11-03): `bootstrap/cache/packages.php` names the
  providers of the vendor just moved away, and one the new vendor lacks
  ("Laravel\Pail\PailServiceProvider not found" on a test copy whose vendor
  had the dev packages) stops the boot before the command could clear it —
  the updater that launches the process is the OLD version's, so the new
  code's front door is the only line that can protect every update to it.
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
fail without its fix — and one check of a whole kind: every hook listener's
object, and what that object holds, compared with what the container hands
the NEXT request; another instance of a shared binding is a memo kept past
its request (it found `torgash.seo`'s three listeners holding the templates'
memo through their constructor, 2026-10-29). Between two requests the test
may save models again: the trait points Eloquent's static resolver at the
worker while it serves and back at the test after.

Tinker under FrankenPHP's `php-cli`: PsySH forks its loop where pcntl is, and
the child calls `cli_set_process_title()`, which only PHP's own CLI has.
`CoreServiceProvider::tinkerWithoutFork()` hands PsySH a config without the
fork (`app/Core/Support/psysh-config.php`) when that function is missing,
unless the server set its own `PSYSH_CONFIG`.

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

## What a request may not decide (report of 2026-11-09)

**A host nobody named** (`App\Core\Http\ForeignHost`, global). `Host` is a line
the sender writes, and every absolute address starts from it: "I lost my
password" posted with somebody else's domain mailed a genuine letter whose button
led there. A host is the shop's when APP_URL or a storefront names it, as a
subdomain of such a name, as the bare name of a `www.` one, or this machine. Any
other is still answered (a shop opened by its IP goes on working), but `url()`
and `route()` write APP_URL, the canonical says so, and the page cache keeps no
copy (copies are filed by host). `$request->url()` and `getSchemeAndHttpHost()`
go on repeating what was sent: fine for a redirect back to where the visitor
stands, never for what leaves the page. Nothing is forced while APP_URL is this
machine, which has no better address to offer. Not the framework's
`trustHosts()`: that answers 400 to a shop opened by its IP.

**A limit is per door** (`App\Core\Http\ThrottlePerRoute`, the `throttle` alias).
Laravel's key for `throttle:N,M` is the visitor alone, so every plain limit in
the engine added to one counter: a few CSP reports from a browser (`/csp-report`,
120 a minute) closed "I lost my password" (5 in 10 minutes). The door goes into
the key, by action, so a page's copies per language are one door. A named limiter
has its own key and is not touched.

**The installer** answers the database test only to whoever holds the install
code (`check()`), shows no folder of the server, and refuses a host or database
name that would add a setting to the DSN (`ServerCheck::connect`: a `;` starts
one). **A backup folder** (`Destinations::folderError`) is a full path and never
where the web server hands files out: `public/`, the public disk, modules,
themes. **An update's package** comes from the host the update source is on
(`UpdateServer::assertFromSource`; a GitHub source: GitHub's). **`EnvFile::quote`**
escapes `$` too: `it's${APP_KEY}` was read back with the key spliced in.
