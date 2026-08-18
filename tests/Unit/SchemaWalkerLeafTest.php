<?php

declare(strict_types=1);

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\Introspection\SchemaWalker;
use Gait\FilamentMobile\Introspection\WalkWarnings;

function walk(array $components): array
{
    return (new SchemaWalker(new WalkWarnings()))->walk($components, 'TestResource');
}

it('emits the contract node shape for a text field', function () {
    $nodes = walk([
        TextInput::make('email')->label('البريد')->placeholder('اكتب بريدك')->required(),
    ]);

    expect($nodes)->toHaveCount(1)
        ->and($nodes[0]['type'])->toBe('text')
        ->and($nodes[0]['name'])->toBe('email')
        ->and($nodes[0]['label'])->toBe('البريد')
        ->and($nodes[0]['placeholder'])->toBe('اكتب بريدك')
        ->and($nodes[0]['rules']['required'])->toBeTrue();
});

it('marks a live field so the client knows to round-trip', function () {
    $nodes = walk([Select::make('country_id')->live()]);

    expect($nodes[0]['live'])->toBeTrue();
});

it('leaves a non-live field unmarked', function () {
    $nodes = walk([TextInput::make('name')]);

    expect($nodes[0]['live'])->toBeFalse();
});

it('inlines statically resolvable select options', function () {
    $nodes = walk([
        Select::make('status')->options(['active' => 'نشط', 'banned' => 'محظور']),
    ]);

    expect($nodes[0]['config']['options'])->toBe([
        ['value' => 'active', 'label' => 'نشط'],
        ['value' => 'banned', 'label' => 'محظور'],
    ]);
});

it('marks a multiple select as multiselect', function () {
    $nodes = walk([Select::make('roles')->multiple()]);

    expect($nodes[0]['type'])->toBe('multiselect');
});

it('emits a CheckboxList as a multiselect carrying its options', function () {
    // The map entry alone is not the whole claim: config() reads getOptions()
    // for `multiselect`, so a CheckboxList has to arrive with real options or
    // the client renders an empty control.
    $nodes = walk([\Filament\Forms\Components\CheckboxList::make('roles')->options([
        'admin' => 'مدير',
        'editor' => 'محرر',
    ])]);

    expect($nodes[0]['type'])->toBe('multiselect')
        ->and($nodes[0]['name'])->toBe('roles')
        ->and($nodes[0]['config']['options'])->toBe([
            ['value' => 'admin', 'label' => 'مدير'],
            ['value' => 'editor', 'label' => 'محرر'],
        ]);
});

it('records a warning and omits an unsupported component', function () {
    $warnings = new WalkWarnings();
    $unsupported = new class {
        public function getName(): string
        {
            return 'weird';
        }
    };

    $nodes = (new SchemaWalker($warnings))->walk(
        [TextInput::make('kept'), $unsupported],
        'TestResource',
    );

    expect($nodes)->toHaveCount(1)
        ->and($nodes[0]['name'])->toBe('kept')
        ->and($warnings->all())->toHaveCount(1);
});

it('drops a Hidden field without warning', function () {
    $warnings = new WalkWarnings();

    $nodes = (new SchemaWalker($warnings))->walk(
        [TextInput::make('kept'), Filament\Forms\Components\Hidden::make('secret')],
        'TestResource',
    );

    expect($nodes)->toHaveCount(1)
        ->and($warnings->isEmpty())->toBeTrue();
});

it('maps a toggle', function () {
    expect(walk([Toggle::make('is_active')])[0]['type'])->toBe('toggle');
});

it('still records a warning when a container-less component genuinely throws', function () {
    $warnings = new WalkWarnings();

    $nodes = (new SchemaWalker($warnings))->walk(
        [TextInput::make('x')->disabled(fn () => throw new RuntimeException('boom'))],
        'TestResource',
    );

    expect($nodes[0]['disabled'])->toBeFalse()
        ->and($warnings->all())->toHaveCount(1)
        ->and($warnings->all()[0]['reason'])->toContain('boom');
});

it('emits a single-file field as writable, since P6a', function () {
    $nodes = walk([Filament\Forms\Components\FileUpload::make('avatar')->label('الصورة')]);

    expect($nodes[0]['type'])->toBe('file')
        ->and($nodes[0]['name'])->toBe('avatar')
        ->and($nodes[0]['label'])->toBe('الصورة')
        ->and($nodes[0]['config']['readOnly'])->toBeFalse()
        // `multiple` is always present on a current server's file node —
        // absence means a server predating P12, and a client must never
        // invent a capability the server did not declare.
        ->and($nodes[0]['config']['multiple'])->toBeFalse();
});

