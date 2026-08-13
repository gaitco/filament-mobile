<?php

declare(strict_types=1);

/**
 * P7 Task 3: the separator mirror.
 *
 * `TagsInput::make('separated_labels')->separator(',')` changes what gets
 * PERSISTED, not merely what gets rendered — measured in
 * `TagsInput::dehydrateStateUsing()`, which implodes the state into `"a,b,c"`
 * before the web panel saves, and `hydrateTags()`, which explodes it back.
 * This package's write path deliberately never runs Filament's dehydration; it
 * writes `validated()` straight to the model. So without this mirror the same
 * column holds an array from the phone and a delimited string from the panel:
 * two surfaces, two shapes, one column.
 *
 * Every assertion here reads the STORED COLUMN through `getRawOriginal()`, not
 * the response code and not the cast accessor. The defect is a correct-looking
 * 200 over a wrong column, so `assertOk()` passes against the bug and a cast
 * would disguise the shape. `separated_labels` is a `text` column with no cast
 * on the fixture model for exactly that reason.
 */
use Filament\Panel;
use Filament\PanelRegistry;
use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;
use Gait\FilamentMobile\Tests\Fixtures\Models\Company;
use Gait\FilamentMobile\Tests\Fixtures\Resources\BannerResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\CardedTagsBannerResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\CardedTagsCompanyResource;

/** The two fields every banner write must carry to clear validation. */
function bannerWritePayload(array $extra): array
{
    return ['name' => 'Banner', 'body_html' => '<p>Body</p>', ...$extra];
}

it('joins a separated tags field with its separator before persisting, on update', function () {
    $banner = seedBanner();

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", bannerWritePayload([
            'separated_labels' => ['a', 'b'],
        ]))
        ->assertOk();

    // The stored column, not the response. A 200 was never the question.
    expect($banner->fresh()->getRawOriginal('separated_labels'))->toBe('a,b');
});

it('joins a separated tags field with its separator before persisting, on create', function () {
    // Both write seams, because they ARE two seams: a mirror applied to
    // `update()` alone is a bug in `store()`, and the shipped defect shape
    // this project has now corrected three times is a rule written twice.
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', bannerWritePayload([
            'name' => 'Created',
            'separated_labels' => ['a', 'b'],
        ]))
        ->assertCreated();

    expect(Banner::query()->where('name', 'Created')->sole()
        ->getRawOriginal('separated_labels'))->toBe('a,b');
});

it('persists an unseparated tags field as an array, untouched, on update', function () {
    // The control group. A `TagsInput` with no separator goes through the
    // UNMODIFIED write path — the mirror must be keyed off the separator, not
    // off the field being a tags field.
    $banner = seedBanner();

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", bannerWritePayload([
            'labels' => ['a', 'b'],
        ]))
        ->assertOk();

    expect($banner->fresh()->labels)->toBe(['a', 'b'])
        // Raw too: a mirror that fired here would store `"a,b"` under a json
        // column, and the array cast would decode nothing while the accessor
        // assertion above could still be argued around.
        //
        // DECODED, not compared byte-for-byte: MySQL's json type re-serialises
        // what it stores and puts a space after each comma (`["a", "b"]`),
        // so the literal string pinned SQLite's formatting rather than the
        // claim. The claim is "a JSON array landed here, not a delimited
        // string", and decoding tests exactly that on either driver — a
        // stored `"a,b"` decodes to the string `'a,b'` and still fails.
        ->and(json_decode($banner->fresh()->getRawOriginal('labels'), true))
        ->toBe(['a', 'b']);
});

it('persists an unseparated tags field as an array, untouched, on create', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', bannerWritePayload([
            'name' => 'Plain',
            'labels' => ['a', 'b'],
        ]))
        ->assertCreated();

    expect(Banner::query()->where('name', 'Plain')->sole()->labels)->toBe(['a', 'b']);
});

it('publishes a delimited column back to the client as a list', function () {
    // The read half of "the wire value is ALWAYS a List<String>". The column
    // holds the panel's own delimited string; the client never sees or
    // constructs that form, so the explode is the server's job — the Dart side
    // parses `separated_labels` as a `List<String>` in every case.
    $banner = seedBannerWith(['separated_labels' => 'a,b']);

    // The premise, pinned: the column really does hold a string. Without this
    // the test would pass just as happily against a json column.
    expect($banner->fresh()->getRawOriginal('separated_labels'))->toBe('a,b');

    $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}")
        ->assertOk()
        ->assertJsonPath('data.separated_labels', ['a', 'b']);
});

