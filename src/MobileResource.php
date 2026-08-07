<?php

declare(strict_types=1);

namespace Gait\FilamentMobile;

use Closure;
use InvalidArgumentException;

/**
 * A resource's opt-in mobile declaration. A Filament resource without a
 * `mobile()` method does not appear in the panel at all — opt-in is the safety
 * property, so pointing this package at a large admin exposes nothing until
 * each resource is deliberately added.
 *
 * The form and infolist are NOT declared here: they are read automatically
 * from the resource's existing form() and infolist() methods.
 */
final class MobileResource
{
    private MobileCard $card;

    /** @var array<string, MobileCard> relation key => card */
    private array $relationCards = [];

    /** @var list<string> */
    private array $searchable = [];

    /** @var array<string, string> sort key => label */
    private array $sorts = [];

    private ?string $defaultSortKey = null;

    private string $defaultSortDirection = 'asc';

    /** @var list<string> */
    private array $actions = [];

    private function __construct()
    {
        $this->card = MobileCard::make();
    }

    public static function make(): self
    {
        return new self();
    }

    /** @param callable(MobileCard): MobileCard $configure */
    public function card(callable $configure): self
    {
        $this->card = $configure(MobileCard::make());

        return $this;
    }

    /**
     * The host's override for a relation manager's card, for when
     * `RelationCard::fromColumns()` derives nothing usable. Same eager
     * closure-configure pattern as `card()`.
     *
     * A closure that returns something other than a card is refused here
     * rather than stored: `fn ($card) => $card->title('name');` with a
     * trailing semicolon inside a block body returns null, which used to be
     * stored, read back as "the host declared nothing", and silently replaced
     * by the derived card — the author's declaration never read and nothing
     * anywhere saying so. `defaultSort()` refuses its own misdeclaration the
     * same way, at declaration time, where the stack trace still names the
     * resource.
     *
     * A key naming no relation, and a card that fills no slot, are the two
     * misdeclarations this cannot catch here — the first needs the resource's
     * `getRelations()` and the second is legal until something has to render
     * it. `RelationDiscovery` refuses both and `doctor` names them.
     */
    public function relationCard(string $key, Closure $configure): self
    {
        $card = $configure(MobileCard::make());

        if (! $card instanceof MobileCard) {
            throw new InvalidArgumentException(
                "relationCard('{$key}') must return the MobileCard it was given, got "
                . get_debug_type($card) . '.',
            );
        }

        $this->relationCards[$key] = $card;

        return $this;
    }

    public function getRelationCard(string $key): ?MobileCard
    {
        return $this->relationCards[$key] ?? null;
    }

    /**
     * Every key `relationCard()` was called with — what `RelationDiscovery`
     * checks against the relations the resource actually declares.
     *
     * @return list<string>
     */
    public function getRelationCardKeys(): array
    {
        return array_keys($this->relationCards);
    }

    /** @param list<string> $columns */
    public function searchable(array $columns): self
    {
        $this->searchable = array_values($columns);

        return $this;
    }

    /** @param array<string, string> $labels sort key => human label */
    public function sorts(array $labels): self
    {
        $this->sorts = $labels;

        // Calling sorts() after defaultSort() must not leave a default that
        // points at nothing: sortsToArray() would then emit no `default` flag
        // at all and the list would silently lose its ordering, while
        // applySort() still ordered by the vanished key.
        if ($this->defaultSortKey !== null && ! array_key_exists($this->defaultSortKey, $labels)) {
            $this->defaultSortKey = null;
            $this->defaultSortDirection = 'asc';
        }

        return $this;
    }

    public function defaultSort(string $key, string $direction = 'asc'): self
    {
        if (! array_key_exists($key, $this->sorts)) {
            throw new InvalidArgumentException(
                "defaultSort('{$key}') is not one of the declared sorts. "
                . 'Call sorts() with this key first.',
            );
        }

        // Normalised, and rejected when it is neither. The server was
        // forgiving — applySort() lowercases — so only the client broke:
        // `direction: "DESC"` fails the Dart parser's closed enum and throws
        // on the *whole* panel document, killing every screen over a
        // capitalisation.
        $direction = strtolower($direction);

        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException(
                "defaultSort('{$key}', '{$direction}') must be 'asc' or 'desc'.",
            );
        }

        $this->defaultSortKey = $key;
        $this->defaultSortDirection = $direction;

        return $this;
    }

    /**
     * Names of record actions the resource's own `table()` defines, opted in
     * for mobile. The package never constructs an action: this says WHICH,
     * Filament says WHAT. A name that resolves nowhere — or resolves to an
     * action carrying a form — is omitted from the wire and reported by
     * `filament-mobile:doctor`.
     *
     * @param  list<string>  $names
     */
    public function actions(array $names): self
    {
        $this->actions = array_values(array_unique($names));

        return $this;
    }

    public function getCard(): MobileCard
    {
        return $this->card;
    }

    /** @return list<string> */
    public function getSearchable(): array
    {
        return $this->searchable;
    }

    /** @return array<string, string> */
    public function getSorts(): array
    {
        return $this->sorts;
    }

    public function getDefaultSortKey(): ?string
    {
        return $this->defaultSortKey;
    }

    public function getDefaultSortDirection(): string
    {
        return $this->defaultSortDirection;
    }

    /** @return list<string> */
    public function getActions(): array
    {
        return $this->actions;
    }

    /**
     * Contract §5.2 `sorts` shape. The `default` key is present only on the
     * flagged sort — the Dart parser reads a missing `default` as false.
     *
     * @return list<array<string, mixed>>
     */
    public function sortsToArray(): array
    {
        $sorts = [];

        foreach ($this->sorts as $key => $label) {
            $isDefault = $key === $this->defaultSortKey;

            $sort = [
                'key' => $key,
                'label' => $label,
                'direction' => $isDefault ? $this->defaultSortDirection : 'asc',
            ];

            if ($isDefault) {
                $sort['default'] = true;
            }

            $sorts[] = $sort;
        }

        return $sorts;
    }
}
