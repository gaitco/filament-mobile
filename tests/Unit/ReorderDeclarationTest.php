<?php

declare(strict_types=1);

use Gait\FilamentMobile\Introspection\ReorderDeclaration;
use Gait\FilamentMobile\Tests\Fixtures\Resources\ArticleResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\SlideDescResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\SlideDisabledResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\SlidePivotResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\SlideResource;

it('reads the reorder column and default direction off the table', function () {
    $declaration = ReorderDeclaration::for(SlideResource::class);

    expect($declaration)->not->toBeNull()
        ->and($declaration->column)->toBe('position')
        ->and($declaration->direction)->toBe('asc');
});

it('reads a desc direction', function () {
    expect(ReorderDeclaration::for(SlideDescResource::class)?->direction)->toBe('desc');
});

it('is null for a table that declares no reorder column', function () {
    expect(ReorderDeclaration::for(ArticleResource::class))->toBeNull();
});

it('is null for a dotted (pivot) column — not offered on mobile', function () {
    expect(ReorderDeclaration::for(SlidePivotResource::class))->toBeNull();
});

it('authorizes through the table, with the request bound', function () {
    // SlideResource's table: ->authorizeReorder(fn () => request()->user()?->email === 'admin@example.test')
    $admin = makeUser('admin');
    $other = makeUser('other');

    expect(ReorderDeclaration::authorizes(SlideResource::class, requestAs($admin)))->toBeTrue()
        ->and(ReorderDeclaration::authorizes(SlideResource::class, requestAs($other)))->toBeFalse();
});

it('honours reorderable()\'s own condition, independent of authorizeReorder()', function () {
    // SlideDisabledResource authorizes everyone but declares
    // condition: false — Filament's isReorderable() is
    // filled(column) && evaluate($condition) && isReorderAuthorized(),
    // so a false condition must refuse even a fully-authorized user.
    $anyone = makeUser('anyone');

    expect(ReorderDeclaration::authorizes(SlideDisabledResource::class, requestAs($anyone)))->toBeFalse();
});