describe('every serialize seam, not just show()', function () {
    // Fix round 1, Finding 1. The read half was wired at show() alone, so a
    // card listing a delimited tags column published `"a,b"` from index() and
    // the write responses, and `["a","b"]` from show() — two shapes for one
    // column, and the spec says List<String> "in every case". The rule now
    // lives inside RecordSerializer, which every seam serialises through.
    beforeEach(function () {
        config()->set('filament-mobile.resources', [CardedTagsBannerResource::class]);
    });

    it('publishes a list from index(), not the raw delimited column', function () {
        seedBannerWith(['name' => 'Carded', 'separated_labels' => 'a,b']);

        $this->actingAs(makeUser('admin'))
            ->getJson('/api/mobile-panel/carded-tags-banners')
            ->assertOk()
            ->assertJsonPath('data.0.separated_labels', ['a', 'b']);
    });

    it('publishes a list from the create response body', function () {
        $this->actingAs(makeUser('admin'))
            ->postJson('/api/mobile-panel/carded-tags-banners', bannerWritePayload([
                'name' => 'Created carded',
                'separated_labels' => ['a', 'b'],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.separated_labels', ['a', 'b']);
    });

    it('publishes a list from the update response body', function () {
        $banner = seedBanner();

        $this->actingAs(makeUser('admin'))
            ->putJson("/api/mobile-panel/carded-tags-banners/{$banner->id}", bannerWritePayload([
                'separated_labels' => ['a', 'b'],
            ]))
            ->assertOk()
            ->assertJsonPath('data.separated_labels', ['a', 'b']);
    });
});

describe('the relation seam', function () {
    // Fix round 2, Finding 1. RelationController passed no resource class, on
    // the reasoning that a related record "has a card but no form". Wrong: the
    // column's shape is declared by the resource that WRITES it — BannerResource
    // — not by the company listing it. Five seams agreed and the sixth did not.
    //
    // Reachable without a hand-declared card, too: RelationCard::fromColumns()
    // derives one from the manager's TextColumns.
    beforeEach(function () {
        // Filament's default `canViewForRecord` resolves its user through
        // `Filament::auth()`, i.e. the default panel's guard, so with no panel
        // registered the relation endpoint answers 403 to everyone. Same
        // reason RelationEndpointTest registers one.
        app(PanelRegistry::class)->register(
            Panel::make()->id('mobile-test')->authGuard('web')->default(),
        );

        $this->acme = Company::create(['name' => 'Acme']);

        Banner::create([
            'company_id' => $this->acme->id,
            'name' => 'Child',
            'status' => 'active',
            'internal_note' => 'x',
            'separated_labels' => 'a,b',
        ]);
    });

    /** The relation payload's first row, for the one column under test. */
    function relatedSeparatedLabels(int $company): mixed
    {
        return test()->actingAs(makeUser("admin"))
            ->getJson("/api/mobile-panel/carded-tags-companies/{$company}/relations/banners")
            ->assertOk()
            ->json('data.0.separated_labels');
    }

    it('publishes a list from a relation card, like every other seam', function () {
        config()->set('filament-mobile.resources', [
            CardedTagsCompanyResource::class,
            BannerResource::class,
        ]);

        expect(relatedSeparatedLabels($this->acme->id))->toBe(['a', 'b']);
    });

    it('degrades to the raw column when no mobile resource owns the child model', function () {
        // BannerResource unregistered: nothing declares the column's shape, so
        // the honest answer is the stored value — the behaviour every relation
        // had before this. Not a 500 and not a guess.
        config()->set('filament-mobile.resources', [CardedTagsCompanyResource::class]);

        expect(relatedSeparatedLabels($this->acme->id))->toBe('a,b');
    });

    it('degrades to the raw column when two resources serve the child model', function () {
        // Ambiguity fails safe. Two resources over Banner can declare different
        // forms, so picking one would publish a column in a shape the other
        // resource's client does not expect. A model with no single owner gets
        // no answer rather than a coin flip.
        config()->set('filament-mobile.resources', [
            CardedTagsCompanyResource::class,
            BannerResource::class,
            CardedTagsBannerResource::class,
        ]);

        expect(relatedSeparatedLabels($this->acme->id))->toBe('a,b');
    });
});

describe('a non-string tag element', function () {
    // P7 final review, Finding 1. A tags field's own rules are `['array',
    // 'list']` — they constrain the CONTAINER and say nothing about its
    // ELEMENTS. Before this fix the per-element `{$name}.*` rule was minted
    // only when the panel had declared `->nestedRecursiveRules()`, so a
    // plain separator tags field admitted an element of any type, and
    // `TagSeparators::dehydrate()` then called `implode()` on it:
    // `ErrorException "Array to string conversion"`, a first-party 500 on a
    // well-formed payload from an ordinary authenticated writer.
    //
    // The non-crashing half is the same root cause with no separator to
    // crash on: the element is simply STORED, so the column holds a
    // list-of-maps where the contract promises a `List<String>` in every
    // case, and the client's `whereType<String>()` then deletes it on the
    // user's first edit.
    //
    // Both halves close at the shared root: `{$name}.*` is now seeded with
    // `'string'` for every tags field.

    it('answers 422 rather than 500 when a separated field gets a nested array, on update', function () {
        $banner = seedBanner();

        $this->actingAs(makeUser('admin'))
            ->putJson("/api/mobile-panel/banners/{$banner->id}", bannerWritePayload([
                'separated_labels' => [['x'], 'y'],
            ]))
            // Keyed on the offending ELEMENT, not the field: `separated_labels`
            // itself is a perfectly good list, and a test asserting only
            // `assertStatus(422)` would pass against a fix that refused the
            // whole field for the wrong reason.
            ->assertJsonValidationErrors(['separated_labels.0']);

        // The column is untouched. The 500 happened AFTER validation, so a
        // status assertion alone never proved the write did not land.
        expect($banner->fresh()->getRawOriginal('separated_labels'))->toBeNull();
    });

    it('answers 422 rather than 500 when a separated field gets a nested array, on create', function () {
        // Both write seams, because `dehydrate()` is called from both.
        $this->actingAs(makeUser('admin'))
            ->postJson('/api/mobile-panel/banners', bannerWritePayload([
                'name' => 'Crafted',
                'separated_labels' => [['x']],
            ]))
            ->assertJsonValidationErrors(['separated_labels.0']);

        expect(Banner::query()->where('name', 'Crafted')->exists())->toBeFalse();
    });

    it('refuses a non-string element on an unseparated field rather than storing it', function () {
        // The quiet half: 200, and a `[{"x":"y"}]` in a column the contract
        // says is a `List<String>`.
        $banner = seedBanner();

        $this->actingAs(makeUser('admin'))
            ->putJson("/api/mobile-panel/banners/{$banner->id}", bannerWritePayload([
                'labels' => [['x' => 'y']],
            ]))
            ->assertJsonValidationErrors(['labels.0']);

        expect($banner->fresh()->getRawOriginal('labels'))->toBeNull();
    });

    it('still enforces the panel-declared per-tag rule alongside the string seed', function () {
        // `labels` declares `->nestedRecursiveRules(['max:20'])`. The seed
        // must not clobber it: a 21-character STRING still has to 422, and
        // the reason must be `max`, not `string`.
        $banner = seedBanner();

        $this->actingAs(makeUser('admin'))
            ->putJson("/api/mobile-panel/banners/{$banner->id}", bannerWritePayload([
                'labels' => [str_repeat('x', 21)],
            ]))
            ->assertJsonValidationErrors(['labels.0']);
    });

    it('still accepts an ordinary list of strings on both fields', function () {
        // The control group. A seed that refused a legitimate write would be
        // a worse bug than the one it closes.
        $banner = seedBanner();

        $this->actingAs(makeUser('admin'))
            ->putJson("/api/mobile-panel/banners/{$banner->id}", bannerWritePayload([
                'labels' => ['urgent', 'billing'],
                'separated_labels' => ['a', 'b'],
            ]))
            ->assertOk();

        expect($banner->fresh()->labels)->toBe(['urgent', 'billing'])
            ->and($banner->fresh()->getRawOriginal('separated_labels'))->toBe('a,b');
    });
});

it('reads a whitespace-only column back as an empty list, as Filament does', function () {
    // Fix round 1, Finding 2. `hydrateTags()` collapses with `blank()`, which
    // is true for `'   '`; a bare `=== ''` published `["   "]` — one stray tag
    // of whitespace where the web panel shows an empty field.
    $banner = seedBannerWith(['separated_labels' => '   ']);

    $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}")
        ->assertOk()
        ->assertJsonPath('data.separated_labels', []);
});

it('round-trips a separated tags field: list in, delimited column, list out', function () {
    // The two halves composed. Written by the client as a list, stored as the
    // panel stores it, handed back as a list — which is what makes the
    // client's "always a list" contract true rather than merely claimed.
    $banner = seedBanner();

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", bannerWritePayload([
            'separated_labels' => ['urgent', 'billing', 'vip'],
        ]))
        ->assertOk();

    expect($banner->fresh()->getRawOriginal('separated_labels'))->toBe('urgent,billing,vip');

    $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}")
        ->assertOk()
        ->assertJsonPath('data.separated_labels', ['urgent', 'billing', 'vip']);
});
