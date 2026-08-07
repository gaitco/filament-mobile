<?php

declare(strict_types=1);

use Gait\FilamentMobile\RelationCard;

it('makes the first column the title and the second the subtitle', function () {
    $card = RelationCard::fromColumns([
        ['name' => 'name', 'label' => 'Name'],
        ['name' => 'status', 'label' => 'Status'],
        ['name' => 'ignored', 'label' => 'Ignored'],
    ]);

    expect($card->toArray())->toBe([
        'title' => ['field' => 'name'],
        'subtitle' => ['field' => 'status'],
    ]);
});

it('makes a title-only card from a single column', function () {
    $card = RelationCard::fromColumns([['name' => 'name', 'label' => 'Name']]);

    expect($card->toArray())->toBe(['title' => ['field' => 'name']])
        ->and($card->toArray())->not->toHaveKey('subtitle');
});

it('returns null when there are no columns to derive from', function () {
    expect(RelationCard::fromColumns([]))->toBeNull();
});
