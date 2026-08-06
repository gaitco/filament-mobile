<?php

declare(strict_types=1);

namespace Gait\FilamentMobile;

use Illuminate\Database\Eloquent\Model;

/**
 * Turns a record into exactly the payload the card declared, and nothing more.
 *
 * The card's fieldPaths() is the whitelist: a column no screen displays never
 * reaches the phone, so adding a secret column to a table cannot leak it. The
 * corollary is deliberate — a field missing from the card is absent from every
 * record, which is the opt-in property, not a bug.
 */
final class RecordSerializer
{
    /** @var list<string> */
    private array $infolistPaths = [];

    /** @var list<string> */
    private array $formPaths = [];

    public function __construct(
        private readonly MobileCard $card,
        private readonly string $recordKey,
    ) {
    }

    /**
     * The detail screen renders the infolist, which routinely names fields the
     * list card never shows. Widening is additive and still a whitelist: only
     * paths some declared screen actually references are ever serialised.
     *
     * Returns a copy so one serializer cannot silently widen another's payload
     * — the list endpoint's card-only instance stays card-only.
     *
     * @param  list<string>  $paths
     */
    public function withInfolistPaths(array $paths): self
    {
        $clone = clone $this;
        $clone->infolistPaths = array_values($paths);

        return $clone;
    }

    /**
     * Form-field paths, serialised under their LITERAL key.
     *
     * A translatable field is published as `caption.ar`, and Laravel validates
     * that as a PATH — `caption[ar]`. But an infolist entry asks for `caption`,
     * Spatie's accessor, which is the current locale's string. They are
     * different things sharing one JSON key, and `data_set('caption.ar', …)`
     * replaces the scalar with a map: the endpoint answered
     * `{"caption": {"ar": null, "en": null}}` from the moment the record
     * payload was widened with the form's fields.
     *
     * Neither representation can be dropped — without the scalar the infolist
     * has nothing to render, without the per-locale keys the edit form opens
     * blank — and ordering is not a fix, because whichever writes last wins.
     * So form paths get a flat key of their own, and the client reads a
     * literal key before it tries to traverse.
     *
     * @param  list<string>  $paths
     */
    public function withFormPaths(array $paths): self
    {
        $clone = clone $this;
        $clone->formPaths = array_values($paths);

        return $clone;
    }

    /** @return array<string, mixed> */
    public function serialize(Model $record): array
    {
        // The key is not a card field, but the client needs it to route to the
        // record endpoint, so it is always present.
        $payload = [$this->recordKey => data_get($record, $this->recordKey)];

        $paths = array_unique([...$this->card->fieldPaths(), ...$this->infolistPaths]);

        foreach ($paths as $path) {
            // data_get()/data_set() give dotted paths their nesting for free:
            // `company.name` reads through the eager-loaded relation and
            // writes {"company":{"name":…}}. A null anywhere along the path
            // yields null — a missing relation is never a failure.
            data_set($payload, $path, $this->read($record, $path));
        }

        // AFTER the nesting pass, and written flat: a form path that shares its
        // first segment with a card or infolist path must not overwrite it, and
        // must not be overwritten either. `caption` stays the infolist's
        // scalar; `caption.ar` sits beside it as its own key.
        foreach ($this->formPaths as $path) {
            // Flat for every form path. For an undotted name this is exactly
            // what data_set() would have written anyway; for a dotted one it
            // is the whole point — `caption.ar` becomes its own key instead of
            // nesting over the infolist's `caption` scalar.
            //
            // A form path that collides with an already-written key does not
            // overwrite it: `caption` (infolist) and `caption.ar` (form) are
            // different keys, so both survive.
            $payload[$path] = $this->read($record, $path);
        }

        return $payload;
    }

    /**
     * `data_get($record, 'title.ar')` cannot reach a translation: the first
     * segment resolves through the accessor, which hands back the *current
     * locale's string*, and reading `ar` off a string is null. So every
     * translatable field serialised as null — the card showed the right text
     * (its path is undotted) while every edit form opened blank, and saving
     * that blank form would have written the blanks back.
     *
     * `method_exists` rather than a dependency: Spatie's `HasTranslations` is
     * what Filament's own translatable plugin builds on, and a model without
     * it falls through to the ordinary path unchanged.
     */
    private function read(Model $record, string $path): mixed
    {
        $value = data_get($record, $path);

        if ($value !== null || ! str_contains($path, '.')) {
            return $value;
        }

        [$attribute, $rest] = explode('.', $path, 2);

        if (! method_exists($record, 'getTranslations')
            || ! method_exists($record, 'getTranslatableAttributes')
            || ! in_array($attribute, $record->getTranslatableAttributes(), true)) {
            return null;
        }

        return data_get($record->getTranslations($attribute), $rest);
    }
}
