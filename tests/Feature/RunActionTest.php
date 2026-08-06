<?php

declare(strict_types=1);

it('runs an opted-in action and reports its success title', function () {
    $banner = seedBanner('Runnable');

    $this->actingAs(makeUser('admin'))
        ->postJson("/api/mobile-panel/banners/{$banner->id}/actions/approve")
        ->assertOk()
        ->assertJson(['message' => 'Banner approved']);

    expect($banner->fresh()->status)->toBe('approved');
});

it('answers 403 for an action the record may not run', function () {
    // `publish` is hidden for a non-draft. The published list omitted it;
    // a client that POSTs it anyway must be refused, not obeyed — the
    // payload is a hint, resolve() is the gate.
    $banner = seedBanner('NotDraft');

    $this->actingAs(makeUser('admin'))
        ->postJson("/api/mobile-panel/banners/{$banner->id}/actions/publish")
        ->assertForbidden();

    expect($banner->fresh()->status)->toBe('active');
});

it('answers 403 for a form-carrying action', function () {
    $banner = seedBanner('Formy');

    $this->actingAs(makeUser('admin'))
        ->postJson("/api/mobile-panel/banners/{$banner->id}/actions/reject")
        ->assertForbidden();
});

it('answers 403 for an action the resource never opted in', function () {
    $banner = seedBanner('Unopted');

    $this->actingAs(makeUser('admin'))
        ->postJson("/api/mobile-panel/banners/{$banner->id}/actions/ghost")
        ->assertForbidden();
});

it('answers 404 for an unknown resource or record before any authorization', function () {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/nope/1/actions/approve')
        ->assertNotFound();

    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners/999999/actions/approve')
        ->assertNotFound();
});

it('answers 401 for an unauthenticated request', function () {
    $banner = seedBanner('Anon');

    $this->postJson("/api/mobile-panel/banners/{$banner->id}/actions/approve")
        ->assertUnauthorized();
});

it('answers 422 with the failure title when the action halts', function () {
    $banner = seedBanner('Halting');

    $this->actingAs(makeUser('admin'))
        ->postJson("/api/mobile-panel/banners/{$banner->id}/actions/halting")
        ->assertStatus(422)
        ->assertJson(['message' => 'Cannot do that yet']);
});

it('answers 422 with the failure title when the action reports failure without halting', function () {
    // `$action->failure()` and a normal return: the web panel switches on
    // getStatus() after the call and shows the FAILURE notification. A 200
    // with the success title here would tell the phone the opposite of what
    // the web tells the desk.
    $banner = seedBanner('Failing');

    $this->actingAs(makeUser('admin'))
        ->postJson("/api/mobile-panel/banners/{$banner->id}/actions/failing")
        ->assertStatus(422)
        ->assertJson(['message' => 'Could not fail politely']);

    // The 422 is a report, not a rollback: the closure's own mutation ran
    // before failure() and stands, exactly as it does on the web.
    expect($banner->fresh()->status)->toBe('poked');
});

it('answers 200 with no message when the action cancels', function () {
    // The web panel catches Cancel and sends no notification at all — a
    // graceful no-op the user chose, not a fault. 500ing it (or 422ing it)
    // would report a refusal the web never shows.
    $banner = seedBanner('Cancelling');

    $this->actingAs(makeUser('admin'))
        ->postJson("/api/mobile-panel/banners/{$banner->id}/actions/cancelling")
        ->assertOk()
        ->assertJson(['message' => null]);
});

it('lets a throwing action fail loudly rather than reporting a success it cannot vouch for', function () {
    $banner = seedBanner('Exploding');

    $this->withoutExceptionHandling();

    $this->actingAs(makeUser('admin'))
        ->postJson("/api/mobile-panel/banners/{$banner->id}/actions/explode");
})->throws(RuntimeException::class);

it('answers 403 for a record the user may not update-authorize through the action gate', function () {
    // The action's own isAuthorized() is the gate — a policy-denied action
    // must be refused even for a record the user may view.
    $banner = seedBanner('Denied');

    $this->actingAs(makeUser('viewer'))
        ->postJson("/api/mobile-panel/banners/{$banner->id}/actions/approve")
        ->assertForbidden();
});

it('answers the same status for a real and a nonexistent record when the resource itself is denied', function () {
    // Banner carries no policy, so it cannot exercise this: every test above
    // reaches the record lookup regardless of who's asking. PostPolicy is
    // the one fixture policy that denies `viewAny` outright (for a user
    // named 'restricted'), which is what makes it the case that matters —
    // a caller who may not reach the resource AT ALL must get 403 for both
    // a real id and a fake one. Getting 403 for the real id and 404 for the
    // fake one would let that caller enumerate which ids exist on a
    // resource they can't see, purely from the status code — the record
    // lookup must never run before this gate does.
    $post = seedPost('Gated');
    $user = makeUser('restricted');

    $this->actingAs($user)
        ->postJson("/api/mobile-panel/posts/{$post->id}/actions/anything")
        ->assertForbidden();

    $this->actingAs($user)
        ->postJson('/api/mobile-panel/posts/999999/actions/anything')
        ->assertForbidden();
});
