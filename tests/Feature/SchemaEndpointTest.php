<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Resources\BannerResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\BrokenGroupResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\PostResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\TagResource;

// Testbench tears down the test database via `migrate:rollback` after every
// test. The production test below flips the app environment and never flips
// it back, so without this reset that teardown's rollback command hits
// ConfirmableTrait's production gate and blows up on a non-interactive
// output mock. This is a test-harness interaction, not part of the endpoint
// contract, so it is reset here rather than folded into the test body.
afterEach(function () {
    app()->detectEnvironment(fn () => 'testing');
});

it('serves the panel document', function () {
    $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/schema')
        ->assertOk()
        ->assertJsonPath('version', 1)
        ->assertJsonStructure(['version', 'panel' => ['id', 'title'], 'resources']);
});

it('requires authentication', function () {
    $this->getJson('/api/mobile-panel/schema')->assertUnauthorized();
});

it('filters resources by policy for the authenticated user', function () {
    $keys = collect(
        $this->actingAs(makeUser('restricted'))
            ->getJson('/api/mobile-panel/schema')
            ->json('resources'),
    )->pluck('key');

    expect($keys)->not->toContain('posts');
});

it('includes _warnings outside production', function () {
    app()->detectEnvironment(fn () => 'local');

    $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/schema')
        ->assertOk()
        ->assertJsonStructure(['_warnings']);
});

it('omits _warnings in production so a phone never receives diagnostics', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/schema')
        ->assertOk()
        ->assertJsonMissingPath('_warnings');
});

it('honours a configured route prefix', function () {
    config()->set('filament-mobile.prefix', 'api/v2/panel');
    $this->app->register(new Gait\FilamentMobile\FilamentMobileServiceProvider($this->app), true);

    $this->actingAs(makeUser('admin'))
        ->getJson('/api/v2/panel/schema')
        ->assertOk();
});

it('does not claim every URI ending in /schema — only the configured prefix', function () {
    // Unauthenticated on purpose: a wildcard match would hit our `auth`
    // middleware first and answer 401, wrongly claiming a route we don't
    // own. The correct answer for a path outside our prefix is a plain 404,
    // with no auth challenge at all.
    $this->getJson('/graphql/schema')->assertNotFound();
});

it('publishes a multi-valued relationship select as disabled', function () {
    // The write path never calls saveRelationships(), so this field returns
    // 201 having attached nothing. Publishing it editable invites a user to
    // fill a control whose contents are silently discarded.
    $node = findFormNode(schemaFor('banners'), 'tag_ids');

    expect($node['disabled'])->toBeTrue();
});

it('leaves a single-valued relationship select editable', function () {
    // A BelongsTo select writes a foreign key like any other column. Flipping
    // it too would break the common case in the name of fixing the rare one.
    $node = findFormNode(schemaFor('banners'), 'company_id');

    expect($node['disabled'])->toBeFalse();
});

it('leaves a plain multiple select editable', function () {
    // Select::multiple() over a JSON/array column writes fine. The refusal is
    // about the RELATIONSHIP, not about multiplicity.
    $node = findFormNode(schemaFor('banners'), 'plain_multi');

    expect($node['disabled'])->toBeFalse();
});

it('publishes a CheckboxList relationship as disabled, the one real class with no isMultiple()', function () {
    // CheckboxList is inherently multi-valued and, like the singular
    // relationship container below, has no isMultiple() to ask. It is named
    // explicitly by class in isMultiValuedRelationship() rather than assumed
    // from the absent method.
    //
    // The fixture's `->dehydrated()` is load-bearing: CheckboxList::relationship()
    // also calls dehydrated(false) unconditionally, so without overriding it
    // back to true this field would be locked by the pre-existing
    // dehydrated-literal check regardless of the class-name branch — double
    // covered exactly like the Section below, and this assertion would pass
    // for the wrong reason. With the override, this is the one wired
    // assertion that actually depends on isMultiValuedRelationship() naming
    // CheckboxList by class.
    $node = findFormNode(schemaFor('banners'), 'checkbox_tag_ids');

    expect($node['disabled'])->toBeTrue();
});

