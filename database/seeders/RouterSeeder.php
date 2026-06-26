<?php

namespace Database\Seeders;

use App\Models\Router;
use Illuminate\Database\Seeder;

/**
 * Seeds one demo MikroTik router.
 * Plans, NetworkTraffic all reference this router.
 */
class RouterSeeder extends Seeder
{
    public function run(): void
    {
        Router::updateOrCreate(
            ['name' => 'Core-Router-01'],
            [
                'ip_address' => '192.168.88.1',
                'username'   => 'admin',
                'password'   => 'mikrotik_demo',
                'port'       => 8728,
                'type'       => 'mikrotik',
                'location'   => 'Server Room — Bungoma HQ',
                'status'     => 'online',
                'last_seen'  => now(),
            ]
        );

        $this->command->info('RouterSeeder: 1 router seeded.');
    }
}
