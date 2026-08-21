<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// P18: the `Slide` fixture's own table — `position` is what
// `ReorderDeclaration` reads off `SlideResource`'s `->reorderable('position')`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slides', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->integer('position');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slides');
    }
};