/**
 * A SINGULAR relationship CONTAINER — review's suggested guard rail for
 * isMultiValuedRelationship(), since Section/Group/Grid/Fieldset/Flex/Form
 * all expose getRelationship() through EntanglesStateWithSingularRelationship
 * and, like CheckboxList, have no isMultiple() to ask.
 *
 * It still publishes `disabled: true` — correctly, not because of the
 * multi-valued check this task added. `EntanglesStateWithSingularRelationship
 * ::relationship()` unconditionally calls `dehydrated(false)`: the
 * container's own value is never part of getState() because Filament saves
 * it through `saveRelationshipsBeforeChildrenUsing()`, which only its own
 * `saveRelationships()` invokes — and this package's write path never calls
 * that either. So the nested `name` field here is exactly as discarded as
 * `tag_ids` is, just caught by the pre-existing dehydrated-literal check
 * (see `isUndehydratedByConfiguration()`) rather than by the new one.
 *
 * `tests/Unit/FieldPersistenceTest.php` is the guard rail that actually
 * isolates the fixed discrimination: every real Filament class in this
 * family is double-covered, so no fixture reachable through this package's
 * supported component vocabulary can show the multi-valued check flipping
 * this field's `disabled` on its own.
 */
it('locks a singular relationship container, but for its own dehydration, not for being mistaken as multi-valued', function () {
    $node = findFormNodeWhere(
        schemaFor('banners'),
        fn (array $node): bool => $node['type'] === 'section' && $node['label'] === 'Company',
    );

    expect($node['disabled'])->toBeTrue();
});

it('emits a field default so a create form can show it', function () {
    // The server already APPLIES defaults on write (FormDefaults). This is
    // about the user seeing what they are about to save.
    $node = findFormNode(schemaFor('banners'), 'status');

    expect($node['default'])->toBe('draft');
});

it('omits the key entirely when a field declares no default', function () {
    // Not `null` — absent. A null default and no default are different, and
    // the client distinguishes an explicit null from an unset field.
    $node = findFormNode(schemaFor('banners'), 'name');

    expect($node)->not->toHaveKey('default');
});

it('degrades a throwing default to no default rather than a 500', function () {
    // Every closure the walker evaluates goes through SafeEvaluator. A default
    // reaching for context that does not exist outside a Livewire request must
    // cost one field's prefill, not the whole /schema — the FIELD must survive
    // the walk. Asserting only `not->toHaveKey('default')` is satisfied just as
    // well by a coarser guard that drops the entire field (findFormNode()
    // returns null, and Pest casts null to `[]` before toHaveKey, so the
    // assertion holds vacuously) — that would prove nothing about
    // SafeEvaluator specifically. Asserting the node survives, with its name,
    // type and label intact, is what only the field-level guard can satisfy;
    // the whole-component catch in SchemaWalker::walk() cannot.
    $schema = schemaFor('banners');
    $node = findFormNode($schema, 'throwing_default');

    expect($node)->not->toBeNull()
        ->and($node['name'])->toBe('throwing_default')
        ->and($node['type'])->toBe('text')
        ->and($node['label'])->toBe('Throwing default')
        ->and($node)->not->toHaveKey('default');

    expect(findFormNode($schema, 'name'))->not->toBeNull();
});

it('publishes numeric so the client can tell a value bound from a length', function () {
    $node = findFormNode(schemaFor('banners'), 'quantity');

    expect($node['rules']['numeric'])->toBeTrue();
});

it('publishes numeric even when the field renders as another type', function () {
    // ->email()->numeric() types as `email` because that is how it renders.
    // The rule is a separate question and must survive the type refinement.
    $node = findFormNode(schemaFor('banners'), 'numeric_email');

    expect($node['type'])->toBe('email')
        ->and($node['rules']['numeric'])->toBeTrue();
});

it('omits numeric on an ordinary text field', function () {
    // Absent, not false — the client treats a missing key as "not numeric",
    // and an always-present false would bloat every node in the panel.
    $node = findFormNode(schemaFor('banners'), 'name');

    expect($node['rules'])->not->toHaveKey('numeric');
});

it('publishes a translated message for each rule it emits', function () {
    app()->setLocale('ar');

    $node = findFormNode(schemaFor('banners'), 'name');

    expect($node['rules']['messages'])->toHaveKey('required')
        ->and($node['rules']['messages']['required'])->not->toBe('')
        ->and($node['rules']['messages']['required'])
            ->toBe(__('validation.required', ['attribute' => 'display Title']));
});

