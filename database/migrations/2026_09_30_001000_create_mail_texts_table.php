<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * What the shop wrote over a letter's default words (PLAN §41): one row per
 * EVENT, each text a translatable map, so a letter nobody touched has no row
 * and speaks its module's own default in the reader's language. `recipients`
 * is for letters to the shop (a new order to the director), `enabled` lets
 * the shop stop a letter it does not want sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_texts', function (Blueprint $table) {
            $table->id();
            $table->string('event', 80)->unique();
            $table->jsonb('subject')->nullable();
            $table->jsonb('heading')->nullable();
            $table->jsonb('body')->nullable();
            $table->jsonb('button')->nullable();
            $table->boolean('enabled')->default(true);
            $table->text('recipients')->nullable();
            $table->timestamps();
        });

        Permission::findOrCreate('mail.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_texts');
        Permission::where('name', 'mail.manage')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
