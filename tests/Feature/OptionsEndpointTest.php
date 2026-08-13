<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Company;

/**
 * Posts to the options endpoint with sensible defaults, so a test states only
 * the part it is about.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function searchOptionsFor(array $overrides = []): array
{
    return test()->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/options', [
            'field' => 'searchable_company_id',
            'record_id' => null,
            'state' => [],
            'q' => '',
            ...$overrides,
        ])->json();
}

it('returns options for a searchable select', function () {
    Company::create(['name' => 'Acme Ltd']);

    $body = searchOptionsFor(['q' => 'Acme']);

    expect($body['options'])->toHaveCount(1)
        ->and($body['options'][0]['label'])->toBe('Acme Ltd');
});

it('serves a multi-select that never declared searchable', function () {
    // Filament's isSearchable() defaults to isMultiple(), so `tag_ids` reaches
    // the client as an optionsUrl node without ever calling ->searchable().
    // An endpoint that served only explicitly-searchable fields would 422 on a
    // node the schema itself told the client to fetch.
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/options', [
            'field' => 'tag_ids', 'record_id' => null, 'state' => [], 'q' => '',
        ])->assertOk();
});

it('resolves options against SETTLED state, not the submitted payload', function () {
    // The security property, on the shape the write pilot measured: a select
    // whose option QUERY reads a sibling. `kind` is a Hidden — never written,
    // so never trusted — and the settle resets it to its default. A crafted
    // `kind` must not widen the list, or a client chooses which tenant's rows
    // it sees by sending a field the server will never store.
    Company::create(['name' => 'Acme Ltd']);

    $crafted = searchOptionsFor([
        'field' => 'scoped_company_id',
        'state' => ['kind' => 'unlock'],
        'q' => 'Acme',
    ]);

    expect($crafted['options'])->toBeEmpty();
});

it('a settled value DOES open the same query, so the test above is not vacuous', function () {
    // The control. If the scoped select returned nothing under every state,
    // the crafted-sibling assertion would pass against an endpoint that had no
    // security property at all. `kind` reaches 'unlock' here through the
    // record's own stored value, which the settle trusts.
    Company::create(['name' => 'Acme Ltd']);
    $banner = seedBannerWith(['kind' => 'unlock']);

    $opened = searchOptionsFor([
        'field' => 'scoped_company_id',
        'record_id' => $banner->id,
        'q' => 'Acme',
    ]);

    // Non-empty rather than an exact count: the fixture's own seeding creates
    // a company too, and the control's job is to prove the query CAN return
    // rows, not to count them.
    expect($opened['options'])->not->toBeEmpty();
});

it('a WRITABLE sibling does reach the schema, exactly as the form would', function () {
    // The counter-test. Without it, an endpoint that ignored state entirely
    // would pass the test above while being useless — a dependent picker needs
    // the state it depends on. `name` is writable, so it survives the settle
    // and reaches the components the search runs against.
    Company::create(['name' => 'Acme Ltd']);

    $body = searchOptionsFor(['state' => ['name' => 'anything'], 'q' => 'Acme']);

    expect($body['options'])->toHaveCount(1);
});

it('422s for a field that is not a select', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/options', [
            'field' => 'name', 'record_id' => null, 'state' => [], 'q' => '',
        ])->assertStatus(422);
});

it('422s for a field absent from the schema, never 404', function () {
    // A 404 would leak whether the resource has such a column. 422 says only
    // "that is not a field you can search here".
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/options', [
            'field' => 'no_such_field', 'record_id' => null, 'state' => [], 'q' => '',
        ])->assertStatus(422);
});

it('404s for a resource nobody serves, before asking about the field', function () {
    // Resource resolution precedes the Gate on every other endpoint, and this
    // one is no exception: a resource with no mobile() is a 404 for everyone.
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/no-such-resource/options', [
            'field' => 'x', 'record_id' => null, 'state' => [], 'q' => '',
        ])->assertNotFound();
});

it('authorizes create when record_id is null and update when it is not', function () {
    // `posts` is the fixture with a policy; `banners` has none, so everyone
    // passes there and a 403 test against it could never fail. The update leg
    // asks the RECORD-level question — under an ownership policy the class and
    // record answers disagree for most rows. See Authorizer::allowsRecord().
    //
    // Authorization runs before the field is resolved, which is why these can
    // name a field that does not exist on posts: a forbidden user must not
    // learn which fields a resource has.
    $post = seedPost();

    $this->actingAs(makeUser('restricted'))
        ->postJson('/api/mobile-panel/posts/options', [
            'field' => 'anything', 'record_id' => null, 'state' => [], 'q' => '',
        ])->assertForbidden();

    $this->actingAs(makeUser('owned-denier'))
        ->postJson('/api/mobile-panel/posts/options', [
            'field' => 'anything', 'record_id' => $post->id, 'state' => [], 'q' => '',
        ])->assertForbidden();
});

it('reports hasMore when the result was capped', function () {
    for ($i = 0; $i < 60; $i++) {
        Company::create(['name' => "Company {$i}"]);
    }

    expect(searchOptionsFor(['q' => 'Company'])['hasMore'])->toBeTrue();
});

it('reports hasMore false when the whole result fits', function () {
    // The counter-test to the one above: an endpoint that hard-coded `true`
    // would pass it while telling every client its list is incomplete.
    Company::create(['name' => 'Acme Ltd']);

    expect(searchOptionsFor(['q' => 'Acme'])['hasMore'])->toBeFalse();
});

it('rejects a non-scalar record_id with 422 rather than fatalling', function () {
    // `nullable` alone still admits `record_id: []`, which reaches the query
    // builder as an array binding and 500s — for input the client controls.
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/options', [
            'field' => 'searchable_company_id', 'record_id' => [],
            'state' => [], 'q' => '',
        ])->assertStatus(422);
});

it('finds a searchable select inside a repeater row template', function () {
    // L18 server half: the client renders a row's select off the item
    // template and asks for it by its bare child name. A descent that
    // stopped at the repeater's border 422'd a node the schema itself
    // published with an `optionsUrl`.
    config()->set('filament-mobile.resources', [
        \Gait\FilamentMobile\Tests\Fixtures\Resources\RowSelectBannerResource::class,
    ]);
    Company::create(['name' => 'Acme Row']);

    $body = $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/options', [
            'field' => 'row_company_id', 'record_id' => null, 'state' => [], 'q' => 'Acme',
        ])->assertOk()->json();

    expect($body['options'])->toHaveCount(1)
        ->and($body['options'][0]['label'])->toBe('Acme Row');
});

it('publishes an optionsUrl on the row select itself', function () {
    // The other half of the same shape: the walker's config() computes the
    // remote-search hint for row children exactly as for top-level fields.
    config()->set('filament-mobile.resources', [
        \Gait\FilamentMobile\Tests\Fixtures\Resources\RowSelectBannerResource::class,
    ]);

    $form = collect(schemaDocument()['resources'])->firstWhere('key', 'banners')['form'];
    $repeater = collect($form)->firstWhere('name', 'line_items');
    $select = collect($repeater['children'])->firstWhere('name', 'row_company_id');

    expect($select['config']['optionsUrl'])->toBeString()
        ->and($select['config'])->not->toHaveKey('options');
});
