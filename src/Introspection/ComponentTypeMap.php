<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Introspection;

/**
 * Filament component class => contract `type` string.
 *
 * Matching is by class name, not `instanceof`, so a plugin component such as
 * SpatieMediaLibraryImageEntry maps without this package depending on the
 * plugin. The map was measured against a real panel: see the plan's component
 * type table for the usage counts that justified each entry.
 */
final class ComponentTypeMap
{
    /** @var array<string, string> fully-qualified class => contract type */
    private const MAP = [
        // Fields
        'Filament\\Forms\\Components\\TextInput' => 'text',
        'Filament\\Forms\\Components\\Textarea' => 'textarea',
        'Filament\\Forms\\Components\\Select' => 'select',
        // Same `Concerns\HasOptions` trait and the same `getOptions()` as
        // Select (measured in vendor), so the walker's existing option reader
        // and flatOptions() apply unchanged — only the rendering differs.
        'Filament\\Forms\\Components\\Radio' => 'radio',
        // Straight to `multiselect`, not to `select` refined by isMultiple():
        // a CheckboxList is always multi-valued and has no isMultiple(). It
        // carries getOptions() like Select does, so config() populates its
        // options with no extra handling. The pilot found 38 uses — 39% of
        // every warning that panel produced, and the single largest gap.
        'Filament\\Forms\\Components\\CheckboxList' => 'multiselect',
        // The only component in this map that implements
        // `Contracts\HasNestedRecursiveValidationRules` (measured in vendor),
        // which is what makes its entry more than a config mapping — see
        // RuleExtractor's `{$name}.*` branch. Its value is a `List<String>`
        // on the wire in every case; a configured `separator` changes only
        // what Filament stores, never what the client sends.
        'Filament\\Forms\\Components\\TagsInput' => 'tags',
        // DANGER, measured in vendor: the setters are `editableKeys()` /
        // `editableValues()`, but the getters are NOT named after them —
        // they are `canEditKeys()` / `canEditValues()`, alongside
        // `isAddable()` / `isDeletable()`. Reading the setter name through
        // the walker's guarded reader returns null, which read() converts
        // to its fallback of `true` — so a locked field would publish as
        // editable, with no error anywhere. See SchemaWalker::config()'s
        // `keyvalue` branch, which reads the real names.
        'Filament\\Forms\\Components\\KeyValue' => 'keyvalue',
        'Filament\\Forms\\Components\\Toggle' => 'toggle',
        'Filament\\Forms\\Components\\Checkbox' => 'checkbox',
        'Filament\\Forms\\Components\\DatePicker' => 'date',
        'Filament\\Forms\\Components\\DateTimePicker' => 'datetime',
        // Its own type, not `datetime`: matching here is by exact class name,
        // so a TimePicker would otherwise fall through unmapped and be
        // dropped with a warning — and a dropped field gets no validation
        // rule, so a NOT NULL time column fails at the database instead. The
        // class is five lines in vendor (`extends DateTimePicker`, overriding
        // only `hasDate(): false`), so it inherits getMinDate()/getMaxDate()/
        // hasSeconds() unchanged — which is why SchemaWalker::config()'s date
        // branch is widened to `time` rather than copied.
        'Filament\\Forms\\Components\\TimePicker' => 'time',
        // Its only accessor is getFormat() (measured in vendor/filament/
        // forms/src/Components/ColorPicker.php) — 'hex' by default, or
        // 'hsl'/'rgb'/'rgba' via the matching ->hsl()/->rgb()/->rgba()
        // helper. The wire *value* is a plain string in that declared
        // format and travels unmodified through the ordinary default/state
        // paths every other field uses; only the format name is config()'s
        // concern (SchemaWalker's `color` branch), and it is a closed set —
        // anything else normalises to 'hex', the same rule direction()
        // applies to a bogus `filament-panels::layout.direction`.
        'Filament\\Forms\\Components\\ColorPicker' => 'color',
        // Single-file upload shipped in P6a: a plain or Spatie-backed upload
        // field maps to `file` and is editable through the separate upload
        // endpoint. Only the MULTIPLE shape stays `config.readOnly: true`
        // (the walker's `file` branch) — deferred, and reported by doctor.
        'Filament\\Forms\\Components\\FileUpload' => 'file',
        'Filament\\Forms\\Components\\SpatieMediaLibraryFileUpload' => 'file',
        // Raw HTML, edited as text. Reading shipped in P6e (the record payload
        // carries a converted ProseMirror document beside the raw value); a
        // real WYSIWYG editor needs a Flutter dependency the client does not
        // take, so the FORM field stays a textarea. Honest rather than good.
        'Filament\\Forms\\Components\\RichEditor' => 'textarea',
        // A JSON-column repeater: its item template is published once as
        // `children` (see SchemaWalker::childrenOf() via ChildComponents,
        // which already reaches getChildSchema()), and its value round-trips
        // as an ordinary array through the unmodified write path. Deliberately
        // NOT in LAYOUT_TYPES — see that constant's docblock. A relationship
        // repeater (`->relationship()`) maps to the same type but writes its
        // rows as child records through Filament's own saveRelationships(),
        // which the controller's relation pass calls (P9 — see
        // FieldPersistence::savesViaRelationship()'s Repeater branch): the
        // walker publishes it editable, and RuleExtractor mints it as a
        // relation-write leaf with no rule, so the rows never touch a column.
        'Filament\\Forms\\Components\\Repeater' => 'repeater',

        // Layout
        'Filament\\Schemas\\Components\\Section' => 'section',
        'Filament\\Schemas\\Components\\Grid' => 'grid',
        'Filament\\Schemas\\Components\\Tabs' => 'tabs',
        'Filament\\Schemas\\Components\\Fieldset' => 'fieldset',
        // A Tab is a labelled child container like any other — same HasLabel
        // and child-component traits as Fieldset — so it needs no contract type
        // of its own. It maps to `section` deliberately: tabs are a poor
        // control on a phone, so a tabbed desktop form is flattened into
        // stacked labelled groups, which is the right mobile treatment anyway.
        // Unmapped, every tab's contents would be dropped with a warning.
        'Filament\\Schemas\\Components\\Tabs\\Tab' => 'section',

        // Infolist entries
        'Filament\\Infolists\\Components\\TextEntry' => 'text_entry',
        // `boolean_entry`, not an `icon_entry` of its own: the contract
        // vocabulary is frozen (design spec §5.3) and has no `icon_entry`, so
        // the Dart parser turned every one into an UnknownComponent — a debug
        // placeholder that is skipped entirely in release builds. An IconEntry
        // is a boolean-shaped display in all but the rarest panel, and
        // `boolean_entry` is the type the client already renders.
        'Filament\\Infolists\\Components\\IconEntry' => 'boolean_entry',
        'Filament\\Infolists\\Components\\ImageEntry' => 'image_entry',
        'Filament\\Infolists\\Components\\SpatieMediaLibraryImageEntry' => 'image_entry',
    ];

