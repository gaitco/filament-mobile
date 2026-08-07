<?php

declare(strict_types=1);

use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Gait\FilamentMobile\Introspection\RelationDiscovery;
use Gait\FilamentMobile\Tests\Fixtures\Relations\BannersRelationManager;
use Gait\FilamentMobile\Tests\Fixtures\Relations\ExplodingBannersRelationManager;
use Gait\FilamentMobile\Tests\Fixtures\Relations\HiddenBannersRelationManager;
use Gait\FilamentMobile\Tests\Fixtures\Relations\NarrowedBannersRelationManager;
use Gait\FilamentMobile\Tests\Fixtures\Relations\Nested\NarrowedBannersRelationManager as NestedNarrowedBannersRelationManager;
use Gait\FilamentMobile\Tests\Fixtures\Resources\CompanyResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\GroupedCompanyResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\NarrowedCompanyResource;

it('discovers a plain relation manager with its columns', function () {
    $relations = RelationDiscovery::for(CompanyResource::class);

    expect($relations)->toHaveCount(1)
        ->and($relations[0]['key'])->toBe('banners')
        ->and($relations[0]['manager'])->toBe(BannersRelationManager::class)
        ->and(array_column($relations[0]['columns'], 'name'))->toBe(['name', 'status'])
        ->and(array_column($relations[0]['columns'], 'label'))->toBe(['Name', 'Status'])
        ->and(RelationDiscovery::refusalsFor(CompanyResource::class))->toBeEmpty();
});

it('labels a relation from its relationship name', function () {
    // The wire's `label`, and the only thing the phone shows above the list.
    expect(RelationDiscovery::for(CompanyResource::class)[0]['label'])->toBe('Banners');
});

it('prefers the title the manager declares over the humanised key', function () {
    // Proves labelFor()'s getTitle() branch actually fires. Measured first:
    // `getModel()` does not exist on a RelationManager, so the reflected
    // model instance an earlier draft built would have thrown on every
    // manager and left this branch permanently dead.
    expect(RelationDiscovery::for(TitledCompanyResource::class)[0]['label'])
        ->toBe('Active banners');
});

it('falls back to the humanised key when the title needs an owner record', function () {
    // The other half of the same branch, and the shapes that make a throwaway
    // record dangerous rather than merely useless. Eloquent's `__get` answers
    // NULL for an unset attribute and `getKey()` answers null on an unsaved
    // model — neither throws — so a manager reading the record directly used
    // to publish `Banners for ` and `Banners #`: a mangled heading presented
    // as the panel's own. Only the chained shape (`->company->name`) throws,
    // which is why one fixture made the guard look total.
    expect(RelationDiscovery::for(RecordTitledCompanyResource::class)[0]['label'])
        ->toBe('Banners')
        ->and(RelationDiscovery::for(AttributeTitledCompanyResource::class)[0]['label'])
        ->toBe('Banners')
        ->and(RelationDiscovery::for(KeyTitledCompanyResource::class)[0]['label'])
        ->toBe('Banners');
});

it('refuses a relation whose table narrows its own query', function () {
    // The probe: Table::getQuery() outside Livewire yields
    // `select * where "status" = ?` with a NULL model — the narrowing is
    // detectable but not reproducible, so publishing the relation would
    // list rows the web panel hides.
    $refusals = RelationDiscovery::refusalsFor(NarrowedCompanyResource::class);

    expect($refusals)->toHaveKey(NarrowedBannersRelationManager::class)
        ->and($refusals[NarrowedBannersRelationManager::class])->toContain('narrows its own query')
        ->and(RelationDiscovery::for(NarrowedCompanyResource::class))->toBeEmpty();
});

it('loses no refusal when two entries share a name', function () {
    // `doctor` is the only channel that tells a panel author why a relation
    // vanished, so a refusal that overwrites another is a relation that
    // disappears in silence. Keyed by class basename, the two managers below
    // collapsed into one entry, and so did the two groups.
    $sameBasename = RelationDiscovery::refusalsFor(TwinCompanyResource::class);
    $sameType = RelationDiscovery::refusalsFor(TwoGroupsCompanyResource::class);

    expect($sameBasename)->toHaveCount(2)
        ->and(array_keys($sameBasename))->toBe([
            NarrowedBannersRelationManager::class,
            NestedNarrowedBannersRelationManager::class,
        ])
        ->and($sameType)->toHaveCount(2)
        ->and(array_keys($sameType))->toBe([
            RelationGroup::class,
            RelationGroup::class . ' #2',
        ]);
});

it('refuses a getRelations() entry that is not a plain RelationManager', function () {
    // RelationGroup and RelationManagerConfiguration are both legal entries
    // and neither is handled this slice.
    $refusals = RelationDiscovery::refusalsFor(GroupedCompanyResource::class);

    expect($refusals)->not->toBeEmpty()
        ->and(implode(' ', $refusals))->toContain('not a plain RelationManager');
});

it('refuses a relation whose table cannot be built rather than propagating', function () {
    // A relation that throws while declaring its own table degrades itself,
    // never the request — the fail-closed rule every other accessor in this
    // package follows.
    $refusals = RelationDiscovery::refusalsFor(ExplodingTableCompanyResource::class);

    expect(implode(' ', $refusals))->toContain('table could not be built')
        ->and(implode(' ', $refusals))->toContain('deliberately broken relation table')
        ->and(RelationDiscovery::for(ExplodingTableCompanyResource::class))->toBeEmpty();
});

