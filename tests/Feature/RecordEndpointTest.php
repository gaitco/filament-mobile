<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;
use Gait\FilamentMobile\Tests\Fixtures\Models\Post;
use Gait\FilamentMobile\Tests\Fixtures\Policies\ImplicitNullPolicy;
use Gait\FilamentMobile\Tests\Fixtures\Policies\OwnedPostPolicy;
use Illuminate\Support\Facades\Gate;

it('serves one record with the attributes the infolist references', function () {
    $banner = seedBanner(name: 'لافتة');

    $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $banner->id)
        ->assertJsonPath('data.name', 'لافتة')
        ->assertJsonPath('data.company.name', 'Acme');
});

it('widens the payload with a field only the infolist references', function () {
    // The Post card declares title and body; only the infolist entries name
    // `published`. The list endpoint must not carry it, the record must.
    $post = seedPost();
    $user = makeUser('admin');

    $list = $this->actingAs($user)->getJson('/api/mobile-panel/posts')->json('data.0');
    $record = $this->actingAs($user)->getJson("/api/mobile-panel/posts/{$post->id}")->json('data');

    expect($list)->not->toHaveKey('published')
        ->and($record)->toHaveKey('published')
        ->and($record['published'])->toBeTrue();
});

it('keeps a reactive infolist entry a null host would have fataled on', function () {
    // PostResource's `published` entry is visible only when the record has a
    // title. Built with `Schema::make()` and no host, that closure fatals in
    // getComponents() — and the catch around it degrades the detail screen to
    // the card's fields, so the loss is silent. `published` is infolist-only
    // (the card declares title and body), which is what makes it the witness.
    $post = seedPost();

    $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/posts/{$post->id}")
        ->assertOk()
        ->assertJsonPath('data.published', true);
});

it('still withholds a column no screen references', function () {
    $banner = seedBanner();

    $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}")
        ->assertJsonMissingPath('data.internal_note');
});

it('404s for a record outside the resource query scope', function () {
    // Banner has no SoftDeletes trait — the scope lives on the *resource*, so
    // the row has to be marked deleted by hand for this to test anything. A
    // `$banner->delete()` here would hard-delete and pass for the wrong reason.
    $banner = seedBanner();
    $banner->update(['deleted_at' => now()]);

    $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}")
        ->assertNotFound();
});

it('404s for an id that does not exist', function () {
    $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/banners/9999')
        ->assertNotFound();
});

it('403s when the policy denies view', function () {
    $post = seedPost();

    $this->actingAs(makeUser('restricted'))
        ->getJson("/api/mobile-panel/posts/{$post->id}")
        ->assertForbidden();
});

it('403s when viewAny denies, even where the record policy allows view', function () {
    // The web panel gates ViewRecord on canAccess() => canViewAny(). Mobile
    // gated only on the record's `view`, so this exact user got 403 on the web
    // and 200 on the phone. PostPolicy::view() delegating to viewAny() is the
    // only reason 118 tests never saw it.
    Gate::policy(Post::class, OwnedPostPolicy::class);
    $post = seedPost();

    $this->actingAs(makeUser('owner'))
        ->getJson("/api/mobile-panel/posts/{$post->id}")
        ->assertForbidden();
});

it('still serves the record under that policy to a user viewAny allows', function () {
    // The control: the 403 above is the viewAny gate, not a broken fixture.
    Gate::policy(Post::class, OwnedPostPolicy::class);
    $post = seedPost();

    $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/posts/{$post->id}")
        ->assertOk();
});

it('404s for a resource that declares no mobile()', function () {
    $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/secrets/1')
        ->assertNotFound();
});

it('requires authentication', function () {
    $banner = seedBanner();

    $this->getJson("/api/mobile-panel/banners/{$banner->id}")->assertUnauthorized();
});

it('carries a permissions block computed against the real record', function () {
    // The fixture PostPolicy: view follows viewAny, update denies everyone,
    // delete denies a published post. So this record is viewable and neither
    // updatable nor deletable — a per-record answer no class-level check could
    // have produced.
    $post = seedPost(published: true);

    $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/posts/{$post->id}")
        ->assertOk()
        ->assertJsonPath('permissions.view', true)
        ->assertJsonPath('permissions.update', false)
        ->assertJsonPath('permissions.delete', false);
});

it('answers per record where the resource block only reports capability', function () {
    // The whole point of the split: /schema says "this resource supports
    // delete and you are not categorically barred", the record says whether
    // *this* row may actually be deleted. They must be free to disagree.
    $user = makeUser('admin');
    $published = seedPost(published: true);
    $draft = seedPost(published: false);

    $capability = collect(
        $this->actingAs($user)->getJson('/api/mobile-panel/schema')->json('resources'),
    )->firstWhere('key', 'posts')['permissions'];

    $get = fn (int $id): array => $this->actingAs($user)
        ->getJson("/api/mobile-panel/posts/{$id}")
        ->json('permissions');

    expect($capability['delete'])->toBeTrue()
        ->and($get($published->id)['delete'])->toBeFalse()
        ->and($get($draft->id)['delete'])->toBeTrue();
});

