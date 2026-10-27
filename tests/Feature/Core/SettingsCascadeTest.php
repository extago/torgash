<?php

namespace Tests\Feature\Core;

use App\Core\Settings\SettingsManager;
use App\Models\Storefront;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A setting resolves through three layers: the manifest default, the global
 * value in the database, and the per-storefront override. Getting the order
 * wrong is invisible until a multi-storefront shop is live.
 */
class SettingsCascadeTest extends TestCase
{
    protected SettingsManager $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = app(SettingsManager::class);
        $this->settings->registerDefaults('test.module', ['greeting' => 'from the manifest']);
    }

    public function test_the_manifest_default_is_used_when_nothing_is_stored(): void
    {
        $this->assertSame('from the manifest', $this->settings->get('test.module', 'greeting'));
    }

    public function test_a_stored_value_wins_over_the_default(): void
    {
        $this->settings->set('test.module', 'greeting', 'from the database');

        $this->assertSame('from the database', $this->settings->get('test.module', 'greeting'));
    }

    public function test_a_storefront_override_wins_over_the_global_value(): void
    {
        $storefront = Storefront::where('is_default', true)->firstOrFail();

        $this->settings->set('test.module', 'greeting', 'global');
        $this->settings->set('test.module', 'greeting', 'this storefront only', $storefront->id);

        $this->assertSame('this storefront only', $this->settings->get('test.module', 'greeting', null, $storefront->id));
        $this->assertSame('global', $this->settings->get('test.module', 'greeting'));
    }

    public function test_a_storefront_falls_back_to_the_global_value_for_keys_it_does_not_override(): void
    {
        $storefront = Storefront::where('is_default', true)->firstOrFail();

        $this->settings->set('test.module', 'greeting', 'global');

        $this->assertSame('global', $this->settings->get('test.module', 'greeting', null, $storefront->id));
    }

    public function test_defaults_are_never_written_to_the_database(): void
    {
        $this->settings->get('test.module', 'greeting');

        $this->assertSame(0, DB::table('settings')->where('module', 'test.module')->count());
    }

    public function test_forget_returns_the_key_to_its_default(): void
    {
        $this->settings->set('test.module', 'greeting', 'from the database');
        $this->settings->forget('test.module', 'greeting');

        $this->assertSame('from the manifest', $this->settings->get('test.module', 'greeting'));
    }

    /**
     * Values are stored as json, so a setting keeps the type it was saved with
     * — a checkbox that comes back as the string "0" would be true.
     */
    public function test_values_keep_their_type(): void
    {
        $this->settings->set('test.module', 'enabled', false);
        $this->settings->set('test.module', 'sizes', ['120x120', '400x400']);

        $this->assertFalse($this->settings->get('test.module', 'enabled'));
        $this->assertSame(['120x120', '400x400'], $this->settings->get('test.module', 'sizes'));
    }
}
