<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;
use Gait\FilamentMobile\Tests\Fixtures\Models\Company;
use Gait\FilamentMobile\Tests\Fixtures\Models\Post;
use Gait\FilamentMobile\Tests\Fixtures\Models\User;

uses(Gait\FilamentMobile\Tests\TestCase::class)->in('Unit', 'Feature');

/**
 * The same nested value with every object's keys sorted, for comparing
 * anything that has been through a JSON column.
 *
 * MySQL's native `json` type stores objects with their keys sorted and hands
 * them back that way, so a repeater row written `['sku' => …, 'qty' => …]`
 * reads back `['qty' => …, 'sku' => …]`. Identical content — and key order is
 * not part of this wire contract: a JSON object is unordered, and the client
 * builds each repeater row from the schema's item TEMPLATE and reads every
 * value by name (see `RepeaterFieldWidget`). But `toBe()` is order-sensitive,
 * so nine assertions passed on SQLite and failed on MySQL the moment that job
 * could finally run.
 *
 * Sorting both sides keeps the strict comparison — a wrong type or a missing
 * key still fails — and drops only the ordering nothing ever promised.
 * Deliberately NOT `toEqual()`, which would fix the ordering by switching to
 * loose `==` and would then also stop telling `1` from `'1'`, exactly the
 * distinction a JSON-column test most needs.
 *
 * Lists keep their order: that IS contractual — a repeater's rows are
 * ordered, and only the maps inside them are not.
 */
function keySorted(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    $value = array_map(keySorted(...), $value);

    if (! array_is_list($value)) {
        ksort($value);
    }

    return $value;
}

function makeUser(string $name): User
{
    return User::create([
        'name' => $name,
        'email' => $name . '@example.test',
    ]);
}

function seedBanner(string $name = 'Banner', string $status = 'active', ?array $options = null): Banner
{
    return seedBannerWith(['name' => $name, 'status' => $status, 'options' => $options]);
}

/**
 * Attribute overrides merged over seedBanner()'s defaults, for a test that
 * needs a fixture-only column (e.g. `secret_note`, `infolist_only_note`)
 * without a dedicated named parameter for every column on the table.
 */
function seedBannerWith(array $attributes): Banner
{
    return Banner::create([
        'company_id' => Company::firstOrCreate(['name' => 'Acme'])->id,
        'name' => 'Banner',
        'status' => 'active',
        'internal_note' => 'never leaves the server',
        'options' => null,
        ...$attributes,
    ]);
}

function seedPost(string $title = 'Post', bool $published = true): Post
{
    return Post::create([
        'title' => $title,
        'body' => 'body',
        'published' => $published,
    ]);
}

function seedBanners(int $count): void
{
    for ($i = 1; $i <= $count; $i++) {
        seedBanner("Banner {$i}");
    }
}

function softDeleteOneBanner(): void
{
    Banner::query()->firstOrFail()->update(['deleted_at' => now()]);
}

/**
 * The full `/schema` document, for assertions against top-level keys (e.g.
 * `panel`) rather than a single resource — see schemaFor() for that.
 */
function schemaDocument(): array
{
    return test()->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/schema')
        ->assertOk()
        ->json();
}

/**
 * Thin wrapper over the `/schema` request, returning one resource by key.
 */
function schemaFor(string $key): array
{
    $resources = collect(
        test()->actingAs(makeUser('admin'))
            ->getJson('/api/mobile-panel/schema')
            ->assertOk()
            ->json('resources'),
    );

    return $resources->firstWhere('key', $key);
}

/**
 * Thin wrapper over schemaFor(), named for what a caller reading a resource's
 * top-level keys (e.g. `group`) actually wants, rather than the request that
 * produces it.
 */
function resourceBlock(string $key): array
{
    return schemaFor($key);
}

/**
 * Recursive name search over a resource's published `form` tree.
 */
function findFormNode(array $resource, string $name): ?array
{
    return findFormNodeWhere($resource, fn (array $node): bool => ($node['name'] ?? null) === $name);
}

/**
 * The general form: a predicate search over a resource's published `form`
 * tree. Layout containers (Section, Grid, Fieldset) never carry a `name` —
 * Filament's schema-level `HasKey` has no `getName()`, so `nameOf()` always
 * answers null for them — which is why a container can only be located by
 * something else it publishes, e.g. its `label`.
 */
function findFormNodeWhere(array $resource, callable $predicate): ?array
{
    return findNodeWhere($resource['form'], $predicate);
}

/** @param  list<array<string, mixed>>  $nodes */
function findNodeWhere(array $nodes, callable $predicate): ?array
{
    foreach ($nodes as $node) {
        if ($predicate($node)) {
            return $node;
        }

        if ($found = findNodeWhere($node['children'] ?? [], $predicate)) {
            return $found;
        }
    }

    return null;
}
