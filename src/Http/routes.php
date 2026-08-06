<?php

declare(strict_types=1);

use Gait\FilamentMobile\Http\MobilePanelController;
use Gait\FilamentMobile\Http\OptionsController;
use Gait\FilamentMobile\Http\StateController;
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
        // Above {resource}/{record} so `state` is never captured as a record id.
        Route::post('{resource}/state', StateController::class)->name('state');
        // Same reason, same placement: `options` is a literal, not a record id.
        Route::post('{resource}/options', OptionsController::class)->name('options');
        // Above {resource}/{record} so a POST cannot be shadowed by it.
        Route::post('{resource}', [MobilePanelController::class, 'store'])->name('store');
        Route::get('{resource}', [MobilePanelController::class, 'index'])->name('index');
        Route::get('{resource}/{record}', [MobilePanelController::class, 'show'])->name('show');
        Route::put('{resource}/{record}', [MobilePanelController::class, 'update'])->name('update');
        Route::delete('{resource}/{record}', [MobilePanelController::class, 'destroy'])->name('destroy');
    });
