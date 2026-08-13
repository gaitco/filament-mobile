<?php

declare(strict_types=1);

use Gait\FilamentMobile\Http\DashboardController;
use Gait\FilamentMobile\Http\MobilePanelController;
use Gait\FilamentMobile\Http\OptionsController;
use Gait\FilamentMobile\Http\RelationController;
use Gait\FilamentMobile\Http\StateController;
use Gait\FilamentMobile\Http\UploadController;
use Illuminate\Support\Facades\Route;

$guard = config('filament-mobile.guard');

// Host middleware first, auth last: locale negotiation and the like must have
// run by the time the controller serialises labels.
$middleware = array_merge(
    (array) config('filament-mobile.middleware', []),
    [$guard === null ? 'auth' : "auth:{$guard}"],
);

Route::prefix(config('filament-mobile.prefix'))
    ->middleware($middleware)
    ->name('filament-mobile.')
    ->group(function (): void {
        // Registered before the wildcard so the literal wins the match.
        Route::get('schema', [MobilePanelController::class, 'schema'])->name('schema');
        // A literal, like `schema` — above the {resource} wildcards so it is
        // never matched as a resource key.
        Route::get('dashboard', DashboardController::class)->name('dashboard');
        // Above {resource}/{record} so `state` is never captured as a record id.
        Route::post('{resource}/state', StateController::class)->name('state');
        // Same reason, same placement: `options` is a literal, not a record id.
        Route::post('{resource}/options', OptionsController::class)->name('options');
        // A literal segment, like `state` and `options` — above the record
        // wildcard so `upload` is never captured as a record id.
        Route::post('{resource}/upload', UploadController::class)->name('upload');
        // Above {resource}/{record} so a POST cannot be shadowed by it.
        Route::post('{resource}', [MobilePanelController::class, 'store'])->name('store');
        Route::get('{resource}', [MobilePanelController::class, 'index'])->name('index');
        // Placed here for consistency with `state`/`options` above, though
        // unlike those two this route has no actual competitor: it is a
        // four-segment POST, and nothing else registered matches that shape
        // regardless of ordering.
        Route::post('{resource}/{record}/actions/{action}', [MobilePanelController::class, 'runAction'])
            ->name('run-action');
        // A four-segment GET. Placed above `{resource}/{record}` for
        // consistency with the routes around it, though — like `run-action` —
        // it has no actual competitor at this segment count.
        Route::get('{resource}/{record}/relations/{relation}', RelationController::class)
            ->name('relations');
        // P9: relation row writes. Same segment-count reasoning as the GET
        // above — nothing else registered matches a four- or five-segment
        // shape, so ordering here is for the reader, not the matcher.
        Route::post('{resource}/{record}/relations/{relation}', [RelationController::class, 'store'])
            ->name('relations.store');
        Route::put('{resource}/{record}/relations/{relation}/{child}', [RelationController::class, 'update'])
            ->name('relations.update');
        Route::delete('{resource}/{record}/relations/{relation}/{child}', [RelationController::class, 'destroy'])
            ->name('relations.destroy');
        Route::get('{resource}/{record}', [MobilePanelController::class, 'show'])->name('show');
        Route::put('{resource}/{record}', [MobilePanelController::class, 'update'])->name('update');
        Route::delete('{resource}/{record}', [MobilePanelController::class, 'destroy'])->name('destroy');
    });