it('emits a multiple-file field as writable, publishing multiple and its declared count bounds', function () {
    // P12: a multiple field is a List<String> of stored paths, writable
    // through the ordinary write path — the refusal is gone. maxFiles/
    // minFiles publish only when the field declared them.
    $nodes = walk([Filament\Forms\Components\FileUpload::make('gallery')->multiple()->maxFiles(5)->minFiles(1)]);

    expect($nodes[0]['type'])->toBe('file')
        ->and($nodes[0])->not->toHaveKey('writable')
        ->and($nodes[0]['config']['readOnly'])->toBeFalse()
        ->and($nodes[0]['config']['multiple'])->toBeTrue()
        ->and($nodes[0]['config']['maxFiles'])->toBe(5)
        ->and($nodes[0]['config']['minFiles'])->toBe(1);
});

it('publishes no count bounds a multiple field did not declare', function () {
    $nodes = walk([Filament\Forms\Components\FileUpload::make('gallery')->multiple()]);

    expect($nodes[0]['config']['multiple'])->toBeTrue()
        ->and($nodes[0]['config'])->not->toHaveKey('maxFiles')
        ->and($nodes[0]['config'])->not->toHaveKey('minFiles');
});

it('locks a multiple-file field whose constraint closure throws, publishing no hints', function () {
    // The throwing-constraint rule is unchanged by P12: a field whose
    // acceptedFileTypes() cannot answer is one UploadController refuses
    // unconditionally, so the wire must not offer the control.
    $nodes = walk([Filament\Forms\Components\FileUpload::make('gallery')
        ->multiple()
        ->acceptedFileTypes(fn () => throw new RuntimeException('boom'))]);

    expect($nodes[0]['config']['readOnly'])->toBeTrue()
        ->and($nodes[0]['config']['multiple'])->toBeTrue()
        ->and($nodes[0]['config'])->not->toHaveKey('accept')
        ->and($nodes[0]['config'])->not->toHaveKey('maxSize')
        ->and($nodes[0]['config'])->not->toHaveKey('maxFiles')
        ->and($nodes[0]['config'])->not->toHaveKey('minFiles');
});

it('emits a file field whose multiple() gate throws as read-only and unwritable — the closed answer all three sites share', function () {
    // RuleExtractor withholds the rule and UploadFieldResolver refuses the
    // upload on the same throw, so the walker must not offer an editable
    // control either. `multiple: true` is the closed PUBLISHED shape: the
    // field is read-only either way, and a client must never render an
    // unknown-multiplicity stored value as a single path.
    $nodes = walk([Filament\Forms\Components\FileUpload::make('boom')
        ->multiple(fn () => throw new RuntimeException('broken gate'))]);

    expect($nodes[0]['writable'])->toBeFalse()
        ->and($nodes[0]['config']['readOnly'])->toBeTrue()
        ->and($nodes[0]['config']['multiple'])->toBeTrue()
        ->and($nodes[0]['config'])->not->toHaveKey('accept')
        ->and($nodes[0]['config'])->not->toHaveKey('maxSize');
});

it('resolves live and disabled through a real container, exactly like production', function () {
    $components = Schema::make()
        ->components([
            Select::make('country_id')->live(),
            TextInput::make('locked')->disabled(),
        ])
        ->getComponents();

    $warnings = new WalkWarnings();
    $nodes = (new SchemaWalker($warnings))->walk($components, 'TestResource');

    expect($nodes[0]['live'])->toBeTrue()
        ->and($nodes[1]['disabled'])->toBeTrue()
        ->and($warnings->isEmpty())->toBeTrue();
});

it('strips markup from the labels of an allowHtml() select', function () {
    // The write pilot: `->allowHtml()` is the idiomatic way to put an icon
    // beside each option, and one 30-option icon picker carried 22.6 KB of
    // inline SVG — 12% of the whole /schema document — which a phone renders
    // as literal `<span class="flex items-center gap-2"><svg …` in every row.
    $nodes = walk([
        Select::make('icon')
            ->options([
                'pencil' => '<span class="flex gap-2"><svg viewBox="0 0 20 20">'
                    ."\n".'<path d="m5 13"/></svg><span>Pencil Square</span></span>',
                'flag' => '<span>Flag &amp; Pin</span>',
            ])
            ->allowHtml(),
    ]);

    expect($nodes[0]['config']['options'])->toBe([
        ['value' => 'pencil', 'label' => 'Pencil Square'],
        ['value' => 'flag', 'label' => 'Flag & Pin'],
    ]);
});

it('leaves a plain label alone, angle brackets included', function () {
    // Only `allowHtml()` opts into stripping: a label that merely contains a
    // `<` is text, and mangling it would be the package inventing markup.
    $nodes = walk([
        Select::make('op')->options(['lt' => 'a < b', 'gt' => 'a > b']),
    ]);

    expect($nodes[0]['config']['options'])->toBe([
        ['value' => 'lt', 'label' => 'a < b'],
        ['value' => 'gt', 'label' => 'a > b'],
    ]);
});
