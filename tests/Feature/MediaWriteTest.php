<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Gallery;
use Gait\FilamentMobile\Tests\Fixtures\Resources\GalleryResource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * P14 Task 4: the write-path relation pass reconciles a Spatie media
 * collection instead of ignoring it. Not in the shared TestCase resource
 * list — same reasoning as MediaFixtureTest: `galleries` would change
 * ContractSnapshotTest's golden.
 */
beforeEach(function () {
    // 'public' is where an ATTACHED media item lives (Spatie's own
    // `media-library.disk_name` default); the upload FIELD's own disk is a
    // separate Filament setting (`filament.default_filesystem_disk`,
    // defaulting to 'local') that GalleryResource's fields never override —
    // so a FRESH upload's stored path lives there instead, and both must be
    // faked for a test that touches both a pre-attached item and a fresh
    // upload in the same request.
    Storage::fake('public');
    Storage::fake(config('filament.default_filesystem_disk'));
    config()->set('filament-mobile.resources', [GalleryResource::class]);
    $this->gallery = Gallery::create(['name' => 'Trip']);
    $this->a = $this->gallery->addMediaFromString(fakePngBytes())->usingFileName('a.jpg')->toMediaCollection('photos');
    $this->b = $this->gallery->addMediaFromString(fakePngBytes())->usingFileName('b.jpg')->toMediaCollection('photos');
});

it('keeps submitted uuids, consumes fresh paths, deletes omissions', function () {
    // A fresh upload through the real endpoint mints a stored path.
    $path = $this->actingAs(makeUser('admin'))
        ->post('/api/mobile-panel/galleries/upload', [
            'field' => 'photos',
            'file' => UploadedFile::fake()->image('c.png'),
        ])->assertOk()->json('path');

    $response = $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/galleries/{$this->gallery->id}", [
            'name' => 'Trip',
            'photos' => [$this->a->uuid, $path],   // keep a, add c, omit b
        ])->assertOk();

    $media = $this->gallery->refresh()->getMedia('photos');

    // UploadController deliberately discards the client's original filename
    // (`c.png`) and stores under a minted uuid+extension — see its
    // `Str::uuid()->toString() . '.' . $extension` naming — so the fresh
    // media's own file_name is the stored path's basename, not 'c.png'.
    expect($media)->toHaveCount(2)
        ->and($media->pluck('uuid'))->toContain($this->a->uuid)
        ->and($media->pluck('file_name'))->toContain(basename($path))
        ->and($media->pluck('uuid'))->not->toContain($this->b->uuid);

    // EXTRA REQUIREMENT: the write RESPONSE body itself reflects the
    // post-reconciliation collection — MediaReconciler runs before
    // RecordSerializer builds the response, not after.
    $data = $response->json('data');

    expect($data['photos'])->toBeArray()->toContain($this->a->uuid)
        ->and($data['photos'])->not->toContain($this->b->uuid)
        ->and($data['photos.__media'])->toBeArray()->toHaveCount(2)
        ->and(collect($data['photos.__media'])->pluck('uuid'))->toContain($this->a->uuid)
        ->and(collect($data['photos.__media'])->pluck('name'))->toContain(basename($path));
});

it('clears on explicit [] and leaves an unmentioned field untouched', function () {
    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/galleries/{$this->gallery->id}", ['name' => 'Renamed'])
        ->assertOk();
    expect($this->gallery->refresh()->getMedia('photos'))->toHaveCount(2);

    $response = $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/galleries/{$this->gallery->id}", ['name' => 'Renamed', 'photos' => []])
        ->assertOk();
    expect($this->gallery->refresh()->getMedia('photos'))->toHaveCount(0);

    $data = $response->json('data');
    expect($data['photos'])->toBe([])
        ->and($data['photos.__media'])->toBe([]);
});

