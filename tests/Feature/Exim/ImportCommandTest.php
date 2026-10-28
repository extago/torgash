<?php

namespace Tests\Feature\Exim;

use App\Models\BackgroundTask;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Exim\Jobs\RunImport;
use Torgash\Exim\Models\ImportDraft;
use Torgash\Exim\Models\ImportPreset;
use Torgash\Guard\Models\AuditEntry;

/**
 * `exim:import` — the import screen's work from the console (PLAN §59.2): the
 * preset and the column guess the screen uses, the screen's own task under
 * Background tasks, and said out loud what the screen would have shown.
 */
class ImportCommandTest extends TestCase
{
    protected function file(string $csv): string
    {
        $path = sys_get_temp_dir().'/torgash-cli-'.bin2hex(random_bytes(6)).'.csv';
        file_put_contents($path, $csv);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }

    protected function product(string $sku): Product
    {
        return Product::create([
            'sku' => $sku, 'name' => [content_base_locale() => 'Quokka '.$sku], 'slug' => 'cli-quokka-'.strtolower($sku),
            'price' => 10_00, 'stock' => 1, 'status' => 'active',
        ]);
    }

    protected function supplierPreset(array $formulas = ['Закупка' => 'ending($value * 1.2, 90)']): ImportPreset
    {
        return ImportPreset::create([
            'entity' => 'product', 'name' => 'Quokka supplier',
            'columns' => ['Артикул', 'Название', 'Закупка', 'Склад'],
            'mapping' => ['Артикул' => 'sku', 'Название' => 'name_'.content_base_locale(), 'Закупка' => 'price_units', 'Склад' => ''],
            'options' => ['create_new' => false, 'formulas' => $formulas],
        ]);
    }

    public function test_a_file_of_our_own_columns_changes_what_it_names_as_a_background_task(): void
    {
        $product = $this->product('CLI-1');
        $base = content_base_locale();
        $path = $this->file("sku,name_{$base},price_units\nCLI-1,Renamed quokka,12.50\nCLI-2,New quokka,5\n");
        $copies = Storage::disk('private')->files(ImportDraft::DIR);

        $this->artisan('exim:import', ['file' => $path])
            ->expectsOutputToContain('No import preset: the columns are matched by their names.')
            ->expectsOutputToContain('Done: 1 created, 1 updated, 0 skipped.')
            ->assertSuccessful();

        $this->assertSame('Renamed quokka', $product->fresh()->name[$base]);
        $this->assertSame(12_50, $product->fresh()->price);
        $this->assertSame(5_00, Product::where('sku', 'CLI-2')->sole()->price);

        $task = BackgroundTask::query()->latest('id')->firstOrFail();
        $this->assertSame([BackgroundTask::DONE, 'Import', 1, 1], [$task->status, $task->name, $task->result['created'], $task->result['updated']]);
        $this->assertNull($task->user_id, 'nobody named, nobody recorded');
        // The file named stays where it is; the task's own copy is gone.
        $this->assertFileExists($path);
        $this->assertSame($copies, Storage::disk('private')->files(ImportDraft::DIR));
    }

    public function test_a_dry_run_says_what_would_happen_and_changes_nothing(): void
    {
        $product = $this->product('CLI-1');
        $path = $this->file("sku,price_units\nCLI-1,99\nCLI-2,5\n");
        $tasks = BackgroundTask::count();

        $this->artisan('exim:import', ['file' => $path, '--dry-run' => true, '--skip-new' => true])
            ->expectsOutputToContain('Rows that match nothing are skipped.')
            ->expectsOutputToContain('Would create 0, update 1, skip 1. Nothing was changed.')
            ->assertSuccessful();

        $this->assertSame(10_00, $product->fresh()->price);
        $this->assertFalse(Product::where('sku', 'CLI-2')->exists());
        $this->assertSame($tasks, BackgroundTask::count());
    }

    public function test_a_column_that_goes_to_no_field_stops_the_import_until_it_is_left_out_knowingly(): void
    {
        $product = $this->product('CLI-1');
        $path = $this->file("sku,price_units,Warehouse\nCLI-1,99,A1\n");

        $this->artisan('exim:import', ['file' => $path])
            ->expectsOutputToContain('Columns that go to no field: "Warehouse".')
            ->assertFailed();
        $this->assertSame(10_00, $product->fresh()->price);

        $this->artisan('exim:import', ['file' => $path, '--skip-unknown' => true])->assertSuccessful();
        $this->assertSame(99_00, $product->fresh()->price);
    }

