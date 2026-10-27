<?php

use App\Core\Support\Phones;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A code channel names COUNTRIES, not calling codes: +1 is the USA and
 * Canada, +7 Russia and Kazakhstan, so "only 7" could not say which. The
 * codes already typed are read as the first country that uses each.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_automations_code_channels', function (Blueprint $table) {
            $table->string('countries')->nullable()->after('secrets');
        });

        foreach (DB::table('m_automations_code_channels')->whereNotNull('prefixes')->get(['id', 'prefixes']) as $row) {
            $countries = collect(preg_split('/[\s,;+]+/', (string) $row->prefixes) ?: [])
                ->filter(fn ($code) => ctype_digit($code))
                ->map(fn ($code) => Phones::countryForCallingCode((int) $code))
                ->filter()->unique()->implode(' ');

            DB::table('m_automations_code_channels')->where('id', $row->id)->update(['countries' => $countries ?: null]);
        }

        Schema::table('m_automations_code_channels', function (Blueprint $table) {
            $table->dropColumn('prefixes');
        });
    }

    public function down(): void
    {
        Schema::table('m_automations_code_channels', function (Blueprint $table) {
            $table->string('prefixes')->nullable();
            $table->dropColumn('countries');
        });
    }
};
