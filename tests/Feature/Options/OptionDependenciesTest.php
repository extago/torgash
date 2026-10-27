<?php

namespace Tests\Feature\Options;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Torgash\Cart\CartManager;
use Torgash\Catalog\Models\Product;
use Torgash\Options\Configurator;
use Torgash\Options\Groups;
use Torgash\Options\Models\OptionChoice;
use Torgash\Options\Models\OptionGroup;
use Torgash\Options\PriceRules;

/**
 * "Memory — only with a processor" (PLAN §33.7, phase 3).
 *
 * A configurator asks questions that only make sense once an earlier one has
 * been answered. What is pinned here is that "not asked" means the same thing
 * at every stage — it is not on the page, it cannot be demanded, its answer is
 * not kept and it is not charged for — because those are four separate pieces
 * of code and a shop is robbed by whichever one forgets.
 */
class OptionDependenciesTest extends TestCase
{
    protected Product $server;

    protected Product $cpu;

    protected OptionGroup $cpuGroup;

    protected OptionChoice $intel;

    protected OptionChoice $amd;

    protected OptionGroup $memory;

    protected OptionChoice $ddr5;

    protected function setUp(): void
    {
        parent::setUp();

        app(Groups::class)->flush();
        app(PriceRules::class)->flush();

        $this->server = $this->product('SRV-1', 'Server', 1000_00);
        $this->cpu = $this->product('CPU-1', 'Xeon', 170_00);

        $this->cpuGroup = $this->group(['type' => 'select', 'name' => ['en' => 'Processor']]);
        $this->intel = $this->choice($this->cpuGroup, ['kind' => 'plain', 'name' => ['en' => 'Intel'], 'price_rule' => 'fixed', 'amount' => 170_00]);
        $this->amd = $this->choice($this->cpuGroup, ['kind' => 'plain', 'name' => ['en' => 'AMD'], 'price_rule' => 'fixed', 'amount' => 150_00]);

        // Memory is only asked once a processor has been picked — and it is
        // REQUIRED, which is the case that breaks a shop with no dependencies:
        // a required question nobody can sensibly answer yet.
        $this->memory = $this->group([
            'type' => 'select',
            'name' => ['en' => 'Memory'],
            'required' => true,
            'depends_on_group_id' => $this->cpuGroup->id,
        ]);
        $this->ddr5 = $this->choice($this->memory, ['kind' => 'plain', 'name' => ['en' => '32 GB'], 'price_rule' => 'fixed', 'amount' => 90_00]);
    }

    public function test_a_dependent_question_is_not_asked_until_its_own_is_answered(): void
    {
        $asked = app(Groups::class)->asked($this->server, []);

        $this->assertSame(['Processor'], $asked->map(fn (OptionGroup $g) => $g->t('name'))->all());

        $asked = app(Groups::class)->asked($this->server, [
            (string) $this->cpuGroup->id => [['c' => $this->intel->id, 'q' => 1]],
        ]);

        $this->assertSame(['Processor', 'Memory'], $asked->map(fn (OptionGroup $g) => $g->t('name'))->all());
    }

    /**
     * The one that would otherwise make the product unbuyable: a required
     * question the customer has not been shown may not refuse the purchase.
     */
    public function test_a_required_question_nobody_was_asked_does_not_block_the_purchase(): void
    {
        app(Configurator::class)->validate($this->server, []);

        // …and the moment it IS asked, it is required again.
        $this->expectException(ValidationException::class);

        app(Configurator::class)->validate($this->server, $this->answers([
            (string) $this->cpuGroup->id => [['c' => $this->intel->id, 'q' => 1]],
        ]));
    }

    /**
     * The theft this exists to prevent: memory chosen, then a processor with
     * it soldered on, and the invisible module still on the bill.
     */
    public function test_an_answer_to_a_question_that_went_away_is_dropped(): void
    {
        $configurator = app(Configurator::class);

        $config = $configurator->normalise($this->server, [Configurator::KEY => [
            $this->cpuGroup->id => (string) $this->intel->id,
            $this->memory->id => (string) $this->ddr5->id,
        ]]);

        $this->assertArrayHasKey((string) $this->memory->id, $config[Configurator::KEY]);

        // The processor is unchosen, and the memory goes with it.
        $config = $configurator->normalise($this->server, [Configurator::KEY => [
            $this->memory->id => (string) $this->ddr5->id,
        ]]);

        $this->assertSame([], $config[Configurator::KEY] ?? []);
    }

    /** …and a line stored before the dependency existed is not charged for it either. */
    public function test_a_stored_answer_to_a_question_no_longer_asked_is_not_charged(): void
    {
        $parts = app(Configurator::class)->parts($this->server, 1, $this->answers([
            (string) $this->memory->id => [['c' => $this->ddr5->id, 'q' => 1]],
        ]));

        $this->assertSame([], $parts);
    }

