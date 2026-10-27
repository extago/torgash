<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Attachments were written to the `local` disk, which is the engine's own
 * scratch folder; they now live on `private`, the disk that may be a private
 * bucket. Locally both are the same folder, so no file moves — only the name
 * the media row remembers.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('media')->where('collection_name', 'attachments')->where('disk', 'local')->update(['disk' => 'private']);
        DB::table('media')->where('collection_name', 'attachments')->where('conversions_disk', 'local')->update(['conversions_disk' => 'private']);
    }

    public function down(): void
    {
        DB::table('media')->where('collection_name', 'attachments')->where('disk', 'private')->update(['disk' => 'local']);
        DB::table('media')->where('collection_name', 'attachments')->where('conversions_disk', 'private')->update(['conversions_disk' => 'local']);
    }
};
