<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Company;

/**
 * The write pilot's headline finding.
 *
 * `Select::relationship()` resolves its options through the schema's model
 * instance, reached through the component's container. The package supplied
 * neither: `Schema::make($host)` set no `->model()`, and the walker recursed
 * with `getDefaultChildComponents()`, whose children are detached. Both
 * failures surfaced as an empty `options` array with *no warning* — the
 * walker's missing-container guard swallowed it as an ordinary default.
 *
 * A required foreign key therefore reached the phone as a picker with nothing
 * in it, and every write to that resource 422'd on a field the client had no
 * legal value to send. Six of the pilot panel's 33 resources were blocked on
 * exactly this, and it was invisible in the fixtures: the committed contract
 * snapshot carried `company_id` with `options: []` and the suite was green.
 */
function optionsFor(array $document, string $name): array
{
    $find = function (array $nodes) use (&$find, $name): ?array {
        foreach ($nodes as $node) {
            if (isset($node['children'])) {
                $hit = $find($node['children']);

                if ($hit !== null) {
                    return $hit;
                }

                continue;
            }

            if (($node['name'] ?? null) === $name) {
                return $node;
            }
        }

        return null;
    };

    return $find($document)['config']['options'] ?? [];
}

it('publishes the options of a relationship select nested in a section', function () {
    $company = Company::create(['name' => 'Acme']);

    $response = $this->actingAs(makeUser('admin'))->getJson('/api/mobile-panel/schema');

    $banners = collect($response->json('resources'))->firstWhere('key', 'banners');

    expect(optionsFor($banners['form'], 'company_id'))
        ->toBe([['value' => $company->id, 'label' => 'Acme']]);
});

it('publishes the same options from /state', function () {
    $company = Company::create(['name' => 'Acme']);

    $response = $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/state', ['state' => []]);

    expect(optionsFor($response->json('components'), 'company_id'))
        ->toBe([['value' => $company->id, 'label' => 'Acme']]);
});
