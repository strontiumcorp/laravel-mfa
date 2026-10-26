<?php

use Illuminate\Support\Facades\DB;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Models\MfaTrustedBrowser;
use StrontiumCorp\LaravelMfa\Support\OtpStore;
use StrontiumCorp\LaravelMfa\Support\TrustedBrowsers;

/*
 * SDAP-791 ("trust this browser for 30 days" asked again the next day): on a
 * server with explicit_defaults_for_timestamp off, a bare NOT NULL
 * `timestamp('expires_at')` became ON UPDATE CURRENT_TIMESTAMP, so any update
 * of the row moved its expiry to now. SQLite never shows it.
 */

it('keeps a trusted browser trusted on every new session, the next days too', function () {
    [$user, $factor] = $this->userWithFactor();
    $response = $this->loginWithSession($user)
        ->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor), 'remember' => true])
        ->assertOk();
    $name = app(TrustedBrowsers::class)->cookieName('web', $user);
    // As the browser holds it (encrypted, when EncryptCookies runs).
    $cookie = collect($response->headers->getCookies())->sole(fn ($c) => $c->getName() === $name)->getValue();
    $expiresAt = MfaTrustedBrowser::query()->sole()->expires_at->getTimestamp();

    // Each day a new session (the old one expired) signs in with the password: the cookie skips the code.
    foreach ([1, 2, 3] as $day) {
        $this->travel(1)->day();
        $this->flushSession();
        $this->app['cookie']->flushQueuedCookies();
        $this->freshGuards()->loginWithSession($user)
            ->withUnencryptedCookies([$name => $cookie])
            ->get('/dashboard')
            ->assertOk();

        expect(MfaTrustedBrowser::query()->sole())
            ->expires_at->getTimestamp()->toBe($expiresAt, "day {$day}: using the browser moved its expiry")
            ->last_used_at->not->toBeNull();
    }
});

it('keeps an emailed code valid after a wrong guess', function () {
    [, $factor] = $this->userWithFactor(FactorType::Email);
    $store = app(OtpStore::class);
    $options = ['length' => 6, 'ttl' => 600, 'max_attempts' => 5, 'resend_cooldown' => 0];
    $code = $store->issue($factor, $options)['code'];

    $store->verify($factor, $code === '000000' ? '111111' : '000000', $options);
    $this->travel(1)->minute();

    expect($store->verify($factor, $code, $options)->failed())->toBeFalse();
});

it('gives no expiry column an automatic update', function (string $table) {
    $extra = DB::selectOne(
        'select extra from information_schema.columns where table_schema = database() and table_name = ? and column_name = ?',
        [config("mfa.tables.{$table}"), 'expires_at'],
    )->extra;

    expect(strtolower($extra))->not->toContain('on update');
})->with(['trusted_browsers', 'otp_codes']);

it('fails mfa:doctor while an expiry column still updates itself', function () {
    $this->artisan('mfa:doctor')->expectsOutputToContain("[mfa_trusted_browsers.expires_at] doesn't update itself");
    DB::statement('alter table mfa_trusted_browsers modify expires_at timestamp not null default current_timestamp on update current_timestamp');

    try {
        $this->artisan('mfa:doctor')->assertFailed()->expectsOutputToContain('the expiry moved to "now" on every update');
    } finally {
        // DDL commits: put the column back for the tests that run after this one.
        DB::statement('alter table mfa_trusted_browsers modify expires_at timestamp not null default current_timestamp');
    }
});
