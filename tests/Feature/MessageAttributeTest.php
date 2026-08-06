<?php

declare(strict_types=1);

/**
 * The published `rules.messages[rule]` and the `422` the same submission gets
 * must be the same sentence — they come from one translator and the client
 * shows one before the round-trip and the other after it.
 *
 * They did not. `SchemaWalker::rules()` interpolates
 * `getValidationAttribute()`, which is Filament's label-aware attribute and
 * what the web panel shows; `$request->validate()` interpolated Laravel's
 * default, which is the humanised column name. Wherever a field carries a
 * label the pilot measured the two disagreeing on 137 of 187 constrained
 * fields in English — and on 148 of 187 in Arabic, where the `422` embedded a
 * raw English column name in an otherwise Arabic sentence
 * ("title.ar مطلوب").
 *
 * Every assertion below reads the expected text out of `/schema` rather than
 * hard-coding it, so the two can never be updated apart.
 */

use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;

/** The published message for one rule on one field of the banners form. */
function publishedMessage(string $field, string $rule): string
{
    $document = test()->actingAs(makeUser('reader'))
        ->getJson('/api/mobile-panel/schema')
        ->json();

    $node = collect($document['resources'])
        ->firstWhere('key', 'banners')['form'];

    $find = function (array $nodes) use (&$find, $field): ?array {
        foreach ($nodes as $n) {
            if (($n['name'] ?? null) === $field) {
                return $n;
            }
            $hit = $find($n['children'] ?? []);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    };

    return $find($node)['rules']['messages'][$rule];
}

it('answers a create 422 with the message it published for that rule', function () {
    $published = publishedMessage('name', 'required');

    // The label is 'Display Title', so this is "The display Title field is
    // required." — NOT "The name field is required.", which is what Laravel
    // produces from the column name alone.
    expect($published)->toContain('display Title');

    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', [])
        ->assertStatus(422)
        ->assertJsonPath('errors.name.0', $published);
});

it('answers an update 422 with the message it published for that rule', function () {
    $banner = seedBanner();
    $published = publishedMessage('name', 'max');

    expect($published)->toContain('display Title');

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => str_repeat('x', 81),
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.name.0', $published);
});

it('keeps the panel label in the 422 when the panel speaks another language', function () {
    // The whole point of publishing messages: the phone shows the panel's
    // language. A `422` that falls back to the column name puts an English
    // identifier inside an Arabic sentence, which is what the pilot saw.
    app('translator')->addLines(['validation.required' => 'حقل :attribute مطلوب.'], 'ar');
    app()->setLocale('ar');

    $published = publishedMessage('name', 'required');

    expect($published)->toBe('حقل display Title مطلوب.');

    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', [])
        ->assertStatus(422)
        ->assertJsonPath('errors.name.0', $published);
});

it('leaves an unlabelled field reading exactly as Laravel would', function () {
    // The guard rail. Passing custom attributes for EVERY name could just as
    // easily change a message that was already correct; `quantity` has no
    // explicit label, so both sides must still say "quantity".
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', [
            'name' => 'ok',
            'quantity' => 'not-a-number',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.quantity.0', 'The quantity field must be a number.');

    expect(Banner::query()->where('name', 'ok')->exists())->toBeFalse();
});
