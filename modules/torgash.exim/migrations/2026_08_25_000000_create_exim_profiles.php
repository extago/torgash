<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row = one export/import configuration: a one-off export, a
 * wholesaler price list, or a scheduled CRM feed are all the same object
 * with different settings, the familiar "datafeed" shape.
 *
 * price_type_id deliberately has NO foreign-key constraint: it points at
 * m_catalog_price_types, owned by torgash.catalog, which this module does
 * not require (manifest.json requires.modules is empty) — resolved instead
 * via hook_filter('exim.resolve_price_type', ...), which catalog answers
 * only if it's installed. A real FK here would make that claim false at
 * the schema level even though the manifest doesn't say so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_exim_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('type', 8); // import | export
            $table->string('entity', 32); // product | category | feature
            $table->string('format', 8)->nullable(); // csv | xml | json — export only
            $table->string('mode', 8)->default('schema'); // schema | template
            $table->jsonb('name');
            $table->jsonb('fields')->nullable(); // schema mode: ordered selected field keys
            $table->jsonb('filters')->nullable(); // category_ids, label_ids, enabled_only, ...
            $table->text('template_body')->nullable(); // template mode only
            $table->unsignedBigInteger('price_type_id')->nullable(); // see docblock — no constrained()
            $table->string('schedule')->nullable(); // hourly | daily | cron expr
            $table->string('public_token', 64)->nullable()->unique();
            $table->string('basic_auth_user')->nullable();
            $table->string('basic_auth_pass')->nullable(); // hashed
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_file_path')->nullable(); // relative path on the `local` (private) disk
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_exim_profiles');
    }
};
