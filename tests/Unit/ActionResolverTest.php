<?php

declare(strict_types=1);

use Gait\FilamentMobile\Actions\ActionResolver;
use Gait\FilamentMobile\Tests\Fixtures\Resources\BannerResource;

function resolverFor(): ActionResolver
{
    return new ActionResolver(BannerResource::class, BannerResource::mobile());
}

it('lists the available actions for a record, in declaration order', function () {
    $banner = seedBanner('Listable');           // status: 'active'

    // `publish` is visible only for a draft, `reject` carries a form,
    // `ghost` resolves nowhere — none of the three may appear. The two
    // throwing-getter actions ARE available: only their presentation
    // costs a throw, never their gate.
    expect(array_keys(resolverFor()->available($banner)))
        ->toBe(['approve', 'archive', 'explode', 'halting', 'throwing_label', 'throwing_confirmation', 'failing', 'cancelling', 'html_label']);
});

it('includes an action whose visibility closure passes for this record', function () {
    $banner = seedBannerWith(['name' => 'Draft', 'status' => 'draft']);

    expect(array_keys(resolverFor()->available($banner)))->toContain('publish');
});

it('resolves an opted-in action to a bound instance', function () {
    $banner = seedBanner('Bound');

    $action = resolverFor()->resolve('approve', $banner);

    expect($action)->not->toBeNull()
        ->and($action->getRecord()->getKey())->toBe($banner->getKey());
});

it('refuses a name that is not opted in, even when the table defines it', function () {
    // The whole safety property: an action the resource never named must not
    // be runnable from a phone.
    $mobile = \Gait\FilamentMobile\MobileResource::make()->actions(['approve']);
    $resolver = new ActionResolver(BannerResource::class, $mobile);

    expect($resolver->resolve('archive', seedBanner('Unnamed')))->toBeNull();
});

it('refuses a form-carrying action', function () {
    expect(resolverFor()->resolve('reject', seedBanner('Formy')))->toBeNull();
});

it('refuses an action hidden for this record', function () {
    // `publish` is visible only for a draft.
    expect(resolverFor()->resolve('publish', seedBanner('NotDraft')))->toBeNull();
});

it('refuses a name no table action answers to', function () {
    expect(resolverFor()->resolve('ghost', seedBanner('Ghostly')))->toBeNull();
});

it('serialises an action to the wire shape', function () {
    $banner = seedBanner('Wire');
    $resolver = resolverFor();

    expect($resolver->serialise($resolver->resolve('approve', $banner)))->toBe([
        'name' => 'approve',
        'label' => 'Approve',
        'color' => 'success',
        'icon' => 'heroicon-o-check',
        'confirmation' => null,
    ]);
});

it('carries the action own confirmation strings when it requires confirmation', function () {
    $banner = seedBanner('Confirmed');
    $resolver = resolverFor();

    $node = $resolver->serialise($resolver->resolve('archive', $banner));

    expect($node['confirmation']['heading'])->toBe('Archive this banner?')
        ->and($node['confirmation']['description'])->toBe('It will stop being served.')
        ->and($node['confirmation']['submit'])->toBeString()
        ->and($node['confirmation']['cancel'])->toBeString();
});

it('reports an unresolvable name and a form-carrying one as configuration problems', function () {
    $problems = implode("\n", resolverFor()->problems());

    expect($problems)->toContain('ghost')
        ->and($problems)->toContain('reject');
});

it('degrades a throwing label closure to the action own name, keeping the action available', function () {
    // Cosmetic, not a gate: a throwing label means we cannot NAME the
    // action, not that the user may not run it.
    $banner = seedBanner('LabelTrap');
    $resolver = resolverFor();

    $node = $resolver->serialise($resolver->resolve('throwing_label', $banner));

    expect($node['label'])->toBe('throwing_label')
        ->and($node['color'])->toBeNull()
        ->and($node['confirmation'])->toBeNull();
});

it('fails closed on a throwing confirmation block, never answering null', function () {
    // Safety-relevant: guessing "no confirmation" here would run a
    // destructive action with no prompt. Must degrade to a generic
    // confirmation, never drop it.
    $banner = seedBanner('ConfirmTrap');
    $resolver = resolverFor();

    $node = $resolver->serialise($resolver->resolve('throwing_confirmation', $banner));

    expect($node['confirmation'])->not->toBeNull()
        ->and($node['confirmation']['heading'])->toBeString()
        ->and($node['confirmation']['heading'])->not->toBe('')
        ->and($node['confirmation']['description'])->toBeNull()
        // Empty string, not null: the client's documented fallback is
        // "empty means use my own default label" — see the Flutter
        // confirmation dialog, which only substitutes on isEmpty.
        ->and($node['confirmation']['submit'])->toBe('')
        ->and($node['confirmation']['cancel'])->toBe('');
});

it('degrades a throwing visibility closure to omission rather than a fatal', function () {
    // Same rule as every other closure in this package: a gate that cannot
    // answer refuses, and the request survives.
    $mobile = \Gait\FilamentMobile\MobileResource::make()->actions(['trapdoor']);
    $resolver = new ActionResolver(
        \Gait\FilamentMobile\Tests\Fixtures\Resources\TrapActionResource::class,
        $mobile,
    );

    expect($resolver->available(seedBanner('Trap')))->toBe([]);
});
