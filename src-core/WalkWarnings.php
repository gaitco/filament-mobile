<?php

declare(strict_types=1);

namespace Gait\MobileCore;

/**
 * Everything the walker could not handle. A schema that silently loses a field
 * is the exact failure this package exists to avoid, so warnings are collected
 * and surfaced — in `doctor` always, and in the /schema response outside
 * production — never discarded.
 */
final class WalkWarnings
{
    /** @var list<array{resource: string, component: string, reason: string}> */
    private array $warnings = [];

    public function add(string $resource, string $component, string $reason): void
    {
        $this->warnings[] = [
            'resource' => $resource,
            'component' => $component,
            'reason' => $reason,
        ];
    }

    /** @return list<array{resource: string, component: string, reason: string}> */
    public function all(): array
    {
        return $this->warnings;
    }

    public function isEmpty(): bool
    {
        return $this->warnings === [];
    }
}