it('permits every ability on a model with no policy at all', function () {
    // Banner has no policy: Filament's semantics are that an app without
    // policies sees everything, and mobile must not be stricter than web.
    $banner = seedBanner();

    $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}")
        ->assertJsonPath('permissions.view', true)
        ->assertJsonPath('permissions.update', true)
        ->assertJsonPath('permissions.delete', true);
});

it('denies a policy method that falls off the end, as Gate::check() does', function () {
    // An untyped policy method with an implicit-null return is legal PHP and
    // common. Laravel denies on that null; reading it as "nobody defined this
    // ability" would make mobile looser than the web panel — and it errs open,
    // so it is the dangerous direction.
    Gate::policy(Banner::class, ImplicitNullPolicy::class);
    $banner = seedBanner();

    $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}")
        ->assertOk()
        ->assertJsonPath('permissions.view', true)
        ->assertJsonPath('permissions.update', false)
        ->assertJsonPath('permissions.delete', false);
});

it('403s on a view that falls off the end rather than erring open', function () {
    Gate::policy(Banner::class, ImplicitNullPolicy::class);
    $banner = seedBanner();

    $this->actingAs(makeUser('blocked'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}")
        ->assertForbidden();
});

it('never emits an _actions key — actions are P3', function () {
    $banner = seedBanner();

    $json = $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}")
        ->content();

    expect($json)->not->toContain('_actions');
});

it('serialises a form field the infolist does not show', function () {
    // The edit form needs values for every field it renders. Before this,
    // show() serialised the card's fields plus the infolist's paths only, so
    // a form-only field arrived absent — and a blank edit then wrote the blank
    // over real data. `secret_note` is form-only: absent from BannerResource's
    // infolist() AND its card, so this can only pass via formPaths().
    $banner = seedBannerWith(['name' => 'Original', 'secret_note' => 'kept']);

    $data = $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}")
        ->assertOk()
        ->json('data');

    expect($data)->toHaveKey('secret_note')
        ->and($data['secret_note'])->toBe('kept');
});

it('still serialises the card and infolist paths', function () {
    // Guards against the new path set REPLACING the old one rather than
    // extending it. Without this, Task 1 could pass its own test while
    // emptying the detail screen.
    //
    // `name`/`id` alone do NOT discriminate this: RecordSerializer::serialize()
    // merges the card's fields and the record key unconditionally, regardless
    // of what withInfolistPaths() is given, so both survive even if
    // infolistPaths() were dropped from the merge entirely. See the next test
    // for the field that actually can only arrive via infolistPaths().
    $banner = seedBanner(name: 'Original');

    $data = $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}")
        ->assertOk()
        ->json('data');

    expect($data)->toHaveKey('name')->toHaveKey('id');
});

it('serialises an infolist-only field the card does not show', function () {
    // The real discriminator for "formPaths() replaced infolistPaths()
    // instead of extending it": `infolist_only_note` names neither the card
    // nor the form, so it can only reach the payload through infolistPaths().
    $banner = seedBannerWith(['name' => 'Original', 'infolist_only_note' => 'shown']);

    $data = $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}")
        ->assertOk()
        ->json('data');

    expect($data)->toHaveKey('infolist_only_note')
        ->and($data['infolist_only_note'])->toBe('shown');
});

it('serialises a translatable form field under its literal key', function () {
    // `caption.ar` is a form field. Laravel validates it as a PATH, and
    // data_set() would nest it — replacing whatever `caption` already holds.
    $banner = seedBannerWith(['caption' => ['ar' => 'عربي', 'en' => 'English']]);

    $data = $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}")
        ->assertOk()
        ->json('data');

    // array_key_exists, not toHaveKey: Pest reads a dot as a PATH separator,
    // so toHaveKey('caption.en') would pass against the nested shape this test
    // exists to rule out. The literal key is the whole assertion.
    expect(array_key_exists('caption.en', $data))->toBeTrue()
        ->and($data['caption.en'])->toBe('English');

    // NOT asserted here: that a sibling `caption` scalar survives. This
    // fixture's `caption` is a JSON-cast column, so it legitimately reads as
    // an array and there is no scalar to preserve. The real collision needs a
    // Spatie translatable accessor — an infolist entry named `caption`
    // returning the current locale's string beside a form field named
    // `caption.en`. The pilot has that shape; the fixtures do not, and
    // modelling Spatie here would mean taking a dependency to test one key.
    // The literal-key write above is what makes both representations
    // possible; proving they coexist belongs to the pilot.
});

it('leaves a dotted CARD path nested, so cards keep working', function () {
    // Only FORM paths change. `company.name` is a card field and every card in
    // the panel reads it nested — flattening it would break the list screen.
    $banner = seedBannerWith([]);

    $data = $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}")
        ->assertOk()
        ->json('data');

    expect($data['company'] ?? null)->toBeArray();
});
