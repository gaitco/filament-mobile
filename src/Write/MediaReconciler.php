<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Write;

use Gait\FilamentMobile\Http\UploadController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Wholesale replacement, the P12/repeater model: the submitted value IS the
 * whole new set for this field's collection. A kept item is named by its
 * uuid; a fresh file arrives as the stored path `UploadController` returned
 * and is consumed into the library via `addMediaFromDisk()`; anything this
 * record's collection holds but the submission does not name is deleted.
 *
 * Split into `classify()` (pure read, may throw) and `apply()` (mutates)
 * rather than one `sync()`, for two reasons:
 *
 *  - Validate-then-mutate within ONE field is not enough when a request
 *    carries several media fields: `saveRelations()` must classify every
 *    media component before applying ANY of them, or field A's write
 *    already lands while field B's submission is still being refused — a
 *    422 that only half-reverts. See `RecordForm::saveRelations()`.
 *  - `classify()` alone can run to find out whether a request WOULD
 *    succeed without touching the database.
 *
 * An "add" token is trusted only after it is proven to be a path THIS
 * package's own upload endpoint minted — see `looksMinted()`. Accepting any
 * non-uuid string here would hand `addMediaFromDisk()`'s
 * `preservingOriginal(false)` an arbitrary path on the same disk: Spatie
 * stores every record's own files at `{media_id}/{file_name}`, so an
 * unvalidated token let a crafted payload steal a DIFFERENT record's file
 * into the caller's own collection and delete the original in the same
 * move — the disk has no ownership concept of its own, only this check
 * does.
 */
final class MediaReconciler
{
    /** The bare shape `Str::uuid()->toString()` produces — no braces, lowercase or not. */
    private const UUID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /**
     * Reads the record's own media plus (when there is anything to add) the
     * component's own disk/directory, and answers with an unapplied plan —
     * or throws. Nothing is deleted or added here.
     *
     * @param  mixed  $submitted  the RAW payload value for this field — a
     *     string for a single-file component, a list<string> for one that
     *     declared ->multiple(). Shape is enforced here against the
     *     component's own `isMultiple()`, not assumed: RuleExtractor
     *     withholds a rule for every relation-write name (this one
     *     included), so nothing upstream already 422s a scalar sent for a
     *     multiple field or an array sent for a single one.
     * @return array{record: Model, field: string, collection: string, keep: list<string>, add: list<string>, disk: ?string}
     */
    public static function classify(Model $record, string $field, object $component, string $collection, mixed $submitted): array
    {
        $multiple = self::isMultiple($component);

        if ($multiple !== is_array($submitted)) {
            throw self::refuse($field, 'This field expects a different value shape.');
        }

        // Single-valued: one string, or "" as the explicit clear — the same
        // shape a multiple field's `[]` already is.
        $tokens = is_array($submitted) ? array_values($submitted) : ($submitted === '' ? [] : [$submitted]);

        $existing = $record->getMedia($collection)->keyBy('uuid');

        // Resolved once per call, whenever there is anything to classify —
        // even a token that turns out to be an owned uuid needs this to
        // decide a DIFFERENT token in the same list isn't a minted path
        // (see the ordering note below). Read the same way
        // UploadController does, fail closed: a throwing gate here refuses
        // rather than guesses at a disk/directory to check against.
        $directory = null;
        $disk = null;

        if ($tokens !== []) {
            try {
                $directory = $component->getDirectory() ?? '';
                $disk = $component->getDiskName();
            } catch (Throwable) {
                throw self::refuse($field, 'This field cannot accept a file right now.');
            }
        }

        $keep = [];
        $add = [];

        foreach ($tokens as $token) {
            if (! is_string($token) || $token === '') {
                throw self::refuse($field, 'This field contains an invalid value.');
            }

            if ($existing->has($token)) {
                $keep[] = $token;

                continue;
            }

            // Checked BEFORE the uuid-shape refusal below, deliberately: a
            // minted path for a field with no ->directory() and a sniffed
            // type outside SAFE_EXTENSIONS is a BARE uuid — indistinguishable
            // from an ordinary uuid token by shape alone. Only "is this
            // exactly the path this endpoint stores to, and does it still
            // exist there" tells the two apart; existence also refuses a
            // once-valid path a prior request already consumed
            // (`preservingOriginal(false)` deletes the source on first use)
            // and a token repeated to make it look otherwise.
            if (self::looksMinted($token, $directory) && Storage::disk($disk)->exists($token)) {
                if (in_array($token, $add, true)) {
                    // The same path listed twice: the first would move/delete
                    // the source file, and the second could never be
                    // classified as an add again — refuse both rather than
                    // let the first through and the second 500.
                    throw self::refuse($field, 'This field lists the same upload twice.');
                }

                $add[] = $token;

                continue;
            }

            // Not this record's own media, and not a path this endpoint
            // minted: a uuid-shaped token here is a crafted cross-record
            // reference; anything else is simply not a value this field
            // will ever accept.
            if (self::looksLikeUuid($token)) {
                throw self::refuse($field, 'This item does not belong to this record.');
            }

            throw self::refuse($field, 'This field contains an invalid value.');
        }

        // The count/required rules the walker publishes as HINTS (P12's file
        // branch, SchemaWalker::config() :695-711) were never enforced here:
        // `FieldPersistence::savesViaRelationship()` makes RuleExtractor drop
        // a media upload from fromComponents() entirely (a relation-write
        // field carries no Laravel rule at all — see its docblock), so
        // `->required()`/`->minFiles()`/`->maxFiles()` on a Spatie upload was
        // enforced by the web panel and by NOTHING on mobile. Enforced here
        // instead, off the plan's own final count — the one number that
        // reflects what this submission WOULD leave the collection holding.
        $count = count($keep) + count($add);

        if (self::isRequired($component, $field) && $count === 0) {
            throw self::refuse($field, 'This field is required.');
        }

        // Count bounds only meaningful for multiple fields — the same
        // "single-file fields never carry them" rule the walker's own
        // config() applies to `minFiles`/`maxFiles` (SchemaWalker.php:695-698:
        // "min/max ITEMS are meaningless for one path").
        if ($multiple) {
            $min = self::fileCount($component, 'getMinFiles', $field);

            if ($min !== null && $count < $min) {
                throw self::refuse($field, "This field requires at least {$min} file(s).");
            }

            $max = self::fileCount($component, 'getMaxFiles', $field);

            if ($max !== null && $count > $max) {
                throw self::refuse($field, "This field allows at most {$max} file(s).");
            }
        }

        return [
            'record' => $record,
            'field' => $field,
            'collection' => $collection,
            'keep' => $keep,
            'add' => $add,
            'disk' => $disk,
        ];
    }

