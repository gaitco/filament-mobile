<?php

declare(strict_types=1);

namespace Gait\MobileCore;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The query-parameter machinery every paginated list endpoint shares: the
 * resource index (`MobilePanelController::index`) and, since P11, the
 * relation endpoint (`RelationController::__invoke`). One mechanism, not two
 * — the call sites differ only in where the searchable columns and the
 * sorts/default triple come from (the resource's declarations, or one
 * relation's).
 *
 * Everything here runs AFTER the endpoint's full gate sequence: a 422 for a
 * bad parameter must never leak whether a resource, relation or record
 * exists to a caller the gates would have refused.
 */
final class ListQuery
{
    /**
     * The one place a query parameter becomes a PHP value. `?sort[]=x` binds an
     * array, and every scalar string function below would fatal on it — a 500
     * for input a client fully controls. Non-strings are rejected with the same
     * 422 the sort path already promises, and an empty value is read as absent
     * (`?sort=` is an ordinary way to say "no sort", not an unknown key).
     */
    public static function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        abort_if($value !== null && ! is_string($value), 422, "[{$key}] must be a string.");

        return ($value === null || $value === '') ? null : $value;
    }

    /** @param list<string> $columns */
    public static function applySearch(Builder $query, ?string $search, array $columns): void
    {
        if ($search === null || $columns === []) {
            return;
        }

        // `%` and `_` are LIKE metacharacters: unescaped, `?search=%` matches
        // every row and the filter stops filtering. The ESCAPE clause is
        // explicit because SQLite — unlike MySQL and Postgres — has no default
        // escape character.
        //
        // The escape character is `!`, not the conventional backslash. MySQL
        // treats a backslash as an escape *inside string literals*, so the
        // clause `escape '\'` is an unterminated literal and every search
        // request is a 1064 syntax error — which SQLite, where the package's
        // tests run, parses happily as a literal backslash. Writing `escape
        // '\\'` would fix MySQL and break SQLite, which reads it as a
        // two-character escape string and rejects it. `!` needs no escaping in
        // a string literal on any of the three drivers, so one clause is
        // correct everywhere.
        $term = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';

        // One where group, not a chain of top-level orWheres: a bare orWhere
        // would escape any scope the resource query already applied and widen
        // the result set past it. On a relation query that scope is the
        // relationship's own constraint, and the group must not escape it
        // either — the same rule, one nesting level down.
        // ponytail: plain columns only. A dotted searchable() would need
        // whereHas — add it the day a resource declares one.
        $query->where(function (Builder $group) use ($term, $columns): void {
            foreach ($columns as $column) {
                $group->orWhereRaw(
                    $group->getGrammar()->wrap($column) . " like ? escape '!'",
                    [$term],
                );
            }
        });
    }

    /**
     * The resolved declarations, not the MobileResource: the resource index
     * passes the resource's own triple, the relation endpoint passes one
     * relation's, and this method cannot tell the difference.
     *
     * @param  array<string, string>  $sorts  sort key => label
     */
    public static function applySort(
        Builder $query,
        Request $request,
        array $sorts,
        ?string $defaultSortKey,
        string $defaultSortDirection,
    ): void {
        $requested = self::stringQuery($request, 'sort');
        $key = $requested ?? $defaultSortKey;

        if ($key === null) {
            return;
        }

        // An undeclared key is an error, never a silently ignored parameter:
        // a client sorting by a column that does not exist must find out.
        // This holds even when NOTHING is declared — the parameter claimed a
        // capability, the search parameter did not.
        abort_unless(array_key_exists($key, $sorts), 422, "Unknown sort key [{$key}].");

        $direction = self::stringQuery($request, 'direction')
            ?? ($key === $defaultSortKey ? $defaultSortDirection : 'asc');

        $query->orderBy($key, strtolower($direction) === 'desc' ? 'desc' : 'asc');
    }
}
