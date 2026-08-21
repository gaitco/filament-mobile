<?php

declare(strict_types=1);

use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\Introspection\HeadlessSchemaHost;
use Gait\FilamentMobile\Tests\Fixtures\Models\Gallery;
use Gait\FilamentMobile\Write\MediaReconciler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Wires a bare component into a real (headless) Schema bound to `$model` —
 * exactly the shape `RecordForm::schema()` builds for every write endpoint.
 * Without this, `getDiskName()`'s own `getModelInstance()` (vendor) throws on
 * a component built by `::make()` alone, since nothing bound it to a record.
 */
function boundMediaComponent(object $component, Model $model): object
{
    return Schema::make(new HeadlessSchemaHost())
        ->model($model)
        ->operation('edit')
        ->components([$component])
        ->getComponents()[0];
}

/**
 * P14 final review, Finding 1: `->required()`/`->minFiles()`/`->maxFiles()`
 * on a Spatie upload never reached the wire as a Laravel rule at all — the
 * component is relationship-saved, so RuleExtractor::fromComponents() drops
 * it before rulesFor()'s file branch ever runs (see that class's own
 * docblock). Enforced here instead, off the plan's own final count, since
 * `classify()` is the one place that already knows what the submission WOULD
 * leave the collection holding.
 *
 * A bare Gallery model + an inline component, never a Resource fixture: the
 * rule lives on `classify()` alone, and building it through GalleryResource
 * would risk the OTHER MediaWriteTest cases (which rely on `photos` staying
 * un-required so an explicit `[]` clears it).
 */
beforeEach(function (): void {
    Storage::fake('public');
    $this->gallery = Gallery::create(['name' => 'Trip']);
});

it('refuses a required field submitted empty, leaving the collection untouched', function () {
    $existing = $this->gallery->addMediaFromString(fakePngBytes())->usingFileName('a.jpg')->toMediaCollection('photos');

    $component = SpatieMediaLibraryFileUpload::make('photos')->collection('photos')->multiple()->required();

    expect(fn () => MediaReconciler::classify($this->gallery, 'photos', $component, 'photos', []))
        ->toThrow(ValidationException::class);

    // classify() never mutates — the collection is exactly as it was.
    expect($this->gallery->refresh()->getMedia('photos')->pluck('uuid')->all())
        ->toBe([$existing->uuid]);
});

it('refuses a submission under minFiles', function () {
    $a = $this->gallery->addMediaFromString(fakePngBytes())->usingFileName('a.jpg')->toMediaCollection('photos');

    $component = boundMediaComponent(
        SpatieMediaLibraryFileUpload::make('photos')->collection('photos')->multiple()->minFiles(2),
        $this->gallery,
    );

    expect(fn () => MediaReconciler::classify($this->gallery, 'photos', $component, 'photos', [$a->uuid]))
        ->toThrow(ValidationException::class);
});

it('refuses a submission over maxFiles', function () {
    $a = $this->gallery->addMediaFromString(fakePngBytes())->usingFileName('a.jpg')->toMediaCollection('photos');
    $b = $this->gallery->addMediaFromString(fakePngBytes())->usingFileName('b.jpg')->toMediaCollection('photos');
    $c = $this->gallery->addMediaFromString(fakePngBytes())->usingFileName('c.jpg')->toMediaCollection('photos');

    $component = boundMediaComponent(
        SpatieMediaLibraryFileUpload::make('photos')->collection('photos')->multiple()->maxFiles(2),
        $this->gallery,
    );

    expect(fn () => MediaReconciler::classify(
        $this->gallery,
        'photos',
        $component,
        'photos',
        [$a->uuid, $b->uuid, $c->uuid],
    ))->toThrow(ValidationException::class);
});

it('passes when the submission satisfies required, minFiles and maxFiles', function () {
    $a = $this->gallery->addMediaFromString(fakePngBytes())->usingFileName('a.jpg')->toMediaCollection('photos');

    $component = boundMediaComponent(
        SpatieMediaLibraryFileUpload::make('photos')
            ->collection('photos')
            ->multiple()
            ->required()
            ->minFiles(1)
            ->maxFiles(3),
        $this->gallery,
    );

    $plan = MediaReconciler::classify($this->gallery, 'photos', $component, 'photos', [$a->uuid]);

    expect($plan['keep'])->toBe([$a->uuid])
        ->and($plan['add'])->toBe([]);
});

it('does not apply minFiles/maxFiles bounds to a single-file field', function () {
    // A single-file field's stored value is a scalar; minFiles()/maxFiles()
    // would be meaningless for one path (the same rule SchemaWalker's own
    // config() applies), so a single-file component that happens to declare
    // them must not have them enforced here. minFiles(2) with 0 submitted
    // would throw if this rule were not applied.
    $component = SpatieMediaLibraryFileUpload::make('cover')->collection('cover')->minFiles(2)->maxFiles(3);

    $plan = MediaReconciler::classify($this->gallery, 'cover', $component, 'cover', '');

    expect($plan['keep'])->toBe([])
        ->and($plan['add'])->toBe([]);
});

/**
 * P14 final review, Finding 3: a throw from `addMediaFromDisk()` inside the
 * add loop — after the delete loop already ran — was an uncaught 500 over
 * media rows and files this same call had just destroyed. Not reproducible
 * through one HTTP request (classify()'s own exists() check runs first and
 * would refuse the same missing file before apply() ever sees it), so this
 * covers `apply()` directly with a hand-built plan whose add path does not
 * exist on disk.
 */
it('turns an apply()-time storage failure into a legible ValidationException', function () {
    $plan = [
        'record' => $this->gallery,
        'field' => 'photos',
        'collection' => 'photos',
        'keep' => [],
        'add' => ['does/not/exist.jpg'],
        'disk' => 'public',
    ];

    expect(fn () => MediaReconciler::apply($plan))->toThrow(ValidationException::class);
});
