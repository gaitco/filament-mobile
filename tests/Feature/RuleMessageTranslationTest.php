<?php

declare(strict_types=1);

/**
 * The walker's rule-message translations are guarded the same way
 * PanelSchemaBuilder::direction()'s own key is: a throwing translator costs
 * THIS FIELD'S published `rules.messages` — the client falls back to its own
 * FilamentStrings per rule — never the component, and never the document.
 * Before the guard, a bare test-double translator took down all of /schema
 * (found while writing PanelDirectionTest's own throwing double, which had
 * to spare every other key for exactly this reason).
 */

/** One banners form node by field name, wherever it sits in the tree. */
function bannersNode(array $document, string $field): ?array
{
    $form = collect($document['resources'])->firstWhere('key', 'banners')['form'];

    $find = function (array $nodes) use (&$find, $field): ?array {
        foreach ($nodes as $node) {
            if (($node['name'] ?? null) === $field) {
                return $node;
            }
            $hit = $find($node['children'] ?? []);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    };

    return $find($form);
}

it('degrades to no published messages when the translator throws on validation keys', function () {
    $real = app('translator');

    app()->instance('translator', new class($real) implements \Illuminate\Contracts\Translation\Translator {
        public function __construct(private \Illuminate\Contracts\Translation\Translator $real) {}

        public function get($key, array $replace = [], $locale = null)
        {
            if (str_starts_with((string) $key, 'validation.')) {
                throw new \RuntimeException('translator exploded');
            }

            return $this->real->get($key, $replace, $locale);
        }

        public function choice($key, $number, array $replace = [], $locale = null)
        {
            return $this->real->choice($key, $number, $replace, $locale);
        }

        public function getLocale()
        {
            return $this->real->getLocale();
        }

        public function setLocale($locale)
        {
            $this->real->setLocale($locale);
        }
    });

    // schemaDocument() asserts Ok — the document survives, which is the point.
    $node = bannersNode(schemaDocument(), 'name');

    // The field's RULES still publish — only the translated hints degrade.
    expect($node)->not->toBeNull()
        ->and($node['rules']['required'])->toBeTrue()
        ->and($node['rules'])->not->toHaveKey('messages');
});

it('skips a rule whose translation resolves to an array, keeping the rest', function () {
    $real = app('translator');

    app()->instance('translator', new class($real) implements \Illuminate\Contracts\Translation\Translator {
        public function __construct(private \Illuminate\Contracts\Translation\Translator $real) {}

        public function get($key, array $replace = [], $locale = null)
        {
            // A host override can turn a flat key into a nested group, and
            // trans() then hands back an array where the contract promises a
            // string. Only that rule's message may be skipped.
            if ($key === 'validation.required') {
                return ['string' => 'nested'];
            }

            return $this->real->get($key, $replace, $locale);
        }

        public function choice($key, $number, array $replace = [], $locale = null)
        {
            return $this->real->choice($key, $number, $replace, $locale);
        }

        public function getLocale()
        {
            return $this->real->getLocale();
        }

        public function setLocale($locale)
        {
            $this->real->setLocale($locale);
        }
    });

    $node = bannersNode(schemaDocument(), 'name');

    expect($node['rules']['messages'])->not->toHaveKey('required')
        ->and($node['rules']['messages']['max'])->toBeString();
});
