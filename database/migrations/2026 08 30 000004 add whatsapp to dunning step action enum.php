<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds 'whatsapp' to dunning_steps.action so a dunning ladder can send a
 * WhatsApp warning (WhatsAppService::sendSuspensionWarning() — fully coded
 * since the original build but never reachable, because no dunning step
 * could ever be persisted with this action: both the DB check constraint and
 * StoreDunningStepRequest/UpdateDunningStepRequest rejected anything outside
 * ['email','sms','call','suspend','escalate']).
 *
 * Follows the exact pattern already used in
 * 2026_08_10_000000_fix_billing_enum_mismatches.php for widening a Postgres
 * enum-via-check-constraint (and the MySQL/SQLite equivalents) — see that
 * migration for the original precedent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE dunning_steps DROP CONSTRAINT IF EXISTS dunning_steps_action_check");
            DB::statement("ALTER TABLE dunning_steps ADD CONSTRAINT dunning_steps_action_check CHECK (action::text = ANY (ARRAY['email'::text, 'sms'::text, 'call'::text, 'suspend'::text, 'escalate'::text, 'whatsapp'::text]))");
        } elseif ($driver === 'sqlite') {
            // SQLite: no native enum support — skip (string columns handle this fine).
        } else {
            // MySQL: full enum replace.
            DB::statement("ALTER TABLE dunning_steps MODIFY COLUMN action ENUM('email','sms','call','suspend','escalate','whatsapp') NOT NULL DEFAULT 'email'");
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE dunning_steps DROP CONSTRAINT IF EXISTS dunning_steps_action_check");
            DB::statement("ALTER TABLE dunning_steps ADD CONSTRAINT dunning_steps_action_check CHECK (action::text = ANY (ARRAY['email'::text, 'sms'::text, 'call'::text, 'suspend'::text, 'escalate'::text]))");
        } elseif ($driver === 'sqlite') {
            // SQLite: no native enum support — skip.
        } else {
            DB::statement("ALTER TABLE dunning_steps MODIFY COLUMN action ENUM('email','sms','call','suspend','escalate') NOT NULL DEFAULT 'email'");
        }
    }
};