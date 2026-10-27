<?php

namespace Tests\Feature\Core;

use App\Core\Files\FileStorage;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The shop's files go through the disk, never through a local path — so the
 * same code runs against a folder and a bucket. What a folder can prove about
 * that is proved here: a local copy comes out and goes back, a probe writes
 * and reads, the copy command moves the files between the two halves.
 */
class FileStorageTest extends TestCase
{
    protected function tempRoot(string $name): string
    {
        $root = storage_path('framework/testing/'.$name.'-'.uniqid());
        File::ensureDirectoryExists($root);

        return $root;
    }

    public function test_a_local_copy_comes_out_and_goes_back(): void
    {
        config(['filesystems.disks.public.root' => $root = $this->tempRoot('public')]);
        Storage::forgetDisk('public');
        Storage::disk('public')->put('12/photo.txt', 'before');

        $files = app(FileStorage::class);
        $copy = $files->localCopy('public', '12/photo.txt');
        $this->assertSame('before', file_get_contents($copy));

        file_put_contents($copy, 'after');
        $files->putBack('public', '12/photo.txt', $copy);
        $files->release($copy);

        $this->assertFileDoesNotExist($copy);
        $this->assertSame('after', Storage::disk('public')->get('12/photo.txt'));

        File::deleteDirectory($root);
    }

    public function test_a_probe_writes_reads_and_cleans_up(): void
    {
        config(['filesystems.disks.private.root' => $root = $this->tempRoot('private')]);
        Storage::forgetDisk('private');

        $probe = app(FileStorage::class)->probe('private');

        $this->assertTrue($probe['ok'], (string) $probe['error']);
        $this->assertSame([], Storage::disk('private')->allFiles('.torgash-probe'));

        File::deleteDirectory($root);
    }

    public function test_the_copy_command_moves_public_files_and_only_known_private_ones(): void
    {
        $local = ['public' => $this->tempRoot('lp'), 'private' => $this->tempRoot('lv')];
        $bucket = ['public' => $this->tempRoot('sp'), 'private' => $this->tempRoot('sv')];

        foreach (['public', 'private'] as $disk) {
            config(["filesystems.disks.files-local-$disk" => ['driver' => 'local', 'root' => $local[$disk]]]);
            // A folder standing in for the bucket: the command only ever talks
            // to it through the disk, which is the point.
            config(["filesystems.disks.files-s3-$disk" => ['driver' => 'local', 'root' => $bucket[$disk], 'bucket' => 'test']]);
            Storage::forgetDisk("files-local-$disk");
            Storage::forgetDisk("files-s3-$disk");
        }

        $media = \Spatie\MediaLibrary\MediaCollections\Models\Media::create([
            'model_type' => 'tests', 'model_id' => 1, 'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'collection_name' => 'gallery', 'name' => 'photo', 'file_name' => 'photo.jpg', 'mime_type' => 'image/jpeg',
            'disk' => 'public', 'conversions_disk' => 'public', 'size' => 3,
            'manipulations' => [], 'custom_properties' => [], 'generated_conversions' => [], 'responsive_images' => [],
        ]);
        $folder = (string) $media->id;

        Storage::disk('files-local-public')->put("$folder/photo.jpg", 'jpg');
        Storage::disk('files-local-public')->put("$folder/conversions/photo-thumb-120x120.webp", 'thumb');
        // Somebody else's leftovers in the same folder (a test run, a crash).
        Storage::disk('files-local-public')->put("$folder/conversions/stranger-thumb-120x120.webp", 'junk');
        Storage::disk('files-local-public')->put('theme-images/1/logo-abc.png', 'png');
        // Private: an exported file of the engine's own must stay behind.
        Storage::disk('files-local-private')->put('exim/export.csv', 'csv');

        $this->artisan('torgash:files-copy', ['--to' => 's3'])->assertSuccessful();

        $this->assertSame('jpg', Storage::disk('files-s3-public')->get("$folder/photo.jpg"));
        $this->assertTrue(Storage::disk('files-s3-public')->exists("$folder/conversions/photo-thumb-120x120.webp"));
        $this->assertFalse(Storage::disk('files-s3-public')->exists("$folder/conversions/stranger-thumb-120x120.webp"));
        $this->assertTrue(Storage::disk('files-s3-public')->exists('theme-images/1/logo-abc.png'));
        $this->assertFalse(Storage::disk('files-s3-private')->exists('exim/export.csv'));

        // Run again: nothing left to copy.
        $this->artisan('torgash:files-copy', ['--to' => 's3'])
            ->expectsTable(['copied', 'already there', 'failed', 'MB'], [[0, 3, 0, 0.0]])
            ->assertSuccessful();

        foreach ([...$local, ...$bucket] as $root) {
            File::deleteDirectory($root);
        }
    }

    public function test_the_storage_screen_says_where_files_live_and_checks_them(): void
    {
        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->get('http://localhost/admin/storage')
            ->assertOk()
            ->assertSee('torgash:files-copy');

        $this->actingAs($admin)->post('http://localhost/admin/storage/check')
            ->assertRedirect(route('admin.storage'))
            ->assertSessionHas('probes', fn ($probes) => $probes['public']['ok'] && $probes['private']['ok']);
    }
}
