<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('public'));

it('stores a valid file and returns its path', function () {
    $response = $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/upload', [
            'field' => 'hero_image',
            'file' => UploadedFile::fake()->image('photo.png'),
        ])
        ->assertOk();

    $path = $response->json('path');

    expect($path)->toBeString()->not->toBeEmpty();
    Storage::disk('public')->assertExists($path);
});

it('refuses a file larger than the field allows', function () {
    // maxSize(1024) is KB, so 2 MB must be refused — by the SERVER, from the
    // field's own configuration, not by anything the client was told.
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/upload', [
            'field' => 'hero_image',
            'file' => UploadedFile::fake()->image('huge.png')->size(2048),
        ])
        ->assertStatus(422);
});

it('refuses a type the field does not accept', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/upload', [
            'field' => 'hero_image',
            'file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])
        ->assertStatus(422);
});

it('refuses a file whose content-type lies about its content', function () {
    // The sharpest case this endpoint exists to defend: the client claims
    // image/png, the bytes are not. The type must be sniffed from content.
    //
    // UploadedFile::fake()->createWithContent() cannot express this case:
    // Illuminate\Http\Testing\File overrides getMimeType() to derive the
    // MIME type from the FILENAME's extension (Testing\MimeType::from()),
    // never from the bytes, so a fake file can never disagree with its own
    // name. A real Illuminate\Http\UploadedFile carries no such override —
    // its getMimeType() falls through to Symfony's File::getMimeType(),
    // which calls MimeTypes::guessMimeType() and genuinely sniffs the file
    // on disk. Constructing one directly (test mode, which only skips the
    // is_uploaded_file() check) is the only way to produce a file whose
    // claimed type and real bytes disagree. Verified directly: for this
    // content, getMimeType() sniffs 'text/x-php' while getClientMimeType()
    // — the claim carried in the constructor — stays 'image/png'.
    $path = tempnam(sys_get_temp_dir(), 'upload');
    file_put_contents($path, '<?php echo "x";');

    $file = new UploadedFile($path, 'evil.png', 'image/png', null, true);

    try {
        $this->actingAs(makeUser('admin'))
            ->postJson('/api/mobile-panel/banners/upload', [
                'field' => 'hero_image',
                'file' => $file,
            ])
            ->assertStatus(422);
    } finally {
        @unlink($path);
    }
});

it('accepts a file for a multiple field, one file per request', function () {
    // P12: the endpoint keeps its shape — `file` + `field`, one path back —
    // and a multiple field is served by N calls. `gallery` declares no
    // constraints and no disk, so it stores on the default disk like
    // `avatar`.
    Storage::fake('local');

    $response = $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/upload', [
            'field' => 'gallery',
            'file' => UploadedFile::fake()->image('photo.png'),
        ])
        ->assertOk();

    $path = $response->json('path');

    expect($path)->toBeString()->not->toBeEmpty();
    Storage::disk('local')->assertExists($path);
});

it('applies a multiple field\'s own per-file constraints, unchanged', function () {
    // Per-file enforcement is the upload's job (the count bound is the
    // write path's): `attachments` declares disk/maxSize/accept, and each
    // file of the loop must pass exactly as a single-file field's would.
    $response = $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/upload', [
            'field' => 'attachments',
            'file' => UploadedFile::fake()->image('photo.png'),
        ])
        ->assertOk();

    Storage::disk('public')->assertExists($response->json('path'));

    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/upload', [
            'field' => 'attachments',
            'file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])
        ->assertStatus(422);

    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/upload', [
            'field' => 'attachments',
            'file' => UploadedFile::fake()->image('huge.png')->size(2048),
        ])
        ->assertStatus(422);
});

it('refuses a disabled field and an unknown field identically', function () {
    // One bodyless 403 for every refusal: a client must not be able to map
    // the panel's configuration by probing field names. Asserting the
    // status code alone would be true by construction (every abort(403)
    // shares Laravel's default body) — comparing the bodies is what
    // actually pins "identical", not just "all forbidden". `gallery` left
    // this list in P12 — a multiple field is served now — and
    // `exploding_multiple` is the multiplicity shape that still refuses.
    $user = makeUser('admin');
    $bodies = [];

    foreach (['locked_file', 'no_such_field', 'name', 'exploding_multiple'] as $field) {
        $bodies[$field] = $this->actingAs($user)
            ->postJson('/api/mobile-panel/banners/upload', [
                'field' => $field,
                'file' => UploadedFile::fake()->image('photo.png'),
            ])
            ->assertForbidden()
            ->json();
    }

    $distinctBodies = array_unique(array_map(fn (array $body) => json_encode($body), $bodies));

    expect($distinctBodies)->toHaveCount(1);
});

