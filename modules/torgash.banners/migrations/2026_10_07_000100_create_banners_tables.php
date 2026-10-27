<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Banners, sliders and the templates that draw them (PLAN §51).
 *
 * A TEMPLATE is HTML with {tokens}, CSS and the fields its tokens are — made
 * in the admin, or restored from a preset file of the module or the theme.
 * A banner template draws one slot in up to three SHAPES (wide, card,
 * strip); a frame template draws a slider around its slides. A banner row is
 * only the VALUES of its template's fields — never a style column (an older engine's
 * banner table carries ~250 per-device ones).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_banners_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            // banner / frame
            $table->string('kind', 8);
            $table->string('name', 190);
            // A frame's slot shape: wide / card / strip.
            $table->string('shape', 8)->nullable();
            // {"wide": "<div>…", "card": …} for a banner; {"frame": …} for a frame.
            $table->jsonb('html')->default('{}');
            $table->text('css')->nullable();
            // [{"key", "type", "label", "help", "default", "options", "on"}], in order.
            $table->jsonb('fields')->default('[]');
            // The preset it came from — "module:hero", "theme:default:hero" — for "restore".
            $table->string('preset', 190)->nullable();
            $table->timestamps();
        });

        Schema::create('m_banners_banners', function (Blueprint $table) {
            $table->id();
            // The admin's own name for it — never printed on the page.
            $table->string('name', 190);
            $table->foreignId('template_id')->constrained('m_banners_templates')->restrictOnDelete();
            // The template's fields: translatable words as {"en": …}, the rest
            // plain; pictures are media collections `f_<key>`.
            $table->jsonb('values')->default('{}');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('enabled')->default(true);
            $table->jsonb('rules')->nullable();
            $table->string('rules_match', 3)->default('all');
            $table->timestamps();
        });

        Schema::create('m_banners_sliders', function (Blueprint $table) {
            $table->id();
            $table->string('name', 190);
            $table->foreignId('frame_id')->constrained('m_banners_templates')->restrictOnDelete();
            // The frame's own fields.
            $table->jsonb('values')->default('{}');
            // How it turns: autoplay, loop, effect, per view.
            $table->jsonb('settings')->default('{}');
            // A second slider the frame may print as {aside} — the cards beside a slide.
            $table->foreignId('aside_id')->nullable()->constrained('m_banners_sliders')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('m_banners_slider_banner', function (Blueprint $table) {
            $table->foreignId('slider_id')->constrained('m_banners_sliders')->cascadeOnDelete();
            $table->foreignId('banner_id')->constrained('m_banners_banners')->cascadeOnDelete();
            $table->integer('position')->default(0);
            $table->primary(['slider_id', 'banner_id']);
            $table->index('banner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_banners_slider_banner');
        Schema::dropIfExists('m_banners_sliders');
        Schema::dropIfExists('m_banners_banners');
        Schema::dropIfExists('m_banners_templates');
    }
};
