<?php

namespace Tests\Feature\Options;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Exim\ExportRunner;
use Torgash\Exim\ImportRunner;
use Torgash\Exim\Models\EximProfile;
use Torgash\Kits\Models\Kit;
use Torgash\Kits\Models\KitSlot;
use Torgash\Options\Groups;
use Torgash\Options\Models\OptionChoice;
use Torgash\Options\Models\OptionGroup;
use Torgash\Options\PriceRules;

/**
 * The configurator and the kits in a spreadsheet (PLAN §33.7).
 *
 * What this is for: a shop with two hundred products each carrying an extended
 * warranty changes its price in one file rather than two hundred forms, and a
 * catalogue arriving from another engine brings its options with it.
 *
 * Which makes the statements worth pinning the ones about IDENTITY. A file
 * that travels means nothing unless the words in it mean the same thing at
 * both ends: a group is named by its code, a product by its SKU, a
 * characteristic by its own code, and an id never appears at all. The other
 * half is the money rule — what is exported is the amount as TYPED, because a
 * file carrying the books column would rewrite it on the first import into a
 * shop with a different base currency.
 */
class OptionsEximTest extends TestCase
{
    protected Product $server;

    protected Product $cpu;

    protected function setUp(): void
    {
        parent::setUp();

        app(Groups::class)->flush();
        app(PriceRules::class)->flush();

        $this->server = $this->product('SRV-1', 'Server', 1000_00);
        $this->cpu = $this->product('CPU-1', 'Xeon', 170_00);
    }

    /** A code is given to everything, because a code nothing has is one nothing matches. */
    public function test_a_row_is_given_a_stable_code_without_being_asked(): void
    {
        $group = $this->group(['admin_name' => 'Warranty for servers']);

        $this->assertSame('warranty-for-servers', $group->code);

        // A second row of the same name does not steal it.
        $twin = $this->group(['admin_name' => 'Warranty for servers']);

        $this->assertNotSame($group->code, $twin->code);
        $this->assertStringStartsWith('warranty-for-servers-', $twin->code);

        // A name in an alphabet that slugs to nothing still gets something
        // usable — an empty code is one nothing can be matched by.
        $cyrillic = $this->group(['admin_name' => 'Гарантия']);

        $this->assertNotSame('', (string) $cyrillic->code);

        // What the shop types is never overwritten, including by a rename.
        $own = $this->group(['admin_name' => 'Anything', 'code' => 'srv-warranty']);
        $own->update(['admin_name' => 'Renamed entirely']);

        $this->assertSame('srv-warranty', $own->fresh()->code);
    }

    /** The export names things by the words that travel, never by id. */
    public function test_the_export_names_products_by_sku_and_groups_by_code(): void
    {
        $group = $this->group(['admin_name' => 'Processor', 'product_id' => $this->server->id]);
        $choice = OptionChoice::create([
            'group_id' => $group->id, 'enabled' => true, 'kind' => 'product',
            'product_id' => $this->cpu->id, 'price_rule' => 'product_price',
        ]);

        $groups = $this->export('option_group', ['code', 'admin_name', 'product_sku']);
        $choices = $this->export('option_choice', ['code', 'group_code', 'product_sku', 'kind']);

        $this->assertStringContainsString('processor,Processor,SRV-1', $groups);
        $this->assertStringContainsString($choice->code.',processor,CPU-1,product', $choices);
        $this->assertStringNotContainsString((string) $this->server->id, $groups);
    }

    /** …and the import reads them back, onto the rows they name. */
    public function test_an_edited_file_updates_the_rows_it_names(): void
    {
        $group = $this->group(['admin_name' => 'Warranty', 'code' => 'warranty']);
        $choice = OptionChoice::create([
            'group_id' => $group->id, 'enabled' => true, 'kind' => 'plain',
            'code' => 'warranty-1y', 'name' => ['en' => '+1 year'],
            'price_rule' => 'fixed', 'amount' => 500_00,
        ]);

        $this->import('option_choice', "code,group_code,kind,name_en,price_rule,currency,amount,enabled\n"
            ."warranty-1y,warranty,plain,+2 years,fixed,,90000,yes\n");

        $choice->refresh();

        $this->assertSame('+2 years', $choice->name['en']);
        $this->assertSame(900_00, $choice->amount);
        $this->assertTrue($choice->enabled);
        $this->assertSame($group->id, $choice->group_id, 'still in the question it was in');
        $this->assertSame(1, OptionChoice::count(), 'updated, not duplicated');
    }

    /** A row the file does not know is created, not merged into the nearest thing. */
    public function test_an_unknown_code_creates_a_row(): void
    {
        $this->group(['admin_name' => 'Warranty', 'code' => 'warranty']);

        $this->import('option_choice', "code,group_code,kind,name_en,price_rule,amount\n"
            ."warranty-3y,warranty,plain,+3 years,fixed,150000\n");

        $created = OptionChoice::where('code', 'warranty-3y')->firstOrFail();

        $this->assertSame(1500_00, $created->amount);
        $this->assertSame('+3 years', $created->name['en']);
    }

