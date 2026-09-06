<?php

namespace App\Services\Platform;

use App\Models\PlatformSetting;
use InvalidArgumentException;

/**
 * Platform-level operator settings (PrimeBill itself — not tenant ISP
 * settings).
 *
 * Every setting in the schema is genuinely consumed somewhere in the platform
 * console; there are no decorative keys. `all()` merges persisted values over
 * documented defaults so unset keys still render truthfully. Values are cast
 * by declared type on read.
 */
class PlatformSettingsService
{
    public const SCHEMA = [
        'security' => [
            'failed_login_threshold' => [
                'label'       => 'Failed-login threshold',
                'type'        => 'int',
                'default'     => 5,
                'min'         => 1,
                'max'         => 100,
                'description' => 'IPs with more logged-in failures than this within the suspicious window are flagged in the Security Center.',
            ],
            'suspicious_window_days' => [
                'label'       => 'Suspicious window (days)',
                'type'        => 'int',
                'default'     => 7,
                'min'         => 1,
                'max'         => 90,
                'description' => 'How far back suspected brute-force activity is scanned.',
            ],
        ],
        'billing' => [
            'invoice_prefix' => [
                'label'       => 'Invoice prefix',
                'type'        => 'string',
                'default'     => 'PB-INV',
                'max'         => 20,
                'description' => 'Prefix used when numbering PlatformInvoices generated for tenant subscriptions.',
            ],
            'payment_terms_days' => [
                'label'       => 'Payment terms (days)',
                'type'        => 'int',
                'default'     => 14,
                'min'         => 1,
                'max'         => 120,
                'description' => 'Due date for newly generated platform invoices (now + N days).',
            ],
        ],
    ];

    public const GROUPS = ['security' => 'Security', 'billing' => 'Billing'];

    /**
     * Grouped settings with persisted values merged over defaults.
     *
     * Shape: group => key => { key, value, label, type, description, default }
     */
    public function all(): array
    {
        $stored = PlatformSetting::pluck('value', 'key')->toArray();
        $out = [];

        foreach (self::SCHEMA as $group => $keys) {
            foreach ($keys as $key => $meta) {
                $raw = $stored[$key] ?? $meta['default'];

                $out[$group][$key] = [
                    'key'         => $key,
                    'value'       => $this->cast($key, $raw),
                    'label'       => $meta['label'],
                    'type'        => $meta['type'],
                    'description' => $meta['description'],
                    'default'     => $meta['default'],
                    'is_default'  => ! array_key_exists($key, $stored),
                ];
            }
        }

        return $out;
    }

    /**
     * Read one setting (persisted value, else schema default), cast by type.
     */
    public function get(string $key): mixed
    {
        $this->assertKnown($key);

        $setting = PlatformSetting::where('key', $key)->first();

        return $this->cast($key, $setting?->value ?? self::SCHEMA[$this->groupFor($key)][$key]['default']);
    }
    /**
     * Persist one setting, validating against the schema.
     */
    public function set(string $key, mixed $value): void
    {
        $this->assertKnown($key);

        $meta = self::SCHEMA[$this->groupFor($key)][$key];
        $normalised = $this->cast($key, $value);

        if ($meta['type'] === 'int') {
            $min = $meta['min'] ?? 1;
            $max = $meta['max'] ?? PHP_INT_MAX;
            if ($normalised < $min || $normalised > $max) {
                throw new InvalidArgumentException("{$key} must be between {$min} and {$max}.");
            }
        }

        if ($meta['type'] === 'string' && strlen((string) $normalised) > ($meta['max'] ?? 255)) {
            throw new InvalidArgumentException("{$key} exceeds the maximum length.");
        }

        PlatformSetting::updateOrCreate(
            ['key' => $key],
            ['value' => (string) $normalised, 'group' => $this->groupFor($key)]
        );
    }

    /**
     * Bulk update from a UI form. Unknown keys are rejected loudly rather than
     * silently persisted — the schema is the source of truth.
     *
     * @param  array<string, mixed>  $values
     * @return array<string>  keys persisted
     */
    public function bulkUpdate(array $values): array
    {
        $unknown = array_diff(array_keys($values), array_keys(array_merge(...array_values(self::SCHEMA))));

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown platform settings: '.implode(', ', $unknown).'.');
        }

        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }

        return array_keys($values);
    }

    public function known(string $key): bool
    {
        foreach (self::SCHEMA as $group => $keys) {
            if (array_key_exists($key, $keys)) {
                return true;
            }
        }

        return false;
    }

    protected function cast(string $key, mixed $value): mixed
    {
        $type = self::SCHEMA[$this->groupFor($key)][$key]['type'];

        return match ($type) {
            'int'    => (int) $value,
            'string' => (string) $value,
            default  => $value,
        };
    }

    protected function groupFor(string $key): string
    {
        foreach (self::SCHEMA as $group => $keys) {
            if (array_key_exists($key, $keys)) {
                return $group;
            }
        }

        throw new InvalidArgumentException("Unknown platform setting: {$key}.");
    }

    protected function assertKnown(string $key): void
    {
        if (! $this->known($key)) {
            throw new InvalidArgumentException("Unknown platform setting: {$key}.");
        }
    }
}