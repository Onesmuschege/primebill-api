<?php

namespace App\Services\Radius;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class FreeRadiusAdapter implements RadiusAdapterInterface
{
    protected string $connection;

    public function __construct()
    {
        $this->connection = config('network.radius_connection', 'radius');
    }

    public function createUser(array $data): bool
    {
        if (!$this->tablesExist()) {
            Log::warning('FreeRadiusAdapter: RADIUS tables not found');

            return false;
        }

        $username = $data['username'];
        $password = $data['password'];
        $group    = $data['group'] ?? 'default';

        DB::connection($this->connection)->transaction(function () use ($username, $password, $group, $data) {
            $this->deleteUserRecords($username);

            DB::connection($this->connection)->table('radcheck')->insert([
                'username'  => $username,
                'attribute' => 'Cleartext-Password',
                'op'        => ':=',
                'value'     => $password,
            ]);

            DB::connection($this->connection)->table('radusergroup')->insert([
                'username'  => $username,
                'groupname' => $group,
                'priority'  => 1,
            ]);

            if (!empty($data['rate_limit'])) {
                DB::connection($this->connection)->table('radreply')->insert([
                    'username'  => $username,
                    'attribute' => 'Mikrotik-Rate-Limit',
                    'op'        => '=',
                    'value'     => $data['rate_limit'],
                ]);
            }
        });

        return true;
    }

    public function deleteUser(string $username): bool
    {
        if (!$this->tablesExist()) {
            return false;
        }

        $this->deleteUserRecords($username);

        return true;
    }

    public function syncUsers(): bool
    {
        if (!$this->tablesExist()) {
            return false;
        }

        $accounts = \App\Models\ClientAccount::with('plan')
            ->whereIn('status', ['active'])
            ->get();

        foreach ($accounts as $account) {
            if (!$account->plan) {
                continue;
            }

            $this->createUser([
                'username'   => $account->username,
                'password'   => $account->password,
                'group'      => $account->plan->name,
                'rate_limit' => $this->buildRateLimit($account->plan),
            ]);
        }

        return true;
    }

    public function suspendUser(string $username): bool
    {
        if (!$this->tablesExist()) {
            return false;
        }

        DB::connection($this->connection)->table('radcheck')
            ->where('username', $username)
            ->where('attribute', 'Auth-Type')
            ->delete();

        DB::connection($this->connection)->table('radcheck')->insert([
            'username'  => $username,
            'attribute' => 'Auth-Type',
            'op'        => ':=',
            'value'     => 'Reject',
        ]);

        return true;
    }

    public function unsuspendUser(string $username): bool
    {
        if (!$this->tablesExist()) {
            return false;
        }

        DB::connection($this->connection)->table('radcheck')
            ->where('username', $username)
            ->where('attribute', 'Auth-Type')
            ->delete();

        return true;
    }

    protected function deleteUserRecords(string $username): void
    {
        foreach (['radcheck', 'radreply', 'radusergroup'] as $table) {
            DB::connection($this->connection)->table($table)
                ->where('username', $username)
                ->delete();
        }
    }

    protected function buildRateLimit(\App\Models\Plan $plan): string
    {
        $down = $plan->speed_down ?? 1024;
        $up   = $plan->speed_up ?? 512;

        return "{$up}k/{$down}k";
    }

    protected function tablesExist(): bool
    {
        try {
            return Schema::connection($this->connection)->hasTable('radcheck');
        } catch (\Throwable) {
            return Schema::hasTable('radcheck');
        }
    }
}