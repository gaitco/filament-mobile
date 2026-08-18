<?php

declare(strict_types=1);

use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;
use Gait\FilamentMobile\Tests\Fixtures\Resources\BannerResource;
use Gait\FilamentMobile\Upload\UploadFieldResolver;

function uploadResolver(): UploadFieldResolver
{
    return new UploadFieldResolver(BannerResource::class);
}

it('resolves a single-file upload field', function () {
    $component = uploadResolver()->resolve('hero_image');

    expect($component)->not->toBeNull()
        ->and($component->getName())->toBe('hero_image');
});

it('publishes the field own accepted types and max size', function () {
    $resolver = uploadResolver();
    $constraints = $resolver->constraintsFor($resolver->resolve('hero_image'));

    expect($constraints['types'])->toBe(['image/png', 'image/jpeg'])
        ->and($constraints['maxSizeKb'])->toBe(1024);
});

it('resolves a multiple upload field, since P12', function () {
    // Multiplicity is no longer a refusal: the endpoint still takes ONE file
    // per request and the client loops, and the per-file constraints apply
    // exactly as for a single-file field. The name is in the write path's
    // own allow-set, which is what this resolve keys off.
    $component = uploadResolver()->resolve('gallery');

    expect($component)->not->toBeNull()
        ->and($component->getName())->toBe('gallery');
});

it('refuses a disabled upload field', function () {
    expect(uploadResolver()->resolve('locked_file'))->toBeNull();
});

it('refuses a single-file upload nested inside a disabled section', function () {
    // A disabled Section is the idiomatic whole-column permission gate — see
    // BannerResource's `Restricted` section. Task 2 relies on exactly this
    // property: a container gate protects the upload inside it the same way
    // it protects any other field.
    expect(uploadResolver()->resolve('restricted_image'))->toBeNull();
});

it('refuses a field whose disabled gate throws rather than propagating', function () {
    // Fail closed: a gate that cannot answer must not be read as "not
    // disabled". See FieldPersistence for the same rule applied elsewhere.
    expect(fn () => uploadResolver()->resolve('exploding_gate'))->not->toThrow(Throwable::class)
        ->and(uploadResolver()->resolve('exploding_gate'))->toBeNull();
});

it('refuses a field whose multiple() gate throws rather than propagating', function () {
    // The closed answer all three readers must share: RuleExtractor
    // withholds the rule and SchemaWalker publishes readOnly: true on the
    // same throw (tested in their own suites). The refusal here rides the
    // WritableNames check — the withheld rule keeps the name out of the
    // allow-set — so it cannot drift from what the write path would do.
    expect(fn () => uploadResolver()->resolve('exploding_multiple'))->not->toThrow(Throwable::class)
        ->and(uploadResolver()->resolve('exploding_multiple'))->toBeNull();
});

it('reports a throwing constraint closure as refused, not as max:0', function () {
    // `max:0` passes a zero-byte file — "refuse everything" must be an
    // explicit flag the controller answers 422 for, not a rule that leaks
    // one input through.
    $resolver = uploadResolver();

    expect($resolver->constraintsFor($resolver->resolve('exploding_max_size'))['refused'])->toBeTrue()
        ->and($resolver->constraintsFor($resolver->resolve('exploding_types'))['refused'])->toBeTrue()
        ->and($resolver->constraintsFor($resolver->resolve('hero_image'))['refused'])->toBeFalse();
});

it('refuses a field that is not an upload at all', function () {
    // `name` is a TextInput. Accepting it would let a crafted request write a
    // file path into an arbitrary column.
    expect(uploadResolver()->resolve('name'))->toBeNull();
});

it('refuses a field the form does not declare', function () {
    expect(uploadResolver()->resolve('no_such_field'))->toBeNull();
});

it('refuses rather than throwing when the resource form cannot be built', function () {
    // Fail closed, the rule everywhere else in this package.
    //
    // BrokenInfolistResource is NOT the fixture for this: BrokenSchemaTest
    // pins that its *form* builds cleanly ("The form is fine — only the
    // infolist is broken"), so resolving against it would prove nothing
    // about the catch(Throwable) around components() below. An anonymous
    // resource whose form() throws unconditionally is what actually
    // exercises it.
    $class = new class extends Resource
    {
        protected static ?string $model = Banner::class;

        public static function form(Schema $schema): Schema
        {
            throw new RuntimeException('deliberately broken form');
        }
    };

    $resolver = new UploadFieldResolver($class::class);

    expect(fn () => $resolver->resolve('anything'))->not->toThrow(Throwable::class)
        ->and($resolver->resolve('anything'))->toBeNull();
});
