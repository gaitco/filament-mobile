<?php

declare(strict_types=1);

/**
 * A6: `url`, `regex` and `confirmed` are published in the `rules` block AND
 * enforced by the write path. Dart has parsed all three since an earlier
 * phase (`ValidationRules.url/regex/confirmed`); until this slice the server
 * never emitted them, so a `->url()` field 422'd on the web panel and sailed
 * through mobile — the one-sided contract this test closes.
 */

use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;
use Gait\FilamentMobile\Tests\Fixtures\Resources\FlaggedRegexResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\InertFieldResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\RuledBannerResource;

beforeEach(function () {
    config()->set('filament-mobile.resources', [RuledBannerResource::class]);
});

/** The one form node, by field name. */
function ruledNode(string $field): array
{
    $document = schemaDocument();
    $form = collect($document['resources'])->firstWhere('key', 'banners')['form'];

    return collect($form)->firstWhere('name', $field);
}

it('publishes url, regex and confirmed as rules hints', function () {
    expect(ruledNode('website')['rules']['url'])->toBeTrue()
        // UNDELIMITED, and this expectation is the whole finding: the first
        // cut published the pattern verbatim, and a client regex engine that
        // takes a bare pattern compiles `/^[a-z0-9_]+$/` into one that can
        // never match — the `/` is a literal, the `^` behind it unreachable —
        // so every valid handle was refused before it ever left the phone.
        // `contract/panel.json` already carried this shape.
        ->and(ruledNode('handle')['rules']['regex'])->toBe('^[a-z0-9_]+$')
        ->and(ruledNode('access_token')['rules']['confirmed'])->toBeTrue();
});

it('withholds the regex hint when the pattern carries flags it cannot express', function () {
    // Fail OPEN, not stricter-than-the-server: a client with no inline
    // modifiers would treat a case-insensitive pattern as case-sensitive and
    // refuse input the panel accepts. No hint leaves the server the only
    // judge, which it is anyway.
    config()->set('filament-mobile.resources', [FlaggedRegexResource::class]);

    expect(ruledNode('handle')['rules'] ?? [])->not->toHaveKey('regex');
});

it('enforces a flagged pattern server-side even with no hint published', function () {
    // The half that must not regress with the hint withheld.
    config()->set('filament-mobile.resources', [FlaggedRegexResource::class]);

    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', [
            'name' => 'Flagged',
            'handle' => 'has spaces',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['handle']]);

    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', [
            'name' => 'Flagged',
            // Upper case: accepted only because the pattern is /i.
            'handle' => 'OK_Handle',
        ])
        ->assertCreated();
});

it('publishes the confirmation sibling as a field the client can fill', function () {
    // The A6 corner that made ->confirmed() unusable: Filament's own idiom
    // gives the confirmation `->dehydrated(false)`, which published it
    // disabled and unwritable — so the client rendered it inert AND left it
    // out of the payload, and the server's rule then compared against a key
    // that could never arrive. Permanent 422 on a field that saved fine
    // before the rule existed.
    $node = ruledNode('access_token_confirmation');

    expect($node)->not->toBeNull()
        ->and($node['disabled'])->toBeFalse()
        // Absent means writable on this contract.
        ->and($node['writable'] ?? true)->toBeTrue();
});

it('still refuses an ordinary dehydrated(false) field that confirms nothing', function () {
    // The guard is scoped to a name a `confirmed` rule actually reads, not to
    // dehydrated(false) in general — otherwise it would readmit the silent
    // drop that predicate was written for.
    config()->set('filament-mobile.resources', [InertFieldResource::class]);

    $node = ruledNode('scratch_note');

    expect($node['disabled'])->toBeTrue()
        ->and($node['writable'])->toBeFalse();
});

it('publishes a translated message for each of the three rules', function () {
    // Through the same translator the 422 uses, like every other rule —
    // the client shows one before the round-trip and the other after it.
    expect(ruledNode('website')['rules']['messages']['url'])->toBeString()
        ->and(ruledNode('handle')['rules']['messages']['regex'])->toBeString()
        ->and(ruledNode('access_token')['rules']['messages']['confirmed'])->toBeString();
});

it('422s a non-url website, as the web panel would', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', [
            'name' => 'Ruled',
            'website' => 'not a url',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['website']]);
});

it('422s a handle that breaks the declared pattern', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', [
            'name' => 'Ruled',
            'handle' => 'NOT-OK!',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['handle']]);
});

it('422s an unconfirmed access token', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', [
            'name' => 'Ruled',
            'access_token' => 'secret-one',
            'access_token_confirmation' => 'secret-two',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['access_token']]);
});

it('accepts and persists all three when valid — and never persists the confirmation', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', [
            'name' => 'Ruled',
            'website' => 'https://example.test',
            'handle' => 'ok_handle_42',
            'access_token' => 'secret',
            'access_token_confirmation' => 'secret',
        ])
        ->assertCreated();

    $banner = Banner::query()->where('name', 'Ruled')->firstOrFail();

    expect($banner->website)->toBe('https://example.test')
        ->and($banner->handle)->toBe('ok_handle_42')
        ->and($banner->access_token)->toBe('secret')
        // dehydrated(false) earns no rule, so no validated key, so no column
        // write — even though the table has none for it to land in.
        ->and($banner->getAttributes())->not->toHaveKey('access_token_confirmation');
});
