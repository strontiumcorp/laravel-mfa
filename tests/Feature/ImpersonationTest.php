<?php

use Illuminate\Support\Facades\Auth;
use StrontiumCorp\LaravelMfa\Exceptions\ImpersonationNotAllowed;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaAuditLog;

beforeEach(function () {
    [$this->admin] = $this->userWithFactor(attributes: ['is_admin' => true]);
    [$this->target] = $this->userWithFactor();
});

describe('per-request setUser impersonation (clone-voice / podcast-flow)', function () {
    it('works unchanged because the session still holds the verified admin', function () {
        $this->actingAsMfaVerified($this->admin)
            ->withSession(['impersonated_id' => $this->target->id])
            ->get('/impersonating')
            ->assertOk()
            ->assertSee('as:'.$this->target->id);
    });
});

describe('login-swap impersonation (artistly)', function () {
    it('challenges for the target\'s MFA without the grant', function () {
        $this->actingAsMfaVerified($this->admin)->post("/admin/switch/{$this->target->id}");

        $this->freshGuards()->get('/dashboard')->assertRedirect(route('mfa.challenge'));
    });

    it('lets a verified admin in with the grant, and back out again', function () {
        $this->actingAsMfaVerified($this->admin)->post("/admin/switch/{$this->target->id}", ['grant' => 1]);

        $this->freshGuards()->get('/dashboard')->assertOk()->assertSee('dashboard:'.$this->target->id);

        $this->post('/admin/exit');
        $this->freshGuards()->get('/dashboard')->assertOk()->assertSee('dashboard:'.$this->admin->id);

        expect(MfaAuditLog::where('event', 'impersonation_granted')->sole()->context)
            ->toMatchArray(['impersonator_id' => $this->admin->id]);
    });

    it('refuses the grant when the admin has not passed MFA', function () {
        $this->loginWithSession($this->admin);

        Mfa::grantForImpersonation($this->admin, $this->target, request()->setLaravelSession(session()->driver()));
    })->throws(ImpersonationNotAllowed::class);

    it('allows the grant for admins who have no MFA at all (not enforced)', function () {
        $plainAdmin = $this->makeUser(['is_admin' => true]);
        $this->loginWithSession($plainAdmin);
        $request = request()->setLaravelSession(session()->driver());

        Mfa::grantForImpersonation($plainAdmin, $this->target, $request);
        Auth::loginUsingId($this->target->id);

        $this->freshGuards()->get('/dashboard')->assertOk();
    });
});
