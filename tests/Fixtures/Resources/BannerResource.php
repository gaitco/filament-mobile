<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Cancel;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Table;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;
use Gait\FilamentMobile\Tests\Fixtures\Relations\TagsRelationManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
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
            ->defaultSort('created_at', 'desc')
            ->actions([
                'approve', 'archive', 'publish', 'reject', 'explode', 'halting',
                // Presentation getters that throw — ActionResolver::serialise()
                // must degrade the field, not drop the action or 500 the row.
                'throwing_label', 'throwing_confirmation',
                // failure() without halt, Cancel, and Htmlable presentation.
                'failing', 'cancelling', 'html_label',
                // Resolves nowhere — the doctor case.
                'ghost',
            ]);
    }

    /**
     * P6d Task 5: the golden snapshot's one populated relation, so the
     * client's relation parsing is exercised against real server output
     * rather than only `[]`. BelongsToMany, deliberately — every relation
     * fixture elsewhere in this suite is a HasMany.
     */
    public static function getRelations(): array
    {
        return [
            TagsRelationManager::class,
        ];
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

    public static function table(Table $table): Table
    {
        return $table->recordActions([
            Action::make('approve')
                ->label('Approve')
                ->color('success')
                ->icon('heroicon-o-check')
                ->successNotificationTitle('Banner approved')
                // The one action gated behind a real, policy-shaped check:
                // Banner otherwise carries no policy, and RunActionTest needs
                // a record the user may VIEW but may not RUN this action on
                // — the action's own isAuthorized() is a gate distinct from
                // the resource-level viewAny() the endpoint also checks.
                // Narrowest possible: denies only the fixture's 'viewer'
                // user, nobody else.
                ->authorize(fn () => auth()->user()?->name !== 'viewer')
                ->action(fn (Banner $record) => $record->update(['status' => 'approved'])),
            // Confirmation travels: the phone must reproduce the web
            // panel's "are you sure", with the action's own strings.
            Action::make('archive')
                ->label('Archive')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Archive this banner?')
                ->modalDescription('It will stop being served.')
                ->action(fn (Banner $record) => $record->update(['status' => 'archived'])),
            // Hidden for this record: must be absent from the payload AND
            // refused by the endpoint, not merely greyed out.
            Action::make('publish')
                ->visible(fn (Banner $record): bool => $record->status === 'draft')
                ->action(fn (Banner $record) => $record->update(['status' => 'active'])),
            // Carries a form: omitted from the wire, reported by doctor.
            Action::make('reject')
                ->schema([TextInput::make('reason')->required()])
                ->action(fn () => null),
            // A closure that throws — the endpoint must answer 500 rather
            // than report a success it cannot vouch for.
            Action::make('explode')
                ->action(fn () => throw new RuntimeException('boom')),
            // Halts deliberately: a 422 with the failure title.
            Action::make('halting')
                ->failureNotificationTitle('Cannot do that yet')
                ->action(fn () => throw new Halt()),
            // A cosmetic getter that throws. The action itself is fine —
            // authorized, visible, form-free — so it must stay RUNNABLE:
            // serialise() degrades `label` to the action's own name rather
            // than dropping the action or 500ing the whole record.
            Action::make('throwing_label')
                ->label(fn () => throw new RuntimeException('label boom'))
                ->action(fn () => null),
            // Reports failure WITHOUT halting: the closure returns normally
            // after `$action->failure()`. Filament's own web path switches on
            // getStatus() after the call and shows the FAILURE notification —
            // the mutation before it is real and stays. Mobile must answer
            // 422 with the failure title, not a 200 with the success one.
            Action::make('failing')
                ->failureNotificationTitle('Could not fail politely')
                ->action(function (Action $action, Banner $record): void {
                    $record->update(['status' => 'poked']);
                    $action->failure();
                }),
            // Cancels: the web panel catches Cancel and sends NO notification
            // at all — a graceful no-op, not a fault. Mobile answers 200 with
            // no message.
            Action::make('cancelling')
                ->action(fn () => throw new Cancel()),
            // Htmlable presentation: rendered HTML has no meaning on a phone,
            // so text() answers null — which must degrade the label to the
            // machine name (never a blank button) and the modal heading to
            // the generic prompt (never an empty heading).
            Action::make('html_label')
                ->label(new HtmlString('<b>Bold</b>'))
                ->requiresConfirmation()
                ->modalHeading(new HtmlString('<b>Sure?</b>'))
                ->action(fn () => null),
            // A safety-relevant getter that throws. Unlike the label above,
            // guessing "no confirmation" here would run a destructive action
            // with no prompt — serialise() must fail CLOSED: a non-null
            // confirmation block with the package's generic fallback.
            Action::make('throwing_confirmation')
                ->requiresConfirmation()
                ->modalHeading(fn () => throw new RuntimeException('heading boom'))
                ->action(fn () => null),
        ]);
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
                // Disabled relation select: submitted ids must neither attach
                // nor degrade into a clearing sync — fail closed, both ways.
                Select::make('gated_tag_ids')
                    ->multiple()
                    ->relationship('tags', 'name')
                    ->disabled(),
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
                // An upload nested inside a disabled container. Verified
                // empirically: Filament's own isDisabled() DOES propagate the
                // container's gate down to the child (it answers `true` here,
                // wired into a real Schema), so the resolver's own
                // isDisabled() check already refuses it directly — belt and
                // suspenders with WritableNames' independent descent through
                // RuleExtractor's LAYOUT_TYPES branch, the same one that
                // protects `locked_note` above. Never written, so no column.
                FileUpload::make('restricted_image'),
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
            // P6a: the slice's subject, a single-file upload the resolver
            // must admit as writable.
            FileUpload::make('hero_image')
                ->image()
                ->disk('public')
                ->maxSize(1024)
                ->acceptedFileTypes(['image/png', 'image/jpeg']),
            // Multiple stays refused this slice — published readOnly and
            // rejected by the endpoint, so nothing looks editable that is
            // not.
            FileUpload::make('gallery')->multiple(),
            // A disabled upload must refuse exactly like a disabled field
            // anywhere else in this package.
            FileUpload::make('locked_file')->disabled(),
            // A gate that cannot answer. UploadFieldResolver::resolve() must
            // refuse this rather than let the exception propagate — the same
            // fail-closed rule FieldPersistence applies everywhere else.
            FileUpload::make('exploding_gate')
                ->disabled(fn () => throw new RuntimeException('deliberately broken disabled gate')),
            // A field the resolver DOES admit (not disabled, not multiple),
            // but whose own type allow-list cannot answer. UploadController
            // must fail closed here too — see UploadFieldResolver::
            // constraintsFor()'s empty-from-a-throw handling.
            FileUpload::make('exploding_types')
                ->acceptedFileTypes(fn () => throw new RuntimeException('deliberately broken accepted-types gate')),
            // The other half of the same guard: `getMaxSize()` is read
            // through the same fail-closed path as `getAcceptedFileTypes()`
            // (SchemaWalker::config()), so a throwing size gate must lock
            // the field too, not just publish a missing `maxSize` hint on an
            // otherwise-offered control.
            FileUpload::make('exploding_max_size')
                ->maxSize(fn () => throw new RuntimeException('deliberately broken max-size gate')),
            // The one closure this feature keys WRITABILITY off, throwing.
            // All three readers must give the same CLOSED answer:
            // RuleExtractor withholds the rule (not in WritableNames, so
            // PUT can neither write nor clear the column), SchemaWalker
            // publishes readOnly: true, and UploadFieldResolver refuses the
            // upload. Before this fixture, a throwing multiple() failed
            // OPEN in the first two and closed only in the third — the wire
            // offered a control whose every upload 403'd.
            FileUpload::make('exploding_multiple')
                ->multiple(fn () => throw new RuntimeException('deliberately broken multiple gate')),
            // A storage getter that throws AFTER validation passed. The
            // endpoint must degrade to the same 422 a throwing constraint
            // closure gets, never a 500 — see UploadController's guarded
            // storeAs() inputs.
            FileUpload::make('exploding_disk')
                ->disk(fn () => throw new RuntimeException('deliberately broken disk gate')),
            // P6c: the slice's subject, a JSON-column repeater.
            Repeater::make('line_items')
                ->schema([
                    TextInput::make('sku')->required()->maxLength(20),
                    TextInput::make('qty')->numeric(),
                ])
                ->minItems(1)
                ->maxItems(5),
            // Add/delete gates off, so the client must not offer them.
            Repeater::make('fixed_rows')
                ->schema([TextInput::make('note')])
                ->addable(false)
                ->deletable(false),
            // A relationship repeater writes child rows — out of scope this
            // slice, so it must publish readOnly and be refused.
            Repeater::make('tag_rows')
                ->relationship('tags')
                ->schema([TextInput::make('name')]),
            // The one closure this field keys WRITABILITY off, throwing —
            // the repeater's counterpart to `exploding_multiple` above.
            // Read through read() it failed OPEN (a thrown getRelationship()
            // came back as the null fallback, i.e. "not a relationship"), so
            // the wire offered rows the write path can never save.
            Repeater::make('exploding_relationship')
                ->schema([TextInput::make('name')])
                ->relationship(fn () => throw new RuntimeException('deliberately broken relationship gate')),
            // A throwing config closure must degrade this one field, not the
            // /schema document.
            Repeater::make('exploding_repeater')
                ->schema([TextInput::make('x')])
                ->maxItems(fn () => throw new RuntimeException('boom')),
            // Task 3: a genuinely disabled repeater — distinct from
            // `fixed_rows` above, whose addable()/deletable() gates are off
            // but which is not itself disabled. Its submitted rows must be
            // silently dropped, not written, the same refusal every other
            // disabled field gets.
            Repeater::make('locked_rows')
                ->schema([TextInput::make('note')])
                ->disabled(),
            // P6c close-out: a child that would NOT round-trip refuses the
            // whole repeater. `Hidden::make('id')` inside a row is the
            // ordinary shape — an identifier the panel stamps and the client
            // never edits — and it is dropped by ComponentTypeMap::SKIPPED,
            // so no rule names it and Laravel's `validated()` rebuild deleted
            // it from every row on every save. Published read-only and given
            // no rule at all instead; the stored rows stay readable.
            Repeater::make('guarded_rows')
                ->schema([
                    TextInput::make('sku'),
                    Hidden::make('id'),
                ]),
            // The same refusal earned a different way. A relation-write child
            // gets no rule from RuleExtractor::fromComponents() — its value
            // reaches the database through saveRelationships(), not the
            // payload — and `->dehydrated(true)` overrides the literal
            // `dehydrated(false)` that `relationship()` sets, so Filament DOES
            // put `tags` in the row's stored state. No rule for a key that is
            // stored means `validated()` deletes it from every row on save.
            Repeater::make('relation_rows')
                ->schema([
                    TextInput::make('title'),
                    CheckboxList::make('tags')
                        ->relationship('tags', 'name')
                        ->dehydrated(true),
                ]),
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
            // P6e Task 4: the golden panel's `rich_entry` fixture. No
            // `->prose()` call here — Banner::setUpRichContent() already
            // registers `body_html` (Task 1), so this exercises the
            // MODEL-DECLARED half of SchemaWalker::isRich() against real
            // data, which is the same column the form above already edits
            // with RichEditor.
            TextEntry::make('body_html'),
        ]);
    }
}
