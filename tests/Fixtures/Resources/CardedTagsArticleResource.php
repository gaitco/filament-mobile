<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;

/**
 * P15 Task 3: `ArticleResource` with the any-type `tags` field ON ITS CARD —
 * the same `CardedTagsBannerResource` precedent, for the tags read path
 * rather than the separator mirror. `ArticleResource`'s own card binds only
 * `title`, so its list rows never carry a `tags` key at all; this fixture is
 * what proves the card-bound-only publish AND the card-bound eager-load
 * query-count assertion.
 *
 * Extends `ArticleResource` so the form (both the any-type `tags` and typed
 * `topics` fields) is the same declaration under test everywhere else.
 */
class CardedTagsArticleResource extends ArticleResource
{
    protected static ?string $slug = 'carded-tags-articles';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card
                ->title('title')
                ->meta('tags'));
    }
}
