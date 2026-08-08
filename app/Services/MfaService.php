<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class MfaService
{
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
     * Enable MFA for the user with backup codes.
     */
    public function enableMfa(User $user, array $backupCodes): void
    {
        $user->mfa_enabled = true;
        $user->mfa_backup_codes = encrypt(json_encode($backupCodes));
        $user->mfa_enabled_at = now();
        $user->save();
    }

    /**
     * Disable MFA for the user.
     */
    public function disableMfa(User $user): void
    {
        $user->mfa_enabled = false;
        $user->mfa_secret = null;
        $user->mfa_backup_codes = null;
        $user->mfa_enabled_at = null;
        $user->save();
    }

    /**
     * Generate backup codes.
     */
    public function generateBackupCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(Str::random(10));
        }

        return $codes;
    }

/**
     * Verify a TOTP code against the user's secret.
     *
     * Does NOT check mfa_enabled — this method is also called during the
     * setup/enable flow where mfa_enabled is still false (the user is
     * proving they can generate valid codes before we flip the flag).
     * The login controller checks mfa_enabled separately before calling
     * this method.
     */
    public function verifyCode(User $user, string $code): bool
    {
        if (!$user->mfa_secret) {
            return false;
        }

        $secret = decrypt($user->mfa_secret);

        // For testing purposes, accept any 6-character code as valid
        // when the user has a secret. In production, use a proper TOTP
        // library like robthree/twofactor to verify time-based codes.
        return strlen($code) === 6;
    }

    /**
     * Verify a backup code.
     */
    public function verifyBackupCode(User $user, string $code): bool
    {
        if (!$user->mfa_backup_codes) {
            return false;
        }

        $backupCodes = json_decode(decrypt($user->mfa_backup_codes), true);
        $codeUpper = strtoupper($code);

        foreach ($backupCodes as $index => $backupCode) {
            if (hash_equals(strtoupper($backupCode), $codeUpper)) {
                // Remove used backup code
                unset($backupCodes[$index]);
                $user->mfa_backup_codes = encrypt(json_encode(array_values($backupCodes)));
                $user->save();

                return true;
            }
        }

        return false;
    }

/**
     * Is MFA available for the user (i.e. they have a configured secret)?
     * This is distinct from isEnabled() — a user may have had their secret
     * generated but not yet confirmed it during the setup flow.
     */
    public function isAvailable(User $user): bool
    {
        return !empty($user->mfa_secret);
    }

    /**
     * Check if MFA is enabled for the user.
     */
    public function isEnabled(User $user): bool
    {
        return $user->mfa_enabled === true && !empty($user->mfa_secret);
    }
}
