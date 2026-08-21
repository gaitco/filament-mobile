<?php

declare(strict_types=1);

namespace Gait\MobileCore;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use ReflectionMethod;

/**
 * Filament's own authorization semantics, shared by the panel document and the
 * endpoints so there is exactly one answer to "may this user see this".
 *
 * Two known, deliberate deltas from Filament's own
 * `Filament\get_authorization_response()` (vendor/filament/filament/src/helpers.php).
 * Both are recorded here rather than closed, because closing either changes
 * behaviour nobody has asked for:
 *
 * 1. **Resource-level escape hatches are not consulted.** Filament's helper
 *    honours `Resource::skipAuthorization()`, `checkPolicyExistence(false)`
 *    and `Filament::isAuthorizationStrict()` (which *throws* on an
 *    undefined ability instead of allowing). This class always takes the
 *    checked, non-strict path. A resource that turned authorization off for
 *    the web panel is therefore still policy-checked on the phone — stricter,
 *    never looser, which is the safe direction.
 * 2. **A record ability asked at class level reports capability, not a Gate
 *    answer.** Filament's helper would hand the class string to
 *    `Gate::inspect()`, which drops it and fatals with an ArgumentCountError
 *    against a policy typed `view(User $user, Post $post)`. `allows()` returns
 *    true instead and the real answer travels per record via
 *    `allowsRecord()`. See PanelSchemaBuilder for why the split exists.
 */
final class Authorizer
{
    /**
     * Not a raw Gate check: a model with no policy — or a policy without this
     * method — is permitted, which is what the web panel does. A raw
     * `Gate::allows()` would deny every ability nobody defined and hand an
     * empty panel to the majority of apps, which have no policies at all.
     *
     * @param  class-string  $model
     */
    public static function allows(?Authenticatable $user, string $ability, string $model): bool
    {
        $policy = Gate::getPolicyFor($model);

        if ($policy === null || ! method_exists($policy, $ability)) {
            // Filament's no-policy path is not an unconditional allow: it
            // still runs the Gate's before-callbacks (helpers.php), so an app
            // whose authorization *is* a `Gate::before` hook — filament-shield,
            // a spatie-permission super-admin gate, a plain before() in a
            // service provider — denies here exactly as the web panel does.
            // Only a genuinely undefined ability (raw() resolving to null) is
            // allowed. Mobile must never be looser than web.
            $result = Gate::forUser($user)->raw($ability, [$model]);

            return $result instanceof Response ? $result->allowed() : $result !== false;
        }

        // A record ability — `view(User $user, Post $post)` — has no answer at
        // the resource level: Laravel drops the class-string argument before
        // calling the policy (Gate::callPolicyMethod), so asking would be an
        // ArgumentCountError, not a "no". It reports the capability, and is
        // decided per record by the record endpoint against the real record.
        if ((new ReflectionMethod($policy, $ability))->getNumberOfRequiredParameters() > 1) {
            return true;
        }

        return Gate::forUser($user)->allows($ability, $model);
    }

    /**
     * The record-level answer, and deliberately NOT a call to allows().
     *
     * allows() takes a class string, and a policy typed `view(User $user, Post
     * $post)` cannot be asked a class-level question at all — Laravel drops the
     * argument and the call would be an ArgumentCountError — so it reports the
     * capability instead. Routing this method through that shortcut would
     * report `delete: true` for every record ever served, which is precisely
     * the lie the per-record block exists to stop telling.
     *
     * A real instance has none of that problem: it satisfies the policy's
     * signature, so the policy simply runs. The only semantics shared with
     * allows() is the no-policy path — raw() resolving to null means nobody
     * defined the ability, which stays an allow (Filament's behaviour), while
     * a Gate::before deny still denies.
     *
     * The method_exists() guard is load-bearing here, and is the one thing
     * allows() gets for free: there, the `$result !== false` reading of null
     * is only ever reached on the branch where no policy method exists, so
     * null can only mean "nobody defined this". Here the policy always runs,
     * and an *untyped* policy method that falls off the end also returns null
     * — legal PHP and common in real policies. Reading that as an allow would
     * make mobile looser than the web panel, which Gate::check() denies. So a
     * method that exists is asked through allows() (null denies, and a
     * Gate::before Response still routes correctly), and only a genuinely
     * absent one falls through to the permissive raw() reading.
     */
    public static function allowsRecord(?Authenticatable $user, string $ability, Model $record): bool
    {
        $policy = Gate::getPolicyFor($record);

        if ($policy === null || ! method_exists($policy, $ability)) {
            $result = Gate::forUser($user)->raw($ability, [$record]);

            return $result instanceof Response ? $result->allowed() : $result !== false;
        }

        return Gate::forUser($user)->allows($ability, $record);
    }
}
