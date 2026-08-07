<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;

/**
 * `BannerResource` with the separator-configured tags column ON ITS CARD.
 *
 * P7 Task 3 fix round 1 exists because the read half of the separator mirror
 * was wired at `show()` alone: `index()` and the `store()`/`update()` response
 * bodies serialise the CARD's fields, and a panel that lists a delimited tags
 * column there got `"a,b"` from three seams and `["a","b"]` from the fourth.
 * Nothing in the suite could see it, because no fixture card listed one.
 *
 * A separate resource rather than a `->meta()` on `BannerResource`'s own card,
 * for one reason: that card is in `contract/laravel-panel.json`, and this task
 * changes no wire shape, so the snapshot must not move. This one is registered
 * only by the tests that need it.
 *
 * It extends `BannerResource` so the form — and therefore `separated_labels`'
 * `->separator(',')` — is the same declaration under test everywhere else. A
 * copy would be free to drift into agreeing with the implementation.
 */
class CardedTagsBannerResource extends BannerResource
{
    protected static ?string $slug = 'carded-tags-banners';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card
                ->title('name')
                ->meta('separated_labels'));
    }
}
