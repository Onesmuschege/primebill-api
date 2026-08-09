<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds 60 realistic synthetic clients per tenant (active, suspended,
 * inactive, disabled) with unique phone numbers per tenant. Data is
 * generated deterministically so re-runs are stable and never collide
 * across tenants.
 */
class ClientSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $admin = User::where('tenant_id', $tenant->id)->whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->first();

            $count = $tenant->slug === 'primenet-isp' ? 60 : 40;

            $firstNames = ['James','Grace','Peter','Alice','David','Mary','Joseph','Sarah','Michael','Esther','Samuel','Lucy','Robert','Faith','Daniel','Agnes','Patrick','Caroline','Kevin','Beatrice','Thomas','Irene','Geoffrey','Winnie','Charles','Judith','Brian','Lydia','Hassan','Miriam','Arnold','Naomi','Edwin','Sharon','Victor','Consolata','Emmanuel','Dorcas','Francis','Purity','John','Ann','Peter','Catherine','Simon','Jane','Anthony','Mercy','Kelvin','Caroline','Isaac','Rose','Elijah','Phoebe','Moses','Ruth','Dennis','Joy','Felix','Nelly'];
            $lastNames  = ['Wanyama','Nafula','Simiyu','Nekesa','Barasa','Wekesa','Khisa','Achieng','Odhiambo','Anyango','Mutai','Chebet','Kiplangat','Jepkemboi','Rono','Cherono','Wafula','Namukoya','Shiundu','Atieno','Owino','Adhiambo','Odero','Mbone','Masinde','Kamau','Mwangi','Njoroge','Otieno','Ouma','Juma','Awuor','Kipkoech','Koech','Luyali','Nyongesa','Oloo','Sang','Mwenda','Auma','Kariuki','Wambui','Mutua','Njeri','Kiptoo','Wanjiku','Maina','Wanjiru','Kimani','Akinyi','Ochieng','Adhis','Mboya','Nyambura','Njuguna','Wachira','Wairimu','Ondiek','Atieno'];
            $counties = ['Bungoma','Kakamega','Kisumu','Trans Nzoia','Uasin Gishu','Siaya','Nandi','Homa Bay','Kericho','Nakuru','Kiambu','Nairobi'];
            $towns = ['Bungoma Town','Webuye','Kakamega Town','Kisumu City','Kitale','Eldoret','Siaya Town','Kapsabet','Homa Bay Town','Kericho Town','Nakuru Town','Thika','Ruiru','Nairobi CBD','Mumias','Kimilili','Chwele','Ahero','Muhoroni','Endebess'];

            $statusDistribution = $this->statusForTenant($tenant->slug);

            for ($i = 0; $i < $count; $i++) {
                $fn = $firstNames[$i % count($firstNames)];
                $ln = $lastNames[($i * 7) % count($lastNames)];
                $county = $counties[$i % count($counties)];
                $town   = $towns[($i * 3) % count($towns)];

                // Deterministic, tenant-unique phone.
                $phone = '0712' . str_pad((string) (($tenant->id * 10000) + 1000 + $i), 6, '0', STR_PAD_LEFT);

                $status = $statusDistribution[$i % count($statusDistribution)];

                $createdAt = now()
                    ->subMonths(($i * 2) % 11)
                    ->subDays(($i * 5) % 27)
                    ->setTime(9 + ($i % 8), ($i * 7) % 60);

                $client = Client::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'phone' => $phone],
                    [
                        'first_name'  => $fn,
                        'last_name'   => $ln,
                        'email'       => strtolower($fn . '.' . $ln) . ($i + 1) . '@' . ($tenant->slug === 'primenet-isp' ? 'primenet' : ($tenant->slug === 'swiftlink-communications' ? 'swiftlink' : 'metrowave')) . '.test',
                        'phone'       => $phone,
                        'id_number'   => (string) (28000000 + $tenant->id * 100000 + $i * 7),
                        'county'      => $county,
                        'town'        => $town,
                        'address'     => 'Plot ' . (12 + $i) . ', ' . $town,
                        'gps_lat'     => round(0.2 + ($i % 10) * 0.1, 6),
                        'gps_lng'     => round(34.5 + ($i % 8) * 0.05, 6),
                        'status'      => $status,
                        'created_by'  => $admin?->id,
                    ]
                );

                $client->forceFill([
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ])->save();
            }
        });

        $this->command->info('ClientSeeder: 60/40/40 clients per tenant seeded.');
    }

    private function statusForTenant(string $slug): array
    {
        // A realistic mix: mostly active, some suspended/inactive/disabled.
        $base = ['active','active','active','active','active','active','active','suspended','suspended','inactive','inactive','disabled'];

        return $base;
    }
}