it('refuses a foreign uuid with a 422 keyed to the field', function () {
    $other = Gallery::create(['name' => 'Other']);
    $foreign = $other->addMediaFromString(fakePngBytes())->usingFileName('x.jpg')->toMediaCollection('photos');

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/galleries/{$this->gallery->id}", [
            'name' => 'Trip',
            'photos' => [$foreign->uuid],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['photos']);

    // Nothing was destroyed by the refused write.
    expect($this->gallery->refresh()->getMedia('photos'))->toHaveCount(2);
});

it('refuses a scalar submitted for a multiple field', function () {
    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/galleries/{$this->gallery->id}", [
            'name' => 'Trip',
            'photos' => $this->a->uuid, // scalar, not a list — photos is ->multiple()
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['photos']);

    expect($this->gallery->refresh()->getMedia('photos'))->toHaveCount(2);
});

it('attaches media on create from fresh upload paths', function () {
    $path = $this->actingAs(makeUser('admin'))
        ->post('/api/mobile-panel/galleries/upload', [
            'field' => 'photos',
            'file' => UploadedFile::fake()->image('first.png'),
        ])->json('path');

    $response = $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/galleries', ['name' => 'New', 'photos' => [$path]])
        ->assertCreated();

    $id = $response->json('data.id');

    expect(Gallery::find($id)->getMedia('photos'))->toHaveCount(1);

    $data = $response->json('data');
    expect($data['photos'])->toBeArray()->toHaveCount(1)
        ->and($data['photos.__media'])->toBeArray()->toHaveCount(1);
});

// --- Task 4 review fixes: the add-token path was unvalidated. ---

it('refuses a path token that references another record\'s stored file, and destroys nothing', function () {
    $other = Gallery::create(['name' => 'Other']);
    $foreignMedia = $other->addMediaFromString(fakePngBytes())->usingFileName('secret.jpg')->toMediaCollection('photos');

    // Spatie's OWN storage convention for an existing media item
    // (`{media_id}/{file_name}`) — never the shape UploadController mints —
    // is exactly the kind of token a crafted payload could try to smuggle
    // through as a fresh "add".
    $theftToken = $foreignMedia->getPathRelativeToRoot();

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/galleries/{$this->gallery->id}", [
            'name' => 'Trip',
            'photos' => [$this->a->uuid, $theftToken],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['photos']);

    // This record's own media is untouched by the refusal...
    expect($this->gallery->refresh()->getMedia('photos'))->toHaveCount(2);

    // ...and the victim's media row and its underlying file both survive —
    // `preservingOriginal(false)` never ran against them.
    expect($other->refresh()->getMedia('photos'))->toHaveCount(1);
    expect(Storage::disk('public')->exists($theftToken))->toBeTrue();
});

it('refuses a malformed token before deleting anything', function () {
    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/galleries/{$this->gallery->id}", [
            'name' => 'Trip',
            'photos' => [$this->a->uuid, 'nonsense'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['photos']);

    expect($this->gallery->refresh()->getMedia('photos'))->toHaveCount(2);
});

it('refuses the same fresh-upload path listed twice, before consuming either copy', function () {
    $path = $this->actingAs(makeUser('admin'))
        ->post('/api/mobile-panel/galleries/upload', [
            'field' => 'photos',
            'file' => UploadedFile::fake()->image('dup.png'),
        ])->json('path');

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/galleries/{$this->gallery->id}", [
            'name' => 'Trip',
            'photos' => [$this->a->uuid, $path, $path],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['photos']);

    expect($this->gallery->refresh()->getMedia('photos'))->toHaveCount(2);
    // Neither copy was consumed: the source file is exactly where the
    // upload endpoint left it, on the FIELD's own disk (not 'public' —
    // see the beforeEach comment).
    expect(Storage::disk(config('filament.default_filesystem_disk'))->exists($path))->toBeTrue();
});

