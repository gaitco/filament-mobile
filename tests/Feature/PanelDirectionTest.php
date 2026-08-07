<?php

declare(strict_types=1);

it('publishes rtl for a right-to-left panel locale', function (string $locale, string $expected) {
    app()->setLocale($locale);

    expect(schemaDocument()['panel']['direction'])->toBe($expected)
        ->and(schemaDocument()['panel']['locale'])->toBe($locale);
})->with([
    // Measured against filament/filament 5.7.5's own lang files, which carry
    // a `direction` key in all 62 locales.
    ['ar', 'rtl'],
    ['he', 'rtl'],
    ['fa', 'rtl'],
    ['en', 'ltr'],
    ['fr', 'ltr'],
]);

it('falls back to ltr for a locale filament does not ship', function () {
    // Measured: the key resolves to 'ltr' rather than returning itself, so
    // this needs no guard — but it needs a test, because that is a property
    // of Filament's fallback chain and not of our code.
    app()->setLocale('zz_unknown');

    expect(schemaDocument()['panel']['direction'])->toBe('ltr');
});

it('normalises a nonsense override to ltr', function () {
    // A panel that overrode the key with something unrenderable gets the safe
    // answer, not a value no client can act on. The contract is a closed set.
    //
    // addLines()'s namespace is its THIRD argument, not part of the key — a
    // key of 'filament-panels::layout.direction' is silently a no-op (it
    // writes into the '*' namespace under a bogus group name and is never
    // read back by get()). Confirmed by mutation: with the namespace baked
    // into the key, deleting direction()'s normalising ternary entirely still
    // left this test green.
    app('translator')->addLines(['layout.direction' => 'sideways'], 'en', 'filament-panels');

    expect(schemaDocument()['panel']['direction'])->toBe('ltr');
});

it('degrades to ltr when the translator throws rather than answering', function () {
    // Decorates the REAL translator rather than replacing it wholesale: the
    // rest of /schema also calls trans() (RuleExtractor's validation
    // messages), so a translator that throws on every key would 500 the
    // whole document and prove nothing about direction()'s own catch
    // specifically. Only the one key direction() reads is made to explode;
    // everything else is forwarded to the real translator unchanged.
    $real = app('translator');

    app()->instance('translator', new class($real) implements \Illuminate\Contracts\Translation\Translator {
        public function __construct(private \Illuminate\Contracts\Translation\Translator $real) {}

        public function get($key, array $replace = [], $locale = null)
        {
            if ($key === 'filament-panels::layout.direction') {
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

    expect(schemaDocument()['panel']['direction'])->toBe('ltr');
});
