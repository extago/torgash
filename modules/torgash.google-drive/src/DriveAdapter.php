<?php

namespace Torgash\GoogleDrive;

use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use Throwable;

/**
 * Google Drive as a flysystem adapter — paths over a store that has none.
 * Drive knows files by id, a name is a property and two children of one
 * folder may share it; the adapter walks a path segment by segment from the
 * storage's folder, takes the first child of each name, and remembers the
 * ids of the FOLDERS it has walked (a file's id is asked each time: the
 * next call may have deleted it). A write to a name that exists replaces
 * the file, so a backup re-run lands as one file, not two.
 *
 * The storage's folder (`root`, a path under "My Drive") is made on the
 * first write; with the `drive.file` scope only what the shop made is
 * visible, so the folder is the shop's own in every sense.
 */
class DriveAdapter implements FilesystemAdapter
{
    /** @var array<string, string> folder path (root-relative, '' = the root) => id */
    protected array $folders = [];

    public function __construct(protected DriveClient $client, protected string $root = '') {}

    public function fileExists(string $path): bool
    {
        return $this->node($path, false) !== null;
    }

    public function directoryExists(string $path): bool
    {
        return $this->node($path, true) !== null;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $stream = tmpfile();
        fwrite($stream, $contents);
        rewind($stream);

        try {
            $this->writeStream($path, $stream, $config);
        } finally {
            fclose($stream);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        [$folder, $name] = $this->split($path);

        try {
            $parent = $this->folderId($folder, true);

            if ($existing = $this->client->find($parent, $name, false)) {
                $this->client->delete($existing['id']);
            }

            $this->client->upload($parent, $name, $contents);
        } catch (Throwable $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function read(string $path): string
    {
        $stream = $this->readStream($path);
        $contents = (string) stream_get_contents($stream);
        fclose($stream);

        return $contents;
    }

    public function readStream(string $path)
    {
        $file = $this->node($path, false) ?? throw UnableToReadFile::fromLocation($path, 'No such file in the Drive.');

        try {
            return $this->client->download($file['id']);
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    public function delete(string $path): void
    {
        try {
            if ($file = $this->node($path, false)) {
                $this->client->delete($file['id']);
            }
        } catch (Throwable $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function deleteDirectory(string $path): void
    {
        try {
            if ($folder = $this->node($path, true)) {
                $this->client->delete($folder['id']);
                $this->forgetFolder($this->clean($path));
            }
        } catch (Throwable $e) {
            throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        try {
            $this->folderId($this->clean($path), true);
        } catch (Throwable $e) {
            throw UnableToCreateDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'A Drive file\'s visibility is set in Google Drive, not here.');
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, visibility: Visibility::PRIVATE);
    }

    public function mimeType(string $path): FileAttributes
    {
        $file = $this->node($path, false) ?? throw UnableToRetrieveMetadata::mimeType($path, 'No such file in the Drive.');

        return new FileAttributes($path, mimeType: $file['mimeType'] ?? null);
    }

    public function lastModified(string $path): FileAttributes
    {
        $file = $this->node($path, false) ?? throw UnableToRetrieveMetadata::lastModified($path, 'No such file in the Drive.');

        return new FileAttributes($path, lastModified: isset($file['modifiedTime']) ? strtotime($file['modifiedTime']) ?: null : null);
    }

    public function fileSize(string $path): FileAttributes
    {
        $file = $this->node($path, false) ?? throw UnableToRetrieveMetadata::fileSize($path, 'No such file in the Drive.');

        return new FileAttributes($path, fileSize: (int) ($file['size'] ?? 0));
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $folder = $this->clean($path);
        $id = $this->folderId($folder, false);

        if ($id === null) {
            return;
        }

        foreach ($this->client->children($id) as $child) {
            $childPath = ltrim($folder.'/'.$child['name'], '/');

            if (($child['mimeType'] ?? '') === DriveClient::FOLDER) {
                $this->folders[$childPath] = $child['id'];
                yield new DirectoryAttributes($childPath);

                if ($deep) {
                    yield from $this->listContents($childPath, true);
                }

                continue;
            }

            yield new FileAttributes(
                $childPath,
                (int) ($child['size'] ?? 0),
                Visibility::PRIVATE,
                isset($child['modifiedTime']) ? strtotime($child['modifiedTime']) ?: null : null,
                $child['mimeType'] ?? null,
            );
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $file = $this->node($source, false) ?? throw UnableToMoveFile::fromLocationTo($source, $destination);
        [$folder, $name] = $this->split($destination);

        try {
            $from = $this->folderId($this->split($source)[0], false);
            $to = $this->folderId($folder, true);

            if ($existing = $this->client->find($to, $name, false)) {
                $this->client->delete($existing['id']);
            }

            $this->client->update($file['id'], ['name' => $name], $from === $to ? [] : ['addParents' => $to, 'removeParents' => $from]);
        } catch (Throwable $e) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $file = $this->node($source, false) ?? throw UnableToCopyFile::fromLocationTo($source, $destination);
        [$folder, $name] = $this->split($destination);

        try {
            $to = $this->folderId($folder, true);

            if ($existing = $this->client->find($to, $name, false)) {
                $this->client->delete($existing['id']);
            }

            $this->client->copy($file['id'], $to, $name);
        } catch (Throwable $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    /** The file or folder at a path, or null. Never creates. */
    protected function node(string $path, bool $folder): ?array
    {
        $clean = $this->clean($path);

        if ($folder && $clean === '') {
            $id = $this->folderId('', false);

            return $id === null ? null : ['id' => $id, 'mimeType' => DriveClient::FOLDER];
        }

        [$parentPath, $name] = $this->split($clean);
        $parent = $this->folderId($parentPath, false);

        return $parent === null ? null : $this->client->find($parent, $name, $folder);
    }

    /**
     * The id of a folder of the storage ('' is the storage's own), made on
     * the way when $create — or null when it is not there.
     */
    protected function folderId(string $folder, bool $create): ?string
    {
        $folder = $this->clean($folder);

        if (isset($this->folders[$folder])) {
            return $this->folders[$folder];
        }

        // The storage's root is a path under "My Drive" too.
        $segments = array_values(array_filter(explode('/', trim($this->root, '/').'/'.$folder), fn (string $s) => $s !== ''));
        $skip = count(array_filter(explode('/', $this->root), fn (string $s) => $s !== ''));

        $id = 'root';
        $walked = [];
        foreach ($segments as $index => $name) {
            $walked[] = $name;
            $relative = implode('/', array_slice($walked, $skip));

            if ($index >= $skip && isset($this->folders[$relative])) {
                $id = $this->folders[$relative];

                continue;
            }

            $found = $this->client->find($id, $name, true);

            if ($found === null) {
                if (! $create) {
                    return null;
                }
                $id = $this->client->createFolder($id, $name);
            } else {
                $id = $found['id'];
            }

            if ($index >= $skip) {
                $this->folders[$relative] = $id;
            }
        }

        return $this->folders[$folder] = $id;
    }

    protected function forgetFolder(string $folder): void
    {
        foreach (array_keys($this->folders) as $known) {
            if ($known === $folder || str_starts_with($known, $folder.'/')) {
                unset($this->folders[$known]);
            }
        }
    }

    /** @return array{0: string, 1: string} the folder's path and the last segment */
    protected function split(string $path): array
    {
        $clean = $this->clean($path);
        $slash = strrpos($clean, '/');

        return $slash === false ? ['', $clean] : [substr($clean, 0, $slash), substr($clean, $slash + 1)];
    }

    protected function clean(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }
}