it('attaches a fresh upload whose minted path has no extension', function () {
    // UploadController mints a BARE uuid (no extension at all) when the
    // sniffed MIME type falls outside SAFE_EXTENSIONS — simulated directly
    // on the fake disk rather than smuggling a non-image past the field's
    // own `mimetypes` rule, which the real endpoint would 422 on first. A
    // bare uuid is indistinguishable BY SHAPE from an ordinary uuid token;
    // only the minted-path-plus-existence check (checked before the
    // foreign-uuid refusal) tells the two apart.
    $bareUuidPath = (string) Str::uuid();
    Storage::disk(config('filament.default_filesystem_disk'))->put($bareUuidPath, fakePngBytes());

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/galleries/{$this->gallery->id}", [
            'name' => 'Trip',
            'photos' => [$this->a->uuid, $bareUuidPath],
        ])
        ->assertOk();

    $media = $this->gallery->refresh()->getMedia('photos');

    expect($media)->toHaveCount(2)
        ->and($media->pluck('uuid'))->toContain($this->a->uuid);
});

it('refuses the whole write when one of two media fields fails classification, leaving both untouched', function () {
    $other = Gallery::create(['name' => 'Other']);
    $foreignCover = $other->addMediaFromString(fakePngBytes())->usingFileName('cover.jpg')->toMediaCollection('cover');

    $this->gallery->addMediaFromString(fakePngBytes())->usingFileName('cover.jpg')->toMediaCollection('cover');
    expect($this->gallery->getMedia('cover'))->toHaveCount(1);

    $path = $this->actingAs(makeUser('admin'))
        ->post('/api/mobile-panel/galleries/upload', [
            'field' => 'photos',
            'file' => UploadedFile::fake()->image('good.png'),
        ])->json('path');

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/galleries/{$this->gallery->id}", [
            'name' => 'Trip',
            'photos' => [$path], // valid on its own: would drop a and b, add the fresh one
            'cover' => $foreignCover->uuid, // invalid: belongs to another record
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['cover']);

    // `photos` classified successfully in isolation, but must not have been
    // APPLIED ahead of `cover`'s refusal — both fields reconcile together or
    // not at all.
    expect($this->gallery->refresh()->getMedia('photos'))->toHaveCount(2)
        ->and($this->gallery->getMedia('cover'))->toHaveCount(1);
});

it('reconciles a single-file field on its own', function () {
    $path = $this->actingAs(makeUser('admin'))
        ->post('/api/mobile-panel/galleries/upload', [
            'field' => 'cover',
            'file' => UploadedFile::fake()->image('cover.png'),
        ])->assertOk()->json('path');

    $response = $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/galleries/{$this->gallery->id}", [
            'name' => 'Trip',
            'cover' => $path,
        ])
        ->assertOk();

    $media = $this->gallery->refresh()->getMedia('cover');

    expect($media)->toHaveCount(1)
        ->and($media->first()->file_name)->toBe(basename($path));

    $data = $response->json('data');
    expect($data['cover'])->toBe($media->first()->uuid)
        ->and($data['cover.__media'])->toBeArray()->toHaveCount(1);
});

it('refuses the same fresh path claimed by two different media fields, before either consumes it', function () {
    $path = $this->actingAs(makeUser('admin'))
        ->post('/api/mobile-panel/galleries/upload', [
            'field' => 'photos',
            'file' => UploadedFile::fake()->image('shared.png'),
        ])->json('path');

    // Each field's OWN classification passes in isolation — the file still
    // exists, since nothing has applied yet. Only checking ACROSS plans
    // catches the double claim.
    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/galleries/{$this->gallery->id}", [
            'name' => 'Trip',
            'photos' => [$path],
            'cover' => $path,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['cover']);

    // Neither field moved: `photos` still holds a and b, `cover` is still
    // empty, and the source file was never consumed.
    expect($this->gallery->refresh()->getMedia('photos'))->toHaveCount(2)
        ->and($this->gallery->getMedia('cover'))->toHaveCount(0);
    expect(Storage::disk(config('filament.default_filesystem_disk'))->exists($path))->toBeTrue();
});
