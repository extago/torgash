<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Torgash\Checkout\Models\PaymentMethod;

/**
 * A payment method carries its own mark, the same way a shipping method carries
 * the carrier's (ShippingMethodLogoTest). Stored as a single-file media
 * collection so it goes through the shop's one upload pipeline and disk.
 */
class PaymentMethodLogoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_a_logo_is_uploaded_replaced_and_removed(): void
    {
        $method = PaymentMethod::create(['name' => ['en' => 'Card'], 'enabled' => true]);

        $this->actingAs($this->admin());

        $this->put("http://localhost/admin/payments/methods/{$method->id}", [
            'name' => 'Card',
            'enabled' => '1',
            'logo' => UploadedFile::fake()->image('visa.png', 120, 60),
        ])->assertRedirect();

        $this->assertNotNull($method->refresh()->logoUrl());

        // singleFile(): a second upload replaces rather than accumulates.
        $this->put("http://localhost/admin/payments/methods/{$method->id}", [
            'name' => 'Card',
            'enabled' => '1',
            'logo' => UploadedFile::fake()->image('mastercard.png', 120, 60),
        ])->assertRedirect();

        $this->assertCount(1, $method->refresh()->getMedia('logo'));
        $this->assertStringContainsString('mastercard', $method->logoUrl());

        // Removal is its own answer — a shop dropping a mark has no new file.
        $this->put("http://localhost/admin/payments/methods/{$method->id}", [
            'name' => 'Card',
            'enabled' => '1',
            'logo_remove' => '1',
        ])->assertRedirect();

        $this->assertNull($method->refresh()->logoUrl());
    }

    public function test_the_form_offers_removal_only_when_there_is_a_logo(): void
    {
        $method = PaymentMethod::create(['name' => ['en' => 'Card'], 'enabled' => true]);

        $this->actingAs($this->admin());

        $this->get("http://localhost/admin/payments/methods/{$method->id}/edit")
            ->assertOk()
            ->assertSee('Logo')
            ->assertDontSee('Remove the logo');

        $this->put("http://localhost/admin/payments/methods/{$method->id}", [
            'name' => 'Card', 'enabled' => '1',
            'logo' => UploadedFile::fake()->image('visa.png', 120, 60),
        ])->assertRedirect();

        $this->get("http://localhost/admin/payments/methods/{$method->id}/edit")
            ->assertOk()
            ->assertSee('Remove the logo');
    }

    public function test_saving_the_form_without_a_file_keeps_the_logo(): void
    {
        $method = PaymentMethod::create(['name' => ['en' => 'Card'], 'enabled' => true]);

        $this->actingAs($this->admin());

        $this->put("http://localhost/admin/payments/methods/{$method->id}", [
            'name' => 'Card', 'enabled' => '1',
            'logo' => UploadedFile::fake()->image('visa.png', 120, 60),
        ])->assertRedirect();

        $this->put("http://localhost/admin/payments/methods/{$method->id}", [
            'name' => 'Card renamed', 'enabled' => '1',
        ])->assertRedirect();

        $this->assertNotNull($method->refresh()->logoUrl());
    }

    public function test_an_svg_is_refused_as_a_field_error(): void
    {
        $method = PaymentMethod::create(['name' => ['en' => 'Card'], 'enabled' => true]);

        $this->actingAs($this->admin());

        $this->put("http://localhost/admin/payments/methods/{$method->id}", [
            'name' => 'Card', 'enabled' => '1',
            'logo' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'),
        ])->assertSessionHasErrors('logo');

        $this->assertNull($method->refresh()->logoUrl());
    }

    protected function admin(): User
    {
        $user = User::firstWhere('email', 'pay-logo@test');

        if (! $user) {
            $user = User::create(['name' => 'Admin', 'email' => 'pay-logo@test', 'password' => bcrypt('x')]);
            $user->assignRole(Role::findOrCreate('admin'));
        }

        return $user;
    }
}
