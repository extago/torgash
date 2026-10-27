<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * A shipping method carries the carrier's mark.
 *
 * A customer picks "Nova Poshta" by its logo long before reading the row, and
 * a checkout that lists four carriers as four lines of text is the one people
 * misread. Stored as a single-file media collection rather than a path column,
 * so it goes through the same upload validation and the same disk as every
 * other image in the shop.
 */
class ShippingMethodLogoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_a_logo_is_uploaded_replaced_and_removed(): void
    {
        $method = ShippingMethod::create(['name' => ['en' => 'Carrier'], 'enabled' => true]);

        $this->actingAs($this->admin());

        $this->put("http://localhost/admin/shipping/methods/{$method->id}", [
            'name' => 'Carrier',
            'enabled' => '1',
            'logo' => UploadedFile::fake()->image('logo.png', 120, 60),
        ])->assertRedirect();

        $this->assertNotNull($method->refresh()->logoUrl());

        // singleFile(): a second upload replaces rather than accumulates, or the
        // shop would quietly serve whichever media row came back first.
        $this->put("http://localhost/admin/shipping/methods/{$method->id}", [
            'name' => 'Carrier',
            'enabled' => '1',
            'logo' => UploadedFile::fake()->image('other.png', 120, 60),
        ])->assertRedirect();

        $this->assertCount(1, $method->refresh()->getMedia('logo'));
        $this->assertStringContainsString('other', $method->logoUrl());

        // Removing is its own answer: a shop dropping a carrier's mark has no
        // new file to hand over.
        $this->put("http://localhost/admin/shipping/methods/{$method->id}", [
            'name' => 'Carrier',
            'enabled' => '1',
            'logo_remove' => '1',
        ])->assertRedirect();

        $this->assertNull($method->refresh()->logoUrl());
    }

    /** The form renders the field, and offers removal only once there is one. */
    public function test_the_form_offers_removal_only_when_there_is_a_logo(): void
    {
        $method = ShippingMethod::create(['name' => ['en' => 'Carrier'], 'enabled' => true]);

        $this->actingAs($this->admin());

        $this->get("http://localhost/admin/shipping/methods/{$method->id}/edit")
            ->assertOk()
            ->assertSee('Logo')
            ->assertDontSee('Remove the logo');

        $this->put("http://localhost/admin/shipping/methods/{$method->id}", [
            'name' => 'Carrier', 'enabled' => '1',
            'logo' => UploadedFile::fake()->image('logo.png', 120, 60),
        ])->assertRedirect();

        $this->get("http://localhost/admin/shipping/methods/{$method->id}/edit")
            ->assertOk()
            ->assertSee('Remove the logo');
    }

    /** Saving anything else must not drop the logo — the field is simply empty. */
    public function test_saving_the_form_without_a_file_keeps_the_logo(): void
    {
        $method = ShippingMethod::create(['name' => ['en' => 'Carrier'], 'enabled' => true]);

        $this->actingAs($this->admin());

        $this->put("http://localhost/admin/shipping/methods/{$method->id}", [
            'name' => 'Carrier', 'enabled' => '1',
            'logo' => UploadedFile::fake()->image('logo.png', 120, 60),
        ])->assertRedirect();

        $this->put("http://localhost/admin/shipping/methods/{$method->id}", [
            'name' => 'Carrier renamed', 'enabled' => '1',
        ])->assertRedirect();

        $this->assertNotNull($method->refresh()->logoUrl());
    }

    /** An SVG is script that renders as a picture; the image pipeline takes none. */
    public function test_a_file_the_image_pipeline_will_not_take_is_refused_as_a_field_error(): void
    {
        $method = ShippingMethod::create(['name' => ['en' => 'Carrier'], 'enabled' => true]);

        $this->actingAs($this->admin());

        $this->put("http://localhost/admin/shipping/methods/{$method->id}", [
            'name' => 'Carrier', 'enabled' => '1',
            'logo' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'),
        ])->assertSessionHasErrors('logo');

        $this->assertNull($method->refresh()->logoUrl());
    }

    protected function admin(): User
    {
        $user = User::firstWhere('email', 'ship-logo@test');

        if (! $user) {
            $user = User::create(['name' => 'Admin', 'email' => 'ship-logo@test', 'password' => bcrypt('x')]);
            $user->assignRole(Role::findOrCreate('admin'));
        }

        return $user;
    }
}
