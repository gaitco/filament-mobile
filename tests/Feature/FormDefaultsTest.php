<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;

/**
 * The write pilot's second package-breaking finding.
 *
 * Filament's own CreateRecord fills the form with each field's `->default()`
 * before saving. This package did not, so `Hidden::make('type')->default(...)`
 * — the ordinary way a resource stamps a row as belonging to it — was dropped,
 * and the created record landed outside the resource's own getEloquentQuery():
 * a 201 followed by a 404 on every read, update and delete of the row that had
 * just been created. Three of the pilot panel's 33 resources did exactly this.
 */
it('applies a hidden field default the client can never send', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', ['name' => 'Defaulted', 'body_html' => '<p>Body</p>'])
        ->assertCreated();

    expect(Banner::query()->where('name', 'Defaulted')->value('kind'))->toBe('promo');
});

it('lets a submitted value win over the default', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', ['name' => 'Explicit', 'body_html' => '<p>Body</p>', 'status' => 'active'])
        ->assertCreated();

    // `status` has no default; `kind` does and is not publishable, so the pair
    // pins that defaults sit *under* the payload rather than over it.
    $banner = Banner::query()->where('name', 'Explicit')->firstOrFail();

    expect($banner->status)->toBe('active')
        ->and($banner->kind)->toBe('promo');
});

it('does not re-apply defaults on update', function () {
    $banner = seedBanner('Existing');
    $banner->update(['kind' => 'legacy']);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", ['name' => 'Renamed', 'body_html' => '<p>Body</p>'])
        ->assertOk();

    // Filament fills defaults when creating, never when editing: an edit that
    // reset every defaulted column would silently undo the record's history.
    expect($banner->fresh()->kind)->toBe('legacy');
});

it('nests a dotted field name rather than writing it as a literal key', function () {
    // Review's catch on the write pilot: RuleExtractor treats `caption.ar` as
    // a path and FormDefaults did not, so the create mass-assigned an
    // attribute literally named `caption.ar` — an unknown column, or silently
    // dropped under an explicit $fillable, which is the orphan row this class
    // exists to prevent.
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', ['name' => 'Dotted', 'body_html' => '<p>Body</p>'])
        ->assertCreated();

    expect(Banner::query()->where('name', 'Dotted')->value('caption'))
        ->toBe(['ar' => 'AR default']);
});

it('keeps a defaulted locale when its sibling is submitted', function () {
    // The half that is easy to miss: both sides nest under one `caption` key,
    // so a spread merge replaces the whole array and drops `ar`. The payload
    // has to be filled in path by path, `caption.ar` on its own.
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', [
            'name' => 'Partial',
            'body_html' => '<p>Body</p>',
            'caption' => ['en' => 'submitted'],
        ])
        ->assertCreated();

    // toEqual, not toBe: the submitted locale is written first and the default
    // fills in behind it, so the stored key ORDER is payload-first. Nothing
    // reads a JSON object's key order, and pinning it here would only make the
    // test fail the next time the fill order changes.
    expect(Banner::query()->where('name', 'Partial')->value('caption'))
        ->toEqual(['ar' => 'AR default', 'en' => 'submitted']);
});

it('never overwrites a submitted scalar with a default nested under it', function () {
    // `caption` is a field in its own right AND the first segment of
    // `caption.ar`'s path — the write half of the title/title.ar collision.
    // data_set() would replace the text the user typed with ['ar' => ...],
    // i.e. the default beating the input, which no merge may ever do.
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', [
            'name' => 'Collided',
            'body_html' => '<p>Body</p>',
            'caption' => 'user typed this',
        ])
        ->assertCreated();

    expect(Banner::query()->where('name', 'Collided')->value('caption'))
        ->toBe('user typed this');
});

it('does not merge a list-valued default into the submitted list', function () {
    // The other way the same merge goes wrong, and the reason the fix cannot
    // be array_replace_recursive(): PHP merges lists BY INDEX, so a default of
    // ['a','b','c'] under a submitted ['c'] stores ['c','b','c'] — the user
    // picked one option and the record kept three. A submitted key is answered
    // whole, never merged into.
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', [
            'name' => 'Listed',
            'body_html' => '<p>Body</p>',
            'plain_multi' => ['c'],
        ])
        ->assertCreated();

    expect(Banner::query()->where('name', 'Listed')->value('plain_multi'))
        ->toBe(['c']);
});

it('still applies a list-valued default the payload omits', function () {
    // The control for the case above: skipping the merge must not turn into
    // skipping the default.
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', ['name' => 'Unlisted', 'body_html' => '<p>Body</p>'])
        ->assertCreated();

    expect(Banner::query()->where('name', 'Unlisted')->value('plain_multi'))
        ->toBe(['a', 'b', 'c']);
});
