<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Upload;

use Filament\Forms\Components\BaseFileUpload;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\Introspection\ChildComponents;
use Gait\FilamentMobile\Introspection\HeadlessSchemaHost;
use Gait\FilamentMobile\Write\WritableNames;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Resolves one submitted field name to the upload component that owns it —
 * or refuses.
 *
 * The endpoint accepts bytes, which makes this the widest attack surface the
 * package has: without it, a crafted `field` could write a stored path into
 * any column the form mentions. So the answer is null for every reason a
 * write would be refused, and the caller turns null into one bodyless 403
 * that cannot distinguish "no such field" from "you may not write it".
 *
 * The writable allow-set is the same one the write path computes, so a field
 * this refuses is exactly a field `store()`/`update()` would refuse — the two
 * cannot drift.
 */
final class UploadFieldResolver
{
    /** @param class-string $resourceClass */
    public function __construct(private readonly string $resourceClass) {}

    /**
     * Assumes field names are unique within a schema — the final gate below
     * checks whether the NAME is in the writable allow-set, not whether the
     * specific component `find()` returned is. `find()` returns the FIRST
     * component matching `$field` in traversal order; `RuleExtractor`'s own
     * descent (which both `WritableNames` and this check ultimately read)
     * keys its leaves by name in a plain array, so if two components ever
     * shared one name, its LAST match wins instead. Two different orderings
     * over the same duplicate name could disagree, and the component this
     * method hands back could then be backed by a DIFFERENT component's
     * writability — its constraints (`constraintsFor()`) would describe the
     * wrong field. Not client-inducible (the client supplies only the name,
     * never the schema), so this is a property of how a resource author
     * writes a form, not an input to validate — no code change follows from
     * it. Named here so the next reader does not have to re-derive it.
     */
    public function resolve(string $field, ?Model $record = null): ?object
    {
        if ($field === '') {
            return null;
        }

        try {
            $components = $this->components($record);
        } catch (Throwable) {
            // A form that cannot be built cannot vouch for anything.
            return null;
        }

        $component = $this->find($components, $field);

        if (! $component instanceof BaseFileUpload) {
            return null;
        }

        try {
            // Disabled refuses like any other field. Multiple is NOT a
            // refusal since P12 — the endpoint still takes one file per
            // request and the client loops; a multiplicity gate that THROWS
            // is refused below instead, through the writable allow-set
            // (RuleExtractor withholds its rule on the same throw), so this
            // resolver and the write path cannot disagree about it.
            if ($component->isDisabled()) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        // The write path's own allow-set. A name it will not persist is a
        // name this must not accept bytes for.
        return in_array($field, WritableNames::of($components), true)
            ? $component
            : null;
    }

    /**
     * `refused` is an explicit flag, not a value smuggled through a rule: the
     * old encodings — an empty types array for a throwing types closure, and
     * `max:0` for a throwing size closure — were not equivalent refusals.
     * `max:0` PASSES a zero-byte file, so an unrestricted field with a
     * throwing size gate stored an empty file. A throwing constraint closure
     * must not widen (or leak through) what is accepted; the caller answers
     * `refused` with a 422 before building any rule. See UploadController.
     *
     * @return array{types: list<string>|null, maxSizeKb: int|null, refused: bool}
     */
    public function constraintsFor(object $component): array
    {
        $types = null;
        $maxSize = null;
        $refused = false;

        try {
            $types = $component->getAcceptedFileTypes();
        } catch (Throwable) {
            $refused = true;
        }

        try {
            $maxSize = $component->getMaxSize();
        } catch (Throwable) {
            $refused = true;
        }

        return [
            'types' => is_array($types) ? array_values($types) : null,
            'maxSizeKb' => is_int($maxSize) ? $maxSize : null,
            'refused' => $refused,
        ];
    }

    /**
     * @param  iterable<mixed>  $components
     */
    private function find(iterable $components, string $field): ?object
    {
        foreach ($components as $component) {
            if (! is_object($component)) {
                continue;
            }

            if (method_exists($component, 'getName')) {
                try {
                    if ($component->getName() === $field) {
                        return $component;
                    }
                } catch (Throwable) {
                    // A name that cannot be read is not the one we want.
                }
            }

            // The same descent the walker and extractor use, so a field
            // nested in a Section is reachable exactly as it is there.
            $found = $this->find(ChildComponents::of($component), $field);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @return list<object> */
    private function components(?Model $record): array
    {
        $host = new HeadlessSchemaHost();
        $host->setMobileState([]);

        $class = $this->resourceClass;

        return $class::form(
            Schema::make($host)
                ->model($record ?? $class::getModel())
                ->operation($record === null ? 'create' : 'edit'),
        )->getComponents();
    }
}
