<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Tag;
use Gait\FilamentMobile\Tests\Fixtures\Resources\BannerResource;

/**
 * Golden file for a real RELATION-LIST payload, the third endpoint shape to
 * get one: ContractSnapshotTest pins `/schema`, DashboardSnapshotTest pins
 * `/dashboard`, RecordSnapshotTest pins the record — this pins
 * `GET /{resource}/{record}/relations/{key}`'s `{data, meta}` envelope,
 * through the real endpoint over the golden fixture's own BelongsToMany
 * relation. Regenerate with UPDATE_SNAPSHOTS=1.
 *
 * The rows carry NO `id`, and that is the fixture working: Tag's route key
 * is `name` (see the model's own docblock), so each row's record key IS its
 * `name` — the contract rule that a relation row is keyed by the RELATED
 * model's route key, never assumed `id`. Do not "fix" the missing id.
 *
 * Row order is Blue then Red although Red was created first: BannerResource
 * declares `relationDefaultSort('tags', 'name')` (P11), so the golden also
 * proves the declared default sort reaches the endpoint. Do not "fix" the
 * order either.
 */

it('matches the committed relation-list contract snapshot', function () {
    // Same idiom RelationEndpointTest uses: the endpoint's authorization
    // resolves its user through the default panel, so one must exist.
    app(\Filament\PanelRegistry::class)->register(
        \Filament\Panel::make()->id('mobile-test')->default(),
    );

    // BannerResource is on TestCase's shared list already; stated here so
    // this test owns its resource set, like every other snapshot test.
    config()->set('filament-mobile.resources', [BannerResource::class]);

    $banner = seedBanner('Sale');
    $banner->tags()->attach(Tag::create(['name' => 'Red']));
    $banner->tags()->attach(Tag::create(['name' => 'Blue']));

    $body = $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}/relations/tags")
        ->assertOk()
        ->json();

    $json = json_encode(
        $body,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ) . "\n";

    if (getenv('UPDATE_SNAPSHOTS') === '1') {
        file_put_contents(contractPath('relation-list.json'), $json);
    }

    expect(contractPath('relation-list.json'))->toBeReadableFile()
        ->and($json)->toBe(file_get_contents(contractPath('relation-list.json')));
});
