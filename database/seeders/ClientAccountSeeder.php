<?php
namespace Database\Seeders;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\Plan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ClientAccountSeeder extends Seeder
{
    public function run(): void
    {
        $plans   = Plan::all()->values();
        $counter = 0;

        Client::all()->each(function (Client $client) use ($plans, &$counter) {
            if (ClientAccount::where("client_id", $client->id)->exists()) {
                return;
            }

            $plan = $plans[$client->id % $plans->count()];

            [$accountStatus, $expiryDate, $activatedAt] = $this->deriveStatus($client->status);

            $octet3 = intdiv($counter, 254) + 1;
            $octet4 = ($counter % 253) + 2;

            ClientAccount::create([
                "client_id"    => $client->id,
                "plan_id"      => $plan->id,
                "username"     => "pb_" . strtolower($client->first_name) . str_pad($client->id, 3, "0", STR_PAD_LEFT),
                "password"     => Str::random(12),
                "ip_address"   => "10.10.{$octet3}.{$octet4}",
                "mac_address"  => $this->fakeMac($client->id),
                "type"         => "prepaid",
                "status"       => $accountStatus,
                "expiry_date"  => $expiryDate,
                "activated_at" => $activatedAt,
            ]);

            $counter++;
        });

        $this->command->info("ClientAccountSeeder: {$counter} accounts seeded.");
    }

    private function deriveStatus(string $clientStatus): array
    {
        return match ($clientStatus) {
            "active"    => ["active",    Carbon::now()->addDays(rand(5, 30)),   Carbon::now()->subDays(rand(1, 60))],
            "suspended" => ["suspended", Carbon::now()->subDays(rand(1, 15)),   Carbon::now()->subDays(rand(30, 90))],
            "inactive"  => ["expired",   Carbon::now()->subDays(rand(30, 90)),  Carbon::now()->subDays(rand(90, 180))],
            "disabled"  => ["suspended", Carbon::now()->subDays(rand(60, 120)), Carbon::now()->subDays(rand(120, 365))],
            default     => ["active",    Carbon::now()->addDays(15),            Carbon::now()->subDays(10)],
        };
    }

    private function fakeMac(int $seed): string
    {
        $parts = [];
        $val   = $seed * 2654435761;
        for ($i = 0; $i < 6; $i++) {
            $parts[] = strtoupper(sprintf("%02X", ($val >> ($i * 4)) & 0xFF));
        }
        $parts[0] = strtoupper(sprintf("%02X", hexdec($parts[0]) | 0x02));
        return implode(":", $parts);
    }
}
