<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Slide;
use Gait\FilamentMobile\Tests\Fixtures\Resources\ArticleResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\SlideDescResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\SlideDisabledResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\SlidePivotResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\SlideResource;

// P18 Task 3: `GET {resource}?reorder=1`. Not the shared TestCase list — same
// reasoning as ReorderSchemaTest's beforeEach: these fixtures exist for this
// slice only.
beforeEach(function () {
    config()->set('filament-mobile.resources', [
        SlideResource::class,
        SlideDescResource::class,
        SlidePivotResource::class,
        ArticleResource::class,
        SlideDisabledResource::class,
    ]);
});

/**
 * 25 rows — one more than `per_page` (20) — with SHUFFLED `position` values,
 * so ascending-by-position order differs from both insertion (id) order and
 * title order. `title` carries an "Alpha"/"Beta" split for the search test:
 * the first 5 positions (1..5) are Alpha, the rest Beta, so a search that
 * narrows to Alpha also proves the narrowed set stays position-ordered.
 *
 * @return list<int> the 25 created ids, in insertion order
 */
function seedShuffledSlides(): array
{
    $positions = range(1, 25);
    shuffle($positions);

    $ids = [];

    foreach ($positions as $i => $position) {
        $label = $position <= 5 ? 'Alpha' : 'Beta';

        $ids[] = Slide::create([
            'title' => "{$label} slide {$position}",
            'position' => $position,
        ])->id;
    }

    return $ids;
}

it('serves all 25 rows in ascending position order with meta.last_page == 1', function () {
    seedShuffledSlides();

    $response = test()->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/slides?reorder=1')
        ->assertOk()
        ->json();

    expect($response['data'])->toHaveCount(25);

    $positions = array_column(array_map(
        fn (array $row) => Slide::find($row['id']),
        $response['data'],
    ), 'position');

    expect($positions)->toBe(range(1, 25))
        ->and($response['meta'])->toBe([
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => 25,
            'total' => 25,
        ]);
});

it('serves a desc-reorderable resource in descending position order', function () {
    seedShuffledSlides();

    $response = test()->actingAs(makeUser('other'))
        ->getJson('/api/mobile-panel/slide-descs?reorder=1')
        ->assertOk()
        ->json();

    $positions = array_column(array_map(
        fn (array $row) => Slide::find($row['id']),
        $response['data'],
    ), 'position');

    expect($positions)->toBe(range(25, 1));
});

it('ignores sort/direction in reorder mode — still ordered by position, no 422', function () {
    seedShuffledSlides();

    $response = test()->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/slides?reorder=1&sort=title&direction=desc')
        ->assertOk()
        ->json();

    $positions = array_column(array_map(
        fn (array $row) => Slide::find($row['id']),
        $response['data'],
    ), 'position');

    expect($positions)->toBe(range(1, 25));
});

it('still applies search in reorder mode, narrowed set stays position-ordered', function () {
    seedShuffledSlides();

    $response = test()->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/slides?reorder=1&search=Alpha')
        ->assertOk()
        ->json();

    expect($response['data'])->toHaveCount(5)
        ->and($response['meta'])->toBe([
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => 5,
            'total' => 5,
        ]);

    $positions = array_column(array_map(
        fn (array $row) => Slide::find($row['id']),
        $response['data'],
    ), 'position');

    expect($positions)->toBe(range(1, 5));
});

it('422s ?reorder=1 on a resource with no reorderable column', function () {
    test()->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/articles?reorder=1')
        ->assertStatus(422)
        ->assertJson(['message' => 'Resource [articles] is not reorderable.']);
});

it('422s ?reorder=1 for a user authorizeReorder denies', function () {
    seedShuffledSlides();

    test()->actingAs(makeUser('other'))
        ->getJson('/api/mobile-panel/slides?reorder=1')
        ->assertStatus(422)
        ->assertJson(['message' => 'Resource [slides] is not reorderable.']);
});

it('422s ?reorder=1 when reorderable()\'s own condition is false', function () {
    // SlideDisabledResource: condition: false, no authorizeReorder()
    // override — must 422 the same as any other non-reorderable resource,
    // not silently succeed because the (default) authorizer allows it.
    test()->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/slide-disableds?reorder=1')
        ->assertStatus(422)
        ->assertJson(['message' => 'Resource [slide-disableds] is not reorderable.']);
});

it('treats ?reorder=0 as the normal paginated list', function () {
    seedShuffledSlides();

    $response = test()->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/slides?reorder=0')
        ->assertOk()
        ->json();

    expect($response['data'])->toHaveCount(20)
        ->and($response['meta']['last_page'])->toBe(2)
        ->and($response['meta']['total'])->toBe(25);
});

it('treats ?reorder=yes as the normal paginated list', function () {
    seedShuffledSlides();

    $response = test()->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/slides?reorder=yes')
        ->assertOk()
        ->json();

    expect($response['data'])->toHaveCount(20)
        ->and($response['meta']['last_page'])->toBe(2)
        ->and($response['meta']['total'])->toBe(25);
});
