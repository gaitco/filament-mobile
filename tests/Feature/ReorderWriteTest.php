<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Slide;
use Gait\FilamentMobile\Tests\Fixtures\Resources\ArticleResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\SlideBrokenResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\SlideDescResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\SlideDisabledResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\SlidePivotResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\SlideResource;
use Illuminate\Support\Facades\DB;

// P18 Task 4: `POST {resource}/reorder`. Same fixture-registration pattern as
// ReorderListTest's beforeEach — these resources exist for this slice only.
beforeEach(function () {
    config()->set('filament-mobile.resources', [
        SlideResource::class,
        SlideDescResource::class,
        SlidePivotResource::class,
        ArticleResource::class,
        SlideBrokenResource::class,
        SlideDisabledResource::class,
    ]);
});

/**
 * Slides A,B,C,D at positions 1..4, distinct titles.
 *
 * @return array<string, int> letter => id
 */
function seedFourSlides(): array
{
    $ids = [];

    foreach (['A', 'B', 'C', 'D'] as $i => $letter) {
        $ids[$letter] = Slide::create([
            'title' => "Slide {$letter}",
            'position' => $i + 1,
        ])->id;
    }

    return $ids;
}

/** @return array<string, int> letter => current position, read fresh from the DB */
function positionsOf(array $ids): array
{
    return array_map(fn (int $id): int => Slide::find($id)->position, $ids);
}

it('reorders slides ascending — one UPDATE query, positions match the posted order', function () {
    $ids = seedFourSlides();

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        if (str_starts_with(strtolower($query->sql), 'update')) {
            $queries[] = $query->sql;
        }
    });

    $response = test()->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/slides/reorder', [
            'order' => [$ids['D'], $ids['B'], $ids['A'], $ids['C']],
        ])
        ->assertOk()
        ->assertExactJson(['message' => 'Reordered.']);

    expect($queries)->toHaveCount(1);

    expect(positionsOf($ids))->toBe(['A' => 3, 'B' => 2, 'C' => 4, 'D' => 1]);
});

it('reorders a desc-reorderable resource — direction reversed, same rows', function () {
    $ids = seedFourSlides();

    test()->actingAs(makeUser('other'))
        ->postJson('/api/mobile-panel/slide-descs/reorder', [
            'order' => [$ids['D'], $ids['B'], $ids['A'], $ids['C']],
        ])
        ->assertOk();

    // desc: the FIRST posted key gets the HIGHEST position.
    expect(positionsOf($ids))->toBe(['A' => 2, 'B' => 3, 'C' => 1, 'D' => 4]);
});

it('404s for an unknown resource key, the registry\'s own message', function () {
    test()->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/nope/reorder', ['order' => [1]])
        ->assertStatus(404)
        ->assertJson(['message' => 'No mobile resource [nope].']);
});

it('404s for a resource with no reorder column, the SAME message shape — indistinguishable from unknown', function () {
    test()->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/articles/reorder', ['order' => [1]])
        ->assertStatus(404)
        ->assertJson(['message' => 'No mobile resource [articles].']);
});

it('403s for reorderable()\'s own condition being false — same as an authorizeReorder() denial, never a 404', function () {
    // SlideDisabledResource: condition: false, no authorizeReorder()
    // override (default: always authorized). The column IS declared —
    // ReorderDeclaration::for() finds it, so this is not the "resource
    // doesn't exist" 404 above. Filament's own gate folds a false condition
    // and a false authorizeReorder() into the same isReorderable() == false
    // outcome (CanReorderRecords.php:104) with no way to tell them apart
    // from the public API alone, so this package doesn't invent a
    // distinction Filament itself doesn't make.
    test()->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/slide-disableds/reorder', ['order' => [1]])
        ->assertStatus(403);
});

it('403s when authorizeReorder() refuses this user', function () {
    $ids = seedFourSlides();

    test()->actingAs(makeUser('other'))
        ->postJson('/api/mobile-panel/slides/reorder', [
            'order' => [$ids['D'], $ids['B'], $ids['A'], $ids['C']],
        ])
        ->assertStatus(403);

    expect(positionsOf($ids))->toBe(['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4]);
});

it('422s a missing order key', function () {
    test()->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/slides/reorder', [])
        ->assertStatus(422);
});

it('422s a non-array order', function () {
    test()->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/slides/reorder', ['order' => 'x'])
        ->assertStatus(422);
});

it('422s an empty order', function () {
    test()->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/slides/reorder', ['order' => []])
        ->assertStatus(422);
});

it('422s a non-scalar order entry', function () {
    test()->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/slides/reorder', ['order' => [[1]]])
        ->assertStatus(422);
});

it('422s a boolean order entry — a bool is scalar but must not survive as a 0/1 id', function () {
    test()->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/slides/reorder', ['order' => [true]])
        ->assertStatus(422);
});

it('422s a duplicate order entry', function () {
    $ids = seedFourSlides();

    test()->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/slides/reorder', [
            'order' => [$ids['A'], $ids['A'], $ids['B']],
        ])
        ->assertStatus(422);

    expect(positionsOf($ids))->toBe(['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4]);
});

it('422s a foreign id outside the resource\'s own query, and changes nothing', function () {
    $ids = seedFourSlides();

    test()->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/slides/reorder', [
            'order' => [$ids['A'], $ids['B'], 999999],
        ])
        ->assertStatus(422)
        ->assertJson(['message' => 'Unknown record in order.']);

    expect(positionsOf($ids))->toBe(['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4]);
});

it('422s a zero-padded string id rather than 500ing on a loose-compare DB match', function () {
    // SQLite's numeric affinity coerces "01" into 1 for a whereIn() against
    // an INTEGER column, so the membership query itself matches slide A —
    // but the resolved collection is keyed by the DB's OWN value (int 1),
    // and PHP array-keys "01" (non-canonical) and 1 apart. Never a 500: the
    // per-key ->get() miss aborts 422, the same answer a truly-unknown id
    // gets, matching how show()/update() already treat a route key that
    // doesn't resolve.
    $ids = seedFourSlides();

    test()->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/slides/reorder', [
            'order' => ['0' . $ids['A'], $ids['B'], $ids['C'], $ids['D']],
        ])
        ->assertStatus(422)
        ->assertJson(['message' => 'Unknown record in order.']);

    expect(positionsOf($ids))->toBe(['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4]);
});

it('500s and writes nothing when the UPDATE itself throws (unknown column)', function () {
    // Not a demonstration of a genuine mid-write rollback — a missing column
    // fails at PREPARE, before any row is touched, so this proves "500 +
    // nothing written + the exception propagates uncaught" rather than
    // "a partially-applied UPDATE was undone". A true rollback demo would
    // need a second write inside the same transaction closure that succeeds
    // and then gets undone when a LATER statement throws — deliberately not
    // built here, since DB::transaction()'s rollback-on-exception behaviour
    // is Laravel's own guarantee, not something this endpoint implements.
    $ids = seedFourSlides();

    test()->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/slide-brokens/reorder', [
            'order' => [$ids['D'], $ids['B'], $ids['A'], $ids['C']],
        ])
        ->assertStatus(500);

    expect(positionsOf($ids))->toBe(['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4]);
});
