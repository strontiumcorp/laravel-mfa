<?php

namespace StrontiumCorp\LaravelMfa\Factors;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;
use StrontiumCorp\LaravelMfa\Contracts\Factor;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Enums\FailureReason;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Support\VerificationResult;

final class TotpFactor implements Factor
{
    private const STEP_SECONDS = 30;

    /** @param array{issuer?: string, issuer_environment?: bool, window?: int} $config */
    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly array $config,
        private readonly string $environment = 'production',
    ) {}

    /**
     * The name authenticator apps show: factors.totp.issuer, with the
     * environment in brackets outside production ("Acme (staging)").
     */
    public function issuer(): string
    {
        // Equivalent mutant(s): the issuer is a string in config.
        $issuer = (string) ($this->config['issuer'] ?? 'Laravel'); // @pest-mutate-ignore: RemoveStringCast

        // The default (on) is in config/mfa.php; the deep merge always provides the key.
        return ! empty($this->config['issuer_environment']) && $this->environment !== '' && $this->environment !== 'production'
            ? "{$issuer} ({$this->environment})"
            : $issuer;
    }

    public function type(): FactorType
    {
        return FactorType::Totp;
    }

    public function enroll(MultiFactorAuthenticatable $user, array $input): array
    {
        $user->mfaFactors()->where('type', FactorType::Totp)->whereNull('confirmed_at')->delete();

        /** @var MfaFactor $factor */
        $factor = $user->mfaFactors()->create([
            'type' => FactorType::Totp,
            'label' => $input['label'] ?? 'Authenticator app',
            'secret' => $this->google2fa->generateSecretKey(32),
        ]);

        return ['factor' => $factor, 'setup' => $this->setupData($factor, $user)];
    }

    /**
     * QR code + manual-entry secret for an unconfirmed factor. Computed on
     * demand from the encrypted secret so it never sits in the session.
     *
     * @return array{secret: string, otpauth_url: string, qr_svg: string}
     */
    public function setupData(MfaFactor $factor, MultiFactorAuthenticatable $user): array
    {
        $url = $this->google2fa->getQRCodeUrl(
            $this->issuer(),
            $user->getMfaLabel(),
            // Equivalent mutant(s): the secret is always a string for TOTP factors.
            (string) $factor->secret, // @pest-mutate-ignore: RemoveStringCast
        );

        // Equivalent mutant(s): QR pixel size and margin are cosmetic.
        $svg = (new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd)))->writeString($url); // @pest-mutate-ignore: IncrementInteger,DecrementInteger

        return [
            // Equivalent mutant(s): the secret is always a string for TOTP factors.
            'secret' => (string) $factor->secret, // @pest-mutate-ignore: RemoveStringCast
            'otpauth_url' => $url,
            // Equivalent mutant(s): only strips the XML prolog line; trim/offset differences leave a valid <svg>.
            'qr_svg' => trim(substr($svg, (int) strpos($svg, "\n") + 1)), // @pest-mutate-ignore: DecrementInteger,UnwrapTrim,RemoveIntegerCast
        ];
    }

    public function challenge(MfaFactor $factor): VerificationResult
    {
        return VerificationResult::success();
    }

    public function verify(MfaFactor $factor, string $code): VerificationResult
    {
        // Equivalent mutant(s): preg_replace only returns null on a regex error.
        $code = preg_replace('/\s+/', '', $code) ?? ''; // @pest-mutate-ignore: EmptyStringToNotEmpty

        if (preg_match('/^\d{6}$/', $code) !== 1 || ! $factor->secret) {
            return VerificationResult::failure(FailureReason::InvalidCode);
        }

        // Equivalent mutant(s): config always provides an int window; dropping the config is tested.
        $window = (int) ($this->config['window'] ?? 1); // @pest-mutate-ignore: RemoveIntegerCast,IncrementInteger,DecrementInteger
        $now = $this->currentStep();

        // Equivalent mutant(s): the compare-and-set below rejects replays too; this only saves a write.
        $step = $this->google2fa->verifyKeyNewer($factor->secret, $code, (int) ($factor->last_totp_timestep ?? 0), $window, $now); // @pest-mutate-ignore: RemoveIntegerCast,CoalesceRemoveLeft,IncrementInteger,DecrementInteger

        // Equivalent mutant(s): verifyKeyNewer() only returns true when oldTimestamp is null, and we always pass an int.
        if ($step === false || $step === true) { // @pest-mutate-ignore: TrueToFalse
            return VerificationResult::failure(
                $this->google2fa->verifyKey($factor->secret, $code, $window, $now) ? FailureReason::Replayed : FailureReason::InvalidCode
            );
        }

        // Compare-and-set: of two concurrent requests with the same code,
        // only one can advance the timestep.
        $updated = MfaFactor::query()
            ->whereKey($factor->getKey())
            ->where(fn ($query) => $query->whereNull('last_totp_timestep')->orWhere('last_totp_timestep', '<', $step))
            ->update(['last_totp_timestep' => $step]);

        if ($updated !== 1) {
            return VerificationResult::failure(FailureReason::Replayed);
        }

        $factor->last_totp_timestep = $step;

        return VerificationResult::success();
    }

    public function currentStep(): int
    {
        return intdiv(now()->getTimestamp(), self::STEP_SECONDS);
    }

    /** Current valid code for a secret. Intended for tests and tooling. */
    public function currentCode(string $secret): string
    {
        return $this->google2fa->oathTotp($secret, $this->currentStep());
    }
}
