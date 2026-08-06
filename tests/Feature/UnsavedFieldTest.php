<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;

/**
 * The write pilot's most serious finding.
 *
 * `->disabled(fn () => ! auth()->user()->can('rates.manage'))` is how a Filament
 * panel makes a column read-only for a user who may edit the record but not
 * that field, and `->dehydrated(fn ($state) => filled($state) && $user->can(...))`
 * is how it stops a crafted save from overwriting a stored secret. The mobile
 * write path consulted neither, so it re-opened every gate the web panel closes.
 *
 * Measured on the pilot panel for an ordinary low-privilege account: 12 fields
 * across 8 resources, including `customs_duty_rate`, `vat_rate`,
 * `exchange_rate` and an encrypted credential.
 */
it('never writes a field inside a disabled container', function () {
    $banner = seedBanner('Gated');

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => 'Gated',
            'body_html' => '<p>Body</p>',
            'locked_note' => 'crafted',
        ])
        ->assertOk();

    expect($banner->fresh()->locked_note)->toBeNull();
});

it('never writes a field whose dehydration closure takes no state', function () {
    $banner = seedBanner('Undehydrated');

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => 'Undehydrated',
            'body_html' => '<p>Body</p>',
            'relation_note' => 'crafted',
        ])
        ->assertOk();

    expect($banner->fresh()->relation_note)->toBeNull();
});

/**
 * The shape that survived the first fix. `dehydrated(fn ($state) => ...)` can
 * only be evaluated by a component wired into its schema, and the rule
 * extractor was reading nested children out of the raw stored array, where
 * every accessor throws and the guard fails open. On the pilot panel that
 * field was `password`, and this request blanked the stored hash.
 */
it('honours a state-dependent dehydration closure on a nested field', function () {
    $banner = seedBanner('Nested');
    $banner->update(['secret_note' => 'kept']);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => 'Nested',
            'body_html' => '<p>Body</p>',
            'secret_note' => '',
        ])
        ->assertOk();

    expect($banner->fresh()->secret_note)->toBe('kept');
});

it('still writes it when the closure says yes', function () {
    $banner = seedBanner('Nested');

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => 'Nested',
            'body_html' => '<p>Body</p>',
            'secret_note' => 'supplied',
        ])
        ->assertOk();

    expect($banner->fresh()->secret_note)->toBe('supplied');
});

/**
 * The banner resource's form as /schema publishes it, flattened — the two
 * fields at issue sit inside a Section, which is where a real panel puts them.
 *
 * @return array<string, array<string, mixed>> by field name
 */
function publishedBannerForm(array $nodes): array
{
    $flat = [];

    foreach ($nodes as $node) {
        if ($node['name'] !== null) {
            $flat[$node['name']] = $node;
        }

        $flat = [...$flat, ...publishedBannerForm($node['children'] ?? [])];
    }

    return $flat;
}

/**
 * The other half of every test above: refusing the write is only half a
 * contract if /schema still draws the field editable. It did — `relation_note`
 * and `strict_note` came back `disabled: false`, the POST answered 201, and
 * both columns stayed NULL. The user's typing was discarded behind a success
 * response with nothing on the wire to say so.
 */
it('publishes as disabled exactly the fields the write path always refuses', function () {
    $banners = collect(
        $this->actingAs(makeUser('admin'))
            ->getJson('/api/mobile-panel/schema')
            ->assertOk()
            ->json('resources'),
    )->firstWhere('key', 'banners');

    $nodes = publishedBannerForm($banners['form']);

    expect($nodes['relation_note']['disabled'])->toBeTrue()
        ->and($nodes['strict_note']['disabled'])->toBeTrue()
        // Not "locked": a state-dependent dehydration closure is false on the
        // empty form /schema walks because nobody has typed yet. Publishing it
        // as disabled makes a password unsettable from a phone forever.
        ->and($nodes['secret_note']['disabled'])->toBeFalse()
        // Untouched for the same reason it always was: a single-valued
        // relationship select dehydrates normally.
        ->and($nodes['company_id']['disabled'])->toBeFalse();
});

it('leaves every infolist entry editable-flagged as before', function () {
    // `Entry::isDehydrated()` is a hard `false` — an entry displays, it never
    // saves — so a naive read of the write predicate would lock every one of
    // them and change what `disabled` means on a display-only component.
    $banners = collect(
        $this->actingAs(makeUser('admin'))
            ->getJson('/api/mobile-panel/schema')
            ->assertOk()
            ->json('resources'),
    )->firstWhere('key', 'banners');

    expect($banners['infolist'])->not->toBeEmpty();

    foreach ($banners['infolist'] as $entry) {
        expect($entry['disabled'])->toBeFalse();
    }
});

it('never writes either one on create', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', [
            'name' => 'Fresh',
            'body_html' => '<p>Body</p>',
            'locked_note' => 'crafted',
            'relation_note' => 'crafted',
        ])
        ->assertCreated();

    $banner = Banner::query()->where('name', 'Fresh')->firstOrFail();

    expect($banner->locked_note)->toBeNull()
        ->and($banner->relation_note)->toBeNull();
});
