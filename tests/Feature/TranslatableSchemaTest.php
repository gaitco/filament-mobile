<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Resources\NoticeResource;

/**
 * P17 Task 2: `panel.locales` (plugin → config → absent, never `[]`) and the
 * node-level `translatable: true` annotation (dotted leaf whose head segment
 * is one of the model's OWN `getTranslatableAttributes()`).
 *
 * No test here installs the official `filament/spatie-laravel-translatable-plugin`
 * — it is not a dependency of this package and is not cheaply installable
 * alongside `spatie/laravel-translatable` (the model-trait package Task 1
 * added). No panel is ever registered in this suite (see
 * `PanelDiscoveryTest`'s own note), so `Filament::getCurrentOrDefaultPanel()`
 * throws in every one of these tests and `PanelSchemaBuilder::locales()`'s
 * fail-closed `try/catch` around the plugin lookup is exercised on every run
 * here, falling through to the config source — the guard path the brief asks
 * for when the plugin itself is not exercised directly.
 */
it('omits panel.locales when neither the plugin nor config answers', function () {
    expect(schemaDocument()['panel'])->not->toHaveKey('locales');
});

it('publishes panel.locales from config, flat and ordered as declared', function () {
    config()->set('filament-mobile.locales', ['ar', 'en']);

    expect(schemaDocument()['panel']['locales'])->toBe(['ar', 'en']);
});

it('does not publish panel.locales for a malformed config value', function () {
    config()->set('filament-mobile.locales', ['ar', 42]);

    expect(schemaDocument()['panel'])->not->toHaveKey('locales');
});

it('does not publish panel.locales for an empty config value — never []', function () {
    config()->set('filament-mobile.locales', []);

    expect(schemaDocument()['panel'])->not->toHaveKey('locales');
});

it('annotates the dotted siblings of a real translatable attribute, never the undotted field', function () {
    config()->set('filament-mobile.resources', [NoticeResource::class]);

    $notice = schemaFor('notices');

    expect(findFormNode($notice, 'caption.ar')['translatable'])->toBeTrue()
        ->and(findFormNode($notice, 'caption.en')['translatable'])->toBeTrue()
        ->and(findFormNode($notice, 'title'))->not->toHaveKey('translatable');
});

it('never annotates a dotted field on a model without the real HasTranslations trait', function () {
    // Banner's `caption` is cast to `array`, never `Spatie\Translatable\
    // HasTranslations` — the fake-cast fixture the design spec names as the
    // zero-golden-drift proof: `laravel-panel.json` has no trait-backed model,
    // so nothing here can ever gain the key.
    $banner = schemaFor('banners');

    expect(findFormNode($banner, 'caption.en'))->not->toHaveKey('translatable')
        ->and(findFormNode($banner, 'caption'))->not->toHaveKey('translatable');
});
