<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use UnitEnum;

class BannerResource extends Resource
{
    protected static ?string $model = Banner::class;

    // A plain string group — the ordinary case. See TagResource for the enum
    // one and BrokenGroupResource for the throwing one.
    protected static string | UnitEnum | null $navigationGroup = 'Content';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card
                ->title('name')
                ->subtitle('company.name')
                ->badge('status', ['active' => 'success', 'draft' => 'warning'])
                ->meta('created_at', format: 'date'))
            ->searchable(['name'])
            ->sorts(['created_at' => 'Newest', 'name' => 'Name'])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * A deliberately non-default scope: Banner does NOT use the SoftDeletes
     * trait, so this filter exists only here. A list endpoint that queried the
     * model directly instead of the resource would return the deleted row and
     * fail the test — which is the point of the fixture.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNull('deleted_at');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Details')->schema([
                // The label is deliberately NOT the humanised column name.
                // Every other fixture leaves the label implicit, so
                // `getValidationAttribute()` ("numeric email") and Laravel's
                // own default (":attribute" from `numeric_email`) agree by
                // accident — and the whole published-message-versus-422
                // question stays invisible to this suite. The pilot found
                // 137 of 187 real constrained fields disagreeing for exactly
                // this reason. See MessageAttributeTest.
                TextInput::make('name')->label('Display Title')->required()->maxLength(80),
                // A plain value bound, and a value bound on a field that
                // renders as another type: refineType() checks isEmail()
                // before isNumeric(), so this types as `email`, and the
                // published `numeric` rule is what keeps the client from
                // measuring a length where Laravel compares a value.
                TextInput::make('quantity')->numeric(),
                TextInput::make('numeric_email')->email()->numeric(),
                // The combination no other fixture exercises: numeric() AND
                // maxLength() together, which is what forces the
                // messages['max'] resolution through validation.max.numeric
                // ("must not be greater than 80") rather than
                // validation.max.string ("...80 characters"). Without this,
                // that branch is only proven by an ad-hoc probe, not a test.
                TextInput::make('bounded_quantity')->numeric()->maxLength(80),
                // The blocker: RichEditor is unmapped, so a NOT NULL rich-text
                // column had no rule and no validated key, and the insert
                // failed with MySQL 1364. Read-only would not clear it — only
                // a writable mapping produces a `required` rule for this
                // field. See ComponentTypeMap::MAP.
                RichEditor::make('body_html')->required(),
                // The only server rule the wire never carried. RuleExtractor
                // has always emitted `email`; this is what proves it now
                // reaches the published `rules` block.
                TextInput::make('contact_email')->email(),
                // Three options, not two: SchemaEndpointTest lowers
                // options_inline_max to 2 to prove the cap trigger fires on
                // its own, independent of searchable() — two options would
                // never exceed a cap of two.
                Select::make('status')->options([
                    'draft' => 'Draft',
                    'active' => 'Active',
                    'archived' => 'Archived',
                ])->default('draft'),
                // A throwing default must degrade to no `default` key rather
                // than a 500 for the whole /schema document — every closure
                // the walker evaluates goes through SafeEvaluator.
                TextInput::make('throwing_default')->default(
                    fn () => throw new RuntimeException('nope'),
                ),
                // A closure that throws, which /state must degrade to a warning
                // rather than a 500. Nested inside the section on purpose: a
                // top-level one is evaluated by `getComponents()`'s own hidden
                // filter during *construction*, which is outside SafeEvaluator's
                // reach and would drop the whole form from /schema.
                TextInput::make('trap')->visible(
                    fn () => throw new RuntimeException('deliberately broken closure'),
                ),
                // NESTED, not top-level, and that placement is the test. A
                // relationship select resolves its options through the schema's
                // model instance, which it reaches through its container — and
                // a component the walker read out of `getDefaultChildComponents()`
                // has no container. Every such field published `options: []`,
                // silently, so a required foreign key arrived at the phone as an
                // empty picker. The write pilot found it on 6 of 33 resources.
                Select::make('company_id')
                    ->relationship('company', 'name'),
                // Searchable, so config() must publish an `optionsUrl` and
                // withhold `options` regardless of how few rows `company`
                // has — the panel author already said the list is too long
                // to scroll, independent of the inline cap. See
                // SchemaEndpointTest.
                Select::make('searchable_company_id')
                    ->relationship('company', 'name')
                    ->searchable(),
                // The security fixture for the options endpoint, and the shape
                // the write pilot measured on the real panel: a select whose
                // option QUERY reads a sibling. `kind` is a Hidden — never
                // written, so never trusted — so a crafted `kind` must not
                // widen the list. Settled state resets it to its default, and
                // the endpoint's answer must follow the settled value, not the
                // submitted one. See OptionsEndpointTest.
                Select::make('scoped_company_id')
                    ->relationship(
                        'company',
                        'name',
                        fn (Builder $query, Get $get): Builder => $query->where(
                            'name',
                            'like',
                            ($get('kind') ?? null) === 'unlock' ? '%' : 'NOMATCH%',
                        ),
                    )
                    ->searchable(),
                // The write path never calls saveRelationships(), so this
                // returns 201/200 having attached nothing. Publishing it
                // editable invites a user to fill a control whose contents
                // are silently discarded — see FieldPersistence.
                Select::make('tag_ids')
                    ->multiple()
                    ->relationship('tags', 'name'),
                // The control group for the field above: multiplicity alone
                // is not the refusal, the RELATIONSHIP is. A plain multiple
                // select over static options writes fine.
                //
                // Its default is a LIST, and that is the second half of the
                // fixture: array_replace_recursive() merges lists by index, so
                // a submitted `['c']` over this default stored `['c','b','c']`
                // — the user's own choice silently corrupted. See
                // MobilePanelController::fillMissingPaths().
                Select::make('plain_multi')
                    ->multiple()
                    ->options(['a' => 'A', 'b' => 'B', 'c' => 'C'])
                    ->default(['a', 'b', 'c']),
                // CheckboxList has no isMultiple() at all — it is inherently
                // multi-valued — so isMultiValuedRelationship() names it by
                // class rather than inferring it from an absent method. This
                // is the one real, wired case that exercises that branch.
                //
                // `->dehydrated()` is not decorative: CheckboxList::relationship()
                // ALSO calls `dehydrated(false)` unconditionally (same as
                // Section's), so without this override the pre-existing
                // dehydrated-literal check would lock this field on its own —
                // double-covered exactly like Section, proving nothing about
                // the new class-name branch. Overriding it back to `true`
                // (evaluated last, so it wins) means only
                // isMultiValuedRelationship() can be the reason this is locked.
                CheckboxList::make('checkbox_tag_ids')
                    ->relationship('tags', 'name')
                    ->dehydrated(),
                // Never published (the walker skips Hidden by design), so only
                // the server can supply it — which is exactly why its default
                // has to survive to create(). A resource stamping its own rows
                // with `Hidden::make('type')->default(...)` is the ordinary
                // shape, and losing it writes a row outside the resource's own
                // query: created 201, then 404 forever.
                Hidden::make('kind')->default('promo'),
                // A dotted field name is a PATH — `title.ar` is how every
                // translatable Filament field is named, and the validator,
                // the rule extractor and Eloquent all read it as one.
                // FormDefaults wrote it as a literal key, so the create
                // mass-assigned a column named `caption.ar`.
                //
                // The `ar` half is Hidden with a default the client can never
                // send; the `en` half is an ordinary published field. They
                // meet under one `caption` key, which is where a spread merge
                // drops the default and only a recursive one keeps it. The
                // published half is also this panel's first dotted field
                // name — precisely the shape whose absence hid the pilot's
                // 422-on-every-translatable-resource bug.
                Hidden::make('caption.ar')->default('AR default'),
                TextInput::make('caption.en'),
                // The collision spec §9 records on the serializer, on the WRITE
                // side: a scalar field whose name is the first segment of a
                // dotted one. `data_set` would overwrite what the user typed
                // here with `['ar' => 'AR default']` — the default beating the
                // input. The panel that owns both shapes still gets only one of
                // them; this fixture pins WHICH one.
                TextInput::make('caption'),
                // NESTED and state-dependent, which is the combination that
                // broke. A closure taking `$state` can only be evaluated by a
                // component wired into its schema; read out of the raw stored
                // children it throws, the guard fails open, and the field is
                // written anyway. On the pilot panel that field was a user's
                // password, and `PUT {"password": ""}` blanked the hash.
                TextInput::make('secret_note')
                    ->dehydrated(fn (?string $state): bool => filled($state)),
                // `disabledOn('edit')` is THE idiomatic immutable-after-create
                // gate — slug, sku, email, type. It compiles to
                // `disabled(fn ($livewire, string $operation) => ...)`, and
                // `Schema::getOperation()` falls through to
                // `getLivewire()::class` when the schema never set one, so it
                // matched nothing and every operation gate in every panel
                // evaluated false.
                TextInput::make('bypass_note')->disabledOn('edit'),
                // The `hidden` half of the same family, and it is pointed at
                // the *defaults* path: hidden implies not dehydrated, so on
                // create this default must not reach the row.
                TextInput::make('op_note')->hiddenOn('create')->default('leak'),
                // Record-dependent, nullable hint. Without `Schema::record()`
                // on the update path this answered null — i.e. as a create —
                // on every PUT, leaving the gate open for the whole life of
                // the record it was written to protect.
                TextInput::make('rec_note')
                    ->disabled(fn (?Banner $record): bool => $record !== null),
                // Non-nullable hint: the *stricter* signature was the more
                // exposed one. With no record it TypeErrors, and the guard used
                // to read a throw as "no opinion" and admit the field. It must
                // refuse instead — while still writing normally on edit, where
                // the record resolves and the closure genuinely says yes.
                TextInput::make('strict_note')
                    ->dehydrated(fn (Banner $record): bool => true),
                // Hidden in its own right rather than by operation, and NESTED,
                // which is what makes it a real case: `store()` reads
                // `getComponents()` and that filters hidden components at the
                // top level only, so a top-level one never reached the defaults
                // extractor and proved nothing. Below the top level it does.
                Hidden::make('hidden_note')->hidden()->default('leak'),
                // An ordinary gate that raises an exception carrying submitted
                // input verbatim: ModelNotFoundException appends the ids it
                // looked for. That is what let a client steer the guard's own
                // error handling — see GateBypassTest and FieldPersistence.
                // `kind` is a Hidden field, so it is never written, but its
                // submitted value still seeds the state this closure reads.
                TextInput::make('probe_note')
                    ->disabled(fn (Get $get): bool => Banner::findOrFail($get('kind'))->exists),
                // Steered by `kind`, which is a Hidden and therefore NEVER
                // written — that is what makes a crafted value an escalation
                // rather than an ordinary edit. Gating on a WRITABLE field would
                // be wrong: a client is entitled to set a writable field, and
                // Filament's own UI opens the gate when it does. See spec §2.0.
                TextInput::make('gate_note')
                    ->disabled(fn (Get $get): bool => ($get('kind') ?? null) !== 'unlock'),
                // The same gate, reached on the create path, where there is no
                // record and the only trusted state is the form's own defaults.
                // `kind` already exists above as Hidden::make('kind')
                // ->default('promo') — both gates read that one field.
                TextInput::make('sib_note')
                    ->disabled(fn (Get $get): bool => ($get('kind') ?? null) !== 'unlock'),
                // The two-hop probe. `trusted` on create is the form's own
                // defaults, and reset() seeds EVERY pass's state from it —
                // so a default that lands in `trusted` is a permanent state
                // floor the final build reads, and row defaults come off that
                // build. Deriving `trusted` from a schema built with the
                // SUBMITTED state therefore let the payload plant one.
                //
                // One hop cannot show it: the first default's own component is
                // hidden by the settled state, so it never reaches the row.
                // Two hops do — `kind` opens `lever`, `lever`'s planted default
                // survives into `trusted`, and `victim_note` reads `lever` on
                // every subsequent pass. `POST {"kind":"unlock"}` wrote
                // `victim_note` in a column the panel would never have written.
                //
                // Building `trusted` from an EMPTY state closes it, which is
                // also what Filament's own CreateRecord does.
                Hidden::make('lever')->default('lev')
                    ->visible(fn (Get $get): bool => $get('kind') === 'unlock'),
                Hidden::make('victim_note')->default('LEAKED')
                    ->visible(fn (Get $get): bool => $get('lever') === 'lev'),
            ]),
            // The permission-gate shape a real panel uses: a whole section
            // switched off for users who may edit the record but not this
            // column. `disabled` is inherited by every field inside, so both
            // the rule extractor and the defaults extractor have to refuse the
            // container, not just the leaf.
            Section::make('Restricted')->disabled()->schema([
                TextInput::make('locked_note'),
                // A default *inside* a gated section. Filament fills defaults
                // into state and then dehydrates, so this never reaches the
                // row; the first version of FormDefaults wrote it.
                Hidden::make('gated_note')->default('leak'),
            ]),
            // The flat half of the same guard: `dehydrated(false)` needs no
            // state, so it is readable even detached. `Select::relationship()`
            // is this shape.
            TextInput::make('relation_note')->dehydrated(false),
            // A container entangled with a SINGULAR relationship (BelongsTo,
            // via EntanglesStateWithSingularRelationship) — the shape review
            // asked for a guard rail against, since it has no isMultiple()
            // either and the naive "no isMultiple() means assume multiple"
            // fallback locked it as if it were the multi-valued case.
            //
            // It stays locked anyway, for an unrelated reason: relationship()
            // on this trait unconditionally calls dehydrated(false) — its own
            // value is never part of getState(), because saving happens
            // through saveRelationshipsBeforeChildrenUsing(), which only
            // Filament's own saveRelationships() invokes. This package's
            // write path never calls it, so nested edits here (`name`, below)
            // are exactly as discarded as a multi-valued relationship's are.
            // See FieldPersistenceContainerTest for the isolated case that
            // actually exercises the fixed discrimination.
            Section::make('Company')
                ->relationship('company')
                ->schema([
                    TextInput::make('name'),
                ]),
            // The only `file` field in the fixtures. It is what puts the type
            // into the contract snapshot (so the Dart parser is proven to
            // accept it) and what gives FileFieldTest a real column to protect.
            FileUpload::make('avatar'),
            // The reactive pair /state exists for: `city_id` is visible only
            // when `country_id` is 3, which is answerable only against
            // submitted state.
            Select::make('country_id')->options([3 => 'Egypt', 99 => 'Elsewhere']),
            Select::make('city_id')
                ->options(['cai' => 'Cairo'])
                ->visible(fn (Get $get) => $get('country_id') === 3),
            // Keyed off a real column, unlike the pair above: this is what
            // proves /state resolves an untouched value from the record rather
            // than only from the payload.
            TextInput::make('active_note')
                ->visible(fn (Get $get) => $get('status') === 'active'),
            // Keyed off a CAST column. `options` is a JSON string in
            // getAttributes() and an array in attributesToArray(), so this
            // closure is false under the former and true under the latter —
            // which is what pins the form to Filament's own cast-value
            // semantics instead of raw database values.
            TextInput::make('featured_note')
                ->visible(fn (Get $get) => is_array($get('options'))
                    && ($get('options')['featured'] ?? false) === true),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name'),
            TextEntry::make('status')->badge(),
            TextEntry::make('company.name'),
            // Infolist-only: neither the card nor the form names it, so it is
            // the one field that can only reach the payload through
            // infolistPaths() — the discriminator for a merge that silently
            // became a replacement.
            TextEntry::make('infolist_only_note'),
        ]);
    }
}
