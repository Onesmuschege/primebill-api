<?php

namespace App\Services\Audit;

use App\Models\SystemLog;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * AuditService - Centralized audit logging for PrimeBill
 *
 * This service provides structured audit logging using the SystemLog model.
 * It supports both manual logging and model event-driven logging.
 *
 * Features:
 * - Automatic model event tracking
 * - User attribution
 * - Tenant awareness
 * - IP address tracking
 * - User agent tracking
 * - Request ID correlation
 * - Changed fields (before/after values)
 * - Event categories
 * - Severity levels
 *
 * When spatie/laravel-activitylog is installed, this service can optionally
 * delegate to it for more advanced features, but it works standalone.
 */
class AuditService
{
    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_CRITICAL = 'critical';

    public const CATEGORY_AUTH = 'auth';
    public const CATEGORY_BILLING = 'billing';
    public const CATEGORY_CLIENT = 'client';
    public const CATEGORY_NETWORK = 'network';
    public const CATEGORY_ADMIN = 'admin';
    public const CATEGORY_SECURITY = 'security';
    public const CATEGORY_SYSTEM = 'system';

    /**
     * Keys whose values are masked in audit log old/new values before they
     * are persisted — never store raw secrets in the audit trail.
     */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'api_key',
        'api_secret',
        'secret',
        'token',
        'key_hash',
        'key_secret',
        'mpesa_passkey',
        'consumer_secret',
        'private_key',
        'auth_token',
        'mfa_secret',
        'mfa_backup_codes',
    ];

    /**
     * Log an audit event
     */
    public function log(
        string $action,
        ?string $model = null,
        ?int $modelId = null,
        array $oldValues = [],
        array $newValues = [],
        array $attributes = []
    ): SystemLog {
        $user = Auth::user();
        $request = request();

        // Determine category and severity from action
        $category = $attributes['category'] ?? $this->determineCategory($action);
        $severity = $attributes['severity'] ?? $this->determineSeverity($action);

        return SystemLog::create([
            'tenant_id'   => Tenant::current()?->id,
            'user_id'     => $user?->id,
            'action'      => $action,
            'model'       => $model,
            'model_id'    => $modelId,
            'old_values'  => !empty($oldValues) ? $this->maskSensitiveData($oldValues) : null,
            'new_values'  => !empty($newValues) ? $this->maskSensitiveData($newValues) : null,
            'ip_address'  => $attributes['ip_address'] ?? $request->ip(),
            'user_agent'  => $attributes['user_agent'] ?? $request->userAgent(),
            'request_id'  => $attributes['request_id'] ?? ($request->header('X-Request-ID') ?? Str::uuid()->toString()),
        ]);
    }

    /**
     * Recursively mask values whose keys appear in the sensitive list.
     * Nested arrays (e.g. a changeset with 'from'/'to' pairs) are handled
     * by recursing into the value while preserving the structure.
     */
    private function maskSensitiveData(array $data): array
    {
        $masked = [];

        foreach ($data as $key => $value) {
            // Mask based on the direct key match first.
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $masked[$key] = '********';
                continue;
            }

            if (is_array($value)) {
                $masked[$key] = $this->maskSensitiveData($value);
                continue;
            }

            // Heuristic: mask anything that looks like a secret even when the
            // key isn't in the list (e.g. 'credentials' => 'abc123...').
            if (is_string($value) && $this->looksLikeSecret($key, $value)) {
                $masked[$key] = '********';
                continue;
            }

            $masked[$key] = $value;
        }

        return $masked;
    }

    /**
     * Heuristic for secret-like values: long high-entropy strings, JWTs,
     * base64 payloads, or values under secret-ish keys.
     */
    private function looksLikeSecret($key, string $value): bool
    {
        $keyLower = is_string($key) ? strtolower($key) : '';

        $secretishKey = str_contains($keyLower, 'secret')
            || str_contains($keyLower, 'password')
            || str_contains($keyLower, 'token')
            || str_contains($keyLower, 'key');

        if ($secretishKey) {
            return true;
        }

        // JWT pattern: header.payload.signature
        if (preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $value)) {
            return true;
        }

        // Long random-looking base64
        if (strlen($value) >= 20 && preg_match('/^[A-Za-z0-9+\/=_-]+$/', $value)) {
            return true;
        }

        return false;
    }

    /**
     * Determine event category from action name
     */
    private function determineCategory(string $action): string
    {
        if (str_starts_with($action, 'auth.')) {
            return self::CATEGORY_AUTH;
        }
        if (str_starts_with($action, 'payment.') || str_starts_with($action, 'billing.') || str_starts_with($action, 'invoice.')) {
            return self::CATEGORY_BILLING;
        }
        if (str_starts_with($action, 'client.')) {
            return self::CATEGORY_CLIENT;
        }
        if (str_starts_with($action, 'network.') || str_starts_with($action, 'account.')) {
            return self::CATEGORY_NETWORK;
        }
        if (str_starts_with($action, 'security.')) {
            return self::CATEGORY_SECURITY;
        }
        if (str_starts_with($action, 'admin.')) {
            return self::CATEGORY_ADMIN;
        }
        return self::CATEGORY_SYSTEM;
    }

    /**
     * Determine severity level from action name
     */
    private function determineSeverity(string $action): string
    {
        if (str_contains($action, '.failed') || str_contains($action, '.error') || str_contains($action, '.denied')) {
            return self::SEVERITY_ERROR;
        }
        if (str_contains($action, '.deleted') || str_contains($action, '.suspended')) {
            return self::SEVERITY_WARNING;
        }
        return self::SEVERITY_INFO;
    }

    /**
     * Log a model create event
     */
    public function created(string $model, int $modelId, array $attributes = []): SystemLog
    {
        return $this->log(
            action: "{$model}.created",
            model: $model,
            modelId: $modelId,
            newValues: $attributes
        );
    }

    /**
     * Log a model update event
     */
    public function updated(string $model, int $modelId, array $oldValues = [], array $newValues = [], array $attributes = []): SystemLog
    {
        return $this->log(
            action: "{$model}.updated",
            model: $model,
            modelId: $modelId,
            oldValues: $oldValues,
            newValues: $newValues
        );
    }

    /**
     * Log a model delete event
     */
    public function deleted(string $model, int $modelId, array $oldValues = [], array $attributes = []): SystemLog
    {
        return $this->log(
            action: "{$model}.deleted",
            model: $model,
            modelId: $modelId,
            oldValues: $oldValues
        );
    }

    /**
     * Log a model restore event (soft delete)
     */
    public function restored(string $model, int $modelId, array $attributes = []): SystemLog
    {
        return $this->log(
            action: "{$model}.restored",
            model: $model,
            modelId: $modelId
        );
    }

    /**
     * Log a custom action
     */
    public function action(string $action, ?string $model = null, ?int $modelId = null, array $data = [], array $attributes = []): SystemLog
    {
        return $this->log(
            action: $action,
            model: $model,
            modelId: $modelId,
            newValues: $data,
            attributes: $attributes
        );
    }

    /**
     * Log authentication events
     */
    public function auth(string $event, ?int $userId = null, array $data = []): SystemLog
    {
        return $this->log(
            action: "auth.{$event}",
            model: 'User',
            modelId: $userId,
            newValues: $data
        );
    }

    /**
     * Log payment events
     */
    public function payment(string $event, int $paymentId, array $data = []): SystemLog
    {
        return $this->log(
            action: "payment.{$event}",
            model: 'Payment',
            modelId: $paymentId,
            newValues: $data
        );
    }

    /**
     * Log billing events
     */
    public function billing(string $event, int $invoiceId, array $data = []): SystemLog
    {
        return $this->log(
            action: "billing.{$event}",
            model: 'Invoice',
            modelId: $invoiceId,
            newValues: $data
        );
    }

    /**
     * Log client events
     */
    public function client(string $event, int $clientId, array $data = []): SystemLog
    {
        return $this->log(
            action: "client.{$event}",
            model: 'Client',
            modelId: $clientId,
            newValues: $data
        );
    }

    /**
     * Log network/provisioning events
     */
    public function network(string $event, ?int $accountId = null, array $data = []): SystemLog
    {
        return $this->log(
            action: "network.{$event}",
            model: $accountId ? 'ClientAccount' : null,
            modelId: $accountId,
            newValues: $data
        );
    }

    /**
     * Log ticket/support events
     */
    public function ticket(string $event, int $ticketId, array $data = []): SystemLog
    {
        return $this->log(
            action: "ticket.{$event}",
            model: 'Ticket',
            modelId: $ticketId,
            newValues: $data
        );
    }

    /**
     * Log admin actions (user management, role changes, etc.)
     */
    public function admin(string $event, ?string $model = null, ?int $modelId = null, array $data = []): SystemLog
    {
        return $this->log(
            action: "admin.{$event}",
            model: $model,
            modelId: $modelId,
            newValues: $data
        );
    }

    /**
     * Log security events (failed login, permission denied, etc.)
     */
    public function security(string $event, ?int $userId = null, array $data = []): SystemLog
    {
        return $this->log(
            action: "security.{$event}",
            model: $userId ? 'User' : null,
            modelId: $userId,
            newValues: $data
        );
    }

    /**
     * Get recent audit logs with filters
     */
    public function getRecent(int $limit = 50, ?string $action = null, ?int $userId = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = SystemLog::with('user')
            ->orderByDesc('created_at')
            ->limit($limit);

        if ($action) {
            $query->where('action', 'like', "{$action}%");
        }

        if ($userId) {
            $query->where('user_id', $userId);
        }

        return $query->get();
    }

    /**
     * Get audit logs for a specific model
     */
    public function getForModel(string $model, int $modelId, int $limit = 100): \Illuminate\Database\Eloquent\Collection
    {
        return SystemLog::with('user')
            ->where('model', $model)
            ->where('model_id', $modelId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Get audit logs for a date range
     */
    public function getForDateRange(\DateTimeInterface $from, \DateTimeInterface $to, ?string $action = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = SystemLog::with('user')
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at');

        if ($action) {
            $query->where('action', 'like', "{$action}%");
        }

        return $query->get();
    }

    /**
     * Clean old audit logs (call from scheduled command)
     */
    public function cleanOldLogs(int $days = 365): int
    {
        return SystemLog::where('created_at', '<', now()->subDays($days))->delete();
    }

    /**
     * Get audit statistics
     */
    public function getStats(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $logs = SystemLog::whereBetween('created_at', [$from, $to]);

        return [
            'total'     => $logs->count(),
            'by_action' => SystemLog::whereBetween('created_at', [$from, $to])
                ->selectRaw('action, COUNT(*) as count')
                ->groupBy('action')
                ->orderByDesc('count')
                ->limit(20)
                ->pluck('count', 'action')
                ->toArray(),
            'by_user'   => SystemLog::whereBetween('created_at', [$from, $to])
                ->selectRaw('user_id, COUNT(*) as count')
                ->whereNotNull('user_id')
                ->groupBy('user_id')
                ->orderByDesc('count')
                ->limit(10)
                ->with('user')
                ->get()
                ->map(fn($log) => [
                    'user_id' => $log->user_id,
                    'name'    => $log->user?->name ?? 'Unknown',
                    'count'   => $log->count,
                ])
                ->toArray(),
        ];
    }
}
