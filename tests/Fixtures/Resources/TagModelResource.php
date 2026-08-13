<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Tag;

/**
 * A mobile resource over the TAG model — P9's fixture for a BelongsToMany
 * relation whose child resolves to exactly one resource, which switches the
 * write endpoints on for `BannerResource`'s `tags` relation. It is also the
 * only relation child in the fixtures with a non-`id` route key (`name` —
 * see the model), which is what proves the write endpoints resolve `{child}`
 * by the RELATED model's own route key rather than assuming `id`.
 *
 * Distinct from the older `TagResource`, which despite its name serves
 * BANNER — it is the enum-navigation-group fixture, and renaming it would
 * churn every group test for no gain.
 *
 * Not in the shared fixture list: registered only by the relation-write
 * tests, so `banners.tags` stays a zero-resource (read-only) relation
 * everywhere else.
 */
class TagModelResource extends Resource
{
    protected static ?string $model = Tag::class;

    protected static ?string $slug = 'tags';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('name'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
        ]);
    }
}
