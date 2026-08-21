<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Gallery;
use Gait\FilamentMobile\Tests\Fixtures\Resources\GalleryResource;
use Illuminate\Support\Facades\Storage;

it('attaches media to the gallery fixture', function () {
    Storage::fake('public');

    $gallery = Gallery::create(['name' => 'Trip']);
    $gallery->addMediaFromString(fakePngBytes())
        ->usingFileName('photo.jpg')
        ->toMediaCollection('photos');

    expect($gallery->getMedia('photos'))->toHaveCount(1)
        ->and($gallery->getFirstMedia('photos')->uuid)->toBeString();
});

it('publishes the galleries resource on /schema with an editable photos field', function () {
    // Not the shared TestCase list: this fixture exists for the media slice
    // only (CompanyResource's precedent in RelationEndpointTest.php), and it
    // must stay out of the resource list ContractSnapshotTest's
    // ResourceRegistry() reads by default — a `galleries` resource there
    // would change `laravel-panel.json` and defeat that golden's job of
    // answering "server without media".
    config()->set('filament-mobile.resources', [GalleryResource::class]);

    $response = $this->actingAs(makeUser('admin'))->getJson('/api/mobile-panel/schema');

    $resource = collect($response->json('resources'))->firstWhere('key', 'galleries');

    expect($resource)->not->toBeNull();
});