    /**
     * The money rule. What is typed is what travels; the books column is
     * derived at both ends, so the same file means the same PROMISE in a shop
     * whose books are in another currency rather than the same number.
     */
    public function test_an_amount_travels_as_typed_with_its_currency(): void
    {
        // The shop keeps its books in USD here, so UAH is the other currency —
        // the one a price can be TYPED in and therefore the one with anything
        // to travel.
        $this->rate('UAH', 40.0);

        $group = $this->group(['admin_name' => 'Warranty', 'code' => 'warranty']);
        OptionChoice::create([
            'group_id' => $group->id, 'enabled' => true, 'kind' => 'plain',
            'code' => 'warranty-uah', 'name' => ['en' => 'Cover'], 'price_rule' => 'fixed',
            'amount' => 10_00, 'amount_original' => 400_00, 'currency' => 'UAH',
        ]);

        // Exported as the TYPED amount, not as what the books hold.
        $csv = $this->export('option_choice', ['code', 'currency', 'amount']);
        $this->assertStringContainsString('warranty-uah,UAH,40000', $csv);

        // And read back through Prices: 800 UAH at 40 is 20 in the books.
        $this->import('option_choice', "code,group_code,kind,price_rule,currency,amount\n"
            ."warranty-uah,warranty,plain,fixed,UAH,80000\n");

        $choice = OptionChoice::where('code', 'warranty-uah')->firstOrFail();

        $this->assertSame(800_00, $choice->amount_original);
        $this->assertSame(20_00, $choice->amount);
        $this->assertSame('UAH', $choice->currency);
    }

    /**
     * A reference that cannot be resolved leaves the row where it was. Moving
     * a warranty onto whatever happened to sort first is the one answer that
     * silently sells the wrong thing.
     */
    public function test_an_unknown_reference_never_moves_a_row(): void
    {
        $group = $this->group(['admin_name' => 'Warranty', 'code' => 'warranty', 'product_id' => $this->server->id]);

        $this->import('option_group', "code,admin_name,product_sku,depends_on\n"
            ."warranty,Warranty,NO-SUCH-SKU,no-such-group\n");

        $group->refresh();

        $this->assertSame($this->server->id, $group->product_id, 'the product it belongs to is unchanged');
        $this->assertNull($group->depends_on_group_id);
    }

    /** Kits travel the same way, and a slot names its characteristic by code. */
    public function test_a_kit_and_its_slots_travel_by_code(): void
    {
        $kit = Kit::create([
            'admin_name' => 'Phone set', 'name' => ['en' => 'Phone set'],
            'main_product_id' => $this->server->id, 'enabled' => true,
        ]);
        KitSlot::create([
            'kit_id' => $kit->id, 'kind' => 'product', 'product_id' => $this->cpu->id,
            'qty' => 1, 'discount_type' => 'percent', 'discount_percent' => 10, 'enabled' => true,
        ]);

        $this->assertSame('phone-set', $kit->code);

        $slots = $this->export('kit_slot', ['code', 'kit_code', 'product_sku', 'discount_percent']);
        $this->assertStringContainsString('phone-set,CPU-1,10', $slots);

        $this->import('kit_slot', "code,kit_code,kind,product_sku,discount_type,discount_percent,qty,enabled\n"
            .KitSlot::first()->code.",phone-set,product,CPU-1,percent,25,2,yes\n");

        $slot = KitSlot::firstOrFail();

        $this->assertSame(25.0, $slot->discount_percent);
        $this->assertSame(2, $slot->qty);
        $this->assertSame(1, KitSlot::count());
    }

    /** All four are on offer, so a profile can be made for each. */
    public function test_the_four_entities_are_offered_to_a_profile(): void
    {
        $entities = array_keys(hook_filter('exim.entities', []));

        foreach (['option_group', 'option_choice', 'kit', 'kit_slot'] as $entity) {
            $this->assertContains($entity, $entities);
            $this->assertNotEmpty(hook_filter("exim.schema.{$entity}", []), $entity.' has fields');
        }
    }

    /** @param list<string> $fields */
    protected function export(string $entity, array $fields): string
    {
        Storage::fake('local');

        $profile = EximProfile::create([
            'type' => 'export', 'entity' => $entity, 'format' => 'csv', 'mode' => 'schema',
            'name' => ['en' => $entity], 'fields' => $fields, 'enabled' => true,
        ]);

        $this->actingAs($this->admin());

        return Storage::disk('local')->get(app(ExportRunner::class)->run($profile));
    }

    protected function import(string $entity, string $csv): void
    {
        $path = tempnam(sys_get_temp_dir(), 'exim').'.csv';
        file_put_contents($path, $csv);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        $runner = app(ImportRunner::class);
        $runner->commit($entity, $runner->rowsOf($path, $entity));
    }

    protected function rate(string $code, float $rate): void
    {
        \App\Models\Currency::updateOrCreate(
            ['code' => $code],
            ['symbol' => '$', 'rate' => $rate, 'decimals' => 2, 'enabled' => true],
        );

        app(\App\Core\Support\Currencies::class)->flush();
    }

    protected function group(array $attributes): OptionGroup
    {
        $group = OptionGroup::create($attributes + [
            'name' => ['en' => $attributes['admin_name'] ?? 'Question'],
            'type' => 'select',
            'enabled' => true,
        ]);

        app(Groups::class)->flush();

        return $group;
    }

    protected function product(string $sku, string $name, int $price): Product
    {
        return Product::create([
            'sku' => $sku, 'name' => ['en' => $name], 'slug' => strtolower($sku),
            'price' => $price, 'stock' => 10, 'status' => 'active',
            'availability_status' => 'in_stock',
        ]);
    }

    protected function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
