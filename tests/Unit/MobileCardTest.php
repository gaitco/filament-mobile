<?php

declare(strict_types=1);

use Gait\FilamentMobile\MobileCard;

it('emits the contract card shape', function () {
    $card = MobileCard::make()
        ->title('name')
        ->subtitle('company.name')
        ->leadingImage('image_url', fallback: 'initials')
        ->badge('status', ['active' => 'success', 'draft' => 'warning'])
        ->meta('created_at', format: 'date');

    expect($card->toArray())->toBe([
        'title' => ['field' => 'name'],
        'subtitle' => ['field' => 'company.name'],
        'leading' => ['type' => 'image', 'field' => 'image_url', 'fallback' => 'initials'],
        'badges' => [
            ['field' => 'status', 'colors' => ['active' => 'success', 'draft' => 'warning']],
        ],
        'meta' => [
            ['field' => 'created_at', 'format' => 'date'],
        ],
    ]);
});

it('omits absent slots entirely rather than emitting nulls', function () {
    expect(MobileCard::make()->title('name')->toArray())
        ->toBe(['title' => ['field' => 'name']]);
});

it('is valid with no slots at all', function () {
    expect(MobileCard::make()->toArray())->toBe([]);
});

it('accepts several badges and meta lines in declaration order', function () {
    $card = MobileCard::make()
        ->badge('status')
        ->badge('tier')
        ->meta('created_at')
        ->meta('updated_at');

    expect(array_column($card->toArray()['badges'], 'field'))->toBe(['status', 'tier'])
        ->and(array_column($card->toArray()['meta'], 'field'))->toBe(['created_at', 'updated_at']);
});

it('lists every referenced field path', function () {
    $card = MobileCard::make()
        ->title('name')
        ->subtitle('company.name')
        ->badge('status')
        ->meta('created_at');

    expect($card->fieldPaths())->toBe(['name', 'company.name', 'status', 'created_at']);
});

it('derives relation prefixes for eager loading, deduplicated', function () {
    $card = MobileCard::make()
        ->title('company.name')
        ->subtitle('company.owner.email')
        ->meta('created_at');

    expect($card->relationPaths())->toBe(['company', 'company.owner']);
});
