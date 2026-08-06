<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Tag;

/**
 * P3b Task 8 — the write path saves multi-valued relationships.
 *
 * Filament persists a `Select::multiple()->relationship()` through
 * `saveRelationships()`, never through the model's attributes: the field is
 * non-dehydrating by design, so it has no rule, no place in the validated
 * payload, and no column. Until this suite, the mobile write path answered
 * 200/201 having attached nothing — and the field was published `disabled`
 * to keep that honest. This suite is the other half: save the pivot, publish
 * the field editable.
 *
 * The dangerous shape is absence. A request that never mentions `tag_ids`
 * must leave the pivot exactly as it was — syncing the settled (trusted)
 * state would CLEAR a relation the request never touched, the silent-data-
 * loss shape this package has closed in every phase. Absent and `[]` are
 * different statements and both suites below hold them apart.
 */
function tag(string $name): Tag
{
    return Tag::query()->create(['name' => $name]);
}

it('attaches a submitted relation on update', function () {
    $banner = seedBanner('Rel');
    $a = tag('a');
    $b = tag('b');

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => 'Rel',
            'body_html' => '<p>B</p>',
            'tag_ids' => [$a->id, $b->id],
        ])
        ->assertOk();

    expect($banner->tags()->pluck('tags.id')->all())
        ->toEqualCanonicalizing([$a->id, $b->id]);
});

it('replaces, not merges: ids absent from a submitted list detach', function () {
    $banner = seedBanner('Swap');
    $old = tag('old');
    $new = tag('new');
    $banner->tags()->attach($old);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => 'Swap',
            'body_html' => '<p>B</p>',
            'tag_ids' => [$new->id],
        ])
        ->assertOk();

    expect($banner->tags()->pluck('tags.id')->all())->toBe([$new->id]);
});

it('leaves a relation the request never mentioned untouched', function () {
    $banner = seedBanner('Untouched');
    $kept = tag('kept');
    $banner->tags()->attach($kept);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => 'Untouched',
            'body_html' => '<p>B</p>',
        ])
        ->assertOk();

    expect($banner->tags()->pluck('tags.id')->all())->toBe([$kept->id]);
});

it('clears a relation submitted as an explicit empty list', function () {
    $banner = seedBanner('Cleared');
    $banner->tags()->attach(tag('gone'));

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => 'Cleared',
            'body_html' => '<p>B</p>',
            'tag_ids' => [],
        ])
        ->assertOk();

    expect($banner->tags()->count())->toBe(0);
});

it('attaches a submitted relation on create', function () {
    $t = tag('fresh');

    $response = $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', [
            'name' => 'Created',
            'body_html' => '<p>B</p>',
            'tag_ids' => [$t->id],
        ])
        ->assertCreated();

    $id = $response->json('data.id');

    expect(\Gait\FilamentMobile\Tests\Fixtures\Models\Banner::query()->find($id)->tags()->pluck('tags.id')->all())
        ->toBe([$t->id]);
});

it('syncs a CheckboxList relationship without ever touching a column', function () {
    // checkbox_tag_ids overrides ->dehydrated() back to true, so without an
    // explicit relation carve-out in RuleExtractor it would gain a rule,
    // survive validation, and reach $record->update() as a column write —
    // which crashes, because no such column exists. A 200 here proves both
    // halves: the pivot synced AND the name never entered mass assignment.
    $banner = seedBanner('Checkbox');
    $t = tag('boxed');

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => 'Checkbox',
            'body_html' => '<p>B</p>',
            'checkbox_tag_ids' => [$t->id],
        ])
        ->assertOk();

    expect($banner->tags()->pluck('tags.id')->all())->toBe([$t->id]);
});

it('refuses a disabled relation select, and does not clear it either', function () {
    // Fail closed, both ways: the crafted ids must not attach, and the
    // refusal must not degrade into a sync-to-settled-state that clears
    // what was already there.
    $banner = seedBanner('Gated');
    $kept = tag('kept');
    $crafted = tag('crafted');
    $banner->tags()->attach($kept);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => 'Gated',
            'body_html' => '<p>B</p>',
            'gated_tag_ids' => [$crafted->id],
        ])
        ->assertOk();

    expect($banner->tags()->pluck('tags.id')->all())->toBe([$kept->id]);
});

it('publishes the relation select editable now that the write path saves it', function () {
    $node = findFormNode(schemaFor('banners'), 'tag_ids');

    expect($node['disabled'])->toBeFalse();
});
