<?php

declare(strict_types=1);

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Gait\FilamentMobile\Introspection\SchemaWalker;
use Gait\MobileCore\WalkWarnings;

function walkTree(array $components): array
{
    return (new SchemaWalker(new WalkWarnings()))->walk($components, 'TestResource');
}

it('recurses into a section and emits children', function () {
    $nodes = walkTree([
        Section::make()->label('البيانات')->schema([
            TextInput::make('name'),
            Toggle::make('is_active'),
        ]),
    ]);

    expect($nodes[0]['type'])->toBe('section')
        ->and($nodes[0]['name'])->toBeNull()
        ->and($nodes[0]['children'])->toHaveCount(2)
        ->and($nodes[0]['children'][0]['name'])->toBe('name')
        ->and($nodes[0]['children'][1]['type'])->toBe('toggle');
});

it('labels a section from its heading, which is where Section::make() puts it', function () {
    expect(walkTree([Section::make('Details')])[0]['label'])->toBe('Details');
});

it('recurses to arbitrary depth through every layout type', function () {
    $nodes = walkTree([
        Section::make()->schema([
            Grid::make()->schema([
                Tabs::make()->schema([
                    Fieldset::make()->schema([
                        TextInput::make('deep'),
                    ]),
                ]),
            ]),
        ]),
    ]);

    expect($nodes[0]['children'][0]['children'][0]['children'][0]['children'][0]['name'])->toBe('deep');
});

it('emits an empty children list for an empty container', function () {
    expect(walkTree([Section::make()])[0]['children'])->toBe([]);
});

it('records a warning for an unsupported child without losing its siblings', function () {
    $warnings = new WalkWarnings();
    $unsupported = new class {
        public function getName(): string
        {
            return 'weird';
        }
    };

    $nodes = (new SchemaWalker($warnings))->walk(
        [Section::make()->schema([TextInput::make('kept'), $unsupported])],
        'TestResource',
    );

    expect($nodes[0]['children'])->toHaveCount(1)
        ->and($nodes[0]['children'][0]['name'])->toBe('kept')
        ->and($warnings->all())->toHaveCount(1);
});

it('drops a non-component child (a bare string) with a warning, keeping its siblings', function () {
    $warnings = new WalkWarnings();

    $nodes = (new SchemaWalker($warnings))->walk(
        [Section::make()->schema(['Some text', TextInput::make('kept')])],
        'TestResource',
    );

    expect($nodes[0]['children'])->toHaveCount(1)
        ->and($nodes[0]['children'][0]['name'])->toBe('kept')
        ->and($warnings->all())->toHaveCount(1);
});

it('flattens a grouped select rather than 500ing the whole document', function () {
    // `(string) $label` on the nested array raised "Array to string
    // conversion" — an uncaught ErrorException that took /schema down whole.
    $nodes = walkTree([
        Select::make('city')->options([
            'Egypt' => ['cai' => 'Cairo', 'alx' => 'Alexandria'],
            'ksa' => 'Riyadh',
        ]),
    ]);

    expect($nodes[0]['config']['options'])->toBe([
        ['value' => 'cai', 'label' => 'Cairo'],
        ['value' => 'alx', 'label' => 'Alexandria'],
        ['value' => 'ksa', 'label' => 'Riyadh'],
    ]);
});

it('drops a component whose node cannot be built, keeping its siblings', function () {
    // The structural half of the same finding: SafeEvaluator guards each
    // accessor and PanelSchemaBuilder guards construction, but nothing guarded
    // the walker's own value handling in between. getOptions() returns fine
    // here; building the node from it is what throws.
    $warnings = new WalkWarnings();

    $nodes = (new SchemaWalker($warnings))->walk(
        [Select::make('broken')->options(['a' => new stdClass()]), TextInput::make('kept')],
        'TestResource',
    );

    expect($nodes)->toHaveCount(1)
        ->and($nodes[0]['name'])->toBe('kept')
        ->and($warnings->all())->toHaveCount(1)
        ->and($warnings->all()[0]['component'])->toBe('broken')
        ->and($warnings->all()[0]['reason'])->toContain('could not be converted to a contract node');
});

it('emits no colours config for a badge entry (out of scope for P1)', function () {
    $nodes = walkTree([
        TextEntry::make('status')->badge()->colors(['active' => 'success']),
    ]);

    expect($nodes[0]['type'])->toBe('badge_entry')
        ->and($nodes[0])->not->toHaveKey('config');
});

it('emits icons and colours for a boolean icon entry from the public API', function () {
    $nodes = walkTree([
        IconEntry::make('is_active')
            ->trueIcon('heroicon-o-check')
            ->falseIcon('heroicon-o-x-mark')
            ->trueColor('success')
            ->falseColor('danger'),
    ]);

    expect($nodes[0]['type'])->toBe('boolean_entry')
        ->and($nodes[0]['config']['icons'])->toBe(['true' => 'heroicon-o-check', 'false' => 'heroicon-o-x-mark'])
        ->and($nodes[0]['config']['colors'])->toBe(['true' => 'success', 'false' => 'danger']);
});

it('emits no config for a non-boolean icon entry', function () {
    $nodes = walkTree([IconEntry::make('status')]);

    expect($nodes[0]['type'])->toBe('boolean_entry')
        ->and($nodes[0])->not->toHaveKey('config');
});