it('refuses a non-string field with the same bodyless 403 as every other refusal, never a 500', function () {
    // `field[]=hero_image` makes `$request->input('field')` an array, and
    // the old `$request->string('field')` coerced it — an array-to-string
    // Warning Laravel escalates to a 500. A non-string `field` is just
    // another unresolvable field name: same bodyless 403, deliberately NOT
    // a validate() 422, which would make this refusal distinguishable from
    // the others.
    $user = makeUser('admin');

    $arrayBody = $this->actingAs($user)
        ->postJson('/api/mobile-panel/banners/upload', [
            'field' => ['hero_image'],
            'file' => UploadedFile::fake()->image('photo.png'),
        ])
        ->assertForbidden()
        ->json();

    $unknownBody = $this->actingAs($user)
        ->postJson('/api/mobile-panel/banners/upload', [
            'field' => 'no_such_field',
            'file' => UploadedFile::fake()->image('photo.png'),
        ])
        ->assertForbidden()
        ->json();

    expect($arrayBody)->toBe($unknownBody);
});

it('refuses a field whose multiple() gate throws, like every other unresolvable field', function () {
    // The resolver's own try/catch already refuses this; the test pins the
    // endpoint-level behaviour so the RuleExtractor/SchemaWalker halves
    // (tested in their own suites) can never drift from what a client sees.
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/upload', [
            'field' => 'exploding_multiple',
            'file' => UploadedFile::fake()->image('photo.png'),
        ])
        ->assertForbidden();
});

it('refuses a zero-byte file on a field whose max-size gate throws, rather than storing an empty file', function () {
    // `getMaxSize()` throwing used to map to `max:0` intending "refuse
    // everything" — but Laravel's max:0 PASSES a 0 KB file, so this
    // unrestricted field stored an empty file. The throw must fail closed
    // the way the types path does: a 422 in the same shape.
    $response = $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/upload', [
            'field' => 'exploding_max_size',
            'file' => UploadedFile::fake()->create('empty.png', 0, 'image/png'),
        ])
        ->assertStatus(422);

    expect($response->json('errors.file'))->toBeArray()->not->toBeEmpty();
});

it('refuses with a 422, never a 500, when a storage getter throws after validation passed', function () {
    // getDirectory()/getDiskName()/getVisibility() all evaluate closures.
    // A throwing one used to 500 the endpoint after every gate had said
    // yes — the only closure this endpoint read bare. 422 (not 403): the
    // field resolved and the file validated; like a throwing constraint
    // closure, this is "cannot accept a file right now", not "no such
    // field".
    $response = $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/upload', [
            'field' => 'exploding_disk',
            'file' => UploadedFile::fake()->image('photo.png'),
        ])
        ->assertStatus(422);

    expect($response->json('errors.file'))->toBeArray()->not->toBeEmpty();
});

it('refuses a file whose sniffed type the field cannot answer for, in the same shape as every other refusal', function () {
    // acceptedFileTypes() throwing must fail closed exactly like every
    // other validation refusal in this endpoint — same `message` + `errors`
    // shape, not a bare abort() a client's error-rendering machinery
    // would silently drop.
    $response = $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/upload', [
            'field' => 'exploding_types',
            'file' => UploadedFile::fake()->image('photo.png'),
        ])
        ->assertStatus(422);

    expect($response->json('errors.file'))->toBeArray()->not->toBeEmpty();
    expect($response->json('message'))->toBeString()->not->toBeEmpty();
});

it('never trusts the client-supplied filename extension for the stored file', function () {
    // A polyglot: genuinely valid PNG bytes (passes mimetypes:image/png)
    // whose CLIENT-supplied name claims a server-executable extension. If
    // the stored filename were built from the client's extension, this
    // would be written to a public disk as `<uuid>.pht` — remote code
    // execution on any webserver configured to execute that extension
    // (Apache's `AddHandler`/`AddType` commonly covers `.pht`/`.phtml`
    // alongside `.php`).
    //
    // `.pht` specifically, not `.php`: Laravel's own `mimetypes` rule
    // separately special-cases the literal php/php3/php4/php5/php7/php8/
    // phtml/phar extensions and blocks them outright before it even reaches
    // content sniffing (`shouldBlockPhpUpload()`), which would make a
    // `.php`-named case pass for the wrong reason — blocked by Laravel's
    // narrow hard-coded list, not by this endpoint's own extension
    // handling. `.pht` is outside that list, so this genuinely exercises
    // the fix: the file must still not be storable under the extension the
    // client chose.
    $tmpPath = tempnam(sys_get_temp_dir(), 'upload');
    $image = imagecreatetruecolor(2, 2);
    imagepng($image, $tmpPath);
    imagedestroy($image);

    $file = new UploadedFile($tmpPath, 'shell.pht', 'image/png', null, true);

    try {
        $response = $this->actingAs(makeUser('admin'))
            ->postJson('/api/mobile-panel/banners/upload', [
                'field' => 'hero_image',
                'file' => $file,
            ])
            ->assertOk();

        $path = $response->json('path');

        expect($path)->not->toEndWith('.pht');
        expect($path)->toEndWith('.png');
        Storage::disk('public')->assertExists($path);
    } finally {
        @unlink($tmpPath);
    }
});

