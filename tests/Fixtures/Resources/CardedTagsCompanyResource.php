<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;

/**
 * A company whose BANNERS relation card lists the delimited tags column.
 *
 * The sixth serialize seam, and the one P7 Task 3 fix round 1 excluded on the
 * wrong reasoning that a relation "has no form": the child rows are banners,
 * and `separated_labels`' shape is declared by `BannerResource::form()` — the
 * resource that writes the column — not by whatever lists it.
 *
 * Its own card is `CompanyResource`'s; only the relation card differs, so a
 * failure here can only be about the relation seam.
 */
class CardedTagsCompanyResource extends CompanyResource
{
    protected static ?string $slug = 'carded-tags-companies';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('name'))
            ->relationCard('banners', fn (MobileCard $card) => $card
                ->title('name')
                ->meta('separated_labels'));
    }
}
