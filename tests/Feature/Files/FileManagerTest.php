<?php

namespace Tests\Feature\Files;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Torgash\Files\Models\Folder;
use Torgash\Files\Models\StoredFile;

/**
 * The file manager (torgash.files): a file is taken by what its bytes are,
 * its link never moves when it is renamed or moved, an SVG is rebuilt, and
 * deleting a row (or its folder) takes the bytes along.
 */
class FileManagerTest extends TestCase
{
    protected User $admin;

    protected string $base = 'http://localhost/admin/files';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->admin = User::role('admin')->firstOrFail();
    }

    public function test_a_picture_is_stored_under_its_own_name_and_keeps_its_link(): void
    {
        $folder = Folder::create(['name' => 'Avito']);

        $this->actingAs($this->admin)
            ->post($this->base.'/upload', ['folder' => $folder->id, 'files' => [$this->png('Красный ботинок.png', 800, 600)]], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('stored', 1);

        $file = StoredFile::firstOrFail();
        $this->assertSame('files/krasnyi-botinok.png', $file->path);
        $this->assertSame([800, 600], [$file->width, $file->height]);
        $this->assertSame($folder->id, $file->folder_id);
        Storage::disk('public')->assertExists($file->path);
        $this->assertNotNull($file->thumb_path, 'a picture wider than the grid gets a thumbnail');
        Storage::disk('public')->assertExists($file->thumb_path);
        $this->assertStringNotContainsString('/.', $file->thumb_path, 'a web server refuses a folder starting with a dot');

        // Both views draw it; the list is remembered for the next visit.
        $this->actingAs($this->admin)->get($this->base.'?folder='.$folder->id.'&view=list')->assertOk()->assertSee('Красный ботинок.png');
        $this->actingAs($this->admin)->get($this->base.'?folder='.$folder->id)->assertOk()->assertSee('<table', false);

        // The same name again is a new address, never the old file replaced.
        $this->actingAs($this->admin)->post($this->base.'/upload', ['files' => [$this->png('Красный ботинок.png', 10, 10)]], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('files/krasnyi-botinok-2.png', StoredFile::latest('id')->first()->path);

        // Renamed and moved: rows change, the link does not.
        $link = $file->url();
        $this->actingAs($this->admin)->patch($this->base.'/'.$file->id, ['name' => 'Boot, red'])->assertRedirect();
        $this->actingAs($this->admin)->post($this->base.'/bulk', ['action' => 'move', 'ids' => [$file->id], 'target' => 0])->assertRedirect();
        $file->refresh();
        $this->assertSame('Boot, red', $file->name);
        $this->assertNull($file->folder_id);
        $this->assertSame($link, $file->url());
        $this->assertStringStartsWith('http', $link, 'a link pasted into a feed must be absolute');
    }

    public function test_a_short_code_names_the_file_when_the_shop_says_so(): void
    {
        app(SettingsManager::class)->set('torgash.files', 'file_names', 'code');

        $this->actingAs($this->admin)->post($this->base.'/upload', ['files' => [$this->png('photo.png', 10, 10)]], ['Accept' => 'application/json'])->assertOk();

        $this->assertMatchesRegularExpression('#^files/[a-z0-9]{12}\.png$#', StoredFile::firstOrFail()->path);
    }

    public function test_what_a_browser_would_run_is_refused_and_an_svg_is_rebuilt(): void
    {
        $this->actingAs($this->admin)->post($this->base.'/upload', ['files' => [$this->file('photo.jpg', "<?php echo 'hi';")]], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->actingAs($this->admin)->post($this->base.'/upload', ['files' => [$this->file('page.html', '<html><script>alert(1)</script></html>')]], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->assertSame(0, StoredFile::count());

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" onload="alert(1)"><script>alert(2)</script><circle cx="5" cy="5" r="4"/></svg>';
        $this->actingAs($this->admin)->post($this->base.'/upload', ['files' => [$this->file('icon.svg', $svg)]], ['Accept' => 'application/json'])->assertOk();

        $stored = Storage::disk('public')->get(StoredFile::firstOrFail()->path);
        $this->assertStringContainsString('<circle', $stored);
        $this->assertStringNotContainsString('script', $stored);
        $this->assertStringNotContainsString('onload', $stored);
    }

    public function test_deleting_a_folder_takes_everything_inside_and_its_bytes(): void
    {
        $top = Folder::create(['name' => 'Blocks']);
        $inner = Folder::create(['name' => 'Icons', 'parent_id' => $top->id]);

        $this->actingAs($this->admin)->post($this->base.'/upload', ['folder' => $inner->id, 'files' => [$this->png('a.png', 500, 500)]], ['Accept' => 'application/json'])->assertOk();
        $file = StoredFile::firstOrFail();

        $this->actingAs($this->admin)->get($this->base.'?folder='.$inner->id)->assertOk()->assertSee('a.png')->assertSee('Blocks');

        $this->actingAs($this->admin)->delete($this->base.'/folders/'.$top->id)->assertRedirect();

        $this->assertSame(0, Folder::count());
        $this->assertSame(0, StoredFile::count());
        Storage::disk('public')->assertMissing($file->path);
        Storage::disk('public')->assertMissing($file->thumb_path);
    }

    protected function png(string $name, int $width, int $height): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 30));
        $path = tempnam(sys_get_temp_dir(), 'png');
        imagepng($image, $path);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** A real upload over real bytes, so the type is sniffed as it would be. */
    protected function file(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fm');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }
}