it('never lets an unrestricted field store an executable extension, whatever this box\'s libmagic reports', function () {
    // The reviewer's exact reproduction — real PHP bytes named shell.php,
    // POSTed to `avatar`, a field that never calls acceptedFileTypes() at
    // all (constraintsFor() answers types: null, the ordinary "unrestricted"
    // shape, not a refusal) — made deterministic across machines.
    //
    // On THIS box, real PHP source bytes sniff as 'text/x-php'
    // (verified directly with mime_content_type()), which Symfony's table
    // does not map to any extension — so the reproduction is safe here by
    // ACCIDENT, not because of anything this fix does: a bare revert of
    // SAFE_EXTENSIONS would not turn this red on this machine, because the
    // unfiltered lookup already answers null for 'text/x-php'. Other
    // libmagic builds sniff the identical bytes as
    // 'application/x-httpd-php', which Symfony's table maps straight to
    // '.php' — that machine-dependent split IS the vulnerability the
    // review found. getMimeType() is the only interface the controller
    // reads, so overriding it to force that exact reported value is a
    // genuine, portable simulation of "a different box's libmagic" — not
    // a shortcut around the real code path.
    $tmpPath = tempnam(sys_get_temp_dir(), 'upload');
    file_put_contents($tmpPath, '<?php echo "pwn"; ?>');

    Storage::fake('local');

    $file = new class($tmpPath, 'shell.php', 'image/png', null, true) extends UploadedFile {
        public function getMimeType(): string
        {
            return 'application/x-httpd-php';
        }
    };

    try {
        $response = $this->actingAs(makeUser('admin'))
            ->postJson('/api/mobile-panel/banners/upload', [
                'field' => 'avatar',
                'file' => $file,
            ])
            ->assertOk();

        $storedPath = $response->json('path');

        expect($storedPath)->not->toEndWith('.php');
        Storage::disk('local')->assertExists($storedPath);
    } finally {
        @unlink($tmpPath);
    }
});

it('stores a sniffed type outside the safe-extension allow-list with no extension at all', function () {
    // A real, universally-recognised zip file: PK-magic detection is
    // deterministic across libmagic versions, unlike PHP/shell/Python
    // source (see the test above) — so this needs no getMimeType()
    // override to be portable. Symfony's table DOES map 'application/zip'
    // to '.zip' (verified directly), and 'zip' is deliberately absent from
    // SAFE_EXTENSIONS: this is the allow-list clamp firing on a real,
    // correctly-sniffed, non-dangerous type that simply isn't on the list,
    // not on a lie or a throw.
    $tmpPath = tempnam(sys_get_temp_dir(), 'upload');
    $zip = new ZipArchive();
    $zip->open($tmpPath, ZipArchive::OVERWRITE);
    $zip->addFromString('a.txt', 'hello');
    $zip->close();

    Storage::fake('local');

    $file = new UploadedFile($tmpPath, 'archive.zip', 'application/zip', null, true);

    try {
        $response = $this->actingAs(makeUser('admin'))
            ->postJson('/api/mobile-panel/banners/upload', [
                'field' => 'avatar',
                'file' => $file,
            ])
            ->assertOk();

        $storedPath = $response->json('path');

        expect($storedPath)->not->toContain('.');
        Storage::disk('local')->assertExists($storedPath);
    } finally {
        @unlink($tmpPath);
    }
});

it('answers 404 for an unknown resource before any authorization', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/nope/upload', [
            'field' => 'hero_image',
            'file' => UploadedFile::fake()->image('photo.png'),
        ])
        ->assertNotFound();
});

it('requires authentication', function () {
    $this->postJson('/api/mobile-panel/banners/upload', [
        'field' => 'hero_image',
        'file' => UploadedFile::fake()->image('photo.png'),
    ])->assertUnauthorized();
});

it('applies the viewAny gate before anything else, leaking no field information', function () {
    // Same ordering the other endpoints settled on — see RunActionTest's
    // enumeration-leak regression.
    $this->actingAs(makeUser('restricted'))
        ->postJson('/api/mobile-panel/posts/upload', [
            'field' => 'anything',
            'file' => UploadedFile::fake()->image('photo.png'),
        ])
        ->assertForbidden();
});
