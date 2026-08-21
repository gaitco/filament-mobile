<?php

declare(strict_types=1);

// ponytail: back-compat alias — host apps import Gait\FilamentMobile\MobileResource.
// Delete only in a release that INTENTIONALLY breaks host imports of the
// Gait\FilamentMobile\{MobileCard,MobileResource,RelationCard} names; the
// promotion to gait/laravel-mobile-core deliberately kept them working.
class_alias(
    \Gait\MobileCore\MobileResource::class,
    \Gait\FilamentMobile\MobileResource::class,
);
