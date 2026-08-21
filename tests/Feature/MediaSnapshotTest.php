<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Gallery;
use Gait\FilamentMobile\Tests\Fixtures\Resources\GalleryResource;
use Illuminate\Support\Facades\Storage;

/**
 * Golden file for a real RECORD payload carrying Spatie medialibrary fields,
 * mirroring what RecordSnapshotTest does for rich text and DashboardSnapshotTest
 * does for `/dashboard`: this is what the real endpoint actually emits for
 * GalleryResource's `photos` (multiple) and `cover` (single) collections, read
 * by the Dart contract test. Regenerate with UPDATE_SNAPSHOTS=1.
 *
 * `record-payload.json` stays media-free deliberately (see contract/README.md)
 * — its fixture answers "server without this feature", the same role
 * `panel.json` plays for `direction`. This golden is the media-carrying sibling.
 *
 * Through the real endpoint, not RecordSerializer directly, for the same
 * reason every other snapshot test here goes through its controller: the
 * `photos`/`cover` sibling wiring lives in MobilePanelController::show()'s
 * mediaPaths assembly (MediaFields::pathsIn() over both form and infolist
 * components), not in RecordSerializer alone.
 */
it('matches the committed media-record contract snapshot', function () {
    // GalleryResource is not in TestCase's shared list (see its own docblock)
    // — registered here for the duration of this test only, same idiom
    // RecordSnapshotTest uses for RichResource.
    config()->set('filament-mobile.resources', [GalleryResource::class]);

    Storage::fake('public');

    $gallery = Gallery::create(['name' => 'Trip']);
    $gallery->addMediaFromString(fakePngBytes())->usingFileName('a.jpg')->toMediaCollection('photos');
    $gallery->addMediaFromString(fakePngBytes())->usingFileName('b.jpg')->toMediaCollection('photos');
    $gallery->addMediaFromString(fakePngBytes())->usingFileName('c.jpg')->toMediaCollection('cover');

    $body = $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/galleries/{$gallery->id}")
        ->assertOk()
        ->json();

    $normalised = normaliseMediaPayload($body);

    $json = json_encode(
        $normalised,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ) . "\n";

    if (getenv('UPDATE_SNAPSHOTS') === '1') {
        file_put_contents(contractPath('media-record.json'), $json);
    }

    expect(contractPath('media-record.json'))->toBeReadableFile()
        ->and($json)->toBe(file_get_contents(contractPath('media-record.json')));
});

/**
 * Replaces every volatile media value with a stable placeholder, so the
 * committed golden does not churn on every regeneration.
 *
 * `uuid`, `url`, `thumbUrl` and `size` are all runtime-random or
 * environment-dependent (uuids are generated per run; urls embed
 * `APP_URL` + the media id; a PNG's exact byte size can vary with the
 * GD/libpng build) — nothing else on this payload is (`id`, `name` and the
 * rest are seeded literals, same as every other snapshot test here).
 *
 * The placeholder scheme, applied identically when the golden is written and
 * when it is asserted against:
 * - each real uuid is replaced by `"uuid-1"`, `"uuid-2"`, … in ENCOUNTER
 *   ORDER — the order its owning media item is first seen while walking the
 *   `data` array's `*.__media` siblings — and every occurrence of that same
 *   uuid (both the raw `photos`/`cover` value and the `__media` item) maps to
 *   the same placeholder, so which-media-is-which survives the normalisation.
 * - `url` becomes `"https://media.test/{n}/{name}"` and a non-null `thumbUrl`
 *   becomes `"https://media.test/{n}/thumb-{name}"`, where `{n}` is that
 *   media's placeholder index and `{name}` its real (already-deterministic)
 *   file name — preserving which file each url belongs to and whether a
 *   thumbnail was generated, while dropping the random path segment.
 * - `size` becomes a fixed `100` for every item.
 *
 * @param  array<string, mixed>  $body
 * @return array<string, mixed>
 */
function normaliseMediaPayload(array $body): array
{
    $data = $body['data'];
    $uuidMap = [];

    foreach ($data as $key => $value) {
        if (! is_array($value) || ! str_ends_with((string) $key, '.__media')) {
            continue;
        }

        foreach ($value as $item) {
            $uuidMap[$item['uuid']] ??= 'uuid-' . (count($uuidMap) + 1);
        }
    }

    foreach ($data as $key => $value) {
        if (is_array($value) && str_ends_with((string) $key, '.__media')) {
            $data[$key] = array_map(static function (array $item) use ($uuidMap): array {
                $placeholder = $uuidMap[$item['uuid']];
                $item['uuid'] = $placeholder;
                // Read-then-replace, like thumbUrl below: substitute the
                // placeholder only when the source has the expected type, so
                // an endpoint that stopped emitting `url`/`size` (or emitted
                // the wrong type) leaves the wrong source value in place and
                // fails the golden comparison, instead of PHP silently
                // creating a "correct-looking" key on write.
                $item['url'] = is_string($item['url'])
                    ? "https://media.test/{$placeholder}/{$item['name']}"
                    : $item['url'];
                $item['thumbUrl'] = $item['thumbUrl'] === null
                    ? null
                    : "https://media.test/{$placeholder}/thumb-{$item['name']}";
                $item['size'] = is_int($item['size']) ? 100 : $item['size'];

                return $item;
            }, $value);

            continue;
        }

        if (is_string($value) && isset($uuidMap[$value])) {
            $data[$key] = $uuidMap[$value];
        } elseif (is_array($value)) {
            $data[$key] = array_map(static fn ($v) => is_string($v) && isset($uuidMap[$v]) ? $uuidMap[$v] : $v, $value);
        }
    }

    $body['data'] = $data;

    return $body;
}
