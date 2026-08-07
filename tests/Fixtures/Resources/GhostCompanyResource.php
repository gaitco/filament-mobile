<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Company;
use Gait\FilamentMobile\Tests\Fixtures\Relations\GhostsRelationManager;

/**
 * A relation manager naming a relationship the owner model does not have.
 *
 * Published, it is a control that cannot work: `/schema` advertises it, the
 * endpoint refuses, and nothing explains why. See GhostsRelationManager.
 */
class GhostCompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $slug = 'ghost-companies';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('name'));
    }

    public static function getRelations(): array
    {
        return [
            GhostsRelationManager::class,
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name'),
        ]);
    }
}
