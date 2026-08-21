<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// P15: the Article fixture's own table, plus spatie/laravel-tags' tag
// tables published from its stub (vendor/spatie/laravel-tags/database/
// migrations/create_tag_tables.php.stub) with the tag table renamed to
// `spatie_tags` — the existing fixture `Tag` model keeps the plain `tags`
// table it already owns (0001_01_01_000000_create_fixture_tables.php), so
// this is a separate table entirely, not a migration of it. The pivot's
// foreign key is repointed at `spatie_tags` accordingly.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('spatie_tags', function (Blueprint $table): void {
            $table->id();

            $table->json('name');
            $table->json('slug');
            $table->string('type')->nullable();
            $table->integer('order_column')->nullable();

            $table->timestamps();
        });

        Schema::create('taggables', function (Blueprint $table): void {
            // P15 Task 4: kept literally `tag_id`, NOT the
            // `Str::snake(class_basename('SpatieTag')) . '_id'` Eloquent
            // would otherwise infer (`spatie_tag_id`) — `HasTags::syncTagIds()`
            // (called by `syncTagsWithType()`, the typed-field write path)
            // hardcodes the literal column name `tag_id` in a raw join, with
            // no config to remap it. `Article::tags()` overrides the
            // relation's inferred pivot key to match this literal column, so
            // both the manual query and Eloquent's own relation agree on one
            // name.
            $table->foreignId('tag_id')->constrained('spatie_tags')->cascadeOnDelete();

            $table->morphs('taggable');

            $table->unique(['tag_id', 'taggable_id', 'taggable_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('spatie_tags');
        Schema::dropIfExists('articles');
    }
};