    /**
     * Components that are deliberately dropped with no warning. A hidden field
     * is intentionally not on screen, so its absence from the mobile schema is
     * correct rather than a gap worth reporting.
     *
     * @var list<string>
     */
    private const SKIPPED = [
        'Filament\\Forms\\Components\\Hidden',
    ];

    /**
     * Contract types that contain children rather than a value. Shared by
     * SchemaWalker (which recurses into these when building the schema
     * document) and RuleExtractor (which must recurse into exactly the same
     * set when building the rules array) — one list, so the two can't drift
     * apart on which components are containers.
     *
     * @var list<string>
     */
    public const LAYOUT_TYPES = ['section', 'grid', 'tabs', 'fieldset'];

    public static function for(object $component): ?string
    {
        return self::forClassName($component::class);
    }

    public static function forClassName(string $class): ?string
    {
        return self::hostTypes()[$class] ?? self::MAP[$class] ?? null;
    }

    /**
     * The host's own mappings, consulted before the built-in map.
     *
     * A real panel uses components this package has never heard of — the
     * pilot found `Ysfkaya\FilamentPhoneInput` and `Guava\IconPicker`, and any
     * panel grows more. Hard-coding them would make this package track class
     * names in repositories it has no dependency on and cannot version-check,
     * and the next panel with a different phone-input package would be blocked
     * again with no recourse but a pull request.
     *
     * Host entries win, so a panel can also override a built-in it disagrees
     * with. A value that is not a type this contract defines is ignored: a typo
     * must not put an unrenderable type on the wire.
     *
     * @return array<string, string>
     */
    private static function hostTypes(): array
    {
        $declared = config('filament-mobile.types', []);

        if (! is_array($declared)) {
            return [];
        }

        $vocabulary = self::types();

        return array_filter(
            $declared,
            static fn (mixed $type, mixed $class): bool => is_string($class)
                && is_string($type)
                && in_array($type, $vocabulary, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Types no class maps to directly: SchemaWalker::refineType() derives them
     * from a mapped component's own accessors — a multiple Select, an email or
     * numeric TextInput, a badged TextEntry.
     *
     * They live here rather than only in the walker so that `types()` is the
     * whole emittable vocabulary. A type that only one of the two files knows
     * about is a type the contract snapshot cannot notice is untested.
     *
     * @var list<string>
     */
    private const REFINED = ['multiselect', 'email', 'password', 'number', 'badge_entry', 'rich_entry'];

    public static function isSkipped(object $component): bool
    {
        return in_array($component::class, self::SKIPPED, true);
    }

    /**
     * Every `type` string this package can put on the wire.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return array_values(array_unique([...array_values(self::MAP), ...self::REFINED]));
    }
}
