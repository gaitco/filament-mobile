<?php

declare(strict_types=1);

// ponytail: back-compat alias — host apps import Gait\FilamentMobile\RelationCard.
// Delete only in a release that INTENTIONALLY breaks host imports of the
// Gait\FilementMobile\{MobileCard,MobileResource,RelationCard} names; the
// promotion to gait/laravel-mobile-core deliberately kept them working.
class_alias(
    \Gait\MobileCore\RelationCard::class,
    \Gait\FilamentMobile\RelationCard::class,
);
