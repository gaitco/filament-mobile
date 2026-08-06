<?php

declare(strict_types=1);

/**
 * The three files in `src/` allowed to reference Livewire, and why:
 *
 * - `Console/DoctorCommand.php` is a CLI command, not a request path, and it
 *   needs a real Table to check mobile()/table() drift.
 * - `Introspection/HeadlessSchemaHost.php` is the Livewire component that
 *   reactive schema closures resolve `Get` against; it exists so that every
 *   controller — including `Http/StateController.php` — stays Livewire-free.
 * - `Introspection/HeadlessTableHost.php` is the same headless-object trick
 *   over `HasTable` instead of `HasSchemas`: it builds a resource's `table()`
 *   without Livewire, so `Actions/ActionResolver.php` — on the request path
 *   — can read declared actions without importing `Filament\Tables\Table`
 *   itself.
 *
 * This list is a fixed three, not a pattern. A fourth offending file must
 * fail.
 */
const LIVEWIRE_EXEMPT_PATHS = [
    'Console/DoctorCommand.php',
    'Introspection/HeadlessSchemaHost.php',
    'Introspection/HeadlessTableHost.php',
];

function architectureOffendersIn(string $dir): array
{
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        foreach (LIVEWIRE_EXEMPT_PATHS as $exempt) {
            if (str_ends_with($file->getPathname(), $exempt)) {
                continue 2;
            }
        }

        $contents = file_get_contents($file->getPathname());

        foreach (['Livewire\\', 'HasTable', 'Filament\\Tables\\Table'] as $forbidden) {
            if (str_contains($contents, $forbidden)) {
                $offenders[] = $file->getPathname() . ' → ' . $forbidden;
            }
        }
    }

    return $offenders;
}

it('never references Livewire or Filament tables from src', function () {
    expect(architectureOffendersIn(__DIR__ . '/../../src'))->toBeEmpty();
});

it('permits Livewire in exactly three named files and nowhere else', function () {
    $src = __DIR__ . '/../../src';

    // A stale entry silently widens the exemption to a filename nothing checks.
    foreach (LIVEWIRE_EXEMPT_PATHS as $exempt) {
        expect(file_exists($src . '/' . $exempt))->toBeTrue(
            "exempt path {$exempt} does not exist — the list has drifted",
        );
    }

    // A fourth file containing Livewire must fail. Asserting the exempt
    // list's length would prove nothing; this is what proves it is a fixed
    // three-item list rather than a pattern.
    $intruder = $src . '/__ArchitectureProbe.php';
    file_put_contents($intruder, "<?php\n\nuse Livewire\\Component;\n");

    try {
        expect(architectureOffendersIn($src))->toContain($intruder . ' → Livewire\\');
    } finally {
        unlink($intruder);
    }
});

it('publishes the documented config defaults', function () {
    expect(config('filament-mobile.prefix'))->toBe('api/mobile-panel')
        ->and(config('filament-mobile.per_page'))->toBe(20)
        ->and(config('filament-mobile.guard'))->toBeNull();
});
