<?php

namespace Tests\Feature\Exim;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Exim\ExportRunner;
use Torgash\Exim\Models\EximProfile;

/**
 * A profile's variables (PLAN §68.1): values typed beside a template rather
 * than inside it, read as `vars.<name>`.
 */
class ProfileVarsTest extends TestCase
{
    public function test_a_profiles_variables_reach_its_template(): void
    {
        Storage::fake('local');
        $this->actingAs(User::role('admin')->firstOrFail());
        $base = content_base_locale();
        Product::create(['sku' => 'VARS-1', 'name' => [$base => 'Квокфон'], 'slug' => 'quokkaphone-vars', 'price' => 100_00, 'stock' => 1, 'status' => 'active']);

        $profile = EximProfile::create([
            'type' => 'export', 'entity' => 'product', 'format' => 'xml', 'mode' => 'template', 'name' => ['en' => 'Vars'],
            'template_body' => "<f cost=\"{{ vars.delivery_cost }}\">{% for item in rows %}<o>{{ item.sku }}{% if vars.pickup %}+pickup{% endif %}</o>{% endfor %}</f>",
            'enabled' => true, 'public_token' => Str::random(32),
        ]);

        $this->put("http://localhost/admin/exim/profiles/{$profile->id}", [
            'name' => 'Vars', 'entity' => 'product', 'format' => 'xml', 'mode' => 'template', 'enabled' => 1,
            'template_body' => $profile->template_body, 'vars_lines' => "delivery_cost = 790\npickup = true\n\ncompany = Техно = Лидер",
        ])->assertSessionHasNoErrors();
        $this->assertEquals(['delivery_cost' => '790', 'pickup' => 'true', 'company' => 'Техно = Лидер'], $profile->fresh()->vars, 'jsonb keeps no key order; a value may hold =');

        $xml = Storage::disk('local')->get(app(ExportRunner::class)->run($profile->fresh()));
        $this->assertStringContainsString('<f cost="790">', $xml);
        $this->assertStringContainsString('<o>VARS-1+pickup</o>', $xml);

        $this->get("http://localhost/admin/exim/profiles/{$profile->id}/edit")->assertOk()->assertSee('delivery_cost = 790');

        // A name a template could not say is refused by name.
        $this->put("http://localhost/admin/exim/profiles/{$profile->id}", [
            'name' => 'Vars', 'entity' => 'product', 'format' => 'xml', 'mode' => 'template', 'enabled' => 1,
            'template_body' => $profile->template_body, 'vars_lines' => 'стоимость = 790',
        ])->assertSessionHasErrors('vars_lines');
    }
}
