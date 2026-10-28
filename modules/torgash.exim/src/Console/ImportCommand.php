<?php

namespace Torgash\Exim\Console;

use App\Models\BackgroundTask;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use Torgash\Exim\Import\Columns;
use Torgash\Exim\Import\CsvFile;
use Torgash\Exim\Import\Formula;
use Torgash\Exim\Import\FormulaException;
use Torgash\Exim\Import\ImportFile;
use Torgash\Exim\Import\XmlFile;
use Torgash\Exim\ImportRunner;
use Torgash\Exim\Jobs\RunImport;
use Torgash\Exim\Models\ImportDraft;
use Torgash\Exim\Models\ImportPreset;
use Torgash\Exim\SchemaRegistry;

/**
 * `exim:import <file>` — the import screen's work without the screen (PLAN
 * §59.2): a change made by a script, the price list a supplier sends every
 * day, a server reached over SSH.
 *
 * It is not a second importer. The file is read, its preset found by its
 * header and its columns guessed exactly as the mapping screen does
 * (`ImportPreset::forHeader`, `Columns::guess`), and the rows are written by
 * the screen's own task, `RunImport` — listed under Background tasks with its
 * counts and notes. The one-off script that filled the live shop left no trace
 * there, and that was the complaint.
 *
 * How a file is READ (delimiter, encoding, the XML record) and what its
 * columns become is set up once on the screen and kept as a preset; the
 * command line only says what changes from one run to the next. What the
 * screen makes sure of by showing it, this says out loud: a column that goes
 * to no field and that no preset skips stops the import, and so does a
 * formula that cannot be read. `--dry-run` is the screen's "Check".
 */
class ImportCommand extends Command
{
    protected $signature = 'exim:import
        {file? : A CSV, XML or YML file}
        {--entity=product : What its rows are: product, category, feature...}
        {--fields : List the columns the entity takes, and stop}
        {--preset= : An import preset, by name or number; without it, the one saved for these columns, if any}
        {--no-preset : Match the columns by their names alone, whatever presets are saved}
        {--dry-run : Say what would be created, updated and skipped, and change nothing}
        {--skip-new : Rows that match nothing are skipped (a price list), not created}
        {--create-new : Rows that match nothing are created, whatever the preset says}
        {--images= : Photo links: empty (only products without photos), missing (add the new ones) or replace}
        {--skip-unknown : Import although some columns go to no field; they are left out}
        {--user= : The email of a staff member: the import is theirs in Background tasks and the audit trail}
        {--queue : Hand it to the queue worker instead of running it here}';

    protected $description = 'Import a file as the import screen does, by a preset or by its column names';

    public function handle(ImportRunner $runner, SchemaRegistry $schema): int
    {
        $entity = (string) $this->option('entity');
        $fields = $runner->fields($entity);

        if ($fields === []) {
            $importable = array_filter(array_keys($schema->entities()), fn (string $key) => $runner->fields($key) !== []);
            $this->error("Nothing is imported as \"{$entity}\". These are: ".implode(', ', $importable).'.');

            return self::FAILURE;
        }

        if ($this->option('fields')) {
            return $this->listFields($fields);
        }

        $path = $this->file();
        $given = $this->given();
        $user = $this->user();
        $preset = $this->namedPreset($entity);

        if ($path === null || $given === null || $user === false || $preset === false) {
            return self::FAILURE;
        }

        try {
            [$file, $preset, $options] = $this->read($path, $entity, $preset, $given);
        } catch (Throwable $e) {
            $this->error('The file cannot be read: '.$e->getMessage());

            return self::FAILURE;
        }

        $header = $file->header();
        $mapping = Columns::guess($header, array_values($fields), $preset);
        // A preset keeps formulas by column name; only an imported column's counts.
        $options['formulas'] = array_filter(
            Columns::formulasByIndex($header, (array) ($preset?->options['formulas'] ?? [])),
            fn ($text, $i) => ($mapping[$i] ?? '') !== '',
            ARRAY_FILTER_USE_BOTH,
        );
        // The guess made concrete: the task reads the file as it was read here.
        $options = array_merge($options, $file->settings());
        $unknown = $this->unknown($header, $mapping, $preset);

        $this->describe($path, $file, $preset, $header, $mapping, $unknown, $fields, $options);

        if (! in_array(true, array_map(fn ($key) => $key !== '', $mapping), true)) {
            $this->error('No column of the file goes to a field. --fields lists the columns '.$entity.' takes.');

            return self::FAILURE;
        }

        if ($unknown !== [] && ! $this->option('skip-unknown')) {
            $this->error('Columns that go to no field: "'.implode('", "', $unknown).'".');
            $this->line('Name them as --fields does, save a preset for this file on the import screen, or pass --skip-unknown to leave them out.');

            return self::FAILURE;
        }

        // Never imported as if it were not there: the bare purchase price
        // would go in as the price.
        foreach ($options['formulas'] as $i => $text) {
            try {
                Formula::parse($text);
            } catch (FormulaException $e) {
                $this->error('The formula of the column "'.$header[$i].'" cannot be read: '.$e->describe());

                return self::FAILURE;
            }
        }

        if ($this->option('dry-run')) {
            return $this->check($runner, $file, $entity, $mapping, $options);
        }

        if ($user) {
            // Whoever starts a task is who the audit names for what it writes.
            auth()->setUser($user);
        }

        // The task reads its file from the private disk and deletes it when
        // done, as it does an upload: the file named here stays where it is.
        $stored = ImportDraft::DIR.'/'.Str::random(32).'.'.strtolower(pathinfo($path, PATHINFO_EXTENSION));
        Storage::disk('private')->putFileAs(dirname($stored), new File($path), basename($stored));
        $preset?->update(['last_used_at' => now()]);

        if ($this->option('queue')) {
            $task = RunImport::start($entity, $stored, $mapping, $options);
            $this->info("Queued as task #{$task->id}: the queue worker runs it, Background tasks follows it.");

            return self::SUCCESS;
        }

        $this->line('Importing...');

        try {
            $task = RunImport::runHere($entity, $stored, $mapping, $options);
        } catch (Throwable $e) {
            $this->error('The import failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            // Gone already when the task got through; left when it did not.
            Storage::disk('private')->delete($stored);
        }

        return $this->report($task);
    }

    /** The file named, as a full path — or null, having said what is wrong with it. */
    protected function file(): ?string
    {
        $given = (string) $this->argument('file');

        if ($given === '') {
            $this->error('Name the file to import, or ask --fields what its columns may be.');

            return null;
        }

        $path = realpath($given);

        if ($path === false || ! is_file($path) || ! is_readable($path)) {
            $this->error("There is no file {$given}.");

            return null;
        }

        if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ImportFile::EXTENSIONS, true)) {
            $this->error('Give a CSV or an XML/YML file: a spreadsheet is saved as CSV first.');

            return null;
        }

