<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

class ServiceCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // Services table is minimal (only id + timestamps), seed placeholders
        $services = [
            'Home Basic 5Mbps',
            'Home Standard 10Mbps',
            'Home Premium 20Mbps',
            'Business 10Mbps',
            'Business 20Mbps',
            'Business 50Mbps',
            'Enterprise 100Mbps',
            'Enterprise 200Mbps',
            'Dedicated Leased Line',
        ];

        foreach ($services as $service) {
            Service::updateOrCreate(['name' => $service], ['name' => $service]);
        }

        $this->command->info('ServiceCatalogSeeder: ' . count($services) . ' global services seeded.');
    }
}