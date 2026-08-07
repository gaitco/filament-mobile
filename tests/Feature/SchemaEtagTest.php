<?php

declare(strict_types=1);

afterEach(function () {
    app()->detectEnvironment(fn () => 'testing');
});

it('sends an ETag with the panel document', function () {
    $response = $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/schema')
        ->assertOk();

    expect($response->headers->get('ETag'))->toBeString()->not->toBeEmpty();
});

it('answers 304 with an empty body when the client already has that document', function () {
    $user = makeUser('admin');
    $etag = $this->actingAs($user)
        ->getJson('/api/mobile-panel/schema')
        ->headers->get('ETag');

    $response = $this->actingAs($user)
        ->withHeaders(['If-None-Match' => $etag])
        ->getJson('/api/mobile-panel/schema');

    // The contract promises the ETag header is still present on the 304
    // (contract/README.md). Symfony's prepare() provides it today; pin it so
    // a framework change cannot silently drop the promise.
    expect($response->getStatusCode())->toBe(304)
        ->and($response->getContent())->toBe('')
        ->and($response->headers->get('ETag'))->toBe($etag);
});

it('answers 200 for a stale If-None-Match', function () {
    $this->actingAs(makeUser('admin'))
        ->withHeaders(['If-None-Match' => '"not-the-current-one"'])
        ->getJson('/api/mobile-panel/schema')
        ->assertOk()
        ->assertJsonPath('version', 1);
});

it('gives the same user the same ETag twice', function () {
    $user = makeUser('admin');

    $first = $this->actingAs($user)->getJson('/api/mobile-panel/schema')->headers->get('ETag');
    $second = $this->actingAs($user)->getJson('/api/mobile-panel/schema')->headers->get('ETag');

    expect($first)->toBe($second);
});

it('gives users who see different resources different ETags', function () {
    // The document is per-user — policies filter it — so the hash must be
    // too, without the server knowing anything about identity.
    $admin = $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/schema')->headers->get('ETag');
    $restricted = $this->actingAs(makeUser('restricted'))
        ->getJson('/api/mobile-panel/schema')->headers->get('ETag');

    expect($admin)->not->toBe($restricted);
});

it('excludes _warnings from the hash so the ETag does not move between environments', function () {
    // `_warnings` is dev-only and not part of the contract. If it fed the
    // hash, every client would revalidate to a 200 on a production deploy
    // for a document that did not change.
    $user = makeUser('admin');

    app()->detectEnvironment(fn () => 'local');
    $local = $this->actingAs($user)->getJson('/api/mobile-panel/schema')->headers->get('ETag');

    app()->detectEnvironment(fn () => 'production');
    $production = $this->actingAs($user)->getJson('/api/mobile-panel/schema')->headers->get('ETag');

    expect($local)->toBe($production);
});

it('answers 304 for a wildcard If-None-Match, meaning "any cached copy"', function () {
    // RFC 7232 §3.2: `*` matches any current representation, distinct from
    // naming a specific ETag — the "I have *a* copy, tell me if it's stale"
    // form a client (or an intermediary) may send instead of echoing one.
    $response = $this->actingAs(makeUser('admin'))
        ->withHeaders(['If-None-Match' => '*'])
        ->getJson('/api/mobile-panel/schema');

    expect($response->getStatusCode())->toBe(304)
        ->and($response->getContent())->toBe('');
});

it('fails loudly rather than collapsing every document to the same ETag when it cannot encode', function () {
    // `json_encode()` returns false on invalid UTF-8 — reachable here through
    // `panel.title`, which PanelSchemaBuilder reads verbatim from
    // config('app.name'), no fixture contortion needed. Silently hashing the
    // resulting '' would give every failing document the same ETag, so two
    // genuinely different (both-broken) documents would collide and a
    // client would keep a stale panel forever with no way to notice — the
    // guard exists to turn that into a loud, visible 500 instead.
    config(['app.name' => "bad \xB1\x31 utf8"]);

    $this->withoutExceptionHandling();

    $this->actingAs(makeUser('admin'))->getJson('/api/mobile-panel/schema');
})->throws(RuntimeException::class);
