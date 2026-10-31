<?php

namespace Tests\Feature\Core;

use App\Core\Settings\SettingsManager;
use App\Core\Support\PhoneMask;
use App\Models\Storefront;
use App\Models\User;
use Tests\TestCase;

/**
 * The storefront's phone mask (Support\PhoneMask): the shop's choice, a
 * storefront's own over it, a pattern of one's own only when it can be one,
 * and the one script that carries it — in its own attributes.
 */
class PhoneMaskTest extends TestCase
{
    public function test_no_mask_no_script(): void
    {
        $this->get('http://localhost/')->assertOk()->assertDontSee('phone-mask.js', false);
    }

    public function test_the_shops_mask_and_a_storefronts_own_over_it(): void
    {
        $settings = app(SettingsManager::class);
        $storefront = Storefront::query()->orderBy('id')->firstOrFail();

        $settings->set('core', 'phone_mask', 'ua');
        $this->get('http://localhost/')->assertOk()
            ->assertSee('phone-mask.js', false)
            ->assertSee('data-pattern="+380 (__) ___-__-__"', false)
            ->assertSee('data-trunk="0"', false);

        $settings->set('core', 'phone_mask', 'custom', $storefront->id);
        $settings->set('core', 'phone_mask_custom', '+998 (__) ___-__-__', $storefront->id);
        $this->assertSame('+998 (__) ___-__-__', PhoneMask::for($storefront->id)['pattern']);
        $this->assertSame('+380 (__) ___-__-__', PhoneMask::for(null)['pattern'], 'the shop\'s own is untouched');
    }

    public function test_a_pattern_that_cannot_be_one_is_no_mask_and_is_refused(): void
    {
        $this->assertNull(PhoneMask::cleanPattern('+7 (999) 999-99-99'), 'no "_": nothing to type into');
        $this->assertNull(PhoneMask::cleanPattern('<script>__'));
        $this->assertSame('+998 (__) ___-__-__', PhoneMask::cleanPattern(' +998 (__) ___-__-__ '));

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $storefront = Storefront::query()->orderBy('id')->firstOrFail();

        $this->actingAs($admin)->get(route('admin.storefronts.edit', $storefront))->assertOk()
            ->assertSee('name="phone_mask"', false);
    }
}