    /**
     * `isRequired()`, fail-closed like `getDirectory()`/`getDiskName()` above:
     * a throwing gate refuses the field rather than guessing it is optional,
     * since guessing wrong here would let mobile persist an empty collection
     * a `->required()` field forbids on the web panel.
     *
     * Public: `RecordForm::saveRelations()`'s create-only absence check
     * (final review finding 2) reads this same answer for a media field the
     * request never mentioned at all — `classify()`'s own call below only
     * ever sees a field that WAS submitted, so that check needs this
     * predicate independently, on the same fail-closed terms.
     */
    public static function isRequired(object $component, string $field): bool
    {
        if (! method_exists($component, 'isRequired')) {
            return false;
        }

        try {
            return (bool) $component->isRequired();
        } catch (Throwable) {
            throw self::refuse($field, 'This field cannot accept a file right now.');
        }
    }

    /**
     * `getMinFiles()`/`getMaxFiles()`, same fail-closed shape as
     * `isRequired()` above: a declared bound this gate cannot read must
     * refuse rather than silently go unenforced.
     */
    private static function fileCount(object $component, string $method, string $field): ?int
    {
        if (! method_exists($component, $method)) {
            return null;
        }

        try {
            $value = $component->{$method}();
        } catch (Throwable) {
            throw self::refuse($field, 'This field cannot accept a file right now.');
        }

        return is_int($value) ? $value : null;
    }

