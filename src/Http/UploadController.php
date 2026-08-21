<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Http;

use Gait\FilamentMobile\ResourceRegistry;
use Gait\FilamentMobile\Upload\UploadFieldResolver;
use Gait\MobileCore\Authorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

/**
 * Stores one file for one upload field and answers with its stored path.
 *
 * The path is all that travels back: the form puts it in the field's value
 * and the ordinary write path saves it as a string, which is why this slice
 * needed no change to store()/update() at all.
 *
 * Filament's own `saveUploadedFile()` is deliberately NOT used — its
 * signature takes a Livewire `TemporaryUploadedFile`, and importing that
 * lifecycle is exactly what this package exists to avoid. Storing from the
 * component's own disk/directory/visibility reaches the same place.
 */
final class UploadController
{
    /**
     * The only extensions this endpoint will ever write to disk for the
     * stored file, regardless of what the sniffed MIME type maps to in
     * Symfony's full mime-to-extension table.
     *
     * Symfony's table is not a safe list to trust wholesale: it maps MIME
     * types a webserver can be configured to EXECUTE
     * (`application/x-httpd-php` => `php`, `application/x-sh` => `sh`, ...)
     * to exactly those extensions. Whether a given upload ever reaches one
     * of those entries depends on what this deployment's libmagic build
     * happens to sniff PHP/shell/Python source as — some report
     * `text/x-php` (which Symfony's table does not map to anything,
     * verified empirically on this machine) and others report
     * `application/x-httpd-php` (which it maps straight to `.php`). "Can
     * this endpoint ever write an executable extension" must not depend on
     * that — it must be answerable by reading this constant. A sniffed
     * type outside this set stores with NO extension at all, never the
     * mapped one.
     *
     * Images and PDF only: every fixture and every real use in this
     * package's test suite needs exactly that. Widen deliberately, not by
     * falling through to Symfony's table — if a real panel needs `.zip` or
     * similar, add it here by name.
     *
     * Public so `Write\MediaReconciler` can recognise the exact shape a
     * minted path takes (uuid + one of these, or no extension at all) when
     * it decides whether a submitted "add" token is a stored path this
     * endpoint actually produced — reusing this list rather than
     * duplicating it is what keeps that check from drifting if this one is
     * ever widened.
     */
    public const SAFE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf'];

    public function __construct(private readonly ResourceRegistry $registry) {}

    public function __invoke(Request $request, string $resource): JsonResponse
    {
        [$class, ] = $this->registry->findByKey($resource)
            ?? abort(404, "No mobile resource [{$resource}].");

        // Before anything else, matching every other endpoint — a caller with
        // no access to the resource must not be able to probe field names.
        abort_unless(
            Authorizer::allows($request->user(), 'viewAny', $class::getModel()),
            403,
        );

        $request->validate(['file' => ['required', 'file']]);

        // input(), never string(): `field[]=hero_image` makes this an array,
        // and Stringable's `(string)` cast on one is an array-to-string
        // Warning that HandleExceptions escalates to a 500. A non-string
        // `field` is just another unresolvable field name and gets the same
        // bodyless 403 as every other refusal — deliberately not validate(),
        // whose 422 would make this refusal distinguishable from the rest.
        $field = $request->input('field');

        if (! is_string($field)) {
            abort(403);
        }

        $resolver = new UploadFieldResolver($class);

        $component = $resolver->resolve($field)
            ?? abort(403);

        $constraints = $resolver->constraintsFor($component);

        // Either constraint closure threw. Refuse rather than accept
        // anything — fail closed, in the same `message` + `errors` shape as
        // every other validation failure here. Explicitly flagged rather
        // than smuggled through a rule: the old `max:0` encoding of "refuse
        // everything" passed a zero-byte file.
        if ($constraints['refused']) {
            throw ValidationException::withMessages([
                'file' => 'This field cannot accept a file right now.',
            ]);
        }

        // Enforced here, from the component, never from what the client was
        // told: the published constraints are a hint.
        $rules = ['file'];

        if ($constraints['maxSizeKb'] !== null) {
            $rules[] = 'max:' . $constraints['maxSizeKb'];
        }

        if (is_array($constraints['types']) && $constraints['types'] !== []) {
            // `mimetypes` sniffs the file's CONTENT. `mimes` would trust the
            // extension, which is exactly the lie this guards against.
            $rules[] = 'mimetypes:' . implode(',', $constraints['types']);
        } elseif (is_array($constraints['types'])) {
            // A genuinely-configured empty allow-list (`acceptedFileTypes([])`)
            // accepts nothing. (A THROWING closure is `refused` above, not
            // this branch.) Same shape as every other validation failure in
            // this method (`message` + `errors`), so a client's existing
            // field-error rendering picks it up too.
            throw ValidationException::withMessages([
                'file' => 'This field cannot accept a file right now.',
            ]);
        }
        // else: $constraints['types'] === null. The field never called
        // acceptedFileTypes() — an ordinary, unremarkable configuration,
        // not a refusal — so no `mimetypes` rule is added and any content
        // is accepted here, deliberately: Filament's own web panel is
        // exactly as unrestricted for this field, and mobile must not be
        // stricter than web for a case nobody asked to restrict. This is
        // only safe because SAFE_EXTENSIONS below makes the stored
        // artifact inert regardless of what type was sniffed — an
        // unrestricted field must never become a path to an executable
        // extension, whatever this box's libmagic reports.

        $request->validate(['file' => $rules]);

        $file = $request->file('file');

        // The stored extension comes from the SNIFFED mime type, never from
        // the client's filename: `getClientOriginalExtension()` is exactly
        // as client-controlled as the Content-Type header the rest of this
        // method already refuses to trust. A polyglot with valid PNG bytes
        // but a client-side name of `shell.php` must not be written out as
        // `<uuid>.php` — on a public disk served by a PHP-executing
        // webserver, that a client can name anything mapped through here is
        // a straight path to remote code execution.
        //
        // And the mapped extension itself is clamped to SAFE_EXTENSIONS —
        // see that constant's comment for why trusting Symfony's full table
        // here would make "is this ever executable" a question about this
        // box's libmagic build rather than this package's own decision.
        $extension = MimeTypes::getDefault()->getExtensions($file->getMimeType())[0] ?? null;
        $extension = in_array($extension, self::SAFE_EXTENSIONS, true) ? $extension : null;

        // All three storage getters evaluate closures. A throwing one used to
        // 500 the endpoint AFTER validation passed — the only closures this
        // phase read bare. 422, not 403: the field resolved and the file
        // validated, so like a throwing constraint closure this is "cannot
        // accept a file right now", not "no such field".
        try {
            $directory = $component->getDirectory() ?? '';
            $disk = $component->getDiskName();
            $visibility = $component->getVisibility();
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'file' => 'This field cannot accept a file right now.',
            ]);
        }

        $path = $file->storeAs(
            $directory,
            Str::uuid()->toString() . ($extension !== null ? '.' . $extension : ''),
            [
                'disk' => $disk,
                'visibility' => $visibility,
            ],
        );

        return response()->json(['path' => $path]);
    }
}