    public function test_a_suppliers_file_is_read_by_its_preset_named_or_found_by_its_columns(): void
    {
        $product = $this->product('CLI-1');
        $this->supplierPreset();
        // "Склад" goes nowhere, and the preset says so: not a column nobody looked at.
        $path = $this->file("Артикул;Название;Закупка;Склад\nCLI-1;Квокка;1000;A1\nCLI-GONE;Квокка 2;500;B2\n");

        $this->artisan('exim:import', ['file' => $path, '--preset' => 'quokka SUPPLIER'])
            ->expectsOutputToContain('Read by the import preset "Quokka supplier"')
            ->expectsOutputToContain('Done: 0 created, 1 updated, 1 skipped.')
            ->assertSuccessful();

        $this->assertSame(1290_00, $product->fresh()->price, 'the formula of the preset, by its column name');
        $this->assertFalse(Product::where('sku', 'CLI-GONE')->exists(), 'a price list invents no goods');

        $this->artisan('exim:import', ['file' => $path, '--create-new' => true])
            ->expectsOutputToContain('Read by the import preset "Quokka supplier"')
            ->expectsOutputToContain('Done: 1 created, 1 updated, 0 skipped.')
            ->assertSuccessful();
        $this->assertSame(690_00, Product::where('sku', 'CLI-GONE')->sole()->price);
    }

    public function test_a_row_that_matches_nothing_and_has_no_name_is_skipped_and_said_so(): void
    {
        $product = $this->product('CLI-1');
        // A price list with a mistyped SKU: there is nothing to make a product of.
        $path = $this->file("sku,price_units\nCLI-1,99\nCLI-TYPO,5\n");

        $this->artisan('exim:import', ['file' => $path, '--dry-run' => true])
            ->expectsOutputToContain('Would create 0, update 1, skip 1.')
            ->expectsOutputToContain('Line 2: skipped — no product matches it, and a new one needs a name')
            ->assertSuccessful();

        $this->artisan('exim:import', ['file' => $path])
            ->expectsOutputToContain('Done: 0 created, 1 updated, 1 skipped.')
            ->expectsOutputToContain('Line 2: skipped — no product matches it')
            ->assertSuccessful();

        $this->assertSame(99_00, $product->fresh()->price, 'one mistyped SKU does not cost the rest of the file');
        $this->assertFalse(Product::where('sku', 'CLI-TYPO')->exists());
    }

    public function test_a_formula_that_cannot_be_read_stops_the_import(): void
    {
        $product = $this->product('CLI-1');
        $this->supplierPreset(['Закупка' => '$value *']);
        $path = $this->file("Артикул;Название;Закупка;Склад\nCLI-1;Квокка;1000;A1\n");

        $this->artisan('exim:import', ['file' => $path])
            ->expectsOutputToContain('The formula of the column "Закупка" cannot be read')
            ->assertFailed();

        $this->assertSame(10_00, $product->fresh()->price);
    }

    public function test_the_staff_member_named_is_who_the_task_and_the_audit_trail_name(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $product = $this->product('CLI-1');
        $path = $this->file("sku,price_units\nCLI-1,99\n");

        $this->artisan('exim:import', ['file' => $path, '--user' => strtoupper($admin->email)])->assertSuccessful();

        $task = BackgroundTask::query()->latest('id')->firstOrFail();
        $entry = AuditEntry::for(Product::class, $product->id)->where('action', 'updated')->latest('id')->first();
        $this->assertSame($admin->id, $task->user_id);
        $this->assertNotNull($entry, 'the import left no trail');
        $this->assertSame([$admin->id, $task->id], [$entry->user_id, $entry->task_id]);

        $this->artisan('exim:import', ['file' => $path, '--user' => 'nobody@quokka.test'])
            ->expectsOutputToContain('No staff member has the email nobody@quokka.test.')
            ->assertFailed();
    }

    public function test_queued_the_task_gets_its_own_copy_of_the_file(): void
    {
        Queue::fake();
        $path = $this->file("sku,price_units\nCLI-1,99\n");

        $this->artisan('exim:import', ['file' => $path, '--queue' => true])
            ->expectsOutputToContain('Queued as task #')
            ->assertSuccessful();

        $this->assertSame(BackgroundTask::QUEUED, BackgroundTask::query()->latest('id')->firstOrFail()->status);
        Queue::assertPushed(RunImport::class, function (RunImport $job) use ($path) {
            $copy = Storage::disk('private')->get($job->path);
            Storage::disk('private')->delete($job->path);

            return str_starts_with($job->path, ImportDraft::DIR.'/') && $copy === file_get_contents($path) && $job->mapping === ['sku', 'price_units'];
        });
        $this->assertFileExists($path);
    }

    public function test_what_is_not_there_is_named_beside_what_is(): void
    {
        $this->supplierPreset();
        $path = $this->file("sku\nCLI-1\n");

        $this->artisan('exim:import', ['file' => $path, '--preset' => 'Wombat supplier'])
            ->expectsOutputToContain('"Quokka supplier"')
            ->assertFailed();
        $this->artisan('exim:import', ['file' => $path, '--entity' => 'wombat'])
            ->expectsOutputToContain('These are: ')
            ->assertFailed();
        $this->artisan('exim:import', ['file' => $path.'.gone'])
            ->expectsOutputToContain('There is no file')
            ->assertFailed();
        $this->artisan('exim:import', ['--fields' => true])
            ->expectsOutputToContain('price_units')
            ->assertSuccessful();
    }
}