    /**
     * A single fresh path claimed by TWO plans in the same request —
     * `{"photos": ["<minted-path>"], "cover": "<minted-path>"}` — passes
     * `classify()` for BOTH fields, because each call only ever sees its own
     * field's tokens and the file genuinely still exists until something
     * applies. Left unchecked, `apply(photos)` would consume the file
     * (`preservingOriginal(false)` deletes the source) and `apply(cover)`'s
     * `addMediaFromDisk()` would then throw on a file that's already gone —
     * an uncaught 500 with `photos` already reconciled and no way back.
     *
     * Checked across every plan BEFORE any of them applies, so this is
     * itself part of the read-only, validate-then-mutate phase. Refused on
     * the SECOND plan naming the path, keyed to that plan's own field — the
     * first field did nothing wrong on its own.
     *
     * @param  list<array{record: Model, field: string, collection: string, keep: list<string>, add: list<string>, disk: ?string}>  $plans
     */
    public static function assertNoCrossFieldClaims(array $plans): void
    {
        $claimed = [];

        foreach ($plans as $plan) {
            foreach ($plan['add'] as $path) {
                // Keyed by disk too: two DIFFERENT components could
                // (pathologically) resolve the same bare string as a path on
                // different disks, which are not the same file.
                $key = $plan['disk'] . '|' . $path;

                if (isset($claimed[$key])) {
                    throw self::refuse($plan['field'], 'This upload is already attached to another field in this request.');
                }

                $claimed[$key] = true;
            }
        }
    }

    /**
     * Deletes every existing item this plan did not keep, adds every fresh
     * path, then clears the model's cached `media` relation.
     *
     * The clear matters even though it looks like tidying: `getMedia()`
     * above caches the model's `media` relation on first read, and neither
     * `Media::delete()` nor `addMediaFromDisk()` ever refreshes that cache
     * (verified against the installed medialibrary package — no caller
     * unsets it anywhere). Left alone, the write response's own
     * `RecordSerializer::serialize()` call — which reads `getMedia()` again
     * on this same model instance right after `saveRelations()` returns —
     * would see the collection as it stood BEFORE this call, not after.
     *
     * @param  array{record: Model, field: string, collection: string, keep: list<string>, add: list<string>, disk: ?string}  $plan
     */
    public static function apply(array $plan): void
    {
        $record = $plan['record'];
        $collection = $plan['collection'];

        $existing = $record->getMedia($collection)->keyBy('uuid');

        foreach ($existing as $uuid => $media) {
            if (! in_array($uuid, $plan['keep'], true)) {
                $media->delete();
            }
        }

        foreach ($plan['add'] as $path) {
            // Not a transaction — the deletes above already ran and this does
            // not undo them (see the class docblock: classify-then-apply
            // keeps fields atomic with EACH OTHER, not a single field's own
            // delete-then-add). This is the cheaper hardening: a legible 422
            // naming the field, instead of an uncaught 500 over media rows
            // and files this same call already destroyed.
            try {
                $record->addMediaFromDisk($path, $plan['disk'])
                    ->preservingOriginal(false)
                    ->toMediaCollection($collection);
            } catch (Throwable) {
                throw self::refuse($plan['field'], 'This file could not be stored.');
            }
        }

        $record->unsetRelation('media');
    }

    /**
     * Whether `$path` is EXACTLY the shape `UploadController::store()`
     * mints for this component: the component's own directory (or none),
     * immediately followed by a bare uuid with an optional SAFE_EXTENSIONS
     * extension — never a nested path. A token like `"7/private.pdf"`
     * (Spatie's own `{media_id}/{file_name}` convention for an EXISTING
     * media item) fails here because either the directory prefix doesn't
     * match or the remainder contains a `/`, which this refuses outright
     * regardless of the regex.
     */
    private static function looksMinted(string $path, string $directory): bool
    {
        $prefix = $directory === '' ? '' : rtrim($directory, '/') . '/';

        if (! str_starts_with($path, $prefix)) {
            return false;
        }

        $basename = substr($path, strlen($prefix));

        return $basename !== ''
            && ! str_contains($basename, '/')
            && preg_match(self::mintedBasenamePattern(), $basename) === 1;
    }

    /**
     * `UploadController::SAFE_EXTENSIONS`, not a duplicated list: widening
     * that constant must widen what this accepts too, and a second literal
     * list here is exactly how the two would drift.
     */
    private static function mintedBasenamePattern(): string
    {
        $extensions = implode('|', array_map(
            static fn (string $extension): string => preg_quote($extension, '/'),
            UploadController::SAFE_EXTENSIONS,
        ));

        return '/^' . self::UUID_PATTERN . '(\.(' . $extensions . '))?$/i';
    }

    private static function looksLikeUuid(string $token): bool
    {
        return (bool) preg_match('/^' . self::UUID_PATTERN . '$/i', $token);
    }

    private static function isMultiple(object $component): bool
    {
        if (! method_exists($component, 'isMultiple')) {
            return false;
        }

        try {
            return (bool) $component->isMultiple();
        } catch (Throwable) {
            return false;
        }
    }

    private static function refuse(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => $message]);
    }
}
