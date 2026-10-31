<?php

namespace App\Core\Translation;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\FileLoader;

/**
 * The framework's lang-file loader with the shop's own wording laid over
 * the JSON strings (TextOverrides): what `__('Bonus points')` returns in
 * Russian is the module's ru.json unless the shop said otherwise.
 *
 * Only the JSON strings: the PHP groups (validation, pagination) are the
 * framework's own and have no screen. Built from the loader the framework
 * registered (CoreServiceProvider), so every path a module or theme adds
 * afterwards lands here as before.
 */
class TextLoader extends FileLoader
{
    public function __construct(Filesystem $files, array|string $path, protected TextOverrides $overrides)
    {
        parent::__construct($files, $path);
    }

    public static function from(FileLoader $loader, Filesystem $files, TextOverrides $overrides): self
    {
        $own = new self($files, $loader->paths(), $overrides);

        foreach ($loader->jsonPaths() as $path) {
            $own->addJsonPath($path);
        }

        foreach ($loader->namespaces() as $namespace => $hint) {
            $own->addNamespace($namespace, $hint);
        }

        return $own;
    }

    protected function loadJsonPaths($locale)
    {
        return array_replace(parent::loadJsonPaths($locale), $this->overrides->for($locale));
    }
}
