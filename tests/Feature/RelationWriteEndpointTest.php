<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;
use Gait\FilamentMobile\Tests\Fixtures\Models\Company;
use Gait\FilamentMobile\Tests\Fixtures\Models\Tag;
use Gait\FilamentMobile\Tests\Fixtures\Policies\NoWritesBannerPolicy;
use Gait\FilamentMobile\Tests\Fixtures\Resources\BannerResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\CardedTagsBannerResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\CompanyResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\TagModelResource;
use Illuminate\Support\Facades\Gate;

/**
 * P9: the relation write endpoints — POST/PUT/DELETE
 * `/{resource}/{record}/relations/{relation}[/{child}]`.
 *
 * The fixtures line up with the design spec's matrix: `companies.banners` is
 * the writable HasMany (Banner has exactly one mobile resource here),
 * `banners.tags` is the zero-resource relation (read-only, writes 404)
 * unless TagModelResource is registered, and registering
 * CardedTagsBannerResource beside BannerResource makes Banner the
 * two-resources child (also read-only, writes 404).
 *
 * Every test asserts the row, not just the status: a 201 that wrote nothing
 * is the failure shape this package has shipped before.
 */
beforeEach(function () {
    // The relation gate (canViewForRecord) resolves its user through the
    // default panel's guard, so a panel must exist — same reasoning
    // RelationEndpointTest's beforeEach documents. Inlined rather than
    // shared: that file's helper is its own, and a cross-file function
    // definition makes this file unrunnable on its own.
    app(\Filament\PanelRegistry::class)->register(
        \Filament\Panel::make()->id('mobile-test')->default(),
    );

    // The minimal writable set: Company has the relation, Banner is the
    // singly-resourced child. Tag has NO mobile resource here on purpose.
    config()->set('filament-mobile.resources', [
        BannerResource::class,
        CompanyResource::class,
    ]);
});

it('creates a child row through the relationship, answering 201 with the row', function () {
    $acme = Company::create(['name' => 'Acme']);

    $response = $this->actingAs(makeUser('admin'))
        ->postJson("/api/mobile-panel/companies/{$acme->id}/relations/banners", [
            'name' => 'New banner',
            'body_html' => '<p>Body</p>',
        ])
        ->assertCreated();

    // The relation envelope's row shape: the card's fields plus the related
    // model's route key — the same shape the read endpoint paginates.
    expect($response->json('data.name'))->toBe('New banner')
        ->and($response->json('data.id'))->not->toBeNull();

    $banner = Banner::query()->where('name', 'New banner')->firstOrFail();

    // Through the relationship means the foreign key is the parent's by
    // construction — never set from the payload.
    expect($banner->company_id)->toBe($acme->id)
        // The child form's own defaults apply, exactly as store() applies
        // them: `kind` is Hidden with ->default('promo'), invisible to the
        // client, so only the server can supply it.
        ->and($banner->kind)->toBe('promo');
});

it('settles relation-write fields on the child form through the relation pass', function () {
    // `tag_ids` is BannerResource's Select::multiple()->relationship() — no
    // column, saved only by saveRelationships(). The same machinery the
    // resource endpoints use must run here too, or the 201 lies.
    $acme = Company::create(['name' => 'Acme']);
    $tag = Tag::create(['name' => 'Red']);

    $response = $this->actingAs(makeUser('admin'))
        ->postJson("/api/mobile-panel/companies/{$acme->id}/relations/banners", [
            'name' => 'Tagged',
            'body_html' => '<p>Body</p>',
            'tag_ids' => [$tag->id],
        ])
        ->assertCreated();

    $banner = Banner::query()->findOrFail($response->json('data.id'));

    expect($banner->tags()->pluck('tags.id')->all())->toBe([$tag->id]);
});

it('422s a create the child form rejects, keyed by field', function () {
    // `name` is required on BannerResource's form — the client renders the
    // error against the same form it already has, so the key matters.
    $acme = Company::create(['name' => 'Acme']);

    $response = $this->actingAs(makeUser('admin'))
        ->postJson("/api/mobile-panel/companies/{$acme->id}/relations/banners", [
            'body_html' => '<p>Body</p>',
        ])
        ->assertStatus(422);

    expect(array_keys($response->json('errors')))->toContain('name');
    expect(Banner::query()->count())->toBe(0);
});

it('updates a child row resolved through the relationship, answering 200 with the row', function () {
    $acme = Company::create(['name' => 'Acme']);
    $banner = Banner::create(['company_id' => $acme->id, 'name' => 'Before', 'status' => 'draft', 'internal_note' => 'x']);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/companies/{$acme->id}/relations/banners/{$banner->id}", [
            'name' => 'After',
            'body_html' => '<p>Body</p>',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'After')
        ->assertJsonPath('data.id', $banner->id);

    expect($banner->fresh()->name)->toBe('After');
});

