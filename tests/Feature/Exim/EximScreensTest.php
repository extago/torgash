<?php

namespace Tests\Feature\Exim;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Torgash\Exim\Models\ImportDraft;
use Torgash\Exim\Models\EximProfile;

/**
 * Export profiles and import in the admin's common shape: search and status
 * tabs, the status in place and for several at once, a form that stays open,
 * and an import check that shows only the columns the file has.
 */
class EximScreensTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::role('admin')->firstOrFail());
    }

    protected function profile(string $name, array $extra = []): EximProfile
    {
        return EximProfile::create(['type' => 'export', 'name' => ['en' => $name], 'entity' => 'product', 'format' => 'csv',
            'mode' => 'schema', 'public_token' => bin2hex(random_bytes(16)), 'enabled' => true, ...$extra]);
    }

    public function test_the_list_searches_tabs_and_switches(): void
    {
        $on = $this->profile('Quokka wholesale', ['schedule' => 'daily']);
        $off = $this->profile('Quokka marketplace', ['enabled' => false]);

        $this->get('http://localhost/admin/exim/profiles?q=quokka&status=scheduled')->assertOk()
            ->assertSee('Quokka wholesale')->assertDontSee('Quokka marketplace');

        $this->patch("http://localhost/admin/exim/profiles/{$off->id}/status", ['enabled' => 1])
            ->assertOk()->assertSee('profile-status-'.$off->id, false);
        $this->assertTrue($off->fresh()->enabled);

        $this->post('http://localhost/admin/exim/profiles/bulk', ['action' => 'disable', 'ids' => [$on->id, $off->id]])->assertRedirect();
        $this->assertFalse($on->fresh()->enabled);

        // A disabled profile's feed answers "not found".
        $this->get('http://localhost/exim/feed/'.$on->public_token)->assertNotFound();
    }

    public function test_the_form_stays_open_and_names_this_installs_scheduler(): void
    {
        $profile = $this->profile('Stay open', ['schedule' => 'daily']);

        $this->get("http://localhost/admin/exim/profiles/{$profile->id}/edit")->assertOk()
            // The install's own paths, worked out — not one machine's typed in.
            ->assertSee('schedule:run')->assertSee(base_path('artisan'), false);

        $this->put("http://localhost/admin/exim/profiles/{$profile->id}", ['name' => 'Stay open', 'entity' => 'product', 'format' => 'csv', 'mode' => 'schema', 'enabled' => 1])
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.exim.profiles.edit', $profile));
        $this->put("http://localhost/admin/exim/profiles/{$profile->id}", ['name' => 'Stay open', 'entity' => 'product', 'format' => 'csv', 'mode' => 'schema', 'after_save' => 'close'])
            ->assertRedirect(route('admin.exim.profiles.index'));
    }

    public function test_the_import_check_shows_the_columns_the_file_has(): void
    {
        $this->get('http://localhost/admin/exim/import')->assertOk();

        $csv = "sku,name,price
IMP-CHECK-1,Checked,12.50
";
        $file = UploadedFile::fake()->createWithContent('products.csv', $csv);

        $map = $this->post('http://localhost/admin/exim/import/upload', ['entity' => 'product', 'file' => $file]);
        $map->assertRedirect();
        $token = basename(dirname(parse_url($map->headers->get('Location'), PHP_URL_PATH)));

        $this->get('http://localhost/admin/exim/import/'.$token.'/map')->assertOk()
            ->assertSee('IMP-CHECK-1')
            ->assertSee('— skip this column —');

        $this->post('http://localhost/admin/exim/import/preview', ['token' => $token, 'map' => ['sku', 'name', ''], 'create_new' => 1])->assertOk()
            ->assertSee('IMP-CHECK-1')
            ->assertSee('1 will be created');
    }

    public function test_a_text_column_fills_one_language_or_all_of_them(): void
    {
        $locales = app(\Torgash\Exim\SchemaRegistry::class)->locales();
        if (count($locales) < 2) {
            $this->markTestSkipped('needs a shop with two languages');
        }
        $feature = \Torgash\Catalog\Models\Feature::create(['code' => 'quokka-tint', 'name' => ['en' => 'Quokka tint']]);

        $file = UploadedFile::fake()->createWithContent('products.csv', "sku,Название
IMP-ALL-1,Квокка
");
        $map = $this->post('http://localhost/admin/exim/import/upload', ['entity' => 'product', 'file' => $file]);
        $token = basename(dirname(parse_url($map->headers->get('Location'), PHP_URL_PATH)));

        // The field is listed once, its language beside it; a characteristic shows its id.
        $this->get('http://localhost/admin/exim/import/'.$token.'/map')->assertOk()
            ->assertSee('data-key="name"', false)
            ->assertDontSee('data-key="name_'.$locales[0].'"', false)
            ->assertSee('name="lang[1]"', false)
            ->assertSee('#'.$feature->id);

        $this->post('http://localhost/admin/exim/import/preview', [
            'token' => $token, 'map' => ['sku', 'name'], 'lang' => ['', \Torgash\Exim\Import\Columns::ALL], 'create_new' => 1,
        ])->assertOk()->assertSee('1 will be created');

        // Back from the check, the screen shows the choice as it was made.
        $this->get('http://localhost/admin/exim/import/'.$token.'/map')->assertOk()
            ->assertSee('value="*" selected', false);

        $this->post('http://localhost/admin/exim/import/commit', ['token' => $token])->assertRedirect();

        $name = (array) \Torgash\Catalog\Models\Product::where('sku', 'IMP-ALL-1')->firstOrFail()->name;
        foreach ($locales as $locale) {
            $this->assertSame('Квокка', $name[$locale] ?? null, "the {$locale} name");
        }
    }

    public function test_an_upload_is_a_draft_listed_saved_as_picked_and_deleted_with_its_file(): void
    {
        $file = UploadedFile::fake()->createWithContent('quokka-prices.csv', "Код квокки,Цена квокки\nIMP-DRAFT-1,10\n");
        $map = $this->post('http://localhost/admin/exim/import/upload', ['entity' => 'product', 'file' => $file]);
        $token = basename(dirname(parse_url($map->headers->get('Location'), PHP_URL_PATH)));
        $draft = ImportDraft::where('token', $token)->sole();

        $this->get('http://localhost/admin/exim/import/'.$token.'/map')->assertOk();
        $this->assertSame(['', ''], $draft->fresh()->mapping, 'nobody named these columns');
        $this->assertSame(1, $draft->fresh()->row_count);

        // Picked on the screen, kept without "Check".
        $this->post('http://localhost/admin/exim/import/'.$token.'/save', ['map' => ['sku', 'price_units'], 'create_new' => 1])->assertNoContent();
        $this->assertSame(['sku', 'price_units'], $draft->fresh()->mapping);

        $this->get('http://localhost/admin/exim/import')->assertOk()
            ->assertSee('Unfinished imports')
            ->assertSee('quokka-prices.csv')
            ->assertSee('2 / 2');

        $this->delete('http://localhost/admin/exim/import/drafts/'.$token)->assertRedirect();
        $this->assertNull(ImportDraft::where('token', $token)->first());
        $this->assertFalse(Storage::disk('private')->exists($draft->path));
        $this->get('http://localhost/admin/exim/import/'.$token.'/map')->assertRedirect(route('admin.exim.import.index'));
    }

    public function test_a_formula_changes_a_column_on_the_way_in_and_its_preset_keeps_it_by_name(): void
    {
        $file = UploadedFile::fake()->createWithContent('quokka-supplier.csv', "sku,Название,Закупка
IMP-F-1,Квокка,\"1 028,50\"
IMP-F-2,Квокка 2,по запросу
");
        $map = $this->post('http://localhost/admin/exim/import/upload', ['entity' => 'product', 'file' => $file]);
        $token = basename(dirname(parse_url($map->headers->get('Location'), PHP_URL_PATH)));
        $this->get('http://localhost/admin/exim/import/'.$token.'/map')->assertOk();

        // Worked out on the screen as it is typed.
        $this->post('http://localhost/admin/exim/import/'.$token.'/formula', ['column' => 2, 'formula' => [2 => 'ending($value * 1.2, 90)']])
            ->assertOk()->assertSee('id="map-values-2"', false)->assertSee('1290')->assertSee('is not a number');
        $this->post('http://localhost/admin/exim/import/'.$token.'/formula', ['column' => 2, 'formula' => [2 => '$value *']])
            ->assertOk()->assertSee('ends too early');

        // A formula that cannot be read is never imported as if it were not there.
        $this->post('http://localhost/admin/exim/import/preview', ['token' => $token, 'map' => ['sku', 'name', 'price_units'], 'formula' => [2 => '$value *'], 'create_new' => 1])
            ->assertRedirect()->assertSessionHasErrors('map');

        $this->post('http://localhost/admin/exim/import/preview', [
            'token' => $token, 'map' => ['sku', 'name', 'price_units'], 'formula' => [2 => 'ending($value * 1.2, 90)'],
            'create_new' => 1, 'preset_name' => 'Quokka supplier',
        ])->assertOk()->assertSee('2 will be created')->assertSee('Line 2: column');

        $this->post('http://localhost/admin/exim/import/commit', ['token' => $token])->assertRedirect();

        $this->assertSame(129000, \Torgash\Catalog\Models\Product::where('sku', 'IMP-F-1')->firstOrFail()->price);
        // The row whose cell was not a number: created, its price not invented.
        $this->assertSame(0, (int) \Torgash\Catalog\Models\Product::where('sku', 'IMP-F-2')->firstOrFail()->price);
        $task = \App\Models\BackgroundTask::query()->latest('id')->firstOrFail();
        $this->assertStringContainsString('"по запросу" is not a number', implode("
", $task->notes()));

        $preset = \Torgash\Exim\Models\ImportPreset::where('name', 'Quokka supplier')->sole();
        $this->assertSame(['Закупка' => 'ending($value * 1.2, 90)'], $preset->options['formulas']);

        // The supplier's next file, its columns the other way round: the formula follows its column.
        $next = UploadedFile::fake()->createWithContent('quokka-supplier-2.csv', "Закупка,Название,sku
500,Квокка 3,IMP-F-3
");
        $map = $this->post('http://localhost/admin/exim/import/upload', ['entity' => 'product', 'file' => $next]);
        $token = basename(dirname(parse_url($map->headers->get('Location'), PHP_URL_PATH)));
        $this->get('http://localhost/admin/exim/import/'.$token.'/map')->assertOk()
            ->assertSee('name="formula[0]" value="ending($value * 1.2, 90)"', false)
            ->assertSee('690');
    }

    public function test_the_check_names_a_row_that_cannot_make_a_new_product(): void
    {
        $file = UploadedFile::fake()->createWithContent('typo.csv', "sku,price_units\nCHK-TYPO,5\n");
        $map = $this->post('http://localhost/admin/exim/import/upload', ['entity' => 'product', 'file' => $file]);
        $token = basename(dirname(parse_url($map->headers->get('Location'), PHP_URL_PATH)));
        $this->get('http://localhost/admin/exim/import/'.$token.'/map')->assertOk();

        $this->post('http://localhost/admin/exim/import/preview', ['token' => $token, 'map' => ['sku', 'price_units'], 'create_new' => 1])
            ->assertOk()
            ->assertSee('0 will be created')
            ->assertSee('Line 1: skipped — no product matches it, and a new one needs a name');
    }

    public function test_a_draft_left_alone_for_a_week_is_swept_with_its_file(): void
    {
        Storage::disk('private')->put(ImportDraft::DIR.'/'.str_repeat('q', 32).'.csv', "sku\nX\n");
        $old = ImportDraft::create(['token' => str_repeat('q', 32), 'entity' => 'product', 'path' => ImportDraft::DIR.'/'.str_repeat('q', 32).'.csv', 'file_name' => 'old.csv']);
        ImportDraft::query()->whereKey($old->id)->update(['updated_at' => now()->subDays(ImportDraft::DAYS + 1)]);
        $fresh = ImportDraft::create(['token' => str_repeat('r', 32), 'entity' => 'product', 'path' => ImportDraft::DIR.'/'.str_repeat('r', 32).'.csv', 'file_name' => 'new.csv']);

        $this->assertSame(1, ImportDraft::sweep());
        $this->assertNull(ImportDraft::find($old->id));
        $this->assertFalse(Storage::disk('private')->exists($old->path));
        $this->assertNotNull(ImportDraft::find($fresh->id));
    }
}
