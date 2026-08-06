<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\Tests\Fixtures\Models\Company;

/**
 * Declares no mobile() — proves opt-in. It must never reach the mobile panel,
 * however many other resources do.
 */
class SecretResource extends Resource
{
    protected static ?string $model = Company::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name'),
        ]);
    }
}
