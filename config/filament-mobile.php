<?php

declare(strict_types=1);

return [
    // Route prefix for every mobile endpoint.
    'prefix' => 'api/mobile-panel',

    // Records per page. Deliberately not client-controllable: a `per_page`
    // query parameter would let one request pull an entire table.
    'per_page' => 20,

    // Above this many options, a select publishes an `optionsUrl` instead of
    // inlining. The pilot measured a 55-option list in a development
    // database; the same panel in production inlines the whole table.
    'options_inline_max' => 50,

    // Auth guard for the mobile endpoints. Null uses the application default.
    'guard' => null,

    // Host middleware applied to every mobile endpoint, before the auth guard.
    // Defaults to the application's `api` group so the host's own middleware —
    // locale negotiation above all — runs here like it does on every other
    // route. Without it the panel always serialises in APP_LOCALE, which makes
    // the package unusable in a bilingual app.
    'middleware' => ['api'],

    // Explicit resource list. Null (the default) reads the registered Filament
    // panel, so resources are never listed twice. Set an array only where no
    // panel is booted — or to serve a deliberate subset.
    'resources' => null,

    // Dashboard widgets exposed to mobile, by class name, in publication
    // order. Empty by default and never auto-discovered: a widget runs
    // arbitrary queries, so a panel must name each one it wants on a phone.
    // Only StatsOverviewWidget and ChartWidget subclasses are supported;
    // `filament-mobile:doctor` reports anything else.
    'widgets' => [],

    // Components this package does not ship knowledge of, mapped to a contract
    // type. Entries here win over the built-in map, so a panel can also
    // override one it disagrees with. `filament-mobile:doctor` prints the exact
    // line to paste for anything it finds unmapped.
    //
    //     \Ysfkaya\FilamentPhoneInput\Forms\PhoneInput::class => 'text',
    //     \Guava\IconPicker\Forms\Components\IconPicker::class => 'select',
    'types' => [],
];
