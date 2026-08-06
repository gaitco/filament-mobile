<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;
use Gait\FilamentMobile\Tests\Fixtures\Models\Company;
use Gait\FilamentMobile\Tests\Fixtures\Models\Post;
use Gait\FilamentMobile\Tests\Fixtures\Models\User;

uses(Gait\FilamentMobile\Tests\TestCase::class)->in('Unit', 'Feature');

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
