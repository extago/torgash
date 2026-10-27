<?php

namespace Tests\Feature\Media;

use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Rebuilding every picture of the shop is the media library's right, not
 * anybody's who can open the admin (audit 2026-10-15).
 */
class RebuildThumbnailsAccessTest extends TestCase
{
    public function test_only_who_manages_media_may_rebuild_every_thumbnail(): void
    {
        Queue::fake();
        $role = Role::findOrCreate('thumbs-test-staff');
        $role->givePermissionTo('admin.access');
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($user)->post('http://localhost/admin/media/regenerate')->assertForbidden();
        Queue::assertNothingPushed();

        $role->givePermissionTo('media.manage');
        $this->actingAs($user->fresh())->post('http://localhost/admin/media/regenerate')->assertRedirect();
        Queue::assertPushed(\Torgash\Media\Jobs\RebuildThumbnails::class);
    }
}
