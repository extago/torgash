<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The order of a product's individual attributes, and the sections they fall
 * into.
 *
 * Neither can live in `features` itself, and the reason is worth writing down
 * because it is invisible until you look for it: **Postgres jsonb does not keep
 * key order**. It sorts keys by length and then bytewise, so
 *
 *     {"zzz":1,"a":2,"mmmm":3,"bb":4}  →  {"a":2,"bb":4,"zzz":1,"mmmm":3}
 *
 * (measured on this database). Whatever order the admin typed was already being
 * thrown away; the specs table just happened to look deliberate.
 *
 * So the order lives here, as a flat ordered list where every row declares what
 * it is:
 *
 *     [{"type":"section","label":"Screen"},
 *      {"type":"feature","key":"size"},
 *      {"type":"feature","key":"hz"}]
 *
 * One list, two row types — which is also exactly how the editor presents it,
 * so what the admin drags is what is stored.
 *
 * `features` stays the map it has always been: the VALUES and every lookup
 * against them (variation axes, shipping conditions, exports, label rules) are
 * unaffected, and a product with no layout renders exactly as before. A key in
 * `features` that this list does not mention is shown at the end rather than
 * hidden — an import must not be able to make a spec disappear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->jsonb('features_layout')->nullable()->after('features');
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->dropColumn('features_layout');
        });
    }
};