it('422s an update the child form rejects, keyed by field', function () {
    $acme = Company::create(['name' => 'Acme']);
    $banner = Banner::create(['company_id' => $acme->id, 'name' => 'Before', 'status' => 'draft', 'internal_note' => 'x']);

    $response = $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/companies/{$acme->id}/relations/banners/{$banner->id}", [
            // maxLength(80) on the child form.
            'name' => str_repeat('x', 81),
            'body_html' => '<p>Body</p>',
        ])
        ->assertStatus(422);

    expect(array_keys($response->json('errors')))->toContain('name');
    expect($banner->fresh()->name)->toBe('Before');
});

it('deletes a child row, answering 200 with the deleted row', function () {
    // Deliberately NOT destroy()'s 204-with-no-body: the relation client
    // holds a list it must reconcile, and the design spec fixes the 200 +
    // row shape for it.
    $acme = Company::create(['name' => 'Acme']);
    $banner = Banner::create(['company_id' => $acme->id, 'name' => 'Doomed', 'status' => 'active', 'internal_note' => 'x']);

    $this->actingAs(makeUser('admin'))
        ->deleteJson("/api/mobile-panel/companies/{$acme->id}/relations/banners/{$banner->id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Doomed')
        ->assertJsonPath('data.id', $banner->id);

    expect(Banner::query()->find($banner->id))->toBeNull();
});

it('404s an update naming a child of a DIFFERENT parent — never a cross-parent write', function () {
    $acme = Company::create(['name' => 'Acme']);
    $other = Company::create(['name' => 'Other']);
    $theirs = Banner::create(['company_id' => $other->id, 'name' => 'Theirs', 'status' => 'active', 'internal_note' => 'x']);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/companies/{$acme->id}/relations/banners/{$theirs->id}", [
            'name' => 'Stolen',
            'body_html' => '<p>Body</p>',
        ])
        ->assertNotFound();

    expect($theirs->fresh()->name)->toBe('Theirs');
});

it('404s a delete naming a child of a DIFFERENT parent, and deletes nothing', function () {
    $acme = Company::create(['name' => 'Acme']);
    $other = Company::create(['name' => 'Other']);
    $theirs = Banner::create(['company_id' => $other->id, 'name' => 'Theirs', 'status' => 'active', 'internal_note' => 'x']);

    $this->actingAs(makeUser('admin'))
        ->deleteJson("/api/mobile-panel/companies/{$acme->id}/relations/banners/{$theirs->id}")
        ->assertNotFound();

    expect(Banner::query()->find($theirs->id))->not->toBeNull();
});

it('403s a create the child policy denies, and writes nothing', function () {
    Gate::policy(Banner::class, NoWritesBannerPolicy::class);

    $acme = Company::create(['name' => 'Acme']);

    $this->actingAs(makeUser('admin'))
        ->postJson("/api/mobile-panel/companies/{$acme->id}/relations/banners", [
            'name' => 'Denied',
            'body_html' => '<p>Body</p>',
        ])
        ->assertForbidden();

    expect(Banner::query()->where('name', 'Denied')->exists())->toBeFalse();
});

it('403s an update the child policy denies, and changes nothing', function () {
    Gate::policy(Banner::class, NoWritesBannerPolicy::class);

    $acme = Company::create(['name' => 'Acme']);
    $banner = Banner::create(['company_id' => $acme->id, 'name' => 'Before', 'status' => 'draft', 'internal_note' => 'x']);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/companies/{$acme->id}/relations/banners/{$banner->id}", [
            'name' => 'Denied',
            'body_html' => '<p>Body</p>',
        ])
        ->assertForbidden();

    expect($banner->fresh()->name)->toBe('Before');
});

it('403s a delete the child policy denies, and deletes nothing', function () {
    Gate::policy(Banner::class, NoWritesBannerPolicy::class);

    $acme = Company::create(['name' => 'Acme']);
    $banner = Banner::create(['company_id' => $acme->id, 'name' => 'Before', 'status' => 'draft', 'internal_note' => 'x']);

    $this->actingAs(makeUser('admin'))
        ->deleteJson("/api/mobile-panel/companies/{$acme->id}/relations/banners/{$banner->id}")
        ->assertForbidden();

    expect(Banner::query()->find($banner->id))->not->toBeNull();
});

it('403s a write on a parent record the user may not view, before touching the child', function () {
    // The shared gate order is the read endpoint's: the record's own `view`
    // runs before any per-operation check, so a write on a hidden parent is
    // the same 403 the read gives.
    Gate::before(fn ($user, string $ability, array $arguments = []) => $ability === 'view'
        && ($arguments[0] ?? null) instanceof Company
            ? false
            : null);

    $acme = Company::create(['name' => 'Acme']);

    $this->actingAs(makeUser('admin'))
        ->postJson("/api/mobile-panel/companies/{$acme->id}/relations/banners", [
            'name' => 'Denied',
            'body_html' => '<p>Body</p>',
        ])
        ->assertForbidden();
});

