<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// P17: the Notice fixture's own table, behind the dev-only
// spatie/laravel-translatable dependency. `caption` is `json` because that is
// the column shape `HasTranslations` reads/writes — a map of locale => string,
// not the plain array cast every other fixture model uses for a dotted field.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notices', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->json('caption')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notices');
    }
};