        return $path;
    }

    /**
     * What the command line says about the rows, as import options — put over
     * the preset's, as a change on the screen is. Null (and said why) when it
     * contradicts itself.
     */
    protected function given(): ?array
    {
        $given = [];

        if ($this->option('skip-new') && $this->option('create-new')) {
            $this->error('--skip-new and --create-new say opposite things.');

            return null;
        }

        if ($this->option('skip-new') || $this->option('create-new')) {
            $given['create_new'] = (bool) $this->option('create-new');
        }

        if (($images = (string) $this->option('images')) !== '') {
            if (! in_array($images, ['empty', 'missing', 'replace'], true)) {
                $this->error('--images is empty, missing or replace.');

                return null;
            }

            $given['images_mode'] = $images;
        }

        return $given;
    }

    /** The staff member named by --user, null for nobody — false when there is no such one. */
    protected function user(): User|false|null
    {
        $email = trim((string) $this->option('user'));

        if ($email === '') {
            return null;
        }

        $user = User::staff()->whereRaw('lower(email) = lower(?)', [$email])->first();

        if (! $user) {
            $this->error("No staff member has the email {$email}.");

            return false;
        }

        return $user;
    }

    /** The preset named by --preset, null for none — false when there is no such one. */
    protected function namedPreset(string $entity): ImportPreset|false|null
    {
        $wanted = trim((string) $this->option('preset'));

        if ($wanted === '') {
            return null;
        }

        $preset = (ctype_digit($wanted) ? ImportPreset::where('entity', $entity)->find((int) $wanted) : null)
            ?? ImportPreset::where('entity', $entity)->whereRaw('lower(name) = lower(?)', [$wanted])->first();

        if ($preset) {
            return $preset;
        }

        $saved = ImportPreset::where('entity', $entity)->orderBy('name')->get()
            ->map(fn (ImportPreset $preset) => "#{$preset->id} \"{$preset->name}\"")->implode(', ');
        $this->error("No {$entity} import preset \"{$wanted}\". ".($saved !== ''
            ? "Saved: {$saved}."
            : 'None is saved: the import screen makes one ("Save as an import preset").'));

        return false;
    }

    /**
     * The file read as the mapping screen reads it: by the preset named, or
     * else by the one saved for this header — and the import options that
     * come with it, under what the command line says.
     *
     * @return array{0: CsvFile|XmlFile, 1: ?ImportPreset, 2: array}
     */
    protected function read(string $path, string $entity, ?ImportPreset $preset, array $given): array
    {
        $options = array_merge(ImportRunner::DEFAULT_OPTIONS, (array) ($preset?->options ?? []), $given);
        $file = ImportFile::read($path, $options);

        if (! $preset && ! $this->option('no-preset') && ($preset = ImportPreset::forHeader($entity, $file->header()))) {
            $options = array_merge(ImportRunner::DEFAULT_OPTIONS, (array) $preset->options, $given);
            $file = ImportFile::read($path, $options);
        }

        return [$file, $preset, $options];
    }

    /**
     * Columns that go to no field and that no preset skips knowingly — on the
     * screen, a "skip" somebody would have looked at.
     *
     * @return list<string>
     */
    protected function unknown(array $header, array $mapping, ?ImportPreset $preset): array
    {
        $skipped = [];
        foreach ((array) ($preset?->mapping ?? []) as $column => $key) {
            if ((string) $key === '') {
                $skipped[Columns::normal((string) $column)] = true;
            }
        }

        return array_values(array_filter(
            array_map('strval', $header),
            fn (string $column, int $i) => ($mapping[$i] ?? '') === '' && ! isset($skipped[Columns::normal($column)]),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /** What is about to happen, as the screen would show it: the file, its preset and where every column goes. */
    protected function describe(string $path, ImportFile $file, ?ImportPreset $preset, array $header, array $mapping, array $unknown, array $fields, array $options): void
    {
        $reading = collect($file->settings())
            ->filter(fn ($value) => (string) $value !== '')
            ->map(fn ($value, $key) => str_replace('_', ' ', (string) $key).' '.($value === "\t" ? 'tab' : '"'.$value.'"'))
            ->implode(', ');

        $this->line(basename($path).': '.$file->count().' rows'.($reading !== '' ? ', '.$reading : '').'.');
        $this->line($preset
            ? "Read by the import preset \"{$preset->name}\" (#{$preset->id})."
            : 'No import preset: the columns are matched by their names.');

        $this->table(['Column', 'Goes to', 'Formula'], array_map(fn (int $i) => [
            $header[$i],
            match (true) {
                ($mapping[$i] ?? '') !== '' => $mapping[$i].' — '.$this->title($mapping[$i], $fields),
                in_array((string) $header[$i], $unknown, true) => '(no field)',
                default => '(skipped by the preset)',
            },
            $options['formulas'][$i] ?? '',
        ], array_keys($header)));

        $this->line('Rows that match nothing are '.($options['create_new'] ? 'created' : 'skipped').'.');
    }

    /** A mapping key as the screen names it: "Name (RU)", or the field in every language for `name_*`. */
    protected function title(string $key, array $fields): string
    {
        if (isset($fields[$key])) {
            return SchemaRegistry::title($fields[$key]);
        }

        $targets = Columns::targets($key, $fields);

        return $targets !== [] ? __($fields[$targets[0]]['base_label']).' ('.implode(', ', array_map(fn ($target) => strtoupper($fields[$target]['locale']), $targets)).')' : $key;
    }

    /** --dry-run: the screen's "Check" — every row matched, nothing written. */
    protected function check(ImportRunner $runner, ImportFile $file, string $entity, array $mapping, array $options): int
    {
        $result = $runner->preview($file, $entity, $mapping, $options, 0);

        $this->info(sprintf('Would create %d, update %d, skip %d. Nothing was changed.', $result['counts']['create'], $result['counts']['update'], $result['counts']['skip']));

        if ($result['fallbacks'] > 0) {
            $this->warn("{$result['fallbacks']} found by name only — check them.");
        }

        foreach ([...$result['refused'], ...$result['problems']] as $problem) {
            $this->warn($problem);
        }

        return self::SUCCESS;
    }

    /** What the task did, as Background tasks shows it. */
    protected function report(BackgroundTask $task): int
    {
        $result = (array) $task->result;

        if ($task->status !== BackgroundTask::DONE) {
            $this->error("Task #{$task->id} did not finish: ".($task->messageText() ?? $task->status).'.');

            return self::FAILURE;
        }

        $this->info(sprintf('Done: %d created, %d updated, %d skipped. Task #%d under Background tasks.',
            $result['created'] ?? 0, $result['updated'] ?? 0, $result['skipped'] ?? 0, $task->id));

        foreach ($task->notes() as $note) {
            $this->line('  '.$note);
        }

        if (($more = $task->noteCount() - count($task->notes())) > 0) {
            $this->line("  ...and {$more} more.");
        }

        if (($images = (int) ($result['products_with_images'] ?? 0)) > 0) {
            $this->line("Photos of {$images} products are downloaded by a background task of their own: the queue worker runs it.");
        }

        return self::SUCCESS;
    }

    /** --fields: what a column may be called to go to each field. */
    protected function listFields(array $fields): int
    {
        $this->table(['Column', 'Field', 'Also read from'], array_map(fn (array $field) => [
            $field['key'],
            SchemaRegistry::title($field),
            implode(', ', (array) ($field['aliases'] ?? [])),
        ], array_values($fields)));

        $texts = array_values(array_unique(array_filter(array_column($fields, 'base_key'))));

        if ($texts !== []) {
            $this->line('A text for every language at once: '.implode(', ', array_map(fn ($base) => $base.'_'.Columns::ALL, $texts)).'.');
        }

        return self::SUCCESS;
    }
}