it('404s all three write verbs when the child has NO mobile resource', function () {
    // `tags` here: Tag is served by nothing in this file's resource set, so
    // /schema publishes no `resource` key and the write endpoints do not
    // exist. Absence means unavailable — 404, never a denial-shaped 403.
    $banner = seedBanner('Mine');
    $banner->tags()->attach(Tag::create(['name' => 'Red']));

    expect(collect(schemaFor('banners')['relations'])->firstWhere('key', 'tags'))
        ->not->toHaveKey('resource');

    $this->actingAs(makeUser('admin'))
        ->postJson("/api/mobile-panel/banners/{$banner->id}/relations/tags", ['name' => 'New'])
        ->assertNotFound();

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}/relations/tags/Red", ['name' => 'New'])
        ->assertNotFound();

    $this->actingAs(makeUser('admin'))
        ->deleteJson("/api/mobile-panel/banners/{$banner->id}/relations/tags/Red")
        ->assertNotFound();
});

it('404s all three write verbs when the child has SEVERAL mobile resources', function () {
    // Two resources serve Banner, so no single form owns the write and the
    // package refuses to guess — the relation reads fine and writes 404.
    config()->set('filament-mobile.resources', [
        BannerResource::class,
        CardedTagsBannerResource::class,
        CompanyResource::class,
    ]);

    $acme = Company::create(['name' => 'Acme']);
    $banner = Banner::create(['company_id' => $acme->id, 'name' => 'Mine', 'status' => 'active', 'internal_note' => 'x']);

    expect(collect(schemaFor('companies')['relations'])->firstWhere('key', 'banners'))
        ->not->toHaveKey('resource');

    // The read endpoint is unaffected — this is a write refusal, not a
    // relation refusal.
    $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/companies/{$acme->id}/relations/banners")
        ->assertOk();

    $this->actingAs(makeUser('admin'))
        ->postJson("/api/mobile-panel/companies/{$acme->id}/relations/banners", [
            'name' => 'New',
            'body_html' => '<p>Body</p>',
        ])
        ->assertNotFound();

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/companies/{$acme->id}/relations/banners/{$banner->id}", [
            'name' => 'New',
            'body_html' => '<p>Body</p>',
        ])
        ->assertNotFound();

    $this->actingAs(makeUser('admin'))
        ->deleteJson("/api/mobile-panel/companies/{$acme->id}/relations/banners/{$banner->id}")
        ->assertNotFound();
});

it('writes a BelongsToMany child by its OWN route key, resolved through the pivot', function () {
    // With TagModelResource registered, `tags` gains its writes. Tag's route
    // key is `name`, not `id` — so `{child}` is the name, and a name the
    // pivot does not link to THIS parent is a 404, never a cross-parent edit.
    config()->set('filament-mobile.resources', [
        BannerResource::class,
        CompanyResource::class,
        TagModelResource::class,
    ]);

    $mine = seedBanner('Mine');
    $theirs = seedBanner('Theirs');
    $mine->tags()->attach(Tag::create(['name' => 'Red']));
    $theirs->tags()->attach(Tag::create(['name' => 'Blue']));

    expect(collect(schemaFor('banners')['relations'])->firstWhere('key', 'tags'))
        ->toHaveKey('resource', 'tags');

    // Create attaches the pivot by construction.
    $this->actingAs(makeUser('admin'))
        ->postJson("/api/mobile-panel/banners/{$mine->id}/relations/tags", ['name' => 'Green'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Green');

    expect($mine->tags()->pluck('tags.name')->all())->toEqualCanonicalizing(['Red', 'Green']);

    // Update addressed by the child's own route key — `Red`, not an id.
    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$mine->id}/relations/tags/Red", ['name' => 'Crimson'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Crimson');

    // The other parent's tag is not reachable through this one, by either
    // write verb.
    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$mine->id}/relations/tags/Blue", ['name' => 'Stolen'])
        ->assertNotFound();

    $this->actingAs(makeUser('admin'))
        ->deleteJson("/api/mobile-panel/banners/{$mine->id}/relations/tags/Blue")
        ->assertNotFound();

    expect(Tag::query()->where('name', 'Blue')->exists())->toBeTrue();
});

it('404s a write to a relation the resource does not publish', function () {
    $acme = Company::create(['name' => 'Acme']);

    $this->actingAs(makeUser('admin'))
        ->postJson("/api/mobile-panel/companies/{$acme->id}/relations/nonexistent", [
            'name' => 'New',
        ])
        ->assertNotFound();
});

it('writes a relationship repeater on the child form through the relation pass', function () {
    // B2, end to end at the relation endpoint: `tag_rows` is BannerResource's
    // Repeater::relationship('tags'). Before P9 the submitted rows were
    // silently dropped behind a 200; now Filament's own
    // Repeater::saveToRelationship() writes them, through the same relation
    // pass a relationship select already used.
    $acme = Company::create(['name' => 'Acme']);
    $banner = Banner::create(['company_id' => $acme->id, 'name' => 'Rows', 'status' => 'active', 'internal_note' => 'x']);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/companies/{$acme->id}/relations/banners/{$banner->id}", [
            'name' => 'Rows',
            'body_html' => '<p>Body</p>',
            'tag_rows' => [['name' => 'Via relation endpoint']],
        ])
        ->assertOk();

    expect($banner->fresh()->tags()->pluck('tags.name')->all())->toBe(['Via relation endpoint']);
});
