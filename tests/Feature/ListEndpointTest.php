<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Support\Facades\DB;

it('paginates with the contract meta shape', function () {
    seedBanners(45);

    $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/banners')
        ->assertOk()
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.per_page', 20)
        ->assertJsonPath('meta.total', 45)
        ->assertJsonPath('meta.last_page', 3)
        ->assertJsonCount(20, 'data');
});

it('serves only the attributes the card and infolist reference', function () {
    seedBanners(1);

    $record = $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/banners')
        ->json('data.0');

    expect($record)->toHaveKeys(['id', 'name', 'status', 'created_at'])
        ->and($record)->not->toHaveKey('internal_note');
});

it('nests a dotted relation path', function () {
    seedBanners(1);

    $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/banners')
        ->assertJsonStructure(['data' => [['company' => ['name']]]]);
});

it('eager loads relations so the query count does not grow with the page', function () {
    seedBanners(20);

    DB::enableQueryLog();
    $this->actingAs(makeUser('admin'))->getJson('/api/mobile-panel/banners')->assertOk();
    $queries = count(DB::getQueryLog());

    // Without the derived eager loading this is 20+ queries.
    expect($queries)->toBeLessThan(10);
});

it('searches across the declared searchable columns', function () {
    seedBanner(name: 'الأول');
    seedBanner(name: 'الثاني');

    // Percent-encoded, as a real client sends it — and as this test must send
    // it: PHP 8.4's parse_url(), which Request::create() runs on the URI,
    // rewrites raw UTF-8 bytes in the C1 range (0x80–0x9F) to '_', so a raw
    // Arabic query string is mangled before any application code sees it.
    $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/banners?search=' . urlencode('الأول'))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'الأول');
});

it('sorts by a declared key', function () {
    seedBanner(name: 'ب');
    seedBanner(name: 'أ');

    $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/banners?sort=name&direction=asc')
        ->assertJsonPath('data.0.name', 'أ');
});

it('rejects an undeclared sort key with 422 rather than ignoring it', function () {
    $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/banners?sort=internal_note')
        ->assertStatus(422);
});

it('rejects an array-typed query parameter with 422 rather than fataling', function (string $param) {
    seedBanners(1);

    $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/banners?{$param}[]=x")
        ->assertStatus(422);
})->with(['search', 'sort', 'direction']);

it('does not let a bare LIKE wildcard match every row', function () {
    seedBanner(name: 'الأول');
    seedBanner(name: 'الثاني');

    $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/banners?search=' . urlencode('%'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('treats every LIKE metacharacter as a literal, including the escape character itself', function (string $term) {
    // `!` is the escape character, so it must be escaped first or escaping
    // becomes self-corrupting. These hold on any driver: the escaping is done
    // in PHP and the ESCAPE clause is a constant.
    seedBanner(name: 'plain');
    seedBanner(name: "literal {$term} here");

    $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/banners?search=' . urlencode($term))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', "literal {$term} here");
})->with(['%', '_', '!', '!%', '%_!']);

it('emits an ESCAPE clause that is a self-contained literal on every driver', function () {
    // The pilot found `escape '\'` here, which SQLite parses as a literal
    // backslash and MySQL reads as an unterminated string literal — 100% of
    // search requests were a 1064 syntax error, invisible to this SQLite suite.
    //
    // The clause is a constant, so asserting on the SQL the search actually
    // generates is driver-independent: what broke MySQL was the backslash in
    // the literal, and an unbalanced quote is the shape of that failure.
    seedBanner(name: 'anything');

    DB::enableQueryLog();

    $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/banners?search=x')
        ->assertOk();

    $searchSql = collect(DB::getQueryLog())
        ->pluck('query')
        ->first(fn (string $sql): bool => str_contains($sql, 'like ?'));

    DB::disableQueryLog();

    expect($searchSql)->not->toBeNull()
        ->and($searchSql)->toContain("like ? escape '!'")
        ->and($searchSql)->not->toContain('\\')
        ->and(substr_count($searchSql, "'") % 2)->toBe(0);
});

it('treats an empty sort as absent and falls back to the default', function () {
    seedBanner(name: 'first');
    $this->travel(1)->minutes(); // distinct created_at, or the sort is a tie
    seedBanner(name: 'second');

    // Without this the check is vacuous: Laravel's global
    // ConvertEmptyStringsToNull would turn `sort=` into null before the
    // controller ever sees it. Plenty of API apps drop that middleware — the
    // endpoint has to be right on its own.
    $this->withoutMiddleware(ConvertEmptyStringsToNull::class);

    // The default sort is created_at desc, so the newest row leads.
    $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/banners?sort=&direction=')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'second');
});

it('404s for a resource that declares no mobile()', function () {
    $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/secrets')
        ->assertNotFound();
});

it('403s when the policy denies viewAny', function () {
    $this->actingAs(makeUser('restricted'))
        ->getJson('/api/mobile-panel/posts')
        ->assertForbidden();
});

it('applies the resource getEloquentQuery scope', function () {
    // The fixture BannerResource scopes out soft-deleted records.
    seedBanners(3);
    softDeleteOneBanner();

    $this->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/banners')
        ->assertJsonPath('meta.total', 2);
});
