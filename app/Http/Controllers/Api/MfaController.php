<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MfaService;
use App\Models\User;
use App\Models\SystemLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class MfaController extends Controller
{
    public function __construct(
        private MfaService $mfaService
    ) {}

    /**
     * Generate MFA secret and return QR code data.
     */
    public function generate(Request $request): JsonResponse
    {
        $user = $request->user();
        $secret = $this->mfaService->generateSecret($user);

        // In production, generate a proper TOTP QR code using a library like bacon/bacon-qr-code
        $qrCodeUrl = "otpauth://totap/PrimeBill:" . urlencode($user->email) . "?secret={$secret}&issuer=PrimeBill";

        return response()->json([
            'secret' => $secret,
            'qr_code_url' => $qrCodeUrl,
        ]);
    }

    /**
     * Enable MFA with verification code.
     */
    public function enable(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $user = $request->user();
        $code = $request->input('code');

        if (!$this->mfaService->verifyCode($user, $code)) {
            return response()->json(['message' => 'Invalid verification code'], 422);
        }

        $backupCodes = $this->mfaService->generateBackupCodes();
        $this->mfaService->enableMfa($user, $backupCodes);

        // Log security event
        SystemLog::create([
            'user_id' => $user->id,
            'action' => 'mfa_enabled',
            'model' => 'User',
            'model_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'MFA enabled successfully',
            'backup_codes' => $backupCodes,
        ]);
    }

    /**
     * Disable MFA (requires password confirmation).
     */
    public function disable(Request $request): JsonResponse
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $user = $request->user();

        if (!Hash::check($request->input('password'), $user->password)) {
            return response()->json(['message' => 'Invalid password'], 422);
        }

        $this->mfaService->disableMfa($user);

        SystemLog::create([
            'user_id' => $user->id,
            'action' => 'mfa_disabled',
            'model' => 'User',
            'model_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['message' => 'MFA disabled successfully']);
    }

    /**
     * Verify MFA code during login challenge.
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $user = $request->user();
        $code = $request->input('code');

        $verified = $this->mfaService->verifyCode($user, $code)
            || $this->mfaService->verifyBackupCode($user, $code);

        if (!$verified) {
            SystemLog::create([
                'user_id' => $user->id,
                'action' => 'mfa_verify_failed',
                'model' => 'User',
                'model_id' => $user->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json(['message' => 'Invalid verification code'], 422);
        }

        // Issue MFA-verified token with scope
        $token = $user->createToken('mfa-verified', ['mfa-verified'])->plainTextToken;

        SystemLog::create([
            'user_id' => $user->id,
            'action' => 'mfa_verified',
            'model' => 'User',
            'model_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'token' => $token,
            'user' => $user,
        ]);
    }

    /**
     * MFA challenge during login — the frontend authenticates with the
     * short-lived `mfa_token` returned by POST /api/auth/login, sends the
     * TOTP/backup code here, and on success receives the full admin token.
     */
    public function challenge(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $user = $request->user();
        $code = $request->input('code');

        // Must be using the mfa-pending token (created during login).
        // Resolve the real PersonalAccessToken from the bearer token so we
        // analyse its actual abilities. currentAccessToken() may return a
        // TransientToken in testing contexts, so query the table directly.
        $token = $user->currentAccessToken();
        if ($token instanceof \Laravel\Sanctum\TransientToken || $token === null) {
            // Extract the plaintext part (after "id|") and resolve the real
            // token so we can inspect its abilities.
            $bearer = (string) $request->bearerToken();
            $pos = strpos($bearer, '|');
            $plain = $pos !== false ? substr($bearer, $pos + 1) : $bearer;
            $token = \Laravel\Sanctum\PersonalAccessToken::findToken($plain);
        }
        if (!$token || !in_array('mfa-pending', $token->abilities ?? [], true)) {
            return response()->json(['message' => 'MFA challenge token required'], 403);
        }

        $verified = $this->mfaService->verifyCode($user, $code)
            || $this->mfaService->verifyBackupCode($user, $code);

        if (!$verified) {
            SystemLog::create([
                'user_id' => $user->id,
                'action' => 'mfa_challenge_failed',
                'model' => 'User',
                'model_id' => $user->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json(['message' => 'Invalid verification code'], 422);
        }

        // Issue the real session token
        $sessionToken = $user->createToken('auth_token', ['admin'])->plainTextToken;

        // Consume the pending MFA token so it can't be replayed
        $token->delete();

        // Record the successful login in login history
        app(\App\Services\LoginHistoryService::class)->recordLogin($user, $request);

        SystemLog::create([
            'user_id' => $user->id,
            'action' => 'mfa_challenge_passed',
            'model' => 'User',
            'model_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'token' => $sessionToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
                'is_platform_admin' => (bool) $user->is_platform_admin,
            ],
        ]);
    }

    /**
     * Get MFA status.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'enabled' => $this->mfaService->isEnabled($user),
            'enabled_at' => $user->mfa_enabled_at,
        ]);
    }

    /**
     * Regenerate backup codes.
     */
    public function regenerateBackupCodes(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $user = $request->user();
        $code = $request->input('code');

        if (!$this->mfaService->verifyCode($user, $code)) {
            return response()->json(['message' => 'Invalid verification code'], 422);
        }

        $backupCodes = $this->mfaService->generateBackupCodes();
        $user->mfa_backup_codes = encrypt(json_encode($backupCodes));
        $user->save();

        SystemLog::create([
            'user_id' => $user->id,
            'action' => 'mfa_backup_codes_regenerated',
            'model' => 'User',
            'model_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Backup codes regenerated',
            'backup_codes' => $backupCodes,
        ]);
    }
}