it('publishes no messages key for a field with no rules', function () {
    $node = findFormNode(schemaFor('banners'), 'secret_note');

    expect($node['rules'])->not->toHaveKey('messages');
});

it('interpolates :attribute and :max into the rendered max message rather than the raw array Laravel keys them under', function () {
    // validation.max is not a flat string — Laravel keys it by attribute
    // type (numeric/string/array/file), exactly like the 422 it eventually
    // sends. Asserting only that the `max` key exists would pass just as
    // well if trans() handed back the whole keyed array (a real defect: the
    // contract promises a string, and :attribute/:max would still be
    // unsubstituted placeholders on 3 of its 4 branches). Asserting the
    // exact rendered sentence is what catches both failure modes.
    $node = findFormNode(schemaFor('banners'), 'name');

    expect($node['rules']['messages']['max'])->toBeString()
        ->toBe(__('validation.max.string', ['attribute' => 'display Title', 'max' => 80]));
});

it('interpolates the label into :attribute, not the raw column name', function () {
    // getValidationAttribute() is what Filament's own rule closures pass as
    // :attribute — label-aware ("numeric email"), not the raw field name
    // ("numeric_email"). A published hint that names the field differently
    // than the real 422 would is exactly the disagreement this feature
    // exists to prevent.
    //
    // `numeric_email` is the weak half of this: its label is Filament's own
    // default, which humanises to exactly what Laravel's :attribute fallback
    // produces, so the two agree by accident and neither side proves the
    // other. `name` carries an explicit label that does NOT match its column,
    // which is the case the pilot found on 137 of 187 real fields; the
    // agreement between this string and the real 422 is pinned in
    // MessageAttributeTest.
    $labelled = findFormNode(schemaFor('banners'), 'name');

    expect($labelled['rules']['messages']['required'])
        ->toBe(__('validation.required', ['attribute' => 'display Title']))
        ->not->toContain('The name field');

    $node = findFormNode(schemaFor('banners'), 'numeric_email');

    expect($node['rules']['messages']['numeric'])
        ->toBe(__('validation.numeric', ['attribute' => 'numeric email']))
        ->not->toContain('numeric_email');
});

it('resolves max through the numeric branch, not the string one, when both rules apply', function () {
    // No other fixture combines numeric() with maxLength(). Without this,
    // the numeric branch of the max/min type resolution is only proven by
    // an ad-hoc probe, not a test — and a regression back to the flat
    // "validation.max" key (an array) or to the string branch would pass
    // every other test in this file.
    $node = findFormNode(schemaFor('banners'), 'bounded_quantity');

    expect($node['rules']['messages']['max'])
        ->toBe(__('validation.max.numeric', ['attribute' => 'bounded quantity', 'max' => 80]))
        ->toContain('must not be greater than 80')
        ->not->toContain('characters');
});

it('publishes a rich editor as a textarea, so it can be written', function () {
    // Read-only would leave the NOT NULL column with no rule and no key, so
    // the insert would still fail. Writable is the point.
    $node = findFormNode(schemaFor('banners'), 'body_html');

    expect($node['type'])->toBe('textarea')
        ->and($node['rules'])->toHaveKey('required');
});

it('publishes the email rule', function () {
    $node = findFormNode(schemaFor('banners'), 'contact_email');

    expect($node['rules']['email'])->toBeTrue();
});

it('gives the email rule a translated message like every other rule', function () {
    app()->setLocale('ar');

    $node = findFormNode(schemaFor('banners'), 'contact_email');

    expect($node['rules']['messages'])->toHaveKey('email')
        ->and($node['rules']['messages']['email'])->not->toContain(':');
});

it('omits the email key on a field that is not an email', function () {
    expect(findFormNode(schemaFor('banners'), 'name')['rules'])->not->toHaveKey('email');
});

it('marks a field this package cannot persist as not writable', function () {
    $node = findFormNode(schemaFor('banners'), 'avatar');   // a file field

    expect($node['writable'])->toBeFalse();
});

