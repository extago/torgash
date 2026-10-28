<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A cover's thumbnails used to follow the shop's fit for product photos, so
 * under "Pad" a wide cover became a square with white bands and its card
 * showed them. Covers keep their own shape now (Post::registerMediaConversions);
 * this makes the thumbnails of the covers already uploaded once more, so an
 * updated shop sees the change without rebuilding every picture it has (3105
 * of them took 28 minutes on the live shop).
 */
return new class extends Migration
{
    public function up(): void
    {
        $manipulator = app(FileManipulator::class);

        Media::query()->where('model_type', 'Torgash\Blog\Models\Post')->where('collection_name', 'gallery')
            ->orderBy('id')->each(function (Media $media) use ($manipulator) {
                try {
                    $manipulator->createDerivedFiles($media);
                } catch (Throwable) {
                    // An unreadable original keeps its old thumbnails; the
                    // media screen's "Rebuild all thumbnails" reports it.
                }
            });
    }

    public function down(): void
    {
        // Nothing to put back: thumbnails are made from the originals.
    }
};
