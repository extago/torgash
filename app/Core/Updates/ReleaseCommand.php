<?php

namespace App\Core\Updates;

use Illuminate\Console\Command;
use Throwable;

/** `torgash:release` — build and sign a package of this version (on the developer's machine). */
class ReleaseCommand extends Command
{
    protected $signature = 'torgash:release {--key= : The secret key file (default TORGASH_RELEASE_KEY)} {--dirty : Build even with uncommitted changes}
        {--publish : Put it on the update server (TORGASH_UDS_URL, TORGASH_UDS_TOKEN)}
        {--github : Put it on GitHub as a release (TORGASH_GITHUB_REPO, TORGASH_GITHUB_PUBLISH_TOKEN)}
        {--notes= : What changed, shown to the shops}
        {--notes-file= : The same from a Markdown file: English first, each translation under a line of ---}';

    protected $description = 'Build a signed update package of this version of Torgash';

    public function handle(ReleaseBuilder $builder): int
    {
        $keyFile = (string) ($this->option('key') ?: config('torgash.updates.release_key'));
        if ($keyFile === '' || ! is_file($keyFile)) {
            $this->error('No secret key. Pass --key=<file> or set TORGASH_RELEASE_KEY (make one with torgash:release-key).');

            return self::FAILURE;
        }

        try {
            $zip = $builder->build((string) file_get_contents($keyFile), fn ($m) => $this->line('  '.$m), (bool) $this->option('dirty'));
            // Opened the way a shop opens it: with the public keys of this code.
            UpdatePackage::open($zip);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Update package: '.$zip.' ('.number_format(filesize($zip) / 1048576, 1).' MB)');
        $install = substr($zip, 0, -4).'-install.zip';
        if (is_file($install)) {
            $this->info('Distribution:   '.$install.' ('.number_format(filesize($install) / 1048576, 1).' MB)');
            $this->line('  each with .sha256 and .sig beside it — the distribution is what the download page offers');
        }

        if ($this->option('publish')) {
            try {
                $published = app(UpdateServer::class)->publish($zip, $this->notes());
            } catch (Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->info('Published '.$published['published'].' on '.app(UpdateServer::class)->url());
        }

        if ($this->option('github')) {
            $files = [];
            foreach (array_filter([$zip, is_file($install) ? $install : null]) as $archive) {
                array_push($files, $archive, $archive.'.sha256', $archive.'.sig');
            }

            try {
                $github = app(GitHubPublisher::class)->publish(
                    UpdatePackage::open($zip)->version(),
                    array_values(array_filter($files, 'is_file')),
                    $this->notes(),
                );
            } catch (Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->info('Published '.$github['tag'].' on GitHub: '.$github['url']);
        }

        return self::SUCCESS;
    }

    /** The release's text: --notes, or the Markdown of --notes-file (ReleaseNotes says its shape). */
    protected function notes(): string
    {
        $file = (string) $this->option('notes-file');

        return $file !== '' && is_file($file) ? trim((string) file_get_contents($file)) : (string) $this->option('notes');
    }
}