it('omits writable on an ordinary field', function () {
    // Absent means true. An always-present true would add a key to every node
    // in the panel for a question that usually does not arise.
    expect(findFormNode(schemaFor('banners'), 'name'))->not->toHaveKey('writable');
});

it('keeps disabled meaning what the panel said, independently', function () {
    // A field the PANEL disabled is disabled but perfectly persistable; the
    // two flags answer different questions and must not be aliases.
    $node = findFormNode(schemaFor('banners'), 'locked_note');

    expect($node['disabled'])->toBeTrue()
        ->and($node)->not->toHaveKey('writable');
});

it('emits optionsUrl for a searchable relationship select whose options Filament withholds', function () {
    // `searchable_company_id` is searchable AND a relationship: Filament's own
    // getOptionsFromRelationship() returns null (not preloaded), so getOptions()
    // comes back empty regardless of how many companies exist — seeding one
    // proves the trigger is the withholding, not an empty table.
    seedBanner();

    $node = findFormNode(schemaFor('banners'), 'searchable_company_id');

    expect($node['config']['optionsUrl'])->toBe('/api/mobile-panel/banners/options')
        ->and($node['config'])->not->toHaveKey('options');
});

it('inlines a static multiple select despite multiple() defaulting isSearchable() to true', function () {
    // Filament's Select::isSearchable() falls back to isMultiple() when not
    // set explicitly, so `plain_multi` reads as "searchable" with no
    // ->searchable() call in the fixture. It must still inline: its three
    // options are already known, and forcing a network round trip for a
    // static list the web panel already has in the DOM buys nothing.
    $node = findFormNode(schemaFor('banners'), 'plain_multi');

    expect($node['config']['options'])->not->toBeEmpty()
        ->and($node['config'])->not->toHaveKey('optionsUrl');
});

it('emits optionsUrl when the option count exceeds the cap', function () {
    // Not because the panel asked, but because the list outgrew the wire.
    config(['filament-mobile.options_inline_max' => 2]);

    $node = findFormNode(schemaFor('banners'), 'status');   // 3 options

    expect($node['config'])->toHaveKey('optionsUrl')
        ->and($node['config'])->not->toHaveKey('options');
});

it('still inlines a small non-searchable select', function () {
    // The common case must not pay for the rare one.
    $node = findFormNode(schemaFor('banners'), 'status');

    expect($node['config']['options'])->not->toBeEmpty()
        ->and($node['config'])->not->toHaveKey('optionsUrl');
});

it('respects the configured prefix in the emitted url', function () {
    // A panel served at a different prefix must not receive a path pointing
    // at the default one — the client would 404 on every search.
    seedBanner();
    config(['filament-mobile.prefix' => 'api/admin']);

    expect(findFormNode(schemaFor('banners'), 'searchable_company_id')['config']['optionsUrl'])
        ->toBe('/api/admin/banners/options');
});

describe('resource group', function () {
    beforeEach(function () {
        config()->set('filament-mobile.resources', [
            PostResource::class,
            BannerResource::class,
            TagResource::class,
            BrokenGroupResource::class,
        ]);
    });

    it('publishes a resource navigation group', function () {
        expect(resourceBlock('banners')['group'])->toBe('Content');
    });

    it('omits group entirely when a resource declares none', function () {
        // Absent, not null: a null would add a key to every resource in a panel
        // that groups nothing, and the client treats missing as ungrouped.
        expect(resourceBlock('posts'))->not->toHaveKey('group');
    });

    it('resolves an ENUM group to a string', function () {
        // Filament permits a backed enum as a group, and a naive (string) cast
        // fatals on it. A fixture with only string groups would pass while every
        // enum-grouped panel 500s on /schema — the failure shape P1 shipped twice.
        // Asserted against the label, not merely "non-empty string": the
        // label ('Catalog') and the backed value ('catalog') are both
        // non-empty, so a weaker assertion can't tell the HasLabel branch
        // from the ->value branch — a swapped match arm would still pass.
        expect(resourceBlock('tags')['group'])->toBe('Catalog');
    });

    it('degrades a throwing group accessor to no group, not a 500', function () {
        // Every accessor this package reads goes through SafeEvaluator for the
        // same reason: one resource must not cost the whole document.
        expect(resourceBlock('broken-group'))->not->toHaveKey('group');
    });
});
