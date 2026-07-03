<?php

namespace App\Console\Commands;

use App\Models\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * One-off command to generate referral codes for all existing clients
 * who don't have one yet. Run once after migration.
 *
 * Usage: php artisan clients:generate-referral-codes
 */
class GenerateReferralCodes extends Command
{
    protected $signature   = 'clients:generate-referral-codes';
    protected $description = 'Generate referral codes for clients that do not have one';

    public function handle(): int
    {
        $clients = Client::whereNull('referral_code')->get();

        $bar = $this->output->createProgressBar($clients->count());
        $bar->start();

        foreach ($clients as $client) {
            do {
                $code = strtoupper(Str::random(6));
            } while (Client::where('referral_code', $code)->exists());

            $client->update(['referral_code' => $code]);
            $bar->advance();
        }

        $bar->finish();;
        $this->newLine();
        $this->info("Generated referral codes for {$clients->count()} clients.");

        return self::SUCCESS;
    }
}