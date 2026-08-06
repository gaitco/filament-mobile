<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
        });

        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->boolean('published')->default(false);
            $table->timestamps();
        });

        Schema::create('banners', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable();
            // The searchable half of the same relationship, for the
            // optionsUrl trigger: a searchable select stops inlining its
            // options regardless of how many there are.
            $table->foreignId('searchable_company_id')->nullable();
            $table->foreignId('scoped_company_id')->nullable();
            $table->string('name');
            $table->string('status')->default('draft');
            // The two columns behind ->numeric(): a plain quantity, and a
            // field typed `email` by refineType() while still validated as a
            // value bound rather than a length bound.
            $table->string('quantity')->nullable();
            $table->string('numeric_email')->nullable();
            $table->string('bounded_quantity')->nullable();
            // The RichEditor and email fixture columns. Nullable here only
            // because seedBannerWith() never supplies them — the `required`
            // rule that matters is the one the form publishes, not a DB-level
            // constraint this fixture would then have to satisfy everywhere.
            $table->text('body_html')->nullable();
            $table->string('contact_email')->nullable();
            // Never referenced by the card or the infolist: it is the column
            // the serialisation whitelist has to keep out of the payload.
            $table->text('internal_note')->nullable();
            // Cast to array on the model. A closure reading it sees a JSON
            // *string* from getAttributes() and a real array from
            // attributesToArray() — the observable difference that pins which
            // one the form seeds from.
            $table->json('options')->nullable();
            $table->string('featured_note')->nullable();
            // The column a Hidden field's default() writes. Nothing publishes
            // it, so only FormDefaults can put a value there.
            $table->string('kind')->nullable();
            // The two columns Filament itself would refuse to save: one behind
            // a disabled section, one the field's dehydration closure rejects.
            $table->string('locked_note')->nullable();
            $table->string('secret_note')->nullable();
            $table->string('relation_note')->nullable();
            // The operation- and record-scoped gates review found open.
            $table->string('bypass_note')->nullable();
            $table->string('op_note')->nullable();
            $table->string('rec_note')->nullable();
            $table->string('strict_note')->nullable();
            $table->string('gated_note')->nullable();
            $table->string('hidden_note')->nullable();
            $table->string('probe_note')->nullable();
            // Gated on `kind`, which is Hidden and so never written: the two
            // columns a crafted sibling would open if the write path built its
            // schema from the payload. One per endpoint — `gate_note` for
            // update, `sib_note` for create.
            $table->string('gate_note')->nullable();
            $table->string('sib_note')->nullable();
            // The two-hop probe: `kind` opens `lever`, whose default plants
            // itself in the trusted state, which opens `victim_note`.
            $table->string('lever')->nullable();
            $table->string('victim_note')->nullable();
            // Named by the infolist only — absent from both the card and the
            // form. The witness for the record endpoint widening its payload
            // with infolistPaths() specifically, as distinct from formPaths().
            $table->string('infolist_only_note')->nullable();
            // The column a `file` field names. Upload is P6, so nothing this
            // package serves may ever write it — the fixture exists so that a
            // write which *did* reach it would be observable.
            $table->string('avatar')->nullable();
            // Two Hidden fields share this one, under `caption.ar` and
            // `caption.en`: a dotted field name is a PATH, and FormDefaults
            // wrote it as a literal key until the write pilot's review.
            $table->json('caption')->nullable();
            // A multiple select's LIST-valued default. Merged recursively, a
            // submitted `['c']` over a default of `['a','b','c']` lands here as
            // `['c','b','c']` — PHP merges lists by index.
            $table->json('plain_multi')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        // Minimal: exists so `tag_ids` on BannerResource's form resolves a
        // real BelongsToMany. Nothing here ever writes a pivot row — the
        // write path never calls saveRelationships() — so this fixture is
        // introspected, not exercised.
        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('banner_tag', function (Blueprint $table): void {
            $table->foreignId('banner_id');
            $table->foreignId('tag_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banner_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('banners');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('users');
    }
};
