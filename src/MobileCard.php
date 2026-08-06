<?php

declare(strict_types=1);

namespace Gait\FilamentMobile;

/**
 * How one record renders as a list card — the mobile answer to Filament's
 * data table. Declared explicitly rather than derived from table(), because
 * building a Filament Table requires a Livewire host implementing 46 methods.
 *
 * Every slot is optional; a card with only a title is a valid list tile.
 */
final class MobileCard
{
    private ?string $title = null;

    private ?string $subtitle = null;

    /** @var array{field: string, fallback: ?string}|null */
    private ?array $leading = null;

    /** @var list<array{field: string, colors: array<string, string>}> */
    private array $badges = [];

    /** @var list<array{field: string, format: ?string}> */
    private array $meta = [];

    public static function make(): self
    {
        return new self();
    }

    public function title(string $field): self
    {
        $this->title = $field;

        return $this;
    }

    public function subtitle(string $field): self
    {
        $this->subtitle = $field;

        return $this;
    }

    public function leadingImage(string $field, ?string $fallback = null): self
    {
        $this->leading = ['field' => $field, 'fallback' => $fallback];

        return $this;
    }

    /** @param array<string, string> $colors value => semantic colour name */
    public function badge(string $field, array $colors = []): self
    {
        $this->badges[] = ['field' => $field, 'colors' => $colors];

        return $this;
    }

    public function meta(string $field, ?string $format = null): self
    {
        $this->meta[] = ['field' => $field, 'format' => $format];

        return $this;
    }

    /**
     * Contract §5.4 shape. Absent slots are omitted rather than emitted as
     * null: the Dart parser treats an absent slot as valid and a present slot
     * without a `field` as a contract violation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $card = [];

        if ($this->title !== null) {
            $card['title'] = ['field' => $this->title];
        }

        if ($this->subtitle !== null) {
            $card['subtitle'] = ['field' => $this->subtitle];
        }

        if ($this->leading !== null) {
            $card['leading'] = [
                'type' => 'image',
                'field' => $this->leading['field'],
                'fallback' => $this->leading['fallback'],
            ];
        }

        if ($this->badges !== []) {
            $card['badges'] = $this->badges;
        }

        if ($this->meta !== []) {
            $card['meta'] = $this->meta;
        }

        return $card;
    }

    /**
     * Every attribute path this card reads. Doubles as the serialisation
     * whitelist, so the payload never carries a column no screen displays.
     *
     * @return list<string>
     */
    public function fieldPaths(): array
    {
        return array_values(array_filter([
            $this->title,
            $this->subtitle,
            $this->leading['field'] ?? null,
            ...array_column($this->badges, 'field'),
            ...array_column($this->meta, 'field'),
        ]));
    }

    /**
     * Relation prefixes derived from dotted field paths, for eager loading.
     * `company.owner.email` contributes `company` and `company.owner`. This is
     * the whole N+1 defence, and it is automatic — a developer who writes a
     * dotted field never has to think about it.
     *
     * @return list<string>
     */
    public function relationPaths(): array
    {
        $paths = [];

        foreach ($this->fieldPaths() as $field) {
            $segments = explode('.', $field);
            array_pop($segments); // the attribute itself is not a relation

            for ($i = 1; $i <= count($segments); $i++) {
                $paths[] = implode('.', array_slice($segments, 0, $i));
            }
        }

        return array_values(array_unique($paths));
    }
}
