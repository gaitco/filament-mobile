<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Support;

use Filament\Support\Contracts\HasLabel;

/**
 * A backed enum implementing Filament's HasLabel — the shape
 * `getNavigationGroup()` permits and a naive `(string)` cast on the enum
 * object itself fatals on. See PanelSchemaBuilder::groupOf().
 */
enum TagGroupEnum: string implements HasLabel
{
    case Catalog = 'catalog';

    public function getLabel(): string
    {
        return 'Catalog';
    }
}
