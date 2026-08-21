<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

/**
 * A resource with a web sidebar count badge, for the schema's `badge` node.
 * The throwing colour is deliberate on a second fixture, not here — this one
 * proves the happy path verbatim.
 */
class BadgedCompanyResource extends CompanyResource
{
    protected static ?string $slug = 'badged-companies';

    public static function getNavigationBadge(): ?string
    {
        return '124';
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }
}