it('degrades a throwing column label to the column name, keeping the relation', function () {
    // A label is not a gate: a throwing label closure means this package
    // cannot NAME the column, not that the rows must be withheld. Same call
    // `Actions/ActionResolver::label()` already made for actions — refusing
    // the whole relation over one cosmetic closure is a blast radius far
    // larger than the fault, and only `doctor` would ever say so.
    $relations = RelationDiscovery::for(UnlabelledCompanyResource::class);

    expect($relations)->toHaveCount(1)
        ->and($relations[0]['columns'])->toBe([
            ['name' => 'name', 'label' => 'name'],
            ['name' => 'status', 'label' => 'Status'],
        ])
        ->and(RelationDiscovery::refusalsFor(UnlabelledCompanyResource::class))->toBeEmpty();
});

it('degrades a resource whose getRelations() cannot be read to no relations', function () {
    expect(RelationDiscovery::for(BrokenRelationsCompanyResource::class))->toBe([])
        ->and(RelationDiscovery::refusalsFor(BrokenRelationsCompanyResource::class))->toBe([]);
});

it('publishes a relation whose per-record view gate denies or throws', function () {
    // Discovery is not that gate. `canViewForRecord()` is per-record and
    // needs an owner, so it belongs to the request path — a relation hidden
    // for one record is still a relation this package can serve. Pinning the
    // boundary here stops a later slice from quietly moving the gate into
    // discovery, where there is no record to ask about.
    expect(RelationDiscovery::for(GatedCompanyResource::class))->toHaveCount(2)
        ->and(RelationDiscovery::refusalsFor(GatedCompanyResource::class))->toBeEmpty();
});

it('still finds the Table property the narrowing refusal reflects on', function () {
    // THE tripwire for this slice. `Table::$queryScopes` is protected,
    // undocumented, and the ONLY signal separating a narrowed relation from a
    // plain one — and this package supports `filament/filament: ^4.0|^5.0`.
    // If a future major renames it, a purely behavioural test would pass
    // happily while the refusal silently stopped firing.
    //
    // So assert the property itself, not the behaviour it drives. This test
    // failing means: read the new Filament, find what replaced `queryScopes`,
    // and fix HeadlessTableHost::relationTableFor(). Do not delete this test.
    expect(property_exists(Table::class, 'queryScopes'))->toBeTrue(
        'Filament\Tables\Table::$queryScopes is gone or renamed. The narrowing '
        . 'refusal in HeadlessTableHost::relationTableFor() reads it by '
        . 'reflection; without it the refusal has no signal at all.',
    );
});

class TitledBannersRelationManager extends BannersRelationManager
{
    protected static ?string $title = 'Active banners';
}

class RecordTitledBannersRelationManager extends BannersRelationManager
{
    public static function getTitle(Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return 'Banners for ' . $ownerRecord->company->name;
    }
}

class TitledCompanyResource extends Filament\Resources\Resource
{
    public static function getRelations(): array
    {
        return [TitledBannersRelationManager::class];
    }
}

class RecordTitledCompanyResource extends Filament\Resources\Resource
{
    public static function getRelations(): array
    {
        return [RecordTitledBannersRelationManager::class];
    }
}

class AttributeTitledBannersRelationManager extends BannersRelationManager
{
    public static function getTitle(Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        // Eloquent answers null rather than throwing, so this shape published
        // `Banners for ` until labelFor() stopped calling overridden titles.
        return 'Banners for ' . $ownerRecord->name;
    }
}

class KeyTitledBannersRelationManager extends BannersRelationManager
{
    public static function getTitle(Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return 'Banners #' . $ownerRecord->getKey();
    }
}

class AttributeTitledCompanyResource extends Filament\Resources\Resource
{
    public static function getRelations(): array
    {
        return [AttributeTitledBannersRelationManager::class];
    }
}

class KeyTitledCompanyResource extends Filament\Resources\Resource
{
    public static function getRelations(): array
    {
        return [KeyTitledBannersRelationManager::class];
    }
}

class UnlabelledBannersRelationManager extends BannersRelationManager
{
    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(fn () => throw new RuntimeException('label exploded')),
            TextColumn::make('status'),
        ]);
    }
}

class UnlabelledCompanyResource extends Filament\Resources\Resource
{
    public static function getRelations(): array
    {
        return [UnlabelledBannersRelationManager::class];
    }
}

class TwinCompanyResource extends Filament\Resources\Resource
{
    public static function getRelations(): array
    {
        return [
            NarrowedBannersRelationManager::class,
            NestedNarrowedBannersRelationManager::class,
        ];
    }
}

class TwoGroupsCompanyResource extends Filament\Resources\Resource
{
    public static function getRelations(): array
    {
        return [
            RelationGroup::make('Marketing', [BannersRelationManager::class]),
            RelationGroup::make('Archive', [BannersRelationManager::class]),
        ];
    }
}

class ExplodingTableRelationManager extends Filament\Resources\RelationManagers\RelationManager
{
    protected static string $relationship = 'banners';

    public function table(Table $table): Table
    {
        throw new RuntimeException('deliberately broken relation table');
    }
}

class ExplodingTableCompanyResource extends Filament\Resources\Resource
{
    public static function getRelations(): array
    {
        return [ExplodingTableRelationManager::class];
    }
}

class BrokenRelationsCompanyResource extends Filament\Resources\Resource
{
    public static function getRelations(): array
    {
        throw new RuntimeException('deliberately broken getRelations accessor');
    }
}

class GatedCompanyResource extends Filament\Resources\Resource
{
    public static function getRelations(): array
    {
        return [
            HiddenBannersRelationManager::class,
            ExplodingBannersRelationManager::class,
        ];
    }
}