    /** Only the named answers count, when the shop names any. */
    public function test_naming_choices_narrows_the_dependency_to_them(): void
    {
        $this->memory->update(['depends_on_choices' => [$this->intel->id]]);
        app(Groups::class)->flush();

        $withAmd = app(Groups::class)->asked($this->server, [
            (string) $this->cpuGroup->id => [['c' => $this->amd->id, 'q' => 1]],
        ]);

        $this->assertSame(['Processor'], $withAmd->map(fn (OptionGroup $g) => $g->t('name'))->all());

        $withIntel = app(Groups::class)->asked($this->server, [
            (string) $this->cpuGroup->id => [['c' => $this->intel->id, 'q' => 1]],
        ]);

        $this->assertCount(2, $withIntel);
    }

    /** A chain settles in one pass, and the far end goes with the near one. */
    public function test_a_chain_resolves_all_the_way_down(): void
    {
        $ecc = $this->group([
            'type' => 'select',
            'name' => ['en' => 'ECC'],
            'depends_on_group_id' => $this->memory->id,
        ]);
        $this->choice($ecc, ['kind' => 'plain', 'name' => ['en' => 'Yes'], 'price_rule' => 'fixed', 'amount' => 20_00]);

        $names = fn (array $answers) => app(Groups::class)->asked($this->server, $answers)
            ->map(fn (OptionGroup $g) => $g->t('name'))->all();

        $this->assertSame(['Processor'], $names([]));

        $this->assertSame(['Processor', 'Memory'], $names([
            (string) $this->cpuGroup->id => [['c' => $this->intel->id, 'q' => 1]],
        ]));

        $this->assertSame(['Processor', 'Memory', 'ECC'], $names([
            (string) $this->cpuGroup->id => [['c' => $this->intel->id, 'q' => 1]],
            (string) $this->memory->id => [['c' => $this->ddr5->id, 'q' => 1]],
        ]));
    }

    /**
     * A circle is a corrupt pair, not an impossible one. It must answer rather
     * than hang the request — the restraint `CategoryTree`'s seen-set shows —
     * and it fails closed, hiding both.
     */
    public function test_a_circle_hides_both_instead_of_hanging(): void
    {
        $this->cpuGroup->update(['depends_on_group_id' => $this->memory->id]);
        app(Groups::class)->flush();

        $this->assertSame([], app(Groups::class)->asked($this->server, [
            (string) $this->cpuGroup->id => [['c' => $this->intel->id, 'q' => 1]],
            (string) $this->memory->id => [['c' => $this->ddr5->id, 'q' => 1]],
        ])->all());
    }

    /** The card is drawn, with the sibling question to wait for and its answers. */
    public function test_the_form_offers_the_questions_this_one_could_wait_for(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/options/groups/'.$this->memory->id.'/edit')
            ->assertOk()
            ->assertSee(__('When it is asked'))
            ->assertSee($this->cpuGroup->admin_name)
            // The dependency is already set, so its answers are on screen to
            // be narrowed to.
            ->assertSee('Intel');
    }

    /** …and the admin form refuses to write one, because two vanished questions are unexplainable. */
    public function test_the_form_refuses_to_close_a_circle(): void
    {
        $this->actingAs($this->admin())
            ->from('/admin/options/groups/'.$this->cpuGroup->id.'/edit')
            ->put('/admin/options/groups/'.$this->cpuGroup->id, [
                'name' => 'Processor',
                'admin_name' => 'cpu',
                'type' => 'select',
                'product_id' => $this->server->id,
                'depends_on_group_id' => $this->memory->id,
            ])
            ->assertSessionHasErrors('depends_on_group_id');

        $this->assertNull($this->cpuGroup->fresh()->depends_on_group_id);
    }

    /** The whole build, bought: what was asked is charged, what was not is not there. */
    public function test_the_cart_charges_for_the_questions_that_were_asked(): void
    {
        $cart = app(CartManager::class);

        $cart->add($this->server->id, 1, $this->answers([
            $this->cpuGroup->id => (string) $this->intel->id,
            $this->memory->id => (string) $this->ddr5->id,
        ]));

        $line = $cart->items()->first();

        $this->assertSame(1000_00 + 170_00 + 90_00, $line->line_total);
    }

    /** @param array<int|string, mixed> $answers */
    protected function answers(array $answers): array
    {
        return [Configurator::KEY => $answers];
    }

    protected function group(array $attributes): OptionGroup
    {
        $group = OptionGroup::create($attributes + [
            'product_id' => $this->server->id,
            'admin_name' => 'g'.uniqid(),
            'enabled' => true,
        ]);

        app(Groups::class)->flush();

        return $group;
    }

    protected function choice(OptionGroup $group, array $attributes): OptionChoice
    {
        $choice = OptionChoice::create($attributes + ['group_id' => $group->id, 'enabled' => true]);

        $group->load('choices');
        app(Groups::class)->flush();

        return $choice;
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
