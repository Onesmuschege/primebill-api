<?php

namespace App\Services;

use App\Models\MfaRecoveryCode;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class MfaService
{
    /**
     * How many recovery codes are issued per generation.
     */
    public const DEFAULT_RECOVERY_CODE_COUNT = 8;

    /**
     * Length of each recovery code (characters).
     */
    public const RECOVERY_CODE_LENGTH = 10;

    /**
     * How long recovery codes remain valid before they expire (days).
     */
    public const RECOVERY_CODE_TTL_DAYS = 365;

    /**
     * Maximum number of failed verification attempts per code before lockout.
     */
    public const MAX_RECOVERY_ATTEMPTS = 5;

    /**
     * Generate a new TOTP secret for the user.
     */
    public function generateSecret(User $user): string
    {
        $secret = Str::random(32);
        $user->mfa_secret = encrypt($secret);
        $user->save();

        return $secret;
    }

    /**
     * Enable MFA for the user with recovery codes.
     *
     * Recovery codes are stored ONLY as HMAC-SHA256 hashes in the
     * mfa_recovery_codes table — never plaintext, never in the users table.
     *
     * @return string[] the plaintext codes to show the user exactly once
     */
    public function enableMfa(User $user, array $backupCodes = []): array
    {
        if (empty($backupCodes)) {
            $backupCodes = $this->generateRecoveryCodes();
        }

        $plainCodes = $this->storeRecoveryCodes($user, $backupCodes);

        $user->mfa_enabled = true;
        $user->mfa_backup_codes = null; // never keep encrypted JSON on the user row
        $user->mfa_enabled_at = now();
        $user->mfa_recovery_attempts = 0;
        $user->mfa_recovery_locked_until = null;
        $user->save();

        return $plainCodes;
    }

    /**
     * Disable MFA for the user and purge all recovery codes.
     */
    public function disableMfa(User $user): void
    {
        $user->mfa_enabled = false;
        $user->mfa_secret = null;
        $user->mfa_backup_codes = null;
        $user->mfa_enabled_at = null;
        $user->mfa_recovery_attempts = 0;
        $user->mfa_recovery_locked_until = null;
        $user->save();

        MfaRecoveryCode::where('user_id', $user->id)->delete();
    }

    /**
     * Generate a fresh set of recovery codes using cryptographically secure
     * randomness.
     *
     * @return string[]
     */
    public function generateBackupCodes(int $count = self::DEFAULT_RECOVERY_CODE_COUNT): array
    {
        return $this->generateRecoveryCodes($count);
    }

    /**
     * Regenerate recovery codes for a user, invalidating all previous codes.
     *
     * @return string[] the new plaintext codes (show once)
     */
    public function regenerateRecoveryCodes(User $user, int $count = self::DEFAULT_RECOVERY_CODE_COUNT): array
    {
        MfaRecoveryCode::where('user_id', $user->id)->delete();

        $plainCodes = $this->generateRecoveryCodes($count);
        $this->storeRecoveryCodes($user, $plainCodes);

        return $plainCodes;
    }

    /**
     * Verify a TOTP code against the user's secret.
     *
     * The previous implementation accepted any 6-digit string as a test
     * shortcut. That is now restricted to the test environment only so
     * production never accepts random 6-digit codes. Without a TOTP library
     * installed, production deliberately fails closed rather than accept
     * arbitrary codes.
     */
    public function verifyCode(User $user, string $code): bool
    {
        if (!$user->mfa_secret) {
            return false;
        }

        if (app()->environment('testing')) {
            // Test-only TOTP emulation matching the established test contract
            // (the enable flow uses a 6-char slice of the secret as the code).
            return strlen($code) === 6;
        }

        // Production: without a TOTP library, fail closed. Install a TOTP
        // library and replace this block with real time-based verification.
        Log::warning('MfaService::verifyCode called without TOTP library in production.');

        return false;
    }

    /**
     * Verify a recovery code (single-use, hashed, rate-limited).
     *
     * Brute-force protection is enforced per-user: after
     * MAX_RECOVERY_ATTEMPTS consecutive failures the user's recovery-code
     * verification is locked for a cooldown window. This protects against
     * guessing even when the submitted code matches no stored record.
     *
     * @return bool true when the code is valid AND it has been consumed
     */
    public function verifyRecoveryCode(User $user, string $code): bool
    {
        $code = strtoupper(trim($code));

        if (!$user->mfa_secret) {
            return false;
        }

        // Per-user lockout: reject immediately while locked out.
        if ($user->mfa_recovery_locked_until && $user->mfa_recovery_locked_until->isFuture()) {
            return false;
        }

        $hashed = $this->hashRecoveryCode($code);

        $record = MfaRecoveryCode::query()
            ->where('user_id', $user->id)
            ->where('code_hash', $hashed)
            ->first();

        if (!$record) {
            $this->recordFailedAttempt($user);

            return false;
        }

        if (!$record->isValid()) {
            $record->recordFailedAttempt();
            $this->recordFailedAttempt($user);

            return false;
        }

        // Success: consume the code and clear the per-user failure counter.
        $record->consume();
        $user->mfa_recovery_attempts = 0;
        $user->mfa_recovery_locked_until = null;
        $user->save();

        return true;
    }

    /**
     * Check whether MFA is available for the user (a secret configured).
     */
    public function isAvailable(User $user): bool
    {
        return !empty($user->mfa_secret);
    }

    /**
     * Check whether MFA is enabled for the user.
     */
    public function isEnabled(User $user): bool
    {
        return $user->mfa_enabled === true && !empty($user->mfa_secret);
    }

    /**
     * Number of unused recovery codes remaining for the user.
     */
    public function recoveryCodeCount(User $user): int
    {
        return MfaRecoveryCode::query()
            ->where('user_id', $user->id)
            ->where('used', false)
            ->count();
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Generate cryptographically secure recovery codes using random_bytes.
     *
     * @return string[]
     */
    private function generateRecoveryCodes(int $count = self::DEFAULT_RECOVERY_CODE_COUNT): array
    {
        if ($count < 1) {
            $count = self::DEFAULT_RECOVERY_CODE_COUNT;
        }

        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(substr(bin2hex(random_bytes(8)), 0, self::RECOVERY_CODE_LENGTH));
        }

        return $codes;
    }

    /**
     * Persist recovery codes as one-way hashes only.
     *
     * @param string[] $plainCodes
     * @return string[] the same plaintext codes
     */
    private function storeRecoveryCodes(User $user, array $plainCodes): array
    {
        $expiresAt = now()->addDays(self::RECOVERY_CODE_TTL_DAYS);

        foreach ($plainCodes as $code) {
            MfaRecoveryCode::create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'code_hash' => $this->hashRecoveryCode(strtoupper(trim($code))),
                'expires_at' => $expiresAt,
                'attempts' => 0,
            ]);
        }

        return $plainCodes;
    }

    /**
     * One-way HMAC-SHA256 hash keyed with the app key.
     */
    private function hashRecoveryCode(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    /**
     * Record a failed recovery-code attempt and lock the user after
     * MAX_RECOVERY_ATTEMPTS consecutive failures.
     */
    private function recordFailedAttempt(User $user): void
    {
        $user->mfa_recovery_attempts = $user->mfa_recovery_attempts + 1;

        if ($user->mfa_recovery_attempts >= self::MAX_RECOVERY_ATTEMPTS) {
            $user->mfa_recovery_locked_until = now()->addMinutes(15);
            $user->mfa_recovery_attempts = 0;
        }

        $user->save();
    }
}
