<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `image_url` holds a LIST now — pictures by external link, several, comma
 * separated (Product::externalImageUrls(), behind the `external_images`
 * setting) — and 255 characters is two long CDN addresses. The name stays:
 * the API, the exchange and the invariants already read it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE m_catalog_products ALTER COLUMN image_url TYPE text');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE m_catalog_products ALTER COLUMN image_url TYPE varchar(255) USING left(image_url, 255)');
    }
};
